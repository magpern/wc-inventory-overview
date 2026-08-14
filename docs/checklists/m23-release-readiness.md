# M23 Release Readiness Checklist

**Status:** Frozen and CI-Green (verified) — **Unreleased**, second and final milestone of the M22+M23 feature train
**Date:** 2026-08-14
**Version:** 1.40.0 (intermediate development version — not tagged; becomes the combined M22+M23 release tag once a subsequent combined release-readiness review authorizes it)
**DB_VERSION:** 11 (unchanged — both new values are ordinary product/variation postmeta)
**Canonical base:** `feature/m22-reorder-draft-po-quick-action` @ `1716e3231d6caa229c5bf25ab6c98471c0f05cf7` (M22, frozen, unreleased) — **not `main`**

## Implementation Summary

**Branch:** `feature/m23-replenishment-defaults`
**Plan:** `docs/milestones/m23-implementation-plan.md` (immutable, created at WP-M23-0)
**PR:** draft, opened at freeze, not merged

### Work Packages Completed

- **WP-M23-0:** Baseline re-verified (M22 branch local/origin both at `1716e323`, `main`/`origin/main` untouched at `7f300d55`, no prior M23 artifacts); branch created from M22's frozen tip (not `main`); plan materialized and committed alone
- **WP-M23-1:** M22 characterization suite (`Test_WC_IO_M22_Supplier_Fallback_Characterization`, 9 tests) written before any production change, pinning `resolve_supplier()`'s exact history-branch behavior, notice text, and measured query counts, and `render_line_row()`'s `qty_ordered ?? '1'` default
- **WP-M23-2:** `WC_Inventory_Overview_Replenishment_Defaults` — sole-owner postmeta persistence/validation class for the two new keys; 31 tests (architecture, validation, persistence round-trips including parent/variation independence)
- **WP-M23-3:** `WC_Inventory_Overview_Product_Replenishment_Admin` — WooCommerce Product Data/Variation panel integration; 13 tests including a 3+-variation indexed-field independence proof, an >20-supplier dropdown-truncation regression guard, and the "(unavailable)" silent-clobber guard
- **WP-M23-4:** `Reorder_Prefill_Service::resolve_supplier()` restructured to check the preferred supplier first (`resolve_supplier_from_history()` extracted, byte-for-byte unchanged); quantity default flows into the prefilled line; 16 tests
- **WP-M23-5:** Edge cases (non-stock-managed, variable parent, deleted product, cross-item leakage) + full M17/M21/M22 regression re-run unmodified (307 tests total in that pass, all green)
- **WP-M23-6:** 2-axis query-count performance matrix (0/1/10/50 historical suppliers × unconfigured/valid/stale preference); 4 tests
- **WP-M23-7:** Full validation, CI filter update, version bump, documentation, freeze

## Verification Checklist

### Code Quality

- [x] PHP syntax valid — `parallel-lint --exclude vendor --exclude .git .` clean across 247 files
- [x] Zero mutation in `Reorder_Prefill_Service` (INV-M23-3) — extended (not weakened) M22's own `test_zero_mutation_shaped_tokens`-style guard; new `test_prefill_service_still_never_writes_meta` additionally asserts no `save_preferred_supplier(`/`save_default_qty(` call
- [x] Sole meta-key ownership (INV-M23-12) — grep-verified: no file other than `Replenishment_Defaults` references either `_wc_io_preferred_supplier_id` or `_wc_io_default_replenishment_qty` literal
- [x] No SQL in either new class (INV-M23-13) — grep-verified zero `$wpdb` references
- [x] No new admin-post/AJAX handler, no new public hook/filter (INV-M23-15) — grep-verified
- [x] No new capability constant (INV-M23-20) — both new classes gate on WooCommerce core's own `current_user_can( 'edit_product', $id )`, never `Purchasing_Caps`; verified identical at render and save; a filtered-away `Purchasing_Caps::EDIT_PO` has zero effect on this surface (`test_purchasing_caps_filter_has_no_effect_on_this_surface`)
- [x] No schema change (INV-M23-19) — `DB_VERSION` unchanged at 11; no new table/column/index/option
- [x] Variable parents never store or consult replenishment defaults (INV-M23-10) — identity validation rejects a variable parent before any supplier/quantity resolution runs, even when defaults happen to be stored under its post id
- [x] M22 fallback preserved byte-for-byte when unconfigured (INV-M23-7/18) — measured query count with no preference configured is exactly M22's own baseline (3, including the position query) at every historical-supplier scale
- [x] Preferred-supplier eligibility never bypasses M17 (INV-M23-5) — archived/merged/deleted preferences all fall back to history, verified individually
- [x] Stale preferred supplier never auto-cleared or repointed at a merge target (INV-M23-6) — verified storage is unchanged after rendering a stale preference
- [x] List-table query count unaffected (INV-M23-16) — `list-table.php` untouched by M23 (structural guarantee); its own M21/M22 query-count regression tests re-run unmodified at WP-M23-5 and stayed green
- [x] Supplier-resolution query count bounded and scale-invariant on all three preference branches (INV-M23-17/18) — proven at 0/1/10/50 historical suppliers
- [x] Silent-clobber regression guarded — a stored-but-now-ineligible preferred supplier renders as an explicit "(unavailable)" option, and an unrelated field save cannot wipe it (unchanged resubmission is a no-op)
- [x] Index-arrayed variation field convention correct — a 3+-variation save writes each row to its own item, never collapsing to the last row's value

### Testing

- [x] **77 new M23-specific tests, 354 assertions, 0 failures** (exact per-class counts verified via `--list-tests`): `Test_WC_IO_M22_Supplier_Fallback_Characterization` (9), `Test_WC_IO_Replenishment_Defaults_Architecture` (8), `Test_WC_IO_Replenishment_Defaults_Validation` (18, includes data-provider cases), `Test_WC_IO_Replenishment_Defaults_Persistence` (5), `Test_WC_IO_Product_Replenishment_Admin` (9), `Test_WC_IO_Replenishment_Defaults_Capability` (4), `Test_WC_IO_Preferred_Supplier_Prefill` (9), `Test_WC_IO_Default_Quantity_Prefill` (7), `Test_WC_IO_Replenishment_Defaults_Edge_Cases` (4), `Test_WC_IO_Replenishment_Defaults_Performance` (4)
- [x] CI focused-suite filter updated (`Test_WC_IO_M22_Supplier_Fallback_Characterization`, `Test_WC_IO_Replenishment_Defaults_`, `Test_WC_IO_Product_Replenishment_Admin`, `Test_WC_IO_Preferred_Supplier_Prefill`, `Test_WC_IO_Default_Quantity_Prefill`); discovery proven via `--list-tests` — all 10 M23 test classes discovered under the extended filter alongside every pre-existing M0–M22 class, zero collisions
- [x] Full unit suite green: **501 tests, 2839 assertions, 0 failures**
- [x] Full M1–M23 focused suite green: **1104 tests, 4848 assertions, 0 failures**
- [x] Full integration suite green: **614 tests, 2052 assertions, 0 failures**
- [x] M17 (`Test_WC_IO_Supplier_Merge_*`), M21 (`Test_WC_IO_Reorder_Signal_*`), M22 (`Test_WC_IO_Reorder_Prefill_*`/`Test_WC_IO_PO_*`) regression suites re-run unmodified at WP-M23-5 (307 tests) — all green
- [x] PHP parallel-lint clean: 247 files, no syntax errors
- [x] PHPCS assessed on the full M23 diff (repository policy: advisory-only, not CI-gated, per M22's own precedent). Zero errors remaining after fixes (one Yoda-condition violation and two missing one-line docblocks fixed manually; nine spacing/alignment violations auto-fixed via `phpcbf`) on both new production files; four residual warnings on `Product_Replenishment_Admin` are PHPCS's static capability-name sniff not recognizing WooCommerce's own `edit_product` meta capability — a false positive, not a defect. `reorder-prefill-service.php`/`wc-inventory-overview.php`: 0 violations in the touched ranges. `plugin.php`/`install.php`: 0 new violations attributable to M23's one-line additions (confirmed by line-number attribution against the pre-existing full-file report)
- [x] `composer validate --strict` passes
- [x] `docker compose config` valid for both `tests/docker/docker-compose.phpunit.yml` and `tests/docker/docker-compose.test.yml`
- [x] `scripts/release-audit.sh --development` passes — 108-entry ZIP, version 1.40.0
- [x] GitHub Actions CI green on the draft PR

### No M24+ scope leakage

- [x] No parent-to-variation inheritance (each variation stores its own defaults independently; a variable parent's identity is rejected before defaults are ever consulted)
- [x] No "apply to all variations" bulk convenience action
- [x] No target/par-stock-level quantity semantic, no forecasting/velocity/safety-stock/EOQ calculation, no MOQ/pack-size/case-quantity concept
- [x] No automatic supplier/quantity "learning" beyond M22's existing static history heuristic
- [x] No new `Purchasing_Caps` constant

### Documentation

- [x] Version bumped: 1.39.0 → 1.40.0 (verified in `wc-inventory-overview.php` header + constant), matching the repository's established intermediate-development-version-at-freeze convention
- [x] `DB_VERSION` unchanged: 11 (verified in `class-wc-inventory-overview-install.php:15`, comment extended to `...M21/M22/M23`)
- [x] `CHANGELOG.md` updated with M23 `[1.40.0] - Unreleased` entry
- [x] `CLAUDE.md` updated — M23 Implementation Status row added (🧊 Frozen — Unreleased); feature-train status paragraph updated to name both M22 and M23; "Canonical published baseline"/"Platform status" headers deliberately left unchanged (`main` remains v1.38.0, neither milestone merged)
- [x] `docs/milestones/m23-implementation-plan.md` materialized at WP-M23-0, immutable since, not modified during implementation
- [x] `docs/checklists/m23-release-readiness.md` — this file

### Repository State

- [x] Working tree clean after all commits
- [x] Feature branch: `feature/m23-replenishment-defaults`, branched from M22's frozen tip, pushed to `origin`
- [x] Draft PR open, CI-green
- [x] No merge, tag, or deployment; M22's own draft PR also remains unmerged

## New/Changed Classes

### `WC_Inventory_Overview_Replenishment_Defaults` (new)

Sole-owner persistence/validation for `_wc_io_preferred_supplier_id`/`_wc_io_default_replenishment_qty` postmeta. `get_preferred_supplier_id()`/`get_default_qty()` (plain reads, no eligibility check — that's a use-time concern of the caller). `save_preferred_supplier()` (save-time eligibility validation + the stale-resubmit no-op exception). `save_default_qty()` (numeric/`>0`/4dp validation, reusing `PO_Quantities`' own rule).

### `WC_Inventory_Overview_Product_Replenishment_Admin` (new)

Wires `woocommerce_product_options_stock_fields`/`woocommerce_process_product_meta` (simple products) and `woocommerce_product_after_variable_attributes`/`woocommerce_save_product_variation` (variations, index-arrayed field names). Hand-rolled `<select>`/`<input>` markup (not `woocommerce_wp_select()`/`woocommerce_wp_text_input()`, which live in WC's admin-only bootstrap and are unavailable under the WP PHPUnit harness) mirroring `PO_Admin`'s own established pattern. Injects an "(unavailable)" option for a stale stored preference. Gated by `current_user_can( 'edit_product', $id )`, identical at render and save.

### `WC_Inventory_Overview_Reorder_Prefill_Service` (extended)

`resolve_supplier()` restructured: checks `Replenishment_Defaults::get_preferred_supplier_id()` first; a valid, currently-eligible preference is used directly (history query never runs); a stale one falls back to the extracted, byte-for-byte-unchanged `resolve_supplier_from_history()` plus a new distinct notice. `resolve()`'s `'prefilled'` branch also populates `line['qty_ordered']` from `Replenishment_Defaults::get_default_qty()` when configured. External `resolve()` contract shape (four statuses, four keys) unchanged.
