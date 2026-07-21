<?php

/**
 * ==========================================================================
 * PHP-CS-Fixer configuration (distributable defaults)
 *
 * This is the shared, committed baseline shipped with the template. Developers
 * may create a local, gitignored `.php-cs-fixer.php` that loads this file and
 * overrides individual rules for their environment.
 *
 * Tooling:  friendsofphp/php-cs-fixer (declared in composer.json require-dev)
 * Standard: PSR-12 (see CODING_STANDARD.md)
 *
 * Usage (via Composer scripts):
 *   composer cs        # report violations only (dry-run, CI-safe)
 *   composer cs:fix    # apply fixes in place
 *
 * Usage (direct):
 *   vendor/bin/php-cs-fixer fix --dry-run --diff
 *   vendor/bin/php-cs-fixer fix
 * ==========================================================================
 */

declare(strict_types=1);

// Files/directories that PHP-CS-Fixer should scan. Mirrors the analysis scope
// used by PHPStan (includes, config, public_html, tests) and excludes any
// runtime, generated, or third-party code.
$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/includes',
        __DIR__ . '/config',
        __DIR__ . '/public_html',
        __DIR__ . '/tests',
    ])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true)
    ->exclude([
        'vendor',
        'storage',
    ]);

$config = new PhpCsFixer\Config();

return $config
    // Fail if the runtime php-cs-fixer version cannot guarantee these rules.
    ->setRiskyAllowed(true)
    ->setRules([
        // ---- Base standard --------------------------------------------------
        // PSR-12 is the platform coding standard (CODING_STANDARD.md).
        '@PSR12'                        => true,

        // ---- Imports --------------------------------------------------------
        'no_unused_imports'             => true,
        'ordered_imports'               => [
            'sort_algorithm' => 'alpha',
            'imports_order'  => ['class', 'function', 'const'],
        ],
        'global_namespace_import'       => [
            'import_classes'   => false,
            'import_constants' => false,
            'import_functions' => false,
        ],

        // ---- Language safety (risky) ---------------------------------------
        // Enforce strict typing at the top of every file — matches the
        // procedural include-chain conventions in CODING_STANDARD.md.
        'declare_strict_types'          => true,
        'strict_comparison'             => true,
        'strict_param'                  => true,

        // ---- Arrays ---------------------------------------------------------
        'array_syntax'                  => ['syntax' => 'short'],
        'trailing_comma_in_multiline'   => ['elements' => ['arrays', 'arguments', 'parameters']],
        'no_trailing_comma_in_singleline' => true,

        // ---- Whitespace & formatting ---------------------------------------
        'single_quote'                  => true,
        'no_extra_blank_lines'          => ['tokens' => ['extra', 'throw', 'use']],
        'blank_line_after_opening_tag'  => true,
        'method_chaining_indentation'   => true,
        'concat_space'                  => ['spacing' => 'one'],

        // ---- PHPDoc hygiene -------------------------------------------------
        'no_empty_phpdoc'               => true,
        'phpdoc_trim'                   => true,
        'phpdoc_indent'                 => true,
        'phpdoc_align'                  => ['align' => 'left'],
    ])
    ->setFinder($finder)
    // Keep the cache out of the repo (already ignored in .gitignore).
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
