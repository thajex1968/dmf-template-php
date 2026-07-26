<?php

/**
 * scripts/dmf-mode.php — DMF Platform mode switcher.
 *
 * Switches this application between development mode (dmf/core installed from
 * the sibling ../dmf-core checkout, as a symlink, edits visible immediately)
 * and release mode (dmf/core installed from Git as real files).
 *
 *   php scripts/dmf-mode.php dev       composer dmf:dev
 *   php scripts/dmf-mode.php release   composer dmf:release
 *   php scripts/dmf-mode.php status    composer dmf:status
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 * A Composer path repository is satisfied with a symlink — a directory junction
 * on Windows. That is ideal for development and fatal for release: a junction
 * does not survive ZIP → upload → extract on DirectAdmin shared hosting, so
 * vendor/dmf/core arrives empty and the application dies with
 * "Class Dmf\Core\Security\Sanitizer not found".
 *
 * The fix is that the committed composer.json NEVER declares a path repository.
 * Release mode is the default; development mode is opt-in and writes only
 * git-ignored files.
 *
 * ── HOW ─────────────────────────────────────────────────────────────────────
 * Composer derives its lock filename from the COMPOSER environment variable, so
 * running against composer-dev.json produces composer-dev.lock. The development
 * and production dependency graphs are therefore fully separate and cannot
 * overwrite each other.
 *
 * composer-dev.json is DERIVED from composer.json every time, never authored,
 * so the two cannot drift. Both it and composer-dev.lock are git-ignored.
 *
 * This is PHP rather than shell because Windows is a first-class developer
 * platform here; a .sh plus a .ps1 twin would be two implementations to keep in
 * sync, and PHP is already a hard dependency of every DMF application.
 *
 * See docs/platform/DEVELOPMENT_MODE.md and docs/platform/PRODUCTION_MODE.md.
 */

declare(strict_types=1);

const CORE_PACKAGE  = 'dmf/core';
const CORE_SIBLING  = '../dmf-core';
const DEV_MANIFEST  = 'composer-dev.json';
const DEV_LOCK      = 'composer-dev.lock';

$projectRoot = dirname(__DIR__);
$command     = $argv[1] ?? 'status';

$exit = match ($command) {
    'dev'     => commandDev($projectRoot, array_slice($argv, 2)),
    'release' => commandRelease($projectRoot, array_slice($argv, 2)),
    'status'  => commandStatus($projectRoot),
    'help', '--help', '-h' => usage(0),
    default   => usage(1, sprintf('Unknown command: %s', $command)),
};

exit($exit);

// ─────────────────────────────────────────────────────────────────────────────
//  Commands
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Switch to development mode: install dmf/core from the sibling checkout.
 *
 * @param list<string> $passthru Extra arguments forwarded to composer install.
 */
function commandDev(string $root, array $passthru): int
{
    heading('Development mode');

    $sibling = $root . '/' . CORE_SIBLING;

    if (!is_dir($sibling)) {
        fail(sprintf('Sibling checkout not found: %s', realpathOrRaw($sibling)));
        info('Development mode requires dmf-core checked out next to this project:');
        info('');
        info('    parent-directory/');
        info('    ├── dmf-core/');
        info('    └── ' . basename($root) . '/     ← you are here');
        info('');
        info('    git clone https://github.com/thajex1968/dmf-core.git ' . $sibling);
        info('');
        info('To install from Git instead, run:  composer dmf:release');

        return 1;
    }

    if (!is_file($sibling . '/composer.json')) {
        fail(sprintf('%s exists but is not a Composer package (no composer.json).', $sibling));

        return 1;
    }

    ok(sprintf('Found sibling checkout: %s', realpathOrRaw($sibling)));

    $manifest = readJson($root . '/composer.json');
    $dev      = buildDevManifest($manifest);

    writeJson($root . '/' . DEV_MANIFEST, $dev);
    ok(sprintf('Generated %s (git-ignored, derived from composer.json)', DEV_MANIFEST));

    guardGitignore($root);

    $args = array_merge(['install', '--no-interaction'], $passthru);
    info(sprintf('Running: COMPOSER=%s composer %s', DEV_MANIFEST, implode(' ', $args)));
    echo PHP_EOL;

    $status = runComposer($root, $args, ['COMPOSER' => DEV_MANIFEST]);

    if ($status !== 0) {
        echo PHP_EOL;
        fail('composer install failed. See the output above.');

        return $status;
    }

    echo PHP_EOL;
    reportCoreInstall($root);
    echo PHP_EOL;
    ok('Development mode active — edits in ../dmf-core take effect immediately.');
    warn('Do NOT build a release from this tree. Run "composer dmf:release" first,');
    warn('or let CI build it, which always installs from Git.');

    return 0;
}

/**
 * Switch to release mode: install dmf/core from Git as real files.
 *
 * @param list<string> $passthru Extra arguments forwarded to composer install.
 */
function commandRelease(string $root, array $passthru): int
{
    heading('Release mode');

    foreach ([DEV_MANIFEST, DEV_LOCK] as $artifact) {
        $path = $root . '/' . $artifact;
        if (is_file($path)) {
            unlink($path);
            ok(sprintf('Removed %s', $artifact));
        }
    }

    // A symlinked vendor/dmf/core left over from development mode must go
    // before Composer reinstalls, or Composer may reuse the link in place.
    $vendorCore = $root . '/vendor/dmf/core';
    if (isLinkPath($vendorCore)) {
        removeLink($vendorCore);
        ok('Removed symlinked vendor/dmf/core left over from development mode');
    }

    $args = array_merge(['install', '--no-interaction'], $passthru);
    info(sprintf('Running: composer %s', implode(' ', $args)));
    echo PHP_EOL;

    // COMPOSER is explicitly cleared: a developer with it exported in their
    // shell would otherwise silently keep using the dev manifest.
    $status = runComposer($root, $args, ['COMPOSER' => null]);

    if ($status !== 0) {
        echo PHP_EOL;
        fail('composer install failed.');
        info('If dmf/core could not be found, authenticate against the private repo:');
        info('    composer config --global --auth github-oauth.github.com <your-PAT>');

        return $status;
    }

    echo PHP_EOL;
    reportCoreInstall($root);
    echo PHP_EOL;
    ok('Release mode active — vendor/dmf/core is real files, safe to package.');

    return 0;
}

/** Report which mode is active and how dmf/core is currently installed. */
function commandStatus(string $root): int
{
    heading('DMF mode status');

    $devManifest = is_file($root . '/' . DEV_MANIFEST);
    $envComposer = getenv('COMPOSER');

    line('Development manifest', $devManifest ? DEV_MANIFEST . ' present' : 'absent');
    line('COMPOSER env var', $envComposer === false || $envComposer === '' ? '(unset)' : $envComposer);

    $manifest = readJson($root . '/composer.json');
    line('Committed constraint', (string) ($manifest['require'][CORE_PACKAGE] ?? '(none)'));
    line('Path repository in composer.json', hasPathRepository($manifest) ? 'YES — VIOLATION' : 'no');

    echo PHP_EOL;
    $mode = reportCoreInstall($root);

    echo PHP_EOL;

    if (hasPathRepository($manifest)) {
        fail('composer.json declares a "type": "path" repository.');
        info('The committed manifest must never do this — it is how a junction');
        info('reaches a release artifact. Move it to development mode instead.');

        return 1;
    }

    info(match ($mode) {
        'symlink' => 'Development mode. Run "composer dmf:release" before packaging.',
        'real'    => 'Release mode. Safe to build a release package.',
        default   => 'dmf/core is not installed. Run "composer dmf:dev" or "composer dmf:release".',
    });

    return 0;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Manifest generation
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Derive the development manifest from the committed one.
 *
 * The path repository is prepended so Composer prefers it over the vcs
 * repository, and the constraint is relaxed to @dev because a working checkout
 * reports a dev version.
 *
 * @param  array<string,mixed> $manifest Parsed composer.json.
 * @return array<string,mixed>
 */
function buildDevManifest(array $manifest): array
{
    $manifest['repositories'] = array_merge(
        [[
            'type'    => 'path',
            'url'     => CORE_SIBLING,
            'options' => ['symlink' => true],
        ]],
        // Keep the vcs repository as a fallback for every other DMF package,
        // but drop any path repository that should not have been committed.
        array_values(array_filter(
            $manifest['repositories'] ?? [],
            static fn (array $repo): bool => ($repo['type'] ?? '') !== 'path',
        )),
    );

    $manifest['require'][CORE_PACKAGE] = '@dev';

    // A working checkout is dev-stability; without this Composer refuses it.
    $manifest['minimum-stability'] = 'dev';
    $manifest['prefer-stable']     = true;

    $manifest['description'] = ($manifest['description'] ?? '')
        . ' [DEVELOPMENT MANIFEST — generated by scripts/dmf-mode.php, do not commit]';

    return $manifest;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Inspection
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Print how dmf/core is installed.
 *
 * @return string One of 'symlink', 'real', 'missing'.
 */
function reportCoreInstall(string $root): string
{
    $path = $root . '/vendor/dmf/core';

    if (!file_exists($path)) {
        line('vendor/dmf/core', 'MISSING');

        return 'missing';
    }

    if (isLinkPath($path)) {
        $target = is_link($path) ? (readlink($path) ?: '?') : realpathOrRaw($path);
        line('vendor/dmf/core', sprintf('symlink → %s', $target));
        line('Packageable', 'NO — a junction does not survive ZIP → upload → extract');

        return 'symlink';
    }

    $files = iterator_count(new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    ));

    line('vendor/dmf/core', sprintf('real directory (%d files)', $files));
    line('Sanitizer present', is_file($path . '/src/Security/Sanitizer.php') ? 'yes' : 'NO');
    line('Packageable', 'yes');

    return 'real';
}

/** @param array<string,mixed> $manifest */
function hasPathRepository(array $manifest): bool
{
    foreach ($manifest['repositories'] ?? [] as $repo) {
        if (is_array($repo) && ($repo['type'] ?? '') === 'path') {
            return true;
        }
    }

    return false;
}

/**
 * Warn if the generated dev artifacts are not git-ignored.
 *
 * Committing composer-dev.json would reintroduce a path repository into the
 * tracked tree, which is the exact failure this tool exists to prevent.
 */
function guardGitignore(string $root): void
{
    $gitignore = $root . '/.gitignore';
    $contents  = is_file($gitignore) ? (string) file_get_contents($gitignore) : '';

    foreach ([DEV_MANIFEST, DEV_LOCK] as $artifact) {
        if (!str_contains($contents, $artifact)) {
            warn(sprintf('%s is not listed in .gitignore — it MUST NOT be committed.', $artifact));
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  Process + filesystem helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Run Composer in $root with $env overlaid on the current environment.
 *
 * @param list<string>              $args
 * @param array<string,string|null> $env  A null value unsets the variable.
 */
function runComposer(string $root, array $args, array $env = []): int
{
    $composer = locateComposer();

    if ($composer === null) {
        fail('Composer not found on PATH and no composer.phar located.');

        return 127;
    }

    $environment = getenv();
    foreach ($env as $name => $value) {
        if ($value === null) {
            unset($environment[$name]);
        } else {
            $environment[$name] = $value;
        }
    }

    $process = proc_open(
        array_merge($composer, $args),
        [0 => STDIN, 1 => STDOUT, 2 => STDERR],
        $pipes,
        $root,
        $environment,
    );

    if (!is_resource($process)) {
        fail('Could not start Composer.');

        return 127;
    }

    return proc_close($process);
}

/**
 * Locate a runnable Composer as an argv array.
 *
 * Composer on Windows is a .bat shim, and proc_open()'s array form calls
 * CreateProcess directly — which cannot execute a .bat and fails with
 * "error code: 2". Bare ['composer'] is therefore not portable.
 *
 * Preference order, most reliable first:
 *   1. a composer.phar beside the resolved executable — run it with PHP, which
 *      works identically on every platform
 *   2. the .bat itself, wrapped in `cmd /c`
 *   3. a POSIX shim on PATH, which proc_open can exec via its shebang
 *   4. a composer.phar beside the PHP binary or in the project
 *
 * @return list<string>|null
 */
function locateComposer(): ?array
{
    $isWindows = stripos(PHP_OS_FAMILY, 'Windows') === 0;

    exec(
        ($isWindows ? 'where composer' : 'command -v composer')
        . ' 2>' . ($isWindows ? 'NUL' : '/dev/null'),
        $output,
        $status,
    );

    if ($status === 0 && $output !== []) {
        foreach ($output as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '') {
                continue;
            }

            $phar = dirname($candidate) . DIRECTORY_SEPARATOR . 'composer.phar';

            if (is_file($phar)) {
                return [PHP_BINARY, $phar];
            }

            if ($isWindows && preg_match('/\.(bat|cmd)$/i', $candidate) === 1) {
                return ['cmd', '/c', $candidate];
            }

            if (!$isWindows && is_executable($candidate)) {
                return [$candidate];
            }
        }
    }

    foreach ([
        dirname(PHP_BINARY) . '/composer.phar',
        getcwd() . '/composer.phar',
    ] as $phar) {
        if (is_file($phar)) {
            return [PHP_BINARY, $phar];
        }
    }

    return null;
}

/**
 * Is $path a symlink or a Windows directory junction?
 *
 * On Windows neither is_link() nor readlink() can answer this: is_link()
 * returns false for a junction, and readlink() succeeds for ordinary paths too.
 * What holds on every platform is that realpath() of a link resolves somewhere
 * other than where the link itself sits — so compare the resolved path against
 * the path the entry would have if it were real.
 *
 * Getting this wrong reports a junction as a real directory, which is exactly
 * the misdetection that lets one reach a release artifact.
 */
function isLinkPath(string $path): bool
{
    if (is_link($path)) {
        return true;
    }

    clearstatcache(true, $path);

    $real   = realpath($path);
    $parent = realpath(dirname($path));

    if ($real === false || $parent === false) {
        return false;
    }

    $expected = $parent . DIRECTORY_SEPARATOR . basename($path);

    // Windows path comparison is case-insensitive; realpath() may also
    // normalise the casing of components it resolved.
    return stripos(PHP_OS_FAMILY, 'Windows') === 0
        ? strcasecmp($real, $expected) !== 0
        : $real !== $expected;
}

/** Remove a symlink or junction without following it. */
function removeLink(string $path): void
{
    if (stripos(PHP_OS_FAMILY, 'Windows') === 0 && !is_link($path)) {
        // rmdir() unlinks a junction without touching its target.
        @rmdir($path);

        return;
    }

    @unlink($path);
}

// ─────────────────────────────────────────────────────────────────────────────
//  JSON
// ─────────────────────────────────────────────────────────────────────────────

/** @return array<string,mixed> */
function readJson(string $path): array
{
    $raw = @file_get_contents($path);

    if ($raw === false) {
        fwrite(STDERR, sprintf("Cannot read %s\n", $path));
        exit(1);
    }

    try {
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fwrite(STDERR, sprintf("Invalid JSON in %s: %s\n", $path, $e->getMessage()));
        exit(1);
    }

    return $decoded;
}

/** @param array<string,mixed> $data */
function writeJson(string $path, array $data): void
{
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );

    file_put_contents($path, $json . "\n");
}

function realpathOrRaw(string $path): string
{
    return realpath($path) ?: $path;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Output
// ─────────────────────────────────────────────────────────────────────────────

function supportsColour(): bool
{
    return getenv('NO_COLOR') === false
        && (getenv('CI') !== false || (function_exists('posix_isatty') && @posix_isatty(STDOUT)));
}

function paint(string $code, string $text): string
{
    return supportsColour() ? "\033[{$code}m{$text}\033[0m" : $text;
}

function heading(string $text): void
{
    echo PHP_EOL, paint('1;34', '── ' . $text . ' ' . str_repeat('─', max(0, 60 - strlen($text)))), PHP_EOL, PHP_EOL;
}

function ok(string $text): void
{
    echo paint('0;32', '  ✓ '), $text, PHP_EOL;
}

function info(string $text): void
{
    echo '    ', $text, PHP_EOL;
}

function warn(string $text): void
{
    echo paint('1;33', '  ! '), $text, PHP_EOL;
}

function fail(string $text): void
{
    fwrite(STDERR, paint('0;31', '  ✗ ') . $text . PHP_EOL);
}

function line(string $label, string $value): void
{
    printf("  %-34s %s\n", $label . ':', $value);
}

function usage(int $exit, string $error = ''): int
{
    if ($error !== '') {
        fail($error);
        echo PHP_EOL;
    }

    echo <<<TXT
    DMF Platform mode switcher

      php scripts/dmf-mode.php dev [composer args]
          Install dmf/core from the sibling ../dmf-core checkout.
          vendor/dmf/core becomes a symlink; edits take effect immediately.
          Writes composer-dev.json + composer-dev.lock (both git-ignored).

      php scripts/dmf-mode.php release [composer args]
          Install dmf/core from Git as real files. This is the default state
          of a fresh clone and the only mode a release may be built from.

      php scripts/dmf-mode.php status
          Report the active mode and how dmf/core is installed.

    Shorthands: composer dmf:dev · composer dmf:release · composer dmf:status

    TXT;

    return $exit;
}
