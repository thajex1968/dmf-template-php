<?php

/**
 * includes/Core/autoload.php
 * ==========================================================================
 * DMF Core Framework autoloader.
 *
 * A dependency-free PSR-4 style autoloader for the `DMF\Core\` namespace —
 * no Composer, no third-party packages. Require this single file once during
 * bootstrap and every Core class loads on demand:
 *
 *     require_once __DIR__ . '/../includes/Core/autoload.php';
 *     $db = DMF\Core\Database::connection();
 *
 * The framework requires PHP 8.2+; the floor is enforced here so a
 * mis-provisioned host fails fast with a clear message.
 * ==========================================================================
 */

declare(strict_types=1);

if (\PHP_VERSION_ID < 80200) {
    throw new \RuntimeException(
        'DMF Core Framework requires PHP 8.2 or newer; running ' . \PHP_VERSION . '.'
    );
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'DMF\\Core\\';

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
