<?php

/**
 * includes/Auth/Middleware/AuthMiddleware.php
 * ==========================================================================
 * Require an authenticated user.
 *
 * Guests are sent to the login page (preserving the intended destination) or,
 * for API/AJAX requests, receive a 401 JSON response. Authenticated requests
 * pass straight through.
 *
 * Extends the frozen DMF\Core\Middleware base, so instances are directly usable
 * as Router or Bootstrap middleware.
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Auth\Middleware;

use DMF\Auth\Auth;
use DMF\Core\Middleware;
use DMF\Core\Request;
use DMF\Core\Response;

final class AuthMiddleware extends Middleware
{
    public function __construct(
        private Auth $auth,
        private string $redirectTo = '/auth/login.php',
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($this->auth->check()) {
            return $next($request);
        }

        if ($this->expectsJson($request)) {
            return Response::error('Unauthenticated.', 401);
        }

        $target = $this->redirectTo . '?redirect=' . rawurlencode($request->path());
        return Response::redirect($target);
    }

    /**
     * Should the client receive a JSON error rather than a redirect?
     */
    private function expectsJson(Request $request): bool
    {
        return $request->isAjax()
            || $request->isJson()
            || str_contains($request->header('Accept') ?? '', 'application/json')
            || str_starts_with($request->path(), '/api');
    }
}
