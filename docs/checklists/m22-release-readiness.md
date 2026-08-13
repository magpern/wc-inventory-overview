# M22 Release Readiness Checklist

**Status:** Frozen and CI-Green (verified) — **Unreleased**, first milestone of the M22+M23 feature train
**Date:** 2026-08-13
**Version:** 1.39.0 (intermediate development version — not tagged; joins the combined M22+M23 release once M23 also freezes)
**DB_VERSION:** 11 (unchanged)
**Canonical base:** `main` @ `7f300d556911960faa89d05d02fb8889c1a07992` (v1.38.0, released)

## Implementation Summary

**Branch:** `feature/m22-reorder-draft-po-quick-action`
**Plan:** `docs/milestones/m22-implementation-plan.md` (immutable, created at WP-M22-0)
**PR:** draft, opened at freeze, not merged

### Work Packages Completed

- **WP-M22-0:** Baseline re-verified (`main`==`origin/main`==`7f300d55`, clean tree, no prior M22/M23 artifacts); branch created from `main`; plan materialized and committed alone
- **WP-M22-1:** Repository layer — `Purchase_Order_Lines::distinct_supplier_history_for_item()` (committed-status filter, index-aware dual-path query) and `Suppliers::list_by_ids()`/`is_eligible_for_selection()` (bulk fetch); 18 tests including 0/1/10/50-scale query-count proofs
- **WP-M22-2:** `WC_Inventory_Overview_Reorder_Prefill_Service::resolve()` — the five-state (`malformed`/`invalid`/`stale`/`prefilled`) reorder-prefill contract; 16 tests including a fixed-query-count proof for the `prefilled` path at 0/1/10/50 historical suppliers
- **WP-M22-3:** New PO screen prefill wiring — `PO_Admin::reorder_prefill_url()`, `render_detail()`/`render_header_fields()`/`render_lines_editor()` extended; `render_line_row()` untouched; 10 rendering tests
- **WP-M22-4:** Inventory Overview "Create Draft PO" quick-action link — `render_reorder_signal_badge()` takes `WC_Product`, new `render_reorder_action_link()`; 7 tests including a zero-query-delta proof at 5/60-product scale
- **WP-M22-5:** End-to-end GET-prefill → POST-submit round trip through the unmodified `handle_save()`/`PO_Service::create_draft()` pipeline; tamper/TOCTOU resistance tests; 7 tests
- **WP-M22-6:** Architecture guards + consolidated capability matrix; 10 tests
- **WP-M22-7:** Full validation, CI filter update, version bump, documentation, freeze

## Verification Checklist

### Code Quality

- [x] PHP syntax valid — `parallel-lint --exclude vendor --exclude .git .` clean across 235 files
- [x] Zero mutation introduced (INV-M22-7/8) — verified by grepping the full M22 production/test diff for mutation-shaped tokens (`set_stock_quantity`, `update_option`, `update_post_meta`, `wp_insert_post`, `->save()`): zero matches (`test_zero_mutation_shaped_tokens`)
- [x] Sole PO-creation mutation ownership (INV-M22-3) — `PO_Service::create_draft()` is byte-for-byte unmodified; no new code calls `Purchase_Orders::create_draft()`/`Purchase_Order_Lines::create()` directly; no new `admin_post_*`/`wp_ajax_*` handler registered (`PO_Admin::init()` still registers exactly its pre-existing seven actions, verified by direct extraction)
- [x] Sole classifier ownership preserved (INV-M22-1) — `Reorder_Prefill_Service` calls `Reorder_Signal_Resolver::resolve()`, never reimplements `position <= threshold`; M21's own sole-classifier allowlist extended to include the new service (one legitimate additional caller, guarded invariant unchanged)
- [x] Sole Position-calculator ownership preserved (INV-M22-2) — `Reorder_Prefill_Service` calls `Inventory_Position_Service::get_position()`, never sums `on_hand + incoming` itself; M7/M21's sole-calculator allowlist extended likewise (discovered and fixed during the full unit-suite run)
- [x] No new capability constant (INV-M22-14) — every new capability check routes through `Purchasing_Caps::current_user_can( Purchasing_Caps::EDIT_PO )`, never a raw `current_user_can('manage_woocommerce')` string; `EDIT_PO` already existed and already governs `handle_save()`
- [x] No SQL in rendering/controller layer (INV-M22-10) — `PO_Admin`/`List_Table` contain zero `$wpdb` references (grep-verified, both files)
- [x] No schema change (INV-M22-11) — `DB_VERSION` unchanged at 11; no new table/column/index/option
- [x] Variable parents never become PO lines (INV-M22-12) — structural exclusion (the action link's own render path is never reached for a variable-parent rollup row) plus defensive rejection via the unmodified `PO_Product_Validator`
- [x] No new public hook/filter introduced (INV-M22-9)
- [x] List-table query count unchanged (INV-M22-13/BR-M22-15) — the action link derives entirely from the already-loaded `WC_Product` object and the already-computed `needs_reorder` boolean; zero-delta proven at 5- and 60-product scale
- [x] Supplier resolution is fixed at 2 queries, never N+1 (INV-M22-16) — proven at 0/1/10/50 distinct-historical-supplier scale, at both the repository-method level and the full `Reorder_Prefill_Service::resolve()` level
- [x] Stale-state fail-closed behavior (BR-M22-7) — a no-longer-`needs_reorder` outcome discards the reorder-specific prefill entirely (`line: null`, no supplier query issued), not merely re-shown
- [x] GET/POST isolation (INV-M22-15) — `handle_save()` never reads `wc_io_ro_*`; a full GET-prefill → POST-submit round trip proves the created draft's line matches only what was actually submitted, never a GET-smuggled value
- [x] Edit-PO screen isolation (BR-M22-11) — reorder-prefill GET params are consulted only when rendering `action=new`; the edit screen with the same params present is unaffected

### Testing

- [x] **69 new M22-specific tests, 197 assertions, 0 failures**: `Test_WC_IO_Purchase_Order_Lines_Supplier_History` (10), `Test_WC_IO_Suppliers_Eligibility_And_Bulk_Fetch` (8), `Test_WC_IO_Reorder_Prefill_Service` (16), `Test_WC_IO_PO_Admin_Reorder_Prefill_Rendering` (11), `Test_WC_IO_List_Table_Reorder_Action_Link` (7), `Test_WC_IO_Reorder_Prefill_Security_Toctou` (7), `Test_WC_IO_Reorder_Prefill_Architecture` (7), `Test_WC_IO_Reorder_Prefill_Capability_Matrix` (3)
- [x] CI focused-suite filter updated (`Test_WC_IO_Reorder_Prefill_`, `Test_WC_IO_Purchase_Order_Lines_Supplier_History`, `Test_WC_IO_List_Table_Reorder_Action_Link`; `Test_WC_IO_PO_Admin_Reorder_Prefill_Rendering`/`Test_WC_IO_Suppliers_Eligibility_And_Bulk_Fetch` already covered by the pre-existing `Test_WC_IO_PO_`/`Test_WC_IO_Suppliers_` entries); discovery proven via `--list-tests` (70 M22-related test methods discovered under the extended filter)
- [x] Full unit suite green: **475 tests, 2598 assertions, 0 failures**
- [x] Full M1–M22 focused suite green: **1027 tests, 4476 assertions, 0 failures**
- [x] Full integration suite green: **563 tests, 1921 assertions, 0 failures**
- [x] PHP parallel-lint clean: 235 files, no syntax errors
- [x] PHPCS assessed on the full M22 diff (repository policy: advisory-only, not CI-gated — the CI `lint` job runs `parallel-lint` only, confirmed by reading `.github/workflows/tests.yml`; `.phpcs-baseline.xml` is an aspirational, currently-empty ratchet file not wired into any comparison tooling). Production-file delta: **`class-wc-inventory-overview-reorder-prefill-service.php`, `class-wc-inventory-overview-po-admin.php`, `class-wc-inventory-overview-purchase-order-lines.php`, `wc-inventory-overview.php`: 0 new violations** (the latter three had zero to begin with); `class-wc-inventory-overview-list-table.php`: 3 cosmetic docblock-alignment violations introduced by the rewritten `render_reorder_signal_badge()` docblock, fixed proactively (net 0 remaining in the touched range, confirmed by line-range attribution against the full-file report); `class-wc-inventory-overview-suppliers.php`: 0 new violations (both new methods clean; all reported violations attributed by line-range to pre-existing code outside the inserted range). Test-file violations follow the exact same categories/density already present in every pre-existing test file in this repository (missing docblocks, `file_get_contents()` warnings, `class-` file-naming convention) — not a regression, consistent with established (if imperfect) repo convention for test files.
- [x] `composer validate --strict` passes
- [x] `docker compose config` valid for both `tests/docker/docker-compose.phpunit.yml` and `tests/docker/docker-compose.test.yml`
- [x] `scripts/release-audit.sh --development` passes (see run output at freeze)
- [x] GitHub Actions CI green on the draft PR

### No M23 scope leakage

- [x] No persistent preferred/default-supplier setting introduced (supplier resolution is always derived transitively from PO history at render time)
- [x] No persistent restock-quantity/par-level setting introduced (quantity is always merchant-supplied)
- [x] No bulk "create drafts for all needs-reorder items" action
- [x] No retrofit of the 4 pre-existing duplicated supplier-eligibility predicates in `PO_Service`/`Goods_Receipt_Service` (only the new 5th call site uses the new shared `is_eligible_for_selection()` helper)

### Documentation

- [x] Version bumped: 1.38.0 → 1.39.0 (verified in `wc-inventory-overview.php` header + constant), matching the repository's own established intermediate-development-version-at-freeze convention (confirmed via `CLAUDE.md`'s release-history text before bumping)
- [x] `DB_VERSION` unchanged: 11 (verified in `class-wc-inventory-overview-install.php:15`)
- [x] `CHANGELOG.md` updated with M22 `[1.39.0] - Unreleased` entry
- [x] `CLAUDE.md` updated — M22 Implementation Status row added (🧊 Frozen — Unreleased); one status paragraph added mirroring the pre-merge phrasing pattern prior in-progress milestones used on their own feature branches; "Canonical published baseline"/"Platform status" headers deliberately left unchanged (`main` remains v1.38.0, unmerged)
- [x] `docs/milestones/m22-implementation-plan.md` materialized at WP-M22-0, immutable since, not modified during implementation
- [x] `docs/checklists/m22-release-readiness.md` — this file

### Repository State

- [x] Working tree clean after all commits
- [x] Feature branch: `feature/m22-reorder-draft-po-quick-action`, pushed to `origin`
- [x] Draft PR open, CI-green
- [x] No merge, tag, or deployment

## New/Changed Classes

### `WC_Inventory_Overview_Reorder_Prefill_Service` (new)

Read-only orchestrator implementing the five-state reorder-prefill contract (`resolve( int $product_id, int $variation_id = 0 ): array`). Composes, never reimplements: `PO_Product_Validator::validate()` (identity), `Settings::get_effective_low_stock_amount()` + `Inventory_Position_Service::get_position()` + `Reorder_Signal_Resolver::resolve()` (TOCTOU re-derivation), `Purchase_Order_Lines::distinct_supplier_history_for_item()` + `Suppliers::list_by_ids()` + `Suppliers::is_eligible_for_selection()` (supplier resolution).

### `WC_Inventory_Overview_Purchase_Order_Lines` (extended)

New `distinct_supplier_history_for_item( int $product_id, int $variation_id = 0 ): int[]` — one query, committed-status filter (`placed`/`partially_received`/`received`/`closed_short`), index-aware dual-path (product-id vs. variation-id) matching the existing `list_open_lines_for_*` precedent.

### `WC_Inventory_Overview_Suppliers` (extended)

New `list_by_ids( array $ids ): array` (bulk fetch, one query regardless of count) and `is_eligible_for_selection( array $supplier ): bool` (active + not-merged predicate, mirroring the 4 existing inline call sites without retrofitting them).

### `WC_Inventory_Overview_PO_Admin` (extended)

New `reorder_prefill_url()`. `render_detail()` gains reorder-prefill resolution gated by `Purchasing_Caps::EDIT_PO`; `render_header_fields()`/`render_lines_editor()` gain trailing prefill parameters threaded into their existing empty-state branches. `render_line_row()`, `handle_save()`, and every other method: unmodified.

### `WC_Inventory_Overview_List_Table` (extended)

`render_reorder_signal_badge()` now takes `WC_Product $item` (its one call site updated); new `render_reorder_action_link( WC_Product $item ): string`.
