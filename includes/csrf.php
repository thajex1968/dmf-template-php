<?php

/**
 * includes/csrf.php
 * ==========================================================================
 * Cross-Site Request Forgery protection.
 *
 * A secure default the reference implementation flagged as missing. Every
 * state-changing form (POST) should embed csrf_field() and every POST handler
 * should call csrf_verify() before doing work.
 * ==========================================================================
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Return the current session CSRF token, generating one on first use.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Hidden input to drop inside any <form>.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Validate a submitted token against the session token (timing-safe).
 *
 * @param string|null $token Token from the request; defaults to $_POST.
 */
function csrf_verify(?string $token = null): bool
{
    $token ??= $_POST['csrf_token'] ?? '';
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Verify or die with 403. JSON for XHR, HTML otherwise.
 */
function csrf_require(): void
{
    if (!csrf_verify()) {
        http_response_code(403);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            die(json_encode(['success' => false, 'message' => 'Invalid CSRF token']));
        }
        die('403 Forbidden — invalid CSRF token.');
    }
}
