<?php

/**
 * includes/Auth/Permission.php
 * ==========================================================================
 * Permission definition + registry.
 *
 * A permission is a fine-grained capability string (e.g. "post.create",
 * "user.delete"). This class is both a value object and a static registry of
 * the permissions an application declares, so the set of valid capabilities is
 * discoverable and typo-checkable.
 *
 * Permissions are assigned to roles (see Role) and evaluated by the ACL. This
 * file contains no application permissions — apps register their own.
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Auth;

final class Permission
{
    /** @var array<string,Permission> */
    private static array $registry = [];

    public function __construct(
        private string $name,
        private string $description = '',
    ) {
    }

    /**
     * Declare a permission and add it to the registry.
     */
    public static function define(string $name, string $description = ''): Permission
    {
        return self::$registry[$name] = new self($name, $description);
    }

    public static function get(string $name): ?Permission
    {
        return self::$registry[$name] ?? null;
    }

    public static function exists(string $name): bool
    {
        return isset(self::$registry[$name]);
    }

    /**
     * @return array<string,Permission>
     */
    public static function all(): array
    {
        return self::$registry;
    }

    /**
     * @return array<int,string>
     */
    public static function names(): array
    {
        return array_keys(self::$registry);
    }

    /**
     * Reset the registry (primarily for tests).
     */
    public static function clear(): void
    {
        self::$registry = [];
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }
}
