# AI_GLOSSARY.md — Framework Vocabulary

> A shared, concise vocabulary so AI assistants and humans use terms the same way.
> Where a term applies differently in **v1.x (procedural, current)** vs **v2.x
> (OOP `DMF\Core`/`DMF\Auth`, target/gated)**, both senses are noted. On any
> conflict, the reference docs and actual source code win
> ([`AI_RULES §0`](./AI_RULES.md#0-decision-hierarchy-what-wins)).

---

| Term | Definition |
| ---- | ---------- |
| **Framework** | The reusable, domain-agnostic foundation this repository provides. v1.x: the procedural include-chain + helpers in `includes/`. v2.x (target): the OOP `DMF\Core` package. Never contains business logic. |
| **Template** | This repository (`dmf-template-php`) used as a GitHub Template to generate new applications. Ships structure, tooling, standards, and the reusable core — no domain logic. See [`README.md`](../../README.md). |
| **Platform Layer** | The shared, reusable tier: this template (and the future `dmf-core`). Governs how apps are built. See workspace `../../../.claude/CLAUDE.md`. |
| **Application Layer** | An individual DMF service generated from the template (e.g. `projects.dmf.ac.th`). Contains **business logic only**; never duplicates framework code. |
| **Business Logic** | Domain-specific rules and features of an application (its entities, workflows, screens). Belongs in the Application Layer, **never** in the template. |
| **Module** | A cohesive feature area within an application (e.g. a `documents` module: its tables, pages, endpoints, routes, tests). A unit of organization, not a framework construct. |
| **Route** | A named link/target. v1.x: a key in the `ROUTES` map in `includes/routes.php`, resolved by `route()`/`routeUrl()`; dot-namespaced (`admin.users`). v2.x (target): a `Router` registration. See [`ARCHITECTURE.md`](../../ARCHITECTURE.md#component-map). |
| **Controller** | The code that handles a request and produces a response. v1.x: **conceptual** — the top of a `public_html/*.php` page or `api/*.php` endpoint (there is no Controller class). v2.x (target): a class dispatched by the `Router`. Use the term carefully in v1.x. |
| **Service** | A unit of reusable domain behavior separate from presentation/persistence. **Conceptual/aspirational** in v1.x (procedural helpers); a first-class object only if an app's v2.x design introduces it. Not a shipped framework class. |
| **Repository (pattern)** | See **Repository Pattern**. Distinct from a *git repository* (a codebase such as this template or an application). |
| **Repository Pattern** | A design pattern that isolates data-access behind an abstraction so callers don't write SQL directly. **Not used in v1.x** — the standard is direct PDO prepared statements via `$conn` ([`DATABASE.md`](../../DATABASE.md#database-conventions)). Only introduce it if an app's v2.x architecture calls for it and it is verified in code. |
| **Request** | The incoming HTTP call. v1.x: read via PHP superglobals (`$_POST`, `$_SERVER`, `php://input`), normalized per [`API.md`](../../API.md#request-format). v2.x (target): a `DMF\Core\Request` object. |
| **Response** | The outgoing reply. v1.x: `header()` + `echo json_encode(...)` using the standard envelope ([`API.md`](../../API.md#json-response-format)). v2.x (target): a `DMF\Core\Response` object. |
| **Session** | Server-side user state across requests. v1.x: PHP session managed in `auth_check.php` (idle timeout) and `auth/login.php` (`session_regenerate_id(true)`). See [`SECURITY.md`](../../SECURITY.md#session-management). |
| **Middleware** | A guard that runs before a handler to allow/deny/redirect a request. **v2.x target only** — `DMF\Auth\Middleware\*` (`Auth`, `Guest`, `Role`, `Permission`) described in [`AUTHENTICATION.md`](../../AUTHENTICATION.md#middleware). In v1.x the equivalent is inline gating (`requireLogin()`, `requireRole()`, `csrf_require()`). **Do not generate middleware classes unless v2.x is verified.** |
| **Role** | A named access level on a user (`super_admin`, `admin`, `user`). v1.x: a string on the `users` row, checked with `hasRole()`/`requireRole()` ([`CODING_STANDARD.md`](../../CODING_STANDARD.md#naming-conventions)). |
| **Permission** | A granular capability (e.g. `document.create`). v1.x: typically expressed through roles (flat model). Fine-grained `role_permissions` is a **v3.0 target** ([`ROADMAP.md`](../../ROADMAP.md#v30--platform-services)); OOP `Permission` is a v2.x target ([`ROLE_MODEL.md`](../../ROLE_MODEL.md)). |
| **ACL** (Access Control List) | The engine that decides whether a subject may perform an action on a resource. **v2.x target** (`DMF\Auth\ACL`, [`ACL.md`](../../ACL.md)). In v1.x, access decisions are role checks via `permission.php`. |
| **Migration** | An ordered, plain `.sql` file that evolves the schema (`database/migrations/000N_*.sql`). Never edit an applied migration — add a new one ([`DATABASE.md`](../../DATABASE.md#migration-strategy)). A `schema_migrations` runner is a v2.0 target. |
| **Seed** | Idempotent data to make an install usable (e.g. the initial admin), in `database/seeds/`. Use `INSERT IGNORE` / `ON DUPLICATE KEY UPDATE`; never seed real credentials ([`DATABASE.md`](../../DATABASE.md#seeds)). |
| **View** | The presentation/HTML output. v1.x: the HTML render block at the bottom of a `public_html/*.php` page, with inline CSS/JS and the shared `navbar.php`; all dynamic output escaped with `htmlspecialchars()`. No template engine. |
| **Validation** | Checking that input is well-formed and semantically valid before use. v1.x: inline checks returning `422` with a per-field `errors` object ([`API.md`](../../API.md#error-handling)). Reusable validation helpers are a v2.0 target. |
| **CSRF** (Cross-Site Request Forgery) | An attack where a forged request rides a user's session. Defended by per-session tokens: `csrf_field()` in forms, `X-CSRF-Token` for AJAX, verified with `csrf_require()`/`csrf_verify()` (`hash_equals`) ([`SECURITY.md`](../../SECURITY.md#csrf)). |
| **Audit Log** | The `audit_logs` table — a generic who-did-what trail (user, action, entity, entity_id, JSON data, ip). Write within the same transaction as the change it records ([`DATABASE.md`](../../DATABASE.md#core-schema), [`API.md`](../../API.md#worked-example)). |
| **Dependency** | An external code requirement. This platform ships **no runtime dependencies**; Composer provides **dev tooling only** (PHPStan, PHPUnit, PHP-CS-Fixer). Adding a runtime dependency is forbidden in v1.x ([`CODING_STANDARD.md`](../../CODING_STANDARD.md#principles)). |

---

> **Adding terms:** applications should extend this glossary with their **domain**
> vocabulary in their own `docs/ai/AI_GLOSSARY.md` (or their `APP_CONTEXT.md`),
> not by editing the framework glossary. Keep framework terms and domain terms
> separate.

---

<sub>DMF PHP Framework · AI Glossary · v1.x current / v2.x gated senses noted ·
© 2026 Digital Media Foundation</sub>
