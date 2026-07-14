<?php

/**
 * includes/Auth/Middleware/PermissionMiddleware.php
 * ==========================================================================
 * Require the authenticated user to hold the given permission(s).
 *
 * Unauthenticated requests are treated like AuthMiddleware (401/redirect);
 * authenticated-but-unauthorized requests receive a 403. Permissions are
 * evaluated by the ACL (role-derived plus per-user overrides). By default ALL
 * listed permissions are required; pass requireAll=false to require ANY.
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Auth\Middleware;

use DMF\Auth\ACL;
use DMF\Auth\Auth;
use DMF\Core\Middleware;
use DMF\Core\Request;
use DMF\Core\Response;

final class PermissionMiddleware extends Middleware
{
    /** @var array<int,string> */
    private array $permissions;

    /**
     * @param array<int,string>|string $permissions
     */
    public function __construct(
        private Auth $auth,
        private ACL $acl,
        array|string $permissions,
        private bool $requireAll = true,
    ) {
        $this->permissions = is_array($permissions) ? array_values($permissions) : [$permissions];
    }

    public function handle(Request $request, callable $next): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->expectsJson($request)
                ? Response::error('Unauthenticated.', 401)
                : Response::redirect('/auth/login.php?redirect=' . rawurlencode($request->path()));
        }

        $allowed = $this->requireAll
            ? $this->acl->canAll($user, $this->permissions)
            : $this->acl->canAny($user, $this->permissions);

        if (!$allowed) {
            return $this->deny($request);
        }

        return $next($request);
    }

    private function deny(Request $request): Response
    {
        if ($this->expectsJson($request)) {
            return Response::error('Forbidden.', 403);
        }
        return Response::html('<h1>403 Forbidden</h1>', 403);
    }

    /**
     * Should the client receive a JSON error rather than a redirect/HTML page?
     */
    private function expectsJson(Request $request): bool
    {
        return $request->isAjax()
            || $request->isJson()
            || str_contains($request->header('Accept') ?? '', 'application/json')
            || str_starts_with($request->path(), '/api');
    }
}
