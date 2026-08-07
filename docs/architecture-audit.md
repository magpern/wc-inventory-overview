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

**`Goods_Receipt_Service` integration**: a new pre-transaction validation step (`validate_and_assess_po_linked_lines()`) rejects receiving against a non-receivable PO status (`draft`/`cancelled`/`closed_short`) before any transaction opens, and assesses over-receipt (D5: never blocked, only flagged) against 4-decimal-rounded current outstanding. `post()`/`void()` each gained one additional call per PO-linked line — `PO_Receiving_Sync::apply_line_delta()` — inside the existing transaction, after that line's stock mutation and movement insert. `source` (`direct`/`po`/`mixed`) is derived from line composition at draft-save time, never operator-chosen. A defense-in-depth check (`assert_po_line_matches_product()`) rejects a submitted product that doesn't match its referenced PO line's product, before any draft is even saved. This validation step reads every PO-linked line's PO line and owning PO via two bulk `list_by_ids()` calls (`Purchase_Order_Lines::list_by_ids()`, `Purchase_Orders::list_by_ids()`) rather than one `get()` call per line — see §Audit remediation below.

**`Receipt_Lines`**: `create()`'s hardcoded `'po_line_id' => null` (M4's structural guarantee) became conditional on `$data['po_line_id']` — the only write path to this column anywhere in the repository; `update()`'s whitelist still excludes it (PO linkage is fixed at creation, not editable in place).

**Reconciliation tooling** (`wp wc-io reconcile-qty-received [--fix] [--po=<id>]`, new WP-CLI command, registered via `WP_CLI::add_command()` — the first true registered command in this codebase, alongside the pre-existing `cli/*.php` eval-file scripts): read-only by default (sums posted receipt-line quantities per PO line and compares against stored `qty_received`); `--fix` repairs through `PO_Receiving_Sync::reconcile_line()` only; every repair is individually logged and recorded as its own `po_qty_received_reconciled` PO event; summary output reports verified/drift/repaired counts.

**Admin UI**: a "Receive" button on `PO_Admin`'s detail page (gated by a new `RECEIVE_PO` capability, default `manage_woocommerce`, filterable through the existing map) routes to a new `Goods_Receipt_Admin::render_new_from_po()` entry point, which pre-fills a new draft's lines from the PO's outstanding lines and reuses the existing M4 editable-detail template unchanged — no new persistence method. The PO detail page gained a "Received" column and a bulk-queried (not per-line) "Receiving History" panel; Goods Receipt lines show a "Fulfils: PO-XXXX line N" back-link, computed at render time, never stored redundantly. Each PO-linked line in the receipt editor shows a live "Outstanding: X.XXXX" figure (read fresh at render time, never cached); the post-confirmation screen shows a mandatory, non-suppressible over-receipt warning naming every affected line and its over-received quantity whenever any line exceeds its current outstanding (see §Audit remediation).

**M3 Incoming regression fix**: `Purchase_Order_Lines::query_open_lines()`'s raw SQL `GREATEST()` literal gained the `qty_received` term, and its `WHERE po.status IN (...)` list gained `partially_received`/`received` (previously `placed` only) — a partially-received PO's remaining outstanding now correctly continues to surface as Incoming, the exact recomputation M3's own plan deferred to this milestone. `WC_Inventory_Overview_PO_Delay::sql_line_delayed_predicate()`'s outstanding term was updated identically for consistency, though its own `po.status = 'placed'` gate was deliberately left unchanged (an intentionally narrower, separate scope decision — see the M5 implementation plan's git history for the explicit reasoning) — so a partially-received PO's line is never flagged "Delayed" even though it still has real outstanding, a documented, conservative gap rather than a silent one.

**Architecture guard:** `tests/unit/po-receiving/test-po-receiving-architecture.php` — `increment_qty_received()` has exactly one caller (`PO_Receiving_Sync`); `apply_line_delta()` has exactly one caller (`Goods_Receipt_Service`); `reconcile_line()` has exactly one caller (the reconciliation CLI); `PO_Receiving_Sync` never opens its own transaction; `Restock_Service`'s caller set gained zero new entries; no new value is ever written to the per-line `wc_io_purchase_order_lines.status` column; named `PO_Events::TYPE_*` constants only, never inlined string literals. Every M4 architecture guard whose premise M5 legitimately changed (`test_po_line_id_never_populated`, `test_no_receiving_event_types_declared`, and others) was deliberately revised with a named, documented replacement — none silently broken, none silently deleted (verified individually, not assumed).

### M5 audit remediation (pre-tag)

An independent audit of the completed M5 implementation (before this branch was merged or tagged) found four genuine gaps against the M5 implementation plan's own binding Definition of Done. All four were fixed on this same branch, without touching the ownership chain, schema, DB_VERSION, reconciliation architecture, rollback logic, or transaction boundaries documented above:

- **Outstanding-quantity display** — `Goods_Receipt_Admin::render_line_row()` now shows a live "Outstanding: X.XXXX" figure next to the qty field for every PO-linked line, read fresh via `Purchase_Order_Lines::get()` + `outstanding()` at render time (no business logic duplicated).
- **Mandatory over-receipt warning** — a new `Goods_Receipt_Service::preview_po_over_receipt()` read-only method reuses the exact same assessment `validate_and_assess_po_linked_lines()` computes at real post time; `render_post_confirm()` renders it as a non-suppressible warning banner naming every over-receiving line and its over-received quantity. The authoritative assessment is still always recomputed fresh inside `post()` itself.
- **N+1 in `validate_and_assess_po_linked_lines()`** — replaced per-line `Purchase_Order_Lines::get()` / `Purchase_Orders::get()` calls with two new bulk repository methods, `Purchase_Order_Lines::list_by_ids()` and `Purchase_Orders::list_by_ids()`, each a single `WHERE id IN (...)` query regardless of line count. `tests/integration/po-receiving/test-po-receiving-performance.php` now asserts this directly (constant query count at 2 vs. 100 PO-linked lines, via `ReflectionMethod`) and adds the plan's own named ~100-line end-to-end scale test, previously never exercised past 4 lines.
- **`close_short()` and `qty_received`** — `PO_Service::close_short()`'s per-line loop now cancels exactly the unreceived remainder (`qty_ordered - qty_received`) instead of the full ordered quantity, restoring the invariant `qty_received + qty_cancelled == qty_ordered` for a line closed short after a partial receipt. `tests/integration/po-receiving/test-close-short-with-qty-received.php` covers the partially-received, zero-received, and mixed-lines-on-one-PO cases.

---

## Milestone M6 — Migration & Retirement

**Status:** Complete, v1.23.0. **Schema v10** — two-column addition (`migrated_receipt_id bigint(20) unsigned NULL`, `migrated_at datetime NULL` on `wc_io_purchase_batches`), zero new tables. Migration-tracking metadata only — no new business-domain schema; the migrated data fits M4's existing Goods Receipt shape exactly.

**Scope:** consolidates the plugin onto one receiving history. Migrates every existing legacy Batch Intake record into a posted, `source = 'migrated'` Goods Receipt, replaces the batch↔movement regex linkage with the typed `reference_type`/`reference_id` columns Architecture v1.0 §1 (D14) promised, and retires Batch Intake's ability to create new batches. `Goods_Receipt_Service::post()` remains the sole *live* stock/cost mutator (D3/INV-2, unchanged); M6 adds a second, narrower write path that never mutates current stock or cost at all.

**Central architectural decision — migration is record materialization, not receiving.** A migrated Goods Receipt is a historical fact being written down in today's schema; the stock/cost mutation it describes already happened, once, when the original batch was applied via `Batch_Intake_Service::apply_batch_from_post()`, and is already fully reflected in current WooCommerce stock/cost. `WC_Inventory_Overview_Batch_Migration_Service` therefore never calls `set_stock_quantity()`, never writes `_wc_io_average_unit_cost`/`_wc_io_inventory_value` product meta, and never goes through `Goods_Receipt_Service`, `Restock_Service`, or `PO_Receiving_Sync` — enforced by `tests/unit/batch-migration/test-batch-migration-architecture.php`, the single most important guard in this milestone.

**`WC_Inventory_Overview_Batch_Migration_Service`** (new class): `migrate_batch( int $batch_id )` — one `DB_Transaction::run()` call per batch (Invariant M6-1: never a shared transaction spanning multiple batches — verified both by an architecture guard counting `new WC_Inventory_Overview_DB_Transaction(` instantiations and behaviorally by a forced-failure test). `verify_batch( int $batch_id )` — read-only drift check against the source batch, the permanent reconciliation tool for this data. `rollback_batch( int $batch_id )` — deletes the migrated receipt/lines/costs, clears the movement reference back to `NULL`, clears the batch's tracking columns; never touches current stock/cost (symmetric with forward migration, not a Goods Receipt void). Field mapping is exposed as three pure static functions (`map_header()`, `map_line()`, `map_cost()`) callable independently of the transactional write path, unit-tested directly against fixture rows. Order independence (Invariant M6-2) follows from the design never reading current stock, current cost, or any other batch's rows — each batch's migration is a pure function of that batch's own already-stored rows.

**`WC_Inventory_Overview_Goods_Receipts`**: gained `SOURCE_DIRECT`/`SOURCE_PO`/`SOURCE_MIXED`/`SOURCE_MIGRATED` constants at the definition site of the existing source-string literals; `create_draft()`'s validation whitelist deliberately still excludes `SOURCE_MIGRATED` (migration never calls `create_draft()` — it writes the header row directly, since a migrated receipt needs historical `posted_at`/`created_at`/`posted_by` values `create_draft()`'s always-`now()`/always-draft-status design can't express).

**`WC_Inventory_Overview_Movements::backfill_reference()`** (new method, sole caller `Batch_Migration_Service`, architecture-guard enforced): updates one existing movement row's `reference_type`/`reference_id` in place — never inserts a new row, never touches quantity/cost/note/timestamp columns. Uses the pre-existing `REFERENCE_TYPE_GOODS_RECEIPT` constant (`'goods_receipt'`), the same value M4's `insert_goods_receipt()`/`insert_goods_receipt_void()` already write, so every receipt-referencing movement (live or migrated) shares one uniform `reference_type` vocabulary — a deliberate implementation detail, not a literal reading of the M6 implementation plan's placeholder prose (which said `'receipt'`). Movement rows are matched to their source batch via the same "Batch ID: {id}\n" first-note-line convention `movements-list-table.php`'s regex already reads, matched with an exact-integer-then-newline prefix (never a partial match between e.g. batch #1 and #12).

**`Goods_Receipt_Service::void()`**: one new guard immediately after the existing `STATUS_POSTED` check — rejects `source = 'migrated'` receipts with a documented `WP_Error`. Voiding reverses stock/cost relative to *current* state (M4's `apply_purchase_line_reversal()`), which is wrong for a historical replay row for the same reason forward-migration must never call `post()`. Undoing a migrated receipt is `wp wc-io migrate-batches --rollback=<id>` instead.

**`WC_Inventory_Overview_Landed_Cost_Types`** (new class, extracted from `Batch_Intake_Service`): closes the hidden-coupling remediation trigger M4's own implementation plan flagged (`Goods_Receipt_Costing` previously called `Batch_Intake_Service::allowed_cost_types()`/`landed_cost_type_labels()` directly "because the latter is protected/internal to a feature slated for M6 removal"). Behavior-preserving — same seven cost-type slugs/labels, characterization-tested. `Goods_Receipt_Costing` no longer references `Batch_Intake_Service` at all (source-scan verified).

**`wp wc-io migrate-batches [--apply] [--verify] [--batch=<id>] [--rollback=<id>] [--limit=<n>]`** (new WP-CLI command, modeled on `Reconcile_CLI_Command`'s dry-run-by-default shape): default mode is a strictly read-only preview; `--apply` migrates one batch at a time through `Batch_Migration_Service::migrate_batch()`; `--verify` is the permanent reconciliation mode; `--rollback=<id>` undoes one batch's migration after an operator confirmation prompt (`WP_CLI::confirm()`, respects `--yes`). Every write goes through `Batch_Migration_Service` — never raw SQL from the CLI file itself.

**Retirement** (four categories, per the M6 plan's Retirement strategy): **Removed** — `admin_post_wc_io_batch_apply`/`wp_ajax_wc_io_batch_preview` hook registrations, the Batch Intake subview from the Restock/Cost Adjustment tab nav (default subview becomes Quick Restock; a stale `restock_view=batch` bookmark falls back there rather than erroring), and the `wc-io-batch-intake` asset enqueue (its DOM target no longer renders). **Disabled, not deleted** — `Batch_Intake_Service::apply_batch_from_post()`/`rollback_batch_apply()`/`build_movement_note_for_line()`/`build_preview_from_post()`/`render_preview_markup()`, `Batch_Intake_UI::render_panel()`, and `Plugin::handle_batch_apply_post()`/`ajax_batch_preview()` — all marked `@deprecated`, all still directly callable (used by the test suite's `create_legacy_batch()` fixture builder to construct realistic historical data), slated for physical removal in M8. **Extracted, not deleted** — the landed-cost-type vocabulary (see above). **Frozen forever** — `wc_io_purchase_batches`/`wc_io_purchase_batch_lines`/`wc_io_purchase_batch_costs`, per D14; never dropped or truncated by any M6 code path (architecture-guard verified). Quick Restock and Cost Adjustment are verified unaffected (both source-scan- and behaviorally-tested).

**Architecture guards:** `tests/unit/batch-migration/test-batch-migration-architecture.php` — no `set_stock_quantity()`/cost-meta writes/`Goods_Receipt_Service::post()`/`Restock_Service` mutator calls/`PO_Receiving_Sync` references/supplier-name lookups anywhere in `Batch_Migration_Service`; `map_header()` hardcodes `supplier_id => null`; `backfill_reference()` has exactly one caller; exactly one `DB_Transaction` instantiation per public entry method (Invariant M6-1); no row-level locking; no `DROP TABLE`/`TRUNCATE`.

**Testing:** `tests/unit/batch-migration/` (architecture guards, pure field-mapping), `tests/integration/batch-migration/` (end-to-end mapping correctness, the historical-integrity golden test — byte-for-byte unchanged stock/cost across simple/USD-with-landed-cost/blended-average/multi-batch scenarios — movement backfill, idempotency, forced-failure transactional rollback, order independence, `rollback_batch()` symmetry, retirement regression, landed-cost-type-extraction characterization, per-batch query-cost performance), `tests/integration/goods-receipt/test-goods-receipt-migrated-void-guard.php`, `tests/integration/install/test-schema-v10-upgrade.php`.

---

## Known risks / tech debt

1. **Large god class:** `class-wc-inventory-overview-plugin.php` centralizes UI, handlers, and exports — harder to test and review.
2. **Custom SQL surface:** Profitability and movements list tables build dynamic SQL; most paths use `$wpdb->prepare`, but complexity increases regression risk.
3. **`posts_clauses` filter:** Global filter at priority 999; scoped by query depth and admin context — avoid front-end product queries while filter is active.
4. **Danger zone reset:** Can bulk-delete plugin tables/meta snapshots; gated by capability + nonces + preview token — still high impact for operators.
5. **Inline stock AJAX:** Uses `edit_products` (broader than `manage_woocommerce`) with per-product `edit_product` — intentional for catalog editors.
6. **Automated tests:** PHPUnit unit/integration suites (M0 golden + M1 suppliers + M2 purchase orders + M3 Inventory Position + M4 Goods Receipts + M5 PO Receiving + M6 Batch Migration), PHPCS (local), and GitHub Actions CI (PHP lint + release ZIP). PHPUnit runs via Docker harness under `tests/docker/`. See `docs/testing.md`.
7. **Monorepo mirror:** A development copy may exist under `biopentra-custom-plugins/plugins/wc-inventory-overview/`; this standalone repo is canonical for releases.

---

## Recommended follow-ups (non-blocking)

- Split `Plugin` into tab controllers or modules.
- Extend schema-shape assertion per milestone (M3 introduced no schema change; M4 added Goods Receipt tables/columns; M5 added `qty_received`; M6 added `wc_io_purchase_batches.migrated_receipt_id`/`migrated_at`; next relevant at M8 hardening).
- Physically delete the M6-deprecated Batch Intake code (`Batch_Intake_Service`'s apply/rollback/preview methods, `Batch_Intake_UI::render_panel()`, `Plugin::handle_batch_apply_post()`/`ajax_batch_preview()`) once M8 confirms a full release cycle with zero migration-related incidents.
- `PO_Delay`'s "Delayed" detection deliberately does not yet extend to `partially_received` POs (M5 left this gap open, documented rather than silent) — worth a small follow-up milestone or ADR if operators need delay flagging on partially-received POs.
