# APP_CONTEXT.md — Application Context Template

> **How to use:** Copy this file into every application generated from
> `dmf-template-php` (recommended location: `docs/ai/APP_CONTEXT.md` in the new
> app). Fill every field. This is the per-application counterpart to the
> framework's [`PROJECT_CONTEXT.md`](./PROJECT_CONTEXT.md): the framework file
> describes the reusable base; this file describes *your* domain on top of it.
>
> AI assistants read **both** files. Where this file is silent, the framework
> defaults in [`PROJECT_CONTEXT.md`](./PROJECT_CONTEXT.md) and
> [`AI_RULES.md`](./AI_RULES.md) apply. Leave a field as `Unknown / Not verified`
> rather than guessing — that label is a valid, expected value.

---

## 1. Application Name

- **Name:** `<app-name>` (e.g. `projects.dmf.ac.th`)
- **Repository:** `<git-url>`
- **Template version it was generated from:** `<x.y.z>`
- **Target framework version:** `v1.x (procedural)` **or** `v2.x (OOP DMF\Core/DMF\Auth)`
  > If `v2.x`, the OOP framework classes **must be verified present** before AI
  > generates OOP code (see [`AI_RULES.md`](./AI_RULES.md#4-version-boundary-rule-v1x-vs-v2x--source-roadmapmd-authenticationmd)).

## 2. Purpose

One paragraph: what problem this application solves and for whom.

## 3. Business Domain

- **Domain summary:** `<what the system manages>`
- **Ubiquitous language / key terms:** `<term = definition>` (extend
  [`AI_GLOSSARY.md`](./AI_GLOSSARY.md) with domain terms as needed)
- **Primary user personas:** `<who uses it>`

## 4. Modules

List each functional module and its responsibility.

| Module | Responsibility | Key pages / endpoints |
| ------ | -------------- | --------------------- |
| `<module>` | `<what it does>` | `<paths>` |

## 5. Database

> Domain tables only — the core schema (`users`, `password_resets`,
> `login_attempts`, `audit_logs`) is inherited; do not redefine it. Follow
> [`DATABASE.md`](../../DATABASE.md) conventions and numbered migrations.

| Table | Purpose | Key relationships | Migration file |
| ----- | ------- | ----------------- | -------------- |
| `<table>` | `<purpose>` | `<fk → table>` | `000N_*.sql` |

## 6. Roles

| Role | Description | Inherits |
| ---- | ----------- | -------- |
| `super_admin` | `<...>` | — |
| `admin` | `<...>` | — |
| `<role>` | `<...>` | `<role>` |

## 7. Permissions

> How authorization is expressed in this app (flat roles via `hasRole()` in v1.x;
> fine-grained permissions/ACL only if the app targets v2.x and the classes exist).

| Permission | Applies to | Enforced by |
| ---------- | ---------- | ----------- |
| `<domain.action>` | `<role(s)>` | `requireRole()` / `requirePermission()` |

## 8. API

> Follow the standard JSON envelope and conventions in [`API.md`](../../API.md).

| Endpoint | Method | Auth / role | Purpose |
| -------- | ------ | ----------- | ------- |
| `/api/<verb>_<resource>.php` | `POST` | `<role>` | `<...>` |

## 9. Deployment

- **Environments:** `<dev / staging / prod hostnames>`
- **Document root:** `public_html/` (invariant)
- **Env vars beyond `.env.example`:** `<list>`
- **Cron / scheduled jobs:** `<list or None>`
- **External services:** `<SMTP, storage, SSO, …>`

## 10. Custom Constraints

Anything that overrides or narrows a framework default for this app (e.g. stricter
password policy, mandatory 2FA, data-residency rules). State the constraint and
**why**. If a constraint conflicts with a framework rule, record it in
[`DECISIONS.md`](./DECISIONS.md) rather than silently diverging.

## 11. Known Risks

| Risk | Impact | Mitigation / status |
| ---- | ------ | ------------------- |
| `<risk>` | `<impact>` | `<mitigation>` |

## 12. Architecture Notes

- **Deviations from the template:** `<none / list with justification>`
- **Framework version boundary status:** `<procedural v1.x confirmed / v2.x classes verified at includes/Core, includes/Auth>`
- **Open questions / `Unknown`:** `<list what is unverified>`

---

<sub>DMF PHP Framework · App Context Template · copy per application · pair with
[`PROJECT_CONTEXT.md`](./PROJECT_CONTEXT.md) · © 2026 Digital Media Foundation</sub>
