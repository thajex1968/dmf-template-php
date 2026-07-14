<?php

/**
 * includes/Auth/Role.php
 * ==========================================================================
 * Role definition + registry with inheritance and wildcard permissions.
 *
 * A role groups permissions under a name (e.g. "admin", "editor"). Roles may
 * inherit permissions from parent roles, forming a hierarchy, and a permission
 * may be a wildcard: "*" (all) or "post.*" (any post capability).
 *
 * Roles are consulted by the ACL to answer "can this user do X?". This file
 * defines no application roles — apps register their own via define().
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Auth;

final class Role
{
    /** @var array<string,Role> */
    private static array $registry = [];

    /**
     * @param array<int,string> $permissions
     * @param array<int,string> $parents      Names of roles this one inherits from.
     */
    public function __construct(
        private string $name,
        private array $permissions = [],
        private array $parents = [],
        private string $label = '',
    ) {
    }

    // ── Registry ──────────────────────────────────────────────────────────

    /**
     * @param array<int,string> $permissions
     * @param array<int,string> $parents
     */
    public static function define(string $name, array $permissions = [], array $parents = [], string $label = ''): Role
    {
        return self::$registry[$name] = new self($name, $permissions, $parents, $label);
    }

    public static function get(string $name): ?Role
    {
        return self::$registry[$name] ?? null;
    }

    public static function exists(string $name): bool
    {
        return isset(self::$registry[$name]);
    }

    /**
     * @return array<string,Role>
     */
    public static function all(): array
    {
        return self::$registry;
    }

    /**
     * Reset the registry (primarily for tests).
     */
    public static function clear(): void
    {
        self::$registry = [];
    }

    // ── Accessors ─────────────────────────────────────────────────────────

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return $this->label !== '' ? $this->label : $this->name;
    }

    /**
     * @return array<int,string>
     */
    public function parents(): array
    {
        return $this->parents;
    }

    /**
     * All effective permissions, including those inherited from parents.
     *
     * @return array<int,string>
     */
    public function permissions(bool $includeInherited = true): array
    {
        $permissions = $this->permissions;

        if ($includeInherited) {
            foreach ($this->parents as $parentName) {
                $parent = self::get($parentName);
                if ($parent !== null && $parent->name !== $this->name) {
                    $permissions = array_merge($permissions, $parent->permissions(true));
                }
            }
        }

        return array_values(array_unique($permissions));
    }

    /**
     * Does this role grant the given permission (respecting wildcards)?
     */
    public function hasPermission(string $permission): bool
    {
        foreach ($this->permissions(true) as $granted) {
            if (self::matches($granted, $permission)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Wildcard-aware match: "*" matches anything; "post.*" matches "post.edit".
     */
    public static function matches(string $granted, string $permission): bool
    {
        if ($granted === '*' || $granted === $permission) {
            return true;
        }
        if (str_ends_with($granted, '.*')) {
            $prefix = substr($granted, 0, -1); // keep the trailing dot
            return str_starts_with($permission, $prefix);
        }
        return false;
    }
}
