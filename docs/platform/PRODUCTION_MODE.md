# Production / Release Mode

`dmf/core` installed from Git as **real files**, so the tree can be zipped,
uploaded, and extracted on shared hosting without anything breaking.

Release mode is the **default**. A fresh clone is already in it; nothing has to
be switched on, and nothing has to be remembered before a release.

---

## 1. The rule

> **`type: path` MUST NOT appear in any manifest that a build, a CI run, or a
> release artifact ever reads.**

The committed `composer.json` declares `dmf/core` from Git and nothing else:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/thajex1968/dmf-core.git" }
    ],
    "require": {
        "dmf/core": "^1.1"
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

Composer resolves `^1.1` to the newest matching `1.x` **tag**, downloads the GitHub
zipball, and unpacks real files into `vendor/dmf/core`.

---

## 2. Why this is not optional

Composer satisfies a path repository with a **symlink** — a directory junction
on Windows:

```
vendor/dmf/core  ──►  ../dmf-core        (workstation: junction)
        │
        ├── ZIP        stored as a link entry, or skipped entirely
        ├── upload     the link target does not exist on the server
        └── extract    vendor/dmf/core lands EMPTY
                              │
                              ▼
        Fatal error: Class "Dmf\Core\Security\Sanitizer" not found
```

This is not a hosting quirk to work around. A symlink is a reference to
somewhere else on the *build machine*; a release artifact must be
self-contained. Release mode makes the artifact self-contained by construction.

---

## 3. `composer.lock` is committed

With a path repository the lock recorded:

```json
"dist": { "type": "path", "url": "../dmf-core" }
```

— meaningless anywhere but the machine that wrote it, which is why projects
git-ignored it. Over a `vcs` repository the lock records a **commit SHA**, so it
is reproducible, so it must be tracked.

The lock is what makes a build repeatable: CI, a colleague, and a rebuild six
months from now all resolve the identical `dmf/core` commit. `git log -p
composer.lock` is the record of which library commit each application release
shipped against.

---

## 4. Authentication

`thajex1968/dmf-core` is private.

**Once per developer machine:**

```bash
composer config --global --auth github-oauth.github.com ghp_xxxxxxxxxxxx
```

A classic PAT with `repo` (read) scope, or a fine-grained token with *Contents:
read* on `thajex1968/dmf-core`. It lands in `~/.composer/auth.json` — outside
the project, so it cannot be committed.

**In GitHub Actions:**

```yaml
env:
  COMPOSER_AUTH: '{"github-oauth":{"github.com":"${{ secrets.DMF_CORE_TOKEN }}"}}'
```

`COMPOSER_AUTH` is read directly by Composer and leaves no file behind, so
nothing can be packaged by accident.

> GitHub returns **404**, not 403, for a private repository to an
> unauthenticated client. So a missing token shows up as
> `Could not find package dmf/core in any version` — check the token before
> you go looking for a version-constraint problem.

---

## 5. Building a release

```bash
composer dmf:build            # → release/dmf-dmf-template-php-1.2.0.zip
```

`scripts/build-release.php`:

1. **Preflight** — refuses to build if `composer.json` declares a path
   repository, if `composer.lock` is missing, or if a declared payload path
   does not exist.
2. **Stage dependencies** — copies *only* `composer.json` and `composer.lock`
   into a clean temp directory and runs
   `composer install --no-dev --optimize-autoloader` there.
3. **Stage payload** — copies the paths declared in `extra.dmf-release.include`.
4. **`BUILD_INFO.json`** — version, UTC timestamp, commit, branch, PHP version.
5. **Verify the staged tree** — all 8 gates.
6. **Archive** — ZIP with every entry forced to a plain `0644` file mode.
7. **Verify the ZIP** — all 8 gates again, now against the real artifact. A
   failure **deletes the archive** and exits non-zero.
8. **`SHA256SUMS.txt`**.

Step 2 is the crux: dependencies are **never** copied from the working tree's
`vendor/`. A developer in development mode has a symlinked `vendor/dmf/core`,
and copying it would package a link. Staging from the committed manifest means
the build is identical whatever mode the developer is in — and identical to
what CI produces.

### What gets packaged

Declared in `composer.json`, so a project customises its payload without
editing the script:

```json
"extra": {
    "dmf-release": {
        "output": "release",
        "include": [
            { "from": "public_html", "to": "." },
            { "from": "includes",    "to": "includes" }
        ]
    }
}
```

`"to": "."` flattens a directory into the archive root — that is what lets an
operator extract straight into `~/public_html/` with no manual moves.

---

## 6. Verification gates

Run standalone against any artifact:

```bash
composer dmf:verify -- --zip  release/app-1.2.0.zip
composer dmf:verify -- --tree build/staging
```

| # | Assertion | Detects |
|---|---|---|
| V1 | No ZIP entry is a symlink or link-type entry | junction leaked into the package |
| V2 | `vendor/dmf/core/composer.json` present, non-empty | empty vendor directory |
| V3 | `vendor/dmf/core/src/Security/Sanitizer.php` present | the exact class that broke production |
| V4 | `installed.json` records no `"dist": {"type": "path"}` | path repository survived the build |
| V5 | Packaged `composer.json` declares no `type: path` | dev manifest was packaged |
| V6 | `vendor/autoload.php` resolves the core classes | autoload map is wrong |
| V7 | No `tests/`, `docs/`, `.github/` under `vendor/dmf/core` | `export-ignore` regressed |
| V8 | SHA-256 recorded | upload integrity |

V1, V3 and V4 are the non-negotiable three: together they make the original
production failure impossible to ship.

Symlink detection is deliberate about Windows. Neither `is_link()` nor
`readlink()` identifies a junction there — `is_link()` returns false, and
`readlink()` succeeds for ordinary paths too. What holds everywhere is that
`realpath()` of a link resolves somewhere other than where the link sits, so
that is the test. Getting it wrong reports a junction as a real directory,
which is precisely the misdetection that lets one reach production.

---

## 7. What CI does

| Workflow | Trigger | Does |
|---|---|---|
| `ci.yml` | push, PR | lint, PHP-CS-Fixer, PHPStan, PHPUnit |
| `build.yml` | push, PR, dispatch | builds the ZIP, runs the gates, uploads it as an artifact |
| `verify-release.yml` | called by the others | the 8 gates, plus an explicit no-symlink sweep |
| `release.yml` | tag `v*` | tag + changelog check → quality gates → build → verify → GitHub Release |

`build.yml` runs on **pull requests**, so a change that would break packaging is
caught at review time rather than at release time.

---

## 8. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Build fails: *composer.json declares a path repository* | a path repo was committed | remove it; use `composer dmf:dev` |
| Build fails: *composer.lock is missing* | lock is git-ignored | commit it — §3 |
| V1 fails | working tree was packaged instead of a staged install | use `composer dmf:build`, never zip by hand |
| V4 fails | build ran against `composer-dev.json` | `unset COMPOSER`, `composer dmf:release` |
| V7 fails | `dmf/core` tag predates its `.gitattributes` | require `^1.1` or later |
| `Could not find package dmf/core` | private repo, no token | §4 |

---

## 9. See also

- [`DEVELOPMENT_MODE.md`](DEVELOPMENT_MODE.md) — the counterpart
- [`RELEASE_PIPELINE.md`](RELEASE_PIPELINE.md) — cutting a release
- [`DEPLOYMENT_GUIDE.md`](DEPLOYMENT_GUIDE.md) · [`DIRECTADMIN_GUIDE.md`](DIRECTADMIN_GUIDE.md)
