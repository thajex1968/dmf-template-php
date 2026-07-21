# PROJECT_CONTEXT.md — Ground Truth for AI

> A factual, machine-optimized map of this repository. Read this **before**
> generating anything, so you do not re-derive or hallucinate structure. Every
> section cites the authoritative reference doc; those docs and the actual source
> code outrank this summary on any conflict (see
> [`AI_RULES.md`](./AI_RULES.md#0-decision-hierarchy-what-wins)).

---

## Repository Identity

| Field | Value | Source |
| ----- | ----- | ------ |
| Name | `dmf-template-php` | [`README.md`](../../README.md) |
| Role | Official DMF PHP Framework — parent template for all DMF PHP apps | [`README.md`](../../README.md) |
| Layer | Platform Layer | workspace `../../../.claude/CLAUDE.md` |
| Version | `1.1.0` | [`VERSION`](../../VERSION), [`CHANGELOG.md`](../../CHANGELOG.md) |
| Business logic | **None** — reusable skeleton only | [`README.md`](../../README.md) |
| License | MIT | [`LICENSE`](../../LICENSE) |

## Purpose

Standardized, production-ready foundation that bootstraps every DMF PHP service
(`projects`, `timetable`, `grade`, `library`, `inventory`, `health`, `plant`, …).
It extracts the reusable architecture of the reference implementation
(`projects.dmf.ac.th`) with all domain features removed. Consumed as a GitHub
Template Repository.

---

## Technology Stack

| Concern | Choice | Notes |
| ------- | ------ | ----- |
| Language | PHP ≥ 8.1 | v1.x runtime baseline ([`README.md`](../../README.md#requirements)) |
| Paradigm | Procedural + include-chain | **current source of truth** |
| Data access | PDO, prepared statements, no ORM | single shared `$conn` |
| Database | MySQL 8.0+ / MariaDB 10.5+, InnoDB, `utf8mb4` | [`DATABASE.md`](../../DATABASE.md#platform-standard) |
| Web server | Apache 2.4+ / Nginx | `public_html/` is the only doc root |
| Front-end | Vanilla JS, inline CSS/JS, approved CDNs | no build step |
| Composer | **Dev tooling only** (PHPStan, PHPUnit, PHP-CS-Fixer) | no runtime deps |
| Static analysis | PHPStan level 5 | [`CODING_STANDARD.md`](../../CODING_STANDARD.md#tooling--enforcement) |
| Tests | PHPUnit 10 | [`TESTING.md`](../../TESTING.md) |

---

## Directory Map

> Full trees: [`README.md`](../../README.md#directory-structure),
> [`ARCHITECTURE.md`](../../ARCHITECTURE.md#folder-structure). Summary:

```text
config/config.php     constants + shared PDO $conn (reads .env)
includes/             reusable architecture — ABOVE the web root
  env.php             .env loader → env($key, $default)
  auth_check.php      session guard, idle timeout → $currentUser/$userId/$role/$isAdmin
  permission.php      hasRole/isAdminRole/requireRole/requireLogin/requirePermission
  csrf.php            csrf_token/csrf_field/csrf_verify/csrf_require
  routes.php          ROUTES map + route/routeUrl/redirect/routeTitle/routeActive
  mailer.php          sendMail() — dependency-free STARTTLS SMTP
  navbar.php          shared navigation rendered from ROUTES
public_html/          THE ONLY WEB DOCUMENT ROOT
  .htaccess           security headers + dotfile/extension blocking
  index.php           entry redirect
  auth/               login.php, logout.php
  dashboard/          reference page (canonical include-chain example)
  uploads/            public assets, PHP execution disabled via .htaccess
database/migrations/  ordered .sql (0001_core_schema.sql = core only)
database/seeds/       idempotent seed data (initial admin)
storage/              runtime, git-ignored (cache, logs, uploads)
tests/                PHPUnit (bootstrap.php + CoreTest.php)
docs/ + docs/ai/      reference docs + this AI guide
```

> **Boundary invariant:** `public_html/` is served; `config/`, `includes/`,
> `database/`, `storage/`, `tests/` are **not** reachable over HTTP.

---

## Component Responsibilities

Authoritative table: [`ARCHITECTURE.md`](../../ARCHITECTURE.md#component-map).
Verify a symbol exists (Grep/Read) before referencing it — do not assume.

| File | Provides |
| ---- | -------- |
| `config/config.php` | Constants; the single `$conn` (PDO). |
| `includes/env.php` | `env($key, $default)` from `.env`. |
| `includes/auth_check.php` | Session start, idle timeout, `$currentUser`, `$userId`, `$role`, `$isAdmin`, `$avatarPath`. |
| `includes/permission.php` | `hasRole()`, `isAdminRole()`, `requireRole()`, `requireLogin()`, `requirePermission()`. |
| `includes/csrf.php` | `csrf_token()`, `csrf_field()`, `csrf_verify()`, `csrf_require()`. |
| `includes/routes.php` | `ROUTES` + `route()`, `routeUrl()`, `redirect()`, `routeTitle()`, `routeActive()`. |
| `includes/mailer.php` | `sendMail()` (STARTTLS SMTP). |
| `includes/navbar.php` | Navigation from `ROUTES`, honoring the `admin` flag. |

### Canonical include chain (authenticated page)

```php
require_once __DIR__ . '/../../includes/auth_check.php';  // 1. session → globals
require_once __DIR__ . '/../../includes/permission.php';  // 2. RBAC helpers
require_once __DIR__ . '/../../includes/routes.php';      // 3. link helpers
requirePermission(hasRole($role, 'admin'));               // 4. gate (optional)
// 5. SQL via $conn → 6. compute view → 7. render HTML
require_once __DIR__ . '/../../includes/navbar.php';      // 8. navigation
```

---

## Database Overview — source: [`DATABASE.md`](../../DATABASE.md)

Core schema (reusable only; domain tables belong to each app):

| Table | Purpose |
| ----- | ------- |
| `users` | Accounts + role; `password` = bcrypt/argon hash. |
| `password_resets` | Single-use token recovery; stores only sha256 token hash. |
| `login_attempts` | Per-username/IP attempt log for lockout/rate limiting. |
| `audit_logs` | Generic who-did-what trail with JSON payload. |

Conventions: InnoDB, `utf8mb4_unicode_ci`, `+07:00`; `id BIGINT UNSIGNED
AUTO_INCREMENT`; `fk_*`/`uq_*`/`idx_*` naming; ordered `000N_*.sql` migrations
(never edit an applied one). Authoritative DDL:
`database/migrations/0001_core_schema.sql`.

---

## API Conventions — source: [`API.md`](../../API.md)

- File-based routing: endpoints are PHP files under `public_html/api/`
  (`<verb>_<resource>.php`).
- State-changing endpoints are `POST`; reject other verbs with `405`.
- Single JSON envelope: `{ success, data | message | errors, meta }`.
- Every endpoint: `auth_check.php` + `permission.php`, `csrf_require()`, role gate,
  transaction for multi-step writes, audit log where relevant.
- Session-based auth (no bearer tokens in v1.x — token layer is a v2.0 target).

---

## Authentication Model

- **Verified current (v1.x):** session login via `auth/login.php`
  (`password_verify` + `session_regenerate_id(true)`), idle timeout enforced in
  `auth_check.php`, RBAC via `permission.php`. See
  [`ARCHITECTURE.md`](../../ARCHITECTURE.md#request-flow),
  [`SECURITY.md`](../../SECURITY.md#session-management).
- **Documented target (v2.x, GATED):** OOP `DMF\Auth` package (`Auth`, `Guard`,
  `Password`, `RememberMe`, `CSRF`, middleware) over `DMF\Core`. Described in
  [`AUTHENTICATION.md`](../../AUTHENTICATION.md), [`ROLE_MODEL.md`](../../ROLE_MODEL.md),
  [`ACL.md`](../../ACL.md).
  > **Verified 2026-07-20:** the OOP class files **exist on disk** —
  > `includes/Core/*` (Bootstrap, Router, Database, Session, Request, Response,
  > Middleware, …) and `includes/Auth/*` (Auth, Guard, ACL, Password, RememberMe,
  > CSRF, `Middleware/*`). **However**, `public_html/` (e.g. `dashboard/index.php`,
  > `auth/login.php`) still wires the **procedural** include chain — the OOP
  > framework is present but **not the active/wired runtime**, and the reference
  > docs (README, ARCHITECTURE, CHANGELOG, ROADMAP) still describe it as a v2.0
  > *target*, not shipped. This is a live conflict — see
  > [Known Gap #1](#known-architectural-gaps). **Do not switch an app to the OOP
  > framework merely because the classes exist**: the app must explicitly declare
  > v2.x intent ([`AI_RULES.md`](./AI_RULES.md#4-version-boundary-rule-v1x-vs-v2x--source-roadmapmd-authenticationmd)).

---

## Security Model — source: [`SECURITY.md`](../../SECURITY.md)

Defense in depth, each layer independently enforced:

`.htaccess` (headers + dotfile block) → login (bcrypt + session regenerate) →
session idle timeout → CSRF verify → RBAC gate → PDO prepared statements → output
escaping. Mapped to OWASP Top 10 in
[`SECURITY.md`](../../SECURITY.md#owasp-top-10-coverage).

---

## Naming Conventions — source: [`CODING_STANDARD.md`](../../CODING_STANDARD.md#naming-conventions)

| Element | Convention |
| ------- | ---------- |
| Function / variable | `camelCase` |
| Global constant | `UPPER_SNAKE_CASE` |
| Route key | dot-namespaced (`admin.users`, `api.document.create`) |
| Class (when used) | `PascalCase` |
| Test method | `test<Behavior>()` |
| DB table/column | `snake_case` |
| File | `snake_case.php` |

Identifiers/code in English; user-facing copy may be localized.

---

## Version Boundary

### Current implementation (v1.x) — AUTHORITATIVE

Procedural PHP · include-chain · PDO · session auth · no runtime framework. This
is what exists and what AI must build against by default.

### Future implementation (v2.x) — TARGET ONLY, GATED

OOP framework `DMF\Core` / `DMF\Auth` (present on disk but dormant), migration
runner, token auth, structured logging, central error handler (see
[`ROADMAP.md`](../../ROADMAP.md#v20--framework-layer)). Per
[`DECISIONS.md` ADR-0003](./DECISIONS.md#adr-0003-framework-boundary--procedural-is-the-runtime-dmfcoredmfauth-activate-only-on-explicit-v2x-migration),
the framework is **active only when ALL hold**: the app targets v2.x · Bootstrap
migrated to `DMF\Core\Bootstrap` · routing via `DMF\Core\Router` · auth via
`DMF\Auth` · the app's context file declares v2.x. Otherwise the app **remains
procedural**. **AI must never auto-migrate procedural → OOP** — migration needs
explicit user approval.

---

## Known Architectural Gaps *(recorded, per workspace governance §2/§11)*

1. **Procedural vs OOP duality — CONFLICT, reported not resolved.**
   README/ARCHITECTURE/CODING_STANDARD/API/DATABASE/TESTING/SECURITY describe the
   **procedural include-chain** and state "no runtime framework";
   AUTHENTICATION/ROLE_MODEL/ACL/BOOTSTRAP/CLASS_DIAGRAM/LIFECYCLE/SESSION_FLOW
   describe an **OOP `DMF\Core`/`DMF\Auth`** framework documented as the **v2.0
   target** (ROADMAP marks it *Planned*; CHANGELOG 1.1.0 does **not** list it).
   **Verified 2026-07-20:** the OOP code **is physically present** on disk
   (`includes/Core/*`, `includes/Auth/*`, `includes/Auth/Middleware/*`), yet
   `VERSION` is `1.1.0` and `public_html/` still uses the procedural chain. So the
   framework exists but is **unwired and undocumented-as-shipped**. Follow the
   **implementation that is actually wired** (procedural) as current truth; treat
   the OOP framework as **gated** (§4 of AI_RULES). The boundary is formally
   resolved in [`DECISIONS.md` ADR-0003](./DECISIONS.md#adr-0003-framework-boundary--procedural-is-the-runtime-dmfcoredmfauth-activate-only-on-explicit-v2x-migration):
   **procedural is the runtime; `DMF\Core`/`DMF\Auth` activate only on explicit
   v2.x migration.** The underlying doc/code conflict remains reported here until a
   future ADR formally ships v2.0 (wire Bootstrap, bump `VERSION`, update CHANGELOG).
2. **PHP version statement differs across docs.** `README`/`CODING_STANDARD` say
   PHP ≥ 8.1; `AUTHENTICATION.md` says "Requires PHP 8.2+". Follow the running
   app's actual constraint; if unresolved, report it — do not assume.
3. **No migration runner yet.** `schema_migrations` tracking is on the roadmap;
   until then, applied migrations are tracked in the deployment log
   ([`DATABASE.md`](../../DATABASE.md#migration-strategy)).

> When any gap is closed in code, update this section and record the decision in
> [`DECISIONS.md`](./DECISIONS.md).

---

## Source-of-Truth Priority (repeat, for AI convenience)

1. Source code → 2. Reference docs → 3. CHANGELOG → 4. ROADMAP → 5. VERSION →
6. Git history → 7. AI docs → 8. Previous conversation.
**Conflict ⇒ report it, follow the code, never guess.**

---

<sub>DMF PHP Framework · Project Context (AI) · summary of the reference docs, not
a replacement · © 2026 Digital Media Foundation</sub>
