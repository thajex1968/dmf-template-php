# DECISIONS.md — Architecture Decision Records (ADRs)

> A lightweight ADR log for the AI development context. Record every significant,
> hard-to-reverse decision here so future AI runs and humans share the same
> reasoning. ADRs are **append-only**: once `Accepted`, do not rewrite — supersede
> with a new ADR.
>
> This complements, and does not replace, the platform-level ADRs under the
> workspace governance directory (`../../../.claude/adr/`). Platform-wide decisions
> belong there; repository/AI-workflow decisions belong here. On any conflict,
> the platform ADRs and the reference docs outrank this file.

---

## ADR Template

Copy this block for each new decision. Number sequentially (`ADR-0001`, …).

```markdown
## ADR-NNNN: <short title>

- **Status:** Proposed | Accepted | Superseded by ADR-XXXX | Deprecated
- **Date:** YYYY-MM-DD
- **Author:** <name or AI + supervising human>

### Context
What problem/force prompts this decision? What is the current state and evidence
(cite file:line / doc)? What constraints apply?

### Decision
The choice made, stated in the active voice ("We will …").

### Alternatives
Options considered and why they were not chosen (one short paragraph each).

### Consequences
Positive and negative results, including trade-offs, migration impact, backward
compatibility, and follow-up work.
```

**Field guide**

| Field | Meaning |
| ----- | ------- |
| **Status** | Lifecycle: `Proposed` → `Accepted` → optionally `Superseded`/`Deprecated`. |
| **Date** | Decision date (absolute, `YYYY-MM-DD`). |
| **Author** | Who decided; for AI-driven decisions, name the AI **and** the approving human. |
| **Context** | The forces and evidence that make a decision necessary. |
| **Decision** | The single, clear choice. |
| **Alternatives** | What else was weighed, and why rejected. |
| **Consequences** | What becomes easier/harder; migration and back-compat notes. |

---

## Example ADRs

> Illustrative, pre-filled examples showing the expected depth. They also record
> two real decisions embedded in this documentation set.

## ADR-0001: Default AI-generated code to procedural v1.x; gate the OOP framework

- **Status:** Superseded by [ADR-0003](#adr-0003-framework-boundary--procedural-is-the-runtime-dmfcoredmfauth-activate-only-on-explicit-v2x-migration)
- **Date:** 2026-07-20
- **Author:** Chief Software Architect (AI-assisted), approved by DMF maintainer

### Context
The repository documents **two architectures**: the procedural include-chain
(README, [`ARCHITECTURE.md`](../../ARCHITECTURE.md), [`CODING_STANDARD.md`](../../CODING_STANDARD.md))
and an OOP framework `DMF\Core`/`DMF\Auth` ([`AUTHENTICATION.md`](../../AUTHENTICATION.md),
`ROLE_MODEL.md`, `ACL.md`, `BOOTSTRAP.md`). The reference docs frame the OOP layer
as the **v2.0 target** ([`ROADMAP.md`](../../ROADMAP.md#v20--framework-layer)); the
CHANGELOG does not list it as shipped in 1.1.0.
**Verified 2026-07-20:** the OOP class files **do exist on disk**
(`includes/Core/*`, `includes/Auth/*`, `includes/Auth/Middleware/*`), **but** at
`VERSION` 1.1.0 they are **unwired** — `public_html/` (`dashboard/index.php`,
`auth/login.php`) uses the procedural include chain. So documentation and
implementation **conflict**: docs say "no runtime framework / v2.0 target", while
a complete framework sits in `includes/`. Without a rule, an AI assistant may
generate OOP code into an app whose wired runtime is procedural (or vice-versa),
producing non-functional output.

### Decision
AI assistants will generate **procedural, include-chain v1.x code by default**
(the wired runtime). OOP framework code is **gated**: permitted only when the
target application explicitly declares it targets v2.x **and** the required
`DMF\Core`/`DMF\Auth` classes are verified present on disk. Class presence **alone
does not** justify using the framework — the app must declare v2.x intent.
Otherwise the AI must STOP and report the conflict for a human to resolve.

### Alternatives
- *Treat OOP as primary now* — rejected: the classes are unverified; violates the
  source-of-truth rule (follow the implementation that exists).
- *Forbid OOP entirely* — rejected: it is the accepted v2.0 target; a blanket ban
  would block legitimate v2.x work once the framework lands.

### Consequences
- (+) AI output matches the running architecture; no phantom-class code.
- (+) Clean migration path: flip an app to v2.x and verify classes to unlock OOP.
- (−) AI must actively verify version/class presence each task (added step,
  encoded in [`AI_RULES §4`](./AI_RULES.md#4-version-boundary-rule-v1x-vs-v2x--source-roadmapmd-authenticationmd)).
- Recorded as a Known Gap in [`PROJECT_CONTEXT.md`](./PROJECT_CONTEXT.md#known-architectural-gaps).

---

## ADR-0002: Place the AI Development Guide at repo root + `docs/ai/`

- **Status:** Accepted
- **Date:** 2026-07-20
- **Author:** Chief Software Architect (AI-assisted), approved by DMF maintainer

### Context
The template is copied into every DMF PHP application, so the AI guide must
propagate automatically and be discoverable by multiple tools (Claude Code auto-
loads root `CLAUDE.md`; other tools read repo docs). The reference docs already
occupy the repo root and `docs/`.

### Decision
Keep a **thin `CLAUDE.md` at the repository root** (identity, current architecture,
source-of-truth order, links) and place the detailed AI documents under
**`docs/ai/`** (`AI_RULES`, `PROJECT_CONTEXT`, `APP_CONTEXT_TEMPLATE`, `PROMPTS`,
`DEVELOPMENT_WORKFLOW`, `AI_CHECKLIST`, `DECISIONS`, `AI_GLOSSARY`).

### Alternatives
- *All files at repo root* — rejected: clutters the root and mixes AI docs with
  reference docs.
- *Everything under `docs/ai/` including `CLAUDE.md`* — rejected: reduces auto-
  discovery, since Claude Code auto-loads a **root** `CLAUDE.md`.

### Consequences
- (+) Clean root; AI docs grouped and inherited by every generated app.
- (+) Root `CLAUDE.md` stays cheap to auto-load and points into `docs/ai/`.
- (−) Two locations to keep cross-linked (mitigated by explicit links).

---

## ADR-0003: Framework boundary — Procedural is the runtime; `DMF\Core`/`DMF\Auth` activate only on explicit v2.x migration

- **Status:** Accepted — **formalizes and supersedes the interim rule in ADR-0001**
- **Date:** 2026-07-20
- **Author:** Chief Software Architect (AI-assisted), approved by DMF maintainer

### Context
The repository contains **two architectures at once** (verified 2026-07-20):

- The **procedural include-chain** (`includes/env.php`, `auth_check.php`,
  `permission.php`, `routes.php`, `csrf.php`, `navbar.php`, `mailer.php`) — this is
  what `public_html/` actually wires (`dashboard/index.php`, `auth/login.php`) and
  what every existing generated application runs.
- A complete **OOP framework** on disk — `includes/Core/*` (`Bootstrap`, `Router`,
  `Database`, `Session`, `Request`, `Response`, `Middleware`, …) and
  `includes/Auth/*` (`Auth`, `Guard`, `ACL`, `Password`, `RememberMe`, `CSRF`,
  `Middleware/*`).

`VERSION` is `1.1.0`; README/ARCHITECTURE/CODING_STANDARD state "no runtime
framework"; CHANGELOG 1.1.0 does not list the framework; ROADMAP frames it as the
**v2.0 target**. The framework code is therefore **present but unwired**, and the
documentation conflicts with the on-disk reality. This ADR resolves the boundary
so humans and AI assistants share one unambiguous rule. It does **not** change any
PHP or the running runtime — it is a governance decision only.

### Decision

**1. Current Runtime.**
The current production runtime is **Procedural PHP**. Every application generated
from this template **must continue using the procedural include-chain**.
**Backward compatibility is mandatory** — no change may break existing procedural
applications.

**2. Framework Layer.**
The `DMF\Core` and `DMF\Auth` packages **already exist** in the repository and
represent the **next-generation framework layer (v2.x)**. **Their mere presence
does not make them the active runtime.** They are dormant until explicitly
activated per the rule below.

**3. Activation Rule.**
The framework layer is considered **active** for an application only when **ALL**
of the following are true:

- [ ] The application **explicitly targets Framework v2.x**.
- [ ] **Bootstrap has been migrated** (the app boots via `DMF\Core\Bootstrap`).
- [ ] **Routing uses `DMF\Core\Router`**.
- [ ] **Authentication uses `DMF\Auth`**.
- [ ] **`PROJECT_CONTEXT.md` (or the app's `APP_CONTEXT.md`) declares Framework
      v2.x**.

If **any** condition is false, the application **remains Procedural**, and all AI
work targets the procedural include-chain.

**4. AI Behavior.**
AI assistants **must never migrate an application from Procedural to OOP
automatically.** Detecting that `DMF\Core`/`DMF\Auth` exist is **not** a trigger to
use them. **Migration requires explicit user approval**; absent it, the AI stays
procedural and, if a task assumes the framework, **STOPs and reports**.

**5. Documentation.**
This ADR is the authoritative record of the boundary. It is referenced from
[`CLAUDE.md`](../../CLAUDE.md), [`PROJECT_CONTEXT.md`](./PROJECT_CONTEXT.md),
[`AI_RULES.md`](./AI_RULES.md), and [`DEVELOPMENT_WORKFLOW.md`](./DEVELOPMENT_WORKFLOW.md).
The underlying documentation conflict (docs say "no framework / v2.0 target"; code
ships one) remains **reported** in [`PROJECT_CONTEXT.md`](./PROJECT_CONTEXT.md#known-architectural-gaps)
until a future ADR formally ships v2.0 (wire Bootstrap, bump `VERSION`, update
CHANGELOG/ROADMAP).

### Alternatives
- *Declare v2.0 shipped now and switch the default to OOP* — rejected: `public_html/`
  is not wired to it, it is untested as the runtime, and it would break backward
  compatibility for every existing procedural application.
- *Remove `DMF\Core`/`DMF\Auth` from the repo until wired* — rejected: the code is
  the accepted v2.0 target and valuable; deleting it loses work and history. Keeping
  it dormant behind an explicit activation gate is safer.
- *Let AI decide per-file which style to emit* — rejected: produces mixed,
  non-functional output and violates "follow the implementation that exists."

### Consequences
- (+) One unambiguous rule: **procedural by default; OOP only on explicit,
  all-conditions-met migration.** Backward compatibility preserved.
- (+) The framework layer can mature in-repo without destabilizing shipped apps.
- (+) AI behavior is deterministic and auditable against a 5-point checklist.
- (−) The doc/implementation conflict persists until v2.0 is formally shipped; it
  stays visibly reported, not hidden.
- (−) Every app must explicitly declare its version target (procedural or v2.x) in
  its `APP_CONTEXT.md` for the gate to be enforceable.
- **Supersedes** the interim guidance in ADR-0001, which remains for historical
  context.

---

<sub>DMF PHP Framework · Decision Records (AI context) · append-only ·
complements workspace ADRs in `../../../.claude/adr/` · © 2026 Digital Media
Foundation</sub>
