<?php

/**
 * includes/Core/Bootstrap.php
 * ==========================================================================
 * Application kernel + minimal service container.
 *
 * Bootstrap is the composition root that wires the whole Core Framework
 * together and drives the request lifecycle. It:
 *   1. boot()      — loads Config, sets timezone + error/shutdown handlers,
 *                    registers every Core service as a lazy singleton, and
 *                    starts the secure Session.
 *   2. handle()    — runs a Request through the global middleware pipeline into
 *                    the Router and returns a Response.
 *   3. run()       — captures the request, handles it, sends the response, and
 *                    terminates.
 *
 * The container is intentionally tiny (bind/singleton/make) and dependency-free;
 * it only ever constructs existing Core classes via composition — nothing here
 * modifies or subclasses them.
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Core;

use RuntimeException;
use Throwable;

final class Bootstrap
{
    /** @var array<string,callable> Service factories keyed by id. */
    private array $factories = [];

    /** @var array<string,mixed> Resolved singleton instances. */
    private array $instances = [];

    /** @var array<int,callable> Global middleware run before every dispatch. */
    private array $globalMiddleware = [];

    private bool $booted = false;

    public function __construct(private string $basePath)
    {
        $this->basePath = rtrim($basePath, "/\\");
    }

    // ── Boot ──────────────────────────────────────────────────────────────

    /**
     * Prepare the application: config, error handling, services, session.
     */
    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }

        $config = Config::instance($this->basePath);
        $this->instances['config'] = $config;

        date_default_timezone_set($config->string('TIMEZONE', 'Asia/Bangkok'));

        $this->registerCoreServices();
        $this->registerErrorHandling();
        $this->session()->start();

        $this->booted = true;
        $this->events()->dispatch('app.booted', $this);

        return $this;
    }

    /**
     * Register the Core services as lazy singletons. Nothing connects or does
     * I/O until first resolved.
     */
    private function registerCoreServices(): void
    {
        $this->singleton('logger', fn(): Logger => new Logger(
            $this->config()->string('LOG_PATH', $this->basePath . '/storage/logs'),
            $this->config()->string('LOG_LEVEL', Logger::DEBUG),
            $this->config()->string('LOG_CHANNEL', 'app'),
        ));

        $this->singleton('db', function (): Database {
            Database::addConnection('default', [
                'host'     => $this->config()->string('DB_HOST', '127.0.0.1'),
                'port'     => $this->config()->string('DB_PORT', '3306'),
                'database' => $this->config()->string('DB_DATABASE', ''),
                'username' => $this->config()->string('DB_USERNAME', ''),
                'password' => $this->config()->string('DB_PASSWORD', ''),
            ]);
            return Database::connection('default', $this->logger());
        });

        $this->singleton('session', fn(): Session => new Session(
            $this->config()->int('SESSION_TIMEOUT', 1800)
        ));

        $this->singleton('cache', fn(): Cache => new Cache(
            $this->config()->string('CACHE_PATH', $this->basePath . '/storage/cache'),
            $this->logger(),
        ));

        $this->singleton('view', fn(): View => new View(
            $this->config()->string('VIEW_PATH', $this->basePath . '/views')
        ));

        $this->singleton('events', fn(): Event => new Event());

        $this->singleton('router', fn(): Router => new Router());

        $this->singleton('mail', fn(): Mail => new Mail([
            'host'       => $this->config()->string('MAIL_HOST', ''),
            'port'       => $this->config()->int('MAIL_PORT', 587),
            'username'   => $this->config()->string('MAIL_USERNAME', ''),
            'password'   => $this->config()->string('MAIL_PASSWORD', ''),
            'encryption' => $this->config()->string('MAIL_ENCRYPTION', 'tls'),
            'from'       => $this->config()->string('MAIL_FROM', 'no-reply@dmf.ac.th'),
            'from_name'  => $this->config()->string('APP_NAME', 'DMF'),
        ], $this->logger()));
    }

    // ── Container ─────────────────────────────────────────────────────────

    /**
     * Register a factory that is re-invoked on every make() (non-shared).
     */
    public function bind(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    /**
     * Register a factory whose result is cached (shared).
     */
    public function singleton(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    /**
     * Register a pre-built instance.
     */
    public function instance(string $id, mixed $object): void
    {
        $this->instances[$id] = $object;
    }

    /**
     * Resolve a service by id.
     */
    public function make(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }
        throw new RuntimeException("Service '{$id}' is not registered.");
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    // ── Typed accessors ───────────────────────────────────────────────────

    public function config(): Config
    {
        $s = $this->make('config');
        return $s instanceof Config ? $s : throw new RuntimeException('config service is invalid.');
    }

    public function logger(): Logger
    {
        $s = $this->make('logger');
        return $s instanceof Logger ? $s : throw new RuntimeException('logger service is invalid.');
    }

    public function db(): Database
    {
        $s = $this->make('db');
        return $s instanceof Database ? $s : throw new RuntimeException('db service is invalid.');
    }

    public function session(): Session
    {
        $s = $this->make('session');
        return $s instanceof Session ? $s : throw new RuntimeException('session service is invalid.');
    }

    public function cache(): Cache
    {
        $s = $this->make('cache');
        return $s instanceof Cache ? $s : throw new RuntimeException('cache service is invalid.');
    }

    public function view(): View
    {
        $s = $this->make('view');
        return $s instanceof View ? $s : throw new RuntimeException('view service is invalid.');
    }

    public function events(): Event
    {
        $s = $this->make('events');
        return $s instanceof Event ? $s : throw new RuntimeException('events service is invalid.');
    }

    public function router(): Router
    {
        $s = $this->make('router');
        return $s instanceof Router ? $s : throw new RuntimeException('router service is invalid.');
    }

    public function mail(): Mail
    {
        $s = $this->make('mail');
        return $s instanceof Mail ? $s : throw new RuntimeException('mail service is invalid.');
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────

    /**
     * Add global middleware run around every request.
     */
    public function middleware(callable ...$middleware): self
    {
        foreach ($middleware as $mw) {
            $this->globalMiddleware[] = $mw;
        }
        return $this;
    }

    /**
     * Turn a Request into a Response: global middleware → Router. Uncaught
     * exceptions are logged and converted to a safe 500.
     */
    public function handle(Request $request): Response
    {
        $this->events()->dispatch('request.received', $request);

        try {
            $response = Middleware::run(
                $request,
                $this->globalMiddleware,
                fn(Request $req): Response => $this->router()->dispatch($req)
            );
        } catch (Throwable $e) {
            $this->logger()->error('Unhandled exception: {message}', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            $message = $this->config()->bool('APP_DEBUG', false) ? $e->getMessage() : 'Internal Server Error';
            $response = Response::error($message, 500);
        }

        $this->events()->dispatch('response.prepared', $response);
        return $response;
    }

    /**
     * Full run: boot (if needed), capture, handle, send, terminate.
     */
    public function run(): void
    {
        if (!$this->booted) {
            $this->boot();
        }

        $request = Request::capture();
        $response = $this->handle($request);
        $response->send();
        $this->terminate($request, $response);
    }

    /**
     * Post-response work (flush-and-continue hooks, cleanup, listeners).
     */
    public function terminate(Request $request, Response $response): void
    {
        $this->events()->dispatch('app.terminating', [
            'request'  => $request,
            'response' => $response,
        ]);
    }

    // ── Error handling ────────────────────────────────────────────────────

    /**
     * Install error, exception, and shutdown handlers that route through the
     * Logger and never leak internals in production.
     */
    private function registerErrorHandling(): void
    {
        $debug = $this->config()->bool('APP_DEBUG', false);
        error_reporting(\E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            $this->logger()->warning('{message} in {file}:{line}', [
                'message' => $message,
                'file'    => $file,
                'line'    => $line,
            ]);
            // Return false so PHP's own handler still runs (respects display_errors).
            return false;
        });

        set_exception_handler(function (Throwable $e): void {
            $this->logger()->critical('Uncaught exception: {message}', ['message' => $e->getMessage()]);
            if (!headers_sent()) {
                $message = $this->config()->bool('APP_DEBUG', false) ? $e->getMessage() : 'Internal Server Error';
                Response::error($message, 500)->send();
            }
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [\E_ERROR, \E_PARSE, \E_CORE_ERROR, \E_COMPILE_ERROR], true)) {
                $this->logger()->emergency('Fatal error: {message} in {file}:{line}', [
                    'message' => $error['message'],
                    'file'    => $error['file'],
                    'line'    => $error['line'],
                ]);
                if (!headers_sent()) {
                    $message = $this->config()->bool('APP_DEBUG', false) ? $error['message'] : 'Internal Server Error';
                    Response::error($message, 500)->send();
                }
            }
        });
    }
}
