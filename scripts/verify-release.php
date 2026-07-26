<?php

/**
 * scripts/verify-release.php — release artifact verification gate.
 *
 * Asserts that a built release package can actually run on DirectAdmin shared
 * hosting: no symlinks, no path repository, and a vendor/dmf/core made of real
 * files whose classes resolve.
 *
 *   php scripts/verify-release.php --zip release/grade-4.2.0.zip
 *   php scripts/verify-release.php --tree build/staging
 *
 * Exit code 0 = every gate passed, 1 = at least one failed.
 *
 * ── WHAT THIS CATCHES ───────────────────────────────────────────────────────
 * Composer satisfies a `type: path` repository with a symlink — a directory
 * junction on Windows. Junctions do not survive ZIP → upload → extract, so
 * vendor/dmf/core arrives on the server empty and the application dies with
 * "Class Dmf\Core\Security\Sanitizer not found".
 *
 * This script is the last gate before an artifact is published. It runs against
 * the BUILT PACKAGE rather than the working tree, because the working tree is
 * not what gets uploaded.
 *
 * Gates are specified in dmf-core: docs/platform/RELEASE_PIPELINE.md §6.
 *
 *   V1  no ZIP entry is a symlink or link-type entry
 *   V2  vendor/dmf/core/composer.json present and non-empty
 *   V3  vendor/dmf/core/src/Security/Sanitizer.php present
 *   V4  vendor/composer/installed.json records no "dist": {"type": "path"}
 *   V5  the packaged composer.json declares no "type": "path" repository
 *   V6  vendor/autoload.php resolves Dmf\Core\Security\Sanitizer
 *   V7  no tests/, docs/ or .github/ under vendor/dmf/core
 *   V8  SHA-256 of the artifact recorded
 *
 * V1, V3 and V4 are the non-negotiable three: together they make the original
 * production failure impossible to ship.
 */

declare(strict_types=1);

const REQUIRED_FILES = [
    'vendor/autoload.php',
    'vendor/composer/installed.json',
    'vendor/dmf/core/composer.json',
    'vendor/dmf/core/src/Security/Sanitizer.php',
];

const FORBIDDEN_VENDOR_PATHS = [
    'vendor/dmf/core/tests',
    'vendor/dmf/core/docs',
    'vendor/dmf/core/.github',
    'vendor/dmf/core/phpunit.xml',
    'vendor/dmf/core/phpstan.neon',
];

const PROBE_CLASSES = [
    'Dmf\\Core\\Security\\Sanitizer',
    'Dmf\\Core\\Security\\Csrf',
    'Dmf\\Core\\Database\\Connection',
];

/** Unix file-type mask and symlink bits, as stored in ZIP external attributes. */
const S_IFMT  = 0170000;
const S_IFLNK = 0120000;

$options = parseArgs(array_slice($argv, 1));

if ($options === null) {
    usage();
    exit(1);
}

$verifier = new ReleaseVerifier($options['mode'], $options['path']);
exit($verifier->run() ? 0 : 1);

// ─────────────────────────────────────────────────────────────────────────────

/**
 * @return array{mode: 'zip'|'tree', path: string}|null
 */
function parseArgs(array $argv): ?array
{
    $mode = null;
    $path = null;

    for ($i = 0; $i < count($argv); $i++) {
        match ($argv[$i]) {
            '--zip'  => [$mode, $path] = ['zip', $argv[++$i] ?? ''],
            '--tree' => [$mode, $path] = ['tree', $argv[++$i] ?? ''],
            default  => null,
        };
    }

    if ($mode === null || $path === null || $path === '') {
        return null;
    }

    return ['mode' => $mode, 'path' => $path];
}

function usage(): void
{
    fwrite(STDERR, <<<TXT

    Release artifact verification

      php scripts/verify-release.php --zip  <path-to.zip>
      php scripts/verify-release.php --tree <directory>

    Verifies a built release package contains no symlinks, no path repository,
    and a real vendor/dmf/core whose classes resolve. Exit 0 = ready to ship.


    TXT);
}

// ─────────────────────────────────────────────────────────────────────────────

final class ReleaseVerifier
{
    private int $passed = 0;

    /** Populated by assertInstalledJson() so V7 can diagnose precisely. */
    private string $coreVersion = '';

    private string $coreDistType = '';

    private string $coreDistUrl = '';

    /** @var list<string> */
    private array $failures = [];

    public function __construct(
        private readonly string $mode,
        private readonly string $path,
    ) {
    }

    public function run(): bool
    {
        $this->heading(sprintf('Release verification — %s', $this->path));

        if (!file_exists($this->path)) {
            $this->fail('artifact', sprintf('Not found: %s', $this->path));

            return $this->summary();
        }

        $this->mode === 'zip' ? $this->verifyZip() : $this->verifyTree();

        return $this->summary();
    }

    // ── ZIP ─────────────────────────────────────────────────────────────────

    private function verifyZip(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->fail('ext-zip', 'The zip extension is required to verify a ZIP artifact.');

            return;
        }

        $zip = new ZipArchive();

        if ($zip->open($this->path) !== true) {
            $this->fail('artifact', sprintf('Cannot open archive: %s', $this->path));

            return;
        }

        /** @var array<string,int> $entries name => index */
        $entries = [];
        $symlinks = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            $entries[rtrim($name, '/')] = $i;

            if ($this->zipEntryIsLink($zip, $i)) {
                $symlinks[] = $name;
            }
        }

        // V1 — no symlink survived into the package.
        if ($symlinks === []) {
            $this->pass('V1', 'No symlink entries in the archive');
        } else {
            $this->fail('V1', sprintf(
                'Archive contains %d symlink entr%s — these will not survive extraction:',
                count($symlinks),
                count($symlinks) === 1 ? 'y' : 'ies',
            ));
            foreach (array_slice($symlinks, 0, 10) as $link) {
                $this->detail($link);
            }
        }

        // V2 / V3 — the files whose absence caused the production failure.
        foreach (REQUIRED_FILES as $required) {
            if (isset($entries[$required]) && $zip->statName($required)['size'] > 0) {
                $this->pass($required === 'vendor/dmf/core/src/Security/Sanitizer.php' ? 'V3' : 'V2', $required);
            } else {
                $this->fail(
                    $required === 'vendor/dmf/core/src/Security/Sanitizer.php' ? 'V3' : 'V2',
                    sprintf('Missing or empty in archive: %s', $required),
                );
            }
        }

        // V4 — installed.json must not record a path install.
        $installed = $zip->getFromName('vendor/composer/installed.json');
        $this->assertInstalledJson($installed === false ? null : $installed);

        // V5 — the packaged manifest must not declare a path repository.
        $manifest = $zip->getFromName('composer.json');
        $this->assertManifest($manifest === false ? null : $manifest);

        // V9 — provenance must travel inside the package.
        $buildInfo = $zip->getFromName('BUILD_INFO.json');
        $this->assertProvenance($buildInfo === false ? null : $buildInfo);

        // V7 — development files must not have shipped.
        $leaked = array_values(array_filter(
            FORBIDDEN_VENDOR_PATHS,
            static fn (string $p): bool => isset($entries[$p]),
        ));
        $this->assertNoLeaks($leaked);

        $zip->close();

        // V6 — the autoloader must actually resolve the classes. This needs a
        // real filesystem, so extract to a temp directory and probe there.
        $this->assertAutoloadFromZip();

        // V8 — record the checksum operators should verify after upload.
        $this->pass('V8', sprintf('SHA-256 %s', hash_file('sha256', $this->path)));
        $this->detail(sprintf('Size %s KiB', number_format(filesize($this->path) / 1024, 0)));
    }

    /**
     * Detect a symlink entry in a ZIP.
     *
     * ZipArchive exposes no symlink flag, so read the external attributes: for
     * a Unix-created archive the high 16 bits hold the st_mode, and S_IFLNK
     * marks a symlink. Archives created on Windows report opsys as MSDOS/NTFS
     * and cannot carry a symlink at all.
     */
    private function zipEntryIsLink(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr  = 0;

        if (!$zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }

        if ($opsys !== ZipArchive::OPSYS_UNIX) {
            return false;
        }

        return (($attr >> 16) & S_IFMT) === S_IFLNK;
    }

    private function assertAutoloadFromZip(): void
    {
        $temp = sys_get_temp_dir() . '/dmf-verify-' . bin2hex(random_bytes(6));

        if (!mkdir($temp, 0o777, true) && !is_dir($temp)) {
            $this->fail('V6', 'Could not create a temporary directory to probe the autoloader.');

            return;
        }

        $zip = new ZipArchive();

        if ($zip->open($this->path) !== true) {
            $this->fail('V6', 'Could not reopen the archive to probe the autoloader.');

            return;
        }

        $zip->extractTo($temp);
        $zip->close();

        $this->probeAutoload($temp);
        $this->deleteTree($temp);
    }

    // ── Directory tree ──────────────────────────────────────────────────────

    private function verifyTree(): void
    {
        $root = rtrim($this->path, '/\\');

        // V1 — no symlink or junction anywhere in the tree.
        $links = $this->findLinks($root);

        if ($links === []) {
            $this->pass('V1', 'No symlinks or junctions in the tree');
        } else {
            $this->fail('V1', sprintf('%d symlink(s)/junction(s) found:', count($links)));
            foreach (array_slice($links, 0, 10) as $link) {
                $this->detail($link);
            }
        }

        // V2 / V3
        foreach (REQUIRED_FILES as $required) {
            $full = $root . '/' . $required;
            $gate = $required === 'vendor/dmf/core/src/Security/Sanitizer.php' ? 'V3' : 'V2';

            if (is_file($full) && filesize($full) > 0) {
                $this->pass($gate, $required);
            } else {
                $this->fail($gate, sprintf('Missing or empty: %s', $required));
            }
        }

        // V4
        $installed = @file_get_contents($root . '/vendor/composer/installed.json');
        $this->assertInstalledJson($installed === false ? null : $installed);

        // V5
        $manifest = @file_get_contents($root . '/composer.json');
        $this->assertManifest($manifest === false ? null : $manifest);

        // V9
        $buildInfo = @file_get_contents($root . '/BUILD_INFO.json');
        $this->assertProvenance($buildInfo === false ? null : $buildInfo);

        // V7
        $leaked = array_values(array_filter(
            FORBIDDEN_VENDOR_PATHS,
            static fn (string $p): bool => file_exists($root . '/' . $p),
        ));
        $this->assertNoLeaks($leaked);

        // V6
        $this->probeAutoload($root);
    }

    /**
     * Walk the tree and collect every symlink or junction.
     *
     * Hand-rolled rather than RecursiveIteratorIterator so that recursion stops
     * AT a link instead of descending through it. Descending would walk the
     * link's target — for vendor/dmf/core that means scanning the whole sibling
     * dmf-core checkout, and would report its contents as if they were part of
     * the artifact.
     *
     * @return list<string> Paths relative to $root.
     */
    private function findLinks(string $root): array
    {
        $links = [];
        $walk  = static function (string $dir, string $prefix) use (&$walk, &$links, $root): void {
            $entries = @scandir($dir);

            if ($entries === false) {
                return;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $full     = $dir . DIRECTORY_SEPARATOR . $entry;
                $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;

                if (ReleaseVerifier::isLinkPath($full)) {
                    $links[] = $relative;

                    continue; // Never follow the link.
                }

                if (is_dir($full)) {
                    $walk($full, $relative);
                }
            }
        };

        $walk($root, '');

        return $links;
    }

    /**
     * Is $path a symlink or a Windows directory junction?
     *
     * On Windows neither is_link() nor is_dir() reliably identifies a junction:
     * is_link() returns false, and readlink() succeeds for ordinary paths too,
     * so neither can be used as the test. What does hold on every platform is
     * that realpath() of a link resolves somewhere other than where the link
     * itself sits — so compare the resolved path against the path the entry
     * would have if it were real.
     *
     * Getting this wrong means a junction is reported as a real directory,
     * which is precisely the misdetection that lets one reach production.
     */
    public static function isLinkPath(string $path): bool
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

    // ── Shared assertions ───────────────────────────────────────────────────

    private function assertInstalledJson(?string $json): void
    {
        if ($json === null || $json === '') {
            $this->fail('V4', 'vendor/composer/installed.json is missing — cannot verify install source.');

            return;
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            $this->fail('V4', 'vendor/composer/installed.json is not valid JSON.');

            return;
        }

        $packages = $decoded['packages'] ?? $decoded;
        $found    = false;

        foreach ($packages as $package) {
            if (!is_array($package) || ($package['name'] ?? '') !== 'dmf/core') {
                continue;
            }

            $found    = true;
            $distType = $package['dist']['type'] ?? '(none)';

            // Captured so V7 can diagnose rather than guess — see assertNoLeaks().
            $this->coreVersion  = (string) ($package['version'] ?? '');
            $this->coreDistType = (string) $distType;
            $this->coreDistUrl  = (string) ($package['dist']['url'] ?? '');

            if ($distType === 'path') {
                $this->fail('V4', sprintf(
                    'dmf/core was installed from a PATH repository (url: %s).',
                    $package['dist']['url'] ?? '?',
                ));
                $this->detail('Rebuild in release mode: composer dmf:release');
            } else {
                $this->pass('V4', sprintf(
                    'dmf/core installed from dist type "%s" (version %s)',
                    $distType,
                    $package['version'] ?? '?',
                ));
            }
        }

        if (!$found) {
            $this->fail('V4', 'dmf/core is absent from installed.json.');
        }
    }

    private function assertManifest(?string $json): void
    {
        if ($json === null || $json === '') {
            // Not every package layout ships composer.json at the root; that is
            // acceptable, and V4 already covers the install source.
            $this->pass('V5', 'No composer.json packaged (nothing to violate)');

            return;
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            $this->fail('V5', 'Packaged composer.json is not valid JSON.');

            return;
        }

        foreach ($decoded['repositories'] ?? [] as $repo) {
            if (is_array($repo) && ($repo['type'] ?? '') === 'path') {
                $this->fail('V5', sprintf(
                    'Packaged composer.json declares a path repository (url: %s).',
                    $repo['url'] ?? '?',
                ));
                $this->detail('The committed manifest must never contain "type": "path".');

                return;
            }
        }

        $this->pass('V5', 'Packaged composer.json declares no path repository');
    }

    /**
     * V9 — provenance must travel inside the package.
     *
     * On DirectAdmin there is no SSH, no Composer and no git. BUILD_INFO.json
     * is the only way to answer "what is actually running here?" months after a
     * deployment — and, critically, which dmf/core it was built against, which
     * is the first question when a library bug is suspected.
     *
     * A package without it is deployable but not diagnosable, so this is a hard
     * gate rather than a warning.
     */
    private function assertProvenance(?string $json): void
    {
        if ($json === null || $json === '') {
            $this->fail('V9', 'BUILD_INFO.json is missing — the package carries no provenance.');
            $this->detail('Without it, nobody can tell what is deployed on a server that has');
            $this->detail('no SSH, no git and no Composer. Build via scripts/build-release.sh.');

            return;
        }

        $info = json_decode($json, true);

        if (!is_array($info)) {
            $this->fail('V9', 'BUILD_INFO.json is not valid JSON.');

            return;
        }

        $required = ['version', 'git_commit', 'build_time_utc', 'builder'];
        $missing  = array_values(array_filter(
            $required,
            static fn (string $k): bool => ($info[$k] ?? '') === '',
        ));

        if ($missing !== []) {
            $this->fail('V9', sprintf(
                'BUILD_INFO.json is incomplete — missing: %s',
                implode(', ', $missing),
            ));

            return;
        }

        $this->pass('V9', sprintf(
            'Provenance present — %s @ %s, built %s by %s',
            $info['version'],
            substr((string) ($info['git_commit_short'] ?? $info['git_commit']), 0, 12),
            $info['build_time_utc'],
            $info['builder'],
        ));

        // The dependency record is what ties a deployment to the exact library
        // bytes it shipped with — Composer records shasum:"" for GitHub
        // zipballs, so this is the only such checksum available.
        $core = $info['dependencies']['dmf/core'] ?? null;

        if (!is_array($core)) {
            $this->detail('No dmf/core dependency record — cannot tie this build to a library version.');

            return;
        }

        $this->detail(sprintf(
            'dmf/core %s via %s',
            $core['version'] ?? '?',
            $core['install_source'] ?? '?',
        ));

        if (($core['install_source'] ?? '') === 'path') {
            $this->fail('V9', 'BUILD_INFO records dmf/core installed from a path repository.');
            $this->detail('The package was built in development mode. Rebuild after');
            $this->detail('composer dmf:release.');

            return;
        }

        if (($core['content_sha256'] ?? '') !== '' && $core['content_sha256'] !== 'unknown') {
            $this->detail(sprintf('content_sha256 %s…', substr((string) $core['content_sha256'], 0, 16)));
        }
    }

    /**
     * V7 — the installed dmf/core must match the published release contract.
     *
     * The contract (docs/platform/RELEASE_ARCHITECTURE.md §4) is that a release
     * ships src/ plus its metadata files and nothing else. It is enforced by
     * .gitattributes export-ignore, which `git archive` applies — both locally
     * when building the Release Asset and server-side when GitHub generates the
     * dist archive Composer downloads.
     *
     * ── WHY THIS FAILS, WHEN IT FAILS ───────────────────────────────────────
     * export-ignore is applied from the tree AS IT EXISTED AT THE INSTALLED
     * REF. It is not a repository-level setting and it is not retroactive: a
     * tag cut before .gitattributes was added has no export-ignore rules in it,
     * so its archive legitimately contains tests/ and docs/.
     *
     * That is the only realistic cause, and it is a version problem rather than
     * a packaging problem — so this reports the installed version and the fix,
     * instead of sending the reader off to inspect .gitattributes in a
     * repository where it is very likely already correct.
     *
     * @param list<string> $leaked
     */
    private function assertNoLeaks(array $leaked): void
    {
        if ($leaked === []) {
            $this->pass('V7', sprintf(
                'Release contract satisfied — no development files in dmf/core%s',
                $this->coreVersion !== '' ? ' ' . $this->coreVersion : '',
            ));

            return;
        }

        $this->fail('V7', sprintf(
            'dmf/core%s ships %d development path%s it should not:',
            $this->coreVersion !== '' ? ' ' . $this->coreVersion : '',
            count($leaked),
            count($leaked) === 1 ? '' : 's',
        ));

        foreach ($leaked as $path) {
            $this->detail($path);
        }

        $this->detail('');

        if ($this->coreDistType === 'path') {
            // Already reported by V4; repeating the remedy here keeps each gate
            // independently actionable.
            $this->detail('Cause: installed from a path repository, which copies the');
            $this->detail('working tree verbatim — export-ignore is never consulted.');
            $this->detail('Fix:   composer dmf:release');

            return;
        }

        $this->detail(sprintf(
            'Cause: %s was tagged before .gitattributes existed, so the archive',
            $this->coreVersion !== '' ? $this->coreVersion : 'the installed version',
        ));
        $this->detail('       carries no export-ignore rules. This is a version problem,');
        $this->detail('       not a packaging problem — the rules are not retroactive.');
        $this->detail('Fix:   require "dmf/core": "^1.1" (the first release built to the');
        $this->detail('       contract), then composer update dmf/core');
    }

    /**
     * Load the packaged autoloader in an isolated subprocess and resolve the
     * classes. A subprocess keeps a broken autoloader from taking this script
     * down with it, and reports a fatal error as a clean failure.
     */
    private function probeAutoload(string $root): void
    {
        $autoload = $root . '/vendor/autoload.php';

        if (!is_file($autoload)) {
            $this->fail('V6', 'vendor/autoload.php is missing — cannot probe the autoloader.');

            return;
        }

        $classes = var_export(PROBE_CLASSES, true);
        $script  = sprintf(
            'require %s; foreach (%s as $c) { if (!class_exists($c)) { fwrite(STDERR, $c); exit(1); } } exit(0);',
            var_export($autoload, true),
            $classes,
        );

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process     = proc_open([PHP_BINARY, '-r', $script], $descriptors, $pipes);

        if (!is_resource($process)) {
            $this->fail('V6', 'Could not start a subprocess to probe the autoloader.');

            return;
        }

        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if ($status === 0) {
            $this->pass('V6', sprintf('Autoloader resolves %d core classes', count(PROBE_CLASSES)));
            foreach (PROBE_CLASSES as $class) {
                $this->detail($class);
            }

            return;
        }

        $this->fail('V6', 'The packaged autoloader cannot resolve a core class.');
        $this->detail(trim($stderr) !== '' ? trim($stderr) : 'autoload probe exited ' . $status);
        $this->detail('This is the production failure: Class ... not found.');
    }

    // ── Reporting ───────────────────────────────────────────────────────────

    private function summary(): bool
    {
        $failed = count($this->failures);

        echo PHP_EOL;

        if ($failed === 0) {
            echo $this->paint('0;32', sprintf(
                "  PASS — %d checks, artifact is safe to deploy.\n",
                $this->passed,
            ));

            return true;
        }

        echo $this->paint('0;31', sprintf(
            "  FAIL — %d of %d checks failed. DO NOT DEPLOY.\n",
            $failed,
            $failed + $this->passed,
        ));

        foreach ($this->failures as $failure) {
            echo '    · ', $failure, PHP_EOL;
        }

        return false;
    }

    private function pass(string $gate, string $message): void
    {
        $this->passed++;
        printf("  %s %-4s %s\n", $this->paint('0;32', '✓'), $gate, $message);
    }

    private function fail(string $gate, string $message): void
    {
        $this->failures[] = sprintf('%s %s', $gate, $message);
        printf("  %s %-4s %s\n", $this->paint('0;31', '✗'), $gate, $message);

        if (getenv('GITHUB_ACTIONS') !== false) {
            printf("::error::%s %s\n", $gate, $message);
        }
    }

    private function detail(string $message): void
    {
        printf("       %s\n", $this->paint('0;90', $message));
    }

    private function heading(string $text): void
    {
        echo PHP_EOL, $this->paint('1;34', $text), PHP_EOL, PHP_EOL;
    }

    private function paint(string $code, string $text): string
    {
        $colour = getenv('NO_COLOR') === false
            && (getenv('CI') !== false || (function_exists('posix_isatty') && @posix_isatty(STDOUT)));

        return $colour ? "\033[{$code}m{$text}\033[0m" : $text;
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($path);
    }
}
