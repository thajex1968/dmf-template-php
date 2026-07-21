# PROMPTS.md — Reusable Prompt Templates

> Vetted, task-shaped prompts that bake in the DMF rules so you get compliant
> output without re-explaining constraints each time. Fill the `<…>` placeholders.
> Works with Claude Code, OpenAI Codex, GitHub Copilot, Cursor, and future tools.

**Before using any prompt, the AI must have loaded:** [`CLAUDE.md`](../../CLAUDE.md),
[`AI_RULES.md`](./AI_RULES.md), [`PROJECT_CONTEXT.md`](./PROJECT_CONTEXT.md), and
the app's `APP_CONTEXT.md` (from [`APP_CONTEXT_TEMPLATE.md`](./APP_CONTEXT_TEMPLATE.md)).

## Prompting Principles

1. **State the target version** (v1.x procedural / v2.x OOP). Default is v1.x.
2. **Cite the governing doc** for the task (e.g. API.md for endpoints).
3. **Demand Why → Architecture → Trade-offs → Alternatives → Migration *before*
   code** (workspace governance §0/§9).
4. **Require verification** — "verify the symbol/table/route exists before using
   it; if not, STOP and report."
5. **End every task at the gate** — `composer check` green (see
   [`AI_CHECKLIST.md`](./AI_CHECKLIST.md)).

Each template below has: **Purpose · When to use · Prompt · Expected Output ·
Acceptance Criteria.**

---

## 1. Research

- **Purpose:** Gather verified facts about the codebase/topic before deciding.
- **When to use:** Start of any non-trivial task; whenever a fact is `Unknown`.

**Prompt**
```
Research <topic/area> in this repository. Do NOT write production code.
Sources in priority order: source code → reference docs → CHANGELOG → ROADMAP →
VERSION → git history (per AI_RULES §0).
Report: (1) what exists and where (file:line), (2) what is documented but NOT
found in code, (3) any doc/implementation conflicts, (4) Unknowns and what is
needed to resolve them. Cite every claim with a path.
```
- **Expected Output:** A findings memo with citations; explicit `Unknown` labels;
  a conflict list — no code.
- **Acceptance Criteria:** Every claim cited to a path/line; no invented symbols;
  conflicts reported, not resolved.

---

## 2. Architecture Review

- **Purpose:** Assess a proposed or existing design against platform principles.
- **When to use:** Before large features; when reviewing a structural change.

**Prompt**
```
Review the architecture of <feature/change> against ARCHITECTURE.md and the
principles in CODING_STANDARD.md (SOLID/DRY/KISS/YAGNI, composition, framework
independence). Confirm it uses the v1.x procedural include-chain (no runtime
framework) unless the app declares v2.x AND DMF\Core/DMF\Auth are verified present.
Output: Problem, Current State, Evidence, Options, Trade-offs, Recommendation,
Migration, Backward Compatibility, Risks. No code unless I ask.
```
- **Expected Output:** Structured architecture analysis; a clear recommendation.
- **Acceptance Criteria:** Honors the version boundary; cites ARCHITECTURE.md;
  lists trade-offs and a migration/back-compat note.

---

## 3. Feature Design

- **Purpose:** Turn a requirement into a concrete, reviewable design.
- **When to use:** After research, before implementation.

**Prompt**
```
Design <feature> for <app>. Target version: <v1.x procedural | v2.x>.
Produce: data model changes (tables + migration filenames per DATABASE.md),
affected pages/endpoints (API.md envelope), routes to add to includes/routes.php,
permission/role gates (SECURITY.md), and test plan (TESTING.md).
Explain Why/Architecture/Trade-offs/Alternatives first. Stop for my approval
before writing code.
```
- **Expected Output:** A design doc (model, routes, endpoints, gates, tests) and a
  request for approval.
- **Acceptance Criteria:** Maps cleanly to existing conventions; no business logic
  added to the template repo; approval gate respected.

---

## 4. Feature Implementation

- **Purpose:** Implement an approved design.
- **When to use:** Only after a Feature Design is approved.

**Prompt**
```
Implement the approved design for <feature> in <app>, v1.x procedural.
Follow the canonical include chain (ARCHITECTURE.md), PSR-12 + strict_types
(CODING_STANDARD.md), prepared statements only (DATABASE.md), CSRF + escaping +
role gates (SECURITY.md). Add/adjust PHPUnit tests (TESTING.md). Update CHANGELOG.
Verify every referenced symbol/route/table exists first; if any is missing, STOP.
Finish only when `composer check` is green.
```
- **Expected Output:** Code + tests + CHANGELOG entry; passing local gate.
- **Acceptance Criteria:** [`AI_CHECKLIST.md`](./AI_CHECKLIST.md) Merge Readiness
  passes; no new runtime dependency; conventions followed.

---

## 5. CRUD Module

- **Purpose:** Scaffold create/read/update/delete for a domain resource.
- **When to use:** New domain entity needing standard management pages/endpoints.

**Prompt**
```
Create a CRUD module for <resource> in <app>, v1.x procedural.
Include: migration 000N_add_<resource>_table.sql (DATABASE.md naming/indexes/FKs),
list + form pages under public_html/<resource>/ using the include chain, POST
API endpoints under public_html/api/ (create/update/delete) returning the standard
JSON envelope (API.md), routes in includes/routes.php (dot-namespaced), role gates,
CSRF on every POST, htmlspecialchars on output, and audit_logs writes. Add tests.
Do NOT put this in the template repo — it is application code.
```
- **Expected Output:** Migration, pages, endpoints, routes, tests for the resource.
- **Acceptance Criteria:** All writes transactional + audited; allow-listed columns
  for any dynamic SQL; gate green.

---

## 6. Authentication

- **Purpose:** Work on login/session/password features.
- **When to use:** Auth changes within the platform baseline.

**Prompt**
```
Task: <auth change> for <app>.
v1.x DEFAULT: use the procedural session model — auth/login.php + auth_check.php,
password_hash/verify, session_regenerate_id(true), idle timeout, uniform login
errors (SECURITY.md#password-handling, #session-management).
Do NOT generate DMF\Auth/DMF\Core OOP code unless <app> declares v2.x AND those
classes are verified on disk — otherwise STOP and report the gap (AI_RULES §4).
Add tests; keep login errors generic (no user enumeration).
```
- **Expected Output:** Procedural auth changes (or a STOP report if v2.x assumed
  but unverified).
- **Acceptance Criteria:** No plaintext secrets/logging; session regenerated on
  privilege change; version boundary respected.

---

## 7. Authorization

- **Purpose:** Add or change access control.
- **When to use:** Gating pages/endpoints by role (or permission in v2.x).

**Prompt**
```
Add authorization for <action/resource> in <app>.
v1.x: gate with requireRole()/requirePermission()/hasRole() from
includes/permission.php (ARCHITECTURE.md, SECURITY.md#owasp-top-10-coverage).
Fine-grained permissions/ACL (ROLE_MODEL.md, ACL.md) are v2.x targets — only use
them if the app is v2.x and the classes exist; else STOP.
Enforce on both the page and the underlying endpoint. Add a test proving denial
returns 403 (JSON for XHR).
```
- **Expected Output:** Role/permission gates on page + endpoint; a denial test.
- **Acceptance Criteria:** Deny-by-default; both layers gated; correct 401 vs 403.

---

## 8. REST API

- **Purpose:** Add or modify a JSON endpoint.
- **When to use:** Any `public_html/api/*` work.

**Prompt**
```
Create/modify endpoint <verb>_<resource>.php in <app> per API.md.
Requirements: POST-only (405 otherwise), include auth_check.php + permission.php +
csrf.php, csrf_require(), role gate, normalize form/JSON input, validate (422 with
errors object), prepared statements in a transaction, audit_logs write, standard
JSON envelope, generic 500 on exception (no internal leakage). Add an endpoint test.
```
- **Expected Output:** Endpoint file matching API.md's worked example + test.
- **Acceptance Criteria:** Envelope exact; status codes per API.md table; no SQL
  interpolation; no leaked errors.

---

## 9. Database Migration

- **Purpose:** Evolve the schema safely.
- **When to use:** Any DDL/seed change.

**Prompt**
```
Write migration 000N_<change>.sql for <app> per DATABASE.md.
Rules: new file only (never edit an applied migration), sequential zero-padded
prefix, InnoDB/utf8mb4, id BIGINT UNSIGNED AUTO_INCREMENT, fk_/uq_/idx_ naming,
index every FK, chosen ON DELETE semantics, CREATE TABLE IF NOT EXISTS where safe.
Provide matching idempotent seed if needed. Note there is no migration runner yet —
document apply order. Do NOT touch core schema tables destructively.
```
- **Expected Output:** One new numbered `.sql` (and seed if needed) + apply note.
- **Acceptance Criteria:** No edits to applied migrations; naming/indexes correct;
  reversible/back-compatible or flagged.

---

## 10. Security Review

- **Purpose:** Audit a change against the security baseline.
- **When to use:** Before merging anything touching auth, input, output, uploads,
  or SQL.

**Prompt**
```
Perform a security review of <change> against SECURITY.md and the OWASP Top 10
table. Check: CSRF on state-changing POSTs, htmlspecialchars output escaping,
prepared statements only, upload allow-list + finfo + rename, session hardening,
secrets only via env(), local-only redirects, no internal error leakage.
Report findings as: issue, severity, location (file:line), fix. Do not weaken any
existing control. If a doc and the code disagree, report it.
```
- **Expected Output:** A findings list with severities, locations, and fixes.
- **Acceptance Criteria:** Every SECURITY.md control checked; findings cite lines;
  no control regressed.

---

## 11. Performance Optimization

- **Purpose:** Improve latency/throughput without harming clarity or safety.
- **When to use:** A measured hot path or slow query.

**Prompt**
```
Optimize <path/query> in <app>. First measure and state the baseline + evidence.
Prefer: correct indexes (DATABASE.md#indexes), fewer queries (avoid N+1), selective
columns, appropriate transactions. Do NOT sacrifice prepared statements, escaping,
or readability. Show before/after and the expected impact. Add/adjust tests to lock
behavior.
```
- **Expected Output:** A measured change with before/after and rationale.
- **Acceptance Criteria:** Behavior unchanged (tests green); security intact; the
  win is evidenced, not assumed.

---

## 12. Bug Fixing

- **Purpose:** Diagnose and fix a defect at root cause.
- **When to use:** Reported incorrect behavior/error.

**Prompt**
```
Diagnose and fix <bug> in <app>. Reproduce first; identify root cause with
evidence (file:line, logs). Propose the minimal correct fix and explain why the
bug occurred. Add a regression test that fails before and passes after. Do not mask
symptoms. If the root cause is a doc/implementation conflict, report it.
```
- **Expected Output:** Root-cause explanation, minimal fix, regression test.
- **Acceptance Criteria:** Failing→passing test proves the fix; no unrelated
  changes; gate green.

---

## 13. Refactoring

- **Purpose:** Improve structure without changing behavior.
- **When to use:** Duplication, unclear naming, oversized functions.

**Prompt**
```
Refactor <target> in <app> for clarity and reuse (DRY/KISS, CODING_STANDARD.md).
Behavior must not change — existing tests must stay green; add characterization
tests first if coverage is thin. Keep it procedural v1.x; do not introduce a
framework. Show a small, reviewable diff and explain each move.
```
- **Expected Output:** Behavior-preserving diff + rationale.
- **Acceptance Criteria:** Tests unchanged and green; no new dependency; smaller
  cognitive load, not just moved code.

---

## 14. Unit Testing

- **Purpose:** Add fast, isolated tests for helpers/logic.
- **When to use:** New/changed pure functions or branching logic.

**Prompt**
```
Write PHPUnit unit tests for <function/helper> per TESTING.md. Keep them DB-free
and deterministic. Name <Subject>Test.php with test<Behavior>() methods; use
assertSame and data providers for variants. Cover edge cases and one failure case.
Run via `composer test`.
```
- **Expected Output:** A `*Test.php` file with focused cases.
- **Acceptance Criteria:** Fast, deterministic, no I/O; meaningful assertions;
  green under `composer test`.

---

## 15. Integration Testing

- **Purpose:** Test behavior across boundaries (DB/endpoints).
- **When to use:** Logic that only emerges with a DB or over HTTP.

**Prompt**
```
Write integration tests for <flow> per TESTING.md#database-tests. Use a dedicated
test DB via .env.testing; wrap each test in a transaction and rollBack() in
tearDown(). Group as @group integration so the fast suite stays quick. Assert the
standard JSON envelope for endpoint tests.
```
- **Expected Output:** Isolated integration tests separated from the unit suite.
- **Acceptance Criteria:** No production DB; repeatable via rollback; kept out of
  the fast default suite.

---

## 16. Documentation Update

- **Purpose:** Keep docs truthful and in sync.
- **When to use:** After any behavioral or structural change.

**Prompt**
```
Update documentation for <change>. Update CHANGELOG.md (Keep a Changelog) and, if
released, VERSION (SemVer). Add/adjust cross-references. Do NOT duplicate content
across docs — link instead. If this change closes a Known Gap in
PROJECT_CONTEXT.md, update that section and add an ADR in DECISIONS.md. Do not edit
existing reference docs to hide a conflict — report conflicts.
```
- **Expected Output:** CHANGELOG entry, targeted doc edits, cross-refs, optional ADR.
- **Acceptance Criteria:** No duplication; links not copies; conflicts reported,
  not silently patched.

---

## 17. Release Preparation

- **Purpose:** Produce a clean, verifiable release.
- **When to use:** Cutting a `release/*` branch.

**Prompt**
```
Prepare release <x.y.z> per ROADMAP.md#release-process and README branch strategy.
Steps: ensure develop is green (composer check), bump VERSION, finalize CHANGELOG
(move Unreleased → x.y.z with date), verify docs match implementation, list any
breaking changes + migration notes. Produce the release checklist status from
AI_CHECKLIST.md#release-readiness. Do not tag/push — report readiness for a human.
```
- **Expected Output:** A release-readiness report with checklist status.
- **Acceptance Criteria:** VERSION + CHANGELOG consistent; gates green; breaking
  changes and migrations called out; human sign-off requested.

---

<sub>DMF PHP Framework · Prompt Library · operationalizes the reference docs ·
© 2026 Digital Media Foundation</sub>
