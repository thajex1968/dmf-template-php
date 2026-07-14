# Git Standards, Branching, Versioning & Release

## Branch Strategy

A GitHub Flow / Git Flow hybrid suited to continuous delivery.

| Branch | Purpose | Merges into |
|--------|---------|-------------|
| `main` | Production-ready, released code. Protected. | — |
| `develop` | Integration branch for the next release. | `main` (via release) |
| `feature/*` | New features and enhancements. | `develop` |
| `bugfix/*` | Non-urgent fixes. | `develop` |
| `release/*` | Release stabilization + version bump. | `main` + `develop` |
| `hotfix/*` | Urgent production fixes (branch from `main`). | `main` + `develop` |

**Rules**

- `main` and `develop` are protected — no direct pushes; changes land via PR.
- Every PR must pass CI (lint + static analysis + tests) and have ≥1 review.

## Commit Messages — Conventional Commits

```
<type>(optional scope): <subject>
```

Types: `feat`, `fix`, `docs`, `refactor`, `test`, `chore`, `ci`, `perf`.

```
feat(auth): add password reset flow
fix(routes): correct active-state matching for nested paths
docs: expand database conventions
```

## Versioning — Semantic Versioning 2.0.0

```
MAJOR.MINOR.PATCH
```

| Segment | Increment when… |
|---------|-----------------|
| MAJOR | Incompatible/breaking changes. |
| MINOR | Backward-compatible functionality. |
| PATCH | Backward-compatible bug fixes. |

The single source of truth is the [`VERSION`](../VERSION) file.

## Release Process

1. Branch `release/x.y.z` from `develop`.
2. Bump `VERSION` and move the `CHANGELOG.md` `[Unreleased]` entries under a new
   `[x.y.z] - YYYY-MM-DD` heading.
3. Open a PR into `main`; ensure CI is green and the release is reviewed.
4. Merge to `main` and tag: `git tag -a vx.y.z -m "Release x.y.z" && git push --tags`.
5. Merge `main` back into `develop` so the version bump propagates.
6. Publish a GitHub Release from the tag, pasting the changelog section.

## Changelog

Follow [Keep a Changelog](https://keepachangelog.com/). Group entries under
`Added`, `Changed`, `Fixed`, `Removed`, `Security`. Keep an `[Unreleased]`
section at the top for work in progress.
