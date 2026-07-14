<?php

/**
 * includes/Auth/Middleware/RoleMiddleware.php
 * ==========================================================================
 * Require the authenticated user to hold one of the given roles.
 *
 * Unauthenticated requests are treated like AuthMiddleware (401/redirect);
 * authenticated-but-unauthorized requests receive a 403. Role membership is
 * evaluated by the ACL.
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Auth\Middleware;

use DMF\Auth\ACL;
use DMF\Auth\Auth;
use DMF\Core\Middleware;
use DMF\Core\Request;
use DMF\Core\Response;

final class RoleMiddleware extends Middleware
{
    /** @var array<int,string> */
    private array $roles;

    public function __construct(
        private Auth $auth,
        private ACL $acl,
        string ...$roles,
    ) {
        $this->roles = $roles;
    }

    public function handle(Request $request, callable $next): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->expectsJson($request)
                ? Response::error('Unauthenticated.', 401)
                : Response::redirect('/auth/login.php?redirect=' . rawurlencode($request->path()));
        }

        if (!$this->acl->hasRole($user, ...$this->roles)) {
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
