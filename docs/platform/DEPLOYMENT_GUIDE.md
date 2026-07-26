# Deployment Guide

Getting a built release onto a server. Host-agnostic; for the DirectAdmin
click-path see [`DIRECTADMIN_GUIDE.md`](DIRECTADMIN_GUIDE.md).

---

## 1. Deployment model

DMF applications deploy as a **self-contained ZIP**. The target is shared
hosting with **no SSH and no Composer**, so nothing is installed on the server —
the package already carries a production-only `vendor/` with a class-map
optimised autoloader.

```
    developer / CI                        server
  ┌────────────────┐                ┌────────────────┐
  │ composer       │                │                │
  │   dmf:build    │   upload ZIP   │  ~/public_html │
  │       │        │ ─────────────► │    extract     │
  │  verify gates  │                │       │        │
  │       ▼        │                │       ▼        │
  │  release/*.zip │                │  configure .env│
  └────────────────┘                └────────────────┘
```

The consequence worth internalising: **anything the application needs at
runtime must be in the ZIP**, because there is no build step on the far side.

---

## 2. Get a package

**From a GitHub Release (normal):**

```
https://github.com/thajex1968/dmf-template-php/releases
  ├── dmf-dmf-template-php-1.2.0.zip
  └── SHA256SUMS.txt
```

**Built locally:**

```bash
composer dmf:release      # ensure release mode
composer dmf:build        # → release/<name>-<version>.zip
```

---

## 3. Verify before uploading

```bash
sha256sum -c SHA256SUMS.txt
composer dmf:verify -- --zip release/dmf-dmf-template-php-1.2.0.zip
```

Expected:

```
  ✓ V1   No symlink entries in the archive
  ✓ V2   vendor/autoload.php
  ✓ V3   vendor/dmf/core/src/Security/Sanitizer.php
  ✓ V4   dmf/core installed from dist type "zip"
  ✓ V5   Packaged composer.json declares no path repository
  ✓ V6   Autoloader resolves 3 core classes
  ✓ V7   No development files under vendor/dmf/core
  ✓ V8   SHA-256 …

  PASS — artifact is safe to deploy.
```

**Do not upload an artifact that does not pass.** V1 and V3 in particular mean
the package would deploy to an empty `vendor/dmf/core` and a fatal
`Class "Dmf\Core\Security\Sanitizer" not found`.

---

## 4. Archive layout

The ZIP is **flat** — its root maps directly onto `~/public_html/`, so
extracting inside that directory puts everything where it belongs with no
manual moves:

```
<zip root>                        →  ~/public_html/
├── index.php                        web entry point
├── .htaccess                        routing + security headers + deny rules
├── auth/  dashboard/  uploads/      application pages
├── vendor/                          Composer deps (denied by .htaccess)
│   ├── autoload.php
│   └── dmf/core/                    REAL FILES — never a symlink
├── includes/                        (denied by .htaccess)
├── config/                          (denied by .htaccess)
├── database/                        migrations + seeds
├── storage/                         logs, cache, uploads — must be writable
├── VERSION
├── CHANGELOG.md
├── .env.example
└── BUILD_INFO.json                  version, commit, build time
```

`vendor/`, `includes/`, `config/` and `.env` sit under the document root but
are **blocked from HTTP access by `.htaccess`**. Verify that after the first
deployment — see §7.

---

## 5. Deploy

### First deployment

1. **Back up first** if anything is already there — database and files.
2. Upload the ZIP into `~/public_html/`.
3. Extract **inside** `~/public_html/`.
4. Create `.env` from `.env.example` and fill in real credentials.
5. Import `database/migrations/*.sql` in order via phpMyAdmin.
6. Make `storage/` writable (`755`, or `775` if the web user differs).
7. Run the security checks in §7.
8. Delete any installer script the application ships.

### Subsequent deployments

1. **Back up the database** — always, before every deployment.
2. Back up `.env` and anything under `storage/uploads/`.
3. Upload and extract the new ZIP over the old one.
4. **Do not overwrite `.env`.** Diff it against the new `.env.example` and add
   any new keys.
5. Apply new migrations only.
6. Confirm `BUILD_INFO.json` shows the version you intended.

---

## 6. Environment

`.env` is never in the ZIP — only `.env.example`. Real credentials are not build
artifacts.

```ini
APP_ENV=production
APP_DEBUG=false          # MUST be false in production
APP_URL=https://your-domain

DB_HOST=localhost
DB_NAME=
DB_USER=
DB_PASS=

SESSION_SECURE=true      # HTTPS only
SESSION_HTTPONLY=true
SESSION_SAMESITE=Lax
```

`APP_DEBUG=true` in production leaks stack traces, file paths and query text to
any visitor. Check it on every deployment.

---

## 7. Post-deployment checks

```bash
# 1. Version actually deployed
curl -s https://your-domain/BUILD_INFO.json
#    → 403/404 is CORRECT if .htaccess denies it; check via File Manager instead

# 2. Protected paths must NOT be readable
curl -so /dev/null -w '%{http_code}\n' https://your-domain/.env          # 403
curl -so /dev/null -w '%{http_code}\n' https://your-domain/config/config.php  # 403
curl -so /dev/null -w '%{http_code}\n' https://your-domain/vendor/autoload.php # 403
curl -so /dev/null -w '%{http_code}\n' https://your-domain/includes/env.php    # 403

# 3. Application responds
curl -sI https://your-domain/ | head -1                                  # 200
```

Then confirm in the File Manager that `vendor/dmf/core/src/Security/` contains
real files. If that directory is **empty**, a symlink was packaged — the
artifact was not built through the pipeline. Rebuild with `composer dmf:build`;
do not try to repair it in place.

---

## 8. Rollback

Every release is complete and self-contained, so rollback is just deploying an
older one:

1. Restore the database backup taken before the deployment.
2. Upload and extract the previous release ZIP.
3. Restore `.env`.
4. Confirm `BUILD_INFO.json` shows the previous version.

Reverse any migration applied by the failed deployment **before** restoring
older code, or the old code will meet a newer schema.

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `Class "Dmf\Core\Security\Sanitizer" not found` | `vendor/dmf/core` empty — a symlink was packaged | rebuild via `composer dmf:build`; the gates block this |
| HTTP 500, blank page | `.env` missing or bad DB credentials | check `.env`, then `storage/logs/` |
| `.env` readable over HTTP | `.htaccess` missing or `AllowOverride None` | re-extract; ask the host to enable `AllowOverride All` |
| Cannot write to `storage/` | permissions | `755` (or `775`) on `storage/` and subdirectories |
| Old version still served | host-level opcache | wait, or restart PHP from the control panel |
| Session lost on every request | `SESSION_SECURE=true` without HTTPS | enable HTTPS, or set it `false` for local only |

---

## 10. See also

- [`DIRECTADMIN_GUIDE.md`](DIRECTADMIN_GUIDE.md) — the click-by-click path
- [`PRODUCTION_MODE.md`](PRODUCTION_MODE.md) — how the package is built
- [`RELEASE_PIPELINE.md`](RELEASE_PIPELINE.md) — cutting a release
