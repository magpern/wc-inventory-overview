# Feature Train M9–M12 — Release Readiness (Closure Review)

**Status:** Closure review complete — **bundled WP6 release executed**.  
**Date:** 2026-08-10  
**Review type:** Comprehensive train-level closure (not a repeat of four milestone audits)  
**Train tip (M12 freeze):** `9ce2a85` on `feature/m12-supplier-list-performance`  
**Release-prep tip:** `4edf703`  
**Merge commit on `main`:** `821d059`  
**Published tag:** **`v1.29.0`**

## Train composition

| Milestone | Capability | Development version | Freeze tip |
|-----------|------------|---------------------|------------|
| M9 | Supplier Observed Lead-Time Statistics | 1.26.0 | `e918757` |
| M10 | Purchase Order Expected-Date Suggestion | 1.27.0 | `aa7e214` |
| M11 | Supplier On-Time Delivery Rate | 1.28.0 | `d7574e8` |
| M12 | Supplier List Performance Surface | 1.29.0 | `9ce2a85` (Level A freeze) |
| — | CI recovery (risky DB tests, DB reset, release-audit modes, Actions Node upgrade) | — | in ancestry via `8a5d4d8` |

**Plugin development / published version:** `1.29.0`  
**`DB_VERSION`:** `10` (unchanged across the entire train)  
**Previous published release:** `v1.25.0`  
**Release status:** **COMPLETED** — merged to `main`, tagged `v1.29.0`, GitHub Release published, deployed to `dev.biopentra.eu`.

Immutable plans (unchanged after materialization):  
`docs/milestones/m9-implementation-plan.md` … `m12-implementation-plan.md`.

## WP6 execution record

| Step | Result |
|------|--------|
| Release notes | `docs/GITHUB_RELEASE_NOTES_1.29.0.md` |
| Release PR | https://github.com/magpern/wc-inventory-overview/pull/13 (merge commit) |
| Draft CI PRs | #11 and #12 closed without merging |
| Tag | `v1.29.0` → `821d059` |
| GitHub Release | https://github.com/magpern/wc-inventory-overview/releases/tag/v1.29.0 |
| ZIP | `wc-inventory-overview-1.29.0.zip` (93 entries) |
| Deploy | Bind-mount checkout `/opt/biopentra/dev/wc-inventory-overview` on `main`/`v1.29.0` |
| Rollback rehearsal | `v1.25.0` → restore `v1.29.0`; `DB_VERSION` stayed 10 |

## Closure-review result (pre-release)

**Verdict:** No CRITICAL or MAJOR findings. Train was architecturally coherent and safe to release.

### Cross-milestone ownership (verified pre-release)

```
Expected_Deadline  ←── PO_Delay (live delay)
                   ←── Supplier_Lead_Time_Service (historical stats + on-time)
                              ↑
                   Expected_Date_Suggestion_Service (recommendation policy)
                              ↑
                   Presentation: Purchasing detail / Suppliers list / PO Admin+JS
```

## Next authorized action

Plan the next milestone only with explicit approval. **Do not start M13** without a new approved plan.
