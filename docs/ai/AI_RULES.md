# AI_RULES.md — Mandatory AI Behavior

> Hard constraints for every AI coding assistant working in `dmf-template-php`
> and in any application generated from it. These are **guardrails, not
> suggestions.** Each rule cites the reference document it derives from; on any
> conflict, the cited document — and the actual source code — outranks this file.

**Audience:** Claude Code, OpenAI Codex, GitHub Copilot, Cursor, and future AI
tools. **Also read:** [`PROJECT_CONTEXT.md`](./PROJECT_CONTEXT.md),
[`DEVELOPMENT_WORKFLOW.md`](./DEVELOPMENT_WORKFLOW.md),
[`AI_CHECKLIST.md`](./AI_CHECKLIST.md).

---

## 0. Decision Hierarchy (what wins)

When two sources disagree, obey the higher one:

1. **Actual source code** on disk (`includes/`, `config/`, `public_html/`, `database/`).
2. Reference docs: [`ARCHITECTURE.md`](../../ARCHITECTURE.md),
   [`CODING_STANDARD.md`](../../CODING_STANDARD.md), [`SECURITY.md`](../../SECURITY.md),
   [`DATABASE.md`](../../DATABASE.md), [`API.md`](../../API.md), [`TESTING.md`](../../TESTING.md).
3. [`CHANGELOG.md`](../../CHANGELOG.md) → [`ROADMAP.md`](../../ROADMAP.md) → [`VERSION`](../../VERSION).
4. Git history.
5. These AI docs (`docs/ai/*`).
6. Previous conversation *(never authoritative)*.

> **Golden rule:** *If documentation and implementation differ, do NOT guess.
> Report the inconsistency and follow the implementation that actually exists.*

---

## 1. Unknown Handling

- If a fact cannot be verified from the sources above, label it explicitly:
  `Unknown`, `Not verified`, `Repository-only evidence`, or
  `Verification incomplete` — and state what is needed to resolve it.
- **Never** invent file names, function names, table columns, routes, or classes.
  Verify they exist before referencing them (Grep/Read the source first).
- Absence of proof is not proof of absence. Do not claim a file/class "does not
  exist" unless you inspected the relevant path.

---

## 2. STOP Conditions (halt and report — do not code)

Stop and ask the human when **any** of these hold:

- Documentation and implementation **conflict**, or a rule here conflicts with the
  actual code.
- The task would require **OOP framework code** (`DMF\Core`/`DMF\Auth`) but the
  app does not declare v2.x **or** the classes are not verified on disk (§4).
- The task requires **business logic inside this template repository** (§5).
- A required fact is `Unknown` and cannot be safely defaulted.
- A change would **break backward compatibility**, alter the public API, or edit
  an **already-applied migration** ([`DATABASE.md`](../../DATABASE.md#migration-strategy)).
- A security control would be weakened or removed (§6).
- A quality gate cannot pass (`composer check`) — see
  [`AI_CHECKLIST.md`](./AI_CHECKLIST.md).

When you STOP: state *what* is blocking, *why*, the *evidence*, and the *options* —
then wait.

---

## 3. Architecture Rules — source: [`ARCHITECTURE.md`](../../ARCHITECTURE.md), [`CLAUDE.md`](../../CLAUDE.md)

**MUST**
- Treat **procedural PHP + include-chain + PDO** as the current, authoritative
  pattern for v1.x.
- Follow the canonical include chain for authenticated pages:
  `auth_check.php` → `permission.php` → `routes.php` → gate → SQL → render →
  `navbar.php` (see [`ARCHITECTURE.md`](../../ARCHITECTURE.md#request-flow)).
- Keep code **above** `public_html/`; only `public_html/` is web-served.
- Use environment configuration via `env()` / `config/config.php`; secrets in
  `.env` only.

**MUST NOT**
- Introduce a **runtime framework** or any runtime Composer dependency. Composer
  is dev-tooling only ([`CODING_STANDARD.md`](../../CODING_STANDARD.md#principles)).
- Place any PHP that must not be web-served inside `public_html/`.
- Re-architect the include chain or invent new bootstrap conventions in v1.x.

---

## 4. Version-Boundary Rule (v1.x vs v2.x) — source: [`ROADMAP.md`](../../ROADMAP.md), [`AUTHENTICATION.md`](../../AUTHENTICATION.md)

**MUST NOT** generate OOP framework code — `DMF\Core`, `DMF\Auth`, classes,
`Middleware`, `Router`, `Guard`, `Bootstrap` — **unless BOTH**:

1. The target application **explicitly declares it targets v2.x** (in its
   [`APP_CONTEXT`](./APP_CONTEXT_TEMPLATE.md)), **and**
2. The required framework classes are **verified to exist** on disk
   (Grep/Read `includes/Core/`, `includes/Auth/`).

> **Verified 2026-07-20:** condition 2 is currently **satisfied** — the class
> files `includes/Core/*` and `includes/Auth/*` (incl. `Middleware/*`) are
> present. **Condition 1 is the real gate.** Class *presence alone is not
> sufficient*: at `VERSION` 1.1.0 the OOP framework is **unwired** — `public_html/`
> uses the procedural chain, and the reference docs (README/ARCHITECTURE/CHANGELOG/
> ROADMAP) still describe it as a v2.0 *target*, not shipped. Do **not** convert an
> app to the OOP framework just because the classes exist. This is a documented
> conflict — see [`PROJECT_CONTEXT.md`](./PROJECT_CONTEXT.md#known-architectural-gaps)
> and [`DECISIONS.md`](./DECISIONS.md) ADR-0001.

Otherwise: generate **procedural include-chain** code. If a task assumes the OOP
framework is the active runtime, **STOP** (§2) and report the doc/implementation
conflict. The OOP documents (`AUTHENTICATION.md`, `ROLE_MODEL.md`, `ACL.md`,
`BOOTSTRAP.md`, `CLASS_DIAGRAM.md`, `LIFECYCLE.md`, `SESSION_FLOW.md`) describe the
**v2.x target**; the **wired current implementation is procedural**.

**The framework boundary is formally resolved in
[`DECISIONS.md` ADR-0003](./DECISIONS.md#adr-0003-framework-boundary--procedural-is-the-runtime-dmfcoredmfauth-activate-only-on-explicit-v2x-migration).**
The framework layer is **active only when ALL** of these hold — otherwise the app
**remains procedural**:

- [ ] The application **explicitly targets Framework v2.x**.
- [ ] **Bootstrap migrated** to `DMF\Core\Bootstrap`.
- [ ] **Routing uses `DMF\Core\Router`**.
- [ ] **Authentication uses `DMF\Auth`**.
- [ ] The app's `PROJECT_CONTEXT.md` / `APP_CONTEXT.md` **declares Framework v2.x**.

> **AI MUST NEVER migrate an application from procedural to OOP automatically.**
> Class presence is not a trigger. Migration **requires explicit user approval**;
> without it, stay procedural and STOP if the task assumes the framework.

---

## 5. Scope / Business-Logic Rules — source: [`README.md`](../../README.md), workspace `../../../.claude/CLAUDE.md`

**MUST NOT** (in this template repository)
- Add application/business logic, domain tables, or domain endpoints. The template
  ships **only reusable core** ([`DATABASE.md`](../../DATABASE.md#platform-standard)).
- Duplicate framework functionality that already exists in `includes/`.

**MUST** (in a generated application)
- Keep the template's reusable core intact; add domain logic on top, in the app's
  own files and numbered migrations.

---

## 6. Security Rules — source: [`SECURITY.md`](../../SECURITY.md)

**MUST**
- Escape **all** output: `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`
  ([`SECURITY.md`](../../SECURITY.md#xss)).
- Protect **every** state-changing POST with `csrf_field()` / `csrf_require()`
  ([`SECURITY.md`](../../SECURITY.md#csrf)).
- Gate privileged actions with `requirePermission()` / `requireRole()`
  ([`SECURITY.md`](../../SECURITY.md#owasp-top-10-coverage)).
- Hash passwords with `password_hash()` / verify with `password_verify()`;
  store only token **hashes** ([`SECURITY.md`](../../SECURITY.md#password-handling)).
- Validate uploads by allow-list + `finfo`, rename on storage, keep private files
  under `storage/` ([`SECURITY.md`](../../SECURITY.md#upload-validation)).
- Constrain redirects to local paths (`/...`, reject `//host`).

**MUST NOT**
- Interpolate user input into SQL, shell, or HTML.
- Commit secrets, or read config from anywhere but `.env` via `env()`.
- Weaken `.htaccess` headers, expose `.env`, or set `APP_DEBUG=true` in prod.
- Log plaintext passwords or leak internal error details to clients
  ([`API.md`](../../API.md#error-handling)).

---

## 7. Coding Rules — source: [`CODING_STANDARD.md`](../../CODING_STANDARD.md)

**MUST**
- Follow PSR-12; `declare(strict_types=1);` after `<?php`; no closing `?>`.
- 4-space indent for PHP, LF, UTF-8, final newline (`.editorconfig`).
- Declare parameter and return types on every function; PHPDoc array shapes so
  PHPStan (level 5) can reason.
- Start every file with a docblock (path + purpose). Comment the *why*.
- Use naming conventions from [`CODING_STANDARD.md`](../../CODING_STANDARD.md#naming-conventions)
  (functions `camelCase`, constants `UPPER_SNAKE_CASE`, DB `snake_case`).

**MUST NOT**
- Add jQuery, bundlers, or a build step; front-end is vanilla JS + inline
  assets + approved CDNs ([`CODING_STANDARD.md`](../../CODING_STANDARD.md#front-end-conventions)).
- Leave commented-out code (Git is history).

---

## 8. Database Rules — source: [`DATABASE.md`](../../DATABASE.md)

**MUST**
- Use **PDO prepared statements with positional `?`** — the only permitted access
  pattern ([`DATABASE.md`](../../DATABASE.md#database-conventions)).
- Reuse the single shared `$conn`; wrap multi-step writes in transactions.
- Follow the naming standard (`fk_*`, `uq_*`, `idx_*`, `id BIGINT UNSIGNED`).
- Add schema changes as **new, sequentially numbered** `.sql` migrations
  (`000N_*.sql`); make seeds idempotent.

**MUST NOT**
- Build SQL by string interpolation, ever.
- Open extra DB connections, use an ORM, or **edit an applied migration** — add a
  new file instead ([`DATABASE.md`](../../DATABASE.md#migration-strategy)).

---

## 9. API Rules — source: [`API.md`](../../API.md)

**MUST**
- Return the standard JSON envelope (`success`, `data`|`message`|`errors`,
  `meta`) ([`API.md`](../../API.md#json-response-format)).
- Reject wrong verbs with `405`; use the status-code table
  ([`API.md`](../../API.md#http-status-codes)).
- Include `auth_check.php` + `permission.php`, verify CSRF, gate by role on every
  state-changing endpoint.

---

## 10. Testing Rules — source: [`TESTING.md`](../../TESTING.md)

**MUST**
- Add/adjust PHPUnit tests for every behavioral change; tests are a **merge gate**.
- Name `<Subject>Test.php`, methods `test<Behavior>()`; prefer `assertSame`.
- Keep the fast unit suite DB-free; isolate DB tests in transactions rolled back
  in `tearDown()` ([`TESTING.md`](../../TESTING.md#database-tests)).
- Ensure `composer check` (lint + analyse + test) is green before proposing merge.

---

## 11. Documentation Rules — source: this doc set + [`CHANGELOG.md`](../../CHANGELOG.md)

**MUST**
- Update [`CHANGELOG.md`](../../CHANGELOG.md) (Keep a Changelog format) for every
  user-visible change; bump [`VERSION`](../../VERSION) per SemVer on release.
- Record significant/hard-to-reverse decisions as an ADR in
  [`DECISIONS.md`](./DECISIONS.md).
- Reference existing docs; **do not duplicate** their content.

**MUST NOT**
- Modify existing reference documentation to resolve a conflict silently — report
  it instead (§0 golden rule).

---

## 12. Review Rules — source: [`DEVELOPMENT_WORKFLOW.md`](./DEVELOPMENT_WORKFLOW.md), [`AI_CHECKLIST.md`](./AI_CHECKLIST.md)

**MUST**
- Explain **Why → Architecture → Trade-offs → Alternatives → Migration** *before*
  producing code (workspace governance §0/§9).
- Self-review against [`AI_CHECKLIST.md`](./AI_CHECKLIST.md) before proposing a PR.
- Use `feature/*` off `develop`, Conventional Commits, one approval + green CI to
  merge ([`README.md`](../../README.md#branch-strategy)).

**MUST NOT**
- Push directly to `main`/`develop`, skip CI, or merge with failing gates.

---

<sub>DMF PHP Framework · AI Rules · references the reference docs as source of
truth · © 2026 Digital Media Foundation</sub>
