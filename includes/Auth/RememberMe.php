<?php

/**
 * includes/Auth/RememberMe.php
 * ==========================================================================
 * "Remember me" persistent login via the selector/validator token pattern.
 *
 * On issue, a random selector + validator are generated. The cookie stores
 * "selector:validator"; the database stores the selector and only the SHA-256
 * hash of the validator, so a database leak cannot forge a login. On each use
 * the validator is compared with hash_equals() and rotated (theft-resistant).
 *
 * Requires a token table (default `auth_remember_tokens`) — see AUTHENTICATION.md
 * for the DDL. Cookies are set HttpOnly, SameSite=Lax, and Secure by default.
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Auth;

use DMF\Core\Database;

final class RememberMe
{
    private string $cookie;
    private int $lifetimeDays;
    private string $table;
    private bool $secure;
    private string $path;
    private string $domain;

    /**
     * @param array<string,mixed> $config cookie, days, table, secure, path, domain
     */
    public function __construct(
        private Database $db,
        array $config = [],
    ) {
        $this->cookie = (string) ($config['cookie'] ?? 'dmf_remember');
        $this->lifetimeDays = (int) ($config['days'] ?? 30);
        $this->table = $this->safeIdentifier((string) ($config['table'] ?? 'auth_remember_tokens'));
        $this->secure = (bool) ($config['secure'] ?? true);
        $this->path = (string) ($config['path'] ?? '/');
        $this->domain = (string) ($config['domain'] ?? '');
    }

    /**
     * Issue a new remember token for a user and set the cookie.
     */
    public function issue(int $userId): void
    {
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + $this->lifetimeSeconds());

        $this->db->execute(
            "INSERT INTO {$this->table} (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)",
            [$userId, $selector, hash('sha256', $validator), $expiresAt]
        );

        $this->writeCookie($selector . ':' . $validator);
    }

    /**
     * Resolve the user id from the remember cookie, or null. Rotates the
     * validator on success. A validator mismatch (possible theft) purges the
     * user's tokens.
     */
    public function retrieveUserId(): ?int
    {
        [$selector, $validator] = $this->parseCookie();
        if ($selector === null || $validator === null) {
            return null;
        }

        $row = $this->db->selectOne(
            "SELECT id, user_id, validator_hash, expires_at FROM {$this->table} WHERE selector = ? LIMIT 1",
            [$selector]
        );
        if ($row === null) {
            $this->forgetCookie();
            return null;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            $this->db->execute("DELETE FROM {$this->table} WHERE id = ?", [(int) $row['id']]);
            $this->forgetCookie();
            return null;
        }

        if (!hash_equals((string) $row['validator_hash'], hash('sha256', $validator))) {
            // Selector matched but validator did not — treat as compromise.
            $this->clear((int) $row['user_id']);
            return null;
        }

        $this->rotate((int) $row['id'], $selector);
        return (int) $row['user_id'];
    }

    /**
     * Delete the current token (and optionally all of a user's tokens) and
     * clear the cookie.
     */
    public function clear(?int $userId = null): void
    {
        if ($userId !== null) {
            $this->db->execute("DELETE FROM {$this->table} WHERE user_id = ?", [$userId]);
        } else {
            [$selector] = $this->parseCookie();
            if ($selector !== null) {
                $this->db->execute("DELETE FROM {$this->table} WHERE selector = ?", [$selector]);
            }
        }
        $this->forgetCookie();
    }

    /**
     * Remove expired tokens (housekeeping).
     */
    public function purgeExpired(): int
    {
        return $this->db->execute(
            "DELETE FROM {$this->table} WHERE expires_at < ?",
            [date('Y-m-d H:i:s')]
        );
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * Replace the validator for an existing token and refresh the cookie.
     */
    private function rotate(int $tokenId, string $selector): void
    {
        $validator = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + $this->lifetimeSeconds());

        $this->db->execute(
            "UPDATE {$this->table} SET validator_hash = ?, expires_at = ? WHERE id = ?",
            [hash('sha256', $validator), $expiresAt, $tokenId]
        );

        $this->writeCookie($selector . ':' . $validator);
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function parseCookie(): array
    {
        $raw = (string) ($_COOKIE[$this->cookie] ?? '');
        if ($raw === '' || !str_contains($raw, ':')) {
            return [null, null];
        }
        [$selector, $validator] = explode(':', $raw, 2);
        return [$selector !== '' ? $selector : null, $validator !== '' ? $validator : null];
    }

    private function writeCookie(string $value): void
    {
        if (\PHP_SAPI === 'cli') {
            return;
        }
        setcookie($this->cookie, $value, [
            'expires'  => time() + $this->lifetimeSeconds(),
            'path'     => $this->path,
            'domain'   => $this->domain,
            'secure'   => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$this->cookie] = $value;
    }

    private function forgetCookie(): void
    {
        unset($_COOKIE[$this->cookie]);
        if (\PHP_SAPI === 'cli') {
            return;
        }
        setcookie($this->cookie, '', [
            'expires'  => time() - 3600,
            'path'     => $this->path,
            'domain'   => $this->domain,
            'secure'   => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function lifetimeSeconds(): int
    {
        return $this->lifetimeDays * 86400;
    }

    private function safeIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new \InvalidArgumentException("Unsafe SQL identifier: {$identifier}");
        }
        return $identifier;
    }
}
