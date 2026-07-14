<?php

/**
 * includes/Core/Request.php
 * ==========================================================================
 * Immutable value object describing the current HTTP request.
 *
 * Instead of reaching into $_GET / $_POST / $_SERVER / $_FILES throughout the
 * codebase, controllers receive a Request and read everything through one
 * typed API: query and body params (including JSON bodies), uploaded files,
 * headers, method, client IP, and user agent.
 *
 * Built from the superglobals via capture(), or constructed directly from
 * arrays in tests. The Router passes a Request to every route handler and
 * middleware.
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Core;

final class Request
{
    /** @var array<string,mixed>|null Lazily decoded JSON body. */
    private ?array $jsonCache = null;

    /**
     * @param array<string,mixed> $query   $_GET
     * @param array<string,mixed> $request $_POST
     * @param array<string,mixed> $server  $_SERVER
     * @param array<string,mixed> $cookies $_COOKIE
     * @param array<string,mixed> $files   $_FILES
     * @param string              $rawBody Raw request body (php://input)
     */
    public function __construct(
        private array $query = [],
        private array $request = [],
        private array $server = [],
        private array $cookies = [],
        private array $files = [],
        private string $rawBody = '',
    ) {
    }

    /**
     * Build a Request from PHP's superglobals.
     */
    public static function capture(): self
    {
        $raw = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            $raw = (string) file_get_contents('php://input');
        }

        return new self($_GET, $_POST, $_SERVER, $_COOKIE, $_FILES, $raw);
    }

    // ── Input ─────────────────────────────────────────────────────────────

    /** Read a query-string ($_GET) parameter. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** Read a body ($_POST) parameter. */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->request[$key] ?? $default;
    }

    /**
     * Read a parameter from the merged set (JSON body, then POST, then query).
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * All input merged: query ∪ post ∪ json (later sources win).
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->request, $this->isJson() ? $this->json() : []);
    }

    /**
     * @param string[] $keys
     * @return array<string,mixed>
     */
    public function only(array $keys): array
    {
        return Helpers::only($this->all(), $keys);
    }

    /**
     * @param string[] $keys
     * @return array<string,mixed>
     */
    public function except(array $keys): array
    {
        return Helpers::except($this->all(), $keys);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /** @return array<string,mixed> */
    public function query(): array
    {
        return $this->query;
    }

    // ── JSON ──────────────────────────────────────────────────────────────

    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('Content-Type') ?? ''), 'application/json');
    }

    /**
     * Decode the JSON body. With a key, returns that member; without, the whole
     * decoded array.
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if ($this->jsonCache === null) {
            $decoded = json_decode($this->rawBody, true);
            $this->jsonCache = is_array($decoded) ? $decoded : [];
        }
        if ($key === null) {
            return $this->jsonCache;
        }
        return $this->jsonCache[$key] ?? $default;
    }

    // ── Files ─────────────────────────────────────────────────────────────

    /**
     * A single uploaded file descriptor ($_FILES entry), or null.
     *
     * @return array<string,mixed>|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) ? $file : null;
    }

    public function hasFile(string $key): bool
    {
        $file = $this->file($key);
        return $file !== null && (int) ($file['error'] ?? \UPLOAD_ERR_NO_FILE) === \UPLOAD_ERR_OK;
    }

    /** @return array<string,mixed> */
    public function files(): array
    {
        return $this->files;
    }

    // ── Headers ───────────────────────────────────────────────────────────

    /**
     * Read a header case-insensitively (derived from $_SERVER HTTP_* keys).
     */
    public function header(string $key, ?string $default = null): ?string
    {
        $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        if (isset($this->server[$normalized])) {
            return (string) $this->server[$normalized];
        }
        // Content-Type / Content-Length arrive without the HTTP_ prefix.
        $direct = strtoupper(str_replace('-', '_', $key));
        return isset($this->server[$direct]) ? (string) $this->server[$direct] : $default;
    }

    /**
     * All request headers as a name => value map.
     *
     * @return array<string,string>
     */
    public function headers(): array
    {
        $headers = [];
        foreach ($this->server as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string) $key, 5)))));
                $headers[$name] = (string) $value;
            }
        }
        return $headers;
    }

    /**
     * Extract a Bearer token from the Authorization header, if present.
     */
    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization') ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * The CSRF token supplied by the client (header or input field).
     */
    public function csrfToken(): ?string
    {
        $header = $this->header('X-CSRF-Token');
        if ($header !== null && $header !== '') {
            return $header;
        }
        $value = $this->input('csrf_token');
        return is_string($value) ? $value : null;
    }

    // ── Method / metadata ─────────────────────────────────────────────────

    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    public function isGet(): bool
    {
        return $this->isMethod('GET');
    }

    public function isPost(): bool
    {
        return $this->isMethod('POST');
    }

    /**
     * Was this an AJAX / XHR request?
     */
    public function isAjax(): bool
    {
        return strtolower($this->header('X-Requested-With') ?? '') === 'xmlhttprequest';
    }

    /**
     * Client IP address. By default returns REMOTE_ADDR only; set
     * $trustProxy = true to honor X-Forwarded-For (only behind a trusted proxy).
     */
    public function ip(bool $trustProxy = false): string
    {
        if ($trustProxy) {
            $forwarded = $this->header('X-Forwarded-For');
            if ($forwarded !== null && $forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if ($first !== '') {
                    return $first;
                }
            }
        }
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return (string) ($this->server['HTTP_USER_AGENT'] ?? '');
    }

    /**
     * Full request URI (path + query string).
     */
    public function uri(): string
    {
        return (string) ($this->server['REQUEST_URI'] ?? '/');
    }

    /**
     * Request path without the query string.
     */
    public function path(): string
    {
        $path = parse_url($this->uri(), \PHP_URL_PATH);
        return is_string($path) ? $path : '/';
    }
}
