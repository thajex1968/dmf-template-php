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
 * ── WHAT A RELEASE CARRIES (Specification v2) ───────────────────────────────
 * Beside the payload the package ships four metadata files:
 *
 *   RELEASE_MANIFEST.json  canonical inventory — every payload file with its
 *                          size, sha256, mode and generation order  (gate V12)
 *   MANIFEST.sha256        `sha256sum -c` compatible checksums covering the
 *                          payload and the canonical inventory
 *   BUILD_INFO.json        schema v2 provenance: who built what from which
 *                          commit, against which dmf/core, with which
 *                          compatibility constraints and reproducibility
 *                          attestation                    (gates V9, V10, V11, V13)
 *   SHA256SUMS.txt         checksum of the archive itself (written beside it)
 *
 * The inventory and the checksums come from scripts/release-manifest.php, which
 * is required as a library rather than shelled out to, so this build and the CI
 * reproducibility gate compute them with the same code.
 *
 * ── REPRODUCIBILITY IS PROVEN, NOT CLAIMED ──────────────────────────────────
 * BUILD_INFO.json asserts `reproducible_build.verified`, and gate V10 refuses a
 * package whose attestation is absent or false. So the build genuinely builds
 * TWICE — two independent staging directories, each installed from the
 * committed lock — and compares them before stamping the attestation. A build
 * that cannot reproduce itself writes no artifact.
 *
 * That is the whole cost of the guarantee: a release takes two composer
 * installs. An artifact nobody can rebuild identically cannot be audited.
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

// The canonical inventory writer, the deterministic release UUID and the
// double-build comparison all live here. It dispatches only when it is the
// entry point, so requiring it defines the functions without running anything.
require_once __DIR__ . '/release-manifest.php';

const EXIT_OK    = 0;
const EXIT_ERROR = 1;

$root = dirname(__DIR__);
exit((new ReleaseBuilder($root, array_slice($argv, 1)))->run());

// ─────────────────────────────────────────────────────────────────────────────

final class ReleaseBuilder
{
    private string $version = '';

    private string $staging = '';

    /** Second, independent build of the same commit — compared, then discarded. */
    private string $control = '';

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

            $this->staging = $this->stageBuild(verbose: true);

            $this->info('Building a second time, to prove the build is reproducible...');
            $this->control = $this->stageBuild(verbose: false);

            $this->writeInventory($this->staging, quiet: false);
            $this->writeInventory($this->control, quiet: true);

            // Provisional provenance in both trees: the attestation must not
            // claim "verified" before the comparison has actually passed, and
            // compare() needs a BUILD_INFO.json on each side to compare.
            $this->writeBuildInfo($this->staging, reproducible: false, quiet: true);
            $this->writeBuildInfo($this->control, reproducible: false, quiet: true);

            $this->assertReproducible();

            // Re-stamp the artifact now the comparison has passed.
            // BUILD_INFO.json is metadata and is excluded from the inventory by
            // design, so rewriting it invalidates neither RELEASE_MANIFEST.json
            // nor MANIFEST.sha256.
            $this->writeBuildInfo($this->staging, reproducible: true, quiet: false);

            $this->deleteTree($this->control);
            $this->control = '';

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
            // The control build is never an output, so it goes unconditionally.
            foreach ([$this->control, $this->skipZip ? '' : $this->staging] as $temp) {
                if ($temp !== '') {
                    $this->deleteTree($temp);
                }
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
     * Produce one complete build — dependencies plus payload — in a fresh
     * directory, and return where it landed.
     *
     * Only composer.json and composer.lock are copied in, so the result is a
     * function of the committed manifest alone and cannot inherit a symlinked
     * vendor/dmf/core from the developer's working tree.
     *
     * Called twice per release: once for the artifact, once as the control the
     * reproducibility attestation is measured against. The control build is
     * silent, because a duplicated build log reads like a bug.
     */
    private function stageBuild(bool $verbose): string
    {
        $dir = sys_get_temp_dir() . '/dmf-build-' . bin2hex(random_bytes(6));

        if (!mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the staging directory.');
        }

        copy($this->root . '/composer.json', $dir . '/composer.json');
        copy($this->root . '/composer.lock', $dir . '/composer.lock');

        if ($verbose) {
            $this->info('Installing production dependencies (--no-dev --optimize-autoloader)...');
        }

        $status = $this->runComposer($dir, $verbose, [
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

        if ($verbose) {
            $this->ok(sprintf(
                'vendor/ installed from the committed lock (%d files)',
                $this->countFiles($dir . '/vendor'),
            ));
        }

        $this->stagePayload($dir, $verbose);

        return $dir;
    }

    private function stagePayload(string $dir, bool $verbose): void
    {
        foreach ($this->includes() as $entry) {
            $from = $this->root . '/' . $entry['from'];
            $to   = $entry['to'] === '.'
                ? $dir
                : $dir . '/' . $entry['to'];

            if (is_dir($from)) {
                $this->copyTree($from, $to);
            } else {
                if (!is_dir(dirname($to))) {
                    mkdir(dirname($to), 0o777, true);
                }
                copy($from, $to);
            }

            if ($verbose) {
                $this->ok(sprintf('%-24s → %s', $entry['from'], $entry['to']));
            }
        }
    }

    // ── Specification v2 metadata ───────────────────────────────────────────

    /**
     * Write the canonical inventory: RELEASE_MANIFEST.json and MANIFEST.sha256.
     *
     * Verified by gate V12, which recomputes every hash from the EXTRACTED
     * package rather than from the archive's central directory — so the
     * inventory has to describe files as an operator on DirectAdmin will find
     * them.
     *
     * The three metadata files exclude themselves from the inventory
     * (METADATA_FILES in release-manifest.php). That is what lets
     * BUILD_INFO.json be written, and later re-stamped, without invalidating
     * anything already hashed.
     */
    private function writeInventory(string $dir, bool $quiet): void
    {
        if ($quiet) {
            ob_start();
        }

        $status = commandGenerate([
            'generate',
            $dir,
            // The application owns BUILD_INFO.json: only this build knows which
            // dmf/core was staged, and that record is the point of the file.
            '--no-build-info',
            '--package=' . ($this->manifest['name'] ?? 'dmf/app'),
            '--version=' . $this->version,
        ]);

        if ($quiet) {
            ob_end_clean();
        }

        if ($status !== 0) {
            throw new RuntimeException('Could not write the canonical release manifest.');
        }
    }

    /**
     * V10 — prove reproducibility instead of asserting it.
     *
     * The artifact claims `reproducible_build.verified`, and a claim nobody
     * checked is worth nothing: the whole value of the attestation is that
     * "this is what version X contains" can be confirmed by someone who does
     * not trust the builder. So the release is built twice from the committed
     * lock and the two builds are compared — identical inventories, identical
     * checksum files, identical trees, and identical provenance once the
     * timestamps are masked.
     *
     * The comparison is release-manifest.php's own `compare`, so a local build
     * and a CI build attest to exactly the same property. A failure here is
     * fatal: an artifact that cannot reproduce itself must never ship claiming
     * that it can.
     */
    private function assertReproducible(): void
    {
        $this->info('Comparing two independent builds of the same commit...');

        if (commandCompare(['compare', $this->staging, $this->control]) !== 0) {
            throw new RuntimeException(
                'The build is NOT reproducible — two builds of this commit differ (see above). '
                . 'No artifact written.',
            );
        }
    }

    /**
     * Write BUILD_INFO.json — schema v2 provenance.
     *
     * On DirectAdmin there is no SSH, no Composer and no git, so this file is
     * the only way to answer "what is actually running here?" months after a
     * deployment — and, critically, which dmf/core it was built against, which
     * is the first question when a library bug is suspected.
     *
     * Schema v2 is additive over v1: every v1 field is still present and keeps
     * its meaning, so a consumer written against v1 keeps working. The
     * additions are the release identity (release_uuid, manifest_sha256), the
     * compatibility constraints the host must satisfy, and the reproducibility
     * attestation.
     *
     * Verified by gates V9 (provenance), V11 (schema), V13 (compatibility) and
     * V10 (reproducibility) in scripts/verify-release.php.
     */
    private function writeBuildInfo(string $dir, bool $reproducible, bool $quiet): void
    {
        $env = static fn (string $k): string => (string) (getenv($k) ?: '');

        $manifestPath = $dir . '/RELEASE_MANIFEST.json';

        if (!is_file($manifestPath)) {
            throw new RuntimeException('RELEASE_MANIFEST.json must exist before BUILD_INFO.json is written.');
        }

        /** @var array<string,mixed> $inventory */
        $inventory   = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifestSha = (string) hash_file('sha256', $manifestPath);

        $core   = $this->coreProvenance($dir);
        $now    = gmdate('Y-m-d\TH:i:s\Z');
        $commit = $this->git('rev-parse HEAD');
        $tag    = $this->git('describe --tags --exact-match');

        // Outside CI there is no GITHUB_REPOSITORY, so fall back to the package
        // name — release_uuid must be derived from values that are the same on
        // every machine building this commit, or it is not deterministic.
        $repository = $env('GITHUB_REPOSITORY') ?: (string) ($this->manifest['name'] ?? '');

        $info = [
            // ── v1 fields, unchanged ──────────────────────────────────────────
            'application'      => $this->manifest['name'] ?? 'dmf/app',
            'version'          => $this->version,
            'tag'              => $tag,
            'git_commit'       => $commit,
            'git_commit_short' => $this->git('rev-parse --short HEAD'),
            'git_branch'       => $this->git('rev-parse --abbrev-ref HEAD'),
            'build_time_utc'   => $now,
            'builder'          => $env('GITHUB_ACTIONS') !== ''
                ? 'GitHub Actions'
                : 'scripts/build-release.php',
            'github_run_id'      => $env('GITHUB_RUN_ID'),
            'github_run_attempt' => $env('GITHUB_RUN_ATTEMPT'),
            'github_workflow'    => $env('GITHUB_WORKFLOW'),
            'source_repository'  => $env('GITHUB_REPOSITORY'),
            'php_build'          => PHP_VERSION,
            'mode'               => 'release',
            'dependencies'       => ['dmf/core' => $core],
            'zip_layout'         => 'flat (extract into ~/public_html/)',

            // ── v2 additions ──────────────────────────────────────────────────
            'schema_version'   => 2,
            'release_uuid'     => releaseUuid([
                'repository' => $repository,
                'tag'        => $tag,
                'commit'     => $commit,
            ]),
            'generated_at'     => $now,
            'release_manifest' => 'RELEASE_MANIFEST.json',
            'manifest'         => 'MANIFEST.sha256',
            'manifest_sha256'  => $manifestSha,
            'content_sha256'   => $manifestSha,
            'file_count'       => $inventory['file_count'] ?? 0,
            'total_bytes'      => $inventory['total_bytes'] ?? 0,
            'archive_sha256_published_in' => 'SHA256SUMS.txt',
            'composer_install_source'     => $core['install_source'],
            'compatibility'    => [
                // The constraint the package actually declares — not a guess,
                // so V13 fails a host that cannot run what was built.
                'php'      => (string) ($this->manifest['require']['php'] ?? '^8.1'),
                'composer' => '^2.0',
                // Derived from the staged install, so it can never disagree
                // with the library the package ships (V13 cross-checks it).
                'dmf_core' => $core['version'],
            ],
            'reproducible_build' => [
                'verified' => $reproducible,
                'method'   => 'double-build comparison — two independent installs from the '
                    . 'committed lock, compared file-by-file',
                'excluded' => ['build_time_utc', 'generated_at'],
            ],
        ];

        writeJson($dir . '/BUILD_INFO.json', $info);

        if ($quiet) {
            return;
        }

        $this->ok(sprintf('BUILD_INFO.json — schema v2, %s @ %s', $this->version, $info['git_commit_short']));
        $this->ok(sprintf('  %-15s %s', 'release_uuid', $info['release_uuid']));
        $this->ok(sprintf('  %-15s %s…', 'manifest_sha256', substr($manifestSha, 0, 16)));
        $this->ok(sprintf(
            '  %-15s %s via %s — sha %s…',
            'dmf/core',
            $core['version'],
            $core['install_source'],
            substr($core['content_sha256'], 0, 12),
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
    private function coreProvenance(string $dir): array
    {
        $result = [
            'version'        => 'unknown',
            'install_source' => 'unknown',
            'content_sha256' => 'unknown',
        ];

        $installed = $dir . '/vendor/composer/installed.json';

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

        $coreDir = $dir . '/vendor/dmf/core';

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
        echo "  Confirm the upload arrived intact (where a shell is available):" . PHP_EOL;
        echo "    sha256sum -c MANIFEST.sha256" . PHP_EOL;
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

    /**
     * @param bool         $verbose Stream Composer's output. The control build
     *                              captures it instead and replays it only if
     *                              the install fails — a second install log on
     *                              a successful build reads like the build
     *                              looping, but a failure must still be
     *                              diagnosable.
     *
     *                              Capturing rather than discarding is what
     *                              makes that safe: Composer writes its install
     *                              log to STDERR, so there is no stream that
     *                              can be silenced without also silencing the
     *                              error.
     * @param list<string> $args
     */
    private function runComposer(string $cwd, bool $verbose, array $args): int
    {
        $composer    = $this->locateComposer();
        $descriptors = $verbose
            ? [1 => STDOUT, 2 => STDERR]
            : [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open(
            array_merge($composer, $args),
            $descriptors,
            $pipes,
            $cwd,
            // COMPOSER is cleared so a developer with the dev manifest exported
            // in their shell cannot redirect the staged install to it.
            array_diff_key(getenv(), ['COMPOSER' => null]),
        );

        if (!is_resource($process)) {
            return 127;
        }

        if ($verbose) {
            return proc_close($process);
        }

        // Drain both pipes before proc_close(), or a chatty install deadlocks
        // once the pipe buffer fills.
        $captured = '';
        foreach ([1, 2] as $stream) {
            $captured .= stream_get_contents($pipes[$stream]) ?: '';
            fclose($pipes[$stream]);
        }

        $status = proc_close($process);

        if ($status !== 0) {
            fwrite(STDERR, $captured);
        }

        return $status;
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
