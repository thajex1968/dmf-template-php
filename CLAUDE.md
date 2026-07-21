# CLAUDE.md — AI Entry Point

> **Read this first.** This file is the rules-of-engagement entry point for any AI
> coding assistant (Claude Code, OpenAI Codex, GitHub Copilot, Cursor, and future
> tools) operating in this repository. It is intentionally short. It does **not**
> duplicate the reference documentation — it routes you to it.

---

## Repository Identity

- **Name:** `dmf-template-php`
- **Role:** The **official DMF PHP Framework** — the parent template from which
  **all** future DMF PHP applications are generated.
- **Layer:** Platform Layer (see the workspace governance at
  [`../.claude/CLAUDE.md`](../.claude/CLAUDE.md)).
- **Version:** see [`VERSION`](./VERSION) — currently `1.1.0`.
- **Contains no business logic.** Applications add domain logic on top of this
  skeleton; this repository never does.

---

## Current Architecture (authoritative for v1.x)

The **only shippable pattern today** is:

- **Plain procedural PHP** (no runtime framework).
- **Include-chain architecture** — `require_once` of `includes/*` in a fixed order.
- **PDO** with prepared statements (positional `?`), a single shared `$conn`.

Full detail lives in [`ARCHITECTURE.md`](./ARCHITECTURE.md) and
[`CODING_STANDARD.md`](./CODING_STANDARD.md). Do not restate it — read it.

### Future architecture (v2.x — target only, GATED)

An OOP framework (`DMF\Core` / `DMF\Auth`) is the documented **target** for v2.0
(see [`ROADMAP.md`](./ROADMAP.md), [`AUTHENTICATION.md`](./AUTHENTICATION.md)).

> **HARD RULE:** Never generate OOP framework code (`DMF\Core`, `DMF\Auth`,
> classes, middleware, routers) **unless** the target application explicitly
> declares it targets v2.x **and** the required framework classes are **verified
> to exist on disk**. Otherwise, generate procedural include-chain code.
>
> **The `DMF\Core`/`DMF\Auth` code already exists in `includes/` but is dormant
> (unwired).** Its presence is not a trigger to use it. The runtime is procedural
> until an app is explicitly migrated — the binding rule is
> [`docs/ai/DECISIONS.md` ADR-0003](./docs/ai/DECISIONS.md#adr-0003-framework-boundary--procedural-is-the-runtime-dmfcoredmfauth-activate-only-on-explicit-v2x-migration).
> **AI must never migrate an app from procedural to OOP automatically.**

---

## Source-of-Truth Order (strict priority)

Never rely on memory or assumption. Verify in this order:

1. **Current repository source code** (`includes/`, `config/`, `public_html/`,
   `database/`) — the implementation that actually exists.
2. Repository reference documentation (`ARCHITECTURE.md`, `CODING_STANDARD.md`,
   `SECURITY.md`, `DATABASE.md`, `API.md`, `TESTING.md`).
3. [`CHANGELOG.md`](./CHANGELOG.md)
4. [`ROADMAP.md`](./ROADMAP.md)
5. [`VERSION`](./VERSION)
6. Git history
7. Previous conversation *(lowest — never authoritative)*

**If documentation and implementation differ:** do **not** guess. **Report the
inconsistency** and follow the implementation that actually exists.

If information is missing, state it as `Unknown` / `Not verified` and say what is
needed — never invent it.

---

## AI Document Set

| Document | Purpose |
| -------- | ------- |
| [`docs/ai/AI_RULES.md`](./docs/ai/AI_RULES.md) | Mandatory MUST / MUST NOT / STOP behavior. |
| [`docs/ai/PROJECT_CONTEXT.md`](./docs/ai/PROJECT_CONTEXT.md) | Ground-truth map of this repository, for AI. |
| [`docs/ai/APP_CONTEXT_TEMPLATE.md`](./docs/ai/APP_CONTEXT_TEMPLATE.md) | Per-application context template (copy into each new app). |
| [`docs/ai/PROMPTS.md`](./docs/ai/PROMPTS.md) | Reusable, task-shaped prompt templates. |
| [`docs/ai/DEVELOPMENT_WORKFLOW.md`](./docs/ai/DEVELOPMENT_WORKFLOW.md) | The Research → Release loop AI must follow. |
| [`docs/ai/AI_CHECKLIST.md`](./docs/ai/AI_CHECKLIST.md) | Gate checklists (security, DB, review, release, …). |
| [`docs/ai/DECISIONS.md`](./docs/ai/DECISIONS.md) | ADR template + examples for significant decisions. |
| [`docs/ai/AI_GLOSSARY.md`](./docs/ai/AI_GLOSSARY.md) | Shared framework vocabulary. |

> The reference docs above always outrank these AI docs on any conflict. The AI
> docs index and operationalize the reference docs — they never override them.

---

<sub>DMF PHP Framework · AI Entry Point · aligns with workspace governance
`../.claude/CLAUDE.md` · © 2026 Digital Media Foundation</sub>
