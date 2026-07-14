<?php

/**
 * includes/Core/Logger.php
 * ==========================================================================
 * PSR-3 inspired logger writing daily rotating files.
 *
 * Not a literal PSR-3 implementation (that would require the psr/log package,
 * which the "no Composer packages" rule forbids), but it mirrors the same
 * eight severity levels, the `{placeholder}` context interpolation, and the
 * level-method shortcuts (error(), info(), …).
 *
 * Logs are written to one file per day: `<directory>/<channel>-YYYY-MM-DD.log`.
 * Other Core classes accept an optional Logger for diagnostics (e.g. Database
 * logs connection failures, Upload logs rejected files).
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Core;

final class Logger
{
    // ── PSR-3 severity levels ─────────────────────────────────────────────
    public const EMERGENCY = 'emergency';
    public const ALERT     = 'alert';
    public const CRITICAL  = 'critical';
    public const ERROR     = 'error';
    public const WARNING   = 'warning';
    public const NOTICE    = 'notice';
    public const INFO      = 'info';
    public const DEBUG     = 'debug';

    /** @var array<string,int> Severity ordering (lower = more severe). */
    private const PRIORITIES = [
        self::EMERGENCY => 0,
        self::ALERT     => 1,
        self::CRITICAL  => 2,
        self::ERROR     => 3,
        self::WARNING   => 4,
        self::NOTICE    => 5,
        self::INFO      => 6,
        self::DEBUG     => 7,
    ];

    /**
     * @param string $directory Absolute path to the log directory.
     * @param string $minLevel  Minimum level to record (messages below are dropped).
     * @param string $channel   Log channel / filename prefix.
     */
    public function __construct(
        private string $directory,
        private string $minLevel = self::DEBUG,
        private string $channel = 'app',
    ) {
        $this->directory = rtrim($directory, "/\\");
    }

    // ── Level shortcuts ───────────────────────────────────────────────────

    /** @param array<string,mixed> $context */
    public function emergency(string $message, array $context = []): void
    {
        $this->log(self::EMERGENCY, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function alert(string $message, array $context = []): void
    {
        $this->log(self::ALERT, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function critical(string $message, array $context = []): void
    {
        $this->log(self::CRITICAL, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function notice(string $message, array $context = []): void
    {
        $this->log(self::NOTICE, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    // ── Core ──────────────────────────────────────────────────────────────

    /**
     * Record a message at an arbitrary level.
     *
     * @param array<string,mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!isset(self::PRIORITIES[$level]) || !$this->shouldLog($level)) {
            return;
        }

        $line = sprintf(
            '[%s] %s.%s: %s%s%s',
            date('c'),
            $this->channel,
            strtoupper($level),
            $this->interpolate($message, $context),
            $context === [] ? '' : ' ' . $this->encodeContext($context),
            \PHP_EOL,
        );

        $this->write($line);
    }

    /**
     * Is this level at or above the configured minimum?
     */
    private function shouldLog(string $level): bool
    {
        $min = self::PRIORITIES[$this->minLevel] ?? self::PRIORITIES[self::DEBUG];
        return self::PRIORITIES[$level] <= $min;
    }

    /**
     * Replace `{key}` placeholders with scalar context values (PSR-3 style).
     *
     * @param array<string,mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null || (is_object($value) && method_exists($value, '__toString'))) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }
        return strtr($message, $replacements);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function encodeContext(array $context): string
    {
        $json = json_encode($context, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $json === false ? '{}' : $json;
    }

    /**
     * Append a line to today's log file, creating the directory if needed.
     */
    private function write(string $line): void
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
        $file = $this->directory . \DIRECTORY_SEPARATOR . $this->channel . '-' . date('Y-m-d') . '.log';
        @file_put_contents($file, $line, \FILE_APPEND | \LOCK_EX);
    }
}
