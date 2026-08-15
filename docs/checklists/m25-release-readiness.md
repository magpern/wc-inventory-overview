# M25 Release Readiness Checklist

**Status:** Frozen and CI-Green (verified) — **Unreleased**, second and final milestone of the M24+M25 feature train  
**Date:** 2026-08-15  
**Version:** 1.42.0 (development target — not tagged; becomes the combined M24+M25 release tag once a subsequent combined release-readiness review authorizes it)  
**DB_VERSION:** 11 (unchanged — no schema, no new table/column/index)  
**Canonical base:** M24 frozen tip `feature/m24-replenishment-planning` @ `3c21a69e6402d631575ded4653435dbaa6dbe435` (draft PR #32, CI-green)

## Implementation Summary

**Branch:** `feature/m25-bulk-draft-po-creation`  
**Plan:** `docs/milestones/m25-implementation-plan.md` (Revision 2 + Amendments A–F, immutable, created at WP-M25-0 as `716875f`)  
**PR:** draft [#33](https://github.com/magpern/wc-inventory-overview/pull/33), opened at freeze, not merged

### Work Packages Completed

- **WP-M25-0:** Plan materialized alone on branch from M24 tip; M24 origin/PR/CI precondition verified
- **WP-M25-1:** Characterization of `create_draft()` currency/expected-date/unit-cost fallbacks and `PO_Request_Token` context isolation
- **WP-M25-2:** `Replenishment_Item_Lock`, additive `Purchase_Order_Lines::list_open_or_draft_item_ids_bulk()`, `Replenishment_Commit_Service::commit()`
- **WP-M25-3:** Two-seam failure injection (Seam A genuine `create_draft` failure; Seam B catastrophic interrupt + dual-connection `IS_FREE_LOCK` release proof)
- **WP-M25-4:** `Replenishment_Commit_Admin` + additive Planning-tab form (EDIT_PO-gated, unchecked defaults, select-all remaining-capacity clamp)
- **WP-M25-5–7:** Security/capability/PRG matrix, dual-connection concurrency, performance matrix through 100/100
- **WP-M25-8 / WP2–WP4:** Independent audit → remediation → this Level A freeze

### Implementation commits (after immutable plan)

| SHA | Subject |
|---|---|
| `716875f` | docs(m25): materialize approved implementation plan (immutable) |
| `b8bc16b` | feat(m25): add item lock, conflict bulk-read, and commit service |
| `d8f6c60` | feat(m25): add commit admin controller and planning-tab form |
| `a566466` | test(m25): add replenishment-commit suite and CI filter |
| `9e557ac` | docs(m25): record bulk draft PO creation changelog and status |
| `cfd60d7` | fix(m25): apply PHPCS autofixes and record measured 100/100 timing |
| `ca8e805` | docs(m25): Level A release-readiness checklist and freeze evidence |

## WP2 Findings → WP3 Remediation

| ID | Severity | Remediation |
|---|---|---|
| **F-01** | MAJOR | Implementation committed, pushed to `origin/feature/m25-bulk-draft-po-creation`, draft PR #33 opened; premature CI-green claims removed until Actions were green |
| **F-02** | MAJOR | Seam B lock-release proof rewritten to use an independent mysqli connection + `IS_FREE_LOCK` for **every** acquired `wc_io_replen_item_<id>` (same-connection re-`GET_LOCK` rejected as session-reentrant) |
| **F-03** | MINOR | Stale 0.71s claim removed; freeze-time full performance matrix 100/100 wall time **1.31s** recorded below |
| **F-04** | MINOR | Group select-all clamps to remaining global capacity (`MAX - outsideChecked`); markup/JS covered by form-gating render assertion |
| **F-05** | MINOR | Residual TOCTOU statement recorded in this checklist (section below) |

**OBS-01:** Readiness states correctly — item locks ascend by canonical `item_post_id`; supplier groups ascend by `supplier_id`. Frozen plan wording not modified.  
**OBS-02:** Added POST tests: VIEW_PO-only and `edit_products`-only cannot commit.  
**OBS-03:** Final suites run sequentially against the ephemeral phpunit DB.

## Residual TOCTOU (accepted limitation)

M25 item advisory locks serialize other M25 commit requests that touch the same catalog items.

They do **NOT** serialize unrelated non-M25 mutation paths that do not participate in the advisory-lock protocol.

A non-M25 stock/incoming/PO-state mutation may therefore occur after the single fresh `build_plan()` revalidation but before a later supplier group is created within the same synchronous commit request.

This residual window is bounded to the commit request's own measured wall-clock duration.

**M25 does not claim global serialization.**

## Lock ordering (OBS-01 clarification)

- **Item advisory locks:** ascending canonical `item_post_id` (`variation_id > 0 ? variation_id : product_id`)
- **Supplier create groups:** ascending `supplier_id`

## Conflict-status contract (Amendment A)

**Blocking:** `draft`, `placed`, `partially_received`  
**Non-blocking solely by history:** `received`, `cancelled`, `closed_short`  
Always subject to fresh `needs_reorder` via `build_plan()`.

## Performance evidence (WP-M25-7 / F-03)

Freeze-time full matrix (`Test_WC_IO_Replenishment_Commit_Performance`):

| lines/suppliers | created | POs | queries | GET_LOCK | RELEASE_LOCK | total_wall |
|---|---:|---:|---:|---:|---:|---:|
| 1/1 | 1 | 1 | 33 | 1 | 1 | 0.03s |
| 10/1 | 10 | 1 | 75 | 10 | 10 | 0.08s |
| 50/1 | 50 | 1 | 275 | 50 | 50 | 0.37s |
| 50/10 | 50 | 10 | 347 | 50 | 50 | 0.26s |
| 100/1 | 100 | 1 | 525 | 100 | 100 | 0.57s |
| 100/20 | 100 | 20 | 677 | 100 | 100 | 0.49s |
| **100/100** | **100** | **100** | **1317** | **100** | **100** | **1.31s** |

**100/100 fixture integrity:** 100 selected lines → 100 acquired locks → 100 conflict-free survivors → 100 supplier groups attempted → 100 POs created (0 failed, 0 skipped).

### MAX_COMMIT_GROUPS decision

**MAX_COMMIT_GROUPS NOT REQUIRED — measured evidence supports no additional cap.**  
(100/100 wall 1.31s ≪ ~10s synchronous-HTTP budget; lock overhead not disproportionate.)

## Lock-release empirical evidence (F-02)

Seam B (`test_seam_b_catastrophic_interruption_preserves_prior_success_and_releases_locks`):

- Injected `RuntimeException` after group index 0 succeeds
- Exactly one PO persists; later groups not attempted
- Independent second DB connection asserts `IS_FREE_LOCK('wc_io_replen_item_<id>') = 1` for **every** acquired item id
- Classification: **genuine dual-connection empirical proof** (not same-connection re-acquire)

## Verification Checklist

### Code Quality

- [x] Frozen M24 owners unchanged (`Replenishment_Planning_Service`, `Supplier_Preference_Resolver`, `PO_Service`, `PO_Product_Validator`, `Purchase_Orders`, `PO_Events`, `DB_Transaction`)
- [x] `Purchase_Order_Lines` additive bulk conflict method only
- [x] Version 1.42.0 / `DB_VERSION` 11 / no install schema diff beyond comment
- [x] Plan file immutable (single commit `716875f`, zero subsequent plan diffs)
- [x] No new public hook/filter; only `admin_post_wc_io_replenishment_commit`
- [x] No new capability constant; reuses `EDIT_PO`
- [x] No M26 artifacts

### Tests (local, sequential)

| Suite | Result |
|---|---|
| Targeted WP3 remediation (seams/locks/admin/form/dup) | OK (35 tests) |
| Full unit (`--testsuite unit --exclude-group performance`) | OK (546 tests, 3116 assertions) |
| M1–M25 focused (default `run-phpunit.sh` filter) | OK (1294 tests, 5500 assertions) |
| Full integration (`--testsuite integration --exclude-group performance`) | OK (759 tests, 2427 assertions) after clean DB retry |
| M25 non-performance | OK (85 tests, 208 assertions) |
| M25 performance | OK (7 tests, 19 assertions) |
| M24 planning regression | OK (76 tests, 294 assertions) |
| M17 supplier-merge regression | OK (60 tests, 192 assertions) |
| PO service/validation/architecture | OK (34 tests, 239 assertions) |
| `--list-tests` M25 discovery | 12 classes / 92 methods; CI filter includes `Test_WC_IO_Replenishment_Commit_` |

### Tooling

- [x] PHP parallel lint (CI + local container on `includes/` + main file): clean
- [x] `composer validate --strict`: valid
- [x] `docker compose -f tests/docker/docker-compose.phpunit.yml config`: OK
- [x] `docker compose -f tests/docker/docker-compose.test.yml config`: OK (obsolete `version` warning only)
- [x] `scripts/release-audit.sh --development`: passed (1.42.0 ZIP includes all three M25 production classes; no tests/ in ZIP)
- [x] PHPCS: M25 new production files error-clean after autofix; remaining purchasing-page warnings are pre-existing `manage_woocommerce`/nonce-sniff noise (not CI-gated)

### GitHub CI (exact final executable tip `cfd60d7`)

- Draft PR: https://github.com/magpern/wc-inventory-overview/pull/33
- **CI** (PHP lint and build ZIP): run [`31890787095`](https://github.com/magpern/wc-inventory-overview/actions/runs/31890787095) — **SUCCESS** on `cfd60d73da525ce1fe7e5e3a65b73ab373a1923e`
- **Tests** (PHP Parallel Lint + PHPUnit): run [`31890787107`](https://github.com/magpern/wc-inventory-overview/actions/runs/31890787107) — **SUCCESS** on the same SHA
- Prior implementation tip `9e557ac` also green: CI `31888266069`, Tests `31888265607`

Freeze evidence commit after this tip is docs-only (this checklist + status wording) and does not change executable code.

## BR-M25 / INV-M25 final verdict

- **BR-M25-1..30:** PASS (including BR-M25-23/24/26/27/28)
- **INV-M25-1..26:** PASS (including INV-M25-21/22/23; lock-release empirically dual-connection)

## Explicit non-claims

- NOT MERGED to `main`
- NOT TAGGED (`v1.41.0` / `v1.42.0` absent)
- NOT RELEASED / NO GitHub Release
- NOT DEPLOYED
- M26 NOT STARTED
- Combined M24+M25 feature-train release decision is a **later** stage

## Freeze attestation

Level A freeze completed after WP2 independent audit and WP3 remediation. No second independent audit was performed during WP3/WP4. M25 is frozen and ready for the combined M24+M25 feature-train release decision.
