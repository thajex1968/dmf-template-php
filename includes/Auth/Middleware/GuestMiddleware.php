<?php

/**
 * includes/Auth/Middleware/GuestMiddleware.php
 * ==========================================================================
 * Require an unauthenticated (guest) user.
 *
 * Used to protect pages that only make sense when logged out — login, register,
 * forgot-password. An already-authenticated user is redirected to the app home
 * (or receives a 403 JSON response for API/AJAX requests).
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Auth\Middleware;

use DMF\Auth\Auth;
use DMF\Core\Middleware;
use DMF\Core\Request;
use DMF\Core\Response;

final class GuestMiddleware extends Middleware
{
    public function __construct(
        private Auth $auth,
        private string $redirectTo = '/dashboard/',
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($this->auth->guest()) {
            return $next($request);
        }

        if ($this->expectsJson($request)) {
            return Response::error('Already authenticated.', 403);
        }

        return Response::redirect($this->redirectTo);
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
