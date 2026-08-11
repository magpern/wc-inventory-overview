# Feature-Train Development Head

**Status record (mutable).** Historical pointer for the completed M9–M12 and M13–M15 trains. Not a milestone plan.

## Current published baseline

| Fact | Value |
|------|--------|
| Published branch / tag | `main` / **`v1.32.0`** |
| Contains | M0–M8 GA + M9–M12 (+ CI recovery) + M13–M15 |
| Plugin version | `1.32.0` |
| `DB_VERSION` | `10` |
| GitHub Release | https://github.com/magpern/wc-inventory-overview/releases/tag/v1.32.0 |
| Release notes | [`docs/GITHUB_RELEASE_NOTES_1.32.0.md`](../GITHUB_RELEASE_NOTES_1.32.0.md) |
| Train closure records | [`feature-train-m9-m12-release-readiness.md`](feature-train-m9-m12-release-readiness.md) / [`feature-train-m13-m15-release-readiness.md`](feature-train-m13-m15-release-readiness.md) |
| Next authorized process step | Plan **M16+** only with explicit approval — both trains' WP6 are complete |

## Frozen milestone tips (historical; do not rewrite)

| Branch | Freeze tip | Role |
|--------|------------|------|
| `feature/m9-supplier-observed-lead-time` | `e918757` | M9 freeze |
| `feature/m10-po-expected-date-suggestion` | `aa7e214` | M10 freeze |
| `feature/m11-supplier-on-time-rate` | `d7574e8` | M11 freeze |
| `feature/m12-supplier-list-performance` | `4edf703` (release-prep tip before merge) | M12 / M9–M12 train tip |
| `feature/m13-printable-purchase-order` | `9632215` | M13 freeze |
| `feature/m14-supplier-order-history` | `0780ba7` (accepted DOING_AJAX remediation) | M14 freeze |
| `feature/m15-supplier-spend-summary` | `b6aa3c3` (release-prep tip before merge) | M15 / M13–M15 train tip |

## Related

- [`docs/checklists/ci-recovery-2026-08.md`](ci-recovery-2026-08.md)
- [`docs/process/milestone-lifecycle.md`](../process/milestone-lifecycle.md)
- [`docs/checklists/m9-release-readiness.md`](m9-release-readiness.md) / [`m10`](m10-release-readiness.md) / [`m11`](m11-release-readiness.md) / [`m12`](m12-release-readiness.md)
- [`docs/checklists/m13-release-readiness.md`](m13-release-readiness.md) / [`m14`](m14-release-readiness.md) / [`m15`](m15-release-readiness.md)
