<?php

/**
 * includes/Core/Session.php
 * ==========================================================================
 * Secure session manager.
 *
 * Centralizes the hardening the platform requires: HttpOnly + SameSite cookies,
 * strict-mode ids, id regeneration, and an idle timeout. On top of the raw
 * key/value store it provides one-request flash messages and CSRF token
 * storage/verification — the pieces every authenticated page and API endpoint
 * relies on.
 *
 * Uses Helpers::randomToken() for CSRF tokens and Helpers::e() for the hidden
 * form field.
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Core;

final class Session
{
    private const CSRF_KEY  = '_csrf_token';
    private const FLASH_KEY = '_flash';
    private const TIME_KEY  = '_last_activity';

    private bool $started = false;

    /**
     * @param int                 $timeout       Idle timeout in seconds.
     * @param array<string,mixed> $cookieOptions Overrides for cookie params
     *                                            (lifetime, path, domain, secure,
     *                                            httponly, samesite).
     */
    public function __construct(
        private int $timeout = 1800,
        private array $cookieOptions = [],
    ) {
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────

    /**
     * Start the session with secure cookie parameters, enforce the idle
     * timeout, and age flash messages for the current request.
     */
    public function start(): void
    {
        if ($this->started || session_status() === \PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        $defaults = [
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => (($_SERVER['HTTPS'] ?? '') !== '') && ($_SERVER['HTTPS'] ?? '') !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        $params = array_replace($defaults, $this->cookieOptions);

        if (\PHP_SAPI !== 'cli') {
            ini_set('session.use_strict_mode', '1');
            session_set_cookie_params([
                'lifetime' => (int) $params['lifetime'],
                'path'     => (string) $params['path'],
                'domain'   => (string) $params['domain'],
                'secure'   => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => (string) $params['samesite'],
            ]);
            session_start();
        }

        $this->started = true;
        $this->enforceTimeout();
        $this->ageFlash();
        $this->ensureCsrfToken();
    }

    /**
     * Regenerate the session id (call after any privilege change / login).
     */
    public function regenerate(bool $deleteOld = true): void
    {
        if (\PHP_SAPI !== 'cli' && session_status() === \PHP_SESSION_ACTIVE) {
            session_regenerate_id($deleteOld);
        }
    }

    /**
     * Destroy the session completely and clear its cookie.
     */
    public function destroy(): void
    {
        $_SESSION = [];
        if (\PHP_SAPI !== 'cli' && ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        if (\PHP_SAPI !== 'cli' && session_status() === \PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->started = false;
    }

    // ── Idle timeout ──────────────────────────────────────────────────────

    /**
     * Clear and regenerate the session if it has been idle beyond the timeout.
     */
    private function enforceTimeout(): void
    {
        $last = $_SESSION[self::TIME_KEY] ?? null;
        if (is_int($last) && (time() - $last) > $this->timeout) {
            $_SESSION = [];
            $this->regenerate(true);
        }
        $_SESSION[self::TIME_KEY] = time();
    }

    /**
     * Seconds of inactivity remaining before expiry (0 if already expired).
     */
    public function idleRemaining(): int
    {
        $last = $_SESSION[self::TIME_KEY] ?? time();
        return max(0, $this->timeout - (time() - (int) $last));
    }

    // ── Key/value store ───────────────────────────────────────────────────

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $_SESSION ?? [];
    }

    // ── Flash messages (survive exactly one request) ──────────────────────

    /**
     * Queue a flash value for the next request.
     */
    public function flash(string $key, mixed $value): void
    {
        $_SESSION[self::FLASH_KEY]['new'][$key] = $value;
    }

    /**
     * Read a flash value made available this request.
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION[self::FLASH_KEY]['now'][$key] ?? $default;
    }

    public function hasFlash(string $key): bool
    {
        return isset($_SESSION[self::FLASH_KEY]['now'][$key]);
    }

    /**
     * Carry this request's flash data into the next one as well.
     */
    public function keepFlash(): void
    {
        $now = $_SESSION[self::FLASH_KEY]['now'] ?? [];
        $new = $_SESSION[self::FLASH_KEY]['new'] ?? [];
        $_SESSION[self::FLASH_KEY]['new'] = array_merge($now, $new);
    }

    /**
     * Promote queued flashes to "now" and clear the queue for next request.
     */
    private function ageFlash(): void
    {
        $_SESSION[self::FLASH_KEY]['now'] = $_SESSION[self::FLASH_KEY]['new'] ?? [];
        $_SESSION[self::FLASH_KEY]['new'] = [];
    }

    // ── CSRF ──────────────────────────────────────────────────────────────

    private function ensureCsrfToken(): void
    {
        if (empty($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = Helpers::randomToken(32);
        }
    }

    /**
     * The current CSRF token (generated on demand).
     */
    public function csrfToken(): string
    {
        $this->ensureCsrfToken();
        return (string) $_SESSION[self::CSRF_KEY];
    }

    /**
     * Timing-safe verification of a submitted token.
     */
    public function verifyCsrf(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION[self::CSRF_KEY])
            && Helpers::hashEquals((string) $_SESSION[self::CSRF_KEY], $token);
    }

    /**
     * Hidden input carrying the CSRF token, ready to drop into a form.
     */
    public function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . Helpers::e($this->csrfToken()) . '">';
    }
}
