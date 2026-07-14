<?php

/**
 * includes/Auth/autoload.php
 * ==========================================================================
 * DMF Auth package autoloader.
 *
 * Registers a PSR-4 loader for the `DMF\Auth\` namespace (including the
 * `DMF\Auth\Middleware\` sub-namespace) and guarantees the frozen Core
 * Framework autoloader is available first — the Auth package is built ON Core
 * and never modifies it.
 *
 *     require_once __DIR__ . '/../includes/Auth/autoload.php';
 *     $auth = new DMF\Auth\Auth($db, $session);
 * ==========================================================================
 */

declare(strict_types=1);

// Core is a hard dependency of the Auth package.
require_once __DIR__ . '/../Core/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'DMF\\Auth\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . \DIRECTORY_SEPARATOR
        . str_replace('\\', \DIRECTORY_SEPARATOR, $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
