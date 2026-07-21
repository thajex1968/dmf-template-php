# AI_CHECKLIST.md — Gate Checklists

> Copy-paste checklists an AI assistant (and its human reviewer) run at each gate.
> Each item cites the authoritative doc. A box is only checked when **verified
> against the actual code**, not assumed. If any item cannot pass, **STOP** and
> report ([`AI_RULES §2`](./AI_RULES.md#2-stop-conditions-halt-and-report--do-not-code)).

---

## Architecture Checklist — [`ARCHITECTURE.md`](../../ARCHITECTURE.md)

- [ ] Uses **procedural include-chain** (v1.x) — or v2.x is confirmed **and**
      `DMF\Core`/`DMF\Auth` verified on disk.
- [ ] Authenticated pages follow the canonical include order.
- [ ] Code that must not be web-served lives **above** `public_html/`.
- [ ] **No runtime framework / no runtime Composer dependency** added.
- [ ] Config via `env()`/`config.php`; secrets only in `.env`.
- [ ] No business logic added to the template repository.
- [ ] Composition/loose coupling; SOLID/DRY/KISS/YAGNI respected.

## Security Checklist — [`SECURITY.md`](../../SECURITY.md)

- [ ] Every state-changing POST verifies CSRF (`csrf_require()`).
- [ ] All output escaped with `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`.
- [ ] SQL via **prepared statements with positional `?`** only.
- [ ] Privileged actions gated (`requireRole()`/`requirePermission()`).
- [ ] Uploads: allow-list + `finfo` + rename + stored outside executable path.
- [ ] Passwords hashed (`password_hash`); only token **hashes** stored.
- [ ] Redirects constrained to local paths; `//host` rejected.
- [ ] Secrets never committed/logged; `APP_DEBUG=false` in prod.
- [ ] No internal error details leaked to clients.
- [ ] No existing security control weakened or removed.

## Database Checklist — [`DATABASE.md`](../../DATABASE.md)

- [ ] New schema change = **new numbered `.sql`** (no edit to applied migrations).
- [ ] InnoDB, `utf8mb4_unicode_ci`; `id BIGINT UNSIGNED AUTO_INCREMENT`.
- [ ] `fk_*`/`uq_*`/`idx_*` naming; every FK indexed; `ON DELETE` chosen deliberately.
- [ ] Multi-step writes wrapped in a transaction.
- [ ] Seeds idempotent; no real credentials seeded.
- [ ] Core tables (`users`, `password_resets`, `login_attempts`, `audit_logs`)
      untouched destructively.
- [ ] Apply order documented (no migration runner yet).

## Performance Checklist — [`DATABASE.md#indexes`](../../DATABASE.md#indexes)

- [ ] Baseline measured; improvement evidenced (not assumed).
- [ ] Hot `WHERE`/`JOIN`/`ORDER BY` columns indexed; no N+1 queries.
- [ ] Only needed columns selected; write-heavy tables not over-indexed.
- [ ] No security/readability sacrificed for speed.
- [ ] Behavior unchanged (tests still green).

## Documentation Checklist — [`CHANGELOG.md`](../../CHANGELOG.md) + this doc set

- [ ] `CHANGELOG.md` updated (Keep a Changelog); `VERSION` bumped on release.
- [ ] Cross-references added; **no content duplicated** across docs.
- [ ] Any closed Known Gap updated in [`PROJECT_CONTEXT.md`](./PROJECT_CONTEXT.md).
- [ ] Significant decisions recorded in [`DECISIONS.md`](./DECISIONS.md).
- [ ] Conflicts **reported**, not silently edited away.

## Testing Checklist — [`TESTING.md`](../../TESTING.md)

- [ ] Unit tests added/updated; fast, deterministic, DB-free.
- [ ] DB/integration tests isolated (transaction + rollback), `@group integration`.
- [ ] Endpoint tests assert the standard JSON envelope.
- [ ] Regression test exists for any fixed bug (fails before, passes after).
- [ ] `composer test` green.

## Deployment Checklist — [`INSTALL.md`](../../INSTALL.md), [`ARCHITECTURE.md#deployment-diagram`](../../ARCHITECTURE.md#deployment-diagram)

- [ ] Document root = `public_html/`; `.env` outside it.
- [ ] `storage/` writable; nothing else web-writable.
- [ ] HTTPS enforced; HSTS enabled once TLS is in place.
- [ ] Migrations applied in order; DB user least-privilege.
- [ ] `.env` and uploads included in the backup plan ([`DATABASE.md#backup-strategy`](../../DATABASE.md#backup-strategy)).

## Code Review Checklist — [`CODING_STANDARD.md`](../../CODING_STANDARD.md)

- [ ] PSR-12; `declare(strict_types=1);`; no closing `?>`.
- [ ] Param/return types + PHPDoc array shapes; PHPStan level 5 clean.
- [ ] File docblock present; comments explain *why*; no dead/commented code.
- [ ] Naming conventions followed; functions small and single-purpose.
- [ ] Every referenced symbol/route/table **verified to exist**.

## Merge Readiness Checklist

- [ ] Architecture + Security + Database + Testing + Code Review checklists pass.
- [ ] `composer check` green; CI green.
- [ ] Conventional Commits; branch `feature/*`/`bugfix/*` off `develop`.
- [ ] ≥1 approval; targets `develop` (not `main`).
- [ ] No STOP condition outstanding.

## Release Readiness Checklist — [`ROADMAP.md#release-process`](../../ROADMAP.md#release-process)

- [ ] `develop` green; `release/*` branch cut.
- [ ] `VERSION` bumped; `CHANGELOG.md` `Unreleased` → `x.y.z` with date.
- [ ] Docs match implementation; Known Gaps reviewed.
- [ ] Breaking changes + migration notes listed.
- [ ] Merge to `main`, signed tag `vX.Y.Z`, then `main` → `develop`.
- [ ] Human sign-off obtained (AI does not tag/push).

---

<sub>DMF PHP Framework · AI Checklists · verify against code, not assumption ·
© 2026 Digital Media Foundation</sub>
