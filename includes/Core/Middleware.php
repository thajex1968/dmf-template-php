<?php

/**
 * includes/Core/Middleware.php
 * ==========================================================================
 * Middleware contract + pipeline runner.
 *
 * Middleware wrap the request/response cycle in an "onion": each receives the
 * Request and a `$next` callable, may act before and/or after calling it, and
 * returns a Response. This is the exact shape Router already accepts, so a
 * class extending this base is directly usable as a route or global middleware:
 *
 *     final class RequireLogin extends Middleware {
 *         public function handle(Request $request, callable $next): Response {
 *             // ... short-circuit or $next($request) ...
 *         }
 *     }
 *     $router->get('/admin', $h)->middleware(new RequireLogin());
 *
 * The static run()/pipeline() helpers compose an ordered stack around a core
 * handler — used by Bootstrap for global middleware.
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Core;

abstract class Middleware
{
    /**
     * Process the request and produce a response, optionally delegating to
     * the next layer via $next($request).
     */
    abstract public function handle(Request $request, callable $next): Response;

    /**
     * Make instances directly callable, matching the Router/pipeline signature
     * `fn(Request, callable $next): Response`.
     */
    public function __invoke(Request $request, callable $next): Response
    {
        return $this->handle($request, $next);
    }

    /**
     * Run a request through an ordered middleware stack, ending at $core.
     *
     * @param array<int,callable> $middleware Executed first-to-last (outermost first).
     * @param callable(Request):Response $core
     */
    public static function run(Request $request, array $middleware, callable $core): Response
    {
        $pipeline = $core;
        foreach (array_reverse($middleware) as $layer) {
            $next = $pipeline;
            $pipeline = static function (Request $req) use ($layer, $next): Response {
                return $layer($req, $next);
            };
        }
        return $pipeline($request);
    }

    /**
     * Build a reusable pipeline closure without running it immediately.
     *
     * @param array<int,callable> $middleware
     * @param callable(Request):Response $core
     * @return callable(Request):Response
     */
    public static function pipeline(array $middleware, callable $core): callable
    {
        return static function (Request $request) use ($middleware, $core): Response {
            return self::run($request, $middleware, $core);
        };
    }
}
