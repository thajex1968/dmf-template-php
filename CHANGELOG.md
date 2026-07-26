# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.2.0] - 2026-07-26

Production Release Pipeline. The template now ships a complete, verified path
from a commit to a ZIP an operator can upload to DirectAdmin — and a strict
separation between how `dmf/core` is consumed in development and in release.

No application code was touched. `includes/`, `public_html/`, `config/` and
`database/` are unchanged, and no public API changed.

### Added
- **`dmf/core` dependency**, declared over a `vcs` repository — never a path
  repository. A Composer path repository is satisfied with a symlink (a
  directory junction on Windows), which does not survive ZIP → upload →
  extract on shared hosting: `vendor/dmf/core` arrives empty and the
  application dies with `Class "Dmf\Core\Security\Sanitizer" not found`.
  Requires `dmf/core ^1.1` — the first tag whose dist archive has
  `export-ignore` applied.
- **`scripts/dmf-mode.php`** — the mode switcher, exposed as `composer dmf:dev`,
  `dmf:release`, `dmf:status`. Development mode generates a **git-ignored**
  `composer-dev.json` that prepends the `../dmf-core` path repository, then
  installs against it via the `COMPOSER` environment variable — so Composer
  writes `composer-dev.lock` and the production lock is never disturbed. The
  committed `composer.json` is never written by the tool. PHP rather than
  shell because Windows is a first-class developer platform here.
- **`scripts/build-release.php`** — `composer dmf:build`. Stages dependencies
  in a clean temp directory from **only** `composer.json` + `composer.lock`,
  never by copying the working tree's `vendor/`, so a developer in development
  mode still produces a correct package. Payload declared in
  `extra.dmf-release.include`, so projects customise without editing the
  script. Verifies the staged tree *and* the finished ZIP; a failure deletes
  the archive and exits non-zero.
- **`scripts/verify-release.php`** — `composer dmf:verify`. Eight gates against
  a built artifact (ZIP or tree): no symlinks, `vendor/dmf/core` real and
  populated, `Sanitizer.php` present, install source not `path`, no path
  repository in the packaged manifest, autoloader resolves the core classes,
  no development files shipped, SHA-256 recorded.
- **`.github/workflows/build.yml`** — builds and verifies the package on every
  push and pull request, so packaging breakage surfaces at review time. The
  artifact is downloadable from the run.
- **`.github/workflows/verify-release.yml`** — reusable (`workflow_call`); the
  single definition of the gates, called by both `build.yml` and `release.yml`
  so they cannot drift. Adds an independent `unzip -l` symlink sweep and a
  real extraction check.
- **`.github/workflows/release.yml`** — tag → validate semver → CHANGELOG and
  VERSION agreement → quality gates on PHP 8.1/8.2/8.3 → build → verify →
  GitHub Release with the ZIP and `SHA256SUMS.txt`.
- **`docs/platform/`** — `RELEASE_PIPELINE.md`, `DEVELOPMENT_MODE.md`,
  `PRODUCTION_MODE.md`, `DEPLOYMENT_GUIDE.md`, `DIRECTADMIN_GUIDE.md`,
  `MIGRATION.md`.

### Changed
- **`composer.json`** — added `homepage`, `support`, the `vcs` repository,
  `ext-json`/`ext-pdo`/`ext-hash`, `minimum-stability: stable` +
  `prefer-stable`, the five `dmf:*` scripts with descriptions, and
  `extra.dmf-release`.
- **`.gitignore`** — ignores `composer-dev.json`, `composer-dev.lock`,
  `auth.json`, and built packages, with the reasoning recorded inline.
  `composer.lock` stays tracked: over a `vcs` repository it records a commit
  SHA and is reproducible, which is what makes a build repeatable.

## [1.1.0] - 2026-07-14
### Added
- **Reusable architecture layer** extracted from the `projects.dmf.ac.th`
  reference implementation, with all business logic removed:
  - `config/config.php` — constants + shared PDO `$conn`, secrets read from `.env`.
  - `includes/env.php` — dependency-free `.env` loader.
  - `includes/auth_check.php` — session guard, idle timeout, `$currentUser`/role globals.
  - `includes/permission.php` — RBAC primitives (`hasRole`, `requireRole`, `requirePermission`).
  - `includes/csrf.php` — CSRF token helpers (a secure default the reference lacked).
  - `includes/routes.php` — central `ROUTES` map + `route()`/`redirect()`/`routeActive()`.
  - `includes/mailer.php` — dependency-free SMTP client (STARTTLS).
  - `includes/navbar.php` — shared navigation component.
  - `public_html/` skeleton — `index.php`, `auth/login.php`, `auth/logout.php`,
    `dashboard/index.php` (the canonical include-chain page).
- Security hardening: `public_html/.htaccess` (security headers, dotfile blocking)
  and `public_html/uploads/.htaccess` (no code execution in uploads).
- Core database schema (`users`, `password_resets`, `login_attempts`,
  `audit_logs`), a seed with an initial admin, and the migration convention.
- Dev tooling: `composer.json`, `phpunit.xml.dist`, and smoke tests.
- Documentation set: `docs/architecture.md`, `docs/database.md`,
  `docs/coding-standards.md`, `docs/git-workflow.md`, `docs/security.md`.

## [1.0.0] - 2026-07-14
### Added
- Initial project foundation (Phase 1).
- Standardized directory structure (`config`, `database`, `docs`, `includes`,
  `public_html`, `storage`, `tests`).
- Root tooling: `.editorconfig`, `.gitignore`, `.gitattributes`, `.env.example`,
  `phpstan.neon.dist`, `VERSION`, `LICENSE` (MIT).
- Professional `README.md` with badges, workflow diagram, and roadmap.
- GitHub automation: CI workflow, issue templates, and pull request template.
- `CONTRIBUTING.md` contribution guide.

[Unreleased]: https://github.com/dmf/dmf-template-php/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/dmf/dmf-template-php/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/dmf/dmf-template-php/releases/tag/v1.0.0
