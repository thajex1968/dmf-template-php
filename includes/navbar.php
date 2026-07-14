<?php

/**
 * includes/navbar.php
 * ==========================================================================
 * Shared top navigation bar.
 *
 * Injected right after <body> on authenticated pages:
 *     <?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
 *
 * Renders every route flagged 'nav' => true (respecting the 'admin' flag),
 * highlights the active item, and shows the user menu. Business menus are
 * added purely by extending ROUTES in includes/routes.php — never by editing
 * this component.
 *
 * Expects these globals from the auth_check.php → permission.php chain:
 *     $currentUser, $isAdmin, $avatarPath
 * ==========================================================================
 */

declare(strict_types=1);

if (!function_exists('route')) {
    require_once __DIR__ . '/routes.php';
}

$navUser    = $currentUser ?? ['name' => 'User'];
$navIsAdmin = $isAdmin ?? false;
$navAvatar  = $avatarPath ?? 'https://cdn-icons-png.flaticon.com/512/149/149071.png';
?>
<style>
:root {
    --primary: #1e3a8a;
    --bg:      #f1f5fb;
    --surface: #ffffff;
    --border:  #e2e8f0;
    --text:    #1e293b;
    --muted:   #64748b;
}
.dmf-nav {
    display: flex; align-items: center; gap: 1.25rem;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    padding: 0.6rem 1.25rem;
    font-family: 'Sarabun', system-ui, sans-serif;
    box-shadow: 0 2px 8px rgba(30, 58, 138, 0.05);
}
.dmf-nav__brand { font-weight: 700; color: var(--primary); text-decoration: none; font-size: 1.05rem; }
.dmf-nav__links { display: flex; gap: 0.25rem; flex: 1; }
.dmf-nav__link {
    display: inline-flex; align-items: center; gap: 0.4rem;
    padding: 0.45rem 0.8rem; border-radius: 8px;
    color: var(--muted); text-decoration: none; font-size: 0.9rem; font-weight: 600;
    transition: background 0.15s, color 0.15s;
}
.dmf-nav__link:hover { background: var(--bg); color: var(--primary); }
.dmf-nav__link.is-active { background: rgba(30, 58, 138, 0.1); color: var(--primary); }
.dmf-nav__user { display: flex; align-items: center; gap: 0.6rem; }
.dmf-nav__avatar { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border); }
.dmf-nav__name { font-size: 0.85rem; font-weight: 600; color: var(--text); }
.dmf-nav__logout { color: var(--muted); text-decoration: none; font-size: 0.85rem; }
.dmf-nav__logout:hover { color: #dc2626; }
@media (max-width: 640px) {
    .dmf-nav__name { display: none; }
    .dmf-nav__link span { display: none; }
}
</style>
<nav class="dmf-nav">
    <a class="dmf-nav__brand" href="<?= route('dashboard') ?>"><?= htmlspecialchars(APP_NAME) ?></a>

    <div class="dmf-nav__links">
        <?php foreach (ROUTES as $key => $r): ?>
            <?php
            if (empty($r['nav'])) {
                continue;
            }
            if (!empty($r['admin']) && !$navIsAdmin) {
                continue;
            }
            ?>
            <a class="dmf-nav__link <?= routeActive($key) ? 'is-active' : '' ?>" href="<?= route($key) ?>">
                <i class="fas fa-<?= htmlspecialchars($r['icon'] ?? 'circle') ?>"></i>
                <span><?= htmlspecialchars($r['title']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="dmf-nav__user">
        <a href="<?= route('profile') ?>" class="dmf-nav__user" style="text-decoration:none">
            <img class="dmf-nav__avatar" src="<?= htmlspecialchars($navAvatar) ?>" alt="avatar">
            <span class="dmf-nav__name"><?= htmlspecialchars((string) ($navUser['name'] ?? 'User')) ?></span>
        </a>
        <a class="dmf-nav__logout" href="<?= route('logout') ?>" title="Sign out">
            <i class="fas fa-sign-out-alt"></i>
        </a>
    </div>
</nav>
