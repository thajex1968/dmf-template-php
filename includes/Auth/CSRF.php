<?php

/**
 * includes/Auth/CSRF.php
 * ==========================================================================
 * Cross-Site Request Forgery token manager.
 *
 * Generates a per-session token, renders it as a form field or meta tag, and
 * verifies submitted tokens with a timing-safe comparison. Integrates with the
 * frozen Core Session when supplied; otherwise falls back to the raw $_SESSION
 * (assumes a session has been started).
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Auth;

use DMF\Core\Request;
use DMF\Core\Session;

final class CSRF
{
    public function __construct(
        private ?Session $session = null,
        private string $key = '_csrf_token',
        private string $field = 'csrf_token',
    ) {
    }

    /**
     * The current token, generated on first use.
     */
    public function token(): string
    {
        $token = $this->read();
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->store($token);
        }
        return $token;
    }

    /**
     * Force a new token (call after login / privilege change).
     */
    public function rotate(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->store($token);
        return $token;
    }

    /**
     * Hidden input for embedding in a form.
     */
    public function field(): string
    {
        return '<input type="hidden" name="' . $this->escape($this->field)
            . '" value="' . $this->escape($this->token()) . '">';
    }

    /**
     * Meta tag for exposing the token to JavaScript / fetch().
     */
    public function metaTag(): string
    {
        return '<meta name="csrf-token" content="' . $this->escape($this->token()) . '">';
    }

    /**
     * Timing-safe verification of a raw token.
     */
    public function verify(?string $token): bool
    {
        $stored = $this->read();
        return is_string($token)
            && is_string($stored)
            && $stored !== ''
            && hash_equals($stored, $token);
    }

    /**
     * Verify the token carried by a Core Request (header or field).
     */
    public function check(Request $request): bool
    {
        return $this->verify($request->csrfToken());
    }

    // ── Storage (Core Session or raw $_SESSION) ───────────────────────────

    private function read(): mixed
    {
        if ($this->session !== null) {
            return $this->session->get($this->key);
        }
        return $_SESSION[$this->key] ?? null;
    }

    private function store(string $token): void
    {
        if ($this->session !== null) {
            $this->session->set($this->key, $token);
            return;
        }
        $_SESSION[$this->key] = $token;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
