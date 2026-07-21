# DEVELOPMENT_WORKFLOW.md — The AI Development Loop

> The end-to-end process every AI assistant follows in this repository and in any
> application generated from it. It binds together the Git workflow
> ([`README.md`](../../README.md#git-workflow)), the coding gate
> ([`CODING_STANDARD.md`](../../CODING_STANDARD.md#local-verification)), and the
> testing gate ([`TESTING.md`](../../TESTING.md)) into one agent-facing procedure.
> It does not restate those docs — it sequences them.

**Companion docs:** [`AI_RULES.md`](./AI_RULES.md) (constraints),
[`PROMPTS.md`](./PROMPTS.md) (per-stage prompts),
[`AI_CHECKLIST.md`](./AI_CHECKLIST.md) (per-stage gates).

---

## The Loop

```
        ┌─────────────┐
        │  Research    │  gather verified facts (PROMPTS §1)
        └──────┬──────┘
               ▼
        ┌─────────────┐
        │  Analysis    │  identify repo/version/layer; find gaps & conflicts
        └──────┬──────┘
               ▼
        ┌─────────────┐
        │ Architecture │  fit to ARCHITECTURE.md; confirm v1.x vs v2.x boundary
        └──────┬──────┘
               ▼
        ┌─────────────┐
        │  Design      │  data model, routes, endpoints, gates, test plan
        └──────┬──────┘
               ▼
        ┌─────────────┐
        │  Approval    │  ◀── HUMAN GATE — no code before approval
        └──────┬──────┘
               ▼
        ┌─────────────┐
        │Implementation│  procedural v1.x by default; follow the include chain
        └──────┬──────┘
               ▼
        ┌─────────────┐
        │  Review      │  self-review vs AI_CHECKLIST (security, DB, code)
        └──────┬──────┘
               ▼
        ┌─────────────┐
        │  Testing     │  composer check green across 8.1/8.2/8.3 intent
        └──────┬──────┘
               ▼
        ┌─────────────┐
        │Documentation │  CHANGELOG, cross-refs, ADR if significant
        └──────┬──────┘
               ▼
        ┌─────────────┐
        │  Commit      │  Conventional Commits on feature/* off develop
        └──────┬──────┘
               ▼
        ┌─────────────┐
        │ Pull Request │  into develop; CI green + 1 approval
        └──────┬──────┘
               ▼
        ┌─────────────┐
        │  Release     │  release/* → bump VERSION + CHANGELOG → tag on main
        └─────────────┘
```

---

## Stage Detail

| Stage | Goal | Primary docs | Prompt |
| ----- | ---- | ------------ | ------ |
| **Research** | Establish verified facts; label Unknowns | source code, all reference docs | [`PROMPTS §1`](./PROMPTS.md#1-research) |
| **Analysis** | Repo identity, version, layer; list conflicts/gaps | [`PROJECT_CONTEXT.md`](./PROJECT_CONTEXT.md), app `APP_CONTEXT.md` | — |
| **Architecture** | Confirm design fits principles + version boundary | [`ARCHITECTURE.md`](../../ARCHITECTURE.md), [`AI_RULES §3–4`](./AI_RULES.md#3-architecture-rules--source-architecturemd-claudemd) | [`PROMPTS §2`](./PROMPTS.md#2-architecture-review) |
| **Design** | Concrete, reviewable plan | [`DATABASE.md`](../../DATABASE.md), [`API.md`](../../API.md), [`SECURITY.md`](../../SECURITY.md) | [`PROMPTS §3`](./PROMPTS.md#3-feature-design) |
| **Approval** | Human signs off on the design | governance §0 (Why before code) | — |
| **Implementation** | Build to spec, procedural v1.x default | [`CODING_STANDARD.md`](../../CODING_STANDARD.md) | [`PROMPTS §4–9`](./PROMPTS.md#4-feature-implementation) |
| **Review** | Self-review against gates | [`AI_CHECKLIST.md`](./AI_CHECKLIST.md) | [`PROMPTS §10`](./PROMPTS.md#10-security-review) |
| **Testing** | Prove behavior; `composer check` green | [`TESTING.md`](../../TESTING.md) | [`PROMPTS §14–15`](./PROMPTS.md#14-unit-testing) |
| **Documentation** | Keep docs truthful, no duplication | [`CHANGELOG.md`](../../CHANGELOG.md), [`DECISIONS.md`](./DECISIONS.md) | [`PROMPTS §16`](./PROMPTS.md#16-documentation-update) |
| **Commit** | Conventional Commits, correct branch | [`README.md`](../../README.md#branch-strategy) | — |
| **Pull Request** | CI green + review into `develop` | [`README.md`](../../README.md#git-workflow) | — |
| **Release** | Versioned, tagged promotion to `main` | [`ROADMAP.md`](../../ROADMAP.md#release-process) | [`PROMPTS §17`](./PROMPTS.md#17-release-preparation) |

---

## STOP Conditions

Halt the loop and report to the human whenever (see
[`AI_RULES §2`](./AI_RULES.md#2-stop-conditions-halt-and-report--do-not-code)):

- Documentation and implementation **conflict**, or code contradicts a rule.
- The task needs **OOP `DMF\Core`/`DMF\Auth`** but the app has not satisfied the
  **ADR-0003 activation rule** (targets v2.x · Bootstrap migrated · `DMF\Core\Router`
  · `DMF\Auth` · context declares v2.x). The framework code exists on disk but is
  dormant; **never auto-migrate procedural → OOP** — it requires explicit user
  approval. See [`DECISIONS.md` ADR-0003](./DECISIONS.md#adr-0003-framework-boundary--procedural-is-the-runtime-dmfcoredmfauth-activate-only-on-explicit-v2x-migration).
- The task requires **business logic in the template repository**.
- A required fact is **`Unknown`** and cannot be safely defaulted.
- A change would **break backward compatibility**, alter the public API, or edit
  an **applied migration**.
- A **security control** would be weakened or removed.
- A **quality gate cannot pass** (`composer check`).
- You reach the **Approval** stage — always stop for human sign-off before code.

When stopping, report: *what* blocks, *why*, the *evidence* (paths/lines), and the
*options*. Never guess past a STOP.

---

## Definition of Done

A change is **Done** only when **all** hold:

- [ ] Built against the **verified current implementation** (procedural v1.x unless
      v2.x is confirmed + classes verified).
- [ ] `composer check` (lint + PHPStan level 5 + PHPUnit) is **green**.
- [ ] Security controls intact and applied (CSRF, escaping, prepared statements,
      role gates, secrets in `.env`) — [`SECURITY.md`](../../SECURITY.md).
- [ ] Tests added/updated; a regression test exists for any fixed bug.
- [ ] No runtime dependency added; no business logic added to the template repo.
- [ ] Naming/format conventions followed ([`CODING_STANDARD.md`](../../CODING_STANDARD.md)).
- [ ] Docs updated where behavior changed; **no duplication**, conflicts reported.
- [ ] [`CHANGELOG.md`](../../CHANGELOG.md) updated; ADR added if the decision is
      significant ([`DECISIONS.md`](./DECISIONS.md)).
- [ ] Conventional Commit(s) on a correct `feature/*`/`bugfix/*` branch off
      `develop`; PR opened; CI green; ≥1 approval.
- [ ] Every referenced symbol/route/table/class **verified to exist**.

> A change that cannot check every box is **not** Done — report the gap instead of
> lowering the bar.

---

<sub>DMF PHP Framework · AI Development Workflow · sequences the reference docs ·
© 2026 Digital Media Foundation</sub>
