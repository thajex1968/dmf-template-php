<?php

/**
 * public_html/dashboard/index.php
 * ==========================================================================
 * Reference page — the canonical authenticated-page skeleton.
 *
 * Every page in an application built on this template follows the same shape:
 *   1. auth_check.php   → session guard, loads $currentUser / $userId / $role
 *   2. permission.php   → RBAC helpers available
 *   3. routes.php       → route() / redirect() / routeActive()
 *   4. (optional) requirePermission(...) gate
 *   5. SQL via $conn (PDO prepared statements)
 *   6. HTML document + navbar
 *
 * This page carries NO business logic — it exists to demonstrate the pattern.
 * ==========================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth_check.php';   // 1. session → $currentUser, $userId, $role, $isAdmin
require_once __DIR__ . '/../../includes/permission.php';   // 2. RBAC helpers
require_once __DIR__ . '/../../includes/routes.php';       // 3. link helpers
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard | <?= htmlspecialchars(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root { --primary:#1e3a8a; --bg:#f1f5fb; --surface:#fff; --border:#e2e8f0; --text:#1e293b; --muted:#64748b; }
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Sarabun',system-ui,sans-serif; background:var(--bg); color:var(--text); }
.wrap { max-width:900px; margin:2rem auto; padding:0 1rem; }
.card { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:2rem; box-shadow:0 2px 10px rgba(30,58,138,.06); animation:fadeUp .4s ease both; }
@keyframes fadeUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
h1 { font-size:1.4rem; color:var(--primary); margin-bottom:.5rem; }
.muted { color:var(--muted); font-size:.9rem; }
.hint { margin-top:1.5rem; padding:1rem; background:var(--bg); border-radius:10px; font-size:.85rem; color:var(--muted); }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<div class="wrap">
    <div class="card">
        <h1>Welcome, <?= htmlspecialchars((string) $currentUser['name']) ?> 👋</h1>
        <p class="muted">
            Role: <strong><?= htmlspecialchars($role) ?></strong>
            <?= $isAdmin ? ' · administrator' : '' ?>
        </p>

        <div class="hint">
            This is the template's reference dashboard. It demonstrates the
            standard include chain (<code>auth_check → permission → routes</code>)
            and the shared navbar. Build your application's pages from this same
            skeleton — no business logic ships in the template.
        </div>
    </div>
</div>

</body>
</html>
