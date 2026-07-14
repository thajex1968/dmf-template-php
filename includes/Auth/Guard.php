<?php

/**
 * includes/Auth/Guard.php
 * ==========================================================================
 * Session-backed authentication guard — holds "who is logged in".
 *
 * A thin state manager over the frozen Core Session: it stores the
 * authenticated user id, regenerates the session id on login/logout (defeating
 * fixation), and caches the resolved user record for the current request.
 *
 * Guard performs no database access and no credential checking — that is the
 * Auth service's job. Guard only tracks identity, which keeps it trivial to use
 * and test.
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Auth;

use DMF\Core\Session;

final class Guard
{
    /** @var array<string,mixed>|null The current user, cached for this request. */
    private ?array $user = null;

    public function __construct(
        private Session $session,
        private string $key = 'auth_user_id',
    ) {
    }

    /**
     * Establish an authenticated identity. Regenerates the session id by
     * default to prevent session fixation.
     *
     * @param array<string,mixed> $user
     */
    public function login(array $user, bool $regenerate = true): void
    {
        if ($regenerate) {
            $this->session->regenerate(true);
        }
        $this->session->set($this->key, (int) ($user['id'] ?? 0));
        $this->user = $user;
    }

    /**
     * Clear the authenticated identity and rotate the session id.
     */
    public function logout(): void
    {
        $this->session->remove($this->key);
        $this->user = null;
        $this->session->regenerate(true);
    }

    public function check(): bool
    {
        return $this->id() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    /**
     * The authenticated user's id from the session (null when a guest).
     */
    public function id(): ?int
    {
        $value = $this->session->get($this->key);
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The cached user record for this request (null until Auth resolves it).
     *
     * @return array<string,mixed>|null
     */
    public function user(): ?array
    {
        return $this->user;
    }

    /**
     * Cache the resolved user record for this request.
     *
     * @param array<string,mixed>|null $user
     */
    public function setUser(?array $user): void
    {
        $this->user = $user;
    }
}
