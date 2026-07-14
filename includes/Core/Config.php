<?php

/**
 * includes/Core/Config.php
 * ==========================================================================
 * Environment loader + configuration manager.
 *
 * Two responsibilities:
 *   1. Parse the project's `.env` file (dependency-free) and expose values via
 *      env(), with sensible literal coercion (true/false/null/numbers).
 *   2. Act as an in-memory configuration store addressable by "dot.notation",
 *      seeded from application arrays and/or environment values.
 *
 * This is the single source of truth other Core classes read their settings
 * from — Database, Session, Logger, and Upload are all configured from here.
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Core;

final class Config
{
    /** @var array<string,string> Raw key/value pairs parsed from .env. */
    private array $env = [];

    /** @var array<string,mixed> Application configuration tree. */
    private array $items = [];

    private static ?Config $instance = null;

    /**
     * @param string|null $basePath Directory containing the `.env` file.
     *                              Defaults to the project root (two levels up).
     */
    public function __construct(?string $basePath = null)
    {
        $basePath ??= \dirname(__DIR__, 2);
        $this->loadEnv($basePath . \DIRECTORY_SEPARATOR . '.env');
    }

    /**
     * Shared singleton accessor (lazily constructed).
     */
    public static function instance(?string $basePath = null): self
    {
        return self::$instance ??= new self($basePath);
    }

    /**
     * Replace the shared singleton (useful in tests).
     */
    public static function setInstance(?Config $config): void
    {
        self::$instance = $config;
    }

    // ── Environment ───────────────────────────────────────────────────────

    /**
     * Parse a `.env` file into the internal map. Missing files are ignored so
     * the app can run purely on real environment variables if desired.
     */
    private function loadEnv(string $path): void
    {
        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            // Strip wrapping quotes or a trailing inline comment.
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                $end = strpos($value, $quote, 1);
                $value = $end !== false ? substr($value, 1, $end - 1) : substr($value, 1);
            } elseif (str_contains($value, ' #')) {
                $value = trim(substr($value, 0, (int) strpos($value, ' #')));
            }

            $this->env[$name] = $value;
        }
    }

    /**
     * Read an environment value with literal coercion.
     */
    public function env(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, $this->env)) {
            return $default;
        }

        $value = $this->env[$key];
        return match (strtolower($value)) {
            'true'     => true,
            'false'    => false,
            'null', '' => $default,
            default    => $value,
        };
    }

    // ── Configuration store (dot notation) ────────────────────────────────

    /**
     * Merge an application configuration array into the store.
     *
     * @param array<string,mixed> $config
     */
    public function load(array $config): void
    {
        $this->items = array_replace_recursive($this->items, $config);
    }

    /**
     * Read a config value by "dot.notation".
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Helpers::get($this->items, $key, $default);
    }

    /**
     * Set a config value by "dot.notation".
     */
    public function set(string $key, mixed $value): void
    {
        Helpers::set($this->items, $key, $value);
    }

    /**
     * Does a config key exist?
     */
    public function has(string $key): bool
    {
        return Helpers::has($this->items, $key);
    }

    /**
     * The full configuration tree.
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    // ── Typed convenience getters ─────────────────────────────────────────

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $this->env($key, $default));
        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $this->env($key, $default));
        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $this->env($key, $default));
        if (is_bool($value)) {
            return $value;
        }
        return match (strtolower((string) $value)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }
}
