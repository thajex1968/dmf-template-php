# Release Pipeline

How this application goes from a commit to a ZIP an operator can upload.

The platform-wide normative specification lives in `dmf-core` →
[`docs/platform/RELEASE_PIPELINE.md`](https://github.com/thajex1968/dmf-core/blob/master/docs/platform/RELEASE_PIPELINE.md).
This document is the application-side implementation of it.

---

## 1. Shape

```
   tag v1.2.0
       │
       ▼
  ┌──────────────┐   ┌──────────────┐   ┌──────────────┐
  │ validate-tag │──►│  changelog   │──►│   quality    │
  │   (semver)   │   │    check     │   │    gates     │
  └──────────────┘   └──────────────┘   └──────────────┘
                                               │
       ┌───────────────────────────────────────┘
       ▼
  ┌──────────────┐   ┌──────────────┐   ┌──────────────┐
  │    build     │──►│    verify    │──►│   release    │
  │   package    │   │   artifact   │   │              │
  └──────────────┘   └──────────────┘   └──────────────┘
```

Every stage is blocking. **verify** is the stage that would have caught the
original production failure, so it is never `continue-on-error`.

---

## 2. Workflows

| File | Trigger | Purpose |
|---|---|---|
| `ci.yml` | push, PR | lint · PHP-CS-Fixer · PHPStan · PHPUnit |
| `build.yml` | push, PR, dispatch | build the ZIP and run the gates — catches packaging breakage at review time |
| `verify-release.yml` | `workflow_call`, dispatch | the 8 gates plus a no-symlink sweep; reusable |
| `release.yml` | tag `v*` | the full pipeline, ending in a GitHub Release |

`release.yml` and `build.yml` both call `verify-release.yml`, so the gates have
exactly one definition.

---

## 3. Cutting a release

```bash
# 1. Make sure you are in release mode and green
composer dmf:release
composer check

# 2. Record the release
#    - add a "## [1.2.0] — YYYY-MM-DD" section to CHANGELOG.md
#    - update VERSION
git add CHANGELOG.md VERSION
git commit -m "chore(release): 1.2.0"

# 3. Build and verify locally (optional — CI does this too)
composer dmf:build

# 4. Tag and push
git tag v1.2.0
git push origin main --tags
```

`release.yml` takes over: it validates the tag as strict semver, requires a
matching `CHANGELOG.md` section, runs the quality gates, builds the package,
runs the verification gates, and publishes the ZIP plus `SHA256SUMS.txt` to a
GitHub Release with notes extracted from the changelog.

### Version numbering

Semver, on the application:

| Change | Bump |
|---|---|
| Bug fix, no schema or config change | patch |
| New feature, backward-compatible | minor |
| Breaking config/schema change, or a `dmf/core` major bump | major |

A `dmf/core` **minor** bump is a minor bump here. A `dmf/core` **major** bump
is a major bump here, because the constraint has to widen.

---

## 4. Authentication in CI

`dmf/core` is private, so every job that runs `composer install` needs:

```yaml
env:
  COMPOSER_AUTH: '{"github-oauth":{"github.com":"${{ secrets.DMF_CORE_TOKEN }}"}}'
```

Set `DMF_CORE_TOKEN` in *Settings → Secrets and variables → Actions*: a PAT with
`repo` (read) scope, or fine-grained with *Contents: read* on
`thajex1968/dmf-core`.

Without it Composer reports `Could not find package dmf/core in any version` —
GitHub returns 404 rather than 403 to unauthenticated clients, so a missing
token looks like a missing package.

---

## 5. Artifacts

| Artifact | Where | Retention |
|---|---|---|
| `<name>-<version>.zip` | GitHub Release + workflow artifact | permanent / 90 days |
| `SHA256SUMS.txt` | alongside the ZIP | same |
| `BUILD_INFO.json` | **inside** the ZIP | with the deployment |

`BUILD_INFO.json` records version, UTC build time, commit, branch, PHP version
and `"mode": "release"`. It is how you identify what is actually running on a
server months later, without SSH.

---

## 6. Reproducing a build

```bash
git checkout v1.2.0
composer dmf:release
composer dmf:build
sha256sum release/*.zip
```

The `dmf/core` commit is pinned by `composer.lock`, so this resolves the same
library code CI resolved. The ZIP itself is not bit-identical — it carries
timestamps — but its contents are.

---

## 7. If a release goes wrong

1. **Do not delete the tag.** Published tags are a record.
2. Fix forward: patch, changelog entry, new tag.
3. To roll back a deployment, re-upload the previous release ZIP — every
   release is a complete, self-contained package, so rollback is just extracting
   an older one.
4. If a build passed verification but failed in production, add the gate that
   would have caught it to `scripts/verify-release.php`. That is what the gate
   list is for.

---

## 8. See also

- [`DEVELOPMENT_MODE.md`](DEVELOPMENT_MODE.md) · [`PRODUCTION_MODE.md`](PRODUCTION_MODE.md)
- [`DEPLOYMENT_GUIDE.md`](DEPLOYMENT_GUIDE.md) · [`DIRECTADMIN_GUIDE.md`](DIRECTADMIN_GUIDE.md)
- [`MIGRATION.md`](MIGRATION.md)
