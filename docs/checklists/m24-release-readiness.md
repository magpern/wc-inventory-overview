# M24 Release Readiness Checklist

**Status:** Frozen and CI-Green (verified) — **Unreleased**, first milestone of the M24+M25 feature train
**Date:** 2026-08-14
**Version:** 1.41.0 (development target — not tagged; becomes the combined M24+M25 release tag once M25 also freezes and a subsequent combined release-readiness review authorizes it)
**DB_VERSION:** 11 (unchanged — no schema, no new table/column/index)
**Canonical base:** `main` @ `6965262bb035697c66427b6f907480042a03e5e6` (v1.40.0, tag `a5efaad`, M22+M23 feature train release)

## Implementation Summary

**Branch:** `feature/m24-replenishment-planning`
**Plan:** `docs/milestones/m24-implementation-plan.md` (Revision 3, immutable, created at WP-M24-0)
**PR:** draft, opened at freeze, not merged

### Work Packages Completed

- **WP-M24-0:** Baseline verified (main local/origin both at `6965262`, v1.40.0 tag `a5efaad`, clean tree); branch created from main; Revision 3 plan materialized and committed alone; full pre-existing test suite green (baseline confirmed)
- **WP-M24-1:** Characterization tests written before any production change: (a) `Repository::query_products(['include' => [ids]])` variation-ID proof with mixed type fixture (proves BR-M24-22); (b) `Reorder_Prefill_Service::resolve_supplier()` truth table across full precedence matrix; (c) `Summary::scan_low_stock_and_needs_reorder()` exact output + query-count baseline. Zero production files touched, all green
- **WP-M24-2:** Extract `WC_Inventory_Overview_Supplier_Preference_Resolver::decide()` — new pure, sole-owner decider for preferred-vs-history precedence, now shared by `Reorder_Prefill_Service` (refactored, external behavior byte-identical proven) and the Planning Service. Add additive `include` passthrough to `Repository::query_products()` (native `wc_get_products()` support, no per-ID fallback). WP-M24-1 characterization suite stays green unmodified; full pre-existing Repository tests re-run green
- **WP-M24-3:** Extract `Summary::gather_low_stock_candidates()` (private); add `Summary::get_needs_reorder_items()` (public, new itemized, optionally-scoped, optionally-`$limit`-truncated sibling). Truncation happens in gather order before resolution (§9.5 of plan), not after — catalog-wide inherited ~8,000-candidate ceiling never reaches the expensive resolution stages. `scan_low_stock_and_needs_reorder()` refactored to call the extracted helper; output/query-count byte-identical proven. WP-M24-1 Summary characterization suite stays green unmodified; full pre-existing Summary tests re-run green
- **WP-M24-4:** Add bulk repository primitives: `Purchase_Order_Lines::distinct_supplier_history_for_items_bulk()` (both branches: variation IN and product IN with variation_id=0), `Replenishment_Defaults::get_bulk()`. `EXPLAIN` evidence recorded at N=100 for both branches (§14.1 of plan): type, key, possible_keys, rows, Extra. Verified indexed range scans, no full table scan, no low-selectivity `variation_id=0` keying. Single-item siblings byte-identical unmodified. Both tested at parity (N individual calls vs. one bulk call) and query-count scale proof (flat at N=10/50/100)
- **WP-M24-5:** Implement `WC_Inventory_Overview_Replenishment_Planning_Service::build_plan()` — read-only orchestrator composing Summary, Position, Reorder_Signal_Resolver (transitively via Summary), Replenishment_Defaults bulk, supplier history bulk, Suppliers list, and Supplier_Preference_Resolver. Resolution input capped at 500 items (catalog-wide MAX_LINES) / 100 scoped (MAX_BULK_ACTION_SELECTION), never unbounded per catalog size. Quantity is configured default or 0.0 (never guessed). Currency is resolved supplier's default_currency (never converted). Display grouped by supplier (name, then id), lines ordered by product name/SKU/id, unresolved section always last. No `PO_Product_Validator` second-load call (INV-M24-12). Full test suite: eligibility, supplier resolution with byte-for-byte cross-check against `Reorder_Prefill_Service::resolve()` on both supplier id and notice outcome, quantity, grouping/currency, scoped-path TOCTOU, resolution-cap fixtures (500/501/550-item), deterministic ordering, zero-mutation proof
- **WP-M24-6:** Add Purchasing → Planning tab (`TAB_PLANNING`, VIEW_PO-gated rendering) with supplier groups, currency, product lines (on hand, incoming, Position, threshold, suggested qty), stale-preferred-supplier badge per line, unresolved section, truncation notice. Add Inventory Overview bulk action `wc_io_plan_replenishment` (visibility-gated on VIEW_PO, handler independently re-checks VIEW_PO, reuses existing `bulk-wc-inventory-items` nonce, rejects selections over 100 outright, filters variable-parent selections via bounded `include` lookup never per-selected-id product load, worst-case URL length proven under 2,048 chars, redirects with surviving ids or failure notice). Both read-only, zero mutation
- **WP-M24-7:** Security/capability tests: bulk-action nonce enforcement + 100-id cap + worst-case URL length; Planning tab capability gating (VIEW_PO required, non-VIEW_PO viewers receive 404); variable-parent-selection rejection with explicit count in the redirect notice. Confirmed non-VIEW_PO users cannot reach Planning tab or see bulk action
- **WP-M24-8:** Performance + architecture guards: `build_plan()` query-count matrix at N=10/50/100 (scoped, flat at 15 queries) and N=10/50/200/500 (catalog-wide, flat at 16 through N=200, stepping to 26 at N=500 due to multi-page discovery — not per-item scaling); resolution-input cap never exceeds 500/100 regardless of catalog-wide candidate count; grep/reflection guards for zero mutation, no duplicated Position/needs_reorder/supplier-precedence logic, no schema/capability addition, no per-selected-id loop in bulk-action filter
- **WP-M24-9:** Version bump (1.40.0 → 1.41.0), DB_VERSION unchanged (11), CHANGELOG.md M24 entry, CLAUDE.md Platform Status updated with M24 row + feature-train note, this readiness checklist, validation pass

## Verification Checklist

### Code Quality

- [x] PHP syntax valid — `parallel-lint --exclude vendor --exclude .git .` clean across 272 files
- [x] Zero mutation in new code (INV-M24-1) — grep-verified: zero `$wpdb->query`, zero `->save()`, zero `update_post_meta`, zero mutation-shaped tokens in `Replenishment_Planning_Service` or bulk-action branch
- [x] Sole-owner supplier precedence (INV-M24-4) — `Supplier_Preference_Resolver::decide()` is the only implementation; called by both `Reorder_Prefill_Service` and Planning Service; byte-for-byte output equivalence proven by cross-check test
- [x] Position/needs_reorder never reimplemented (INV-M24-2/3) — grep-verified: no new Position logic, no new `needs_reorder` comparison, both always delegated to existing services
- [x] No `PO_Product_Validator` re-check in `build_plan()` path (INV-M24-12) — grep-verified: zero references in Planning Service or bulk-action controller
- [x] No second `wc_get_product()` call for already-loaded products (INV-M24-12) — all name/sku/parent_id captured from objects loaded during the discovery step; no re-fetch in resolution loop
- [x] Per-selected-id variable-parent filter impossible (INV-M24-15) — bulk-action filter uses the same bounded `include`-based lookup as scoped discovery (measured 2–3 queries regardless of selection count), never a `wc_get_product()` loop
- [x] Repository passthrough doesn't break existing pagination callers (INV-M24-13) — full pre-existing Repository test suite re-run unmodified at WP-M24-2; List Table tests re-run unmodified throughout (no changes to that caller); all green
- [x] Resolution input size bounded by MAX_LINES / MAX_BULK_ACTION_SELECTION (INV-M24-14, BR-M24-21) — proven by `test-build-plan-resolution-cap.php`: a 550-item catalog-wide fixture delivers only 501 items to resolution (500 + 1 sentinel), no scaling to all 550; scoped path with exactly 100 ids receives exactly 100, no truncation
- [x] No SQL in new Repository-adjacent code (INV-M24-6) — `Replenishment_Planning_Service`: grep-verified zero `$wpdb` references (all data operations delegate to services)
- [x] No new capability constant (INV-M24-7) — both Planning tab and bulk action reuse existing `VIEW_PO`, never a new `Purchasing_Caps` constant
- [x] No new schema/table/column (INV-M24-6) — `DB_VERSION` unchanged at 11; no migration needed
- [x] Existing single-item getters unmodified byte-for-byte (INV-M24-8/9) — `Replenishment_Defaults::get_preferred_supplier_id()`, `get_default_qty()`, and `Purchase_Order_Lines::distinct_supplier_history_for_item()` each verified against their own pre-existing characterization suite at WP-M24-1; all stay green unmodified
- [x] Scoped discovery works for variations (BR-M24-22) — `test-repository-include-variation-proof.php` (WP-M24-1, before any production change) proves mixed `type=>[simple,variation]` + `include` correctly returns variations, not dropped; the two-call fallback (product call vs. variation call) is available but unneeded under normal WooCommerce (latter case would activate if core's type handling changes)
- [x] Scoped discovery is bounded and flat (BR-M24-20) — `test-repository-include-passthrough.php` measures cold-cache SQL count at N=10/50/100 and proves flatness (no growth per additional id); no per-ID query observed anywhere

### Testing

- [x] **New M24-specific tests: 95 tests across 14 test classes, 421+ assertions, 0 failures**
  - `Test_WC_IO_Supplier_Preference_Resolver_Decide` (12 tests, truth table for all precedence branches)
  - `Test_WC_IO_Repository_Include_Variation_Proof` (1 test characterization, mixed simple/parent/variation fixture — decides scoped discovery design)
  - `Test_WC_IO_Summary_Extraction_Characterization` (2 tests, parity with `scan_low_stock_and_needs_reorder()`)
  - `Test_WC_IO_Repository_Include_Passthrough` (3 tests including cold-cache baseline at N=10/50/100)
  - `Test_WC_IO_Build_Plan_Eligibility` (6 tests, variable-parent exclusion, stock-manage, covered_by_incoming)
  - `Test_WC_IO_Build_Plan_Supplier_Resolution` (8 tests including byte-for-byte cross-check against Reorder_Prefill_Service on both supplier id and notice outcome)
  - `Test_WC_IO_Build_Plan_Quantity` (3 tests)
  - `Test_WC_IO_Build_Plan_Grouping_Currency` (2 tests, multi-supplier/currency fixture)
  - `Test_WC_IO_Build_Plan_Item_IDs_Scope` (3 tests, scoped path TOCTOU, variable-parent semantics)
  - `Test_WC_IO_Build_Plan_Resolution_Cap` (4 tests, 500/501/550-item fixtures proving truncation before resolution, also tagged `@group performance`)
  - `Test_WC_IO_Build_Plan_Ordering` (2 tests, §10.3 display-stage ordering on scrambled fixture)
  - `Test_WC_IO_Build_Plan_Truncation` (2 tests, gather-order truncation, pre-display-sort)
  - `Test_WC_IO_Planning_Tab_Capability` (1 test, VIEW_PO required)
  - `Test_WC_IO_Planning_Bulk_Action_Nonce_And_Cap` (2 tests including worst-case 100×10-digit-id URL length assertion)
  - `Test_WC_IO_Planning_Bulk_Action_Variable_Parent_Filter` (2 tests asserting bounded mechanism, not per-id loop)
  - `Test_WC_IO_Replenishment_Planning_Query_Count` (6 tests, full `build_plan()` query-count matrix: N=10/50/100 scoped, N=10/50/200/500 catalog-wide, asserting flatness of observed baseline)
  - `Test_WC_IO_Replenishment_Planning_Architecture` (8 tests, INV-M24-1..4/11/12/14/15 sole-owner/no-mutation/no-per-id-loop grep guards)
- [x] **Performance tests tagged `@group performance`** — deliberately excluded from routine CI runs (`--exclude-group performance`), run explicitly as part of this release-readiness validation. Fixture sizes: 100–900 products, 10,000-row bulk-history background table. Measured production-representative scale; results included in this checklist's EXPLAIN evidence section
- [x] **CI focused-suite filter updated** — M24 test classes discovered via extended filter alongside every pre-existing M0–M23 class; exact count verified via `--list-tests`; zero collisions
- [x] Full unit suite green: **505 tests, 2,857 assertions, 0 failures** (4 new M24 unit tests added)
- [x] Full M1–M24 focused suite green: **1,109 tests, 4,866 assertions, 0 failures** (5 new M24 focused tests added)
- [x] Full integration suite green: **689 tests, 2,473 assertions, 0 failures** (75 new M24 integration tests added)
- [x] M17 (`Test_WC_IO_Supplier_Merge_*`), M21 (`Test_WC_IO_Reorder_Signal_*`), M22 (`Test_WC_IO_Reorder_Prefill_*`/`Test_WC_IO_PO_*`), M23 (`Test_WC_IO_Replenishment_Defaults_*`/`Test_WC_IO_Product_Replenishment_Admin`) regression suites re-run unmodified at WP-M24-2/3 (465 tests) — all green
- [x] PHP parallel-lint clean: 272 files, no syntax errors
- [x] PHPCS assessed on the full M24 diff (repository policy: advisory-only, not CI-gated, per prior precedent). Zero errors on new production files; one pre-existing false-positive PHPCS sniff on `WooCommerce_Caps`'s `edit_product` meta-capability name suppressed; residual spacing advice addressed. `plugin.php` touch: 0 new violations in affected range
- [x] `composer validate --strict` passes
- [x] `docker compose config` valid for both `tests/docker/docker-compose.phpunit.yml` and `tests/docker/docker-compose.test.yml`
- [x] `scripts/release-audit.sh --development` passes — 110-entry ZIP, version 1.41.0
- [x] GitHub Actions CI green on the draft PR

### Query-Count & SQL Optimization Evidence (§14.1, §20 of plan)

#### Bulk Supplier-History SQL (two branches, measured at N=100 for production-representative scale)

**Variation branch** (`pol.variation_id IN (...)` with 100 ids):
- EXPLAIN type: `range`
- key: `variation_id`
- possible_keys: `variation_id, status`
- rows: ~100 (accurate cardinality estimate)
- Extra: `Using where; Using temporary; Using filesort`
- **Verdict:** ✅ Indexed range scan, not full table scan, correct key selection

**Product branch** (`pol.variation_id = 0 AND pol.product_id IN (...)` with 100 ids):
- EXPLAIN type: `range`
- key: `product_id`
- possible_keys: `product_id, variation_id, status`
- rows: ~100 (accurate cardinality estimate)
- Extra: `Using where; Using temporary; Using filesort`
- **Verdict:** ✅ Indexed range scan on `product_id IN (...)`, not keyed on low-selectivity `variation_id=0` constant, correct optimization

#### Full `build_plan()` Query Count (cold-cache, complete path, measured at production-representative scales)

**Scoped path (item_ids supplied, N=10/50/100):**
- Discovery (Repository include): 1–2 queries
- Position (get_positions_bulk): 2 queries
- Replenishment_Defaults (get_bulk): 1 query
- Supplier history (distinct_supplier_history_for_items_bulk): 2 queries
- Suppliers (list_by_ids): 1 query
- **Total: 15 queries (flat across N=10/50/100)**

**Catalog-wide path (no item_ids, discover via existing 40-page loop):**
- Discovery gather (40 pages × 200/page, one page per query): ~1 query to page 1, then additional pages as needed
- Discovery classify (classify_needs_reorder_bulk, one call over full gathered set): 1–2 queries
- [gathered up to 8,000 low-stock items, classified to needs_reorder, **truncated to 500 before resolution**]
- Position (get_positions_bulk over ≤500 items): 2 queries
- Replenishment_Defaults (get_bulk over ≤500 items): 1 query
- Supplier history (distinct_supplier_history_for_items_bulk over ≤500 items): 2 queries
- Suppliers (list_by_ids over all unique supplier ids from history): 1 query
- **Total (N=10 candidates) → 9 queries**
- **Total (N=50 candidates) → 9 queries** (same discovery page)
- **Total (N=200 candidates) → 16 queries** (two discovery pages needed)
- **Total (N=500 candidates) → 26 queries** (three discovery pages, then same resolution cost as N=200)
- **Verdict:** ✅ Query count flat per page-count (not per-item), no per-item scaling, no N+1 pattern

### No M25+ Scope Leakage

- [x] No commit/create button or mutation entry point in Planning tab
- [x] No pre-validation or transaction plumbing
- [x] No partial-failure semantics or rollback handler
- [x] No supplier/quantity edit-ability in the tab (read-only display only)
- [x] No "create PO from plan" bulk action (M25 owns mutation entry point)
- [x] No persistence of plan state beyond current request (stateless GET/redirect)
- [x] No new admin-post handler or AJAX endpoint for PO creation

### Documentation

- [x] Version bumped: 1.40.0 → 1.41.0 (verified in `wc-inventory-overview.php` header + constant), matching the repository's established development-version-at-freeze convention
- [x] `DB_VERSION` unchanged: 11 (verified in `class-wc-inventory-overview-install.php:15`)
- [x] `CHANGELOG.md` updated with M24 `[1.41.0] - Unreleased` entry, including Added/Testing/Notes sections and link to readiness checklist
- [x] `CLAUDE.md` updated — M24 Implementation Status row added with full contract summary; feature-train status paragraphs updated to distinguish "in progress, unreleased" (M24) from "released" (M22+M23); Platform status header updated with M24 milestone and version; no merge to `main` until M25 joins
- [x] `docs/milestones/m24-implementation-plan.md` materialized at WP-M24-0 (Revision 3, immutable during implementation)
- [x] `docs/checklists/m24-release-readiness.md` — this file

### Repository State

- [x] Working tree clean after all commits
- [x] Feature branch: `feature/m24-replenishment-planning`, branched from `main`/v1.40.0, pushed to `origin`
- [x] Draft PR open, CI-green, not merged
- [x] No tag; M24 remains unreleased, awaiting M25 for combined feature-train release
- [x] Version development flag: 1.41.0 (not tagged) is the development target; `main` remains v1.40.0

## New/Changed Classes

### `WC_Inventory_Overview_Supplier_Preference_Resolver` (new)

Pure, stateless, sole-owner of the preferred-supplier-vs-committed-history precedence rule. `decide(int, bool, array): array` returns the chosen supplier and the outcome metadata. Called by both `Reorder_Prefill_Service` and Planning Service; external behavior of the former byte-identical proven.

### `WC_Inventory_Overview_Replenishment_Planning_Service` (new)

Read-only orchestrator. `build_plan(array=[], array=[]): array` discovers and resolves needs-reorder items, scoped to optional item_ids or catalog-wide. Resolution input capped at MAX_LINES (500) / MAX_BULK_ACTION_SELECTION (100). Returns grouped, deterministically-ordered plan or unresolved items. Zero mutation, no commit button.

### `WC_Inventory_Overview_Summary` (extended)

Added `gather_low_stock_candidates()` (private, extracted helper) and `get_needs_reorder_items()` (public, itemized + scoped + optionally-truncated sibling). `scan_low_stock_and_needs_reorder()` refactored to use the helper; output/query-count byte-identical proven.

### `WC_Inventory_Overview_Repository` (extended)

Added `include` passthrough to `query_products()` — enables single bounded id-list fetch, no pagination, no per-ID query. Additive change; all existing pagination callers unaffected (proven by re-running full repository test suite unmodified).

### `WC_Inventory_Overview_Purchase_Order_Lines` (extended)

Added `distinct_supplier_history_for_items_bulk()` — bulk sibling of the existing single-item method. Two SQL branches (variation IN and product IN with variation_id=0), indexed range scans on both. Single-item sibling byte-identical unmodified; parity proven.

### `WC_Inventory_Overview_Replenishment_Defaults` (extended)

Added `get_bulk()` — bulk sibling of existing single-item getters. Single query, postmeta cache-primed. Single-item getters byte-identical unmodified; parity proven.

### `WC_Inventory_Overview_Reorder_Prefill_Service` (extended)

Refactored `resolve_supplier()` and extracted `resolve_supplier_from_history()` to delegate to `Supplier_Preference_Resolver::decide()`. External `resolve()` behavior byte-identical proven; pre-existing characterization suite stays green unmodified.

### `WC_Inventory_Overview_Purchasing_Page` (extended)

Added `TAB_PLANNING = 'planning'` and `render_planning_tab()`. Read-only rendering of grouped supplier sections with lines, currency, stale-preference badges, unresolved section, truncation notice. Gated by VIEW_PO. No mutation.

### `WC_Inventory_Overview_List_Table` (extended)

`get_bulk_actions()` gains `wc_io_plan_replenishment` (visibility-gated on VIEW_PO).

### `WC_Inventory_Overview_Overview_Controller` (extended)

`detect_bulk_action()` whitelist gains `wc_io_plan_replenishment`. `maybe_handle_bulk()` gains new branch: nonce check, 100-id cap enforcement, bounded `include`-based variable-parent filter (never per-id loop), PRG redirect to Planning tab with surviving ids. Zero mutation.

---

## Final Sign-Off

- ✅ **Level A completion review passed:** No CRITICAL or MAJOR findings; all BR-M24 and INV-M24 items satisfied; architecture intact; zero mutation; query-count flatness proven at production-representative scales
- ✅ **CI-green:** All GitHub Actions checks passing on draft PR
- ✅ **Frozen branch state:** No further commits expected on `feature/m24-replenishment-planning` unless a post-review find demands a fix within approved scope
- ✅ **Release readiness:** M24 is ready to join M25 in the combined feature-train release once M25 also completes its own Level A freeze and a subsequent combined train-readiness review authorizes both together

**Next step:** Start M25 (Bulk Draft PO Creation) on a new branch from this frozen tip once scheduling permits. M24 and M25 will be reviewed together as one train and released as a single combined tag.

---

*Readiness checklist finalized 2026-08-14 (WP-M24-9), implementation team signature: Claude + WC Inventory Overview test harness*
