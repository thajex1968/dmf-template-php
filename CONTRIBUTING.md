# Contributing to the DMF PHP Template

Thank you for helping improve the DMF PHP foundation. This template underpins
every PHP service in the DMF ecosystem, so consistency and quality matter.

## Getting Started

1. Fork or branch the repository.
2. Copy `.env.example` to `.env` and configure it.
3. Install dev tooling with `composer install`.

## Branching

Branch from `develop` using one of the prefixes:

- `feature/<name>` — new functionality
- `bugfix/<name>` — non-urgent fixes
- `hotfix/<name>` — urgent production fixes (branch from `main`)
- `release/<version>` — release stabilization

## Coding Standards

- Follow **PSR-12** for all PHP code.
- Use `UTF-8`, `LF` line endings, and 4-space indentation (enforced by
  `.editorconfig`).
- Keep functions small and documented with PHPDoc where it adds clarity.

## Commit Messages

Use [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add configuration loader
fix: correct storage path resolution
docs: expand installation guide
chore: bump dependencies
```

## Before Opening a Pull Request

Run the local checks:

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
vendor/bin/phpstan analyse
```

- Ensure CI is green.
- Update documentation and the `CHANGELOG.md` where relevant.
- Bump the `VERSION` file for release branches (SemVer).
- Fill in the pull request template.

## Reporting Issues

Use the [issue templates](./.github/ISSUE_TEMPLATE) for bugs and feature
requests. Never include secrets or credentials in issue reports.
