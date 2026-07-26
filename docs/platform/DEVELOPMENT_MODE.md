# Development Mode

Edit `dmf-core` and see the change in this application on the very next request,
with no copy step and no rebuild.

Development mode is **opt-in**. A fresh clone is in release mode, because
release mode is the one that must never be got wrong.

---

## 1. Layout

Development mode needs `dmf-core` checked out beside this project:

```
D:\All_Project\                 (or ~/projects, anywhere)
├── dmf-core\                   ← the library
└── your-app\                   ← this project; ../dmf-core resolves from here
```

```bash
git clone https://github.com/thajex1968/dmf-core.git
git clone https://github.com/thajex1968/dmf-template-php.git your-app
```

---

## 2. Activate

```bash
cd your-app
composer dmf:dev
```

Output:

```
── Development mode ────────────────────────────────────────

  ✓ Found sibling checkout: D:\All_Project\dmf-core
  ✓ Generated composer-dev.json (git-ignored, derived from composer.json)
    Running: COMPOSER=composer-dev.json composer install

  vendor/dmf/core:                   symlink → D:\All_Project\dmf-core
  Packageable:                       NO — a junction does not survive ZIP → upload → extract

  ✓ Development mode active — edits in ../dmf-core take effect immediately.
```

That is all. Edit `../dmf-core/src/…` and reload.

---

## 3. What just happened

`composer dmf:dev` generated `composer-dev.json` — this project's `composer.json`
with a path repository prepended and the constraint relaxed:

```json
{
    "repositories": [
        { "type": "path", "url": "../dmf-core", "options": { "symlink": true } },
        { "type": "vcs",  "url": "https://github.com/thajex1968/dmf-core.git" }
    ],
    "require": { "dmf/core": "@dev" },
    "minimum-stability": "dev"
}
```

then ran `composer install` with `COMPOSER=composer-dev.json`.

Three properties matter:

| Property | Why |
|---|---|
| `composer-dev.json` is **generated**, never authored | It cannot drift from `composer.json` — it is derived from it every time |
| `composer-dev.json` and `composer-dev.lock` are **git-ignored** | A path repository can never be committed, so it can never reach a build |
| Composer derives the lock name from `COMPOSER` | `composer-dev.lock` and `composer.lock` are separate; dev work cannot corrupt the production lock |

`composer.json` itself is **never written** by the mode switcher. The committed
manifest is immutable to the tool.

---

## 4. Everyday work

```bash
composer dmf:status          # which mode am I in?
composer test                # unaffected by mode
composer check               # lint + analyse + test
```

`composer dmf:status` in development mode:

```
  Development manifest:              composer-dev.json present
  COMPOSER env var:                  (unset)
  Committed constraint:              ^1.1
  Path repository in composer.json:  no

  vendor/dmf/core:                   symlink → D:\All_Project\dmf-core
  Packageable:                       NO — a junction does not survive ZIP → upload → extract

  Development mode. Run "composer dmf:release" before packaging.
```

### Adding a dependency while in development mode

```bash
COMPOSER=composer-dev.json composer require some/package     # bash
$env:COMPOSER='composer-dev.json'; composer require some/package   # PowerShell
```

This updates `composer-dev.json`, which is git-ignored — so **also add it to
the real manifest** and refresh the production lock:

```bash
composer dmf:release
composer require some/package
git add composer.json composer.lock
```

Forgetting this is the one way the two manifests can diverge. `composer dmf:dev`
regenerates from `composer.json`, so the next switch silently drops anything
added only on the dev side.

---

## 5. Leaving development mode

```bash
composer dmf:release
```

Deletes `composer-dev.json` and `composer-dev.lock`, removes the symlinked
`vendor/dmf/core`, and reinstalls from Git as real files.

**Always do this before building a release by hand.** `scripts/build-release.php`
does not trust you: it stages dependencies in a clean directory from the
committed manifest, so it produces a correct package either way. But
`composer dmf:status` should read *Release mode* before you ship anything.

---

## 6. Working on a `dmf-core` change

```bash
cd ../dmf-core
git checkout -b fix/sanitizer-edge-case
# edit src/…, the app sees it immediately
composer test

cd ../your-app
composer test                # exercise it from the consuming side

cd ../dmf-core
git commit && git push       # open a PR
```

Once the `dmf-core` change is merged **and tagged**, pick it up here:

```bash
composer dmf:release
composer update dmf/core
git add composer.lock && git commit -m "chore(deps): bump dmf/core to v1.2.0"
```

Until that tag exists, this application cannot be released against the change —
release mode resolves tags, not branches. That is intentional: it prevents
shipping an application built on an unreviewed library commit.

---

## 7. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `Sibling checkout not found: ../dmf-core` | no sibling clone | §1, or use release mode |
| Edits to `../dmf-core` do nothing | release mode is active | `composer dmf:dev` |
| `composer install` uses the wrong manifest | `COMPOSER` still exported | `unset COMPOSER` (bash) / `Remove-Item Env:COMPOSER` (PowerShell) |
| `Could not find package dmf/core` after `dmf:release` | private repo, no token | `composer config --global --auth github-oauth.github.com <PAT>` |
| `git status` shows `composer-dev.json` | `.gitignore` is missing the entry | add `composer-dev.json` and `composer-dev.lock` — they must never be committed |

---

## 8. See also

- [`PRODUCTION_MODE.md`](PRODUCTION_MODE.md) — the release-mode counterpart
- [`RELEASE_PIPELINE.md`](RELEASE_PIPELINE.md) — how a release is built and verified
- [`MIGRATION.md`](MIGRATION.md) — moving an existing project onto this model
