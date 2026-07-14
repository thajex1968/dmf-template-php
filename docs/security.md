# Security

The template ships the secure defaults that the reference implementation had
listed as "areas for improvement." Treat this as the baseline every DMF PHP
application inherits.

## Implemented baseline

| Control | Where |
|---------|-------|
| Secrets outside source tree | `.env` (git-ignored) → `env()` → `config/config.php` |
| Config above the web root | `config/`, `includes/` are siblings of `public_html/`, not inside it |
| Prepared statements only | `$conn` (PDO), `ATTR_EMULATE_PREPARES => false` |
| Password hashing | `password_hash` / `password_verify` (bcrypt) |
| Session fixation defense | `session_regenerate_id(true)` on login |
| Idle timeout | `SESSION_TIMEOUT` enforced in `auth_check.php` |
| CSRF protection | `includes/csrf.php` — `csrf_field()` / `csrf_verify()` |
| RBAC gate | `requirePermission()` / `requireRole()` |
| Open-redirect defense | login only accepts local `/...` redirects |
| Output escaping | `htmlspecialchars()` on all rendered values |
| Security headers | `public_html/.htaccess` (nosniff, frame options, referrer policy) |
| No code execution in uploads | `public_html/uploads/.htaccess` disables PHP handlers |
| Uniform auth errors | login never reveals whether a username exists |
| Rate-limit scaffolding | `login_attempts` table shipped ready to enforce |

## Operational requirements

- **Copy `.env.example` → `.env`** and set real values. Never commit `.env`.
- Set `APP_DEBUG=false` and `APP_ENV=production` in production so errors are
  logged, not displayed.
- Serve over **HTTPS**; enable the HSTS header in `public_html/.htaccess`.
- Make only `storage/` writable by the web server (`chmod -R 775 storage`).
- Rotate any credential that has ever appeared in git history.

## Reporting

Report vulnerabilities privately to the DMF development team — do not open a
public issue. Never include secrets or live credentials in issue reports.
