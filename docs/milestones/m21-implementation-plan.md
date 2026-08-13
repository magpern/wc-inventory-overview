# M21 Planning Report — wc-inventory-overview

## Context

M20/v1.37.0 (Admin Controller Decomposition, Phase 3) is released and the three-phase
decomposition program (M18–M20) is complete: `WC_Inventory_Overview_Plugin` is now a
410-line composition-root/shell, down from 2,706 lines. The prompt that produced this
plan explicitly requires M21 to return to **functional product development** — not
another refactor, not a bug-fix bundle, not a speculative infrastructure milestone.

This report was produced by: (1) verifying the baseline directly against the repo
(HEAD, tags, `DB_VERSION`), (2) three parallel research passes over docs/roadmap,
architecture/schema, and deferred-feature status, and (3) direct reads of the exact
production code this milestone will touch, to pin every design decision to what the
code actually does today rather than to summaries. Every architectural choice below
resolves by direct precedent already established in this codebase (D12, D13, INV-8,
and the M9/M11/M16 "read-only, reuses an existing service, zero schema" milestone
pattern) — there is no unresolved fork requiring a business decision before
implementation can start.

---

## 1. Verified baseline

- Repo: `/opt/biopentra/dev/wc-inventory-overview`, branch `main`, clean working tree.
- `HEAD` = `153e4d8de233dc2ae9b89d5f189e97a5136d9b72` — **matches the expected baseline exactly.**
- Latest release: `v1.37.0`, release merge `bf57b356cfd441b45f176d33e3ac3159370dfd0b` — **confirmed in `git log`.**
- `WC_Inventory_Overview_Install::DB_VERSION = '11'` — **confirmed in `includes/class-wc-inventory-overview-install.php`.**
- Released milestones M13–M20 all present in CHANGELOG.md and `docs/milestones/`, all ✅ Complete in CLAUDE.md's Implementation Status table.
- No M21 branch, plan, or implementation exists anywhere in the repo (verified: `docs/milestones/`, `docs/checklists/`, git branches/tags all stop at M20/v1.37.0).

## 2. Current product/architecture assessment

- **Plugin class** (`includes/class-wc-inventory-overview-plugin.php`, 410 lines) is confirmed to be a pure composition-root/shell: singleton bootstrap, tab routing/dispatch, menu registration, legacy-URL redirects. Zero `$wpdb` queries, zero business logic remain.
- **Five tab controllers** now own all Inventory & Profit hub behavior: `Dashboard_Controller`, `Settings_Controller`, `Reporting_Controller` (Movements/Order Profit/Product Profitability), `Overview_Controller` (Inventory Overview), `Restock_Controller`. A separate **Purchasing** admin page/menu (`Purchasing_Page`, `PO_Admin`, `Goods_Receipt_Admin`) owns Suppliers/Orders/Receipts.
- **Domain maturity**: Purchase Orders, Suppliers, Goods Receipts (receiving), and Inventory Position are all first-class, fully shipped, mature domain models with sole-owner services (`PO_Service`, `Goods_Receipt_Service` — INV-2 sole stock mutator, `Inventory_Position_Service` — D12 sole calculator) and dedicated repositories. Receiving specifically (D3/D4/D5/INV-2, shipped M4/M5, GA since v1.22.0) is a **complete** three-state (`draft→posted→voided`) lifecycle with PO-linked sync, over-receipt handling, and reconciliation tooling — not a gap.
- **Supplier/purchasing feature backlog is exhausted**: every item that was once listed in `docs/admin-guide-suppliers.md`'s "Not Yet Available" section (lead-time stats, expected-date suggestion, on-time rate, order history, spend summary, supplier merge) shipped across M9–M17. No pre-written "next obvious feature" remains in that line.
- **Warehouse/location concepts genuinely do not exist** in code (zero hits repo-wide) and are explicitly named as out of the current single-warehouse business context (CLAUDE.md §2, `docs/milestones/m16-implementation-plan.md`).
- **Reservations and Coverage/Forecast are architecturally blocked, not merely unbuilt**: D16 bans speculative schema/APIs "until a concrete consumer exists" (Reservations); Coverage/Forecast needs sales-velocity data this plugin does not collect anywhere today (confirmed: no order-velocity tracking exists).
- **D13 is directly load-bearing for this milestone**: *"Low-stock and incoming are composable states, shown simultaneously — never mutually exclusive."* This is an existing, frozen architectural decision — not something M21 invents — and it is currently only half-honored: "Low stock" (raw on-hand vs. threshold) and "Incoming"/"Position" (On Hand + Incoming, M3) are both shipped, but they've never been cross-referenced. A product can show a "Low stock" badge while an open PO already covers it, or vice versa the merchant has no way to see, from the low-stock signal itself, whether incoming supply already resolves it.

## 3. Remaining-roadmap reconstruction

No forward-looking backlog document exists (CLAUDE.md documents history only through M20). The candidate ladder below was reconstructed from: architecture-audit.md's per-milestone "explicitly excluded" lists, the exhausted-backlog finding in §2, the two M20-acceptance defect observations, and direct code reading.

| # | Title | Capability | Dependency | Size | Risk | Schema | Mutation |
|---|---|---|---|---|---|---|---|
| **M21** | **Position-Aware Reorder Signal** | Distinguish "genuinely needs reorder" from "low stock but already covered by incoming PO supply" | M3 Inventory Position (shipped) | S/M | Low | None | None (read-only) |
| M22 | Reorder → Draft PO Quick Action | One-click draft-PO creation from a Needs-Reorder item | M21 | M | Medium | None (likely) | Yes (creates draft PO) |
| M23 | Storewide Supplier Spend Rollup | Cross-supplier, per-currency purchasing spend view (extends M15) | M15 (shipped) | S/M | Low | None | None |
| M24 | Inline-Stock AJAX Hardening & Ledger Parity | Reject negative/non-numeric quantities; write a movement-ledger row for inline edits (see §5 defect assessment) | None | S | Low–Medium | None | Yes (existing path, hardened) |
| M25 | Goods Receipt Attachments | Attach a supplier invoice/document to a posted receipt | M4/M5 Receiving (shipped) | M/L | Medium | Yes (new table) | Yes (new attachment CRUD) |
| M26 | Sales-Velocity Data Foundation | Prerequisite data pipeline — first slice toward eventually unblocking D11's Coverage/Forecast | None | L/XL | Medium–High | Likely yes | New (rollup writes) |

**Retired / not sequenced:**
- **Reservations** — remains blocked on D16 (no concrete consumer). Not retired outright, but not scheduled; revisit only if a concrete consumer emerges (e.g., a future checkout-hold feature).
- **Warehouse/multi-location** — retired as obsolete for the current business context. CLAUDE.md explicitly frames this as a single-warehouse plugin; resurrecting this is a business decision (the shop operating multiple locations), not an engineering-roadmap item.

**Reordering notes:** M23 (spend rollup) is fully independent of M21/M22 and could run before or after them without penalty. M24 is a small, independently-shippable hygiene fix that does **not** need to wait for M21 — see §5. M25 and M26 are both larger, schema-touching initiatives that should not be pulled forward; each needs its own dedicated discovery/planning pass when its turn comes (M26 in particular is likely to need splitting further, exactly the trap this prompt warned against repeating for receiving).

## 4. Candidate comparison — why M21 over the alternatives

- **M22** (quick-PO-from-reorder) is the natural *next* step after M21 but depends on it — you cannot offer a one-click PO from a "needs reorder" signal that doesn't exist yet. It also has a genuine open design question (which supplier to prefill when a product has purchase history with more than one) that deserves its own planning pass rather than being folded into M21's otherwise zero-mutation scope.
- **M23** (storewide spend rollup) is a reasonable alternative first pick — independent, low-risk, no schema. It loses to M21 only on merchant value: a cross-supplier finance rollup is a nice-to-have reporting extension, whereas M21 closes a real, currently-experienced gap (D13's own promise) affecting the plugin's single most-used operational signal (Low Stock, surfaced on both the Dashboard and Overview screens).
- **M24** (AJAX hardening) is a real, evidenced defect (see §5) but is explicitly disqualified from being "M21" by this prompt's own instructions: it is a bug fix, not a coherent product capability, and bundling it into M21 would violate the "one milestone = one primary product concept" principle. It should ship as its own small, fast milestone/patch, independent of this sequencing.
- **M25** (receipt attachments) and **M26** (sales-velocity foundation) are both larger, schema/security-sensitive initiatives that would risk becoming oversized if compressed into "the next milestone" — exactly the trap this prompt names for receiving history. Both are legitimate, but belong later.

**M21 wins on**: zero schema change, zero mutation surface (100% read-only, lowest possible risk of any candidate), full reuse of two already-shipped, already-tested domain concepts (Inventory Position from M3, the low-stock threshold from the plugin's earliest low-stock-badge code) with no new domain owner, immediate and universally-relevant merchant value (every managing-stock product in every catalog is a candidate), and a scope so bounded that this plan can freeze every method signature up front (see §11).

## 5. Backlog assessment: the two M20-observed behaviors

Both are confirmed real, with exact file/line evidence, and were explicitly assessed as **not automatically M21** per this prompt's instructions.

- **G1 — WooCommerce stock-status auto-recompute on save.** Core WooCommerce behavior (`WC_Product::save()` recomputes stock status from quantity when `managing_stock()` is true), not a plugin defect. Already documented in `docs/checklists/m20-release-readiness.md:126`. **Severity: informational.** No code change recommended; if ever addressed, it would be a UI-hint addition, not a functional fix, and does not warrant its own milestone.
- **G2 — Inline-stock AJAX accepts negative/non-numeric quantities.** Confirmed in `WC_Inventory_Overview_Overview_Controller::ajax_save_inline_stock()` (`includes/class-wc-inventory-overview-overview-controller.php:275-308`): the handler checks `isset($_POST['stock_qty'])` only, then calls `wc_stock_amount()` and writes directly via `set_stock_quantity()`/`save()` with **no `>= 0` check** — unlike `Restock_Service`, which explicitly rejects `$qty_added <= 0`. It also writes no movement-ledger row (every other stock-changing path in the plugin does), a related, same-code-path gap. **Severity: Medium** — genuine data-integrity risk (a stray negative value corrupts downstream Position/valuation calculations, and the auto-computed `set_stock_status()` interacts badly with negative-then-clamped values), gated behind `edit_products` + nonce so not an unauthenticated/security issue, but real. **Recommended treatment**: ship as its own small, fast, independent patch/milestone (listed as M24 in §3) — explicitly **not** folded into M21, per this prompt's own instruction not to turn M21 into a bug-fix bundle.

## 6. Recommended M21 title

**M21 — Position-Aware Reorder Signal**

## 7. Why this is next

M21 completes an existing, frozen architectural promise (D13: low-stock and incoming shown simultaneously, never mutually exclusive) that has been half-true since M3 shipped Inventory Position (v1.20.0) without ever being cross-referenced against the plugin's original low-stock badge (which predates M3 and has never been touched by it). It reuses two already-mature, already-tested subsystems with zero new domain concepts, zero schema change, and zero mutation surface — the lowest-risk possible "return to functional development" after three refactor-only milestones. It also directly fixes a real, if minor, merchant-facing pain point: today a product can show an alarming "Low stock" badge on the Dashboard/Overview screen even though a PO is already inbound to fix it, causing false urgency and (left unaddressed) risk of unnecessary duplicate reordering.

## 8. Scope

In scope:
- A new pure, stateless classifier (`Reorder_Signal_Resolver`) that, given an item's already-known Position and low-stock threshold, answers: does this still need reordering, or is incoming supply already enough?
- Surfacing that classification, additively, on: the Inventory Overview list-table row badges (simple products, variations, and variable-parent rollups), the Inventory Overview summary cards, the Dashboard KPI row, and the Dashboard "Recent Low Stock Items" table.
- Extending the two existing `Summary` methods (`build()`, `get_low_stock_lines_for_chart()`) additively to carry the new classification alongside their existing, unchanged output.

## 9. Non-goals

- **No new AJAX endpoint, no new admin-post action, no new nonce/token.** This is pure server-rendered read-only presentation layered onto already-fetched data (Position is already computed once per page load on the list table; the low-stock scan already exists in `Summary`).
- **No new filter/query parameter** to filter the Overview list to "Needs Reorder only." (Doing this correctly would require pre-pagination full-catalog Position scans, a materially different and riskier query shape than anything this milestone touches — a reasonable candidate for a later milestone, not this one.)
- **No "create PO" / "reorder" action button anywhere.** That is M22, deliberately sequenced after M21 and requiring its own preferred-supplier design decision.
- **No change to `render_position_drilldown_section()`.** It already satisfies D13's simultaneity requirement by showing every contributing PO line; M21 only adds a derived tri-state summary judgment on top of already-displayed data, it does not change the drilldown itself.
- **No change to the existing on-hand-based "Low stock" determination** anywhere (`(float) $qty <= (float) $low_amt`, appearing today at `list-table.php:405`, `list-table.php:499`, `list-table.php:852`, `summary.php:96`, `summary.php:148`). All five call sites are left untouched; the new classification is only ever computed as an additional layer on top of an item already found low by the existing, unmodified rule.
- **No fix for G1 or G2** (§5) — tracked as separate backlog items, not part of this milestone.
- **No documentation-currency pass** on the pre-existing staleness of `README.md` (wrong version/milestone count) or `docs/architecture-audit.md` (narrative stops at M16). M21 adds its own entry using the existing structure without correcting prior staleness — fixing that is unrelated cleanup, out of scope here.
- **No schema change.** `DB_VERSION` remains `11`.

## 10. Current-state architecture (the relevant slice)

Two pre-existing, independent subsystems this milestone bridges:

**A. The low-stock threshold and badge** (pre-M3, still in its original form):
- `WC_Inventory_Overview_Settings::get_effective_low_stock_amount( WC_Product $product ): float` (`includes/class-wc-inventory-overview-settings.php:329`) — returns the product's explicit WooCommerce `_low_stock_amount` if set, else the plugin's own `OPTION_DEFAULT_LOW_STOCK_THRESHOLD` (default `3`). This is the **sole threshold source** for every low-stock determination in the plugin today, and remains so — M21 introduces no second threshold.
- The `is_low_stock` check (`managing_stock() && is_in_stock() && stock_status !== on_backorder && $qty <= $low_amt`) is inlined, identically, at five call sites: `list-table.php:405` (`render_direct_stock_badges_inner`, simple/variation row badge), `list-table.php:499` (`compute_variable_aggregate`, per-child count for parent rollup), `list-table.php:852` (`get_row_state_classes`, CSS row state), `summary.php:96` (`count_low_sellable_lines`, Dashboard/Overview KPI count), `summary.php:148` (`get_low_stock_lines_for_chart`, Dashboard table + chart data). **None of these five sites are modified by M21** — the new classification is only ever computed as a second step, after this existing check has already found an item low.

**B. Inventory Position** (M3, v1.20.0, unmodified by M21):
- `WC_Inventory_Overview_Inventory_Position_Service::get_positions_bulk( array $product_on_hand, array $variation_on_hand ): array<int, array{on_hand, incoming, position, incoming_delayed, incoming_lines}>` (`includes/class-wc-inventory-overview-inventory-position-service.php:58`) — the sole calculator (D12); at most one product-scoped and one variation-scoped repository query regardless of item count.
- Already consumed once per page load by `List_Table::prepare_items()` (`includes/class-wc-inventory-overview-list-table.php:1315-1336`), building `$this->position_map`, **gated by `current_user_can('manage_woocommerce')`** — empty for viewers without that capability. This is the existing capability boundary M21 must mirror exactly (§16).
- Also already consumed by `compute_variable_aggregate()` for variable-parent presentation rollups (`incoming_total`/`position_total`, INV-8-compliant: always a sum of child values, never a real parent-level record).

**The gap**: nothing today reads both A and B together. `Summary::count_low_sellable_lines()`/`get_low_stock_lines_for_chart()` use only raw `get_stock_quantity()`, never touching Position/Incoming at all.

## 11. Repository ownership map (files this milestone touches)

| File | Class | Change |
|---|---|---|
| `includes/class-wc-inventory-overview-reorder-signal-resolver.php` (**new**) | `WC_Inventory_Overview_Reorder_Signal_Resolver` | New pure classifier (§12) |
| `includes/class-wc-inventory-overview-list-table.php` | `WC_Inventory_Overview_List_Table` | Extend `render_direct_stock_badges_inner()`, the variation-row badge method (`~line 499` region), `get_row_state_classes()`, `compute_variable_aggregate()` |
| `includes/class-wc-inventory-overview-summary.php` | `WC_Inventory_Overview_Summary` | Extend `build()`, `count_low_sellable_lines()`, `get_low_stock_lines_for_chart()`; add new private bulk-classification helper |
| `includes/class-wc-inventory-overview-overview-controller.php` | `WC_Inventory_Overview_Overview_Controller` | Extend `render_summary_cards()` (capability-gated new card) |
| `includes/class-wc-inventory-overview-dashboard-controller.php` | `WC_Inventory_Overview_Dashboard_Controller` | Extend the KPI array and `render_dashboard_operational_panels()` (capability-gated new KPI + table columns) |
| `includes/class-wc-inventory-overview-dashboard-charts-data.php` | `WC_Inventory_Overview_Dashboard_Charts_Data` | **No functional change** — verify the `lowStock` chart payload (uses only `name`/`qty`) is unaffected by `Summary`'s additive new keys; add a regression assertion, not new logic |

No other production file changes. No repository/service class outside `Summary` is modified — `Inventory_Position_Service`, `Inventory_Position_Resolver`, `Settings`, and every PO/Receipt/Supplier repository are untouched, read-only dependencies.

## 12. Data model impact

None. No new table, column, index, or WordPress option. No product meta read beyond the existing `get_low_stock_amount()`/`get_stock_quantity()` calls already made today.

## 13. Schema/migration decision

**No schema change.** `DB_VERSION` stays `11`. No `create_tables()`/`assert_schema_shape()` change, no migration, no `maybe_upgrade()` impact.

## 14. Domain model changes

One new domain concept, deliberately minimal: the **Reorder Signal** — a derived, per-purchasable-item, two-state classification (`needs_reorder` / `covered_by_incoming`) that only ever applies to items already classified "Low stock" by the pre-existing rule. It is not a new entity, has no identity, is never persisted, and is always recomputed fresh from live Position + live threshold (same freshness contract as Position itself — INV-3: "always reflects the current inventory state").

## 15. Repository/read ownership

No new repository. `Reorder_Signal_Resolver` reads nothing itself — it is a pure function over caller-supplied `(position, threshold)` floats. All reads remain owned by `Inventory_Position_Service` (Position) and `Settings`/`WC_Product` (threshold), unchanged.

## 16. Mutation ownership

None. M21 introduces zero mutation anywhere — no `$wpdb` write, no `update_option`, no `update_post_meta`, no `set_stock_quantity()`/`save()` call in any new or touched code path (INV-M21-1).

## 17. Service-layer design

**New class**: `WC_Inventory_Overview_Reorder_Signal_Resolver` (Internal, not Public — D16), mirroring the existing `Inventory_Position_Resolver`'s style (pure, stateless, single static method):

```php
WC_Inventory_Overview_Reorder_Signal_Resolver::resolve(
    float $position,
    float $threshold
): array{needs_reorder: bool, covered_by_incoming: bool}
```

Contract: `needs_reorder = ($position <= $threshold)`; `covered_by_incoming = ! $needs_reorder`. Both keys always present, always boolean, mutually exclusive and exhaustive. No side effects, no I/O, no WordPress function calls.

**Extended (not new) class**: `WC_Inventory_Overview_Summary` gains one new protected/private bulk-classification helper that is the **sole caller** of `Inventory_Position_Service::get_positions_bulk()` on M21's behalf, so every `Summary` method shares one call path (mirrors D12's "one authoritative calculator" discipline, extended by analogy — INV-M21-2):

```php
protected static function classify_needs_reorder_bulk(
    array $product_candidates,   // product_id => array{on_hand: float, threshold: float}
    array $variation_candidates  // variation_id => array{on_hand: float, threshold: float}
): array   // item_id => array{position: float, needs_reorder: bool, covered_by_incoming: bool}
```
Internally: calls `Inventory_Position_Service::get_positions_bulk()` **exactly once** with the on-hand maps, then calls `Reorder_Signal_Resolver::resolve()` once per candidate using each candidate's own `threshold` (never a single shared threshold — every item may have its own explicit `_low_stock_amount`).

## 18. Admin/controller ownership

No new controller, no new route, no new tab, no new screen. `Overview_Controller` and `Dashboard_Controller` gain additive rendering inside their existing `render()` methods; no new capability gate is introduced at the controller/route level (the existing `edit_products` tab-level gates are unchanged — see §16/§20 for the finer-grained per-widget gating this milestone does introduce).

## 19. UI workflow

- **Inventory Overview list table** (simple/variation rows): when a row is already showing the existing "Low stock" badge (unchanged trigger) **and** the viewer has `manage_woocommerce`, show one additional badge alongside it — "Needs reorder" (alert style) or "Covered by incoming" (informational, non-alarming style) — never replacing the existing badge (D13).
- **Variable-parent rows**: the existing per-child "Low stock" rollup badge/count gains a parallel "Needs reorder" rollup badge/count, using the exact same counting pattern already used for `n_in_low` in `compute_variable_aggregate()`.
- **Row CSS state**: a new `wc-io-needs-reorder` class is added to `get_row_state_classes()`'s output (parallel to the existing `wc-io-is-low-stock`), gated the same way, for future stylability — no new visual requirement beyond the badge itself in this milestone.
- **Inventory Overview summary cards**: one new card, "Needs Reorder", shown only to `manage_woocommerce` viewers (the six existing cards are unchanged and remain visible to `edit_products` viewers as today).
- **Dashboard KPI row**: one new KPI card, "Needs Reorder" (a subset count of the existing "Low Stock Items" card), shown only to `manage_woocommerce` viewers.
- **Dashboard "Recent Low Stock Items" table**: two new columns, "Incoming" and "Reorder status", appended after the existing "Threshold" column, shown only to `manage_woocommerce` viewers; `edit_products`-only viewers see exactly today's 5 columns, unchanged.

## 20. Security model / 21. Capability model

No new capability constant. Every new surface reuses the plugin's existing `manage_woocommerce` capability — the same one already gating `position_map` computation and the Incoming/Position columns in `List_Table` (`current_user_can( 'manage_woocommerce' )`, `list-table.php:1316`). This is a **new, explicit per-widget capability check** inside `Overview_Controller::render_summary_cards()` and `Dashboard_Controller`'s KPI-array/table-render code — both of those methods currently render their existing content at the coarser `edit_products` tab-level gate with no internal capability branching. `Dashboard_Controller` already has exactly this pattern nearby (`$can_shop = current_user_can( 'manage_woocommerce' )`, `dashboard-controller.php:57`, gating the "Quick Actions" panel) — M21's new gates mirror that existing precedent exactly, they do not invent a new pattern.

**Rule, stated once, applied everywhere in this milestone**: a Reorder Signal surface is visible if and only if `current_user_can( 'manage_woocommerce' )` is true for the current request. No exceptions, no partial disclosure (e.g., never show "Needs reorder" without also requiring the same gate that already protects Incoming/Position numbers — a boolean derived from Position is still Position-derived information).

## 22. Nonce/token model

Not applicable. No new form, no new AJAX action, no new admin-post handler — purely additive server-rendered read output.

## 23. Transaction/atomicity requirements

Not applicable. Zero mutation.

## 24. Concurrency requirements

Not applicable. Read-only, stateless computation; no locking, no compare-and-swap, no idempotency concern.

## 25. Snapshot/history semantics

Not applicable — no persisted snapshot is introduced. The Reorder Signal is always computed fresh at render time from live Position (itself always fresh per INV-3) and the live threshold; it has no history and is never expected to reflect a past state.

## 26. Currency/cost semantics

Not applicable. The Reorder Signal operates purely on stock quantities (unitless counts: on-hand, incoming, position, threshold) — it never touches price, cost, or currency in any form.

## 27. Query/performance contract

| Surface | Additional queries beyond current baseline |
|---|---|
| List-table row badges, row CSS class, parent-rollup badge | **Zero.** `position_map` / `current_variable_aggregate` are already computed exactly once per page load today; M21 only adds a pure in-memory comparison against already-fetched values. |
| `Summary::build()`'s new `needs_reorder` key | Exactly **2** additional queries, fixed, regardless of catalog size or how many low-stock lines are found: the existing `count_low_sellable_lines()` pagination loop (unchanged, ≤40 pages) is extended to accumulate every qualifying candidate's id/on-hand/threshold, then **one single** `get_positions_bulk()` call is issued after the loop completes (1 product-scoped + 1 variation-scoped query, per its own D12 contract) — never inside the loop, never once per candidate. |
| `Summary::get_low_stock_lines_for_chart()`'s new fields | Exactly **2** additional queries, fixed, for the same reason — applied to the method's already-existing bounded candidate pool (≤ `$limit × 5`, capped at 250 rows for the maximum allowed `$limit = 50`). |
| Dashboard/Overview rendering of the new surfaces | **Zero** further queries — they consume the already-computed results of the two `Summary` methods above. |

**Regression gate**: extend the existing query-count-bounded pattern (`tests/integration/inventory-position/test-inventory-position-list-table.php`'s `test_position_query_count_bounded_for_twenty_plus_rows`-style assertion) with a new test asserting `Summary::build()` and `Summary::get_low_stock_lines_for_chart()` each issue exactly the stated fixed number of *additional* queries at both a small (5-product) and larger (60-product, mixed low-stock) fixture scale — proving the count does not scale with N.

## 28. Error model

No new error path. Every new code path operates on already-validated, already-typed data (`WC_Product` instances already confirmed to exist and manage stock by the pre-existing low-stock check; `Inventory_Position_Service` already guarantees a result for every requested id). No new `WP_Error`, no new admin notice, no new failure mode is introduced.

## 29. Logging/audit requirements

None. Zero mutation means there is nothing to audit; no PO event, no movement row, no option change is ever written by this milestone.

## 30. Backward compatibility

Full. Every existing method's pre-existing return keys, values, columns, badges, and behavior for any code path *not* newly gated by this milestone are byte-identical to v1.37.0 — enforced by writing a characterization test **before** each touched method is modified (mirroring the discipline already used for the M18/M19/M20 decomposition), per INV-M21-6.

## 31. Upgrade behavior

Trivial. Zero schema change means the upgrade is a pure code deploy; `maybe_upgrade()` is not triggered by this milestone (DB_VERSION unchanged).

## 32. Rollback behavior

Trivial, code-only. v1.38.0 → v1.37.0 is a pure code revert with zero data implications (no schema was touched, nothing was persisted), consistent with the "zero schema, code-only rollback" pattern already rehearsed for M16/M18/M19/M20.

## 33. Test architecture

Follows the codebase's established topic-directory convention. New topic directory `tests/unit/reorder-signal/` and `tests/integration/reorder-signal/` (mirroring how M17 Supplier Merge got its own topic directory even though it touched PO/Supplier repos elsewhere) — chosen over folding into `tests/unit/inventory-position/` because this concept spans List_Table, Summary, Overview_Controller, and Dashboard_Controller, not just the Inventory Position domain itself.

- `tests/unit/reorder-signal/test-reorder-signal-resolver.php` — pure classifier: below/at/above threshold, zero threshold, zero position, boundary equality (`position === threshold` → `needs_reorder = true`, confirming `<=` not `<`).
- `tests/unit/reorder-signal/test-reorder-signal-architecture.php` — grep-based sole-caller guard: every file referencing `needs_reorder`/`covered_by_incoming` calls `Reorder_Signal_Resolver::resolve()` (directly or via `Summary::classify_needs_reorder_bulk()`), no independent `position <= threshold`-shaped reimplementation exists outside those two files.
- `tests/unit/reorder-signal/test-summary-needs-reorder.php` — table-driven: `Summary::build()`'s `low_stock` value is unchanged vs. a pre-M21 fixture; `needs_reorder` is always `<= low_stock`; a mixed fixture (some covered, some not) produces the exact expected count.
- `tests/integration/reorder-signal/test-summary-query-count.php` — the query-count-bounded regression gate from §27, at two fixture scales.
- `tests/integration/reorder-signal/test-list-table-reorder-badges.php` — asserts badge/row-class presence for `manage_woocommerce` viewers and absence for `edit_products`-only viewers, for simple products, variations, and a variable-parent rollup case.
- `tests/integration/reorder-signal/test-dashboard-reorder-surfaces.php` — capability-gated KPI card and table-column presence/absence, mirroring the characterization style of `tests/integration/admin-decomposition/test-dashboard-rendering-characterization.php`.
- `tests/integration/reorder-signal/test-overview-summary-cards.php` — capability-gated new summary card presence/absence.
- Regression: re-run (not rewrite) `tests/unit/inventory-position/test-inventory-position-architecture.php` and `tests/unit/inventory-position/test-inventory-position-resolver.php` to confirm zero change to Position's own contract; confirm the `Dashboard_Charts_Data` `lowStock` chart payload test still passes unmodified (proves the new `Summary` keys are additive, not breaking).

**Validation cadence** (per this program's efficient-validation directive): during each WP, run only the directly-affected new/extended test files plus the specific pre-existing files named above as regression checks, and PHP-lint touched files. Run the full unit suite once, the M1–M21 focused suite once, and the integration suite once — at WP-M21-7 (freeze), not after every WP.

---

## Business Rules

- **BR-M21-1**: The Reorder Signal classification is computed only for a purchasable item already classified "Low stock" by the existing, unmodified rule (`managing_stock() && is_in_stock() && stock_status !== on_backorder && $qty !== null && $qty !== '' && $qty <= effective_low_stock_amount`). Items that are out of stock, on backorder, not managing stock, or not low are never classified and never show a Reorder Signal badge/column.
- **BR-M21-2**: Given a Low-stock item, `needs_reorder = true` when `Position <= effective_low_stock_amount`, using the **same** `Settings::get_effective_low_stock_amount()` value already used for that item's Low-stock determination in the same render pass — never a second, divergent threshold. `covered_by_incoming = ! needs_reorder`; the two are mutually exclusive and exhaustive over the Low-stock population.
- **BR-M21-3**: When `needs_reorder` is true, the UI shows the existing "Low stock" badge **together with** (never instead of) a new "Needs reorder" badge. When `covered_by_incoming` is true, the UI shows the existing "Low stock" badge together with a new "Covered by incoming" indicator, visually distinguished (non-alarming style) from "Needs reorder."
- **BR-M21-4**: Every Reorder Signal surface is visible if and only if `current_user_can( 'manage_woocommerce' )` is true — identical to the existing gate already governing `position_map`/Incoming/Position in `List_Table`. Viewers with only `edit_products` see exactly today's unchanged output on every touched screen.
- **BR-M21-5**: Variable-parent rows classify Reorder Signal per child variation only (never against a parent-level Position, per INV-8) and roll up via a new `n_in_needs_reorder` counter in `compute_variable_aggregate()`, parallel to the existing `n_in_low` counter, producing a "Needs reorder" child-badge parallel to the existing `wc-io-badge-low-child` "Low stock" child-badge.
- **BR-M21-6**: `Overview_Controller::render_summary_cards()` gains a "Needs Reorder" card, rendered only when the current viewer has `manage_woocommerce` — the six existing cards remain visible to `edit_products` viewers exactly as today; this is the first capability-conditional card in that method and must be implemented as an explicit per-card check, not a method-level gate change.
- **BR-M21-7**: `Summary::build()`'s existing `low_stock` key is unchanged in value, meaning, and population. A new, additive `needs_reorder` key is computed from the exact same candidate population already gathered for `low_stock` (i.e., every line counted toward `low_stock` is a candidate; `needs_reorder <= low_stock` always holds).
- **BR-M21-8**: `Summary::get_low_stock_lines_for_chart()`'s existing return shape (`{id, name, qty, low}` per row, sorted by qty ascending then name) is extended additively with `{incoming, position, needs_reorder, covered_by_incoming}`. Existing keys, values, ordering, and sort behavior are unchanged. The `Dashboard_Charts_Data` chart payload continues to use only `name`/`qty`, unaffected by the new keys.
- **BR-M21-9**: The Dashboard "Recent Low Stock Items" table gains two new columns ("Incoming", "Reorder status"), rendered only when `current_user_can( 'manage_woocommerce' )` is true (mirroring the existing `$can_shop` gate already used lower in the same method for "Quick Actions"). Absent that capability, the table renders with exactly its current 5 columns, unchanged.
- **BR-M21-10**: The Dashboard gains a "Needs Reorder" KPI card (a subset count of "Low Stock Items"), rendered only when `current_user_can( 'manage_woocommerce' )` is true. Absent that capability, the existing KPI set — including the unchanged "Low Stock Items" card — renders exactly as today.
- **BR-M21-11**: `WC_Inventory_Overview_Reorder_Signal_Resolver::resolve()` is the sole component permitted to compute the `needs_reorder`/`covered_by_incoming` classification; no call site duplicates the `position <= threshold` comparison inline.
- **BR-M21-12**: Computing the Reorder Signal for any of the surfaces in BR-M21-6 through BR-M21-10 calls `Inventory_Position_Service::get_positions_bulk()` at most once per HTTP request per `Summary` method — never once per candidate item (see §27 for the exact fixed counts).

## Invariants

- **INV-M21-1 (zero mutation)**: M21 introduces no `$wpdb` write, no `update_option`, no `update_post_meta`, no `set_stock_quantity()`/`save()` call anywhere in its new or touched code. Grep-testable.
- **INV-M21-2 (single classifier)**: every occurrence of the `needs_reorder`/`covered_by_incoming` classification in production code is produced by exactly one call path through `Reorder_Signal_Resolver::resolve()`; no call site independently reimplements the comparison. Architecture-guard tested.
- **INV-M21-3 (threshold-source parity)**: the Reorder Signal always uses the same `Settings::get_effective_low_stock_amount()` value already used for that item's Low-stock determination in the same render pass; no second threshold value or option is ever read.
- **INV-M21-4 (bounded queries)**: each surface's additional query cost is a small fixed constant (0 or 2, per §27), never a function of catalog size, low-stock count, or page size. Regression-tested.
- **INV-M21-5 (capability parity)**: every new Reorder Signal surface is visible if and only if `current_user_can( 'manage_woocommerce' )` is true, exactly mirroring the existing boundary already governing Incoming/Position. No new capability constant is introduced.
- **INV-M21-6 (non-destructive additivity)**: every touched method's pre-existing return keys, columns, badges, counts, and behavior for paths unaffected by M21 remain byte-identical to v1.37.0, proven by a characterization test written before each method is modified.
- **INV-M21-7 (INV-8 parity)**: Reorder Signal is always computed per purchasable item (simple product or variation); a variable parent's "Needs reorder" indicator is always a rollup **count** of individually-classified children, never a Position/threshold comparison computed directly against a parent-level aggregate.
- **INV-M21-8 (schema stability)**: `DB_VERSION` remains `11`; no table, column, or index is added, altered, or dropped.

## Data Contracts

```php
// New — includes/class-wc-inventory-overview-reorder-signal-resolver.php
WC_Inventory_Overview_Reorder_Signal_Resolver::resolve(
    float $position,
    float $threshold
): array{needs_reorder: bool, covered_by_incoming: bool}
```

```php
// Extended (internal helper) — includes/class-wc-inventory-overview-summary.php
protected static function classify_needs_reorder_bulk(
    array $product_candidates,   // product_id   => array{on_hand: float, threshold: float}
    array $variation_candidates  // variation_id => array{on_hand: float, threshold: float}
): array   // item_id => array{position: float, needs_reorder: bool, covered_by_incoming: bool}
```

```php
// Extended return shape — Summary::build()
array{
  total:int, in_stock:int, out_of_stock:int, on_backorder:int,
  low_stock:int,           // UNCHANGED value/meaning
  needs_reorder:int,       // NEW — always <= low_stock
  draft:int, hidden:int,
}
```

```php
// Extended per-row shape — Summary::get_low_stock_lines_for_chart()
array{
  id:int, name:string, qty:float, low:float,          // UNCHANGED
  incoming:float, position:float,                      // NEW
  needs_reorder:bool, covered_by_incoming:bool,         // NEW
}
```

```php
// Extended return shape — List_Table::compute_variable_aggregate()
// (signature unchanged; return array gains one new key)
array{
  ...,           // all existing keys unchanged
  n_in_needs_reorder: int,   // NEW — parallel to existing n_in_low
}
```

No new admin URL parameters, no new nonce/action strings, no new WordPress option, no new capability constant.

## State-Transition Model

**Not applicable.** M21 introduces no stateful entity and no lifecycle. The Reorder Signal is a pure, memoryless function of two already-live values (Position, threshold), recomputed fresh on every render; it has no persisted state and therefore no transitions to define.

## Reference/Mutation Map

**Not applicable — intentionally empty.** M21 introduces zero new persisted columns, mutates zero existing table/column, and creates zero new reference relationship (no ID, no ownership, no stock, no cost, no supplier/PO/receipt reference is written or reassigned by this milestone). See INV-M21-1 for the formal zero-mutation guarantee.

---

## Work Packages

Implementation agent executes WP-M21-0 through WP-M21-7 continuously, without stopping for approval between packages, per the Implementation Execution Contract. Each WP's targeted validation runs only its own new/extended tests plus the specific named regression files — full-suite validation happens once, at WP-M21-7.

### WP-M21-0 — Plan materialization
- **Objective**: create the feature branch, materialize this approved plan as `docs/milestones/m21-implementation-plan.md`, commit it alone as the immutable milestone contract.
- **Files**: `docs/milestones/m21-implementation-plan.md` (new).
- **Steps**: create branch from `main`; copy this plan's content into the file; commit with no other changes.
- **Commit boundary**: one commit, plan only.
- **Stop conditions**: none specific to this WP.

### WP-M21-1 — Reorder Signal Resolver
- **Objective**: implement the sole classifier.
- **Production files**: `includes/class-wc-inventory-overview-reorder-signal-resolver.php` (new). Register the require in the plugin bootstrap's dependency-ordered file list (`wc-inventory-overview.php`), placed alongside `Inventory_Position_Resolver`'s existing require.
- **Test files**: `tests/unit/reorder-signal/test-reorder-signal-resolver.php`, `tests/unit/reorder-signal/test-reorder-signal-architecture.php`.
- **Methods**: `WC_Inventory_Overview_Reorder_Signal_Resolver::resolve()`.
- **BRs covered**: BR-M21-2, BR-M21-11. **INVs covered**: INV-M21-2.
- **Targeted validation**: run the two new test files only.
- **Commit boundary**: one commit.
- **Stop conditions**: none beyond the plan's global stop conditions.

### WP-M21-2 — List-table row-level integration
- **Objective**: badges + row CSS class for simple products and variations.
- **Production files**: `includes/class-wc-inventory-overview-list-table.php` — extend `render_direct_stock_badges_inner()`, the variation-row badge rendering path (the method containing the second `$low_amt` computation near line 499), `get_row_state_classes()`.
- **Test files**: `tests/integration/reorder-signal/test-list-table-reorder-badges.php`.
- **Methods**: as named above; new calls into `Reorder_Signal_Resolver::resolve()` gated by `current_user_can('manage_woocommerce')` and by `$this->position_map[$id]` already being populated.
- **BRs covered**: BR-M21-1, BR-M21-3, BR-M21-4. **INVs covered**: INV-M21-4 (zero added queries — reuses `$this->position_map`), INV-M21-5, INV-M21-6.
- **Targeted validation**: new test file + re-run `tests/integration/inventory-position/test-inventory-position-list-table.php` as a regression check.
- **Commit boundary**: one commit.
- **Stop conditions**: if `position_map` is found not yet populated at the point badges render for some row (ordering assumption violated) — stop and re-verify `prepare_items()`'s call order rather than guessing a fetch-on-demand fallback (would violate INV-M21-4).

### WP-M21-3 — List-table variable-parent rollup
- **Objective**: `n_in_needs_reorder` counter and parallel badge in `compute_variable_aggregate()`.
- **Production files**: `includes/class-wc-inventory-overview-list-table.php` — `compute_variable_aggregate()`.
- **Test files**: extend `tests/integration/reorder-signal/test-list-table-reorder-badges.php` with variable-parent fixtures (mixed covered/needs-reorder children).
- **Methods**: `compute_variable_aggregate()`.
- **BRs covered**: BR-M21-5. **INVs covered**: INV-M21-7.
- **Targeted validation**: the extended test file.
- **Commit boundary**: one commit.
- **Stop conditions**: if the child-counting loop structure has diverged from the `n_in_low` pattern described in §10/§19 in a way that prevents a straightforward parallel counter — stop and re-plan this WP rather than restructuring the method further than a parallel counter addition.

### WP-M21-4 — Summary::build() needs_reorder count
- **Objective**: add the bounded, single-bulk-call `needs_reorder` count.
- **Production files**: `includes/class-wc-inventory-overview-summary.php` — `build()`, `count_low_sellable_lines()` (extended to also accumulate candidate id/on-hand/threshold), new `classify_needs_reorder_bulk()` helper.
- **Test files**: `tests/unit/reorder-signal/test-summary-needs-reorder.php`, `tests/integration/reorder-signal/test-summary-query-count.php`.
- **Methods**: as named above.
- **BRs covered**: BR-M21-7, BR-M21-12. **INVs covered**: INV-M21-4, INV-M21-6.
- **Targeted validation**: the two new test files; write the `low_stock`-unchanged characterization assertion **before** modifying `count_low_sellable_lines()`.
- **Commit boundary**: one commit.
- **Stop conditions**: if achieving a single post-loop bulk call requires restructuring `count_low_sellable_lines()`'s pagination beyond accumulating id/on-hand/threshold into arrays (e.g., if early-`break` semantics conflict with needing the full candidate set) — stop and report, do not silently fall back to per-page bulk calls (would violate INV-M21-4's "fixed count" claim).

### WP-M21-5 — Chart-data row extension + Dashboard/Overview consumption
- **Objective**: extend `get_low_stock_lines_for_chart()`, then wire the new fields into the Dashboard KPI card, Dashboard table columns, and Overview summary card.
- **Production files**: `includes/class-wc-inventory-overview-summary.php` (`get_low_stock_lines_for_chart()`), `includes/class-wc-inventory-overview-dashboard-controller.php`, `includes/class-wc-inventory-overview-overview-controller.php` (`render_summary_cards()`).
- **Test files**: extend `tests/unit/reorder-signal/test-summary-needs-reorder.php` for the chart-row shape; `tests/integration/reorder-signal/test-dashboard-reorder-surfaces.php`; `tests/integration/reorder-signal/test-overview-summary-cards.php`.
- **Methods**: `get_low_stock_lines_for_chart()`, `Dashboard_Controller::render_dashboard_operational_panels()` and its KPI-array builder, `Overview_Controller::render_summary_cards()`.
- **BRs covered**: BR-M21-6, BR-M21-8, BR-M21-9, BR-M21-10. **INVs covered**: INV-M21-4, INV-M21-5, INV-M21-6.
- **Targeted validation**: the three test files above; re-run the existing `Dashboard_Charts_Data` chart-payload test as a regression check (must remain unaffected by the new `Summary` row keys).
- **Commit boundary**: one commit.
- **Stop conditions**: if extending the KPI array or table columns is found to require changing `Dashboard_Controller`'s overall page-level capability gate (currently `edit_products`) rather than an internal per-widget check — stop; this would mean the "mirror the existing `$can_shop` pattern" assumption in §20 was wrong and needs re-verification against current code, not a workaround.

### WP-M21-6 — Capability-matrix regression pass
- **Objective**: consolidated, explicit test coverage proving every new surface obeys the single capability rule (§20) in both directions — present for `manage_woocommerce`, absent for `edit_products`-only.
- **Production files**: none (test-only WP; may add small test-support fixtures/helpers).
- **Test files**: review and, if needed, extend all `tests/integration/reorder-signal/*` files from WP-M21-2 through WP-M21-5 to ensure each includes an explicit "capability absent → surface absent, everything else byte-identical" case; add `tests/unit/reorder-signal/test-reorder-signal-architecture.php` assertions confirming no new surface checks a capability other than `manage_woocommerce`.
- **BRs covered**: BR-M21-4 (consolidated). **INVs covered**: INV-M21-5 (consolidated), INV-M21-6 (consolidated).
- **Targeted validation**: the full `tests/*/reorder-signal/` directory.
- **Commit boundary**: one commit.
- **Stop conditions**: any surface found to leak Reorder Signal data (badge, count, or column) to a viewer lacking `manage_woocommerce` — stop and fix before proceeding to freeze; this is a hard gate, not a deferrable finding.

### WP-M21-7 — Full validation, documentation, freeze
- **Objective**: the one expensive full-validation pass, plus all documentation deliverables, plus the release-readiness checklist.
- **Production files**: none.
- **Doc files**: `CHANGELOG.md` (new `[1.38.0]` entry, mirroring the style/detail level of the M16 entry), `CLAUDE.md` (new M21 row in the Implementation Status table + one paragraph in the top summary section, mirroring the M9/M11/M16 entries' style), `docs/checklists/m21-release-readiness.md` (new, mirroring `docs/checklists/m20-release-readiness.md`'s structure), version bump to `1.38.0` in the plugin header docblock and version constant.
- **Validation**: unit suite once (full), M1–M21 focused suite once (add M21 test-class prefixes to the CI filter regex in `tests/docker/run-phpunit.sh`, mirroring how M20 did this), integration suite once (full), M21-specific `--list-tests` discovery proof, PHPCS lint, `composer validate`, `docker compose config` validation, `scripts/release-audit.sh --development`, push + open draft CI PR, obtain GitHub Actions results, perform Level A completion review, freeze.
- **BRs/INVs covered**: full verification of all BR-M21-1 through BR-M21-12 and INV-M21-1 through INV-M21-8.
- **Commit boundary**: one or more commits for docs/version bump, kept separate from the freeze-review commit if any remediation is needed.
- **Stop conditions**: any BR/INV found unsatisfied during the full-suite pass or Level A review — remediate within this WP's scope only (no new scope), per the milestone lifecycle's WP3 discipline; do not merge/tag/release/deploy (out of scope for this milestone's completion, per the Implementation Execution Contract).

---

## Documentation changes

`CHANGELOG.md`, `CLAUDE.md` (Implementation Status table + summary paragraph), `docs/milestones/m21-implementation-plan.md` (this plan, materialized at WP-M21-0), `docs/checklists/m21-release-readiness.md` (at freeze). No admin-guide document covers Inventory Overview/Dashboard directly today (only Suppliers/Purchase-Orders/Storefront-Availability guides exist) — none is created or updated by this milestone; this is consistent with the Non-Goals in §9 (no broader documentation-currency pass).

## Version recommendation

**1.38.0** (next minor, following the plugin's existing per-milestone increment pattern).

## Release classification

**B — feature-train candidate**, with a caveat: zero schema change, zero new public API, zero new capability, zero storefront impact all point to bundling into a future train (matching the M9–M12/M13–M15/M18–M19 pattern). Unlike M18–M19 (pure refactor, zero merchant value), M21 **is** merchant-facing value, so a standalone release is equally defensible if faster visibility is preferred. Per `docs/process/milestone-lifecycle.md`, this is explicitly a business decision to make at WP5/WP6 freeze time, not an engineering constraint — no release trigger (schema/migration/public-API/ownership-boundary/storefront/security/breaking change) applies here either way.

## Stop conditions (consolidated)

- `Settings::get_effective_low_stock_amount()` is found to actually return `null` in some code path (contradicting the "always a float" assumption underlying BR-M21-2/BR-M21-12) — stop, do not guess a fallback.
- `Inventory_Position_Service::get_positions_bulk()`'s no-N+1 guarantee is found broken (an internal per-ID loop discovered) — would invalidate INV-M21-4 — stop.
- `position_map`/`current_variable_aggregate` computation order in `List_Table::prepare_items()` no longer matches the ordering described in §10 — stop, re-verify rather than adding a fetch-on-demand fallback.
- `compute_variable_aggregate()`'s child-counting loop structure has changed enough that a parallel `n_in_needs_reorder` counter cannot be added as a straightforward addition — stop, re-plan WP-M21-3 only.
- Achieving a single post-loop bulk `get_positions_bulk()` call in `Summary::count_low_sellable_lines()`/`get_low_stock_lines_for_chart()` is found to require restructuring beyond accumulating candidate arrays — stop, do not silently degrade to per-page or per-item bulk calls.
- Any new surface is found reachable by a viewer without `manage_woocommerce` — stop and fix immediately (hard gate, not deferrable).
- A schema change is found necessary for any reason — stop; this plan's entire premise is schema-none, so this indicates a false premise requiring re-planning, not an in-flight adjustment.
- Any WP is found to require scope belonging to M22 (a "create PO" action) or M24 (fixing G2) — stop; those are explicitly out of scope (§9).

## Follow-on M22+ roadmap

See §3 for the full ladder (M22 Reorder → Draft PO Quick Action, M23 Storewide Supplier Spend Rollup, M24 Inline-Stock AJAX Hardening & Ledger Parity, M25 Goods Receipt Attachments, M26 Sales-Velocity Data Foundation) and §5 for the G1/G2 backlog assessment. Reservations and Warehouse/multi-location remain outside the active roadmap per D16 and the plugin's single-warehouse business context, respectively.

## Open questions

None. Every design decision in this plan resolves by direct precedent already established in the codebase (D12's sole-calculator discipline, D13's composability requirement, INV-8's purchasable-item scoping, the existing `manage_woocommerce`/`edit_products` capability split, and the M9/M11/M16 "read-only, reuses an existing service, zero schema" milestone template). No repository-reality ambiguity was found that couldn't be resolved from existing product conventions.

## Final planning verdict

**M21 PLAN READY FOR REVIEW**
