<?php

/**
 * includes/auth_check.php
 * ==========================================================================
 * Session guard — required at the top of every authenticated page.
 *
 * Mirrors the reference architecture's include chain:
 *     require_once __DIR__ . '/../../includes/auth_check.php';   // this file
 *     require_once __DIR__ . '/../../includes/permission.php';   // RBAC
 *     require_once __DIR__ . '/../../includes/routes.php';       // links
 *
 * Responsibilities:
 *   1. Start the session and enforce the idle timeout.
 *   2. Redirect anonymous visitors to the login page.
 *   3. Load the current user and expose the shared globals every page uses:
 *        $conn, $currentUser, $userId, $role, $isAdmin, $avatarPath
 *
 * All domain/business rules (which records a user may see) live in
 * permission.php, never here.
 * ==========================================================================
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';

// ── Idle timeout ──────────────────────────────────────────────────────────
if (isset($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    session_unset();
    session_destroy();
    header('Location: /auth/login.php?error=session_expired');
    exit;
}
$_SESSION['last_activity'] = time();

// ── Not authenticated → send to login, preserving the intended destination ─
if (!isset($_SESSION['user_id'])) {
    $back = urlencode($_SERVER['REQUEST_URI'] ?? '');
    header('Location: /auth/login.php' . ($back ? "?redirect=$back" : ''));
    exit;
}

// ── Load the current user ─────────────────────────────────────────────────
$stmt = $conn->prepare('
    SELECT id, name, email, role, avatar, is_active
    FROM users
    WHERE id = ? AND is_active = 1
    LIMIT 1
');
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();

if (!$currentUser) {
    // Account removed or deactivated mid-session.
    session_unset();
    session_destroy();
    header('Location: /auth/login.php?error=session_expired');
    exit;
}

// ── Shared shortcuts available to every page ──────────────────────────────
$userId  = (int) $currentUser['id'];
$role    = strtolower(trim((string) $currentUser['role']));
$isAdmin = in_array($role, ['super_admin', 'admin'], true);

$avatarPath = !empty($currentUser['avatar'])
    ? BASE_URL . '/uploads/avatars/' . $currentUser['avatar']
    : 'https://cdn-icons-png.flaticon.com/512/149/149071.png';
