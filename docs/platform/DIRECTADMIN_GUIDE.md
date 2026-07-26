# DirectAdmin Deployment Guide

Click-by-click deployment on DirectAdmin shared hosting. **No SSH. No Composer.
No command line.**

If you have SSH access, [`DEPLOYMENT_GUIDE.md`](DEPLOYMENT_GUIDE.md) is more
convenient. This guide assumes you have only the control panel.

---

## 1. What you need

| | |
|---|---|
| DirectAdmin login | username + password |
| The release ZIP | from GitHub Releases, or `release/` after `composer dmf:build` |
| Database credentials | created in §3 |
| A few minutes | first deployment ≈ 15 min, updates ≈ 5 min |

The ZIP is **self-contained**: `vendor/` is already inside it, fully installed.
Nothing is built on the server, which is why this works on hosting with no
developer tooling at all.

---

## 2. Back up first

**Every deployment, without exception.**

### Database

1. **Advanced Features → phpMyAdmin**
2. Select your database in the left sidebar
3. **Export** tab → *Quick* → format **SQL** → **Go**
4. Save the `.sql` file somewhere you will find it again

### Files

1. **System Info & Files → File Manager**
2. Navigate to `~/public_html/`
3. Select all → **Compress** → name it `backup-YYYY-MM-DD.zip`
4. **Download** it

Keep at least the last three backups.

---

## 3. Create the database *(first deployment only)*

1. **Account Manager → MySQL Management**
2. **Create new Database**
   - *Database Name*: `myapp` → becomes `username_myapp`
   - *Database User*: `myapp` → becomes `username_myapp`
   - *Password*: generate a strong one
3. **Create**
4. **Write down the full names including the `username_` prefix** — DirectAdmin
   adds it, and it is the single most common cause of a failed first
   deployment.

```
DB_HOST=localhost
DB_NAME=username_myapp
DB_USER=username_myapp
DB_PASS=<the password>
```

---

## 4. Upload

1. **System Info & Files → File Manager**
2. Navigate into `~/public_html/`
   > Extract **inside** `public_html`, not in your home directory. The ZIP is
   > flat: its root *is* `public_html`.
3. **Upload files** → choose the ZIP → wait for 100 %

---

## 5. Extract

1. Confirm you are still in `~/public_html/`
2. Tick the ZIP → **Extract**
3. Target directory: `/public_html` (or leave it as the current directory)
4. **Extract**
5. Delete the ZIP afterwards — it is served over HTTP otherwise

You should now see:

```
~/public_html/
├── index.php
├── .htaccess
├── vendor/
├── includes/
├── config/
├── database/
├── storage/
├── VERSION
└── .env.example
```

### Check `vendor/dmf/core` — the one check that matters

Navigate to `vendor/dmf/core/src/Security/`.

| What you see | Meaning |
|---|---|
| `Sanitizer.php`, `Csrf.php`, `PasswordHasher.php`, `SecureRandom.php` | ✅ correct |
| **empty**, or `core` will not open | ❌ a symlink was packaged — **stop** |

If it is empty, the ZIP was not built through the release pipeline. Do not try
to repair it in the File Manager. Rebuild with `composer dmf:build`, which runs
the verification gates and will not emit a package with this defect.

This is exactly the failure the pipeline exists to prevent: a Composer path
repository produces a symlink at `vendor/dmf/core`, and a symlink does not
survive ZIP → upload → extract. The application would die with
`Class "Dmf\Core\Security\Sanitizer" not found`.

---

## 6. Configure `.env`

`.env` is deliberately **not** in the ZIP — real credentials are not build
artifacts.

1. In File Manager, in `~/public_html/`, tick `.env.example`
2. **Copy** → name the copy `.env`
3. Tick `.env` → **Edit**
4. Fill in:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.ac.th

DB_HOST=localhost
DB_NAME=username_myapp
DB_USER=username_myapp
DB_PASS=your-password

SESSION_SECURE=true
SESSION_HTTPONLY=true
```

5. **Save**

> `APP_DEBUG` **must** be `false`. Left `true`, every visitor sees stack traces,
> absolute file paths and SQL. Check it on every deployment.

If you do not see `.env.example`, enable **Show hidden files** in the File
Manager settings (gear icon).

---

## 7. Import the database *(first deployment only)*

1. **Advanced Features → phpMyAdmin**
2. Select `username_myapp`
3. **Import** tab
4. **Choose File** → `database/migrations/0001_core_schema.sql`
5. **Go**
6. Repeat for each migration **in numeric order**
7. Optionally import `database/seeds/0001_seed.sql`

For an update, import **only migrations added since the running version**.
Re-importing an applied migration can destroy data.

---

## 8. Permissions

`storage/` must be writable by PHP.

1. In File Manager, tick `storage`
2. **Set Permissions**
3. `755`, tick **Recursive** → **Set Permissions**

If writes still fail, try `775`. Never use `777` — it lets any account on a
shared host write to your application.

Correct values:

| Path | Permission |
|---|---|
| `storage/` and subdirectories | `755` (or `775`) |
| directories generally | `755` |
| files generally | `644` |
| `.env` | `600` if the host allows it, else `644` |

---

## 9. Verify

Open in a browser:

| URL | Expected |
|---|---|
| `https://your-domain/` | the application loads |
| `https://your-domain/.env` | **403 or 404** — never the file contents |
| `https://your-domain/config/config.php` | **403 or 404** |
| `https://your-domain/vendor/autoload.php` | **403 or 404** |
| `https://your-domain/includes/env.php` | **403 or 404** |

**If `.env` displays its contents, stop and fix it before going further.** Your
database password is being served to the internet. Either `.htaccess` was not
extracted (check hidden files) or the host has `AllowOverride None` — open a
support ticket asking for `AllowOverride All` on the document root.

Then confirm the deployed version: open `BUILD_INFO.json` in the File Manager
(not over HTTP — it should be blocked) and check `version` and `git_commit`.

---

## 10. Rollback

1. **phpMyAdmin → Import** the `.sql` backup from §2
2. **File Manager** — delete the new files from `~/public_html/`
3. Upload and extract the previous release ZIP
4. Restore `.env`
5. Confirm `BUILD_INFO.json` shows the previous version

Every release ZIP is complete and self-contained, so rolling back is just
deploying an older one.

---

## 11. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `Class "Dmf\Core\Security\Sanitizer" not found` | `vendor/dmf/core` empty — symlink packaged | rebuild via the pipeline (§5) |
| Blank white page | PHP fatal, display off | **Errors → Error Log**, and `storage/logs/` |
| `Access denied for user` | wrong DB name/user | remember the `username_` prefix (§3) |
| Files landed in `~/` not `public_html` | extracted in the wrong directory | move them, or delete and redo §5 |
| `.htaccess` missing after extract | hidden files not shown | enable *Show hidden files* |
| 500 after upload | `.htaccess` directive the host disallows | **Errors → Error Log** names the line |
| Cannot write to `storage/` | permissions | §8 |
| Old version still served | opcache | **Extra Features → PHP Selector**, toggle the version to flush |
| Session lost every request | `SESSION_SECURE=true` without HTTPS | enable HTTPS (Let's Encrypt in DirectAdmin) |

---

## 12. Deployment checklist

```
Before
  [ ] Database exported and downloaded
  [ ] public_html compressed and downloaded
  [ ] Release ZIP verified (composer dmf:verify -- --zip …)

Deploy
  [ ] ZIP uploaded into ~/public_html/
  [ ] Extracted INSIDE ~/public_html/
  [ ] Uploaded ZIP deleted from the server
  [ ] vendor/dmf/core/src/Security/ contains real files
  [ ] .env present, APP_DEBUG=false, credentials correct
  [ ] Migrations imported in order
  [ ] storage/ writable (755)

After
  [ ] Site loads
  [ ] /.env returns 403/404
  [ ] /config/*, /vendor/*, /includes/* return 403/404
  [ ] BUILD_INFO.json shows the expected version
  [ ] Installer removed (if the application ships one)
  [ ] Log in and exercise one real workflow
```

---

## 13. See also

- [`DEPLOYMENT_GUIDE.md`](DEPLOYMENT_GUIDE.md) — host-agnostic reference
- [`PRODUCTION_MODE.md`](PRODUCTION_MODE.md) — why the ZIP is self-contained
