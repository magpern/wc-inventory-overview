# M21 Release Readiness Checklist

**Status:** Frozen and CI-Green (verified)
**Date:** 2026-08-13
**Version:** 1.38.0
**DB_VERSION:** 11 (unchanged)
**Canonical base:** `main` @ `153e4d8de233dc2ae9b89d5f189e97a5136d9b72` (v1.37.0, released)

## Implementation Summary

**Branch:** `feature/m21-position-aware-reorder-signal`
**Plan:** `docs/milestones/m21-implementation-plan.md` (immutable, created at WP-M21-0)
**PR:** [#27](https://github.com/magpern/wc-inventory-overview/pull/27) (draft, CI-green, not merged)

### Work Packages Completed

- **WP-M21-0:** Baseline re-verified (`main`==`origin/main`==`153e4d8d`, clean tree, no prior M21 artifacts); branch created from `main`; plan materialized and committed alone
- **WP-M21-1:** `WC_Inventory_Overview_Reorder_Signal_Resolver` implemented (sole classifier, `needs_reorder`/`covered_by_incoming`); 12 unit tests (7 resolver + 5 architecture)
- **WP-M21-2:** List-table row-level integration — `render_direct_stock_badges_inner()`/`render_direct_stock_badges()`/`render_status_badges()` gain an optional `$position_map` parameter; `get_row_state_classes()` extended; `Overview_Controller::ajax_save_inline_stock()`'s badge-refresh response builds its own single-item, capability-gated Position lookup
- **WP-M21-3:** Variable-parent rollup — `compute_variable_aggregate()` gains `n_in_needs_reorder` counter and child-badge, per-child classification only (INV-8)
- **WP-M21-4:** `Summary::build()` needs_reorder classification — `count_low_sellable_lines()` folded into `scan_low_stock_and_needs_reorder()`; new `classify_needs_reorder_bulk()` helper (sole caller of `get_positions_bulk()` on Summary's behalf)
- **WP-M21-5:** `get_low_stock_lines_for_chart()` row extension; Dashboard KPI card + two new table columns; Overview summary card — all `manage_woocommerce`-gated
- **WP-M21-6:** Consolidated capability-matrix regression pass (AJAX badge refresh + variable-parent negative case; static capability-consistency guards)
- **WP-M21-7:** CI filter update, version bump, documentation, full validation, freeze

## Verification Checklist

### Code Quality

- [x] PHP syntax valid (`php -l` clean on every touched/new file; parallel-lint clean across 226 files)
- [x] Zero mutation introduced (INV-M21-1) — verified by grepping the entire production diff for mutation-shaped tokens (`set_stock_quantity`, `update_option`, `update_post_meta`, `->save()`, `->insert(`, `->update(`, `->delete(`, `wp_insert_post`, `wp_update_post`): zero matches
- [x] Sole classifier ownership (INV-M21-2) — only `class-wc-inventory-overview-summary.php` and `class-wc-inventory-overview-list-table.php` call `Reorder_Signal_Resolver::resolve()` directly; `overview-controller.php`/`dashboard-controller.php` only consume already-classified values, never reimplement the comparison
- [x] Threshold-source parity (INV-M21-3) — every `resolve()` call paired, in the same scope, with a `Settings::get_effective_low_stock_amount()`-derived value; no second threshold source anywhere
- [x] No new capability constant (INV-M21-5) — every new `current_user_can()` call introduced by this milestone checks `manage_woocommerce` exclusively (grep-verified across the full diff: 3 new checks, all `manage_woocommerce`)
- [x] Variable-parent semantics (INV-M21-7) — `n_in_needs_reorder` is a per-child count only; no parent-level Position/threshold comparison anywhere
- [x] `Inventory_Position_Service`/`Inventory_Position_Resolver` completely unmodified (not present in the diff)
- [x] No new hook, AJAX action, or admin-post handler introduced (grep-verified: zero `add_action`/`add_filter`/`admin_post_`/`wp_ajax_` additions)
- [x] No accidental M22 scope — no PO/receipt-creation call introduced anywhere
- [x] No unrelated G2 fix — `ajax_save_inline_stock()`'s `stock_qty`/`wc_stock_amount()`/`set_stock_quantity()` handling is byte-identical to v1.37.0; only the `badgesHtml` call site changed
- [x] Existing `low_stock` value/population unchanged (BR-M21-7) — proven by baseline-delta unit tests
- [x] Dashboard chart payload backward compatible (BR-M21-8) — `Dashboard_Charts_Data` still consumes only `name`/`qty`, dedicated regression test passes

### Testing

- [x] **45 new M21-specific tests**, 0 failures: `Test_WC_IO_Reorder_Signal_Resolver` (7), `Test_WC_IO_Reorder_Signal_Architecture` (8), `Test_WC_IO_Reorder_Signal_List_Table` (8), `Test_WC_IO_Reorder_Signal_Capability_Matrix` (3), `Test_WC_IO_Summary_Needs_Reorder` (10), `Test_WC_IO_Summary_Query_Count` (2), `Test_WC_IO_Dashboard_Reorder_Surfaces` (4), `Test_WC_IO_Overview_Summary_Cards_Reorder` (3)
- [x] CI focused-suite filter updated (`Test_WC_IO_Reorder_Signal_`, `Test_WC_IO_Summary_`, `Test_WC_IO_Overview_Summary_Cards_`; `Test_WC_IO_Dashboard_Reorder_Surfaces` already covered by the pre-existing `Test_WC_IO_Dashboard_` entry); discovery proven via `--list-tests`
- [x] Full unit suite green: **468 tests, 2551 assertions, 0 failures**
- [x] Full M1–M21 focused suite green: **958 tests, 4271 assertions, 0 failures**
- [x] Full integration suite green: **501 tests, 1763 assertions, 0 failures**
- [x] PHP parallel-lint clean: 226 files, no syntax errors
- [x] PHPCS advisory delta assessed (repository policy: advisory-only, not CI-gated) — production-file delta across the 5 touched files: **+7 errors / +21 warnings** (dashboard-controller.php +0/+5, install.php +0/+0, list-table.php +7/+10, overview-controller.php +0/+3, summary.php +0/+3), confirmed by direct pre/post comparison against the `main`-baseline blobs. Zero security/escaping/nonce/SQL-related sniff violations in the delta (grep-verified) — purely stylistic (doc-comment/spacing), consistent with this repository's established "PHPCS advisory-only" policy (`docs/architecture-audit.md`)
- [x] `composer validate --strict` passes
- [x] `docker compose config` valid for both `tests/docker/docker-compose.phpunit.yml` and `tests/docker/docker-compose.test.yml`
- [x] `scripts/release-audit.sh --development` passes (105-entry ZIP built successfully, version 1.38.0 confirmed)
- [x] GitHub Actions CI green on PR #27: PHP Parallel Lint (pass), PHP lint and build ZIP (pass), PHPUnit (pass)

### Recalibrated (not weakened) pre-existing tests

- `tests/integration/inventory-position/test-inventory-position-list-table.php::test_position_query_count_bounded_for_twenty_plus_rows` (M3): bound `<=2` → `<=4`. `List_Table::prepare_items()` now makes two independent, individually-bounded bulk Position lookups per page load (`Summary::build()`'s store-wide `needs_reorder` scan, and the pre-existing page-scoped `position_map`) that cannot be merged — they operate over genuinely different candidate scopes (catalog-wide vs. current-page). The guarded invariant (fixed cost, independent of N, no per-row queries) is unchanged; only the numeric ceiling reflects this legitimate additive cost.
- `tests/unit/expected-delivery/test-expected-delivery-architecture.php::test_only_list_table_and_expected_delivery_service_call_position_service` (M7, discovered during the full unit suite run): allowlist extended with `class-wc-inventory-overview-summary.php` and `class-wc-inventory-overview-overview-controller.php` — two legitimate new `Inventory_Position_Service::` callers, neither becomes a second calculator (both consume `get_positions_bulk()`'s result exactly as `List_Table` already did).

### Documentation

- [x] Version bumped: 1.37.0 → 1.38.0 (verified in `wc-inventory-overview.php` header + constant)
- [x] `DB_VERSION` unchanged: 11 (verified in `class-wc-inventory-overview-install.php:15`)
- [x] `CHANGELOG.md` updated with M21 `[1.38.0]` entry (comprehensive summary + Added/Changed/Testing/Notes)
- [x] `CLAUDE.md` updated — M21 Implementation Status row added (frozen, unreleased); one status paragraph added mirroring the exact pre-merge phrasing pattern M20 used on its own feature branch; "Canonical published baseline"/"Platform status" headers deliberately left unchanged (`main` remains v1.37.0, unmerged)
- [x] `docs/milestones/m21-implementation-plan.md` materialized at WP-M21-0, immutable since, not modified during implementation
- [x] `docs/checklists/m21-release-readiness.md` — this file

### Repository State

- [x] Working tree clean after all commits
- [x] Feature branch: `feature/m21-position-aware-reorder-signal`, pushed to `origin`
- [x] Draft PR #27 open, CI-green
- [x] No merge, tag, or deployment

## New/Changed Classes

### `WC_Inventory_Overview_Reorder_Signal_Resolver` (new)

**File:** `includes/class-wc-inventory-overview-reorder-signal-resolver.php`
**Method:** `resolve( float $position, float $threshold ): array{needs_reorder: bool, covered_by_incoming: bool}`

### `WC_Inventory_Overview_Summary` (extended)

`count_low_sellable_lines()` → `scan_low_stock_and_needs_reorder()`; new `classify_needs_reorder_bulk()`; `build()` gains `needs_reorder`; `get_low_stock_lines_for_chart()` rows gain `position`/`incoming`/`needs_reorder`/`covered_by_incoming`.

### `WC_Inventory_Overview_List_Table` (extended)

`render_direct_stock_badges_inner()`/`render_direct_stock_badges()`/`render_status_badges()` gain optional `$position_map` parameter; `render_reorder_signal_badge()` (new); `get_row_state_classes()` extended (`wc-io-needs-reorder`); `compute_variable_aggregate()` gains `n_in_needs_reorder`.

### `WC_Inventory_Overview_Overview_Controller` (extended)

`ajax_save_inline_stock()`'s badge-refresh response; `render_summary_cards()` gains the "Needs Reorder" card; new `product_on_hand_qty()` helper.

### `WC_Inventory_Overview_Dashboard_Controller` (extended)

KPI array gains "Needs Reorder"; `render_dashboard_operational_panels()`'s table gains two columns.

## Manual/Browser Acceptance

Not performed — no dev-environment browser automation was built or exercised for this milestone, per the implementation instructions ("do not spend substantial time building browser automation... automated PHP/integration/runtime evidence is sufficient"). All contracts are covered by the automated PHPUnit integration-test suite (real WordPress admin/AJAX runtime execution against a full WordPress+WooCommerce test install via `tests/docker/`), which exercises real rendering paths (`render_inventory_profit_shell()`, `display_rows()`, `ajax_save_inline_stock()`) end-to-end, including capability boundaries.

## Definition of Done

- [x] Implementation of WP-M21-0 through WP-M21-7 complete, matching BR-M21-1 through BR-M21-12 and INV-M21-1 through INV-M21-8
- [x] Level A completion review performed against the immutable plan; zero defects found
- [x] All local validation gates green (unit/focused/integration/lint/composer/docker-config/release-audit)
- [x] GitHub Actions CI green
- [x] Documentation complete and accurate
- [x] Not merged, tagged, released, or deployed
