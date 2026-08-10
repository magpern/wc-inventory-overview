# Feature Train M9–M12 — Release Readiness (Closure Review)

**Status:** Closure review complete — **approved for bundled WP6 release preparation**.  
**Date:** 2026-08-10  
**Review type:** Comprehensive train-level closure (not a repeat of four milestone audits)  
**Train tip (M12 freeze):** `9ce2a85` on `feature/m12-supplier-list-performance`  
**Closure-docs tip:** see `git rev-parse feature/m12-supplier-list-performance` after the closure commit

## Train composition

| Milestone | Capability | Development version | Freeze tip |
|-----------|------------|---------------------|------------|
| M9 | Supplier Observed Lead-Time Statistics | 1.26.0 | `e918757` |
| M10 | Purchase Order Expected-Date Suggestion | 1.27.0 | `aa7e214` |
| M11 | Supplier On-Time Delivery Rate | 1.28.0 | `d7574e8` |
| M12 | Supplier List Performance Surface | 1.29.0 | `9ce2a85` (Level A freeze) |
| — | CI recovery (risky DB tests, DB reset, release-audit modes, Actions Node upgrade) | — | in ancestry via `8a5d4d8` |

**Plugin development version at tip:** `1.29.0`  
**`DB_VERSION`:** `10` (unchanged across the entire train)  
**Last published release:** `v1.25.0` on `main`  
**Release has NOT occurred** — no merge to `main`, no tag, no GitHub Release, no deploy.

Immutable plans (unchanged after materialization):  
`docs/milestones/m9-implementation-plan.md` … `m12-implementation-plan.md`.

Per-milestone freeze records:  
`docs/checklists/m9-release-readiness.md` … `m12-release-readiness.md`.

## Closure-review result

**Verdict:** No CRITICAL or MAJOR findings. Train is architecturally coherent and safe to proceed to bundled release preparation.

### Cross-milestone ownership (verified)

```
Expected_Deadline  ←── PO_Delay (live delay)
                   ←── Supplier_Lead_Time_Service (historical stats + on-time)
                              ↑
                   Expected_Date_Suggestion_Service (recommendation policy)
                              ↑
                   Presentation: Purchasing detail / Suppliers list / PO Admin+JS
```

- Statistics sole owner: `Supplier_Lead_Time_Service`
- Recommendation sole owner: `Expected_Date_Suggestion_Service`
- Deadline primitive: `Expected_Deadline` (narrow, pure)
- Live delay: `PO_Delay` (public contract unchanged under M11 refactor)
- No circular dependencies; no presentation SQL; no N+1 list path

### End-to-end semantics (verified)

- EXACT / ESTIMATED eligible for M11 rating; UNKNOWN / missing date excluded
- Grace days shared via `PO_Delay::grace_days_from_option()` for live delay and historical on-time
- Insufficient samples → “not enough data” / list em dash; observed vs rated thresholds independent
- Archived suppliers retain statistics; empty history safe
- **M10 → M11 coupling:** suggestion writes nothing; a *saved* Estimated expected date is an ordinary PO field and is intentionally rateable by M11 (documented in admin guide during this closure)

### Data / mutation

- New train services: zero write tokens
- No schema / migration / stock / cost / Inventory Position / storefront Expected Delivery semantic change
- M10 only influences ordinary new-PO form fields through the existing submit path
- Combined rollback tip → `v1.25.0` is code-only and unconditionally safe

### Query / performance

- M9/M11: one bulk stats SQL per `get_stats_bulk()` (exact-query contract)
- M10: one bulk stats call per suggestion resolution; no per-row suggestion SQL
- M12: one `get_stats_bulk()` per list `prepare_items()`; equal query count at 10/40/200; empty page → 0 stats SQL

### Test / CI (evidence)

- All train test classes match the focused filter (`Test_WC_IO_Supplier_Lead_Time_*`, `Expected_Date_Suggestion_*`, `Expected_Deadline*`, `Supplier_On_Time_Rate_*`, `Suppliers_List_Performance*`)
- M12 GitHub Actions green on PR #12 (Tests + CI) at freeze tip `9ce2a85` — runs `31338621371` / `31338621373`
- Local suites at M12 freeze: unit 270, focused 550, integration 291 — 0 failures / 0 errors / 0 risky
- `release-audit.sh --development` green at `1.29.0`

Full blocking suites were **not** re-run during this docs-only closure pass (existing green CI + freeze evidence accepted).

## Findings summary

| Severity | Count | Disposition |
|----------|-------|-------------|
| CRITICAL | 0 | — |
| MAJOR | 0 | — |
| MINOR | 2 remediations applied | CHANGELOG missing 1.27/1.28 restored; admin-guide M10→M11 wording clarified |
| OBSERVATION | several | See closure report; non-blocking |

Remaining **release-preparation** work (not blockers to *approving* the train):

- Author `docs/GITHUB_RELEASE_NOTES_1.29.0.md` covering M9–M12 (required for `--release` / tag)
- Treat `docs/GITHUB_RELEASE_NOTES_1.26.0.md` as superseded draft (do not tag `v1.26.0`)
- Optional later: detail panel should call `is_observed_value_usable()` instead of inlining the threshold (behaviorally identical today)

## Recommended release version

**Tag and publish `v1.29.0` only.**

Intermediate development bumps `1.26.0` / `1.27.0` / `1.28.0` were never tagged and must not be published as separate GitHub Releases. SemVer here ships the tip minor after last public `1.25.0`. Changelog retains the intermediate sections for history.

## Recommended release topology

1. Keep `feature/m12-supplier-list-performance` as the release source tip (contains full train + CI recovery + closure docs).
2. Open a **non-draft** release PR into `main` (do **not** merge draft CI PRs #11 / #12 as the release).
3. Prefer a **merge commit** (or repository-default non-squash merge) so milestone history remains inspectable; avoid squashing away plan-materialization commits.
4. After CI green on the release PR: merge → tag `v1.29.0` on the merge commit → publish GitHub Release with `GITHUB_RELEASE_NOTES_1.29.0.md` + ZIP → deploy → operational validation.
5. Close draft PRs #11 and #12 without merging after the real release PR exists (or supersede them).
6. Leave frozen milestone tip branches (`feature/m9…` / `m10…` / `m11…`) as historical pointers; optional archive after release.
7. Do **not** start M13 until WP6 completes.

## Next authorized action

**Bundled release preparation / execution (WP6)** — produce `docs/GITHUB_RELEASE_NOTES_1.29.0.md`, open the real release PR to `main`, then tag/publish/deploy per `docs/release-runbook.md`.

**Explicit:** release has **not** yet occurred.
