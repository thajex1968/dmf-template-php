<?php

/**
 * includes/Auth/Auth.php
 * ==========================================================================
 * Authentication service — the front door of the Auth package.
 *
 * Coordinates the whole login flow on top of the frozen Core Framework:
 *   - credential lookup (generic, schema-configurable) + password verification
 *   - brute-force protection / rate limiting via the login_attempts table
 *   - a single generic error for all failure modes (no user enumeration)
 *   - session login through Guard (id regeneration handled there)
 *   - optional "remember me" issuance
 *   - password-reset token issue + consume
 *
 * The database dependency is optional: identity-only operations (login() with a
 * pre-loaded user, logout(), check(), user() from cache) work without it, which
 * keeps the service usable for SSO-style flows and simple to test. DB-backed
 * operations throw if no connection was provided.
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Auth;

use DMF\Core\Database;
use DMF\Core\Session;
use RuntimeException;

final class Auth
{
    public const STATUS_OK        = 'ok';
    public const STATUS_INVALID   = 'invalid';
    public const STATUS_INACTIVE  = 'inactive';
    public const STATUS_LOCKED    = 'locked';

    private Guard $guard;
    private Password $password;
    private ?RememberMe $remember;

    /** @var array<string,mixed> */
    private array $config;

    private string $status = self::STATUS_OK;

    /**
     * @param array<string,mixed> $config table/column names + throttle settings
     */
    public function __construct(
        private ?Database $db,
        Session $session,
        ?Password $password = null,
        ?RememberMe $remember = null,
        array $config = [],
    ) {
        $this->config = array_merge([
            'session_key'    => 'auth_user_id',
            'table'          => 'users',
            'identifier'     => 'username',
            'password_field' => 'password',
            'id_field'       => 'id',
            'active_field'   => 'is_active',
            'attempts_table' => 'login_attempts',
            'reset_table'    => 'password_resets',
            'max_attempts'   => 5,
            'decay_seconds'  => 900,
            'reset_ttl'      => 3600,
        ], $config);

        $this->guard = new Guard($session, (string) $this->config['session_key']);
        $this->password = $password ?? new Password();
        $this->remember = $remember;
    }

    // ── Accessors ─────────────────────────────────────────────────────────

    public function guard(): Guard
    {
        return $this->guard;
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * A single generic message suitable for display — never reveals which part
     * of the credentials failed.
     */
    public function errorMessage(): string
    {
        return $this->status === self::STATUS_LOCKED
            ? 'Too many attempts. Please try again later.'
            : 'Invalid username or password.';
    }

    // ── Login flow ────────────────────────────────────────────────────────

    /**
     * Attempt to authenticate a credential pair. Returns true on success; on
     * failure returns false and sets status()/errorMessage().
     */
    public function attempt(string $identifier, string $password, bool $remember = false, ?string $ip = null): bool
    {
        $this->requireDb();
        $ip ??= (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        if ($this->tooManyAttempts($identifier, $ip)) {
            $this->status = self::STATUS_LOCKED;
            return false;
        }

        $user = $this->findByIdentifier($identifier);
        $hash = is_array($user) ? (string) ($user[$this->config['password_field']] ?? '') : '';

        // Always run verify() to keep timing uniform whether or not the user exists.
        $valid = $user !== null && $this->password->verify($password, $hash);

        if (!$valid) {
            $this->recordFailure($identifier, $ip);
            $this->status = self::STATUS_INVALID;
            return false;
        }

        $activeField = (string) $this->config['active_field'];
        if (array_key_exists($activeField, $user) && !$user[$activeField]) {
            $this->recordFailure($identifier, $ip);
            $this->status = self::STATUS_INACTIVE;
            return false;
        }

        $this->clearAttempts($identifier, $ip);

        if ($this->password->needsRehash($hash)) {
            $this->updatePassword((int) $user[$this->config['id_field']], $this->password->hash($password));
        }

        $this->login($user, $remember);
        $this->touchLastLogin((int) $user[$this->config['id_field']]);
        $this->status = self::STATUS_OK;

        return true;
    }

    /**
     * Log a pre-loaded user in (no credential check). Optionally issue a
     * remember token.
     *
     * @param array<string,mixed> $user
     */
    public function login(array $user, bool $remember = false): void
    {
        $this->guard->login($user);
        if ($remember && $this->remember !== null) {
            $this->remember->issue((int) ($user[$this->config['id_field']] ?? 0));
        }
    }

    /**
     * Log the current user out and drop any remember token.
     */
    public function logout(): void
    {
        $id = $this->guard->id();
        if ($this->remember !== null) {
            $this->remember->clear($id);
        }
        $this->guard->logout();
    }

    // ── Current identity ──────────────────────────────────────────────────

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function id(): ?int
    {
        $id = $this->guard->id();
        if ($id !== null) {
            return $id;
        }
        $user = $this->user();
        return $user !== null ? (int) ($user[$this->config['id_field']] ?? 0) : null;
    }

    /**
     * The current user, resolving from cache → session id → remember cookie.
     *
     * @return array<string,mixed>|null
     */
    public function user(): ?array
    {
        if ($this->guard->user() !== null) {
            return $this->guard->user();
        }

        $id = $this->guard->id();
        if ($id !== null && $this->db !== null) {
            $user = $this->findById($id);
            $this->guard->setUser($user);
            return $user;
        }

        return $this->viaRemember();
    }

    /**
     * Attempt to re-authenticate from the remember cookie.
     *
     * @return array<string,mixed>|null
     */
    public function viaRemember(): ?array
    {
        if ($this->remember === null || $this->db === null || !$this->guard->guest()) {
            return $this->guard->user();
        }

        $id = $this->remember->retrieveUserId();
        if ($id === null) {
            return null;
        }

        $user = $this->findById($id);
        if ($user === null) {
            return null;
        }

        $this->guard->login($user);
        return $user;
    }

    // ── Password reset ────────────────────────────────────────────────────

    /**
     * Create a reset token for the account matching $identifier, storing only
     * its hash. Returns the raw token to email, or null if no such account
     * (callers should still show a uniform message).
     */
    public function createPasswordReset(string $identifier): ?string
    {
        $this->requireDb();
        $user = $this->findByIdentifier($identifier);
        if ($user === null) {
            return null;
        }

        $token = Password::generateToken();
        $this->db->execute(
            "INSERT INTO {$this->ident('reset_table')} (user_id, token_hash, expires_at) VALUES (?, ?, ?)",
            [
                (int) $user[$this->config['id_field']],
                Password::hashToken($token),
                date('Y-m-d H:i:s', time() + (int) $this->config['reset_ttl']),
            ]
        );

        return $token;
    }

    /**
     * Consume a reset token and set a new password. Returns false for an
     * unknown/expired/used token.
     */
    public function resetPassword(string $token, string $newPassword): bool
    {
        $this->requireDb();
        $row = $this->db->selectOne(
            "SELECT id, user_id FROM {$this->ident('reset_table')}
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > ? LIMIT 1",
            [Password::hashToken($token), date('Y-m-d H:i:s')]
        );
        if ($row === null) {
            return false;
        }

        $this->updatePassword((int) $row['user_id'], $this->password->hash($newPassword));
        $this->db->execute(
            "UPDATE {$this->ident('reset_table')} SET used_at = ? WHERE id = ?",
            [date('Y-m-d H:i:s'), (int) $row['id']]
        );

        return true;
    }

    // ── Rate limiting / brute-force protection ────────────────────────────

    public function tooManyAttempts(string $identifier, string $ip): bool
    {
        return $this->recentFailureCount($identifier, $ip) >= (int) $this->config['max_attempts'];
    }

    public function attemptsRemaining(string $identifier, string $ip): int
    {
        return max(0, (int) $this->config['max_attempts'] - $this->recentFailureCount($identifier, $ip));
    }

    /**
     * Seconds until the lockout window clears for this identifier/ip.
     */
    public function availableIn(string $identifier, string $ip): int
    {
        $this->requireDb();
        $since = date('Y-m-d H:i:s', time() - (int) $this->config['decay_seconds']);
        $oldest = $this->db->scalar(
            "SELECT MIN(attempted_at) FROM {$this->ident('attempts_table')}
             WHERE (username = ? OR ip_address = ?) AND successful = 0 AND attempted_at > ?",
            [$identifier, $ip, $since]
        );
        if ($oldest === null) {
            return 0;
        }
        $elapsed = time() - (int) strtotime((string) $oldest);
        return max(0, (int) $this->config['decay_seconds'] - $elapsed);
    }

    private function recentFailureCount(string $identifier, string $ip): int
    {
        $this->requireDb();
        $since = date('Y-m-d H:i:s', time() - (int) $this->config['decay_seconds']);
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM {$this->ident('attempts_table')}
             WHERE (username = ? OR ip_address = ?) AND successful = 0 AND attempted_at > ?",
            [$identifier, $ip, $since]
        );
    }

    private function recordFailure(string $identifier, string $ip): void
    {
        $this->db?->execute(
            "INSERT INTO {$this->ident('attempts_table')} (username, ip_address, successful, attempted_at)
             VALUES (?, ?, 0, ?)",
            [$identifier, $ip, date('Y-m-d H:i:s')]
        );
    }

    private function clearAttempts(string $identifier, string $ip): void
    {
        $this->db?->execute(
            "DELETE FROM {$this->ident('attempts_table')} WHERE username = ? AND ip_address = ? AND successful = 0",
            [$identifier, $ip]
        );
    }

    // ── User lookup ───────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>|null
     */
    private function findByIdentifier(string $identifier): ?array
    {
        $this->requireDb();
        return $this->db->selectOne(
            "SELECT * FROM {$this->ident('table')} WHERE {$this->column('identifier')} = ? LIMIT 1",
            [$identifier]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findById(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        return $this->db->selectOne(
            "SELECT * FROM {$this->ident('table')} WHERE {$this->column('id_field')} = ? LIMIT 1",
            [$id]
        );
    }

    private function updatePassword(int $userId, string $hash): void
    {
        $this->db?->execute(
            "UPDATE {$this->ident('table')} SET {$this->column('password_field')} = ? WHERE {$this->column('id_field')} = ?",
            [$hash, $userId]
        );
    }

    private function touchLastLogin(int $userId): void
    {
        try {
            $this->db?->execute(
                "UPDATE {$this->ident('table')} SET last_login = ? WHERE {$this->column('id_field')} = ?",
                [date('Y-m-d H:i:s'), $userId]
            );
        } catch (\Throwable) {
            // last_login is optional; never fail a login because it is absent.
        }
    }

    // ── Guards / helpers ──────────────────────────────────────────────────

    /**
     * @phpstan-assert !null $this->db
     */
    private function requireDb(): void
    {
        if ($this->db === null) {
            throw new RuntimeException('This operation requires a database connection.');
        }
    }

    private function ident(string $configKey): string
    {
        return $this->safe((string) $this->config[$configKey]);
    }

    private function column(string $configKey): string
    {
        return $this->safe((string) $this->config[$configKey]);
    }

    private function safe(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new RuntimeException("Unsafe SQL identifier: {$identifier}");
        }
        return $identifier;
    }
}
