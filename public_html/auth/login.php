<?php

/**
 * public_html/auth/login.php
 * ==========================================================================
 * Authentication entry point.
 *
 * Demonstrates the platform's secure login pattern:
 *   - CSRF-protected POST
 *   - bcrypt password_verify()
 *   - session_regenerate_id(true) to prevent session fixation
 *   - safe local-only redirect handling
 *
 * The markup is intentionally minimal template UI; replace copy/branding per
 * application. No business logic lives here.
 * ==========================================================================
 */

declare(strict_types=1);

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: /dashboard/');
    exit;
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/csrf.php';

$error    = '';
$username = '';
$redirect = $_GET['redirect'] ?? '/dashboard/';

if (isset($_GET['error']) && $_GET['error'] === 'session_expired') {
    $error = 'Your session expired. Please sign in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid session token. Please try again.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Please enter your username and password.';
        } else {
            $stmt = $conn->prepare('
                SELECT id, name, password, role, is_active
                FROM users
                WHERE username = ?
                LIMIT 1
            ');
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, (string) $user['password'])) {
                // Uniform message — never reveal whether the username exists.
                $error = 'Invalid username or password.';
            } elseif (!$user['is_active']) {
                $error = 'This account has been disabled.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id']       = (int) $user['id'];
                $_SESSION['role']          = $user['role'];
                $_SESSION['user_name']     = $user['name'];
                $_SESSION['last_activity'] = time();

                $conn->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
                     ->execute([(int) $user['id']]);

                // Only allow local redirects to avoid open-redirect abuse.
                $to = filter_var($redirect, FILTER_SANITIZE_URL);
                if (!$to || !str_starts_with($to, '/') || str_starts_with($to, '//')) {
                    $to = '/dashboard/';
                }
                header("Location: $to");
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in | <?= htmlspecialchars(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root { --primary:#1e3a8a; --bg:#f1f5fb; --surface:#fff; --border:#e2e8f0; --text:#1e293b; --muted:#64748b; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family:'Sarabun',system-ui,sans-serif; background:var(--bg); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:2rem 1rem; }
.card { background:var(--surface); border:1px solid var(--border); border-radius:20px; box-shadow:0 8px 28px rgba(30,58,138,.14); width:100%; max-width:400px; padding:2.5rem 2.25rem; }
.logo { text-align:center; margin-bottom:2rem; }
.logo__icon { width:64px; height:64px; background:linear-gradient(135deg,#1e3a8a,#3b82f6); border-radius:18px; display:inline-flex; align-items:center; justify-content:center; font-size:1.75rem; color:#fff; margin-bottom:1rem; }
.logo__title { font-size:1.15rem; font-weight:700; color:var(--primary); }
label { font-size:.82rem; font-weight:600; color:var(--text); margin-bottom:.4rem; display:block; }
.field { margin-bottom:1.1rem; }
input { width:100%; padding:.65rem .9rem; border:1.5px solid var(--border); border-radius:10px; font-family:inherit; font-size:.92rem; background:var(--bg); outline:none; transition:border-color .2s, box-shadow .2s; }
input:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(30,58,138,.1); background:#fff; }
.btn { width:100%; padding:.75rem; background:linear-gradient(135deg,#1e3a8a,#2563eb); color:#fff; border:none; border-radius:10px; font-family:inherit; font-size:1rem; font-weight:600; cursor:pointer; box-shadow:0 4px 14px rgba(30,58,138,.3); }
.btn:hover { transform:translateY(-1px); }
.alert { background:#fef2f2; border:1px solid #fecaca; border-radius:10px; color:#dc2626; font-size:.85rem; padding:.7rem 1rem; margin-bottom:1.25rem; }
</style>
</head>
<body>
<div class="card">
    <div class="logo">
        <div class="logo__icon">🔐</div>
        <div class="logo__title"><?= htmlspecialchars(APP_NAME) ?></div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

        <div class="field">
            <label for="username">Username</label>
            <input id="username" type="text" name="username" value="<?= htmlspecialchars($username) ?>" autofocus required>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required>
        </div>

        <button type="submit" class="btn"><i class="fas fa-sign-in-alt"></i> Sign in</button>
    </form>
</div>
</body>
</html>
