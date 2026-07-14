<?php

/**
 * public_html/index.php
 * ==========================================================================
 * Front entry point. Sends the visitor to the dashboard when authenticated,
 * or to the login page otherwise.
 * ==========================================================================
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    header('Location: /dashboard/');
} else {
    header('Location: /auth/login.php');
}
exit;
