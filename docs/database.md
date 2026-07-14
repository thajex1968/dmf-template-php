# Database

The template ships **only the reusable core schema** — identity,
authentication, and auditing. Domain tables belong to each application's own
migrations under `database/migrations/`.

- **Engine:** InnoDB
- **Charset / collation:** `utf8mb4` / `utf8mb4_unicode_ci`
- **Server:** MySQL 8.0+ or MariaDB 10.5+

## Applying migrations & seeds

```bash
mysql -u <user> -p <database> < database/migrations/0001_core_schema.sql
mysql -u <user> -p <database> < database/seeds/0001_seed.sql
```

Migrations are plain, ordered `.sql` files (`0001_`, `0002_`, …). There is no
migration framework — keep it simple and explicit, matching the platform's
no-dependency runtime.

## Core Tables

### `users`
Application accounts. `password` stores a bcrypt/argon hash. `role` is a single
lowercase string consumed by the RBAC helpers.

| Column | Notes |
|--------|-------|
| `id` | PK |
| `username` | unique login |
| `email` | unique, nullable |
| `password` | password hash — never plaintext |
| `name` | display name |
| `role` | e.g. `super_admin`, `admin`, `user` |
| `avatar` | filename under `public_html/uploads/avatars/` |
| `is_active` | soft enable/disable |
| `last_login` | updated on successful login |
| `created_at` / `updated_at` | timestamps |

### `password_resets`
Token-based recovery. Store only the **sha256 hash** of the emailed token, with
an `expires_at` and single-use `used_at`.

### `login_attempts`
Records login attempts per username/IP to support lockout / rate limiting.
(The reference had this table but never enforced it — here it ships ready to
enforce.)

### `audit_logs`
Generic who-did-what trail: `action`, `entity` + `entity_id`, a JSON `data`
payload, and `ip_address`.

## Conventions

- Every table has `id BIGINT UNSIGNED AUTO_INCREMENT` primary key.
- Foreign keys are named `fk_<table>_<ref>` and indexed.
- Timestamps default to `CURRENT_TIMESTAMP`.
- Add domain tables in new numbered migration files; never edit `0001_`.
- If the application is multi-tenant, add a `tenant_id`/`school_id` column to
  each domain table and scope **every** query by it in application code.
