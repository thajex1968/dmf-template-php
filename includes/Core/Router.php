<?php

/**
 * includes/Core/Router.php
 * ==========================================================================
 * Named-route HTTP router with middleware support and reverse URL generation.
 *
 * A small dispatcher for applications that prefer explicit routing over the
 * platform's file-based convention. Register routes per method with `{param}`
 * (and optional `{param:regex}`) placeholders, name them, attach middleware
 * (globally, per group, or per route), then dispatch() a Request to the
 * matching handler and receive a Response.
 *
 * Middleware are callables of the form `fn(Request, callable $next): Response`,
 * composed as an onion around the route handler. url() generates URLs from
 * named routes — the single place link paths are derived.
 *
 * Kept to one class (no separate Route object) via post-registration chaining:
 *     $router->get('/users/{id}', $h)->name('users.show')->middleware($mw);
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Core;

final class Router
{
    /**
     * @var array<int,array{methods:array<int,string>,path:string,regex:string,handler:mixed,name:?string,middleware:array<int,callable>}>
     */
    private array $routes = [];

    /** @var array<string,int> route name => index in $routes */
    private array $names = [];

    /** @var array<int,callable> Middleware applied to every route. */
    private array $globalMiddleware = [];

    /** @var array{prefix:string,middleware:array<int,callable>} */
    private array $group = ['prefix' => '', 'middleware' => []];

    private int $lastIndex = -1;

    /** @var callable|null Custom 404 handler. */
    private $notFound = null;

    // ── Registration ──────────────────────────────────────────────────────

    public function get(string $path, mixed $handler): self
    {
        return $this->add(['GET', 'HEAD'], $path, $handler);
    }

    public function post(string $path, mixed $handler): self
    {
        return $this->add(['POST'], $path, $handler);
    }

    public function put(string $path, mixed $handler): self
    {
        return $this->add(['PUT'], $path, $handler);
    }

    public function patch(string $path, mixed $handler): self
    {
        return $this->add(['PATCH'], $path, $handler);
    }

    public function delete(string $path, mixed $handler): self
    {
        return $this->add(['DELETE'], $path, $handler);
    }

    /**
     * Register a route for one or more HTTP methods.
     *
     * @param array<int,string> $methods
     */
    public function add(array $methods, string $path, mixed $handler): self
    {
        $path = $this->group['prefix'] . '/' . ltrim($path, '/');
        $path = '/' . trim($path, '/');

        $this->routes[] = [
            'methods'    => array_map('strtoupper', $methods),
            'path'       => $path,
            'regex'      => $this->compile($path),
            'handler'    => $handler,
            'name'       => null,
            'middleware' => $this->group['middleware'],
        ];
        $this->lastIndex = array_key_last($this->routes);

        return $this;
    }

    /**
     * Name the most recently added route (for url()).
     */
    public function name(string $name): self
    {
        if ($this->lastIndex >= 0) {
            $this->routes[$this->lastIndex]['name'] = $name;
            $this->names[$name] = $this->lastIndex;
        }
        return $this;
    }

    /**
     * Attach middleware to the most recently added route.
     */
    public function middleware(callable ...$middleware): self
    {
        if ($this->lastIndex >= 0) {
            foreach ($middleware as $mw) {
                $this->routes[$this->lastIndex]['middleware'][] = $mw;
            }
        }
        return $this;
    }

    /**
     * Register middleware applied to every route.
     */
    public function useMiddleware(callable ...$middleware): void
    {
        foreach ($middleware as $mw) {
            $this->globalMiddleware[] = $mw;
        }
    }

    /**
     * Group routes under a shared path prefix and/or middleware stack.
     *
     * @param array{prefix?:string,middleware?:array<int,callable>} $attributes
     */
    public function group(array $attributes, callable $routes): void
    {
        $previous = $this->group;

        $this->group = [
            'prefix'     => $previous['prefix'] . '/' . trim((string) ($attributes['prefix'] ?? ''), '/'),
            'middleware' => array_merge($previous['middleware'], $attributes['middleware'] ?? []),
        ];
        $this->group['prefix'] = rtrim($this->group['prefix'], '/');

        $routes($this);

        $this->group = $previous;
    }

    public function setNotFound(callable $handler): void
    {
        $this->notFound = $handler;
    }

    // ── Dispatch ──────────────────────────────────────────────────────────

    /**
     * Match the request to a route and run it through the middleware pipeline.
     */
    public function dispatch(Request $request): Response
    {
        $path = rtrim($request->path(), '/');
        if ($path === '') {
            $path = '/';
        }
        $method = $request->method();

        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }
            if (!in_array($method, $route['methods'], true)) {
                $allowed = array_merge($allowed, $route['methods']);
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (!is_int($key)) {
                    $params[$key] = $value;
                }
            }

            return $this->runPipeline($route, $request, $params);
        }

        if ($allowed !== []) {
            return Response::error('Method not allowed', 405)
                ->header('Allow', implode(', ', array_unique($allowed)));
        }

        return $this->handleNotFound($request);
    }

    /**
     * Compose middleware around the handler and execute.
     *
     * @param array{methods:array<int,string>,path:string,regex:string,handler:mixed,name:?string,middleware:array<int,callable>} $route
     * @param array<string,string> $params
     */
    private function runPipeline(array $route, Request $request, array $params): Response
    {
        $core = function (Request $req) use ($route, $params): Response {
            return $this->toResponse(($this->resolveHandler($route['handler']))($req, $params));
        };

        $stack = array_merge($this->globalMiddleware, $route['middleware']);
        $pipeline = $core;
        foreach (array_reverse($stack) as $middleware) {
            $next = $pipeline;
            $pipeline = static function (Request $req) use ($middleware, $next): Response {
                return $middleware($req, $next);
            };
        }

        return $pipeline($request);
    }

    /**
     * Normalize a handler into a callable.
     */
    private function resolveHandler(mixed $handler): callable
    {
        if (is_callable($handler)) {
            return $handler;
        }
        // [ClassName::class, 'method'] — instantiate and bind.
        if (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
            $instance = new $handler[0]();
            return [$instance, $handler[1]];
        }
        throw new \InvalidArgumentException('Route handler is not callable.');
    }

    /**
     * Coerce a handler return value into a Response.
     */
    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result)) {
            return Response::json($result);
        }
        return Response::html((string) $result);
    }

    private function handleNotFound(Request $request): Response
    {
        if ($this->notFound !== null) {
            return $this->toResponse(($this->notFound)($request));
        }
        return Response::error('Not found', 404);
    }

    // ── Reverse routing ───────────────────────────────────────────────────

    /**
     * Generate a URL for a named route. Unused params become a query string.
     *
     * @param array<string,string|int> $params
     */
    public function url(string $name, array $params = []): string
    {
        if (!isset($this->names[$name])) {
            throw new \InvalidArgumentException("Route '{$name}' is not defined.");
        }

        $path = $this->routes[$this->names[$name]]['path'];
        $used = [];

        $path = preg_replace_callback('/\{(\w+)(?::[^}]+)?\}/', static function (array $m) use ($params, &$used): string {
            $key = $m[1];
            if (!array_key_exists($key, $params)) {
                throw new \InvalidArgumentException("Missing parameter '{$key}' for route URL.");
            }
            $used[$key] = true;
            return rawurlencode((string) $params[$key]);
        }, $path) ?? $path;

        $query = array_diff_key($params, $used);
        return Helpers::buildUrl($path, $query);
    }

    // ── Compilation ───────────────────────────────────────────────────────

    /**
     * Turn a route path into an anchored regex with named capture groups.
     */
    private function compile(string $path): string
    {
        $regex = preg_replace_callback('/\{(\w+)(?::([^}]+))?\}/', static function (array $m): string {
            $name = $m[1];
            $pattern = $m[2] ?? '[^/]+';
            return '(?P<' . $name . '>' . $pattern . ')';
        }, $path);

        return '#^' . ($regex ?? $path) . '$#';
    }
}
