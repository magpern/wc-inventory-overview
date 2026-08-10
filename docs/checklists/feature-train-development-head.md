# Feature-Train Development Head

**Status record (mutable).** Points future agents at the canonical unreleased development baseline. Not a milestone plan and not a release.

## Current head

| Fact | Value |
|------|--------|
| Canonical tip branch | `feature/m12-supplier-list-performance` |
| Pre-M12 integration branch | `feature/feature-train-m9-m11` @ `8a5d4d8` |
| Contains | M9 + M10 + M11 + CI recovery + M12 |
| Plugin version | `1.29.0` |
| `DB_VERSION` | `10` |
| Last **released** version | `v1.25.0` (on `main`) |
| GitHub CI baseline | Green (M12 PR #12; re-verified after train closure docs as needed) |
| Closure review | [`feature-train-m9-m12-release-readiness.md`](feature-train-m9-m12-release-readiness.md) |
| Next authorized process step | **Bundled WP6 release preparation/execution** — do **not** start M13 |

## Frozen milestone tips (do not move)

| Branch | Freeze tip | Role |
|--------|------------|------|
| `feature/m9-supplier-observed-lead-time` | `e918757` | M9 freeze |
| `feature/m10-po-expected-date-suggestion` | `aa7e214` | M10 freeze |
| `feature/m11-supplier-on-time-rate` | `d7574e8` | M11 freeze |
| `feature/m12-supplier-list-performance` | see closure readiness (M12 Level A freeze `9ce2a85`; tip may advance for train-closure docs only) | M12 freeze + train tip |

## What is *not* true

- M9 / M10 / M11 / M12 are **not** released, tagged, or on `main`.
- Draft CI PRs (#11 / #12) must **not** be merged as the release vehicle without an explicit WP6 release PR.
- Intermediate development versions `1.26.0` / `1.27.0` / `1.28.0` were **never tagged** and must not be tagged retrospectively; the bundled public tag is **`v1.29.0`**.
- Per-version `docs/GITHUB_RELEASE_NOTES_1.26.0.md` exists as an early draft (standalone M9 framing); WP6 requires **`docs/GITHUB_RELEASE_NOTES_1.29.0.md`** covering M9–M12.

## Related

- [`docs/checklists/ci-recovery-2026-08.md`](ci-recovery-2026-08.md) — CI recovery record
- [`docs/checklists/feature-train-m9-m12-release-readiness.md`](feature-train-m9-m12-release-readiness.md) — train closure
- [`docs/process/milestone-lifecycle.md`](../process/milestone-lifecycle.md) — WP5 continue / WP6 feature-train release
- [`docs/checklists/m9-release-readiness.md`](m9-release-readiness.md) / [`m10`](m10-release-readiness.md) / [`m11`](m11-release-readiness.md) / [`m12`](m12-release-readiness.md)
