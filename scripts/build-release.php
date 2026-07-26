<?php

/**
 * scripts/build-release.php — build a deployable release package.
 *
 *   php scripts/build-release.php               # version from VERSION
 *   php scripts/build-release.php 1.3.0         # explicit version
 *   php scripts/build-release.php --no-zip      # stage only, skip archiving
 *
 * Produces release/<name>-<version>.zip plus SHA256SUMS.txt. The ZIP is
 * self-contained: it already carries a production-only vendor/ with an
 * optimised autoloader, because the deployment target is DirectAdmin shared
 * hosting with no SSH and no Composer.
 *
 * ── THE INVARIANT ───────────────────────────────────────────────────────────
 * Dependencies are installed into a CLEAN STAGING DIRECTORY containing only
 * composer.json and composer.lock — never by copying the working tree's
 * vendor/. A developer in development mode has a vendor/dmf/core that is a
 * symlink to ../dmf-core; copying that would package a link that does not
 * survive ZIP → upload → extract, and the deployed app would die with
 * "Class Dmf\Core\Security\Sanitizer not found".
 *
 * Staging from the committed manifest means the build is identical whatever
 * mode the developer happens to be in, and identical to what CI produces.
 *
 * Every build ends by running scripts/verify-release.php against the staged
 * tree AND the finished ZIP. A build that fails verification writes no
 * artifact and exits non-zero.
 *
 * ── WHAT GETS PACKAGED ──────────────────────────────────────────────────────
 * Declared in composer.json under extra.dmf-release, so a project customises
 * its payload without editing this script:
 *
 *   "extra": {
 *       "dmf-release": {
 *           "output":  "release",
 *           "include": [
 *               { "from": "public_html", "to": "." },
 *               { "from": "includes",    "to": "includes" }
 *           ]
 *       }
 *   }
 *
 * "to": "." flattens a directory into the archive root, which is what lets an
 * operator extract straight into ~/public_html/ with no manual moves.
 *
 * See docs/platform/DEPLOYMENT_GUIDE.md and docs/platform/DIRECTADMIN_GUIDE.md.
 */

declare(strict_types=1);

const EXIT_OK    = 0;
const EXIT_ERROR = 1;

$root = dirname(__DIR__);
exit((new ReleaseBuilder($root, array_slice($argv, 1)))->run());

// ─────────────────────────────────────────────────────────────────────────────

final class ReleaseBuilder
{
    private string $version = '';

    private string $staging = '';

    private bool $skipZip = false;

    /** @var array<string,mixed> */
    private array $manifest = [];

    /** @var array<string,mixed> */
    private array $config = [];

    /** @param list<string> $argv */
    public function __construct(
        private readonly string $root,
        private readonly array $argv,
    ) {
    }

    public function run(): int
    {
        try {
            $this->parseArgs();
            $this->loadManifest();
            $this->heading(sprintf('Building release %s', $this->version));

            $this->preflight();
            $this->stageDependencies();
            $this->stagePayload();
            $this->writeBuildInfo();

            if (!$this->verify('--tree', $this->staging)) {
                throw new RuntimeException('Staged tree failed verification — no artifact written.');
            }

            if ($this->skipZip) {
                $this->ok(sprintf('Staged at %s (--no-zip, archive skipped)', $this->staging));

                return EXIT_OK;
            }

            $archive = $this->createArchive();

            if (!$this->verify('--zip', $archive)) {
                unlink($archive);
                throw new RuntimeException('Archive failed verification — artifact deleted.');
            }

            $this->writeChecksum($archive);
            $this->report($archive);

            return EXIT_OK;
        } catch (Throwable $e) {
            $this->fail($e->getMessage());

            return EXIT_ERROR;
        } finally {
            if ($this->staging !== '' && !$this->skipZip) {
                $this->deleteTree($this->staging);
            }
        }
    }

    // ── Setup ───────────────────────────────────────────────────────────────

    private function parseArgs(): void
    {
        foreach ($this->argv as $arg) {
            if ($arg === '--no-zip') {
                $this->skipZip = true;

                continue;
            }

            if (!str_starts_with($arg, '-')) {
                $this->version = $arg;
            }
        }
    }

    private function loadManifest(): void
    {
        $path = $this->root . '/composer.json';

        if (!is_file($path)) {
            throw new RuntimeException('composer.json not found.');
        }

        /** @var array<string,mixed> $decoded */
        $decoded        = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->manifest = $decoded;
        $this->config   = $decoded['extra']['dmf-release'] ?? [];

        if ($this->version === '') {
            $versionFile = $this->root . '/VERSION';

            if (!is_file($versionFile)) {
                throw new RuntimeException('No version given and no VERSION file present.');
            }

            $this->version = trim((string) file_get_contents($versionFile));
        }

        if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $this->version)) {
            throw new RuntimeException(sprintf('Version "%s" is not semver.', $this->version));
        }
    }

    /**
     * Refuse to build when the inputs cannot produce a correct artifact.
     *
     * The path-repository check is the important one: if the committed manifest
     * declares `type: path`, the staged install would resolve dmf/core to a
     * symlink no matter how clean the staging directory is.
     */
    private function preflight(): void
    {
        foreach ($this->manifest['repositories'] ?? [] as $repo) {
            if (is_array($repo) && ($repo['type'] ?? '') === 'path') {
                throw new RuntimeException(sprintf(
                    'composer.json declares a path repository (%s). A release must never be '
                    . 'built from one — move it into development mode (composer dmf:dev).',
                    $repo['url'] ?? '?',
                ));
            }
        }

        if (!is_file($this->root . '/composer.lock')) {
            throw new RuntimeException(
                'composer.lock is missing. It must be committed so builds are reproducible — '
                . 'run "composer install" and commit the lock.',
            );
        }

        foreach ($this->includes() as $entry) {
            $from = $this->root . '/' . $entry['from'];

            if (!file_exists($from)) {
                throw new RuntimeException(sprintf(
                    'extra.dmf-release.include references a missing path: %s',
                    $entry['from'],
                ));
            }
        }

        $this->ok('Preflight passed — no path repository, lock present, payload complete');
    }

    // ── Staging ─────────────────────────────────────────────────────────────

    /**
     * Install production dependencies into a clean directory.
     *
     * Only composer.json and composer.lock are copied in, so the result is a
     * function of the committed manifest alone and cannot inherit a symlinked
     * vendor/dmf/core from the developer's working tree.
     */
    private function stageDependencies(): void
    {
        $this->staging = sys_get_temp_dir() . '/dmf-build-' . bin2hex(random_bytes(6));

        if (!mkdir($this->staging, 0o777, true) && !is_dir($this->staging)) {
            throw new RuntimeException('Could not create the staging directory.');
        }

        copy($this->root . '/composer.json', $this->staging . '/composer.json');
        copy($this->root . '/composer.lock', $this->staging . '/composer.lock');

        $this->info('Installing production dependencies (--no-dev --optimize-autoloader)...');

        $status = $this->runComposer([
            'install',
            '--no-dev',
            '--optimize-autoloader',
            '--no-interaction',
            '--no-progress',
        ]);

        if ($status !== 0) {
            throw new RuntimeException(
                'composer install failed in the staging directory. If dmf/core could not be '
                . 'found, authenticate against the private repository:' . PHP_EOL
                . '    composer config --global --auth github-oauth.github.com <your-PAT>',
            );
        }

        $files = $this->countFiles($this->staging . '/vendor');
        $this->ok(sprintf('vendor/ installed from the committed lock (%d files)', $files));
    }

    private function stagePayload(): void
    {
        foreach ($this->includes() as $entry) {
            $from = $this->root . '/' . $entry['from'];
            $to   = $entry['to'] === '.'
                ? $this->staging
                : $this->staging . '/' . $entry['to'];

            if (is_dir($from)) {
                $this->copyTree($from, $to);
            } else {
                if (!is_dir(dirname($to))) {
                    mkdir(dirname($to), 0o777, true);
                }
                copy($from, $to);
            }

            $this->ok(sprintf('%-24s → %s', $entry['from'], $entry['to']));
        }
    }

    /**
     * Write provenance into the package.
     *
     * On DirectAdmin there is no SSH, no Composer and no git, so this file is
     * the only way to answer "what is actually running here?" months after a
     * deployment — and, critically, which dmf/core it was built against, which
     * is the first question when a library bug is suspected.
     *
     * Verified by gate V9 in scripts/verify-release.php.
     */
    private function writeBuildInfo(): void
    {
        $env = static fn (string $k): string => (string) (getenv($k) ?: '');

        $info = [
            'application'      => $this->manifest['name'] ?? 'dmf/app',
            'version'          => $this->version,
            'tag'              => $this->git('describe --tags --exact-match'),
            'git_commit'       => $this->git('rev-parse HEAD'),
            'git_commit_short' => $this->git('rev-parse --short HEAD'),
            'git_branch'       => $this->git('rev-parse --abbrev-ref HEAD'),
            'build_time_utc'   => gmdate('Y-m-d\TH:i:s\Z'),
            'builder'          => $env('GITHUB_ACTIONS') !== ''
                ? 'GitHub Actions'
                : 'scripts/build-release.php',
            'github_run_id'     => $env('GITHUB_RUN_ID'),
            'source_repository' => $env('GITHUB_REPOSITORY'),
            'php_build'         => PHP_VERSION,
            'mode'              => 'release',
            'dependencies'      => ['dmf/core' => $this->coreProvenance()],
            'zip_layout'        => 'flat (extract into ~/public_html/)',
        ];

        file_put_contents(
            $this->staging . '/BUILD_INFO.json',
            json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        $this->ok(sprintf('BUILD_INFO.json — %s @ %s', $this->version, $info['git_commit_short']));
        $this->ok(sprintf(
            '  dmf/core %s via %s — sha %s…',
            $info['dependencies']['dmf/core']['version'],
            $info['dependencies']['dmf/core']['install_source'],
            substr($info['dependencies']['dmf/core']['content_sha256'], 0, 12),
        ));
    }

    /**
     * Record which dmf/core was actually packaged.
     *
     * Read from the STAGED install rather than the working tree, since the
     * staged tree is what is being packaged. content_sha256 fingerprints the
     * payload — Composer records shasum:"" for GitHub zipballs, so this is the
     * only checksum tying a deployment to the exact library bytes it shipped
     * with.
     *
     * @return array{version: string, install_source: string, content_sha256: string}
     */
    private function coreProvenance(): array
    {
        $result = [
            'version'        => 'unknown',
            'install_source' => 'unknown',
            'content_sha256' => 'unknown',
        ];

        $installed = $this->staging . '/vendor/composer/installed.json';

        if (is_file($installed)) {
            $decoded  = json_decode((string) file_get_contents($installed), true);
            $packages = $decoded['packages'] ?? $decoded;

            foreach (is_array($packages) ? $packages : [] as $package) {
                if (!is_array($package) || ($package['name'] ?? '') !== 'dmf/core') {
                    continue;
                }
                $result['version']        = (string) ($package['version'] ?? 'unknown');
                $result['install_source'] = (string) ($package['dist']['type'] ?? 'none');
                break;
            }
        }

        $coreDir = $this->staging . '/vendor/dmf/core';

        if (is_dir($coreDir)) {
            $files = [];
            foreach ($this->walk($coreDir) as $file) {
                $files[] = $file;
            }
            sort($files);

            $hash = hash_init('sha256');
            foreach ($files as $file) {
                hash_update($hash, str_replace('\\', '/', substr($file, strlen($coreDir) + 1)));
                hash_update_file($hash, $file);
            }
            $result['content_sha256'] = hash_final($hash);
        }

        return $result;
    }

    // ── Verification ────────────────────────────────────────────────────────

    /** Run the verification gates; a failing build must never emit an artifact. */
    private function verify(string $mode, string $target): bool
    {
        $verifier = $this->root . '/scripts/verify-release.php';

        if (!is_file($verifier)) {
            throw new RuntimeException('scripts/verify-release.php is missing — cannot verify the build.');
        }

        $process = proc_open(
            [PHP_BINARY, $verifier, $mode, $target],
            [1 => STDOUT, 2 => STDERR],
            $pipes,
            $this->root,
        );

        return is_resource($process) && proc_close($process) === 0;
    }

    // ── Archiving ───────────────────────────────────────────────────────────

    private function createArchive(): string
    {
        $outputDir = $this->root . '/' . ($this->config['output'] ?? 'release');

        if (!is_dir($outputDir) && !mkdir($outputDir, 0o777, true) && !is_dir($outputDir)) {
            throw new RuntimeException(sprintf('Could not create output directory: %s', $outputDir));
        }

        $slug    = str_replace('/', '-', (string) ($this->manifest['name'] ?? 'dmf-app'));
        $archive = sprintf('%s/%s-%s.zip', $outputDir, $slug, $this->version);

        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The zip extension is required to build a release.');
        }

        $zip = new ZipArchive();

        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException(sprintf('Could not create archive: %s', $archive));
        }

        $count = 0;
        foreach ($this->walk($this->staging) as $absolute) {
            $relative = str_replace('\\', '/', substr($absolute, strlen($this->staging) + 1));
            $zip->addFile($absolute, $relative);
            // Store a plain 0644 file mode so extraction on the host never
            // reproduces a link or an executable bit.
            $zip->setExternalAttributesName($relative, ZipArchive::OPSYS_UNIX, 0o100644 << 16);
            $count++;
        }

        $zip->close();
        $this->ok(sprintf('Archived %d files → %s', $count, basename($archive)));

        return $archive;
    }

    private function writeChecksum(string $archive): void
    {
        $sums = dirname($archive) . '/SHA256SUMS.txt';
        $line = sprintf("%s  %s\n", hash_file('sha256', $archive), basename($archive));

        file_put_contents($sums, $line);
        $this->ok(sprintf('SHA256SUMS.txt — %s', substr($line, 0, 16) . '…'));
    }

    private function report(string $archive): void
    {
        $kib = (int) round(filesize($archive) / 1024);

        echo PHP_EOL;
        $this->ok(sprintf('Release package ready: %s (%d KiB)', $archive, $kib));
        echo PHP_EOL;
        echo "  DirectAdmin deployment:" . PHP_EOL;
        echo "    1. Upload the ZIP via File Manager" . PHP_EOL;
        echo "    2. Navigate into ~/public_html/ and click Extract" . PHP_EOL;
        echo "    3. Configure .env, then remove the installer" . PHP_EOL;
        echo PHP_EOL;
        echo "  See docs/platform/DIRECTADMIN_GUIDE.md" . PHP_EOL;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return list<array{from: string, to: string}> */
    private function includes(): array
    {
        $includes = $this->config['include'] ?? [];

        if ($includes === []) {
            throw new RuntimeException(
                'composer.json declares no extra.dmf-release.include — nothing to package.',
            );
        }

        return array_map(
            static fn (array $e): array => ['from' => (string) $e['from'], 'to' => (string) ($e['to'] ?? $e['from'])],
            $includes,
        );
    }

    /** @param list<string> $args */
    private function runComposer(array $args): int
    {
        $composer  = $this->locateComposer();
        $descriptors = [1 => STDOUT, 2 => STDERR];

        $process = proc_open(
            array_merge($composer, $args),
            $descriptors,
            $pipes,
            $this->staging,
            // COMPOSER is cleared so a developer with the dev manifest exported
            // in their shell cannot redirect the staged install to it.
            array_diff_key(getenv(), ['COMPOSER' => null]),
        );

        return is_resource($process) ? proc_close($process) : 127;
    }

    /**
     * Locate a runnable Composer as an argv array.
     *
     * Composer on Windows is a .bat shim, and proc_open()'s array form calls
     * CreateProcess directly — which cannot execute a .bat and fails with
     * "error code: 2". Bare ['composer'] is therefore not portable.
     *
     * Preference order: a composer.phar beside the resolved executable (run
     * with PHP, identical on every platform), then the .bat wrapped in
     * `cmd /c`, then a POSIX shim, then a phar beside PHP or in the project.
     *
     * @return list<string>
     */
    private function locateComposer(): array
    {
        $isWindows = stripos(PHP_OS_FAMILY, 'Windows') === 0;

        exec(
            ($isWindows ? 'where composer' : 'command -v composer') . ' 2>' . $this->devNull(),
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

        foreach ([dirname(PHP_BINARY) . '/composer.phar', $this->root . '/composer.phar'] as $phar) {
            if (is_file($phar)) {
                return [PHP_BINARY, $phar];
            }
        }

        throw new RuntimeException('Composer not found on PATH and no composer.phar located.');
    }

    /** @return Generator<string> Absolute paths of every regular file under $dir. */
    private function walk(string $dir): Generator
    {
        $entries = scandir($dir) ?: [];
        sort($entries);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($full)) {
                yield from $this->walk($full);
            } else {
                yield $full;
            }
        }
    }

    private function copyTree(string $from, string $to): void
    {
        if (!is_dir($to) && !mkdir($to, 0o777, true) && !is_dir($to)) {
            throw new RuntimeException(sprintf('Could not create %s', $to));
        }

        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $source = $from . DIRECTORY_SEPARATOR . $entry;
            $target = $to . DIRECTORY_SEPARATOR . $entry;

            is_dir($source) ? $this->copyTree($source, $target) : copy($source, $target);
        }
    }

    private function countFiles(string $dir): int
    {
        return is_dir($dir) ? iterator_count($this->walk($dir)) : 0;
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->deleteTree($path . DIRECTORY_SEPARATOR . $entry);
        }

        @rmdir($path);
    }

    private function git(string $args): string
    {
        exec(sprintf('git -C %s %s 2>%s', escapeshellarg($this->root), $args, $this->devNull()), $out, $status);

        return $status === 0 && $out !== [] ? trim($out[0]) : 'unknown';
    }

    private function devNull(): string
    {
        return stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'NUL' : '/dev/null';
    }

    // ── Output ──────────────────────────────────────────────────────────────

    private function heading(string $text): void
    {
        echo PHP_EOL, $this->paint('1;34', $text), PHP_EOL, PHP_EOL;
    }

    private function ok(string $text): void
    {
        echo '  ', $this->paint('0;32', '✓'), ' ', $text, PHP_EOL;
    }

    private function info(string $text): void
    {
        echo '  ▸ ', $text, PHP_EOL;
    }

    private function fail(string $text): void
    {
        fwrite(STDERR, PHP_EOL . '  ' . $this->paint('0;31', '✗ ') . $text . PHP_EOL . PHP_EOL);

        if (getenv('GITHUB_ACTIONS') !== false) {
            fwrite(STDERR, sprintf("::error::%s\n", str_replace("\n", ' ', $text)));
        }
    }

    private function paint(string $code, string $text): string
    {
        $colour = getenv('NO_COLOR') === false
            && (getenv('CI') !== false || (function_exists('posix_isatty') && @posix_isatty(STDOUT)));

        return $colour ? "\033[{$code}m{$text}\033[0m" : $text;
    }
}
