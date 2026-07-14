<?php

/**
 * includes/Core/Upload.php
 * ==========================================================================
 * Secure file-upload handler.
 *
 * Enforces the upload defenses the platform mandates before a file ever
 * touches disk: real MIME detection (via finfo, not the client header),
 * extension allow-listing, a maximum size, a randomized server-side filename,
 * and destination-directory containment (no path traversal).
 *
 * validate() pre-checks a $_FILES entry; store() validates then moves the file
 * and returns its stored path relative to the destination. On failure store()
 * throws a RuntimeException with the first error message.
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Core;

use RuntimeException;

final class Upload
{
    /** @var array<int,string> */
    private array $errors = [];

    /**
     * @param string            $destination      Absolute base directory files are stored under.
     * @param array<int,string> $allowedMimes     Allowed MIME types (empty = allow any).
     * @param array<int,string> $allowedExtensions Allowed lowercase extensions (empty = allow any).
     * @param int               $maxBytes         Maximum file size in bytes.
     */
    public function __construct(
        private string $destination,
        private array $allowedMimes = [],
        private array $allowedExtensions = [],
        private int $maxBytes = 8_388_608,
        private ?Logger $logger = null,
    ) {
        $this->destination = rtrim($destination, "/\\");
    }

    /**
     * @return array<int,string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors[0] ?? null;
    }

    // ── Validation ────────────────────────────────────────────────────────

    /**
     * Validate a $_FILES entry without moving it.
     *
     * @param array<string,mixed> $file
     */
    public function validate(array $file): bool
    {
        $this->errors = [];

        $error = (int) ($file['error'] ?? \UPLOAD_ERR_NO_FILE);
        if ($error !== \UPLOAD_ERR_OK) {
            $this->errors[] = $this->uploadErrorMessage($error);
            return false;
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $this->errors[] = 'The file was not uploaded properly.';
            return false;
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size > $this->maxBytes) {
            $this->errors[] = sprintf('The file exceeds the maximum size of %d bytes.', $this->maxBytes);
        }

        $extension = $this->extension((string) ($file['name'] ?? ''));
        if ($this->allowedExtensions !== [] && !in_array($extension, $this->allowedExtensions, true)) {
            $this->errors[] = 'The file extension is not allowed.';
        }

        $mime = $this->detectMime($tmp);
        if ($this->allowedMimes !== [] && !in_array($mime, $this->allowedMimes, true)) {
            $this->errors[] = 'The file type is not allowed.';
        }

        return $this->errors === [];
    }

    // ── Storage ───────────────────────────────────────────────────────────

    /**
     * Validate and move an uploaded file. Returns the stored path relative to
     * the destination (e.g. "avatars/ab12cd34.png").
     *
     * @param array<string,mixed> $file   A $_FILES entry.
     * @param string|null         $subdir Optional subdirectory under destination.
     */
    public function store(array $file, ?string $subdir = null): string
    {
        if (!$this->validate($file)) {
            $message = $this->firstError() ?? 'Invalid file upload.';
            $this->logger?->warning('Upload rejected: {message}', ['message' => $message]);
            throw new RuntimeException($message);
        }

        $dir = $this->resolveDirectory($subdir);
        $extension = $this->extension((string) $file['name']);
        $filename = $this->randomName($extension);
        $target = $dir . \DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            throw new RuntimeException('Failed to store the uploaded file.');
        }

        return ltrim(($subdir !== null ? trim($subdir, "/\\") . '/' : '') . $filename, '/');
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * Resolve (and create) the target directory, guaranteeing it stays within
     * the configured destination — defeats "../" traversal in $subdir.
     */
    private function resolveDirectory(?string $subdir): string
    {
        $base = $this->destination;
        if (!is_dir($base) && !@mkdir($base, 0775, true) && !is_dir($base)) {
            throw new RuntimeException('Upload destination is not writable.');
        }

        $target = $base;
        if ($subdir !== null && $subdir !== '') {
            $clean = str_replace(['..', "\0"], '', $subdir);
            $target = $base . \DIRECTORY_SEPARATOR . trim($clean, "/\\");
            if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
                throw new RuntimeException('Failed to create the upload subdirectory.');
            }
        }

        $realBase = realpath($base);
        $realTarget = realpath($target);
        if ($realBase === false || $realTarget === false || !str_starts_with($realTarget, $realBase)) {
            throw new RuntimeException('Resolved upload path escapes the destination directory.');
        }

        return $realTarget;
    }

    private function detectMime(string $path): string
    {
        if (!class_exists('finfo')) {
            return (string) (mime_content_type($path) ?: 'application/octet-stream');
        }
        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        return (string) ($finfo->file($path) ?: 'application/octet-stream');
    }

    private function extension(string $name): string
    {
        return strtolower(pathinfo($name, \PATHINFO_EXTENSION));
    }

    private function randomName(string $extension): string
    {
        $base = bin2hex(random_bytes(8));
        return $extension === '' ? $base : $base . '.' . $extension;
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
            \UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded.',
            \UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            \UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on the server.',
            \UPLOAD_ERR_CANT_WRITE => 'Failed to write the file to disk.',
            \UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
            default                => 'Unknown upload error.',
        };
    }
}
