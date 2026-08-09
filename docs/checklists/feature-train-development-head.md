# Feature-Train Development Head

**Status record (mutable).** Points future agents at the canonical unreleased development baseline. Not a milestone plan and not a release.

## Current head

| Fact | Value |
|------|--------|
| Canonical branch (pre-M12 base) | `feature/feature-train-m9-m11` @ `8a5d4d8` |
| Active M12 / post-M12 tip | `feature/m12-supplier-list-performance` |
| Contains | M9 + M10 + M11 + CI recovery + M12 |
| Plugin version | `1.29.0` |
| `DB_VERSION` | `10` |
| Last **released** version | `v1.25.0` (on `main`) |
| GitHub CI baseline | Green (CI recovery + M12 CI proof) |
| Next authorized process step | **Feature-train closure (WP6)** — do **not** start M13 |

## Frozen milestone tips (do not move)

| Branch | Freeze tip | Role |
|--------|------------|------|
| `feature/m9-supplier-observed-lead-time` | `e918757` | M9 freeze |
| `feature/m10-po-expected-date-suggestion` | `aa7e214` | M10 freeze |
| `feature/m11-supplier-on-time-rate` | `d7574e8` | M11 freeze |

CI recovery was built on top of the M11 tip without rewriting those branches. M12 branched from `feature/feature-train-m9-m11` @ `8a5d4d8` without rewriting frozen tips.

## What is *not* true

- M9 / M10 / M11 / M12 are **not** released, tagged, or on `main`.
- Merging the feature train into `main` is a **WP6** decision, not automatic after M12 freeze.
- Per-version `docs/GITHUB_RELEASE_NOTES_1.26.0.md` exists for M9 content; `1.27.0` / `1.28.0` / `1.29.0` notes are deferred until the bundled release (`scripts/release-audit.sh --release`).

## Related

- [`docs/checklists/ci-recovery-2026-08.md`](ci-recovery-2026-08.md) — CI recovery record
- [`docs/checklists/m12-release-readiness.md`](m12-release-readiness.md) — M12 Level A freeze
- [`docs/process/milestone-lifecycle.md`](../process/milestone-lifecycle.md) — WP5 continue / WP6 feature-train release
- [`docs/checklists/m9-release-readiness.md`](m9-release-readiness.md) / [`m10`](m10-release-readiness.md) / [`m11`](m11-release-readiness.md)
