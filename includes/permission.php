<?php

/**
 * includes/permission.php
 * ==========================================================================
 * Role-Based Access Control (RBAC) primitives.
 *
 * This file provides the *reusable* building blocks only — the generic role
 * checks and the requirePermission() gate. Application-specific rules
 * (e.g. "can this user edit record X?") belong in the consuming project, built
 * on top of these helpers, exactly as the reference implementation did.
 *
 * Convention: a user's role is a single lowercase string on `users.role`.
 * Extend ROLES to match your application's role set.
 * ==========================================================================
 */

declare(strict_types=1);

/**
 * Known roles, most-privileged first. Applications override/extend this list.
 *
 * @var string[]
 */
const ROLES = ['super_admin', 'admin', 'user'];

/**
 * Roles granted unrestricted administrative access.
 *
 * @var string[]
 */
const ADMIN_ROLES = ['super_admin', 'admin'];

/**
 * Does the given role match any of the allowed roles?
 */
function hasRole(string $role, string ...$allowed): bool
{
    return in_array(strtolower(trim($role)), array_map('strtolower', $allowed), true);
}

/**
 * Is this an administrative role?
 */
function isAdminRole(string $role): bool
{
    return in_array(strtolower(trim($role)), ADMIN_ROLES, true);
}

/**
 * Require an authenticated session, or redirect to login.
 */
function requireLogin(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        header('Location: /auth/login.php');
        exit;
    }
}

/**
 * Require the current user to hold one of the given roles.
 *
 * @param array<string,mixed> $user   The $currentUser row.
 * @param string              ...$roles Allowed roles.
 */
function requireRole(array $user, string ...$roles): void
{
    requirePermission(hasRole((string) ($user['role'] ?? ''), ...$roles));
}

/**
 * Gate: stop the request with a 403 when $allowed is false.
 *
 * Returns JSON for XHR requests and a minimal HTML page otherwise — the same
 * dual-mode behavior used across the platform so API and page callers both
 * receive a sensible response.
 */
function requirePermission(bool $allowed, string $message = 'You do not have permission to access this page.'): void
{
    if ($allowed) {
        return;
    }

    http_response_code(403);

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => $message]));
    }

    $safe = htmlspecialchars($message);
    die(<<<HTML
<div style="font-family:'Sarabun',system-ui,sans-serif;text-align:center;padding:4rem;color:#64748b">
    <div style="font-size:3rem">🔒</div>
    <h2 style="color:#1e3a8a">$safe</h2>
    <a href="javascript:history.back()" style="color:#3b82f6">← Go back</a>
</div>
HTML);
}
