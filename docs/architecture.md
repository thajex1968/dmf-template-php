# Architecture

This template extracts the **reusable architecture** of the DMF platform's
reference implementation (`projects.dmf.ac.th`) with **all business logic
removed**. It does not introduce a new architecture — it is the same
plain-procedural-PHP shape, generalized and hardened.

## System Overview

```
┌─────────────┐     ┌──────────────────────────────────────────┐
│   Browser    │────▶│              Apache + PHP 8.1+            │
│ (Bootstrap)  │◀────│                                          │
└─────────────┘     │  public_html/   ← the ONLY web root       │
                    │  ├── index.php  ← entry redirect          │
                    │  ├── auth/      ← login / logout           │
                    │  └── dashboard/ ← reference page           │
                    └─────────────┬────────────────────────────┘
                                  │ requires (above web root)
                                  ▼
                    ┌──────────────────────────────────────────┐
                    │  config/   ← env-driven PDO $conn          │
                    │  includes/ ← auth, permission, routes,     │
                    │              csrf, mailer, navbar          │
                    └─────────────┬────────────────────────────┘
                                  │ PDO (prepared statements)
                                  ▼
                    ┌──────────────────────────────────────────┐
                    │  MySQL 8 / MariaDB 10.5+  (utf8mb4)        │
                    │  core tables only: users, audit_logs, …    │
                    └──────────────────────────────────────────┘
```

Only `public_html/` is served. `config/`, `includes/`, `database/`, and
`storage/` live above the web root and are unreachable over HTTP — a hardening
improvement over the reference, which kept them inside the document root.

## Request Flow (the include chain)

Every authenticated page follows the same skeleton (see
`public_html/dashboard/index.php`):

```php
require_once __DIR__ . '/../../includes/auth_check.php';  // 1. session guard → $currentUser, $userId, $role, $isAdmin
require_once __DIR__ . '/../../includes/permission.php';  // 2. RBAC helpers
require_once __DIR__ . '/../../includes/routes.php';      // 3. route() / redirect() / routeActive()

requirePermission(hasRole($role, 'admin'));               // 4. gate (optional)

// 5. SQL via $conn (PDO prepared statements)
// 6. compute view variables
// 7. render full HTML document (inline <style>/<script>)
require_once __DIR__ . '/../../includes/navbar.php';      // 8. shared navbar
```

Public pages (e.g. a token survey) skip `auth_check.php` and include only
`config/config.php`.

## Components

| File | Responsibility |
|------|----------------|
| `config/config.php` | Constants + single shared `$conn` (PDO). Reads secrets from `.env`. |
| `includes/env.php` | Dependency-free `.env` parser exposing `env()`. |
| `includes/auth_check.php` | Session guard, idle timeout, loads `$currentUser` and role flags. |
| `includes/permission.php` | Reusable RBAC primitives: `hasRole()`, `requireRole()`, `requirePermission()`. |
| `includes/csrf.php` | `csrf_field()` / `csrf_verify()` — a secure default the reference lacked. |
| `includes/routes.php` | Central `ROUTES` map + `route()` / `redirect()` / `routeActive()`. |
| `includes/mailer.php` | Dependency-free SMTP client (STARTTLS). |
| `includes/navbar.php` | Shared navigation, rendered from `ROUTES`. |

## Database Layer

- Single global `$conn` (PDO) created in `config/config.php`.
- **Prepared statements everywhere** — positional `?` parameters, no ORM.
- `PDO::ATTR_EMULATE_PREPARES => false` for real server-side prepares.
- Transactions for multi-step writes.

## Authentication & Authorization

```
login.php
├── CSRF check
├── password_verify() (bcrypt)
├── session_regenerate_id(true)   ← prevents session fixation
└── set $_SESSION[user_id, role, user_name]

auth_check.php (every page)
├── enforce idle timeout (SESSION_TIMEOUT)
├── require $_SESSION[user_id]
├── load user from DB (deny if inactive/missing)
└── expose $currentUser, $userId, $role, $isAdmin, $avatarPath
```

RBAC is deliberately generic: a single lowercase `role` string per user plus
the `hasRole()` / `requireRole()` / `requirePermission()` helpers. Applications
build their domain rules (e.g. "can edit record X") on top of these — exactly
as the reference implementation did.

## Frontend

- No shared CSS/JS build — each page carries its own inline `<style>`/`<script>`.
- CDN dependencies: Bootstrap 5.3, Font Awesome 6.4, Google Fonts (Sarabun).
- Design tokens via CSS variables:
  `--primary:#1e3a8a; --bg:#f1f5fb; --surface:#fff; --border:#e2e8f0; --text:#1e293b; --muted:#64748b`.

## What was removed

Everything domain-specific from the reference was stripped: projects,
activities, budgets, the 4-step approval workflow, free-15 budgeting, public
evaluations, multi-school tenancy, and their tables, routes, and permission
functions. Only the reusable skeleton remains.
