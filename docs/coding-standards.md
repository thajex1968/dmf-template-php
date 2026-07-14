# Coding Standards

These standards are enforced by `.editorconfig`, PHPStan, and CI. They mirror
the conventions of the reference implementation.

## Language & Style

- **PHP 8.1+**, plain procedural PHP with PDO. **No framework** at runtime.
- Follow **PSR-12**. `declare(strict_types=1);` at the top of every PHP file.
- **UTF-8**, **LF** line endings, **4-space** indentation for PHP.
- 2-space indentation for front-end assets (JS/CSS/HTML/JSON/YAML).
- Prefer small, documented functions. Add PHPDoc where it clarifies types.

## Database Access

- **Always** use PDO prepared statements with positional `?` parameters.
- Never interpolate user input into SQL.
- Use transactions for multi-step writes.
- Reuse the single global `$conn`; do not open extra connections.

## Security-by-default

- Escape all output with `htmlspecialchars()`.
- Protect every state-changing POST with `csrf_field()` / `csrf_verify()`.
- Never commit secrets — configuration comes from `.env`.
- Validate and constrain redirects to local paths.

## Naming

- Variables and functions: English, `camelCase` for locals, `snake_case`
  acceptable for helpers matching existing files.
- Routes: dot-namespaced keys in `ROUTES` (e.g. `admin.users`).
- SQL identifiers: `snake_case`; foreign keys `fk_<table>_<ref>`.

## Front-end

- No build step. Each page owns its inline `<style>`/`<script>`.
- Use the shared design tokens (CSS variables) for colors.
- Dependencies via CDN (Bootstrap 5.3, Font Awesome 6.4, Sarabun font).

## Local checks (run before every PR)

```bash
composer lint       # php -l across the tree
composer analyse    # PHPStan (level 5)
composer test       # PHPUnit
# or all three:
composer check
```
