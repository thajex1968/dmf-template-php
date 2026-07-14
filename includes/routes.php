<?php

/**
 * includes/routes.php
 * ==========================================================================
 * Central route map + link helpers (file-based routing, no rewrite engine).
 *
 * Usage:
 *   require_once __DIR__ . '/../../includes/routes.php';
 *   echo route('profile');                 // → /profile.php
 *   echo route('admin.users', ['id' => 5]); // → /admin/users.php?id=5
 *   redirect('dashboard');                  // header Location + exit
 *   routeActive('dashboard');               // bool, for nav highlighting
 *
 * Add one entry per page to ROUTES. This is the ONLY place URLs are defined,
 * so pages never hardcode paths — matching the reference architecture.
 * ==========================================================================
 */

declare(strict_types=1);

/**
 * The route table. Extend it as the application grows.
 *
 * @var array<string, array{path:string, title:string, auth:bool, icon?:string, nav?:bool, admin?:bool, param?:string|null}>
 */
const ROUTES = [
    // ── Auth ──────────────────────────────────────────────────────────────
    'login'       => ['path' => '/auth/login.php',  'title' => 'Sign in',   'auth' => false, 'icon' => 'sign-in-alt'],
    'logout'      => ['path' => '/auth/logout.php', 'title' => 'Sign out',  'auth' => true,  'icon' => 'sign-out-alt'],

    // ── Application ───────────────────────────────────────────────────────
    'dashboard'   => ['path' => '/dashboard/',      'title' => 'Dashboard', 'auth' => true,  'icon' => 'chart-bar', 'nav' => true],
    'profile'     => ['path' => '/profile.php',     'title' => 'Profile',   'auth' => true,  'icon' => 'user-circle'],

    // ── Admin ─────────────────────────────────────────────────────────────
    'admin'       => ['path' => '/admin/',          'title' => 'Admin',     'auth' => true,  'icon' => 'cog',   'admin' => true, 'nav' => true],
    'admin.users' => ['path' => '/admin/users.php', 'title' => 'Users',     'auth' => true,  'icon' => 'users', 'admin' => true, 'param' => 'id'],
];

/**
 * Build the URL for a route, appending any params as a query string.
 *
 * @param array<string, string|int> $params
 */
function route(string $key, array $params = []): string
{
    if (!isset(ROUTES[$key])) {
        trigger_error("Route '$key' not found", E_USER_WARNING);
        return '#';
    }

    $path = ROUTES[$key]['path'];

    if ($params !== []) {
        $separator = str_contains($path, '?') ? '&' : '?';
        $path .= $separator . http_build_query($params);
    }

    return $path;
}

/**
 * Alias for route().
 *
 * @param array<string, string|int> $params
 */
function routeUrl(string $key, array $params = []): string
{
    return route($key, $params);
}

/**
 * Redirect to a route and stop execution.
 *
 * @param array<string, string|int> $params
 * @param array<string, string|int> $extra  Additional query params (e.g. flash flags).
 */
function redirect(string $key, array $params = [], array $extra = []): void
{
    header('Location: ' . route($key, array_merge($params, $extra)));
    exit;
}

/**
 * The human-readable title of a route.
 */
function routeTitle(string $key): string
{
    return ROUTES[$key]['title'] ?? $key;
}

/**
 * Is the current request within the given route's path? (nav active state)
 */
function routeActive(string $key): bool
{
    $path = parse_url(ROUTES[$key]['path'] ?? '', PHP_URL_PATH);
    $current = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    return is_string($path) && is_string($current) && str_starts_with($current, rtrim($path, '/'));
}
