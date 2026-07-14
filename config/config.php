<?php

/**
 * config/config.php
 * ==========================================================================
 * Application bootstrap: constants + the single shared PDO connection ($conn).
 *
 * This mirrors the reference architecture (plain procedural PHP, one global
 * PDO instance, prepared statements everywhere) with one hardened difference:
 * every secret is read from the git-ignored `.env` file instead of being
 * hardcoded here. Copy `.env.example` to `.env` and fill in real values.
 *
 * Lives ABOVE the web root (`public_html/`) so it can never be served or
 * source-disclosed over HTTP.
 * ==========================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/env.php';

// ── Application identity ──────────────────────────────────────────────────
define('APP_NAME',  (string) env('APP_NAME', 'DMF Application'));
define('APP_ENV',   (string) env('APP_ENV', 'production'));
define('APP_DEBUG', (bool)   env('APP_DEBUG', false));
define('BASE_URL',  rtrim((string) env('APP_URL', ''), '/'));

// ── Database ──────────────────────────────────────────────────────────────
define('DB_HOST',    (string) env('DB_HOST', '127.0.0.1'));
define('DB_PORT',    (string) env('DB_PORT', '3306'));
define('DB_NAME',    (string) env('DB_DATABASE', ''));
define('DB_USER',    (string) env('DB_USERNAME', ''));
define('DB_PASS',    (string) env('DB_PASSWORD', ''));
define('DB_CHARSET', 'utf8mb4');

// ── Mail (SMTP) ───────────────────────────────────────────────────────────
define('SMTP_HOST',       (string) env('MAIL_HOST', ''));
define('SMTP_PORT',       (int)    env('MAIL_PORT', 587));
define('SMTP_USER',       (string) env('MAIL_USERNAME', ''));
define('SMTP_PASS',       (string) env('MAIL_PASSWORD', ''));
define('SMTP_ENCRYPTION', (string) env('MAIL_ENCRYPTION', 'tls'));
define('SMTP_FROM',       (string) env('MAIL_FROM', 'no-reply@dmf.ac.th'));
define('SMTP_NAME',       APP_NAME);

// ── Paths ─────────────────────────────────────────────────────────────────
define('BASE_PATH',    dirname(__DIR__));
define('STORAGE_PATH', BASE_PATH . '/storage');
define('UPLOAD_PATH',  STORAGE_PATH . '/uploads');

// ── Localization & session ────────────────────────────────────────────────
define('APP_TIMEZONE',    (string) env('TIMEZONE', 'Asia/Bangkok'));
define('SESSION_TIMEOUT', (int)    env('SESSION_TIMEOUT', 1800));
date_default_timezone_set(APP_TIMEZONE);

// ── Error reporting is driven by APP_ENV, never hardcoded ─────────────────
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

// ── Database connection (single shared PDO instance) ──────────────────────
try {
    $dsn  = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $conn = new PDO($dsn, DB_USER, DB_PASS, $opts);
} catch (PDOException $e) {
    // Fail closed. Never leak connection details to the client.
    http_response_code(500);
    if (APP_DEBUG) {
        error_log('DB connection failed: ' . $e->getMessage());
    }
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Service temporarily unavailable']));
}
