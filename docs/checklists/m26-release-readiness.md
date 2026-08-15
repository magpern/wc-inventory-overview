# M26 Release Readiness Checklist

**Status:** Frozen and CI-Green (verified) — **Unreleased** (do not merge/tag/deploy from this checklist alone)  
**Date:** 2026-08-15  
**Version:** 1.43.0 (development target — not tagged)  
**DB_VERSION:** 11 (unchanged — no schema, no new table/column/index)  
**Canonical base:** `main` / `origin/main` @ `b603907c37242e66610a7abad9fcb98c5ae0689a` (released baseline `v1.42.0` → `9b7e2e83b832426296f3026334e223de403c43e0`)

## Implementation Summary

**Branch:** `feature/m26-apply-replenishment-defaults-to-variations`  
**Plan:** `docs/milestones/m26-implementation-plan.md` (immutable; WP-M26-0 commit `677a108`)  
**PR:** draft [#35](https://github.com/magpern/wc-inventory-overview/pull/35), opened at freeze, not merged  
**Freeze tip (pre-evidence):** `d0f63f6`  
**GitHub Actions:** CI + Tests **pass** on PR #35 (PHP Parallel Lint, PHP lint and build ZIP, PHPUnit)

### Work Packages Completed

- **WP-M26-0:** Immutable plan materialized; M23 characterization suite green against pre-bulk production
- **WP-M26-1:** `apply_to_variations()` + shared qty normalization + idempotent counters + validation atomicity
- **WP-M26-2:** Classic WC bulk PHP seams + `wp_send_json({error})` failure exit
- **WP-M26-3:** Plugin-owned supplier modal + AJAX wrapper + 100-variation client preflight
- **WP-M26-4:** Layered security (owner / hook / WC AJAX nonce boundary)
- **WP-M26-5:** Downstream M22/M24/M25 proofs + full-path performance matrix
- **WP-M26-6:** Version `1.43.0`, `DB_VERSION` 11, CHANGELOG, this checklist
- **WP-M26-7:** Broad gates, draft PR, CI verification, Level A closure

### Implementation commits (after immutable plan)

| SHA | Subject |
|---|---|
| `677a108` | docs(m26): add immutable implementation plan |
| `bce7254` | test(m26): characterize M23 defaults before bulk-apply |
| `acb03ec` | feat(m26): add apply_to_variations with shared qty normalization |
| `88fbc85` | feat(m26): wire WC variation bulk-edit for replenishment defaults |
| `30939b8` | feat(m26): add variation bulk-apply UI and AJAX wrapper |
| `b12c30b` | test(m26): cover bulk-apply security at owner hook and AJAX boundaries |
| `4cbbbda` | test(m26): prove downstream consumption and record bulk-apply timings |
| `81ccf46` | docs(m26): bump 1.43.0 and Level A release-readiness checklist |
| `8158115` | fix(m26): allowlist get_current_screen and clean PHPCS on M26 files |
| `d0f63f6` | test(m26): reset commit seams and assert commit failure details |
| *(this)* | docs(m26): record Level A freeze evidence and CI-green draft PR #35 |

## Mandatory freeze documentation

### 1. Validation atomicity vs write atomicity

**PASS.** All predictable validation (parent identity, membership, caps, eligibility, qty rules, >100 targets, `edit_product` on parent and every target) completes before the first meta mutation. Predictable validation failures produce **zero writes**. M26 does **not** claim write atomicity or all-or-nothing mutation.

### 2. No compensating rollback

**PASS.** No DB transaction wrapping the bulk write; no restore of prior meta; no compensating rollback on mid-write failure. Earlier successful variation writes may remain.

### 3. Idempotent Set/Clear semantics

**PASS.** Already-equal Set and already-absent Clear are successful operations (WordPress `update_post_meta`/`delete_post_meta` returning `false` for no-ops is not treated as failure). Covered by AH–AL scenarios in owner tests.

### 4. Successful-operation counter semantics

**PASS.** Return keys remain `variations_updated`, `supplier_updates`, `qty_updates` (never `*_changed`). Counters count successful operations including idempotent no-ops.

### 5. >100 variation product limitation

**PASS.** Hard product limitation: client alerts on `data-total > 100` and cancels without AJAX; server rejects with the approved message and zero writes. Never silently processes only the first 100.

### 6. Full-path performance results

Measured path = `apply_to_variations()` (membership + per-target `edit_product` + normalize + meta), not a write-helper-only stub.

| N | Supplier wall | Supplier queries | Supplier meta writes | Qty wall | Qty queries | Qty meta writes |
|--:|-------------:|-----------------:|---------------------:|---------:|------------:|----------------:|
| 1 | 0.0076s | 7 | 1 | 0.0065s | 3 | 1 |
| 10 | 0.0275s | 25 | 10 | 0.0332s | 30 | 10 |
| 50 | 0.1291s | 105 | 50 | 0.1555s | 150 | 50 |
| 100 | 0.3442s | 205 | 100 | 0.5687s | 300 | 100 |

**Assessment:** Linear meta writes (= N). Query growth is approximately linear in N (≈2N+c supplier path; ≈3N qty path). No uncontrolled N+1 blowup vs the loose N=1×200 sanity bound. Wall times ≪ synchronous HTTP budget.

### 7. WC AJAX error-channel architecture

**PASS.** On M26 failure after identifying an M26 action: `wp_send_json( array( 'error' => $message ) )` exits before WC sync/`wp_die`. Plugin JS treats `response.error` as failure (show error, unblock, **no reload**). Success falls through to WC `WC_Product_Variable::sync` + empty response → `go_to_page(1)`.

### 8. Layered nonce evidence

| Layer | What is proven | Nonce claim? |
|---|---|---|
| 1 Owner `apply_to_variations` | Validation, caps, eligibility, isolation, counters, idempotency | **No** |
| 2 Plugin hook handler | Action gating, `$data` mapping, auth, `wp_send_json` error exit | **No** |
| 3 WC AJAX boundary | Missing/invalid `bulk-edit-variations` nonce rejected; valid nonce reaches M26 | **Yes — only here** |

### 9. Downstream regression evidence

| Consumer | Result |
|---|---|
| M22 `Reorder_Prefill_Service::resolve` | Prefills supplier + qty from bulk-applied variation meta |
| M24 `Replenishment_Planning_Service::build_plan` | Scoped plan groups include applied supplier + `qty_suggested` |
| M25 `Replenishment_Commit_Service::commit` | Draft PO line uses selected qty; variation identity preserved |

No M22/M24/M25 business-logic edits required.

### 10. Schema-diff result

**PASS.** `DB_VERSION` remains `'11'`. Diff vs canonical base on `includes/class-wc-inventory-overview-install.php` is **comment-only** (appends `/M26.` to the unchanged-version annotation). No CREATE/ALTER; no new tables/columns/indexes.

### 11. Version / DB_VERSION

- Plugin header + `WC_INVENTORY_OVERVIEW_VERSION`: **1.43.0**
- `WC_Inventory_Overview_Install::DB_VERSION`: **11**

### 12. BR-M26 verdicts

| ID | Verdict |
|---|---|
| BR-M26-1 … BR-M26-25 | **PASS** (see Level A matrix in freeze report / architecture tests) |

### 13. INV-M26 verdicts

| ID | Verdict |
|---|---|
| INV-M26-1 … INV-M26-18 | **PASS** |

### 14. Plan immutability

**PASS.** `docs/milestones/m26-implementation-plan.md` introduced only in `677a108`; subsequent commits must show zero plan-file content diffs (`git log -p -- docs/milestones/m26-implementation-plan.md` → single commit).

### 15. Roadmap closure status

**ROADMAP COMPLETE AFTER M26**

- M27 was **not** started (no branch, plan, or implementation).
- Former M27 / optional items remain **unnumbered evidence-gated backlog** only (advisory concurrency UI if incidents appear; hide variable-parent replenishment fields; future React variation editor; forecasting).

## Acceptance mechanism (honest)

No human manual browser acceptance was performed for this freeze. Evidence is automated PHPUnit (unit + integration + M26-specific + performance group) plus repository gates (lint/PHPCS/composer/compose/release-audit) and GitHub Actions on the draft PR.

## Explicit non-release confirmation

- [ ] / **DO NOT** merge the feature PR as part of this task's freeze ceremony without a separate release decision
- [x] **NOT** tagged `v1.43.0`
- [x] **NOT** published as a GitHub Release
- [x] **NOT** deployed
- [x] **NOT** starting M27
