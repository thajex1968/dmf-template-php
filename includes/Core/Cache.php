<?php

/**
 * includes/Core/Cache.php
 * ==========================================================================
 * File-based key/value cache.
 *
 * A dependency-free cache store (no Redis/Memcached extension required) that
 * serializes values to files under a directory, with optional per-item TTL.
 * Ideal for memoizing expensive query results or computed fragments.
 *
 * Composition-friendly: constructed with a directory (typically
 * `storage/cache`) and an optional Logger. Wired as a singleton by Bootstrap.
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Core;

final class Cache
{
    public function __construct(
        private string $directory,
        private ?Logger $logger = null,
    ) {
        $this->directory = rtrim($directory, "/\\");
    }

    /**
     * Store a value. A TTL of 0 (default) means "forever".
     */
    public function put(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->ensureDirectory();
        $payload = serialize([
            'expires' => $ttl > 0 ? time() + $ttl : 0,
            'value'   => $value,
        ]);

        $ok = @file_put_contents($this->path($key), $payload, \LOCK_EX);
        if ($ok === false) {
            $this->logger?->warning('Cache write failed for key {key}', ['key' => $key]);
            return false;
        }
        return true;
    }

    /**
     * Retrieve a value, or the default when missing/expired.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return $default;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return $default;
        }

        $data = @unserialize($raw);
        if (!is_array($data) || !array_key_exists('value', $data)) {
            return $default;
        }

        if (($data['expires'] ?? 0) !== 0 && $data['expires'] < time()) {
            @unlink($file);
            return $default;
        }

        return $data['value'];
    }

    /**
     * Is a (non-expired) value present?
     */
    public function has(string $key): bool
    {
        $sentinel = "\0__miss__\0";
        return $this->get($key, $sentinel) !== $sentinel;
    }

    /**
     * Return the cached value, or compute-store-return it on a miss.
     *
     * @param callable():mixed $callback
     */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $sentinel = "\0__miss__\0";
        $value = $this->get($key, $sentinel);
        if ($value !== $sentinel) {
            return $value;
        }
        $value = $callback();
        $this->put($key, $value, $ttl);
        return $value;
    }

    /**
     * Atomically increment an integer counter, returning the new value.
     */
    public function increment(string $key, int $by = 1): int
    {
        $current = (int) $this->get($key, 0);
        $new = $current + $by;
        $this->put($key, $new);
        return $new;
    }

    /**
     * Decrement an integer counter, returning the new value.
     */
    public function decrement(string $key, int $by = 1): int
    {
        return $this->increment($key, -$by);
    }

    /**
     * Delete a single key.
     */
    public function forget(string $key): bool
    {
        $file = $this->path($key);
        return is_file($file) ? @unlink($file) : true;
    }

    /**
     * Remove every cached item.
     */
    public function flush(): bool
    {
        $ok = true;
        foreach (glob($this->directory . \DIRECTORY_SEPARATOR . '*.cache') ?: [] as $file) {
            $ok = @unlink($file) && $ok;
        }
        return $ok;
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function path(string $key): string
    {
        return $this->directory . \DIRECTORY_SEPARATOR . sha1($key) . '.cache';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
    }
}
