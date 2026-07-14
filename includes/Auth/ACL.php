<?php

/**
 * includes/Auth/ACL.php
 * ==========================================================================
 * Access Control List — the central authorization decision engine.
 *
 * Answers "may this user perform this action?" by combining:
 *   1. Explicit per-user denials  (highest precedence)
 *   2. Explicit per-user grants
 *   3. Permissions granted through the user's role(s) via the Role registry
 *   4. Default deny                (nothing matched)
 *
 * A user is a plain associative array (e.g. a `users` row). Roles are read from
 * the user's `roles` (array) or `role` (string) key. Per-user grant/deny
 * overrides are held in memory here; an application may load them from its own
 * storage — no persistence schema is imposed and no business rules live here.
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Auth;

final class ACL
{
    /** @var array<int,array<int,string>> userId => granted permissions */
    private array $grants = [];

    /** @var array<int,array<int,string>> userId => denied permissions */
    private array $denials = [];

    // ── Role checks ───────────────────────────────────────────────────────

    /**
     * Does the user hold any of the given roles?
     *
     * @param array<string,mixed> $user
     */
    public function hasRole(array $user, string ...$roles): bool
    {
        $userRoles = $this->rolesOf($user);
        foreach ($roles as $role) {
            if (in_array($role, $userRoles, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Does the user hold every one of the given roles?
     *
     * @param array<string,mixed> $user
     */
    public function hasAllRoles(array $user, string ...$roles): bool
    {
        $userRoles = $this->rolesOf($user);
        foreach ($roles as $role) {
            if (!in_array($role, $userRoles, true)) {
                return false;
            }
        }
        return $roles !== [];
    }

    // ── Permission checks ─────────────────────────────────────────────────

    /**
     * Is the user allowed to perform the permission?
     *
     * @param array<string,mixed> $user
     */
    public function can(array $user, string $permission): bool
    {
        $id = (int) ($user['id'] ?? 0);

        // 1. Explicit denial wins outright.
        if ($id !== 0 && $this->matchesAny($this->denials[$id] ?? [], $permission)) {
            return false;
        }

        // 2. Explicit grant.
        if ($id !== 0 && $this->matchesAny($this->grants[$id] ?? [], $permission)) {
            return true;
        }

        // 3. Role-derived permission.
        foreach ($this->rolesOf($user) as $roleName) {
            $role = Role::get($roleName);
            if ($role !== null && $role->hasPermission($permission)) {
                return true;
            }
        }

        // 4. Default deny.
        return false;
    }

    /**
     * @param array<string,mixed> $user
     */
    public function cannot(array $user, string $permission): bool
    {
        return !$this->can($user, $permission);
    }

    /**
     * Allowed to perform ANY of the permissions?
     *
     * @param array<string,mixed> $user
     * @param array<int,string>   $permissions
     */
    public function canAny(array $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($user, $permission)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Allowed to perform ALL of the permissions?
     *
     * @param array<string,mixed> $user
     * @param array<int,string>   $permissions
     */
    public function canAll(array $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->can($user, $permission)) {
                return false;
            }
        }
        return $permissions !== [];
    }

    // ── Per-user overrides ────────────────────────────────────────────────

    public function grant(int $userId, string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            $this->grants[$userId][] = $permission;
        }
    }

    public function deny(int $userId, string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            $this->denials[$userId][] = $permission;
        }
    }

    /**
     * Remove a previously granted or denied override.
     */
    public function revoke(int $userId, string ...$permissions): void
    {
        foreach (['grants', 'denials'] as $bucket) {
            if (!isset($this->{$bucket}[$userId])) {
                continue;
            }
            $this->{$bucket}[$userId] = array_values(
                array_filter($this->{$bucket}[$userId], static fn(string $p): bool => !in_array($p, $permissions, true))
            );
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * Extract the user's role names from a `roles` array or `role` string.
     *
     * @param array<string,mixed> $user
     * @return array<int,string>
     */
    private function rolesOf(array $user): array
    {
        if (isset($user['roles']) && is_array($user['roles'])) {
            return array_values(array_map('strval', $user['roles']));
        }
        if (isset($user['role']) && $user['role'] !== '') {
            return [(string) $user['role']];
        }
        return [];
    }

    /**
     * @param array<int,string> $set
     */
    private function matchesAny(array $set, string $permission): bool
    {
        foreach ($set as $granted) {
            if (Role::matches($granted, $permission)) {
                return true;
            }
        }
        return false;
    }
}
