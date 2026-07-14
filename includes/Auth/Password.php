<?php

/**
 * includes/Auth/Password.php
 * ==========================================================================
 * Password hashing, verification, rehash detection, reset-token helpers, and
 * a configurable strength policy.
 *
 * Uses PHP's native password_* API (bcrypt/argon2) — no dependencies. Reset
 * tokens follow the "store only the hash" rule: a random token is emailed to
 * the user while only its SHA-256 hash is persisted, so a database leak cannot
 * reveal usable tokens.
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Auth;

final class Password
{
    /** @var array<int|string,int> */
    private array $errors = [];

    /**
     * @param string|int|null     $algorithm PASSWORD_DEFAULT | PASSWORD_BCRYPT | PASSWORD_ARGON2ID
     * @param array<string,mixed> $options   Cost / memory options for the algorithm.
     */
    public function __construct(
        private string|int|null $algorithm = \PASSWORD_DEFAULT,
        private array $options = [],
    ) {
    }

    // ── Hashing ───────────────────────────────────────────────────────────

    public function hash(string $plain): string
    {
        return password_hash($plain, $this->algorithm, $this->options);
    }

    public function verify(string $plain, string $hash): bool
    {
        return $hash !== '' && password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options);
    }

    // ── Reset tokens (store the hash, send the token) ─────────────────────

    /**
     * A URL-safe random reset token to email to the user.
     */
    public static function generateToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes(max(16, $bytes)));
    }

    /**
     * The value to store in the database for a token.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Timing-safe comparison of a submitted token against a stored hash.
     */
    public static function verifyToken(string $token, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hashToken($token));
    }

    // ── Strength policy ───────────────────────────────────────────────────

    /**
     * Does the password satisfy the policy? Details available via errors().
     */
    public function meetsPolicy(string $plain, int $minLength = 10): bool
    {
        $this->errors = [];

        if (mb_strlen($plain) < $minLength) {
            $this->errors[] = "Must be at least {$minLength} characters.";
        }
        if (preg_match('/[A-Za-z]/', $plain) !== 1) {
            $this->errors[] = 'Must contain a letter.';
        }
        if (preg_match('/\d/', $plain) !== 1) {
            $this->errors[] = 'Must contain a digit.';
        }

        return $this->errors === [];
    }

    /**
     * @return array<int,string>
     */
    public function policyErrors(): array
    {
        return $this->errors;
    }
}
