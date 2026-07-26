<?php

/**
 * scripts/release-manifest.php — canonical package inventory.
 *
 *   php scripts/release-manifest.php generate <payload-dir> [--version=X] [--tag=vX] ...
 *   php scripts/release-manifest.php verify   <payload-dir>
 *   php scripts/release-manifest.php compare  <dir-a> <dir-b>
 *
 * ── WHAT THIS PRODUCES ──────────────────────────────────────────────────────
 * RELEASE_MANIFEST.json  the canonical inventory — every file with its size,
 *                        sha256, permission mode and generation order
 * MANIFEST.sha256        a `sha256sum -c` compatible checksum file covering the
 *                        payload AND RELEASE_MANIFEST.json
 * BUILD_INFO.json        schema v2 provenance (written by `generate`)
 *
 * ── WHY MANIFEST.sha256 IS NOT SIMPLY THE HASH OF RELEASE_MANIFEST.json ─────
 * `sha256sum -c MANIFEST.sha256` is the documented operator verification step
 * on DirectAdmin, where there is no SSH, no Composer and no git. Reducing that
 * file to a single hash of RELEASE_MANIFEST.json would leave the command
 * verifying one JSON file instead of the whole tree.
 *
 * So MANIFEST.sha256 keeps its format and now also covers RELEASE_MANIFEST.json,
 * and the hash of the canonical inventory is recorded as `manifest_sha256`
 * inside BUILD_INFO.json. Same guarantee, operator contract preserved.
 *
 * ── DETERMINISM ─────────────────────────────────────────────────────────────
 * Everything here must be byte-identical across two builds of the same commit,
 * because release-guard builds twice and compares (gate V10). That constrains:
 *
 *   - file order            sorted by path, byte-wise, locale-independent
 *   - JSON encoding         fixed flags, no pretty-print drift
 *   - permission modes      normalised to 0644/0755 — the umask of a runner is
 *                           not part of the release
 *   - release_uuid          UUIDv5 over repo+tag+commit, NEVER random
 *   - timestamps            confined to BUILD_INFO.json, which is excluded
 *                           from the manifest for exactly this reason
 *
 * A random UUID or an embedded build time inside the manifest would make every
 * release unreproducible by construction, which is why neither is there.
 */

declare(strict_types=1);

const SCHEMA_VERSION = 2;

/** Files that describe the build rather than being part of the payload. */
const METADATA_FILES = [
    'RELEASE_MANIFEST.json',
    'MANIFEST.sha256',
    'BUILD_INFO.json',
];

/**
 * Namespace for deterministic release UUIDs (UUIDv5).
 * Fixed forever — changing it changes every future release identifier.
 */
const DMF_UUID_NAMESPACE = '6ba7b812-9dad-11d1-80b4-00c04fd430c8';

exit(main(array_slice($argv, 1)));

// ─────────────────────────────────────────────────────────────────────────────

/** @param list<string> $argv */
function main(array $argv): int
{
    $command = $argv[0] ?? '';

    return match ($command) {
        'generate' => commandGenerate($argv),
        'verify'   => commandVerify($argv),
        'compare'  => commandCompare($argv),
        default    => usage(),
    };
}

function usage(): int
{
    fwrite(STDERR, <<<TXT

    Canonical package inventory for a DMF release.

      generate <payload-dir> [options]
          Write RELEASE_MANIFEST.json, MANIFEST.sha256 and BUILD_INFO.json.
          Options: --version --tag --commit --repository --branch
                   --run-id --run-attempt --workflow --builder
                   --php --composer --dmf-core

      verify <payload-dir>
          Recompute the inventory and compare it against RELEASE_MANIFEST.json.

      compare <dir-a> <dir-b>
          Assert two builds are reproducible: identical manifests, identical
          checksum files, identical trees, and BUILD_INFO identical once
          timestamps are masked.


    TXT);

    return 2;
}

// ─────────────────────────────────────────────────────────────────────────────
//  generate
// ─────────────────────────────────────────────────────────────────────────────

/** @param list<string> $argv */
function commandGenerate(array $argv): int
{
    $dir = $argv[1] ?? '';

    if ($dir === '' || !is_dir($dir)) {
        fwrite(STDERR, "generate: payload directory not found\n");

        return 1;
    }

    $opt      = parseOptions(array_slice($argv, 2));
    $dir      = rtrim(realpath($dir), '/\\');
    $entries  = buildInventory($dir);
    $manifest = [
        'schema_version' => SCHEMA_VERSION,
        'package'        => $opt['package'] ?? 'dmf/core',
        'version'        => $opt['version'] ?? '',
        'file_count'     => count($entries),
        'total_bytes'    => array_sum(array_column($entries, 'size')),
        'hash_algorithm' => 'sha256',
        'ordering'       => 'path ascending, byte-wise (strcmp)',
        'files'          => $entries,
    ];

    writeJson($dir . '/RELEASE_MANIFEST.json', $manifest);

    $manifestSha = hash_file('sha256', $dir . '/RELEASE_MANIFEST.json');

    // sha256sum -c compatible, covering payload + the canonical inventory.
    $lines = [];
    foreach ($entries as $entry) {
        $lines[] = sprintf('%s  %s', $entry['sha256'], $entry['path']);
    }
    $lines[] = sprintf('%s  %s', $manifestSha, 'RELEASE_MANIFEST.json');
    file_put_contents($dir . '/MANIFEST.sha256', implode("\n", $lines) . "\n");

    printf("  ✓ RELEASE_MANIFEST.json  %d files, %s bytes\n", count($entries), number_format($manifest['total_bytes']));
    printf("  ✓ MANIFEST.sha256        %d lines (payload + inventory)\n", count($lines));

    // Consumer applications write their own BUILD_INFO.json — it carries a
    // `dependencies` block recording which dmf/core was packaged, which only
    // the application's build knows. They call this with --no-build-info and
    // read manifest_sha256 back from RELEASE_MANIFEST.json.
    if (($opt['no-build-info'] ?? '') === 'true') {
        printf("    manifest_sha256        %s\n", $manifestSha);
        printf("    (BUILD_INFO.json left to the caller)\n");

        return 0;
    }

    writeJson($dir . '/BUILD_INFO.json', buildInfo($opt, $manifest, $manifestSha));

    printf("  ✓ BUILD_INFO.json        schema v%d, uuid %s\n", SCHEMA_VERSION, releaseUuid($opt));
    printf("    manifest_sha256        %s\n", $manifestSha);

    return 0;
}

/**
 * Inventory every payload file, deterministically.
 *
 * @return list<array{order: int, path: string, size: int, sha256: string, mode: string}>
 */
function buildInventory(string $dir): array
{
    $paths = [];
    $walk  = static function (string $current) use (&$walk, &$paths, $dir): void {
        $items = scandir($current);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $current . DIRECTORY_SEPARATOR . $item;

            if (is_link($full)) {
                // A link in a release is a hard error, not an inventory entry.
                fwrite(STDERR, sprintf("::error::Symlink in payload: %s\n", $full));
                exit(1);
            }

            if (is_dir($full)) {
                $walk($full);

                continue;
            }

            $relative = str_replace('\\', '/', substr($full, strlen($dir) + 1));

            if (in_array($relative, METADATA_FILES, true)) {
                continue;
            }

            $paths[] = $relative;
        }
    };

    $walk($dir);

    // strcmp, not the locale collation sort() would use — a runner with a
    // different LC_COLLATE must produce the same order.
    usort($paths, static fn (string $a, string $b): int => strcmp($a, $b));

    $entries = [];
    foreach ($paths as $index => $relative) {
        $full = $dir . '/' . $relative;

        $entries[] = [
            'order'  => $index + 1,
            'path'   => $relative,
            'size'   => (int) filesize($full),
            'sha256' => (string) hash_file('sha256', $full),
            // Normalised: a release records intent, not the builder's umask.
            'mode'   => is_executable($full) && !isWindows() ? '0755' : '0644',
        ];
    }

    return $entries;
}

/**
 * @param  array<string,string>                                        $opt
 * @param  array<string,mixed>                                         $manifest
 * @return array<string,mixed>
 */
function buildInfo(array $opt, array $manifest, string $manifestSha): array
{
    $now = gmdate('Y-m-d\TH:i:s\Z');

    // Schema v2 — additive over v1. Every v1 field is still present and keeps
    // its meaning; consumers reading v1 keys continue to work unchanged.
    return [
        // ── v1 fields, unchanged ──────────────────────────────────────────
        'package'           => $opt['package'] ?? 'dmf/core',
        'version'           => $opt['version'] ?? '',
        'tag'               => $opt['tag'] ?? '',
        'git_commit'        => $opt['commit'] ?? '',
        'git_commit_short'  => substr($opt['commit'] ?? '', 0, 7),
        'source_repository' => $opt['repository'] ?? '',
        'source_branch'     => $opt['branch'] ?? '',
        'build_time_utc'    => $now,
        'builder'           => $opt['builder'] ?? 'GitHub Actions',
        'github_run_id'     => $opt['run-id'] ?? '',
        'github_run_attempt' => $opt['run-attempt'] ?? '',
        'github_workflow'   => $opt['workflow'] ?? '',
        'content_sha256'    => $manifestSha,
        'file_count'        => $manifest['file_count'],
        'manifest'          => 'MANIFEST.sha256',
        'archive_sha256_published_in' => 'SHA256SUMS.txt',
        'composer_install_source'     => 'vcs',

        // ── v2 additions ──────────────────────────────────────────────────
        'schema_version'  => SCHEMA_VERSION,
        'release_uuid'    => releaseUuid($opt),
        'generated_at'    => $now,
        'release_manifest' => 'RELEASE_MANIFEST.json',
        'manifest_sha256' => $manifestSha,
        'total_bytes'     => $manifest['total_bytes'],
        'compatibility'   => [
            'php'      => $opt['php'] ?? '^8.1',
            'composer' => $opt['composer'] ?? '^2.0',
            'dmf_core' => $opt['dmf-core'] ?? ($opt['version'] ?? ''),
        ],
        'reproducible_build' => [
            'verified'  => ($opt['reproducible'] ?? 'false') === 'true',
            'method'    => 'double-build comparison in CI',
            'excluded'  => ['build_time_utc', 'generated_at'],
        ],
        'notes' => 'Official production artifact. Composer resolves dmf/core over the vcs '
            . 'repository; this asset exists for audit, rollback, offline installation and '
            . 'DirectAdmin deployment.',
    ];
}

/**
 * Deterministic release identifier — UUIDv5 over repository + tag + commit.
 *
 * MUST NOT be random. Two builds of the same commit have to produce the same
 * manifest and the same BUILD_INFO (timestamps aside), or gate V10 fails by
 * construction. A v5 UUID is name-based, so it is both globally unique per
 * release and stable across rebuilds — which also means an artifact found on a
 * server can be tied back to its source without trusting any other field.
 *
 * @param array<string,string> $opt
 */
function releaseUuid(array $opt): string
{
    $name = sprintf(
        '%s@%s@%s',
        $opt['repository'] ?? '',
        $opt['tag'] ?? '',
        $opt['commit'] ?? '',
    );

    $namespace = str_replace('-', '', DMF_UUID_NAMESPACE);
    $binary    = '';

    for ($i = 0; $i < 32; $i += 2) {
        $binary .= chr((int) hexdec(substr($namespace, $i, 2)));
    }

    $hash = sha1($binary . $name);

    return sprintf(
        '%08s-%04s-%04x-%04x-%12s',
        substr($hash, 0, 8),
        substr($hash, 8, 4),
        (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,   // version 5
        (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,   // RFC 4122 variant
        substr($hash, 20, 12),
    );
}

// ─────────────────────────────────────────────────────────────────────────────
//  verify
// ─────────────────────────────────────────────────────────────────────────────

/** @param list<string> $argv */
function commandVerify(array $argv): int
{
    $dir = $argv[1] ?? '';

    if ($dir === '' || !is_dir($dir)) {
        fwrite(STDERR, "verify: payload directory not found\n");

        return 1;
    }

    $dir  = rtrim(realpath($dir), '/\\');
    $file = $dir . '/RELEASE_MANIFEST.json';

    if (!is_file($file)) {
        fwrite(STDERR, "::error::RELEASE_MANIFEST.json is missing — the package has no canonical inventory.\n");

        return 1;
    }

    $declared = json_decode((string) file_get_contents($file), true);

    if (!is_array($declared) || !isset($declared['files'])) {
        fwrite(STDERR, "::error::RELEASE_MANIFEST.json is malformed.\n");

        return 1;
    }

    $actual = buildInventory($dir);
    $errors = diffInventories($declared['files'], $actual);

    if ($errors !== []) {
        fwrite(STDERR, "::error::The payload does not match RELEASE_MANIFEST.json:\n");
        foreach (array_slice($errors, 0, 20) as $error) {
            fwrite(STDERR, '    ' . $error . "\n");
        }

        return 1;
    }

    printf("  ✓ payload matches RELEASE_MANIFEST.json (%d files)\n", count($actual));

    return 0;
}

/**
 * @param  array<int,array<string,mixed>> $declared
 * @param  array<int,array<string,mixed>> $actual
 * @return list<string>
 */
function diffInventories(array $declared, array $actual): array
{
    $errors = [];
    $byPath = static function (array $entries): array {
        $out = [];
        foreach ($entries as $entry) {
            $out[(string) $entry['path']] = $entry;
        }

        return $out;
    };

    $d = $byPath($declared);
    $a = $byPath($actual);

    foreach ($d as $path => $entry) {
        if (!isset($a[$path])) {
            $errors[] = sprintf('MISSING   %s (declared but not present)', $path);

            continue;
        }

        if ($a[$path]['sha256'] !== $entry['sha256']) {
            $errors[] = sprintf(
                'MODIFIED  %s (sha256 %s… ≠ %s…)',
                $path,
                substr((string) $entry['sha256'], 0, 12),
                substr((string) $a[$path]['sha256'], 0, 12),
            );

            continue;
        }

        if ((int) $a[$path]['size'] !== (int) $entry['size']) {
            $errors[] = sprintf('RESIZED   %s (%d ≠ %d bytes)', $path, $entry['size'], $a[$path]['size']);
        }
    }

    foreach ($a as $path => $_) {
        if (!isset($d[$path])) {
            $errors[] = sprintf('EXTRA     %s (present but not declared)', $path);
        }
    }

    return $errors;
}

// ─────────────────────────────────────────────────────────────────────────────
//  compare — the reproducibility gate
// ─────────────────────────────────────────────────────────────────────────────

/** @param list<string> $argv */
function commandCompare(array $argv): int
{
    $a = $argv[1] ?? '';
    $b = $argv[2] ?? '';

    if (!is_dir($a) || !is_dir($b)) {
        fwrite(STDERR, "compare: both directories must exist\n");

        return 1;
    }

    $failures = [];

    // 1. Canonical inventory — byte-identical.
    foreach (['RELEASE_MANIFEST.json', 'MANIFEST.sha256'] as $file) {
        $ha = @hash_file('sha256', $a . '/' . $file);
        $hb = @hash_file('sha256', $b . '/' . $file);

        if ($ha === false || $hb === false) {
            $failures[] = sprintf('%s missing from one of the builds', $file);

            continue;
        }

        if ($ha !== $hb) {
            $failures[] = sprintf('%s differs between builds (%s… ≠ %s…)', $file, substr($ha, 0, 12), substr($hb, 0, 12));
        } else {
            printf("  ✓ %-22s identical (%s…)\n", $file, substr($ha, 0, 12));
        }
    }

    // 2. Extracted trees — same paths, same content.
    $errors = diffInventories(buildInventory($a), buildInventory($b));

    if ($errors !== []) {
        foreach (array_slice($errors, 0, 10) as $error) {
            $failures[] = 'tree: ' . $error;
        }
    } else {
        printf("  ✓ %-22s identical\n", 'file tree');
    }

    // 3. BUILD_INFO.json — identical once timestamps are masked. Timestamps are
    //    the only fields legitimately allowed to differ; anything else varying
    //    means the build is not a pure function of the commit.
    $ia = json_decode((string) @file_get_contents($a . '/BUILD_INFO.json'), true);
    $ib = json_decode((string) @file_get_contents($b . '/BUILD_INFO.json'), true);

    if (!is_array($ia) || !is_array($ib)) {
        $failures[] = 'BUILD_INFO.json missing or malformed in one of the builds';
    } else {
        $volatile = ['build_time_utc', 'generated_at'];
        foreach ($volatile as $key) {
            unset($ia[$key], $ib[$key]);
        }
        unset($ia['reproducible_build'], $ib['reproducible_build']);

        if ($ia !== $ib) {
            foreach (array_keys($ia + $ib) as $key) {
                $va = json_encode($ia[$key] ?? null);
                $vb = json_encode($ib[$key] ?? null);

                if ($va !== $vb) {
                    $failures[] = sprintf('BUILD_INFO.%s differs: %s ≠ %s', $key, $va, $vb);
                }
            }
        } else {
            printf("  ✓ %-22s identical (timestamps masked)\n", 'BUILD_INFO.json');
        }
    }

    if ($failures !== []) {
        fwrite(STDERR, "\n::error::The build is NOT reproducible.\n");
        foreach ($failures as $failure) {
            fwrite(STDERR, '    ' . $failure . "\n");
        }
        fwrite(STDERR, "\n");
        fwrite(STDERR, "Two builds of the same commit must be byte-identical apart from\n");
        fwrite(STDERR, "build_time_utc and generated_at. A difference means something in the\n");
        fwrite(STDERR, "build depends on the environment rather than the source — commonly a\n");
        fwrite(STDERR, "random value, a locale-dependent sort, an absolute path, or a\n");
        fwrite(STDERR, "filesystem mode leaking the runner's umask.\n");

        return 1;
    }

    printf("\n  REPRODUCIBLE — both builds agree.\n");

    return 0;
}

// ─────────────────────────────────────────────────────────────────────────────
//  helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param  list<string> $args
 * @return array<string,string>
 */
function parseOptions(array $args): array
{
    $out = [];

    foreach ($args as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $pair = explode('=', substr($arg, 2), 2);
        $out[$pair[0]] = $pair[1] ?? 'true';
    }

    return $out;
}

/** @param array<string,mixed> $data */
function writeJson(string $path, array $data): void
{
    // Fixed flags: the encoding must not vary between builds.
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );

    file_put_contents($path, $json . "\n");
}

function isWindows(): bool
{
    return stripos(PHP_OS_FAMILY, 'Windows') === 0;
}
