<?php

/**
 * includes/env.php
 * ==========================================================================
 * Minimal, dependency-free `.env` loader.
 *
 * Reads the project's root `.env` file once and exposes values through the
 * env() helper. This keeps deployment secrets (DB, SMTP, app keys) OUT of the
 * source tree — the file is git-ignored — which is the platform's baseline
 * security standard. Only `.env.example` (placeholders) is committed.
 *
 * There is no framework dependency here on purpose: the runtime stays plain
 * procedural PHP, matching the reference implementation.
 * ==========================================================================
 */

declare(strict_types=1);

if (!function_exists('env')) {
    /**
     * Load and cache the root .env file, then return a single value.
     *
     * @param string          $key     Variable name (e.g. "DB_HOST").
     * @param string|int|bool|null $default Returned when the key is absent.
     * @return string|int|bool|null
     */
    function env(string $key, string|int|bool|null $default = null): string|int|bool|null
    {
        static $vars = null;

        if ($vars === null) {
            $vars = [];
            $path = dirname(__DIR__) . '/.env';

            if (is_readable($path)) {
                $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $line = trim($line);

                    // Skip comments and malformed lines.
                    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                        continue;
                    }

                    [$name, $value] = explode('=', $line, 2);
                    $name  = trim($name);
                    $value = trim($value);

                    // Strip surrounding quotes and any trailing inline comment.
                    if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                        $quote = $value[0];
                        $end   = strpos($value, $quote, 1);
                        $value = $end !== false ? substr($value, 1, $end - 1) : substr($value, 1);
                    } elseif (str_contains($value, ' #')) {
                        $value = trim(substr($value, 0, strpos($value, ' #')));
                    }

                    $vars[$name] = $value;
                }
            }
        }

        if (!array_key_exists($key, $vars)) {
            return $default;
        }

        // Normalize common literal forms.
        return match (strtolower($vars[$key])) {
            'true'       => true,
            'false'      => false,
            'null', ''   => $default,
            default      => $vars[$key],
        };
    }
}
