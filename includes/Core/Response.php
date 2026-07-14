<?php

/**
 * includes/Core/Response.php
 * ==========================================================================
 * HTTP response builder.
 *
 * A small, chainable value object that accumulates status, headers, and body,
 * then emits them with send(). Static factories cover the response types every
 * application needs — json(), html(), text(), redirect(), download(),
 * noContent() — plus success()/error() which produce the platform's documented
 * JSON envelope ({ success, data | message, errors }).
 *
 * Router handlers return a Response; the front controller calls send() once.
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Core;

use JsonSerializable;

final class Response
{
    /** @var array<string,string> */
    private array $headers = [];

    private ?string $downloadPath = null;
    private ?string $downloadName = null;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }
    }

    // ── Fluent builders ───────────────────────────────────────────────────

    public function setStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    // ── Static factories ──────────────────────────────────────────────────

    /**
     * JSON response.
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $body = json_encode(
            $data instanceof JsonSerializable ? $data->jsonSerialize() : $data,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
        );
        return new self($body === false ? '{}' : $body, $status, [
            'Content-Type' => 'application/json; charset=utf-8',
        ] + $headers);
    }

    /**
     * HTML response.
     */
    public static function html(string $html, int $status = 200, array $headers = []): self
    {
        return new self($html, $status, [
            'Content-Type' => 'text/html; charset=utf-8',
        ] + $headers);
    }

    /**
     * Plain-text response.
     */
    public static function text(string $text, int $status = 200): self
    {
        return new self($text, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * Redirect response.
     */
    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    /**
     * No-content (204) response.
     */
    public static function noContent(): self
    {
        return new self('', 204);
    }

    /**
     * File download response. The file is streamed at send() time.
     */
    public static function download(string $path, ?string $name = null, array $headers = []): self
    {
        $response = new self('', 200, $headers);
        $response->downloadPath = $path;
        $response->downloadName = $name ?? basename($path);
        return $response;
    }

    // ── Documented JSON envelope (see API.md) ─────────────────────────────

    /**
     * Success envelope: { "success": true, "data": ..., "meta"?: ... }.
     *
     * @param array<string,mixed> $meta
     */
    public static function success(mixed $data = null, int $status = 200, array $meta = []): self
    {
        $payload = ['success' => true, 'data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        return self::json($payload, $status);
    }

    /**
     * Error envelope: { "success": false, "message": ..., "errors"?: ... }.
     *
     * @param array<string,mixed> $errors
     */
    public static function error(string $message, int $status = 400, array $errors = []): self
    {
        $payload = ['success' => false, 'message' => $message];
        if ($errors !== []) {
            $payload['errors'] = $errors;
        }
        return self::json($payload, $status);
    }

    // ── Emit ──────────────────────────────────────────────────────────────

    /**
     * Send status line, headers, and body to the client. Streams the file for
     * download responses. Safe to call once per request.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }

            if ($this->downloadPath !== null && is_file($this->downloadPath)) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename((string) $this->downloadName) . '"');
                header('Content-Length: ' . (string) filesize($this->downloadPath));
            }
        }

        if ($this->downloadPath !== null && is_file($this->downloadPath)) {
            readfile($this->downloadPath);
            return;
        }

        echo $this->body;
    }
}
