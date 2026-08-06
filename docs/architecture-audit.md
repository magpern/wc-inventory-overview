# Architecture audit — WC Inventory Overview 1.20.0

**Date:** 2026-08-05  
**Scope:** Standalone repo `magpern/wc-inventory-overview` with Milestone M3 implementation (Inventory Position, read-only, schema v7 unchanged)

---

## Main plugin file

| File | Role |
|------|------|
| `wc-inventory-overview.php` | Defines `WC_INVENTORY_OVERVIEW_*` constants, HPOS compatibility, activation hook, `plugins_loaded` bootstrap |

**Bootstrap flow:**

1. `before_woocommerce_init` → `FeaturesUtil::declare_compatibility( 'custom_order_tables', … )`
2. `register_activation_hook` → `WC_Inventory_Overview_Install::activate()`
3. `plugins_loaded` (priority 11) → require all `includes/*.php` → `Install::maybe_upgrade()` → `WC_Inventory_Overview_Plugin::instance()->init()`

If WooCommerce is missing, shows admin notice only.

---

## Core controller

`includes/class-wc-inventory-overview-plugin.php` (~2.7k lines)

- Registers **WooCommerce → Inventory & Profit** submenu (`PAGE_SLUG = wc-inventory-profit`)
- Tab shell: dashboard, overview, restock, movements, order profit, product profitability, settings
- Wires `admin_post_*`, `wp_ajax_*`, CSV exports, asset enqueue, legacy page redirects

---

## Custom database tables

Created/upgraded via `WC_Inventory_Overview_Install` (`DB_VERSION = 7`, option `wc_io_db_version`):

| Table | Purpose | Version |
|-------|---------|---------|
| `{prefix}wc_io_purchase_orders` | **M2:** PO header (number, supplier, currency, status, expected date/confidence) | v7+ |
| `{prefix}wc_io_purchase_order_lines` | **M2:** PO lines (product/variation, qty_ordered, qty_cancelled, unit cost, line-level expected overrides) | v7+ |
| `{prefix}wc_io_po_events` | **M2:** Append-only PO event audit log with optional reason_code | v7+ |
| `{prefix}wc_io_suppliers` | **M1:** Supplier entity with contact info, currency, configured lead time | v6+ |
| `{prefix}wc_io_inventory_movements` | Stock/cost movement ledger | v1+ |
| `{prefix}wc_io_purchase_batches` | Batch purchase headers | v1+ |
| `{prefix}wc_io_purchase_batch_lines` | Per-SKU batch lines | v1+ |
| `{prefix}wc_io_purchase_batch_costs` | Landed cost lines per batch | v1+ |
| `{prefix}wc_io_exchange_rates` | FX rate history | v2+ |

Schema via `dbDelta()` on activate and version bump. M2 adds three PO tables; `assert_schema_shape()` writes canonical `wc_io_schema_assertion` and forbids `qty_received` on PO lines until M5. M1 adds the `wc_io_suppliers` table; `inventory_movements` gains `reference_type`, `reference_id`, and `supplier_id` columns in M4. **M3 introduces no schema change and no migration** — `DB_VERSION` remains `7`; Inventory Position is computed entirely at read time from the two existing M2 PO tables (see [Milestone M3](#milestone-m3--inventory-position-read-only) below).

---

## WordPress options (`wc_io_*`)

Managed in `class-wc-inventory-overview-settings.php`, including:

- Snapshot order status mode, shipping tax inclusion, default shipping package
- Zero supplier cost policy, auto inventory value on stock edit
- Default low stock threshold, reporting range, purchase currency
- Highlight negative profit, legacy FX cleanup on upgrade

Exchange rates are **table-backed**; legacy FX options are deleted on upgrade.

---

## Admin pages and tabs

### Inventory & Profit (`wc-inventory-profit`)

| Tab | Capability | Primary classes |
|-----|------------|-----------------|
| Dashboard | `edit_products` | `Dashboard_Inventory_Metrics`, `Dashboard_Charts_Data` |
| Inventory Overview | `edit_products` (base); Incoming/Position columns + drill-down **M3:** `manage_woocommerce` only, same tier as average cost/inventory value | `List_Table`, `Repository`, `Summary`, `Inventory_Position_Service` |
| Restock / Cost Adjustment | `manage_woocommerce` | `Restock_Service`, `Batch_Intake_*`, `Cost_Adjustment_Service` |
| Movements | `manage_woocommerce` | `Movements`, `Movements_List_Table` |
| Order Profit | `manage_woocommerce` | `Order_Profit_Query`, `Order_Profit_List_Table` |
| Product Profitability | `manage_woocommerce` | `Product_Profitability_Query`, list table |
| Settings | `manage_woocommerce` | `Settings`, `Exchange_Rates`, `Data_Reset` |

### Purchasing (`wc-io-purchasing`, M1 + M2)

| Section | Capability | Primary classes |
|---------|------------|-----------------|
| Purchase Orders | `manage_woocommerce` (filterable via `Purchasing_Caps`) | `Purchasing_Page`, `PO_Admin`, `Purchase_Orders_List_Table`, `PO_Service` |
| Suppliers | `manage_woocommerce` | `Purchasing_Page`, `Suppliers_List_Table`, `Suppliers` |

Legacy slugs `wc-inventory-overview` and `wc-inventory-restock` redirect into the Inventory & Profit hub.

---

## AJAX actions (`wp_ajax_*`)

| Action | Handler | Capability + nonce | Version |
|--------|---------|--------------------|---------| 
| `wc_io_search_suppliers` | **M1** Supplier autocomplete | `manage_woocommerce`; nonce `wc_io_search_suppliers` | v6+ |
| `wc_io_quick_create_supplier` | **M1** Inline supplier creation | `manage_woocommerce`; nonce `wc_io_quick_create_supplier` | v6+ |
| `wc_io_save_inline_stock` | Inline stock edit | `edit_products` + `edit_product`; nonce `wc_io_inventory` | v1+ |
| `wc_io_get_cost_adjustment_preview` | Cost adjustment preview | `manage_woocommerce`; nonce `wc_io_cost_adj_preview` | v1+ |
| `wc_io_batch_preview` | Batch intake preview | `manage_woocommerce`; nonce `wc_io_batch_preview` | v1+ |
| `wc_io_get_exchange_rate` | FX rate lookup | `manage_woocommerce`; nonce `wc_io_get_exchange_rate` | v2+ |

All are **admin-only** (`wp_ajax_*`, not `nopriv`).

---

## admin_post actions

| Hook | Purpose | Version |
|------|---------|---------|
| `wc_io_supplier_save` | **M1** Create/update supplier | v6+ |
| `wc_io_supplier_archive` | **M1** Archive supplier | v6+ |
| `wc_io_supplier_reactivate` | **M1** Reactivate supplier | v6+ |
| `wc_io_po_save` | **M2** Save draft/placed PO edits | v7+ |
| `wc_io_po_place` | **M2** Place draft PO | v7+ |
| `wc_io_po_cancel` | **M2** Cancel PO | v7+ |
| `wc_io_po_close_short` | **M2** Close short (terminal) | v7+ |
| `wc_io_po_delete_draft` | **M2** Hard-delete draft PO | v7+ |
| `wc_io_po_duplicate` | **M2** Duplicate to new draft | v7+ |
| `wc_io_restock` | Quick restock line | v1+ |
| `wc_io_batch_apply` | Commit batch intake | v1+ |
| `wc_io_cost_adjustment` | Average cost adjustment | v1+ |
| `wc_io_save_settings` | Plugin settings | v1+ |
| `wc_io_add_exchange_rate` | Add FX row | v2+ |
| `wc_io_delete_exchange_rate` | Delete FX row | v2+ |
| `wc_io_danger_reset_preview` | Danger zone preview | v1+ |
| `wc_io_danger_reset_apply` | Danger zone delete | v1+ |

CSV exports use `admin_init` + `check_admin_referer` (movements, order profit, product profitability).

---

## WooCommerce / HPOS integration

- **HPOS:** Declared compatible with custom order tables in main file.
- **Order snapshots:** `WC_Inventory_Overview_Order_Item_Snapshots` hooks `woocommerce_order_status_changed` to write line-item meta for profitability.
- **Shipping admin:** `WC_Inventory_Overview_Order_Shipping_Admin` extends order shipping cost capture.
- **Costing:** `WC_Inventory_Overview_Costing` reads/writes average unit cost meta used by movements and reports.
- **Product queries:** `WC_Inventory_Overview_Repository` uses `wc_get_products()` with `posts_clauses` filter for category/search/private exclusion.

No public REST routes or storefront-facing hooks.

---

## Service / class map

| Class | Responsibility | Version |
|-------|----------------|---------|
| `WC_Inventory_Overview_PO_Service` | **M2:** PO orchestration (create, place, cancel, close short, duplicate, line CRUD) in DB transactions | v7+ |
| `WC_Inventory_Overview_Purchase_Orders` | **M2:** PO header persistence | v7+ |
| `WC_Inventory_Overview_Purchase_Order_Lines` | **M2:** PO line persistence; **M3:** adds `list_open_lines_for_product_ids()` / `list_open_lines_for_variation_ids()` bulk read methods for Inventory Position (two separate queries, `status = placed` only, no `qty_received`) | v7+ |
| `WC_Inventory_Overview_PO_Events` | **M2:** Append-only PO event log | v7+ |
| `WC_Inventory_Overview_PO_Admin` | **M2:** PO list/detail UI, PRG handlers, timeline | v7+ |
| `WC_Inventory_Overview_Purchase_Orders_List_Table` | **M2:** WP_List_Table for PO list/views | v7+ |
| `WC_Inventory_Overview_PO_Lifecycle` | **M2:** Transition table and action availability | v7+ |
| `WC_Inventory_Overview_PO_Numbering` | **M2:** `PO-{YYYY}-{NNNN}` allocation (ADR-0002) | v7+ |
| `WC_Inventory_Overview_PO_Delay` | **M2:** Computed delayed detection | v7+ |
| `WC_Inventory_Overview_Purchasing_Caps` | **M2:** Filterable purchasing capability map | v7+ |
| `WC_Inventory_Overview_Inventory_Position_Resolver` | **M3:** Stateless, read-only `Position = On Hand + Incoming` calculator; no `$wpdb`, no product loading, no PO repository access | v7+ |
| `WC_Inventory_Overview_Inventory_Position_Service` | **M3:** Sole authoritative Position calculator (D12), single (`get_position()`) and bulk (`get_positions_bulk()`); the only caller of `Purchase_Order_Lines::list_open_lines_for_*_ids()` (enforced by architecture-guard tests) | v7+ |
| `WC_Inventory_Overview_Suppliers` | **M1:** Supplier CRUD + normalization | v6+ |
| `WC_Inventory_Overview_Suppliers_Migration` | **M1:** Seed migration from historical supplier strings | v6+ |
| `WC_Inventory_Overview_Purchasing_Page` | **M1:** Admin page controller for Purchasing submenu | v6+ |
| `WC_Inventory_Overview_Suppliers_List_Table` | **M1:** WP_List_Table for supplier list/detail | v6+ |
| `Repository` | Product list queries, `posts_clauses` | v1+ |
| `Costing` | Average cost meta | v1+ |
| `Movements` | Insert/query movement rows | v1+ |
| `Restock_Service` | Quick restock transactions | v1+ |
| `Batch_Intake_Service` / `Batch_Intake_UI` | Multi-line purchase batches | v1+ |
| `Cost_Adjustment_Service` | Manual avg cost changes | v1+ |
| `Exchange_Rates` | FX table CRUD + lookup | v2+ |
| `Order_Profit_Query` / `Product_Profitability_Query` | Reporting SQL | v1+ |
| `Data_Reset` | Scoped delete of plugin analytics data | v1+ |
| `Summary` | Hub totals | v1+ |
| `*List_Table` | WP_List_Table admin UIs | v1+ |

---

## Assets

| Path | Enqueued on | Version |
|------|-------------|---------|
| `assets/po-admin.js` | **M2** Purchasing → Purchase Orders detail (line editor) | v7+ |
| `assets/purchasing.css` | **M2** Purchasing page (shared tab styling) | v7+ |
| `assets/supplier-picker.js` | **M1** Restock (Batch Intake, Quick Restock) — Select2 autocomplete + inline supplier creation | v6+ |
| `assets/admin.css`, `admin.js` | Inventory hub (overview tab) | v1+ |
| `assets/batch-intake.js` | Restock batch view | v1+ |
| `assets/restock-cost-adj.js` | Restock quick/adjust views | v1+ |
| `assets/movements-table.js` | Movements tab | v1+ |
| `assets/settings-shipping-fx.js` | Settings | v1+ |
| `assets/dashboard-charts.js` | Dashboard | v1+ |
| `assets/vendor/chart.umd.min.js` | Dashboard (Chart.js, vendored) | v1+ |

Scripts receive localized nonces and AJAX URLs from `Plugin::enqueue_*`.

---

## CLI scripts

| File | Use |
|------|-----|
| `cli/set-low-stock-threshold.php` | WP-CLI `eval-file`: bulk-set variation `_low_stock_amount` to 3 |
| `includes/class-wc-inventory-overview-reconcile-cli-command.php` | **M5:** `wp wc-io reconcile-qty-received [--fix] [--po=<id>]`, a true registered `WP_CLI::add_command()` (loaded via the normal `includes/` require chain, guarded by `class_exists('WP_CLI')`) — qty_received drift diagnostic/repair, read-only by default |

Operational only; not loaded by WordPress admin requests in a meaningful way beyond registration. Requires WP-CLI + WooCommerce.

---

## Milestone M3 — Inventory Position (read-only)

**Status:** Complete, v1.20.0. **No schema change, no migration** — `DB_VERSION` remains `7`. **Entirely read-only** — no receiving, no stock mutation, no cost mutation, no `qty_received`, no Goods Receipts (deferred to M4/M5).

**Resolver** (`WC_Inventory_Overview_Inventory_Position_Resolver::resolve()`): pure function, `Position = On Hand + Incoming`. Stateless — no `$wpdb`, no `wc_get_product()`, no PO-repository coupling. Returns exactly `{on_hand, incoming, position, incoming_delayed}`.

**Service** (`WC_Inventory_Overview_Inventory_Position_Service`): the sole authoritative calculator (D12). `get_position()` delegates to `get_positions_bulk()` with a single-item map, so single and bulk calls always share one code path. `get_positions_bulk()` calls each repository bulk method at most once, groups returned lines by item ID, sums outstanding quantities in PHP, and retains every contributing line individually as `incoming_lines` (INV-1/INV-7) — never writes, never refetches WooCommerce products, never caches.

**Repository reads** (`WC_Inventory_Overview_Purchase_Order_Lines`): two new, independent, safely-`$wpdb->prepare()`-d methods — `list_open_lines_for_product_ids()` (`variation_id = 0`) and `list_open_lines_for_variation_ids()` — deliberately not combined into one OR-based query. Both qualify on PO header `status = 'placed'` only (never the PO-line status column, never `qty_received`, which schema v7 still forbids), compute `outstanding = GREATEST(0, qty_ordered - qty_cancelled)`, and reuse `WC_Inventory_Overview_PO_Delay::sql_line_delayed_predicate()` unmodified for the delayed flag. Empty ID lists short-circuit to an empty result without issuing SQL.

**Inventory Overview integration** (`WC_Inventory_Overview_List_Table`): Incoming/Position columns render next to Stock, gated to `manage_woocommerce` (same sensitivity tier as average cost/inventory value — a deliberately narrower gate than the `edit_products` base tab access, no new capability introduced). `prepare_items()` calls `get_positions_bulk()` exactly once, strictly after the complete parent/variation groups structure (including variations discovered by the later per-parent query) is built — never per-row. Drill-down reuses the existing details-toggle/expandable-details pattern (extended with a matching detail row per variation, which previously had a "Details" button with no panel to open) — no AJAX, no REST, no admin-post, no new modal framework. Variable-parent rows show a presentation-only sum of child-variation Incoming/Position (`compute_variable_aggregate()` extended to accept the position map); no incoming record is ever created against a variable parent (INV-8).

**No-N+1 guard:** a dedicated query-scaling regression test creates 20+ mixed simple/variation items and asserts, via the `query` filter, that at most one product-scoped and one variation-scoped `SELECT` hit the PO-lines table for the entire page — both at the Service layer (`test-inventory-position-service.php`) and the Inventory Overview layer (`test-inventory-position-list-table.php`).

**Architecture guard:** `tests/unit/inventory-position/test-inventory-position-architecture.php` source-scans `includes/` to assert only the Service calls the two bulk repository methods (D12), the Resolver stays stateless/isolated, neither Resolver nor Service contains stock/cost-mutation or receiving-domain code, and no `qty_received` reference exists anywhere in the M3 surface (in executable code — explanatory comments describing the absence are exempt from the scan).

---

## Milestone M4 — Receipt Engine (Goods Receipt)

**Status:** Complete, v1.21.0. **Schema v8** — three new tables (`wc_io_goods_receipts`, `wc_io_receipt_lines`, `wc_io_receipt_costs`) plus an ALTER on `wc_io_inventory_movements` (`reference_type`, `reference_id`, `supplier_id`, nullable — legacy `purchase`/`purchase_batch`/`cost_adjustment` inserts unaffected). **First milestone that mutates WooCommerce stock and weighted-average cost** (D3/INV-2) — every prior milestone was schema-additive-only or strictly read-only.

**Scope:** Quick Receive Without PO (direct receipts) only. `receipt_line.po_line_id` exists (nullable, indexed) for M5's future use but is never populated by any M4 code path — no service method accepts it as a parameter. No PO table is ever written; no `qty_received`; no PO events.

**Schema** (`WC_Inventory_Overview_Install`): `expected_schema_v8()` extends `expected_schema_v7()` with the three new tables' full column lists plus a first-ever `inventory_movements` columns entry (asserting the ALTER); the `expected_schema()` dispatcher now checks `version_compare( $version, '8', '>=' )` before falling through to v7/v6, and `assert_schema_shape()` gained a `receipt_number` unique-index check gated the same way the existing `po_number` check is. `forbidden_columns` for `qty_received` on `purchase_order_lines` carries forward unchanged.

**Numbering** (`WC_Inventory_Overview_Goods_Receipt_Numbering`): `GR-{YYYY}-{NNNN}`, byte-for-byte mirror of `PO_Numbering` — never-reuse, retry-on-collision via the `wc_io_gr_number` filter.

**Lifecycle** (`WC_Inventory_Overview_Goods_Receipt_Lifecycle`): exactly three states, `draft → posted → voided`, no reopen. Structurally identical shape to `PO_Lifecycle` but with the smaller two-transition table.

**Repositories** (`Goods_Receipts`, `Receipt_Lines`, `Receipt_Costs`): persistence-only, no lifecycle policy, no stock mutation. `Goods_Receipts` exposes `compare_and_swap_post()`/`compare_and_swap_void()` — conditional `UPDATE ... WHERE status = %s` (rows-affected checked by the caller) — the entire concurrency-control surface for M4; **no `SELECT ... FOR UPDATE`/`get_for_update()` exists on this repository** (a deliberate deviation from the `Purchase_Orders`/`Purchase_Order_Lines` precedent, which does use row locks for its own draft/placed mutation guards — the M4 plan's concurrency model is explicitly token+compare-and-swap only, with no row-locking of any kind).

**Costing** (`WC_Inventory_Overview_Goods_Receipt_Costing`): landed-cost allocation formula ported (duplicated, not shared) from `Batch_Intake_Service` — proportional by EUR line value, remainder to the last line — because `Batch_Intake_Service`'s allocation logic is `protected`/internal to a feature slated for M6 removal; extracting a shared class now would be premature (no second caller exists yet beyond this one deliberate duplication). Cost-type slugs/labels ARE reused directly from `Batch_Intake_Service` (safe cross-reference, unlike the allocation math). `preview_line()` is a pure, read-only re-derivation of the same weighted-average formula `Restock_Service::apply_purchase_line_change()` uses, for the post-confirmation preview screen only — it never persists anything; the actual mutation always goes through `Restock_Service`.

**Movements** (`WC_Inventory_Overview_Movements`): gained `TYPE_GOODS_RECEIPT`/`TYPE_GOODS_RECEIPT_VOID` constants and `insert_goods_receipt()`/`insert_goods_receipt_void()`, both routed through a refactored `insert_purchase_like()` that now accepts an optional `$reference` array (`reference_type`/`reference_id`/`supplier_id`) via `$wpdb->insert()` — legacy 2-argument calls (`insert_purchase()`, `insert_purchase_batch()`) are unaffected, those columns stay `NULL` exactly as before.

**Restock reversal** (`WC_Inventory_Overview_Restock_Service::apply_purchase_line_reversal()`): current-state-relative subtraction, not a snapshot restore — reads stock/average *now* and subtracts only the voided line's own immutable stored delta (`qty`, `true_unit_cost`), so it composes correctly regardless of how many other receipts posted against the same product in between. Rejects (via `WP_Error`) when the resulting stock would go negative (units already sold) rather than partially reversing.

**Service** (`WC_Inventory_Overview_Goods_Receipt_Service`) — the sole M4 inventory-mutation entry point (structurally enforced: `tests/unit/goods-receipt/test-goods-receipt-architecture.php` source-scans every `includes/` file and fails if anything other than this class and the pre-existing `Batch_Intake_Service` calls `Restock_Service`'s two mutation methods). `post()`/`void()` each wrap their entire mutation in exactly one `WC_Inventory_Overview_DB_Transaction::run()` closure; every fallible call inside that closure is routed through a `throw_if_error()` bridge (`WC_Inventory_Overview_Goods_Receipt_Posting_Exception`) since `DB_Transaction::run()` only catches `Exception`, never `WP_Error`. The service never calls `$txn->begin()`/`commit()`/`rollback()` directly — only `run()`.

**A real bug found and fixed during implementation, not merely anticipated by the plan:** a rolled-back `post()`/`void()` correctly reverts the underlying SQL row (verified directly via a raw `SELECT` against `wp_postmeta`, bypassing all caching), but WordPress's own `update_post_meta()` synchronously writes through to the object-cache `post_meta` group as part of every meta write — a write the SQL `ROLLBACK` cannot reach, since the object cache (Redis, on this deployment) is not part of the MySQL transaction. Without an explicit fix, a failed post left `wc_get_product()` reporting a stock/cost value that was never actually committed, until something else happened to evict that cache entry. Fixed by calling `clean_post_cache()` (not just `wc_delete_product_transients()`, which only clears WooCommerce's own transients, not the WP-core postmeta cache group) for every touched product on **both** the success path (post-commit) and the failure path (post-rollback) — pure invalidation, not a value-setting side effect, so it's safe regardless of transaction outcome and doesn't violate the "no irreversible side effect before commit" rule (invalidating a cache entry doesn't announce a mutation to anything external; it just forces the next read to be accurate).

**Idempotency and concurrency:** `PO_Request_Token` reused with new contexts `gr_post`/`gr_void`, consumed as the very first statement of `post()`/`void()` — before even the cheap pre-transaction status check, so a resubmitted/expired token performs zero DB work. The compare-and-swap status UPDATE is the transaction's first write. No row locking anywhere in the M4 surface (verified by the architecture guard). Note: because the cheap pre-transaction status check (`must be draft`/`must be posted`) already rejects a purely-sequential double-post/double-void before a transaction even opens, the deeper compare-and-swap layer's own conditionality is verified directly at the repository level (`Test_WC_IO_Goods_Receipts_Repository`), since true concurrent-request races aren't reproducible in a synchronous PHPUnit test.

**Admin UI** (`WC_Inventory_Overview_Goods_Receipt_Admin`, `Goods_Receipts_List_Table`): new "Receive Stock" tab on the Purchasing page, alongside (not replacing) Purchase Orders and Suppliers. Mirrors `PO_Admin`'s PRG/token/nonce pattern. Posting and voiding each require a dedicated confirmation screen (`action=post_confirm`/`void_confirm`) showing a computed, read-only preview before the real mutating POST — never a one-click submit.

**Capabilities** (`WC_Inventory_Overview_Purchasing_Caps`): five new action keys (`VIEW_RECEIPT`, `EDIT_RECEIPT`, `POST_RECEIPT`, `VOID_RECEIPT`, `DELETE_RECEIPT`), all defaulting to `manage_woocommerce` through the existing filterable map — no new WordPress capability registered. Enforced both in the admin controller and, independently, inside every `Goods_Receipt_Service` mutation method.

**Architecture guard:** `tests/unit/goods-receipt/test-goods-receipt-architecture.php` (16 tests) — sole-mutator enforcement, no direct `DB_Transaction` begin/commit/rollback calls from the service, every `new WP_Error()` inside a transaction closure wrapped by `throw_if_error()` (verified via brace-matched closure extraction, not a naive regex), cache invalidation isolated to outside the transaction closures, named movement-type constants only (no inlined string literals), no row-locking constructs, `po_line_id` never populated, no `qty_received`, no PO-table writes, no M5 functionality, and the three-state/no-reopen lifecycle shape.

---

## Milestone M5 — Purchase Order Receiving

**Status:** Complete, v1.22.0. **Schema v9** — one column addition (`qty_received decimal(19,4)` on `wc_io_purchase_order_lines`), zero new tables — M4 had already prepared `receipt_line.po_line_id` and `goods_receipt.source` for this moment.

**Scope:** connects Purchase Orders (M2) to the Goods Receipt engine (M4). `Goods_Receipt_Service` remains the sole stock/cost mutator (D3/INV-2, unchanged) and gains a second responsibility: sole business orchestrator for `qty_received` changes. No second mutation path was introduced anywhere.

**Schema** (`WC_Inventory_Overview_Install`): `expected_schema_v9()` extends `expected_schema_v8()` with one new `columns['purchase_order_lines']` entry (`qty_received`) and clears `forbidden_columns['purchase_order_lines']` — the one `forbidden_columns` entry M5 is permitted to change. The `expected_schema()` dispatcher gained a `version_compare( $version, '9', '>=' )` branch before falling through to v8/v7/v6 (the identical dispatcher trap M4 flagged for v7/v8 repeats identically at v8/v9, and is guarded the same way).

**Formal invariant — the three-tier `qty_received` ownership chain** (mirrors D3/INV-2's own elevation from implementation detail to named architectural rule):

```
Goods_Receipt_Service              (sole business orchestrator — initiates the change)
        ↓
PO_Receiving_Sync                  (sole owner of the mutation — decides the delta,
        │                            recomputes PO status, authors the PO event)
        ↓
Purchase_Order_Lines::increment_qty_received()   (sole physical database writer)
```

The one explicitly-named exception: the reconciliation CLI's `--fix` mode writes through a second, distinctly-named method on the *same* sole-owner class (`PO_Receiving_Sync::reconcile_line()`), never through `increment_qty_received()` directly.

**`WC_Inventory_Overview_PO_Receiving_Sync`** (new class): two public methods, each with exactly one permitted caller (architecture-guard enforced). `apply_line_delta()` — the receiving path — is called only by `Goods_Receipt_Service`, from inside its existing `post()`/`void()` transaction closure, immediately after that line's `Restock_Service` call and movement insert succeed. `reconcile_line()` — the reconciliation path — is called only by the CLI command. Neither opens its own transaction (verified by an architecture guard scanning for `begin()`/`commit()`/`rollback()`/`new WC_Inventory_Overview_DB_Transaction`). Internally: an atomic `UPDATE ... SET qty_received = qty_received + %f` (safe under concurrent transactions without row locking, same argument M4 already established for stock), a bulk re-fetch of the PO's lines to sum outstanding/received, a pure status-recompute call, a conditional header status write, and one or two `PO_Events::add()` calls.

**Status recompute** (`WC_Inventory_Overview_PO_Statuses::recompute_for_receiving()`): pure, direction-agnostic function — the same current-state-relative design principle M4 used for void correctness, applied here to PO status. Produces the identical answer whether reached by a post (totals went up) or a void (totals went down), because it never inspects direction, only current totals. Two new statuses, `partially_received`/`received`, are auto-transitioned only — never a manual transition target in `PO_Lifecycle::transitions()`, never operator-selectable.

**`PO_Lifecycle`**: `transitions()` gained `partially_received` as a *from*-status (so `cancel`/`close_short` remain available while a PO is partially received) and `received` as an empty-list *from*-status (no manual action once fully received — its only exit is the automatic downgrade via a receipt void, which bypasses this table entirely). Neither new status is editable.

**`PO_Events`**: five new types (`po_line_received`, `po_line_receipt_voided`, `po_partially_received`, `po_received`, `po_qty_received_reconciled`), written only by `PO_Receiving_Sync` — closing the exact audit-trail gap M4's own Audit-trail decision reserved for this milestone (INV-6's "PO event log" clause, structurally inapplicable to M4's PO-less receipts, is now literally satisfiable for PO-linked ones).

**`Goods_Receipt_Service` integration**: a new pre-transaction validation step (`validate_and_assess_po_linked_lines()`) rejects receiving against a non-receivable PO status (`draft`/`cancelled`/`closed_short`) before any transaction opens, and assesses over-receipt (D5: never blocked, only flagged) against 4-decimal-rounded current outstanding. `post()`/`void()` each gained one additional call per PO-linked line — `PO_Receiving_Sync::apply_line_delta()` — inside the existing transaction, after that line's stock mutation and movement insert. `source` (`direct`/`po`/`mixed`) is derived from line composition at draft-save time, never operator-chosen. A defense-in-depth check (`assert_po_line_matches_product()`) rejects a submitted product that doesn't match its referenced PO line's product, before any draft is even saved.

**`Receipt_Lines`**: `create()`'s hardcoded `'po_line_id' => null` (M4's structural guarantee) became conditional on `$data['po_line_id']` — the only write path to this column anywhere in the repository; `update()`'s whitelist still excludes it (PO linkage is fixed at creation, not editable in place).

**Reconciliation tooling** (`wp wc-io reconcile-qty-received [--fix] [--po=<id>]`, new WP-CLI command, registered via `WP_CLI::add_command()` — the first true registered command in this codebase, alongside the pre-existing `cli/*.php` eval-file scripts): read-only by default (sums posted receipt-line quantities per PO line and compares against stored `qty_received`); `--fix` repairs through `PO_Receiving_Sync::reconcile_line()` only; every repair is individually logged and recorded as its own `po_qty_received_reconciled` PO event; summary output reports verified/drift/repaired counts.

**Admin UI**: a "Receive" button on `PO_Admin`'s detail page (gated by a new `RECEIVE_PO` capability, default `manage_woocommerce`, filterable through the existing map) routes to a new `Goods_Receipt_Admin::render_new_from_po()` entry point, which pre-fills a new draft's lines from the PO's outstanding lines and reuses the existing M4 editable-detail template unchanged — no new persistence method. The PO detail page gained a "Received" column and a bulk-queried (not per-line) "Receiving History" panel; Goods Receipt lines show a "Fulfils: PO-XXXX line N" back-link, computed at render time, never stored redundantly.

**M3 Incoming regression fix**: `Purchase_Order_Lines::query_open_lines()`'s raw SQL `GREATEST()` literal gained the `qty_received` term, and its `WHERE po.status IN (...)` list gained `partially_received`/`received` (previously `placed` only) — a partially-received PO's remaining outstanding now correctly continues to surface as Incoming, the exact recomputation M3's own plan deferred to this milestone. `WC_Inventory_Overview_PO_Delay::sql_line_delayed_predicate()`'s outstanding term was updated identically for consistency, though its own `po.status = 'placed'` gate was deliberately left unchanged (an intentionally narrower, separate scope decision — see the M5 implementation plan's git history for the explicit reasoning) — so a partially-received PO's line is never flagged "Delayed" even though it still has real outstanding, a documented, conservative gap rather than a silent one.

**Architecture guard:** `tests/unit/po-receiving/test-po-receiving-architecture.php` — `increment_qty_received()` has exactly one caller (`PO_Receiving_Sync`); `apply_line_delta()` has exactly one caller (`Goods_Receipt_Service`); `reconcile_line()` has exactly one caller (the reconciliation CLI); `PO_Receiving_Sync` never opens its own transaction; `Restock_Service`'s caller set gained zero new entries; no new value is ever written to the per-line `wc_io_purchase_order_lines.status` column; named `PO_Events::TYPE_*` constants only, never inlined string literals. Every M4 architecture guard whose premise M5 legitimately changed (`test_po_line_id_never_populated`, `test_no_receiving_event_types_declared`, and others) was deliberately revised with a named, documented replacement — none silently broken, none silently deleted (verified individually, not assumed).

---

## Known risks / tech debt

1. **Large god class:** `class-wc-inventory-overview-plugin.php` centralizes UI, handlers, and exports — harder to test and review.
2. **Custom SQL surface:** Profitability and movements list tables build dynamic SQL; most paths use `$wpdb->prepare`, but complexity increases regression risk.
3. **`posts_clauses` filter:** Global filter at priority 999; scoped by query depth and admin context — avoid front-end product queries while filter is active.
4. **Danger zone reset:** Can bulk-delete plugin tables/meta snapshots; gated by capability + nonces + preview token — still high impact for operators.
5. **Inline stock AJAX:** Uses `edit_products` (broader than `manage_woocommerce`) with per-product `edit_product` — intentional for catalog editors.
6. **Automated tests:** PHPUnit unit/integration suites (M0 golden + M1 suppliers + M2 purchase orders + M3 Inventory Position + M4 Goods Receipts + M5 PO Receiving), PHPCS (local), and GitHub Actions CI (PHP lint + release ZIP). PHPUnit runs via Docker harness under `tests/docker/`. See `docs/testing.md`.
7. **Monorepo mirror:** A development copy may exist under `biopentra-custom-plugins/plugins/wc-inventory-overview/`; this standalone repo is canonical for releases.

---

## Recommended follow-ups (non-blocking)

- Split `Plugin` into tab controllers or modules.
- Extend schema-shape assertion per milestone (M3 introduced no schema change; M4 added Goods Receipt tables/columns; M5 added `qty_received`; next relevant at M6 migration/retirement).
- `PO_Delay`'s "Delayed" detection deliberately does not yet extend to `partially_received` POs (M5 left this gap open, documented rather than silent) — worth a small follow-up milestone or ADR if operators need delay flagging on partially-received POs.
