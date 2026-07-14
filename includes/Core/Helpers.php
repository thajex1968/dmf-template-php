<?php

/**
 * includes/Core/Helpers.php
 * ==========================================================================
 * Stateless utility functions grouped by concern: security, strings, arrays,
 * dates, and URLs. Pure and side-effect-free — every method is static and
 * deterministic given its input, which makes them trivially testable and safe
 * to reuse anywhere in the platform.
 *
 * Other Core classes lean on the Security and Array helpers (e.g. Config uses
 * dot-notation array access; Session uses randomToken()).
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Core;

final class Helpers
{
    // ── Security ──────────────────────────────────────────────────────────

    /**
     * Escape a value for safe HTML output (XSS defense).
     */
    public static function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Generate a cryptographically secure random hex token.
     */
    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes(max(1, $bytes)));
    }

    /**
     * Timing-safe string comparison (for tokens, signatures).
     */
    public static function hashEquals(string $known, string $userSupplied): bool
    {
        return hash_equals($known, $userSupplied);
    }

    /**
     * Is a redirect target a safe local path? Rejects absolute URLs and
     * protocol-relative (`//host`) targets to prevent open redirects.
     */
    public static function isLocalRedirect(string $url): bool
    {
        return $url !== '' && $url[0] === '/' && !str_starts_with($url, '//');
    }

    // ── Strings ───────────────────────────────────────────────────────────

    /**
     * URL/filename-safe slug.
     */
    public static function slug(string $value, string $separator = '-'): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/u', $separator, $value) ?? '';
        return trim($value, $separator);
    }

    /**
     * Truncate a string to a maximum length, appending an ellipsis.
     */
    public static function limit(string $value, int $limit = 100, string $end = '…'): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }
        return rtrim(mb_substr($value, 0, $limit)) . $end;
    }

    /**
     * Random alphanumeric string (IDs, temporary codes).
     */
    public static function randomString(int $length = 16): string
    {
        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < max(1, $length); $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    /**
     * Mask a sensitive string, revealing only the last N characters.
     */
    public static function mask(string $value, int $visible = 4, string $mask = '*'): string
    {
        $len = mb_strlen($value);
        if ($len <= $visible) {
            return str_repeat($mask, $len);
        }
        return str_repeat($mask, $len - $visible) . mb_substr($value, -$visible);
    }

    /**
     * snake_case a string.
     */
    public static function snake(string $value, string $delimiter = '_'): string
    {
        $value = preg_replace('/\s+/u', '', ucwords($value)) ?? $value;
        $value = preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value) ?? $value;
        return strtolower($value);
    }

    /**
     * camelCase a string.
     */
    public static function camel(string $value): string
    {
        $studly = str_replace([' ', '-', '_'], '', ucwords($value, ' -_'));
        return lcfirst($studly);
    }

    // ── Arrays (dot notation) ─────────────────────────────────────────────

    /**
     * Read a nested array value using "dot.notation".
     *
     * @param array<string,mixed> $array
     */
    public static function get(array $array, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }
        $segment = $array;
        foreach (explode('.', $key) as $part) {
            if (is_array($segment) && array_key_exists($part, $segment)) {
                $segment = $segment[$part];
            } else {
                return $default;
            }
        }
        return $segment;
    }

    /**
     * Write a nested array value using "dot.notation".
     *
     * @param array<string,mixed> $array
     */
    public static function set(array &$array, string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref = &$array;
        foreach ($segments as $i => $part) {
            if ($i === count($segments) - 1) {
                $ref[$part] = $value;
                return;
            }
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
    }

    /**
     * Does a nested "dot.notation" key exist?
     *
     * @param array<string,mixed> $array
     */
    public static function has(array $array, string $key): bool
    {
        $sentinel = "\0__missing__\0";
        return self::get($array, $key, $sentinel) !== $sentinel;
    }

    /**
     * Return only the given keys.
     *
     * @param array<string,mixed> $array
     * @param string[]            $keys
     * @return array<string,mixed>
     */
    public static function only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    /**
     * Return everything except the given keys.
     *
     * @param array<string,mixed> $array
     * @param string[]            $keys
     * @return array<string,mixed>
     */
    public static function except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }

    /**
     * Extract a single column from a list of rows.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,mixed>
     */
    public static function pluck(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && array_key_exists($key, $row)) {
                $out[] = $row[$key];
            }
        }
        return $out;
    }

    // ── Dates ─────────────────────────────────────────────────────────────

    /**
     * Current timestamp formatted (default MySQL DATETIME).
     */
    public static function now(string $format = 'Y-m-d H:i:s'): string
    {
        return date($format);
    }

    /**
     * ISO-8601 timestamp for the current moment.
     */
    public static function isoNow(): string
    {
        return date('c');
    }

    /**
     * Reformat a date string or timestamp.
     */
    public static function formatDate(string|int $date, string $format = 'Y-m-d'): string
    {
        $ts = is_int($date) ? $date : strtotime($date);
        return $ts === false ? '' : date($format, $ts);
    }

    // ── URLs ──────────────────────────────────────────────────────────────

    /**
     * Append a query string to a base URL/path.
     *
     * @param array<string,scalar> $params
     */
    public static function buildUrl(string $base, array $params = []): string
    {
        if ($params === []) {
            return $base;
        }
        $separator = str_contains($base, '?') ? '&' : '?';
        return $base . $separator . http_build_query($params);
    }

    /**
     * The current request path + query (empty on CLI).
     */
    public static function currentUrl(): string
    {
        return (string) ($_SERVER['REQUEST_URI'] ?? '');
    }
}
