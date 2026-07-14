<?php

/**
 * includes/Core/View.php
 * ==========================================================================
 * Plain-PHP template renderer with layout inheritance.
 *
 * No template compiler and no third-party engine — templates are ordinary
 * `.php` files rendered with output buffering. Supports shared data, a simple
 * section/layout system (extend + sections), and auto-escaping via Helpers::e().
 *
 * Templates receive `$this` (the View) so they can call:
 *   $this->extend('layouts/main');
 *   $this->start('sidebar'); ... $this->stop();
 *   $this->section('content');
 *   $this->e($value);
 *
 * Returns rendered HTML as a string; hand it to Response::html().
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Core;

use RuntimeException;

final class View
{
    /** @var array<string,mixed> Data shared with every template. */
    private array $shared = [];

    /** @var array<string,string> Captured named sections. */
    private array $sections = [];

    /** @var array<int,string> Open section names (buffering stack). */
    private array $sectionStack = [];

    private ?string $layout = null;

    public function __construct(
        private string $path,
        private string $extension = '.php',
    ) {
        $this->path = rtrim($path, "/\\");
    }

    /**
     * Expose a value to every template rendered by this instance.
     */
    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * Does a template exist?
     */
    public function exists(string $name): bool
    {
        return is_file($this->resolve($name));
    }

    /**
     * Render a template to a string, resolving any layout it extends.
     *
     * @param array<string,mixed> $data
     */
    public function render(string $name, array $data = []): string
    {
        $this->layout = null;
        $this->sections = [];
        $this->sectionStack = [];

        $content = $this->evaluate($name, $data);

        // Resolve the layout chain (a layout may itself extend another).
        while ($this->layout !== null) {
            $layout = $this->layout;
            $this->layout = null;
            if (!isset($this->sections['content'])) {
                $this->sections['content'] = $content;
            }
            $content = $this->evaluate($layout, $data);
        }

        return $content;
    }

    // ── Template-facing API ───────────────────────────────────────────────

    /**
     * Declare that the current template extends a layout.
     */
    public function extend(string $layout): void
    {
        $this->layout = $layout;
    }

    /**
     * Begin capturing a named section.
     */
    public function start(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    /**
     * Finish capturing the current section.
     */
    public function stop(): void
    {
        if ($this->sectionStack === []) {
            return;
        }
        $name = array_pop($this->sectionStack);
        $this->sections[$name] = (string) ob_get_clean();
    }

    /**
     * Output a captured section (or a default).
     */
    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /**
     * Escape a value for safe output (delegates to Helpers::e()).
     */
    public function e(string|int|float|null $value): string
    {
        return Helpers::e($value);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * Include a template within an isolated scope and capture its output.
     *
     * The scope is a closure bound to $this with deliberately obscure local
     * names, so template variables (including keys like "name" or "data") never
     * collide with the engine's own locals.
     *
     * @param array<string,mixed> $data
     */
    private function evaluate(string $view, array $data): string
    {
        $file = $this->resolve($view);
        if (!is_file($file)) {
            throw new RuntimeException("View '{$view}' not found at {$file}.");
        }

        $renderer = function (string $__dmf_view_file__, array $__dmf_view_vars__): string {
            extract($__dmf_view_vars__, \EXTR_OVERWRITE);
            ob_start();
            try {
                include $__dmf_view_file__;
            } catch (\Throwable $__dmf_view_error__) {
                ob_end_clean();
                throw $__dmf_view_error__;
            }
            return (string) ob_get_clean();
        };

        // Data overrides shared on key collisions.
        return $renderer($file, array_merge($this->shared, $data));
    }

    /**
     * Map a dotted or slashed view name to a filesystem path.
     */
    private function resolve(string $name): string
    {
        $relative = str_replace('.', \DIRECTORY_SEPARATOR, $name);
        return $this->path . \DIRECTORY_SEPARATOR . $relative . $this->extension;
    }
}
