# Feature-Train Development Head

**Status record (mutable).** Points future agents at the canonical unreleased development baseline. Not a milestone plan and not a release.

## Current head

| Fact | Value |
|------|--------|
| Canonical branch | `feature/feature-train-m9-m11` |
| Tip at this writing | see `git rev-parse feature/feature-train-m9-m11` |
| Contains | M9 + M10 + M11 + CI recovery |
| Plugin version | `1.28.0` |
| `DB_VERSION` | `10` |
| Last **released** version | `v1.25.0` (on `main`) |
| GitHub CI baseline | Green (CI recovery proven on former PR #10; re-verified after this integration) |
| M12 base | **Must branch from `feature/feature-train-m9-m11`** |

## Frozen milestone tips (do not move)

| Branch | Freeze tip | Role |
|--------|------------|------|
| `feature/m9-supplier-observed-lead-time` | `e918757` | M9 freeze |
| `feature/m10-po-expected-date-suggestion` | `aa7e214` | M10 freeze |
| `feature/m11-supplier-on-time-rate` | `d7574e8` | M11 freeze |

CI recovery was built on top of the M11 tip without rewriting those branches. Historical pointer: `chore/ci-green-recovery` (same lineage; superseded as the *name* of the development head by `feature/feature-train-m9-m11`).

## What is *not* true

- M9 / M10 / M11 are **not** released, tagged, or on `main`.
- Merging the feature train into `main` is a **WP6** decision, not automatic after CI recovery.
- Per-version `docs/GITHUB_RELEASE_NOTES_1.26.0.md` exists for M9 content; `1.27.0` / `1.28.0` notes are deferred until the bundled release (`scripts/release-audit.sh --release`).

## Related

- [`docs/checklists/ci-recovery-2026-08.md`](ci-recovery-2026-08.md) — CI recovery record
- [`docs/process/milestone-lifecycle.md`](../process/milestone-lifecycle.md) — WP5 continue / WP6 feature-train release
- [`docs/checklists/m9-release-readiness.md`](m9-release-readiness.md) / [`m10`](m10-release-readiness.md) / [`m11`](m11-release-readiness.md)
