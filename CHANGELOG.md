# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
