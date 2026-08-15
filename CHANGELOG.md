# Changelog — WC Inventory Overview

## [1.42.0] - Unreleased

**Milestone M25 — Bulk Draft PO Creation.** Second and final milestone of the M24+M25 feature train (unreleased; joins a combined release once a post-freeze M24+M25 review authorizes it — no tag, no GitHub Release, no deploy for M25 standalone). Closes the loop M24 deliberately left open: an operator reviews the Replenishment Planning screen, edits/selects quantities, and creates one Draft PO per supplier group in a single confirm action. The plugin's first multi-record mutation milestone, and its first use of MariaDB advisory locks (`GET_LOCK`/`RELEASE_LOCK`) — a new, schema-free, item-level locking primitive that, combined with a new bulk conflicting-open-line check, genuinely serializes concurrent commits and prevents an immediate retry from duplicating a line. **Zero schema change (`DB_VERSION` stays 11), zero new public API, zero new capability** (reuses `Purchasing_Caps::EDIT_PO`, the same one `PO_Service::create_draft()`'s own submission handler already requires). `Replenishment_Planning_Service`, `Supplier_Preference_Resolver`, `PO_Service`, `Purchase_Orders`, `PO_Events`, `PO_Product_Validator`, and `DB_Transaction` all remain byte-identical to the M24 frozen tip.

### Added

- **`WC_Inventory_Overview_Replenishment_Item_Lock`** — new advisory-lock primitive (`acquire()`/`release()`), the plugin's first use of MariaDB's `GET_LOCK`/`RELEASE_LOCK`. Locks are named `wc_io_replen_item_<item_post_id>`, always acquired in ascending numeric order; a lock that can't be acquired within a bounded timeout (default 5s) is simply absent from the returned set — never a hard batch failure. Session-scoped: MySQL auto-releases on connection close, so a crashed request can never leave an orphaned lock.
- **`WC_Inventory_Overview_Purchase_Order_Lines::list_open_or_draft_item_ids_bulk()`** — new additive bulk-read method (existing single-item/bulk-history siblings unchanged): returns item ids that already have at least one PO line in the exact frozen conflict-status set (`draft`, `placed`, `partially_received` — deliberately including `draft`, unlike the M3 "open incoming" queries). One bulk query per non-empty branch, never per-item.
- **`WC_Inventory_Overview_Replenishment_Commit_Service::commit()`** — new pure orchestrator (no `$wpdb`, no `PO_Product_Validator` call, no `Replenishment_Defaults` writes, no duplicated Position/needs-reorder/supplier-precedence logic). Enforces the 100-line cap, normalizes/dedupes/sorts submitted identities to a canonical `item_post_id` before any lock acquisition, acquires item-level locks, rebuilds the plan with exactly one scoped `build_plan()` call, runs the bulk conflicting-line check, groups survivors by fresh `supplier_id` ascending, and calls the existing, unmodified `PO_Service::create_draft()` once per group — quantities always mapped to identities by exact canonical key, never array position. Every acquired lock is released via `finally`, unconditionally, on every code path including a thrown exception. Returns `WP_Error` only for service-boundary/shape violations (`wc_io_replen_commit_too_many`, `wc_io_replen_commit_malformed`); every other outcome — including individual per-group `create_draft()` failures — lands in the `{created, failed, skipped}` array shape, with skip reasons `not_found`, `no_supplier`, `multiple_suppliers`, `no_longer_needs_reorder`, `concurrent_commit_in_progress`, and `already_has_open_po_line`.
- **`WC_Inventory_Overview_Replenishment_Commit_Admin`** — new admin controller (mirrors `Goods_Receipt_Admin`'s dedicated-class precedent) registering `admin_post_wc_io_replenishment_commit`: capability (`EDIT_PO`) → nonce → one-shot request token (`PO_Request_Token`, context `replenishment_commit`) → two-phase request-shape parsing (filter to selected rows first; a selected row with an invalid quantity gets a PRG notice, never `wp_die`; a structurally malformed request `wp_die(400)`s) → delegates to `Replenishment_Commit_Service::commit()` → an opaque, per-user, read-once result transient (`wc_io_replen_result_{user_id}_{result_id}`) → PRG redirect to the Planning tab.
- **Planning tab commit form** (`WC_Inventory_Overview_Purchasing_Page::render_planning_tab()`, additive-only) — every line gets a checkbox (defaulting **unchecked**), an editable quantity input pre-filled from `qty_suggested`, and a per-group "select all" that clamps to remaining global capacity so it cannot push the selection past 100 (UX only — the server remains sole authority). Rendered only for `EDIT_PO`; a `VIEW_PO`-only viewer sees exactly M24's original read-only table. A created/failed/skipped summary renders at the top of the tab when a `wc_io_commit_result` transient is present, resolved strictly against the *current* user's own id.

### Testing

- New test directories `tests/unit/replenishment-commit/` and `tests/integration/replenishment-commit/`: characterization of `create_draft()`'s fallback behavior and `PO_Request_Token` context-isolation (written first, WP-M25-1); the exact frozen conflict-status set (draft/placed/partially_received block, received/cancelled/closed_short don't); simple-product/variation/multi-supplier-group/forged-field happy paths; the full eligibility/skip contract (variable-parent, unresolved, no-longer-needs-reorder, not-found, stale-preferred-supplier fallback); immediate-retry and genuine dual-connection concurrent-commit duplicate-prevention tests (a real second MySQL session, not merely a sequential simulation); the two test-only failure-injection seams (Seam A: one group's `create_draft()` genuinely fails on its own after a supplier is archived mid-commit, other groups unaffected; Seam B: a catastrophic interruption after one group succeeds leaves that PO intact, later groups never attempted, and every acquired item lock is proven free via an independent connection's `IS_FREE_LOCK` — not same-connection re-`GET_LOCK`, which is session-reentrant); a crafted-POST security matrix (forged supplier/currency fields, variable-parent/unresolved/qty rejections, token replay, duplicate identities, unchecked-row discard, reordered-row quantity mapping, VIEW_PO-only and `edit_products`-only denial) plus capability/nonce/token isolation; Planning-tab form capability gating, select-all remaining-capacity clamp, and per-user result-transient scoping; and a query-count/wall-clock performance matrix at 1/1 through 100/100 lines/suppliers (tagged `performance`, excluded from the default filter).
- **100/100 lines/suppliers worst case measured at 1.31s total wall time** (100 `GET_LOCK`/100 `RELEASE_LOCK` round trips, 1,317 total queries, 100 POs created; fixture integrity: 100 created / 0 skipped / 0 failed) — comfortably under the ~10s synchronous-HTTP-request budget. Per §24's decision rule: **MAX_COMMIT_GROUPS NOT REQUIRED — measured evidence supports no additional cap.** Full matrix and decision recorded in `docs/checklists/m25-release-readiness.md`.
- Full pre-existing M17/M22/M24 regression suites re-run unmodified and stayed green; two pre-existing M24 tests that had asserted "no commit form/button exists yet" (an M24-era placeholder for exactly this milestone's own scope) were updated to assert the new, intended M25 contract instead.

### Notes

- Implementation (WP-M25-1 through WP-M25-8) complete on `feature/m25-bulk-draft-po-creation`. CI status and Level A freeze evidence live in `docs/checklists/m25-release-readiness.md` once WP2–WP4 complete. **Unreleased** — the M24+M25 train releases once, together, after a subsequent combined release-readiness review.

## [1.41.0] - Unreleased

**Milestone M24 — Replenishment Planning Screen.** First milestone of the M24+M25 feature train (unreleased; joins a combined release once M25 also freezes — no tag, no GitHub Release, no deploy for M24 standalone). Closes the read-side bulk gap left by M22/M23: nothing previously showed a merchant *all* current needs-reorder items, who to buy each from, and how much, in one place. Adds a read-only, catalog-wide (or selection-scoped) Replenishment Planning screen that bulk-resolves Position, supplier, and suggested quantity for every current needs-reorder item and groups results by supplier. **Zero schema change (`DB_VERSION` stays 11), zero mutation of any kind, zero new capability** (reuses `Purchasing_Caps::VIEW_PO`), zero new public API. Bulk *creation* of draft POs is deliberately **not** part of M24 — reserved for M25.

### Added

- **`WC_Inventory_Overview_Supplier_Preference_Resolver::decide()`** — new pure, stateless decider extracted from `Reorder_Prefill_Service`'s supplier-resolution logic, now the sole implementation of the preferred-supplier-vs-committed-history precedence rule, shared by both `Reorder_Prefill_Service` (refactored to delegate, external behavior unchanged) and the new Planning Service.
- **`WC_Inventory_Overview_Repository::query_products()`** gains an additive `include` passthrough — a single bounded product/variation fetch by id list, no pagination math, no per-ID query. Proven (characterization test, before any production code relied on it) that a mixed `type => [simple, variation]` + `include` query correctly returns simple products and variations, and never returns a variable parent.
- **`WC_Inventory_Overview_Summary::gather_low_stock_candidates()`** (new, private) and **`get_needs_reorder_items()`** (new, public) — the shared low-stock/needs-reorder gather+classify pipeline, extracted from `scan_low_stock_and_needs_reorder()` (output/query-count byte-identical, unmodified) and extended with an itemized, optionally id-scoped, optionally `$limit`-truncated sibling. Truncation happens in gather order, before resolution — the inherited ~8,000-candidate catalog-wide ceiling never reaches the expensive resolution stages.
- **`WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_items_bulk()`** and **`WC_Inventory_Overview_Replenishment_Defaults::get_bulk()`** — new additive bulk-read primitives, at most 2 and 1 queries respectively regardless of item count. `EXPLAIN` evidence (measured at production-representative table volume) confirms both supplier-history branches resolve to indexed range scans, never a full table scan or a low-selectivity `variation_id=0` key.
- **`WC_Inventory_Overview_Replenishment_Planning_Service::build_plan()`** — new read-only orchestrator composing `Summary::get_needs_reorder_items()`, `Inventory_Position_Service` (transitively, via Summary), `Reorder_Signal_Resolver` (transitively), `Replenishment_Defaults::get_bulk()`, `Purchase_Order_Lines::distinct_supplier_history_for_items_bulk()`, `Suppliers::list_by_ids()`, and `Supplier_Preference_Resolver::decide()`. Resolution input is capped at 500 items catalog-wide (`MAX_LINES`) / 100 scoped (`MAX_BULK_ACTION_SELECTION`), regardless of catalog size. Quantity is the configured default or `0.0`, never guessed. Currency is the resolved supplier's own `default_currency`, never converted or blended. Display is grouped by supplier (name, then id), lines ordered by product name/SKU/id, unresolved section always last.
- **Purchasing → Planning tab** (`WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING`) — read-only rendering of supplier groups, currency, product/variation lines (on hand, incoming, Position, threshold, suggested quantity), a stale-preferred-supplier badge per line, an unresolved section, and a truncation notice. Requires `VIEW_PO`. No commit/create button — M25 owns that.
- **Inventory Overview bulk action** `wc_io_plan_replenishment` — offered only to `VIEW_PO`-capable viewers (visibility only; the handler independently re-checks `VIEW_PO`). Reuses the existing `bulk-wc-inventory-items` nonce (no new nonce). Rejects selections over 100 ids outright (never silently truncates). Filters out variable-parent selections via the same bounded `include` lookup as scoped discovery (never a per-selected-id product load), counts them, and redirects to the Planning tab with the surviving ids — or back to Inventory Overview with a failure notice if every selected id was a variable parent.

### Testing

- New test directories `tests/unit/replenishment-planning/` and `tests/integration/replenishment-planning/`: the `Supplier_Preference_Resolver::decide()` truth table; the repository include/variation-proof characterization that decided the scoped-discovery design; `Summary` extraction characterization (parity with `scan_low_stock_and_needs_reorder()`, gather-order truncation, scoped-path TOCTOU/variable-parent semantics); bulk repository primitive parity + query-count + `EXPLAIN` evidence; `build_plan()`'s full contract (eligibility, supplier resolution with a byte-for-byte cross-check against `Reorder_Prefill_Service::resolve()` on both resolved supplier id and semantic notice/outcome, quantity, grouping/currency, item_ids scope, resolution-cap at 500/501/550-item fixtures, deterministic display ordering, zero-mutation proof); Planning tab rendering/capability/visibility; bulk-action redirect/nonce/100-id-cap/URL-length/variable-parent-filter; a full `build_plan()` query-count matrix at N=10/50/100 (scoped, flat at 15 queries) and N=10/50/200/500 (catalog-wide, flat at 16 queries through N=200, stepping to 26 at N=500 due to needing three discovery pages instead of one — not per-item scaling); an architecture-guard suite for zero mutation, no duplicated Position/needs_reorder/supplier-precedence logic, no `PO_Product_Validator`/second product load, no schema/capability addition, and no per-selected-id loop in the bulk-action filter.
- A small number of new tests (tagged the new PHPUnit `performance` group) deliberately build large fixtures (100-900 products, a 10,000-row bulk-history background table) for production-representative `EXPLAIN`/query-count evidence; excluded from the default quick-start filter and from CI's routine integration-suite run (`--exclude-group performance`), run explicitly as part of this milestone's own release-readiness validation pass.
- Full pre-existing `Reorder_Prefill_Service`/`Summary`/Overview/list-table/capability-matrix regression suites re-run unmodified and stayed green throughout — see `docs/checklists/m24-release-readiness.md` for full validation-pass evidence.

### Notes

- Level A completion review passed; see `docs/checklists/m24-release-readiness.md`. **Frozen, CI-green, unreleased** — the M24+M25 train releases once, together, after a subsequent combined release-readiness review.

## [1.40.0] - Unreleased

**Milestone M23 — Replenishment Defaults.** Second and final milestone of the M22+M23 feature train (unreleased; joins a combined release once a post-freeze M22+M23 review authorizes it — no tag, no GitHub Release, no deploy for M23 standalone). Makes M22's "Create Draft PO" quick-action prefill materially faster for repeat purchasing by letting a merchant configure, per product/variation, an explicit preferred supplier and/or a fixed default replenishment quantity — both optional, both suggestions only, both fully editable before a PO is ever created. **Zero schema change (`DB_VERSION` stays 11 — both values are ordinary WordPress product/variation postmeta), zero new mutation entry point (WooCommerce's own product/variation save lifecycle is the only new mutation surface, using WooCommerce's own nonces), zero new public API, zero new capability** (uses WooCommerce core's own `edit_product` gate, not `Purchasing_Caps` — this is a product-editing action, not a purchasing one). `Reorder_Prefill_Service` remains strictly read-only; `PO_Service::create_draft()` remains the sole PO-creation mutation owner, unmodified. With no defaults configured, M22's own supplier-history behavior — including its fixed, non-scaling 2-query cost — is preserved byte-for-byte.

### Added

- **`WC_Inventory_Overview_Replenishment_Defaults`** — new sole-owner persistence class for two new postmeta keys, `_wc_io_preferred_supplier_id` and `_wc_io_default_replenishment_qty`, keyed on the concrete purchasable item's own post id (a variation's own id, never its parent's — no parent-to-variation inheritance). `save_preferred_supplier()` validates the chosen supplier is currently eligible (active, not merged) at save time, with one deliberate exception: resubmitting the value already stored is always accepted as a no-op even if that supplier has since become ineligible, closing a silent-clobber hazard a naive active-suppliers-only dropdown would otherwise create. `save_default_qty()` reuses `PO_Quantities`' own `qty_ordered` rule (numeric, `> 0`, up to 4 decimals, no upper bound); blank clears either field.
- **`WC_Inventory_Overview_Product_Replenishment_Admin`** — new admin integration adding "Preferred supplier" and "Default replenishment quantity" fields to the WooCommerce Product Data → Inventory tab (simple products) and the standard variation panel (variations, using WooCommerce's own index-arrayed field-naming convention). A stored-but-now-ineligible preferred supplier is rendered as an explicit "(unavailable)" option rather than silently vanishing from the dropdown. Gated by WooCommerce's own `current_user_can( 'edit_product', $id )` at both render and save — identical check, no asymmetry.
- **`Reorder_Prefill_Service::resolve()`'s `'prefilled'` branch**: a configured, currently-eligible preferred supplier is used directly and M22's committed-history query never runs at all; a configured-but-stale preferred supplier falls back to the unchanged M22 history algorithm plus a distinct notice; with nothing configured, behavior (including query count) is byte-for-byte M22's own. A configured default quantity populates the prefilled line's `qty_ordered`; absent one, the ordinary `'1'` default is unaffected. The external `resolve()` contract shape (four statuses, four keys) is unchanged.

### Testing

- **77 new M23-specific tests, 354 assertions, 0 failures**, across `tests/unit/replenishment-defaults/` and `tests/integration/replenishment-defaults/`: a characterization suite pinning M22's exact pre-M23 supplier-fallback behavior (notice text, query counts) written *before* any production change; persistence round-trips proving parent/variation independence; save-time/use-time supplier-eligibility matrix (archived/merged/deleted/nonexistent, plus the stale-resubmit no-op exception); quantity validation table (zero/negative/non-numeric/decimal/no-upper-bound); product/variation configuration UI round trips including a 3+-variation indexed-field independence proof and a >20-supplier dropdown-truncation regression guard; capability parity (render/save identical, `Purchasing_Caps` filter changes have zero effect on this surface); a full GET-prefill → POST-submit round trip proving a submitted quantity overrides the prefilled default; a 2-axis query-count matrix (0/1/10/50 historical suppliers × unconfigured/valid/stale preference) proving the resolution cost stays fixed and invariant with respect to historical-supplier count on every branch, and that the unconfigured branch matches M22's own measured baseline exactly.
- Extends (without weakening) M22's own `test_zero_mutation_shaped_tokens`-style read-only guard on `Reorder_Prefill_Service`, and adds a new sole-meta-key-ownership grep guard for the two new postmeta keys.
- Full unit/M1–M23-focused/integration suites green, 0 failures; full M17 (`Supplier_Merge`), M21 (`Reorder_Signal`), and M22 (`Reorder_Prefill`/`PO_`) regression suites re-run unmodified and stayed green.

### Notes

- Level A completion review passed; see `docs/checklists/m23-release-readiness.md`. **Frozen, CI-green, unreleased** — the M22+M23 train releases once, together, after a subsequent combined release-readiness review.

## [1.39.0] - Unreleased

**Milestone M22 — Reorder → Draft Purchase Order Quick Action.** First milestone of the M22+M23 feature train (unreleased; joins a combined release once M23 also freezes — no tag, no GitHub Release, no deploy for M22 standalone). Bridges M21's read-only reorder classification to the existing Purchase Order workflow: a "Create Draft PO" quick action on an already-`needs_reorder` Inventory Overview row navigates (plain `GET`, no nonce, nothing mutated) to the existing New PO screen, server-side prefilled with the product/variation and — where unambiguous — a supplier derived from the item's own committed purchase history. **Zero schema change (`DB_VERSION` stays 11), zero new mutation entry point, zero new public API, zero new capability** (reuses the existing `Purchasing_Caps::EDIT_PO`). `PO_Service::create_draft()` remains the sole PO-creation mutation owner, completely unmodified; the reorder-prefill GET parameters are pure UX hints re-validated from scratch on every render and never consulted by the submission/validation pipeline.

### Added

- **`WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product_id, $variation_id = 0 )`** — new read-only orchestrator implementing a five-state contract (`'malformed'|'invalid'|'stale'|'prefilled'`). Re-validates product/variation identity via the unmodified `PO_Product_Validator::validate()` (the same validator `PO_Service::create_draft()` itself uses at submit time) and re-derives `needs_reorder` from scratch via the unmodified M21 primitives (`Settings::get_effective_low_stock_amount()`, `Inventory_Position_Service::get_position()`, `Reorder_Signal_Resolver::resolve()`) — never trusting the originating badge or URL (TOCTOU). A no-longer-`needs_reorder` outcome discards the reorder-specific prefill entirely (fail closed) rather than re-showing stale state.
- **`WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_item()`** — new repository query: distinct supplier IDs with committed (`placed`/`partially_received`/`received`/`closed_short`; `draft` and `cancelled` excluded) purchase history for one item, most-recent order first. Uses the existing single-column indexes on `product_id`/`variation_id`; no schema change.
- **`WC_Inventory_Overview_Suppliers::list_by_ids()`** / **`is_eligible_for_selection()`** — new bulk supplier fetch (one query regardless of count) and the shared active/not-merged eligibility predicate, so supplier resolution for the New PO prefill costs a fixed 2 queries independent of how many suppliers have ever sold the item (proven at 0/1/10/50-supplier scale).
- **Inventory Overview list table**: a "Create Draft PO" link on any row already showing "Needs reorder" (never on `covered_by_incoming` or variable-parent rollup rows), gated by `Purchasing_Caps::EDIT_PO` — visible only to viewers who could actually complete the action, independent of the pre-existing `manage_woocommerce` badge gate. Adds zero SQL query of its own (derived entirely from the already-loaded product object and the already-computed `needs_reorder` boolean).
- **New PO screen**: when reached via the quick action, the product/variation and (0/1/many-eligible-supplier-aware) supplier field are prefilled; an informational or warning notice explains any non-prefilled outcome (invalid link, invalid item, no-longer-needs-reorder, no/multiple eligible suppliers). Quantity, unit cost, and supplier SKU are never prefilled — the merchant sees exactly the same starting values (`1`/`0`/empty) as any ordinary new PO line. The Edit PO screen is entirely unaffected by these parameters.

### Testing

- **69 new M22-specific tests, 0 failures**, across `tests/unit/reorder-prefill/` and `tests/integration/reorder-prefill/`: repository query and bulk-fetch correctness plus fixed-query-count proofs at 0/1/10/50 scale; `Reorder_Prefill_Service::resolve()`'s five status branches and supplier-eligibility matrix; New PO rendering for each status case; the list-table link's presence/absence matrix and zero-query-delta proof at 5/60-product scale; a full GET-prefill → POST-submit round trip through the unmodified `handle_save()`/`PO_Service::create_draft()` pipeline; tamper/TOCTOU resistance (mismatched identity, stock changes between render and click, malicious input); architecture guards (no reimplementation of M21's classifier or M3's Position arithmetic, zero mutation-shaped tokens, zero SQL in `PO_Admin`/`List_Table`, every new capability check routes through `Purchasing_Caps`); a consolidated capability matrix across `EDIT_PO`/`VIEW_PO`-only/neither viewer types.
- Extends two pre-existing sole-owner architecture guards (M21's `Reorder_Signal_Resolver` allowlist, M7/M21's `Inventory_Position_Service` caller allowlist) to include the new service as a legitimate additional caller — both guarded invariants unchanged, only the allowlists grow.
- Full unit/M1–M22-focused/integration suites green, 0 failures.

### Notes

- Level A completion review passed; see `docs/checklists/m22-release-readiness.md`. **Frozen, CI-green, unreleased** — joins a combined M22+M23 release once M23 also freezes.

## [1.38.0] - 2026-08-13

**Milestone M21 — Position-Aware Reorder Signal.** First functional milestone since the M18–M20 Admin Controller Decomposition program completed. Distinguishes, for products/variations already flagged "Low stock" by the plugin's original on-hand-vs-threshold rule, whether they still genuinely need reordering once already-open incoming purchase-order supply is taken into account — completing D13's frozen promise ("low-stock and incoming are composable states, shown simultaneously") which had been only half-honored since M3 shipped Inventory Position without ever cross-referencing it against the original low-stock badge. **Zero schema change (`DB_VERSION` stays 11), zero mutation (100% read-only), zero new public API, zero new capability, zero new AJAX/admin-post endpoint.** New pure classifier `WC_Inventory_Overview_Reorder_Signal_Resolver::resolve( $position, $threshold ): {needs_reorder, covered_by_incoming}` (sole owner of this comparison) is layered on top of the existing, unmodified Low-stock rule and Inventory Position service — never replacing either, never introducing a second threshold. Every new surface is gated by `manage_woocommerce`, mirroring the capability boundary already governing Incoming/Position elsewhere in the plugin; `edit_products`-only viewers see every touched screen exactly as in v1.37.0. Level A completion review passed; see `docs/checklists/m21-release-readiness.md`.

### Added

- **`WC_Inventory_Overview_Reorder_Signal_Resolver`** — new pure, stateless classifier class (mirrors `Inventory_Position_Resolver`'s style). `needs_reorder = (position <= threshold)`; `covered_by_incoming = !needs_reorder`.
- **Inventory Overview list table**: an already-low-stock row (simple product or variation) additionally shows a "Needs reorder" or "Covered by incoming" badge alongside (never instead of) the existing "Low stock" badge, for `manage_woocommerce` viewers; a new `wc-io-needs-reorder` row CSS state class. Variable-parent rows gain a parallel `n_in_needs_reorder` rollup counter and child-badge, following the exact pattern already used for the existing `n_in_low` counter — classified per child variation only, never against a synthetic parent-level Position (INV-8).
- **Inventory Overview summary cards**: a new "Needs Reorder" card, visible only to `manage_woocommerce` viewers.
- **Dashboard**: a new "Needs Reorder" KPI card (subset of "Low Stock Items"), and two new columns ("Incoming", "Reorder status") on the "Recent Low Stock Items" table — both `manage_woocommerce`-gated.
- **`Summary::build()`** gains an additive `needs_reorder` key (always `<= low_stock`); **`Summary::get_low_stock_lines_for_chart()`** rows gain additive `position`/`incoming`/`needs_reorder`/`covered_by_incoming` keys. Classification runs in one bulk `Inventory_Position_Service::get_positions_bulk()` call per method invocation, never once per candidate.

### Changed

- **`WC_Inventory_Overview_List_Table`** — `render_direct_stock_badges_inner()`/`render_direct_stock_badges()`/`render_status_badges()` gain an optional trailing `$position_map` parameter (default `array()`, fully backward compatible); `get_row_state_classes()` and `compute_variable_aggregate()` gain the new classification. All five pre-existing on-hand-vs-threshold "Low stock" call sites are unmodified.
- **`WC_Inventory_Overview_Overview_Controller`** — `ajax_save_inline_stock()`'s badge-refresh response builds its own single-item, `manage_woocommerce`-gated Position lookup so the same rule applies there too; `render_summary_cards()` gains the new card.
- **`WC_Inventory_Overview_Summary`** — `count_low_sellable_lines()` is folded into a new `scan_low_stock_and_needs_reorder()` (low_stock's value/population unchanged); new `classify_needs_reorder_bulk()` helper is the sole caller of `Inventory_Position_Service::get_positions_bulk()` on this class's behalf.
- **`WC_Inventory_Overview_Dashboard_Controller`** — KPI array and `render_dashboard_operational_panels()` gain the new capability-gated surfaces, reusing the existing `$can_shop` gate pattern.

### Testing

- **45 new M21-specific tests**, 0 failures: `Test_WC_IO_Reorder_Signal_Resolver` (7, pure classifier), `Test_WC_IO_Reorder_Signal_Architecture` (8, sole-owner + stateless + capability guards), `Test_WC_IO_Reorder_Signal_List_Table` (8, row badges/row-class/variable-parent rollup), `Test_WC_IO_Reorder_Signal_Capability_Matrix` (3, AJAX badge refresh + variable-parent negative case), `Test_WC_IO_Summary_Needs_Reorder` (10, count/chart-row correctness, baseline-delta assertions to avoid this suite's pre-existing dbDelta-breaks-rollback cross-test product leakage), `Test_WC_IO_Summary_Query_Count` (2, bounded at 5- and 60-product scale), `Test_WC_IO_Dashboard_Reorder_Surfaces` (4), `Test_WC_IO_Overview_Summary_Cards_Reorder` (3).
- Recalibrated one pre-existing regression assertion: `test_position_query_count_bounded_for_twenty_plus_rows` (M3) bound moves from `<=2` to `<=4` — `List_Table::prepare_items()` now makes two independent, individually-bounded bulk Position lookups per page load (`Summary::build()`'s store-wide `needs_reorder` scan, and the pre-existing page-scoped `position_map`) that cannot be merged, since they operate over genuinely different candidate scopes. The invariant guarded (fixed cost, independent of N, no per-row queries) is unchanged; only the numeric ceiling reflects this legitimate additive cost.
- CI focused-suite filter updated with the new `Test_WC_IO_Reorder_Signal_`/`Test_WC_IO_Summary_`/`Test_WC_IO_Overview_Summary_Cards_` prefixes (`Test_WC_IO_Dashboard_Reorder_Surfaces` already covered by the existing `Test_WC_IO_Dashboard_` prefix); verified via `--list-tests`.
- Full unit/M1-M21-focused/integration suites green, 0 failures/errors.

### Notes

- `DB_VERSION` unchanged at 11; no migration.
- No AJAX endpoint, admin-post handler, nonce/action string, or capability constant was added.

## [1.37.0] - 2026-08-13

**Milestone M20 — Admin Controller Decomposition, Phase 3.** Internal refactoring: extraction of the two remaining god-class tabs — Inventory Overview and Restock/Cost Adjustment — from `WC_Inventory_Overview_Plugin` into two new controllers, `WC_Inventory_Overview_Overview_Controller` and `WC_Inventory_Overview_Restock_Controller`, completing the Admin Controller Decomposition project started in M18. **Zero behavior change, zero schema change (`DB_VERSION` stays 11), zero new public API, zero new capability, zero new hook.** Unlike M19's entirely read-only cluster, both extracted tabs carry real mutation surface (Overview: 4 bulk product-status/visibility/stock-status actions + inline-stock AJAX; Restock: two admin-post mutation handlers) — mutation logic itself is unchanged, relocated verbatim; `Restock_Service`/`Cost_Adjustment_Service` remain the sole, unmodified authoritative mutators. Restock was extracted first (self-contained asset enqueue, mutation already delegated to untouched domain services), then Overview (bulk-action mutation is inline in Plugin with no service layer, and its asset enqueue required a split from Dashboard's, mirroring M19's own `enqueue_assets()` split). Both built characterization-tests-first, each with its own full characterize → extract → guard sub-sequence. Plugin is now a pure composition-root/shell (menu registration, top-level tab routing/dispatch, legacy-slug redirects, Dashboard's own asset enqueue) — the Admin Controller Decomposition project's target end state, reached after three milestones (M18–M20). Level A completion review passed; see `docs/checklists/m20-release-readiness.md`.

### Changed

- **`WC_Inventory_Overview_Restock_Controller`** — new class owning the Restock/Cost Adjustment tab: screen bootstrap, subnav, rendering (`render()`, renamed from `render_restock_panel()`), asset enqueue, both admin-post mutation handlers (`handle_restock_post()`, `handle_cost_adjustment_post()`), and the read-only cost-adjustment preview AJAX (8 methods, ~340 LOC, extracted from Plugin). Registers 4 hooks: `admin_post_wc_io_restock`, `admin_post_wc_io_cost_adjustment`, `wp_ajax_wc_io_get_cost_adjustment_preview`, `admin_enqueue_scripts`. Mutation stays delegated, unmodified, to `WC_Inventory_Overview_Restock_Service::process_purchase_restock()` and `WC_Inventory_Overview_Cost_Adjustment_Service::process()` — this controller is HTTP/admin orchestration only, never a new mutation owner. The supplier-search/quick-create AJAX handlers it enqueues nonces for remain registered on `Purchasing_Page`, untouched — only the pre-existing enqueue/localization glue relocated.
- **`WC_Inventory_Overview_Overview_Controller`** — new class owning the Inventory Overview tab: screen bootstrap, CSV export, all 4 bulk actions, the inline-stock AJAX handler, rendering (`render()`, renamed from `render_inventory_overview_panel()`), summary cards, and the per-page screen-option filter (8 methods + 1 filter, ~360 LOC, extracted from Plugin). Bulk/inline mutations remain inline `WC_Product` calls — no service existed to delegate to, none introduced. `WC_Inventory_Overview_List_Table` is called identically, unmodified.
- **`Plugin::enqueue_assets()` split** — `Overview_Controller::enqueue_overview_assets()` independently re-registers the shared `wc-inventory-overview-admin` stylesheet handle (idempotent per handle, same precedent `Settings_Controller`/`Reporting_Controller` already established) plus `admin.js` + `wcIoInventory` localization, verbatim. `Plugin::enqueue_assets()` shrinks to Dashboard-only.
- **`Plugin::init()` bootstrap** — added `Restock_Controller::instance()->init();` and `Overview_Controller::instance()->init();`, appended after `Reporting_Controller::instance()->init();`, before `Expected_Delivery_Service::register();`.
- **`Plugin::on_load_inventory_profit_page()`/`render_inventory_profit_shell()` dispatch** — the `TAB_RESTOCK` and `TAB_OVERVIEW` branches now call the new controllers; capability gates unchanged, byte-for-byte. Both call sites for Overview's render — the `TAB_OVERVIEW` case **and** the `default:` fallback case — were updated (the second is easy to miss and was verified by an architecture-guard test asserting the call appears exactly twice).

### Testing

- New characterization test suites (68 tests total): `Test_WC_IO_Restock_Rendering_Characterization` (13), `Test_WC_IO_Restock_Mutation_Characterization` (12), `Test_WC_IO_Restock_Cost_Adjustment_Preview_Characterization` (7), `Test_WC_IO_Overview_Rendering_Characterization` (10), `Test_WC_IO_Overview_Bulk_Action_Characterization` (11), `Test_WC_IO_Overview_Inline_Stock_Ajax_Characterization` (9), `Test_WC_IO_Overview_Csv_Export_Characterization` (6). Written and verified green against pre-extraction Plugin code, rerun unchanged (invocation-seam edits only) post-extraction.
- New architecture-guard suites (26 tests): `Test_WC_IO_Restock_Controller_Architecture` (12), `Test_WC_IO_Overview_Controller_Architecture` (14) — no new capabilities/hooks/SQL/transaction boundaries, correct ownership, capability-before-nonce ordering, nonce-string preservation, bootstrap order, both Overview dispatch call-sites, `Restock_Service`/`Cost_Adjustment_Service` confirmed unmodified and sole mutators, supplier-picker AJAX handler confirmed still on `Purchasing_Page`, `List_Table` confirmed unmodified.
- **94 new M20-specific tests total**, 0 failures — combined with M18 (47) and M19 (16), the Admin Controller Decomposition project now has 157 dedicated tests.
- Two pre-existing invocation-seam breaks found and fixed while running the full M1–M20 suite (not during characterization, since these tests are outside the M20-owned clusters): `Test_WC_IO_Batch_Migration_Retirement_Regression`'s two `get_restock_subview()` reflection tests (M6-era) updated to reflect the new `Restock_Controller` target, assertions unchanged.
- One genuine pre-existing test-order-dependency defect found and fixed: `Test_WC_IO_Expected_Delivery_Renderer::test_filter_is_inactive_on_admin_non_ajax_and_active_under_ajax` implicitly relied on `DOING_AJAX` being unset process-wide; since that PHP constant cannot be unset once any test defines it (an existing, pre-M20 convention), and M20 legitimately added two more AJAX-testing files, this precondition became order-dependent for the first time. Fixed by explicitly forcing `wp_doing_ajax()` false for the "inactive" branch via the same filter-override technique the test already used for the "active" branch — assertion semantics unchanged, only the precondition made deterministic.
- `tests/bootstrap.php` gained a global `wp_die_ajax_handler` → `WPDieException` safety net, mirroring `WP_UnitTestCase_Base::wp_die_handler()`'s own logic line-for-line. WP core's test scaffolding only auto-registers this conversion for the non-AJAX `wp_die_handler` filter; once `DOING_AJAX` is permanently true, every later `wp_die()` call in the same process — even one completely unrelated to AJAX — silently killed the entire PHPUnit run with no test report. Reproduced running the full M1–M20 suite for the first time after M20's two new AJAX-handler characterization files shifted execution order enough to expose this pre-existing gap.
- CI discovery filter updated with the nine new M20 test class prefixes; verified via `--list-tests` (94 methods discovered, zero collisions).
- Full unit/M1-M20-focused/integration suites green, 0 failures/errors.

### Notes

- The Plugin class shrinks from 1,230 lines (post-M19) to 410 lines (~67% further reduction). Combined M18+M19+M20 reduction from the original god class: 2,706 → 410 lines (~85% total).
- The Admin Controller Decomposition project is now **complete** — Plugin owns no tab-specific logic, only the composition-root/shell (menu, tab routing/dispatch, legacy redirects, Dashboard's own asset enqueue).
- Query-count contract: baselines captured during characterization (empty/small/larger catalog scale for Overview's render path, small deterministic counts for Restock's mutation/preview paths); equality verified post-extraction for every characterized path.
- No transaction boundary was introduced anywhere — Restock/Cost-Adjustment's pre-existing hand-rolled compensating-rollback pattern and Overview's non-transactional per-ID bulk-save loop are preserved exactly, not "improved."
- No speculative design or scope expansion — this milestone extracts exactly what was already there, changes no business behavior, and completes the god-class decomposition project named across M18/M19's own deferred-work sections.

## [1.36.0] - Unreleased

**Milestone M19 — Admin Controller Decomposition, Phase 2.** Internal refactoring: extraction of the Movements, Order Profit, and Product Profitability tabs from the Plugin god class into a new `WC_Inventory_Overview_Reporting_Controller`, following the same pattern established by `Settings_Controller`/`Dashboard_Controller` in M18. **Zero behavior change, zero schema change (`DB_VERSION` stays 11), zero new public API, zero new capability, zero new hook.** The extracted cluster is entirely read-only (screen bootstrap, rendering, CSV export) — zero mutation, zero AJAX, zero bulk actions. Branched from the unreleased M18 tip, not `main`, since M18 has not yet released. **Overview and Restock remain on Plugin** — both carry real mutation surface (Overview: bulk product-status/visibility mutation + inline-stock AJAX; Restock: two admin-post handlers writing stock/cost) and are deliberately deferred to a future Phase 3, not folded into this milestone. Level A completion review passed; see `docs/checklists/m19-release-readiness.md`. Not individually released — designed to join M18 in one Admin Controller Decomposition feature train.

### Changed

- **`WC_Inventory_Overview_Reporting_Controller`** — new class owning Movements/Order-Profit/Product-Profitability screen bootstrap, rendering, and CSV export (9 methods + 3 top-level screen-option filters, ~330 LOC, extracted from Plugin). Registers one `admin_enqueue_scripts` callback for the Movements tab's shared stylesheet + `movements-table.js`, split out of `Plugin::enqueue_assets()`'s three-way Dashboard/Overview/Movements branch. Instantiated from `Plugin::init()` via the same singleton `instance()->init()` pattern as `Settings_Controller`/`Purchasing_Page`.
- **`Plugin::init()` bootstrap** — added `WC_Inventory_Overview_Reporting_Controller::instance()->init();`, appended after `Settings_Controller::instance()->init();`, before `Expected_Delivery_Service::register();`.
- **`Plugin::on_load_inventory_profit_page()` dispatch** — the Movements/Order-Profit/Product-Profitability branches now call `WC_Inventory_Overview_Reporting_Controller::instance()->on_load_movements()`/`on_load_order_profit()`/`on_load_product_profitability()`; capability gates unchanged.
- **`Plugin::render_inventory_profit_shell()` dispatch** — the corresponding `case` blocks now call `WC_Inventory_Overview_Reporting_Controller::instance()->render_movements()`/`render_order_profit()`/`render_product_profitability()`; capability-gate notice branches unchanged (byte-for-byte, even though characterization confirmed they are currently unreachable via normal navigation — `get_requested_tab()` already falls back to the Overview tab before the switch runs for a user lacking the required capability).
- **`Plugin::enqueue_assets()`** — no longer references `TAB_MOVEMENTS`; the Movements-specific script enqueue moved to `Reporting_Controller::enqueue_reporting_assets()`. Dashboard/Overview branches unchanged.

### Testing

- New characterization test suites (25 tests total): `Test_WC_IO_Movements_Rendering_Characterization` (8), `Test_WC_IO_Order_Profit_Rendering_Characterization` (9), `Test_WC_IO_Product_Profitability_Rendering_Characterization` (8). Written and verified green against pre-extraction Plugin code, rerun unchanged (invocation-seam edits only) post-extraction to prove INV-M19-2. Exact pre-extraction query-count baselines locked in (Movements=2, Order Profit=3, Product Profitability=4), held exactly post-extraction.
- New architecture-guard suite (11 tests): `Test_WC_IO_Reporting_Controller_Architecture`, verifying INV-M19-1/4/5/8/9/10/11/12/13 (no new capabilities, hooks, or SQL; capability-before-nonce ordering per export method; correct ownership; Overview/Restock untouched; bootstrap order preserved).
- Targeted regression runs: `Test_WC_IO_No_Sibling_Plugin_Coupling`, existing `Test_Movements_Characterization` (domain-level), `Test_WC_IO_Schema_V11_Upgrade`, and `Test_WC_IO_Dashboard_*` (regression coverage since `enqueue_assets()` was touched) — 30 tests, 0 failures.
- CI discovery filter updated with the four new M19 test class prefixes; verified via `--list-tests`.
- Full unit/M1-M19-focused/integration suites green, 0 failures/errors.

### Notes

- The Plugin class shrinks from 1,561 lines (post-M18) to 1,230 lines (~21% further reduction). Combined M18+M19 reduction from the original god class: 2,706 → 1,230 lines (~55%).
- **Two tabs remain undecomposed: Overview and Restock** (~361 and ~368 lines respectively), both carrying genuine mutation surface. The god-class decomposition project is **not** complete after M19 — a future Phase 3 milestone is needed to address them, and may reasonably be split into two milestones given the two tabs are not coupled to each other.
- Query-count contract: all DB calls remain unchanged (moved code continues to call identical, unmodified `*_List_Table`/`*_Query` domain classes with identical arguments). Baselines captured during characterization; equality verified post-extraction.
- Reporting Controller code contains zero `global $wpdb` or `$wpdb->` (INV-M19-8) — confirmed no raw SQL existed in the moved Plugin methods to relocate; all data access was already delegated to existing, unmodified domain classes.
- Pre-move symbol audit found zero unqualified `self::`/`static::` constant references in the new controller (the class of defect M18 had in `Dashboard_Controller` — six unqualified `self::` constants — does not recur here).
- No speculative design or scope expansion — this milestone extracts exactly what was already there, changes no business behavior, and reserves Phase 3 (Overview, Restock) for a later milestone.

## [1.35.0] - Unreleased

**Milestone M18 — Admin Controller Decomposition, Phase 1.** Internal refactoring: extraction of Dashboard and Settings tabs from the 2,706-line Plugin god class into two dedicated controller classes (`WC_Inventory_Overview_Settings_Controller`, `WC_Inventory_Overview_Dashboard_Controller`), following the existing pattern used by `WC_Inventory_Overview_Purchasing_Page`. **Zero behavior change, zero schema change (`DB_VERSION` stays 11), zero new public API, zero new capability, zero new hook.** Pure code organization; all business rules, security postures, and user-facing behaviors are byte-identical to v1.34.0. Phase 2 (remaining five tabs: Overview, Restock, Movements, Order Profit, Product Profitability) deferred to a future milestone. Level A completion review passed; see `docs/checklists/m18-release-readiness.md`. Not individually released — designed for feature-train batching.

### Changed

- **`WC_Inventory_Overview_Settings_Controller`** — new class owning all Settings-tab functionality: save handler, exchange-rate CRUD orchestration, danger-zone preview/apply orchestration, rendering, asset enqueue, AJAX exchange-rate lookup (12 methods, ~764 LOC, extracted from Plugin). Registers 7 hooks: `admin_post_wc_io_save_settings`, `admin_post_wc_io_add_exchange_rate`, `admin_post_wc_io_delete_exchange_rate`, `admin_post_wc_io_danger_reset_preview`, `admin_post_wc_io_danger_reset_apply`, `wp_ajax_wc_io_get_exchange_rate`, `admin_enqueue_scripts` (Settings-tab scope only). Instantiated from `Plugin::init()` via singleton `instance()->init()` pattern, same as `Purchasing_Page`.
- **`WC_Inventory_Overview_Dashboard_Controller`** — new class owning Dashboard-tab rendering: date-filter parsing, operational panels (low-stock table, quick actions), KPI/chart rendering (3 methods, ~375 LOC, extracted from Plugin). No dedicated hooks; called directly from Plugin's tab dispatch. Instantiated via singleton `instance()->render()` pattern.
- **`WC_Inventory_Overview_Plugin` visibility changes** — `get_requested_tab()` and `admin_url_tab()` promoted from `protected` to `public` (no signature/body changes) so the new controllers can call them for tab routing.
- **`Plugin::init()` bootstrap** — added `WC_Inventory_Overview_Settings_Controller::instance()->init();` call in the existing bootstrap block, after `Purchasing_Page`, before action-registration begins. Settings-related `add_action()` calls removed; Settings hook registration is now owned by `Settings_Controller::init()`.
- **`Plugin::render_inventory_profit_shell()` dispatch** — `TAB_SETTINGS` case now calls `WC_Inventory_Overview_Settings_Controller::instance()->render();`; `TAB_DASHBOARD` case now calls `WC_Inventory_Overview_Dashboard_Controller::instance()->render();`. Surrounding capability gates and tab availability logic unchanged.

### Testing

- New characterization test suites (17 tests total): `Test_WC_IO_Settings_Save_Characterization` (5 tests for save redirect/capability denial/nonce validation/query counts) and `Test_WC_IO_Dashboard_Rendering_Characterization` (12 tests for date filters, KPIs, low-stock, quick actions, charts). Written and verified green against pre-extraction Plugin code, rerun unchanged post-extraction to prove INV-M18-2 (zero behavior change).
- New architecture-guard suites (14 tests total): `Test_WC_IO_Settings_Controller_Architecture` and `Test_WC_IO_Dashboard_Controller_Architecture`, verifying INV-M18-1/4/5/8/9/10/11/12 (no new capabilities, hooks, or SQL; correct ownership; nonce/capability-check stability).
- Targeted regression runs: `Test_WC_IO_Settings_PO_Delay_Grace_Days` (8 tests) and `Test_WC_IO_No_Sibling_Plugin_Coupling` (8 tests), confirming zero hidden coupling introduced by the refactor.
- CI discovery filter updated to include new test class prefixes.
- Full unit/integration suites green, 0 failures/errors/risky.

### Notes

- The Plugin class shrinks from 2,706 lines to ~1,579 lines (~42% reduction). The two extracted controllers remain singleton-scoped and self-registered, preserving the existing architectural pattern.
- Query-count contract: all DB calls remain unchanged (moved code continues to call identical domain services with identical arguments). Baseline captured during characterization; equality verified post-extraction.
- Settings/Dashboard code contains zero `global $wpdb` or `$wpdb->` (INV-M18-8).
- No speculative design or scope expansion — this milestone extracts exactly what was already there, changes no business behavior, and reserves Phase 2 (remaining tabs) for a later milestone.

## [1.34.0] - 2026-08-11

**Milestone M17 — Supplier Merge.** Administrative capability to irreversibly merge a source supplier into a target supplier, reassigning all associated Purchase Orders and Goods Receipts. Closes the "Not Yet Available: Supplier merge tool" backlog line carried in `docs/admin-guide-suppliers.md` since before M9. **Schema change: `DB_VERSION` 10 → 11** (new `merged_into_supplier_id` column on `wc_io_suppliers`, new append-only `wc_io_supplier_merges` audit table). This is a schema-change / ownership-boundary-change milestone and releases standalone, not via a feature train. Not individually released — implemented and frozen on a feature branch; release timing decided separately.

### Added

- **`WC_Inventory_Overview_Supplier_Merge_Service::merge()`** — the sole orchestrator of a supplier merge. Atomic, exception-safe (`try`/`catch(\Throwable)` guarantees rollback on every exit path), row-locks both suppliers in a fixed low-ID-first order (deadlock-prevention against a concurrent reverse-direction merge), bulk-reassigns `supplier_id` on every Purchase Order and Goods Receipt (all statuses, single `UPDATE` statement each), records one append-only audit row, and archives + permanently marks the source supplier as merged.
- **Server-enforced typed confirmation** (BR-M17-16): the admin must type the exact source supplier name; compared with exact string equality against the freshly row-locked source's current name, inside the transaction — never trusts client-side JS.
- **Permanent dissolution** (BR-M17-15): a merged supplier can never be reactivated (`Suppliers::reactivate()` hardened, its admin handler, and the Suppliers list-table row action all independently reject it), never appears in supplier selection, and can never participate in another merge as either source or target.
- **Concurrent-create race closure** (BR-M17-18): `PO_Service::create_draft()` and `Goods_Receipt_Service::create_draft_from_post()` now lock and re-validate the chosen supplier's row (active, not merged) inside their own transaction before inserting — closes the window where a new draft could be created against a supplier that a concurrently-running merge has just dissolved.
- New admin capability `WC_Inventory_Overview_Purchasing_Caps::MERGE_SUPPLIER`, default-mapped to `manage_woocommerce`, filterable like every other purchasing capability.
- New "Merge into another supplier" section on the Supplier detail admin screen: Select2 AJAX target picker (excludes the source itself, archived suppliers, and already-merged suppliers), typed-confirmation field with a client-side submit gate (UX only — the server-side check is authoritative), and explicit irreversibility warning copy.
- Already-merged suppliers render a static "merged into {target}" notice on their detail screen and a plain non-actionable "Merged into {target}" label in the Suppliers list table, in place of any Reactivate control.

### Notes

- **Historical fidelity preserved**: `supplier_name_snapshot` on both Purchase Orders and Goods Receipts is never rewritten by a merge; `wc_io_inventory_movements` is never touched. Merge-chain history (`A` merged into `B`, later `B` merged into `C`) records only the direct successor at each step — `A.merged_into_supplier_id` continues to read `B`, not `C` — since all of `A`'s operational records were already carried forward to `B`, and then to `C` by the second merge's own bulk `UPDATE`. No runtime code ever resolves a multi-hop chain.
- **All derived-statistics services require zero code changes** to correctly reflect a merge (Observed Lead Time, On-Time Rate, Supplier Order History, Supplier Spend Summary, Inventory Position drilldown) — every one of them filters by `purchase_orders.supplier_id` at query time, which the merge's own bulk `UPDATE` already moves.
- **No new WordPress hook of any kind.** The only new extension-adjacent surface is a private, test-bootstrap-gated static method (`set_test_fail_after_step()`) used to prove exception-safety at each of the three post-lock mutation steps — structurally inert in production (gated by a constant defined only in `tests/bootstrap.php`), not documented as or usable as an extension point.
- **Query-count contract**: the mutation phase is a fixed, itemizable 4-statement set (bulk PO `UPDATE`, bulk GR `UPDATE`, audit `INSERT`, source `UPDATE`) regardless of history size. The complete `merge()` call's total query count was measured empirically at 500/2,000/5,000 related Purchase Orders and confirmed constant at all three scales (11 queries), proving no per-record loop anywhere in the merge path.

### Testing

- New unit suites: `Test_WC_IO_Supplier_Merge_Primitives` (repository-layer read/write contracts), `Test_WC_IO_Supplier_Merge_Service` (full business-rule/threat matrix, exception-safety at all three failure-injection seams, server-side confirmation-mismatch independent of any JS), `Test_WC_IO_Supplier_Merge_Architecture` (sole-mutator guards, no-SQL-in-admin-page guard, no-new-hooks guard), `Test_WC_IO_Supplier_Merge_Performance` (measured-constant query count across three fixture scales, failure-injection rollback proof).
- New integration suites: `Test_WC_IO_Schema_V11_Upgrade` (fresh-install/upgrade schema parity, dispatcher-routing proof), `Test_WC_IO_Supplier_Merge_Concurrency` (concurrent-create race closure, zero regression for ordinary active-supplier creation), `Test_WC_IO_Supplier_Merge_Derived_Stats` (empirical zero-code-change proof across four derived-statistics services), `Test_WC_IO_Supplier_Merge_Admin` (capability/nonce/token/crafted-POST HTTP-level coverage), `Test_WC_IO_Supplier_Merge_Admin_Render` (automated UI-rendering assertions).
- Zero regression in existing `Test_WC_IO_PO_Service`, `Test_WC_IO_Goods_Receipt_Service`, and `Test_WC_IO_Suppliers_Admin_PRG` suites.

## [1.33.0] - 2026-08-11

**Milestone M16 — PO Expected-Date & Delay Transparency.** Three small, read-mostly surfaces that make already-computed-but-hidden facts visible/configurable in the Purchase Order workflow: why an Expected-Date suggestion was made, how many grace days a PO gets before being flagged "delayed," and which supplier/status is behind each contributing line in the Inventory Position drilldown. **Zero schema change (`DB_VERSION` stays 10), zero domain/operational mutation, zero new public API, zero new capability, zero new hook.** First milestone of a new, unreleased post-v1.32.0 train — not individually released.

### Added

- **Expected-Date suggestion provenance** on the New PO screen: an advisory hint beneath the Expected Date/Confidence fields, reading "Suggested from this supplier's delivery history (N orders, avg D days)." for an observed-history suggestion, or "Suggested from supplier's configured default (D days)." for a configured-default suggestion. Presentation only — never submitted, never influences persistence (BR-M16-1/BR-M16-3).
- **`WC_Inventory_Overview_Expected_Date_Suggestion_Service`** return shape gains `sample_count`/`average_days` (both `null` unless `source === 'observed'`), sourced from the same already-fetched `Supplier_Lead_Time_Service::get_stats_bulk()` stats the service already used — no additional query (BR-M16-2).
- **PO delay grace-days Settings field** — new "PO delay grace period (days)" field under a new "Purchasing" section on the Settings tab, exposing the existing `WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS` option (previously editable only via raw `update_option()`). Accepts an integer 0–365; any missing, non-numeric, non-integer, or out-of-range submission leaves the stored value completely untouched — never `absint()`-style coerced into range (BR-M16-4).
- **Supplier + Status columns** on the Inventory Position drilldown mini-table (M3), inserted between PO number and Outstanding: **PO number, Supplier, Status, Outstanding, Expected date, Confidence, Delayed** (BR-M16-8). Supplier uses the PO's own denormalized `supplier_name_snapshot` (survives supplier archive, BR-M16-6); Status reuses the existing `WC_Inventory_Overview_PO_Statuses::label()` map (BR-M16-7). Sourced from two additional `SELECT` columns on the already-executing `query_open_lines()` query — zero new query, zero new join.
- Architecture guards INV-M16-2 (sole mutator of the grace-days option) and confirmation that INV-M16-3 (Inventory Position sole-caller guard) needed zero changes.

### Notes

- `days` (the resolved suggestion value used to prefill the form) is never repurposed as display evidence; the provenance message reads `sample_count`/`average_days` for an observed suggestion, never `days` — keeping "the value we suggest" and "the evidence we show" independently sourced (BR-M16-2).
- Grace-days validation is a deliberate deviation from this codebase's `absint()`-based numeric-Settings-field convention (e.g. `OPTION_DEFAULT_LOW_STOCK_THRESHOLD`): `absint()` would silently turn `-5` into `5`, whereas any invalid input here must preserve the prior stored value untouched. `0` is a valid, saveable value.
- The Inventory Position drilldown extension (M3, shipped in v1.20.0) was found during discovery to already cover PO number/outstanding/expected date/confidence/delayed — only Supplier and Status were actually missing; this milestone folds that trivial, zero-new-query addition in alongside the two related, previously-deferred Settings/provenance items rather than shipping it as an unrelated bolt-on to a different milestone.

### Testing

- New/extended unit coverage for `Expected_Date_Suggestion_Service` (evidence-field presence/nullability, `days` unaffected); new `Test_WC_IO_Settings_PO_Delay_Grace_Days` suite (table-driven validate-or-preserve contract: missing, negative, `>365`, non-numeric, decimal, scientific-notation, trailing-character, `0`, `365`, mid-range valid; sole-mutator architecture guard); new/extended integration coverage for `PO_Admin`'s localized suggestion data (configured/observed/none provenance, i18n templates, edit-screen no-op) and the Inventory Position drilldown (fixed column order, supplier-snapshot-survives-archive, shared status label). Existing `test_position_query_count_bounded_for_twenty_plus_rows` re-run unmodified and still green. Full unit/integration suites green, 0 failures/errors/risky.

## [1.32.0] - 2026-08-11

**Milestone M15 — Supplier Spend Summary.** Read-only, per-currency total of Ordered/Received Value across a supplier's *committed* Purchase Orders, on the existing Supplier detail admin screen, above the Observed Lead Time panel. Closes the one remaining named gap in `docs/admin-guide-suppliers.md`'s "Not Yet Available" list (supplier spend analysis), resolving M14's stated currency-normalization blocker by choosing to never blend or convert currencies. **Zero schema change (`DB_VERSION` stays 10), zero mutation, zero new public API, zero new capability, zero new hook.** **Prerequisite:** `1.31.0` (M14, frozen, unreleased). Third milestone of the same still-unreleased post-v1.29.0 feature train — not individually released.

### Added

- **"Spend Summary" section** on the Supplier detail admin screen, rendered before the Observed Lead Time / Order History sections.
- **`WC_Inventory_Overview_Supplier_Spend_Service`** — new Internal (not Public — D16) service owning the committed-status business rule (BR-M15-1: `placed`/`partially_received`/`received`/`closed_short` only; `draft`/`cancelled` always excluded, INV-M15-1) and composing the aggregate exclusively through `Purchase_Orders::spend_summary_for_supplier()` (INV-M15-3).
- **`WC_Inventory_Overview_Purchase_Orders::spend_summary_for_supplier()`** — new additive, self-contained aggregate read method: one grouped query (`GROUP BY` PO-line currency) returning ordered/received totals and a distinct committed-PO count per currency for a supplier's entire order history. Never blends or converts currencies (INV-M15-2). `po_count` is `COUNT(DISTINCT po.id)` scoped to each currency row (BR-M15-5) — a PO with lines in more than one currency may contribute once to more than one row.
- Each currency row shows **Currency**, **Ordered Value**, **Received Value (PO Cost)**, and **Committed POs**.
- Architecture guards INV-M15-1 / INV-M15-2 / INV-M15-3 / INV-M15-4 (committed-status filtering, currency isolation, zero mutation + approved-read-owner-only sourcing, sole-consumer allowlist).

### Notes

- Value semantics are deliberately narrow, matching M14: PO-line cost only (`qty × unit_cost`), never landed costs (`Receipt_Costs`) or the weighted-average/EUR inventory-value figure Goods Receipt posting maintains.
- Unlike M14's Order History (status-inclusive, for a full audit trail), Spend Summary is the first feature in this plugin to define a "committed spend" status subset that also excludes `cancelled` — a genuinely new business decision (BR-M15-1), not reused verbatim from M13 or M14.
- Unlike M14's page-scoped 3-query contract, this is a true database-level aggregate over the supplier's entire history: exactly 1 query, independent of history size (proven at 200-PO/3-currency scale).
- Version-surface convention for this milestone only: the plugin header, `WC_INVENTORY_OVERVIEW_VERSION`, and `readme.txt`'s `Stable tag` are all bumped together to `1.32.0` (unlike M13/M14, which deliberately left `Stable tag` behind at `1.29.0`) — this repository distributes via its own GitHub updater, not the public WordPress.org directory, so `Stable tag` carries no auto-push-to-users implication here.
- `docs/ARCHITECTURE_BASELINE_v1.24.0.md` and `docs/architecture-audit.md` are brought current for both M14 and M15 in this release — both had fallen out of date after M14 (a documentation-currency gap found during M15 discovery, not a product or architecture defect).

### Testing

- New unit coverage for `spend_summary_for_supplier()` (formula correctness, committed-status filtering, currency-row isolation, `po_count` semantics including a required mixed-line-currency fixture, empty-result short-circuit, no cross-PO/currency summing) and `Supplier_Spend_Service` (committed-status constant, delegation correctness); new integration coverage for the rendered admin section (totals, empty state, currency display, section ordering, capability gate); new performance suite confirming the 1-query contract at 200-PO/3-currency scale. Full suites green with 0 risky.

## [1.31.0] - 2026-08-10

**Milestone M14 — Supplier Order History.** Read-only, paginated list of every Purchase Order for a supplier (every status included — draft, placed, partially received, received, cancelled, closed short), on the existing Supplier detail admin screen. Closes the longest-standing named gap in `docs/admin-guide-suppliers.md`'s "Not Yet Available" list (order-history reporting, named since M9). **Zero schema change (`DB_VERSION` stays 10), zero mutation, zero new public API, zero new capability, zero new hook.** **Prerequisite:** `1.30.0` (M13, frozen, unreleased). Second milestone of the same still-unreleased post-v1.29.0 feature train — not individually released.

### Added

- **"Order History" section** on the Supplier detail admin screen, below the Observed Lead Time panel — newest `order_date` first, paginated via a dedicated `wc_io_supplier_order_history_page` parameter (never the generic `paged`).
- **`WC_Inventory_Overview_Supplier_Order_History_Service`** — new Internal (not Public — D16) service composing the paginated projection exclusively through `Purchase_Orders::count()`/`list()`/`values_bulk()` (INV-M14-3); zero mutation (INV-M14-1); every PO status included (INV-M14-4).
- **`WC_Inventory_Overview_Purchase_Orders::values_bulk()`** — new additive method: one grouped query returning ordered/received PO-cost value per PO id, for the current page's POs only. Never sums across POs or currencies (INV-M14-2).
- Each row shows **Ordered Value** and **Received Value (PO Cost)** — PO-line cost only (`qty × unit_cost`, that PO's own currency); explicitly not a landed-cost or inventory-valuation figure, and never blended across POs.
- Architecture guards INV-M14-1 / INV-M14-2 / INV-M14-3 / INV-M14-4 (zero mutation, per-PO/per-currency value isolation, approved-read-owner-only sourcing, sole-consumer allowlist).

### Notes

- Value semantics are deliberately narrow: landed costs (`Receipt_Costs`) and the weighted-average/EUR inventory-value figure Goods Receipt posting maintains are untouched and never read here. Spend analysis (totals, trends, currency-normalized aggregates) remains a deliberately separate, deferred capability — not folded into this milestone.
- A mechanical `order_date DESC, id DESC` tie-break was not enforced: the underlying `Purchase_Orders::list()` read owner accepts only a single `ORDER BY` column, its existing, unmodified contract shared by every other screen that sorts POs. Ties on identical `order_date` values fall back to that pre-existing, non-guaranteed ordering — a narrow, accepted limitation, not a new defect (see `docs/checklists/m14-release-readiness.md`).

### Testing

- New unit coverage for `values_bulk()` (formula correctness, empty-input short-circuit, no cross-PO/currency summing) and `Supplier_Order_History_Service` (pagination math, status inclusion, empty/out-of-range pages, query-count contract); new integration coverage for the rendered admin section (links, currency display, capability gate, pagination); new performance suite confirming the query contract at 200-PO scale. Full suites green with 0 risky.

## [1.30.0] - 2026-08-10

**Milestone M13 — Printable Purchase Order.** Read-only, standalone HTML printable view of a single Purchase Order, reachable from the existing PO detail screen. A capability reserved since Architecture v1.0 (D17, §11.2) and never built until now. **Zero schema change (`DB_VERSION` stays 10), zero mutation, zero new public API, zero new capability, zero new public hook.** **Prerequisite:** `1.29.0` (M9–M12 feature train, released). First milestone of a new, unreleased feature train — not individually released.

### Added

- **"Print" entry point** on the PO detail screen — available for `placed`, `partially_received`, `received`, `cancelled`, and `closed_short` POs; never for `draft`.
- **`WC_Inventory_Overview_PO_Print_Renderer`** — new presentation-only class (INV-M13-2) rendering the standalone printable document: store name, PO number/status/dates/currency, supplier name/reference/email/phone, per-line product/SKU/quantities/price/line-total, and PO total. Zero repository access, zero authorization, zero mutation, zero product lookup.
- **`admin_post_wc_io_po_print`** handler in `PO_Admin` — requires `VIEW_PO` capability and a PO-and-action-scoped nonce before any purchasing/supplier data is read (INV-M13-4); composes the render model from the existing `Purchase_Orders`, `Purchase_Order_Lines`, and `Suppliers` read owners plus `PO_Statuses::label()` (INV-M13-3) — no new domain owner.
- Architecture guards INV-M13-1 / INV-M13-2 / INV-M13-3 / INV-M13-4 (renderer purity, sole-consumer allowlist, approved-read-owner-only sourcing, capability+nonce ordering).
- New admin guide `docs/admin-guide-purchase-orders.md` documenting the print feature.

### Notes

- Product/variation identity on the printed document always comes from the PO line's own historical `name_snapshot`/`sku_snapshot` — never a live product lookup — so a deleted product/variation cannot break printing. Supplier name falls back to the PO header's own `supplier_name_snapshot` when the live supplier row is unavailable.
- Browser print / Save as PDF is the entire PDF mechanism — no PDF library, no generated/stored file.

### Testing

- New unit coverage for the renderer (document content, escaping, arithmetic, fallback behavior) and the print handler (full capability/nonce/status security matrix, deleted-product and unresolvable-supplier resilience); full suites green with 0 risky.

## [1.29.0] - 2026-08-09

**Milestone M12 — Supplier List Performance Surface.** Read-only Observed Lead Time and On-Time Rate columns on the Purchasing → Suppliers list table, populated by one `Supplier_Lead_Time_Service::get_stats_bulk()` call per page. Completes the M9–M11 supplier performance narrative at the comparison decision point. **Zero schema change (`DB_VERSION` stays 10), zero mutation, zero new public API.** **Prerequisite:** feature-train head with M9–M11 + CI recovery (`1.28.0`). Not individually released — joins the unreleased feature train pending WP6 bundled release.

### Added

- **Suppliers list columns** — Observed Lead Time (rounded average days) and On-Time Rate (rounded percentage), using the same usability thresholds as the supplier detail panel (`is_observed_value_usable` / `is_on_time_rate_usable`).
- Architecture guards INV-M12-1 / INV-M12-2 (no duplicated stats computation; one bulk call per `prepare_items()`; Lead Time service allowlist extended to the list-table file).
- Unit, integration, and query-scaling tests at 10/40/200 suppliers.

### Testing

- New list-performance coverage; full suites green with 0 risky (CI recovery baseline).

### Documentation

- New: `docs/milestones/m12-implementation-plan.md`.
- Updated: admin guide, architecture baseline/audit, CLAUDE.md, runbook/validation/rollback, feature-train head checklist.

## [1.28.0] - 2026-08-09

**Milestone M11 — Supplier On-Time Delivery Rate.** Read-only reliability scoring on the supplier detail screen: of completed orders with a known expected date (Exact or Estimated), what fraction were fully received on or before the deadline (expected date + grace days). **Zero schema change (`DB_VERSION` stays 10), zero mutation, zero new public API.** **Prerequisite:** `1.27.0` (M10). Not individually released — feature train.

### Added

- **`WC_Inventory_Overview_Expected_Deadline`** — narrow pure Internal class (four methods) owning deadline arithmetic and known-date eligibility (INV-M11-2); consumed by `PO_Delay` and `Supplier_Lead_Time_Service`.
- **`Supplier_Lead_Time_Service` extension** — same single bulk query now also returns `on_time_count` / `rated_order_count`; new `is_on_time_rate_usable()`; optional `$grace_days` (default 0). Unknown-confidence orders excluded from both numerator and denominator (INV-M11-1).
- **On-Time Delivery Rate row** on the supplier Observed Lead Time panel (grace days from `PO_Delay::grace_days_from_option()`).

### Changed

- **`PO_Delay` internal refactor** to compose `Expected_Deadline` — public contract and live delay behavior unchanged (pre-existing suite green unmodified).

### Testing

- Expected-deadline unit/architecture guards; on-time observation + performance regressions; M9/M10/`PO_Delay` regression coverage.

### Documentation

- New: `docs/milestones/m11-implementation-plan.md`.
- Updated: architecture baseline/audit, CLAUDE.md, admin guide, runbook/validation/rollback.

## [1.27.0] - 2026-08-09

**Milestone M10 — Purchase Order Expected-Date Suggestion.** Advisory Expected Date/Confidence pre-fill on **new** Purchase Order creation from observed lead time (fallback: configured lead time). Always overridable; never runs on edit-PO (INV-M10-1). **Zero schema change (`DB_VERSION` stays 10), zero mutation of inventory/PO lifecycle beyond ordinary form fields, zero new public API.** **Prerequisite:** `1.26.0` (M9). Not individually released — feature train.

### Added

- **`WC_Inventory_Overview_Expected_Date_Suggestion_Service`** — Internal sole owner of observed → configured → none recommendation policy; delegates statistics to `Supplier_Lead_Time_Service::get_stats_bulk()`.
- **`Supplier_Lead_Time_Service::is_observed_value_usable()`** — additive predicate so suggestion policy never duplicates M9's sample threshold.
- **PO Admin + `po-admin.js` wiring** — localizes suggestions for new-PO only; confidence suggested as Estimated; manual edit latches the fields.

### Testing

- Architecture guards, unit policy tests, integration observations, 10/40/200 performance coverage.

### Documentation

- New: `docs/milestones/m10-implementation-plan.md`.
- Updated: architecture baseline/audit, CLAUDE.md, admin guide, runbook/validation/rollback.

## [1.26.0] - 2026-08-09

**Milestone M9 — Supplier Observed Lead-Time Statistics.** The first post-GA milestone: one narrowly-scoped, read-only reporting feature, filling the `designed-for-later` slot `CLAUDE.md` Decision D8 reserved since M1 and explicitly named in `docs/admin-guide-suppliers.md`'s own "Not Yet Available" backlog. **Zero new domain concepts, zero schema change (`DB_VERSION` stays 10), zero new public API surface.** **Prerequisite:** v1.25.0 (M8 Hardening & GA).

### Added

- **`WC_Inventory_Overview_Supplier_Lead_Time_Service`** — new Internal (not Public — no concrete external consumer exists yet, D16) sole-owner service computing, per supplier, average/fastest/slowest delivery days and completed-order sample count from posted, non-migrated Goods Receipts linked to fully-`received` Purchase Orders. `get_stats_for_supplier()` delegates to `get_stats_bulk()` (single defined as bulk-of-one, same discipline as Inventory Position/Expected Delivery); one grouped aggregate SQL query regardless of scale (proven at 10/40/200 suppliers, no N+1). Nothing is ever persisted — every call recomputes from current operational history.
- **Read-only "Observed Lead Time" panel** on the Supplier admin screen (Purchasing → Suppliers → edit), directly beneath the existing "Default Lead Time (days)" field. Average is rounded to the nearest whole calendar day for display only; a "not enough data yet" state renders below 2 completed orders. Unaffected by archiving a supplier.
- New architecture guard test (`tests/unit/supplier-lead-time/test-supplier-lead-time-architecture.php`): sole-owner computation-signature scan, zero-write token scan, `$wpdb->get_results()`-only check, sole-caller allowlist, bulk-first delegation check.
- `Test_WC_IO_Supplier_Lead_Time_` added to `tests/docker/run-phpunit.sh`'s CI-blocking filter — ships CI-blocking from day one.

### Fixed

- A real bug the new unit tests caught before shipping: `get_stats_bulk()` validated supplier IDs with `absint()`, which takes the *absolute value* of a negative number instead of rejecting it — `-1` would have silently become a lookup for supplier `1`. Switched to an explicit `(int)` cast plus `> 0` check.

### Testing

- 26 new tests. Unit suite 226 tests / 1,486 assertions; M1–M9-focused (CI-blocking) suite 476 tests / 2,373 assertions; full integration suite 261 tests / 930 assertions, 0 errors, 0 failures, 0 skips. Includes a dedicated insertion-order-independence test proving the computed lead time depends only on each receipt's `posted_at`, never on its row ID or insertion order.

### Documentation

- New: `docs/milestones/m9-implementation-plan.md`, `docs/GITHUB_RELEASE_NOTES_1.26.0.md`.
- Updated in place (per `docs/ARCHITECTURE_BASELINE_v1.24.0.md` §12 rule 7, no new versioned baseline file — M9 changes no frozen boundary): `docs/ARCHITECTURE_BASELINE_v1.24.0.md`, `docs/architecture-audit.md`, `CLAUDE.md`'s Implementation Status table, `docs/release-runbook.md`, `docs/checklists/validation-checklist.md`, `docs/rollback-plan.md`. `docs/admin-guide-suppliers.md`'s "Lead-time statistics" backlog entry moved from "Not Yet Available" to "What Is Available Now," with a new Configured-vs-Observed comparison for merchants.

## [1.25.0] - 2026-08-08

**Milestone M8 — Hardening & GA.** Not a feature milestone: a hardening, cleanup, and conformance pass that closes out every genuinely-justified, previously-deferred item from M0–M7, so the platform can be called production-finished. **Zero new domain concepts, zero schema change (`DB_VERSION` stays 10), zero public API change.** With M8 complete, M0–M8 is considered **Version 1.0 / GA ready** — see `docs/ARCHITECTURE_BASELINE_v1.24.0.md`'s updated milestone table and `docs/architecture-audit.md`'s M8 GA-readiness statement. **Prerequisite:** v1.24.0 (M7 Storefront Expected Delivery).

### Removed

- **The M6-deprecated Batch Intake create/apply code**, physically deleted per the governance rule reserving that deletion for M8 (M6's own "disabled, not deleted, slated for physical removal in M8"): `WC_Inventory_Overview_Batch_Intake_Service`'s `build_preview_from_post()`/`apply_batch_from_post()`/`rollback_batch_apply()`/`build_movement_note_for_line()`/`render_preview_markup()` and their private-only helpers; `WC_Inventory_Overview_Batch_Intake_UI` (deleted entirely — its one method had zero remaining callers); `WC_Inventory_Overview_Plugin::ajax_batch_preview()`/`handle_batch_apply_post()` and the already-unreachable `RESTOCK_VIEW_BATCH` admin-notice code path. `landed_cost_type_labels()`/`allowed_cost_types()` are retained as live delegation shims to `WC_Inventory_Overview_Landed_Cost_Types`. This removes code, not history — legacy `wc_io_purchase_batches`/`_lines`/`_costs` tables and rows are completely untouched (D14, frozen forever).

### Fixed

- **`PO_Delay`'s `partially_received` gap.** A partially-received Purchase Order's remaining outstanding could be genuinely overdue and never show "Delayed" in the admin (PO detail page, Inventory Overview drill-down) — `is_line_delayed()` and `sql_line_delayed_predicate()` both gated on `status = 'placed'` only. Now also covers `partially_received`, mirroring the M5 precedent already applied to the sibling Incoming query. Deliberately not extended to `received` (INV-4 guarantees a fully received line's outstanding is always 0). This is the admin-side root cause the M7 storefront Resolver already defended against independently (Invariant M7-1) without fixing.
- The one live PHP 8.4 deprecation notice in the codebase (`WC_Inventory_Overview_Suppliers::validate()`'s implicitly-nullable parameter).
- 11 pre-existing test-content bugs across the FX, Movements, Costing, and Cost Adjustment characterization suites (stale method signatures, wrong class names, wrong column names, a stale bare-float return-shape assumption) — every fix verified against current, unmodified production behavior; zero production code changed, per the M0.14 golden-fixture governance rule. The full integration suite is now clean for the first time (245 tests / 834 assertions, 0 errors, 0 failures, 0 skips).

### Added

- **Repo-wide sibling-plugin-coupling conformance guard** (`tests/unit/conformance/test-no-sibling-plugin-coupling.php`) — the guard M7 explicitly deferred to "M8's conformance audit." Confirms, mechanically rather than by prose, ADR-0003's claim that this plugin has zero named dependency on any sibling plugin: every `class_exists()`/`function_exists()` check across all of `includes/` is on a closed WordPress/WooCommerce/PHP-core allowlist, zero `remove_filter()`/`remove_action()` calls exist, and zero hardcoded sibling-plugin identifiers appear anywhere.
- GA-scale (200-item) confirmatory performance tests extending the existing Inventory Position (D12) and Expected Delivery (Invariant M7-3) query-scaling guards, proven at ~20–40 items, to a size closer to a real catalog. Confirmatory only — no caching or optimization change.

### CI / Infrastructure

- The cumulative integration suite is now a normal CI-blocking gate (`tests.yml`'s `continue-on-error: true` removed) — closes `docs/testing.md`'s own named unblock condition.
- `Test_WC_IO_Close_Short_With_Qty_Received` (an M5 audit-remediation test) added to `run-phpunit.sh`'s blocking filter alongside the new conformance guard.
- `ci.yml` and `release.yml` aligned to PHP 8.4, the version `tests.yml` already exercised (previously 8.2 — validating lint/build/release against a PHP version nothing else in the pipeline tested).

### Explicitly not in this release

- No schema change of any kind — `DB_VERSION` stays `10`.
- No new domain concepts, no new public API surface, no new settings, no new hooks/filters.
- No general PHPCS cleanup (the pre-existing ~559 errors / 634 warnings are unchanged — evaluated and deliberately excluded as disproportionate to a hardening pass).
- No split of the `class-wc-inventory-overview-plugin.php` "god class" into tab controllers — real, documented tech debt with a named remediation direction, deliberately evaluated and deferred past GA rather than attempted under release-time pressure; not a silent drop.

### Testing

- Unit suite: 216 tests / 1,456 assertions (0 failures; 7 pre-existing risky `Test_DB_Transaction` tests, unrelated to M8).
- M1–M8-focused (CI-blocking) suite: 450 tests / 2,247 assertions (0 failures).
- Full integration suite: 245 tests / 834 assertions (0 errors, 0 failures, 0 skips) — now itself a CI-blocking gate.
- All seven architecture guard files (six pre-existing per-milestone guards plus this milestone's own repo-wide conformance guard) pass: 64 tests / 818 assertions.

## [1.24.0] - 2026-08-07

**Milestone M7 — Storefront Expected Delivery** — the first milestone that a customer sees. Exposes exactly **one** governed fact for an out-of-stock item: the earliest credible expected receipt, worded by confidence ("Expected back around 1 September" / "Expected during week 36" / "Expected soon") — never suppliers, PO numbers, quantities, or delay details. Ships behind a stable public API (`API_VERSION = 1`, versioned independently of the plugin version) that stays stable for its whole v1 lifetime: the interface's method set never shrinks, the `STATE_*` constants are never removed or repurposed, and `expected_date()` never changes format. **Schema unchanged (v10)** — zero new tables, columns, or indexes; M7 derives, it does not store. **Prerequisite:** v1.23.0 (M6 Migration & Retirement).

### Added

- **`WC_Inventory_Overview_Expected_Delivery_Service`** — the sole public API. `get_for_product()`/`get_for_products_bulk()` consume Inventory Position (D12) exclusively; nothing in M7 re-queries Purchase Orders, receipts, or receiving repositories directly. `get_for_product()` is defined as exactly `get_for_products_bulk()` returning the single element, so single and bulk can never disagree. Request-scoped memoization only, flushed on the plugin's four existing `wc_io_purchase_order_*` hooks — no persistent caching, because whether a date is still customer-safe depends on *today*, with zero write-side trigger to invalidate against.
- **`WC_Inventory_Overview_Expected_Delivery_Result_Interface`** / **`WC_Inventory_Overview_Expected_Delivery_Result`** — the public contract is the interface, not the concrete class. Four states (`STATE_IN_STOCK`/`STATE_UNAVAILABLE`/`STATE_EXPECTED_DATE`/`STATE_EXPECTED_SOON`), five accessors, immutable, `api_version()` informational only (never branch on it — the Service owns compatibility).
- **`WC_Inventory_Overview_Expected_Delivery_Resolver`** — pure, deterministic selection algorithm. **Invariant M7-1:** a customer-safe line's date is never in the past, regardless of the upstream `is_delayed` flag — closes a concrete customer-facing defect where a partially-received PO's remaining outstanding, or a non-zero delay grace period, could otherwise leave a stale date on the storefront indefinitely.
- **`WC_Inventory_Overview_Expected_Delivery_Renderer`** — the built-in, generic storefront renderer (filters `woocommerce_get_availability`). Not a fallback: the intended external consumer plugin was verified to be an empty directory, so this is the only renderer (see `docs/adr/0003-storefront-expected-delivery-ownership.md`). **Invariant M7-2:** an out-of-stock variable parent presents "Expected soon," never a specific date, regardless of how confident an individual variation's date is — the strongest claim that stays true no matter which variation the customer picks. **Invariant M7-3:** at most one product-scoped and one variation-scoped query per rendered page, regardless of item count (measured: 20 vs 40 mixed products issue the *same* query count).
- **One new setting**, `wc_io_expected_delivery_renderer_enabled` (default `yes`), on the existing Settings tab's new **Storefront** section.
- **Two extension filters**: `wc_io_storefront_render_expected_delivery` (generic per-render opt-out — checked before any query runs) and `wc_io_expected_delivery_text` (copy override).
- **Tests:** `tests/unit/expected-delivery/` and `tests/integration/expected-delivery/` (71 new tests / 218 assertions) covering the Result/Resolver contract and pure algorithm, architecture guards (sole-entry-point rule, D12 extended to the new Service, zero mutation, no sibling-plugin coupling), Service integration (including Invariant M7-2 and memo-flush-on-write), Renderer integration (the full bail ladder, both filters, the ISO-week year boundary), settings, and Invariant M7-3's equality-based query-scaling performance test.

### Documentation

- New: `docs/adr/0003-storefront-expected-delivery-ownership.md`, `docs/api-expected-delivery.md` (public API v1 reference for consumer-plugin developers), `docs/admin-guide-storefront-availability.md` (merchant-facing behavior guide).
- `CLAUDE.md` §2's storefront-ownership statement corrected (the previously-named sibling plugin is an empty directory); milestone status table updated. `docs/architecture-audit.md`'s "No public REST routes or storefront-facing hooks" line corrected — `woocommerce_get_availability` is now a documented, sole-owned storefront hook.

### Verified unchanged

- `DB_VERSION` stays `10`; `wc_io_schema_assertion` reports `ok: true` at `version: "10"`.
- The M3 Inventory Position architecture guard (`test_only_service_calls_bulk_repository_methods`) passes unmodified.
- Quick Restock, Cost Adjustment, Inventory Overview list table, Goods Receipts (M4), PO Receiving (M5), batch migration CLI (M6), and Supplier admin all behave exactly as in v1.23.0.
- In-stock and backordered products are byte-for-byte unchanged on the storefront.

### Important

M7 has the cleanest rollback story of any milestone in this program. The setting toggle is instant with no deploy (`wc_io_expected_delivery_renderer_enabled = no` restores stock WooCommerce output immediately). A code rollback 1.24.0 → 1.23.0 is unconditionally safe: M7 wrote no data, changed no schema, and mutated nothing — see the new M7 section in `docs/rollback-plan.md`.

## [1.23.0] - 2026-08-07

**Milestone M6 — Migration & Retirement** — the headline guarantee: migrating legacy Batch Intake history into Goods Receipts leaves current WooCommerce stock and cost **byte-for-byte unchanged** for every affected product, because migration is historical record materialization, not receiving — it never mutates current stock/cost, only writes a historical record in today's schema (verified by a dedicated golden/characterization test, a release blocker in its own right). Replaces the batch↔movement regex linkage with the typed `reference_type`/`reference_id` columns M4 already added, exactly as Architecture v1.0 §1 (D14) promised. Retires Batch Intake's ability to create new batches — the one thing it still did that Goods Receipts (M4/M5) hadn't already superseded — while leaving the legacy tables frozen, readable, and permanently the audit trail behind every migrated receipt. **Schema v10** — two migration-tracking columns on `wc_io_purchase_batches` (`migrated_receipt_id`, `migrated_at`); no new business-domain schema. **Prerequisite:** v1.22.0 (M5 PO Receiving).

### Added

- **`wp wc-io migrate-batches [--apply] [--verify] [--batch=<id>] [--rollback=<id>] [--limit=<n>]`** — operator-initiated, dry-run by default (modeled on `reconcile-qty-received`'s shape). `--apply` migrates one batch at a time through `WC_Inventory_Overview_Batch_Migration_Service::migrate_batch()`, each call its own transaction (Invariant M6-1 — never a shared transaction across batches, which is what makes an interrupted run safely resumable: earlier-migrated batches stay committed, the failed batch fully rolls back, and a rerun picks up exactly where it stopped). `--verify` is the permanent, read-only reconciliation tool for this data going forward. `--rollback=<id>` undoes one batch's migration (deletes its migrated receipt/lines/costs, clears its movement reference, clears its tracking columns) after an operator confirmation prompt — never a Goods Receipt void, and never touches current stock/cost either.
- **`WC_Inventory_Overview_Batch_Migration_Service`** — the migration engine. Every batch's migration is a pure function of that batch's own already-stored rows (Invariant M6-2 — order independence: batches may be migrated in any order with an identical result, since migration never reads current stock, current cost, or any other batch's data). Migrated receipts always carry `source = 'migrated'`, `supplier_id = NULL` (never fuzzy-matched from the batch's free-text supplier name), and `po_line_id = NULL` on every line (never a fabricated PO linkage, per D7 and M5's own binding note). Receipt numbers are allocated in the batch's *original* year, not the migration year; timestamps (`posted_at`/`created_at`/`updated_at`) are the batch's original `created_at`, not migration time (`wc_io_purchase_batches.migrated_at` records that separately).
- **`Goods_Receipt_Service::void()` migrated-source guard** — rejects voiding a `source = 'migrated'` receipt with a clear error. Voiding reverses stock/cost relative to *current* state, which is wrong for a historical replay row for the same reason forward-migration never calls `post()`.
- **`WC_Inventory_Overview_Landed_Cost_Types`** — the landed-cost-type vocabulary (7 slugs/labels, unchanged), extracted out of `Batch_Intake_Service` into a small, neutral class both `Goods_Receipt_Costing` and (while retained) `Batch_Intake_Service` depend on — closing the hidden-coupling remediation trigger M4's own plan flagged for this exact moment.
- **`WC_Inventory_Overview_Movements::backfill_reference()`** — the sole, purpose-built writer that updates one existing movement row's `reference_type`/`reference_id` in place (never inserts a new row, never touches quantity/cost/note/timestamp columns), used exclusively by migration.
- **Tests:** `tests/unit/batch-migration/` and `tests/integration/batch-migration/` (81 new tests) covering architecture guards (no live-mutation calls anywhere in the migration path), pure field-mapping, the historical-integrity golden test, movement backfill, idempotency/resumability, a deterministic forced-failure transactional-rollback test, order independence, rollback symmetry, retirement regression, the landed-cost-type extraction characterization, and per-batch query-cost performance — plus new coverage in `tests/integration/goods-receipt/` (migrated-void-guard) and `tests/integration/install/` (schema v9→v10 upgrade).

### Removed

- **Batch Intake's create/apply entry points** (`admin_post_wc_io_batch_apply`, `wp_ajax_wc_io_batch_preview`) — no new batch can be created after this release. The "Batch Intake" tab is gone from the Restock / Cost Adjustment admin nav (default subview becomes Quick Restock); a stale `restock_view=batch` bookmark now falls back to Quick Restock instead of erroring.

### Verified unchanged

- Every affected product's stock, average unit cost, and inventory value are byte-for-byte identical before and after a full migration run — the headline claim of this milestone, verified by a dedicated golden test across simple/EUR, USD-with-landed-cost, blended-existing-average, and multi-batch/multi-currency scenarios.
- Legacy `wc_io_purchase_batches`/`wc_io_purchase_batch_lines`/`wc_io_purchase_batch_costs` tables and rows are never dropped, truncated, or otherwise destroyed by any code path in this release (D14 — frozen, not destroyed).
- Quick Restock, Cost Adjustment, Goods Receipts (M4), PO Receiving (M5), Supplier admin, and Inventory Position are all unaffected by Batch Intake's retirement.
- `Batch_Intake_Service`'s create/apply/preview methods and `Batch_Intake_UI::render_panel()` are retained (marked `@deprecated`, disabled-not-deleted, slated for physical removal in M8) — not broken, just unreachable via the removed hooks.

### Important

Unlike M4/M5, this release does **not** introduce a new "code rollback is unsafe" risk class — see the new M6 section at the top of `docs/rollback-plan.md`: migrated Goods Receipts are purely additive rows a pre-M6 codebase never reads, so a plugin-code rollback to v1.22.0 is safe by construction even after batches have been migrated. See `docs/migration-guide-batch-intake.md` for the full operator runbook (backup, dry-run, apply, verify, rollback, and recovering from an interruption).

## [1.22.0] - 2026-08-06

**Milestone M5 — Purchase Order Receiving** — connects Purchase Orders (M2) to the Goods Receipt engine (M4): `qty_received` becomes a real, maintained column (full INV-4 formula), and receipt lines can now link to a PO line. `Goods_Receipt_Service` remains the sole stock/cost mutator and gains a second responsibility — sole business orchestrator for `qty_received` changes, delegated to a new sole-owner class. No second mutation path was introduced anywhere. **Schema v9** — one column addition, zero new tables (M4 had already prepared `receipt_line.po_line_id` and `goods_receipt.source` for this moment). **Prerequisite:** v1.21.0 (M4 Receipt Engine).

### Added

- **`qty_received` on `wc_io_purchase_order_lines`** (schema v9): the full INV-4 formula, `qty_outstanding = GREATEST(0, qty_ordered - qty_received - qty_cancelled)`. The forbidden-column guard from M2/M4 is lifted — the one `forbidden_columns` entry M5 is permitted to change. `Purchase_Order_Lines::increment_qty_received()` is the sole physical writer anywhere in the codebase (architecture-guard enforced).
- **`WC_Inventory_Overview_PO_Receiving_Sync`** — the sole owner of every `qty_received` mutation and its PO-status/PO-event side effects. `apply_line_delta()` (the normal receiving path) is called only by `Goods_Receipt_Service`, from inside its existing transaction, immediately after that line's stock mutation and movement insert succeed. `reconcile_line()` (the reconciliation path) is called only by the new CLI command. Neither method opens its own transaction. Three-tier ownership chain — orchestrator (`Goods_Receipt_Service`) → owner (`PO_Receiving_Sync`) → physical writer (`increment_qty_received()`) — enforced by dedicated architecture guards.
- **Two new PO statuses**, `partially_received` / `received`, auto-transitioned only via a pure, direction-agnostic recompute function (`PO_Statuses::recompute_for_receiving()` — the same current-state-relative design principle M4 used for void correctness, applied here to status). Never reachable through the operator-gated transition table; `cancel`/`close_short` remain available from `partially_received`, not from `received`; neither status is editable.
- **Receiving against a PO**: a "Receive" button on the PO detail page (gated by a new `RECEIVE_PO` capability, default `manage_woocommerce`) pre-fills a new Goods Receipt draft from the PO's outstanding lines — reusing the same `create_draft_from_post()` M4 already built, no new persistence method. The line editor's product picker gained an optional `po_line_id` per line; product-mismatch between a submitted line and its referenced PO line is rejected before any draft is even saved.
- **Mixed and multi-PO receipts**: one receipt may contain PO-linked lines (from one or more POs) alongside direct lines; `source` (`direct`/`po`/`mixed`) is derived from line composition, never operator-chosen.
- **Over-receipt, per D5**: never blocked. A line's quantity may exceed its PO line's current outstanding; the post-confirm screen warns explicitly, and the resulting PO event carries `over_receipt`/`qty_over` markers.
- **Five new PO event types**: `po_line_received`, `po_line_receipt_voided`, `po_partially_received`, `po_received`, `po_qty_received_reconciled` — closing the audit-trail gap M4's own Audit-trail decision explicitly reserved for this milestone (INV-6's "PO event log" clause, structurally inapplicable to M4's PO-less receipts, is now literally satisfiable).
- **Reconciliation tooling**: `wp wc-io reconcile-qty-received [--fix] [--po=<id>]` — read-only drift report by default; `--fix` repairs through `PO_Receiving_Sync::reconcile_line()` only, never bypassing the sole-writer chain. Every repair is individually logged and recorded as its own PO event; summary output reports verified/repaired counts.
- **Receiving history**: a bulk (not per-line) query on the PO detail page lists every receipt line fulfilling any of that PO's lines; Goods Receipt detail pages show a "Fulfils: PO-XXXX line N" back-link per PO-linked line. PO line rows gain a "Received" column.
- **Tests:** `tests/unit/po-receiving/` and `tests/integration/po-receiving/` (12 new files) covering the full formula, the status-recompute function's direction-agnostic behavior, both mandatory rollback regression scenarios (post-A/post-B/void-A, and post-A/post-B/void-B/void-A — order-independence), the forced-failure test proving stock/`qty_received`/PO-status roll back together, over-receipt, mixed/multi-PO receipts, pre-transaction validation, and the M3 Incoming regression M3's own plan deferred to this milestone.

### Fixed

- **M3's Inventory Position "Incoming" figure** now correctly reflects receiving: the raw SQL `GREATEST()` literal in `Purchase_Order_Lines::query_open_lines()` gained the `qty_received` term, and its `WHERE` clause now includes `partially_received`/`received` POs (previously `placed` only) so a partially-received PO's remaining outstanding still surfaces as Incoming.
- **Outstanding-quantity display** — the receipt line editor now shows a live "Outstanding: X.XXXX" figure next to the qty field for every PO-linked line, read fresh at render time (found missing during a pre-tag independent audit against the M5 plan's own Definition of Done).
- **Mandatory over-receipt warning** — the post-confirmation screen now shows a non-suppressible warning naming every over-receiving line and its over-received quantity whenever any line's quantity exceeds its current outstanding, reusing the same server-side over-receipt assessment already computed at post time (found missing during the same audit).
- **N+1 query pattern in pre-transaction PO-line validation** — `Goods_Receipt_Service::validate_and_assess_po_linked_lines()` now bulk-fetches every referenced PO line and owning PO (two new repository methods, `Purchase_Order_Lines::list_by_ids()` / `Purchase_Orders::list_by_ids()`) instead of one `get()` call per line; the performance test suite now verifies constant query cost and exercises the plan's own named ~100-line scale, previously untested past 4 lines.
- **`PO_Service::close_short()` and `qty_received`** — closing a PO short now cancels exactly a line's unreceived remainder (`qty_ordered - qty_received`) instead of the full ordered quantity, restoring the invariant `qty_received + qty_cancelled == qty_ordered` for lines closed short after a partial receipt (previously the displayed "Cancelled" figure could exceed what was actually never received).

### Verified unchanged

- `Restock_Service::apply_purchase_line_change()`/`apply_purchase_line_reversal()`'s caller set gained zero new entries — `PO_Receiving_Sync` never calls either method; all stock/cost mutation still flows exclusively through `Goods_Receipt_Service`.
- No header-level `po_id` column exists on `wc_io_goods_receipts` (D6: line-level linkage only, unchanged from M4).
- No new value is ever written to the per-line `wc_io_purchase_order_lines.status` bookkeeping column — "line completion" is derived from `qty_outstanding == 0`, never stored as a line-level enum.
- Batch Intake, Quick Restock, Cost Adjustment, and Supplier admin behavior are all unmodified.
- Every M4 architecture guard's disposition (kept unchanged / revised with a named replacement / retired with a named replacement) was individually verified, not assumed — none silently broken, none silently deleted.

### Important

Like M4, M5 mutates state that a code-only rollback cannot reverse: a plugin-code rollback to a pre-M5 version does **not** reverse the `qty_received`/PO-status effects of receipts already posted under M5 — see the extended note in `docs/rollback-plan.md`.

## [1.21.0] - 2026-08-06

**Milestone M4 — Receipt Engine (Goods Receipt)** — the first milestone that mutates WooCommerce stock and weighted-average cost through this plugin (D3/INV-2). Implements "Quick Receive Without PO" (D7): direct receipts, no PO linkage. **Schema v8** — three new tables plus an `inventory_movements` ALTER. **Prerequisite:** v1.20.0 (M3 Inventory Position).

### Added

- **Goods Receipt entity** (`wc_io_goods_receipts`, `wc_io_receipt_lines`, `wc_io_receipt_costs`): header/lines/landed-costs, three-state lifecycle (`draft → posted → voided`, no reopen), `GR-{YYYY}-{NNNN}` numbering (never-reuse, mirrors PO numbering). `receipt_line.po_line_id` exists (nullable, indexed) for M5 but is never populated by any M4 code path.
- **`WC_Inventory_Overview_Goods_Receipt_Service`** — the sole entry point for every M4 inventory mutation (structurally enforced by an architecture-guard test). `post()`/`void()` each run inside exactly one `WC_Inventory_Overview_DB_Transaction::run()` closure, with every fallible call routed through a `throw_if_error()` WP_Error→Exception bridge (`DB_Transaction::run()` only catches `Exception`). Forced-failure tests prove full SQL rollback: zero partial stock/cost/movement changes, receipt status unchanged.
- **`WC_Inventory_Overview_Restock_Service::apply_purchase_line_reversal()`** — voiding's current-state-relative reversal: subtracts only the voided receipt line's own stored delta from *current* stock/average, not a snapshot restore, so it composes correctly no matter how many other receipts posted against the same product in between. Rejects (does not partially apply) when the resulting stock would go negative.
- **Landed-cost allocation** (`WC_Inventory_Overview_Goods_Receipt_Costing`): proportional-by-line-value formula ported from `Batch_Intake_Service`, remainder to the last line.
- **Movement provenance**: `TYPE_GOODS_RECEIPT` / `TYPE_GOODS_RECEIPT_VOID` movement types; `wc_io_inventory_movements` gains `reference_type` / `reference_id` / `supplier_id` (nullable — existing `purchase`/`purchase_batch`/`cost_adjustment` inserts unaffected).
- **Idempotency**: one-shot request tokens (`gr_post`/`gr_void` contexts, reusing `PO_Request_Token`) consumed as the very first statement of `post()`/`void()`, plus a compare-and-swap status `UPDATE ... WHERE status = %s` as the transaction's first write — the complete M4 concurrency model (no row locking, no `SELECT ... FOR UPDATE`, deliberately).
- **"Receive Stock" admin tab**: draft create/edit/delete, product/variation picker (excludes variable parents, grouped, external, non-stock-managed products), landed-cost rows, computed preview, explicit post-confirmation screen, void with mandatory reason, read-only posted/voided view. Alongside — not replacing — Batch Intake, Quick Restock, and Cost Adjustment.
- **Capabilities**: `VIEW_RECEIPT` / `EDIT_RECEIPT` / `POST_RECEIPT` / `VOID_RECEIPT` / `DELETE_RECEIPT`, defaulting to `manage_woocommerce` through the existing filterable map (no new WordPress capability); enforced both in the admin controller and independently inside every service mutation method.
- **Tests:** `tests/unit/goods-receipt/` (numbering, lifecycle, 16-test architecture guard) and `tests/integration/goods-receipt/` (repositories, costing/allocation, Restock reversal in isolation, transactional post/void including the intervening-receipt void regression, idempotency, capability) — 230 tests / 1,039 assertions. `tests/docker/run-phpunit.sh`'s blocking filter now includes the `Test_WC_IO_Goods_Receipt_` family alongside the existing M1/M2/M3 prefixes.

### Fixed

- **Object-cache/rollback divergence** (found during M4 implementation, not merely anticipated): a rolled-back `post()`/`void()` correctly reverted the underlying SQL row, but WordPress's own `update_post_meta()` writes through to the object-cache `post_meta` group synchronously — a write a SQL `ROLLBACK` cannot reach on a persistent cache backend (Redis, on this deployment). Fixed by calling `clean_post_cache()` for every touched product on both the commit and the rollback path (pure invalidation, safe regardless of transaction outcome).

### Verified unchanged

- Schema is additive; `qty_received` still absent from `wc_io_purchase_order_lines` (forbidden-column guard unchanged from v7).
- No PO table (`wc_io_purchase_orders`, `wc_io_purchase_order_lines`, `wc_io_po_events`) is ever written by M4 — no PO linkage, no `qty_received`, no PO events, no PO status/quantity change (verified by the architecture guard).
- Batch Intake, Quick Restock, Cost Adjustment, Purchase Order admin, Supplier admin, and Inventory Position behavior are all unmodified.
- M0 golden suite and existing characterization fixtures unchanged; the cumulative integration suite's 13 pre-existing failures (4 errors + 7 failures + 2 skips, documented in `docs/testing.md`) are unchanged in count and identity — M4 introduced zero new failures.

### Important

Unlike M1–M3, M4 mutates WooCommerce stock and cost. A plugin-code rollback to a pre-M4 version does **not** reverse the stock/cost/value effects of Goods Receipts already posted under M4 — see the new prominent note in `docs/rollback-plan.md`.

## [1.20.0] - 2026-08-05

**Milestone M3 — Inventory Position** — a first-class, read-only Inventory Position ({On Hand, Incoming, Position}, D11) for every simple product and variation, surfaced on Inventory Overview. **No schema change, no migration** — `DB_VERSION` remains `7`. **No receiving** — no Goods Receipts, no stock/cost mutation, no `qty_received`; M4/M5 will extend the Incoming formula once receiving exists. **Prerequisite:** v1.19.1 (M2 test-infrastructure hotfix).

### Added

- **Inventory Position Resolver** (`WC_Inventory_Overview_Inventory_Position_Resolver`): stateless, read-only calculator — `Position = On Hand + Incoming` — independent of `$wpdb`, WooCommerce product loading, and PO repositories.
- **Inventory Position Service** (`WC_Inventory_Overview_Inventory_Position_Service`): the sole authoritative calculator (D12), single (`get_position()`) and bulk (`get_positions_bulk()`); aggregates independent contributing PO lines in PHP, retains them individually (`incoming_lines`) for drill-down, and never refetches WooCommerce products or writes data.
- **Bulk open-line repository reads** on `WC_Inventory_Overview_Purchase_Order_Lines`: `list_open_lines_for_product_ids()` and `list_open_lines_for_variation_ids()` — two separate, safely-prepared queries (never one OR-based query), qualifying on PO header `status = placed` only, reusing `WC_Inventory_Overview_PO_Delay::sql_line_delayed_predicate()` for the delayed flag.
- **Incoming and Position columns** on Inventory Overview, next to Stock, gated to `manage_woocommerce` at the same sensitivity tier as average cost / inventory value (no new capability).
- **Per-supply drill-down**: reuses the existing details-toggle/expandable-details pattern (including a new expandable detail row per variation, completing that pattern for variation rows). Each contributing PO line renders independently — PO number/link, outstanding quantity, expected date, confidence, delayed indication — never merged, even when two lines share a date or supplier (INV-1/INV-7).
- **Variable-parent presentation rollup**: parent Incoming/Position are a presentation-only sum of child-variation figures; no incoming record is ever created against a variable parent (INV-8). Child variations retain individual figures and drill-downs.
- **Composable states**: low/out-of-stock badges, Incoming, Position, and delayed indication all display simultaneously — never mutually exclusive.
- **Bulk-fetch sequencing**: `get_positions_bulk()` is called exactly once, after the complete product/variation groups structure (including variations discovered by the later per-parent query) is built — no per-row Position queries, verified by a query-scaling regression test over 20+ mixed simple/variation items.
- **Tests:** `tests/unit/inventory-position/` (resolver, D12 architecture guards) and `tests/integration/inventory-position/` (repository, service, list table) — 44 tests / 137 assertions. `tests/docker/run-phpunit.sh`'s blocking filter now includes the `Test_WC_IO_Inventory_Position_` prefix alongside the existing M1/M2 prefixes.

### Verified unchanged

- No schema change; `DB_VERSION` remains `'7'`; `qty_received` still absent from `wc_io_purchase_order_lines`.
- Supplier behavior, PO lifecycle/mutation behavior, Purchase Order admin screens, and `WC_Inventory_Overview_PO_Delay` / `PO_Quantities` / `PO_Expected` behavior are unmodified.
- M0 golden suite and existing characterization fixtures unchanged; the cumulative integration suite's pre-existing failures (documented in `docs/testing.md`) are unchanged by this milestone.

## [1.19.1] - 2026-08-05

**Test Infrastructure Hotfix** — test/CI infrastructure repair only. No database schema, migration, business-behavior, or UI changes. `DB_VERSION` remains `7`.

### Fixed

- `tests/docker/run-phpunit.sh` never ran `composer install` for the plugin's own `composer.json` (which already declares `phpunit/phpunit`, PHPCS, etc. as dev dependencies), so `vendor/bin/phpunit` never existed and every run failed immediately with `Could not open input file: vendor/bin/phpunit`. Added the missing install step.
- `tests/bootstrap.php` loads WooCommerce via a direct `require` rather than WordPress's normal `activate_plugin()` flow, so WooCommerce's own activation routine (which grants the `manage_woocommerce` capability to the administrator role) never ran. Every Purchase Orders admin-handler test failed with "Insufficient permissions." as a result. Added an explicit `WC_Install::create_roles()` call in the test bootstrap (standard practice in third-party WooCommerce extension test suites).
- `phpunit.xml.dist` declared `failOnDeprecated="true"`, an attribute that does not exist in any PHPUnit 9.x schema (confirmed against the installed `vendor/phpunit/phpunit/schema/*.xsd`); PHPUnit silently ignored it. Removed as a no-op cleanup.
- `tests/docker/docker-compose.phpunit.yml` had no explicit Compose project `name:`, defaulting to the generic directory name `docker` and risking collisions with other ephemeral stacks on a shared host. Added `name: wc-io-phpunit`. Also removed a stale, already-overridden `WP_TESTS_PHPUNIT_POLYFILLS_PATH` environment value that pointed at a path the provisioning script never actually uses.

### Added

- `.github/workflows/tests.yml` gained a `phpunit` job that runs the unit suite and the M2-focused suite (both blocking) plus the cumulative integration suite (visible in the Actions log, `continue-on-error: true` pending the known pre-existing test-content issues below — never silently reported as green).

### Documentation

- `tests/README.md` rewritten — it previously documented an old, non-functional `docker-compose.test.yml`/`seed.sh` workflow instead of the actual `docker-compose.phpunit.yml`/`run-phpunit.sh` harness.
- `docs/testing.md` updated: corrected CI/CD table, added PHPCS status section, added an itemized "Known test-content issues" section for pre-existing failures surfaced now that the suite can finally execute to completion (all in M0-era golden characterization tests -- costing, FX, movements, cost-adjustment, batch-intake -- none in M2/Purchase Orders code).

## [1.19.0] - 2026-08-05

**Milestone M2 — Purchase Orders** — PO aggregate, four-state lifecycle, events audit log, expected-receipt dates with confidence, delayed detection, Purchasing admin UI. Schema v7. **Prerequisite:** v1.18.1 (M1 Purchasing PRG hotfix). **No receiving** — stock and `qty_received` remain out of scope until M5.

### Added

- **Purchase Order aggregate** (schema v7): `wc_io_purchase_orders`, `wc_io_purchase_order_lines`, `wc_io_po_events` tables.
- **Four-state lifecycle:** `draft` → `placed` → `cancelled` | `closed_short`; terminal statuses (`cancelled`, `closed_short`) are absorbing.
- **PO numbering:** `PO-{YYYY}-{NNNN}` format, never reused; unique index on `po_number`. Concurrency model documented in ADR-0002.
- **PO Service** (`WC_Inventory_Overview_PO_Service`): transactional create, update, place, cancel, close short, duplicate, line CRUD; all mutations via explicit DB transactions.
- **PO events:** append-only audit log with optional reason codes (`supplier_change`, `price_change`, `quantity_change`, `schedule_change`, `manual`, `other`).
- **Expected receipt:** header and optional line-level `expected_date` + confidence (`exact` / `estimated` / `unknown`).
- **Delayed detection:** computed condition for placed POs past effective expected date + grace days (`WC_Inventory_Overview_PO_Delay`).
- **Purchasing → Purchase Orders admin tab:** list (status views, delayed filter, search), create/edit detail, event timeline, PRG `admin-post` handlers (save, place, cancel, close short, delete draft, duplicate) with nonces and request tokens.
- **Purchasing capabilities map** (`WC_Inventory_Overview_Purchasing_Caps`): filterable action→capability defaults (`manage_woocommerce`).
- **Assets:** `assets/purchasing.css`, `assets/po-admin.js` (product line editor on PO detail).

### Modified

- `DB_VERSION` '6' → '7'.
- `includes/class-wc-inventory-overview-install.php`: v7 DDL, `expected_schema_v7()`, canonical `wc_io_schema_assertion` option, forbidden-column guard (rejects `qty_received` on PO lines until M5).
- `includes/class-wc-inventory-overview-purchasing-page.php`: Purchase Orders tab (default), delegates PO panel to `PO_Admin`.
- `wc-inventory-overview.php`: require M2 PO classes (statuses, lifecycle, service, admin, list table, etc.).

### Technical

- New files: `includes/class-wc-inventory-overview-po-*.php`, `includes/class-wc-inventory-overview-purchase-orders.php`, `includes/class-wc-inventory-overview-purchase-order-lines.php`, `includes/class-wc-inventory-overview-purchase-orders-list-table.php`, `includes/class-wc-inventory-overview-purchasing-caps.php`, `assets/po-admin.js`, `assets/purchasing.css`, `docs/adr/0002-po-number-allocation-concurrency.md`, `docs/milestones/m2-implementation-plan.md`.
- Test files: `tests/unit/purchase-orders/test-po-*.php` (lifecycle, numbering, service, validation, delay, admin, architecture); extended `tests/integration/install/test-schema-shape-assertion.php`.
- Test harness: `tests/docker/docker-compose.phpunit.yml`, `tests/docker/run-phpunit.sh`.

### Notes

- **No stock or costing changes:** PO lifecycle actions do not mutate WooCommerce stock or weighted-average cost meta.
- **No receiving:** `qty_received`, Goods Receipt, and Receive-Against-PO arrive in M5; schema assertion actively forbids premature receiving columns.
- **No print hooks:** printable PO is a reserved future capability; no `wc_io_po_print_actions` or similar public hook added.
- **M0 golden suite:** passes unmodified; zero fixture changes to costing/FX/allocation/movement characterization tests.
- **Numbering concurrency:** duplicate-key failures under rare concurrent draft creates are a documented limitation (ADR-0002), not a correctness defect.

## [1.18.1] - 2026-08-05

**M1 hotfix** — supplier Purchasing admin PRG and list-table fixes. No schema change (remains v6).

### Fixed

- Supplier save / archive / reactivate admin-post handlers now call `wp_safe_redirect()` + `exit` (were incorrectly calling `wp_safe_remote_post()`, leaving a blank `admin-post.php` page).
- List-table Archive / Reactivate row actions use nonce-checked `admin-post` URLs and are handled by the same handlers (previously unnonced GET links that never routed).
- Active / Archived / All views from `get_views()` are rendered on the suppliers list.
- Create/update success redirect lands on the edit screen with a `saved` notice (removed dead identical ternary).
- Supplier `default_currency` validation accepts form values after `sanitize_key()` (uppercase EUR/USD/SEK).

### Technical

- Touched: `includes/class-wc-inventory-overview-purchasing-page.php`, `includes/class-wc-inventory-overview-suppliers-list-table.php`, `includes/class-wc-inventory-overview-suppliers.php`.
- Tests: `tests/integration/suppliers/test-suppliers-admin-prg.php`; `tests/includes/test-case.php` `flush_cache()` visibility for modern WP PHPUnit.

### Notes

- Patch release on the M1 baseline. Distinct from M2 / v1.19.0 Purchase Orders work.

## [1.18.0] - 2026-08-04

**Milestone M1 — Suppliers** — first-class supplier entity, Purchasing admin page (Suppliers section), seed migration from historical supplier strings, schema v6.

### Added

- **Supplier entity**: new `wc_io_suppliers` table with name, normalized-name (dedupe key), default currency, configured lead time, contact fields, status (active/archived).
- **Supplier service** (`WC_Inventory_Overview_Suppliers`): full CRUD, `get()`, `get_by_normalized_name()`, `list()`, `count()`, `create()`, `update()`, `archive()`, `reactivate()`. No hard-delete.
- **Supplier normalization**: whitespace collapse + trim + casefold only (no punctuation stripping, no suffix removal, no accent folding).
- **Schema-shape assertion** (`assert_schema_shape()`): generic mechanism checking table existence, column presence, unique index presence. Extends by milestone (e.g. M2 adds v7 assertion). Gating mechanism for new features.
- **Purchasing admin page** (new submenu under WooCommerce, uniform `manage_woocommerce` capability):
  - **Suppliers tab**: list with pagination, search, Active/Archived views; detail/create/edit with all §11.1 fields (name, currency, lead time, email, phone, supplier reference, note); archive/reactivate actions.
  - **Tab structure**: extensible for M2+ (Purchase Orders, Receive Stock tabs).
- **Idempotent seed migration** (`WC_Inventory_Overview_Suppliers_Migration`): distinct historical `supplier_name` strings from batches + movements → normalized → deduplicated supplier rows. Deterministic 3-step tie-break (most-frequent original string, earliest created_at, alphabetical strcmp). Persists report to `wc_io_supplier_seed_migration_report` option.
- **Supplier autocomplete**: dedicated JS + own nonce on Batch Intake's `wc-io-batch-supplier` and Quick Restock's `wc-io-supplier` inputs; "+ create supplier" inline quick-create affordance; AJAX handlers `wc_io_search_suppliers`, `wc_io_quick_create_supplier`.
- **Action hook**: `wc_io_supplier_created` (post-commit, full-row payload).

### Modified

- `DB_VERSION` '5' → '6' (first schema-bumping milestone).
- `includes/class-wc-inventory-overview-install.php`: `create_tables()` adds `wc_io_suppliers` DDL; `activate()`/`maybe_upgrade()` call `assert_schema_shape()` + conditional `Migration::run()`.
- `wc-inventory-overview.php`: require the four new classes (service, migration, list table, page controller).
- `includes/class-wc-inventory-overview-plugin.php`: `init()` instantiates Purchasing_Page; `enqueue_restock_assets()` enqueues supplier-picker.js + localized data.
- `includes/class-wc-inventory-overview-batch-intake-ui.php`: additive picker markup + quick-create modal.
- `tests/includes/test-case.php`: additive `create_supplier()` helper.
- `docs/architecture-audit.md`, `docs/release-runbook.md`, `docs/checklists/deployment-checklist.md`: M1-specific updates per §R6.

### Technical

- New files: `includes/class-wc-inventory-overview-suppliers.php`, `includes/class-wc-inventory-overview-suppliers-migration.php`, `includes/class-wc-inventory-overview-suppliers-list-table.php`, `includes/class-wc-inventory-overview-purchasing-page.php`, `assets/supplier-picker.js`, `docs/admin-guide-suppliers.md`.
- Test files: `tests/unit/suppliers/test-normalization.php`, `tests/integration/suppliers/test-suppliers-crud.php`, `tests/integration/suppliers/test-suppliers-migration.php`, `tests/integration/suppliers/test-suppliers-autocomplete-ajax.php`, `tests/integration/suppliers/test-suppliers-capabilities.php`, `tests/integration/install/test-schema-shape-assertion.php`, `tests/fixtures/suppliers/fixture-migration-*.php`.
- Test infrastructure: new test helpers in `tests/includes/test-case.php`.

### Notes

- **M0 golden suite regression**: Full M0 golden test suite (weighted-average, FX, allocation, movements, batch preview/apply) passes unmodified. Zero behavioral changes to existing costing/FX/allocation logic.
- **Backward compatibility**: Legacy free-text supplier fields in Batch Intake/Quick Restock remain unchanged; no `$_POST` handling modification. Supplier autocomplete is purely additive (zero-named select element).
- **No data loss**: Seed migration is idempotent (run twice = identical result); `wc_io_purchase_batches` and `wc_io_inventory_movements` tables untouched.
- **Purchasing menu** only appears when schema assertion passes and user has `manage_woocommerce` capability.

## [1.17.3] - 2026-08-03

**Milestone M0 — Delivery Foundations** — automated test suite infrastructure and characterization tests (zero functional changes).

### Added

- **Test infrastructure**: PHPUnit, PHPCS, GitHub Actions CI/CD workflow.
- **Docker-based test environment**: ephemeral WordPress+WooCommerce stack for isolated testing (`tests/docker/docker-compose.test.yml`).
- **Golden fixtures and characterization tests**: Frozen behavior specifications for weighted-average costing, FX resolution, landed-cost allocation, batch preview/apply parity, movement records, and cost adjustments.
- **DB-transaction helper**: Reusable database transaction wrapper with SAVEPOINT support (built in M0, integrated into M4+).
- **Release rehearsal templates**: Release runbook, deployment, rollback, and validation checklists (reused by every future release).
- **Test documentation**: Philosophy, fixture governance rule, running and extending tests.

### Technical

- `composer.json`, `composer.lock` — development dependencies (PHPUnit, PHPCS, WordPress coding standards).
- `phpunit.xml.dist`, `phpcs.xml.dist`, `.phpcs-baseline.xml` — test configurations.
- `.github/workflows/tests.yml` — CI workflow (PHPUnit + PHPCS + PHP Lint).
- `includes/class-wc-inventory-overview-db-transaction.php` — transaction helper (inert until M4).
- `docs/testing.md`, `docs/release-runbook.md`, `docs/checklists/` — documentation and reusable release templates.

### Notes

- **No plugin behavior changes** — version 1.17.3 functions identically to 1.17.2. Test infrastructure is a pure-tooling addition excluded from the release ZIP.
- The test suite, while comprehensive, is **not** part of the distributed plugin; it ships only in the GitHub repository.
- Golden fixtures lock current behavior as the regression baseline for all future milestones.

## [1.17.2] - 2026-05-19

**Standalone repository releases** — canonical GitHub home is [magpern/wc-inventory-overview](https://github.com/magpern/wc-inventory-overview) with `v*` tags.

### Added

- `includes/class-github-updater.php` — queries this repo's GitHub Releases (`/releases/latest`); installs `wc-inventory-overview-X.Y.Z.zip` only.
- `.github/workflows/ci.yml` and `.github/workflows/release.yml`.
- Disable on dev: `WC_INVENTORY_OVERVIEW_DISABLE_GITHUB_UPDATER` or filter `wc_inventory_overview_github_updater_enabled`.

### Notes

- No intentional plugin behavior changes vs **1.17.1**.

## [1.17.1] - 2026-05-19

**Packaging-only release** — production ZIP and GitHub Release automation for the `biopentra-custom-plugins` monorepo. **No intentional plugin behavior changes** vs 1.17.0.

### Added

- Monorepo release tooling: `scripts/build-one-plugin-zip.sh`, `scripts/release-audit-plugin.sh`, `scripts/lib/verify-release-zip.py`.
- GitHub Actions workflow `.github/workflows/release-wc-inventory-overview.yml` (tag `wc-inventory-overview-v*`).
- Distribution files: `readme.txt`, `LICENSE`, this changelog.

### Changed

- Production ZIP excludes `cli/` and other dev-only paths; ships runtime code and `assets/` only.

### Notes

- WP-CLI maintenance scripts remain in the Git repository under `cli/` — not included in the distributed ZIP.
- Tag format: `wc-inventory-overview-v{version}`.

## [1.17.0]

Prior feature releases tracked in the WooCommerce project; see monorepo git history for `plugins/wc-inventory-overview/`.
