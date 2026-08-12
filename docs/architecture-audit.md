# Architecture audit — WC Inventory Overview 1.29.0

**Date:** 2026-08-08 (updated through Milestone M8); updated through M9–M12 2026-08-09/10; updated through M13–M15 2026-08-11 (M14 was not brought current at the time of its own freeze — a documentation-currency gap closed retroactively together with M15, per `docs/milestones/m15-implementation-plan.md` Part A; no fact about M9–M13 changed in this pass); updated through M16 2026-08-11.
**Scope:** Standalone repo `magpern/wc-inventory-overview`, Milestones M0–M15 complete and published (schema `DB_VERSION` 10) — Version 1.0 / GA ready since M8 (`v1.25.0` published); M9–M12 released as `v1.29.0`; M13–M15 released as `v1.32.0` (M9 received a full independent audit and remediation; M10–M15 each froze after a lightweight Level A completion review — see `docs/checklists/m9-release-readiness.md` … `m15-release-readiness.md`). **M16 is additionally complete, frozen (Level A completion review), and development-version-bumped to `1.33.0`, but not merged, tagged, or released** — `main` remains at `v1.32.0`; see `docs/checklists/m16-release-readiness.md`. For the consolidated architecture snapshot, see [`docs/ARCHITECTURE_BASELINE_v1.24.0.md`](ARCHITECTURE_BASELINE_v1.24.0.md) (updated in place through M16, filename unchanged since none of M8–M16 changed a frozen boundary); this document remains the per-milestone code/schema audit trail, section-by-section below.

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

Created/upgraded via `WC_Inventory_Overview_Install` (current `DB_VERSION = 10` as of M6/v1.23.0, unchanged through M7/v1.24.0 and M8/v1.25.0; option `wc_io_db_version`):

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
| `wc_io_po_print` | **M13** Read-only printable PO document (`VIEW_PO` + PO-scoped nonce; no mutation) | v10+ |
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

No public REST routes. **One storefront-facing hook as of M7:** `woocommerce_get_availability`, filtered exclusively by `WC_Inventory_Overview_Expected_Delivery_Renderer` — see the M7 section below.

---

## Service / class map

| Class | Responsibility | Version |
|-------|----------------|---------|
| `WC_Inventory_Overview_PO_Service` | **M2:** PO orchestration (create, place, cancel, close short, duplicate, line CRUD) in DB transactions | v7+ |
| `WC_Inventory_Overview_Purchase_Orders` | **M2:** PO header persistence | v7+ |
| `WC_Inventory_Overview_Purchase_Order_Lines` | **M2:** PO line persistence; **M3:** adds `list_open_lines_for_product_ids()` / `list_open_lines_for_variation_ids()` bulk read methods for Inventory Position (two separate queries, `status = placed` only, no `qty_received`) | v7+ |
| `WC_Inventory_Overview_PO_Events` | **M2:** Append-only PO event log | v7+ |
| `WC_Inventory_Overview_PO_Admin` | **M2:** PO list/detail UI, PRG handlers, timeline; **M13:** `handle_print()` composes the print render model from the three read owners below | v7+ |
| `WC_Inventory_Overview_PO_Print_Renderer` | **M13:** Presentation-only standalone printable-PO HTML renderer; zero repository access, zero authorization, zero mutation (INV-M13-2) | v10+ |
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

## Milestone M7 — Storefront Expected Delivery

**Status:** Complete, v1.24.0. **Schema unchanged (v10), zero new tables.** M7 derives, it does not store — its only persistent footprint is one `wp_options` row (`wc_io_expected_delivery_renderer_enabled`) the merchant sets deliberately.

**Scope:** the first milestone that a customer sees. Exposes exactly one governed fact — the earliest credible expected receipt, worded by confidence — behind a stable public API (D16: no speculative APIs until a concrete consumer exists, satisfied by the built-in renderer being that consumer). Consumes Inventory Position (D12) as the sole source of incoming-supply data; never re-queries Purchase Orders, receipt tables, or receiving repositories. Relates to INV-7 (aggregation/rollup is a presentation behavior, underlying identity stays retrievable) and INV-8 (a variable parent is a presentation container — Invariant M7-2 below is INV-8 applied to expected-delivery presentation).

**`WC_Inventory_Overview_Expected_Delivery_Result_Interface`** (new, `includes/interface-wc-inventory-overview-expected-delivery-result.php`): the public contract — four `STATE_*` constants (`STATE_IN_STOCK`/`STATE_UNAVAILABLE`/`STATE_EXPECTED_DATE`/`STATE_EXPECTED_SOON`) and five accessors (`api_version()`, `available_now()`, `state()`, `expected_date()`, `confidence()`). Both Service methods, the `wc_io_expected_delivery_text` filter argument, and all consumer documentation are typed against this interface, not the concrete class — an interface freezes only what consumers depend on, leaving room for a second implementation without an `API_VERSION` bump.

**`WC_Inventory_Overview_Expected_Delivery_Result`** (new): the sole `final` implementation — private constructor, static `create()` factory, immutable (no setters, private properties). Deliberately absent, and guard-enforced: `on_hand()`, `incoming()`, `position()`, and anything supplier/PO/quantity/cost/delay-related — widening this Result is how a narrow presentation contract turns into an accidental data-export surface.

**`WC_Inventory_Overview_Expected_Delivery_Resolver`** (new, `@internal`, never called outside the Service — sole-entry-point rule below): pure, deterministic, total selection algorithm. No `$wpdb`, no `WC_Product`/`wc_get_product`, no WordPress option/filter/action functions, no date/clock functions or classes, no `WP_Error`. `$today` is always a caller-supplied parameter (the same clock-as-parameter pattern `WC_Inventory_Overview_PO_Delay` already establishes). Implements **Invariant M7-1** — a customer-safe line's `expected_date` is never in the past, regardless of `is_delayed` — closing a concrete customer-facing defect: `is_delayed` is provably `'0'` for a past-dated line when a PO has auto-transitioned to `partially_received` (the delay predicate only matches `po.status = 'placed'`) or when `wc_io_po_delay_grace_days` is non-zero. M3 tolerated this gap because it only dulled an admin badge; a storefront cannot. M7 defends in its own Resolver rather than editing the frozen `PO_Delay` calculator — **the upstream `partially_received` delay-predicate gap itself remains open, recorded as M8 follow-up** (see Recommended follow-ups below, unchanged from the pre-M7 note).

**`WC_Inventory_Overview_Expected_Delivery_Service`** (new): the **sole public API** (`API_VERSION = 1`, independent of the plugin version). `get_for_product()` is defined as exactly `get_for_products_bulk( array( $product ) )` returning the single element, so single and bulk can never disagree. Implements **Invariant M7-2** — a variable parent may present `STATE_EXPECTED_SOON`, never `STATE_EXPECTED_DATE`; its `expected_date()` is always `null`, derived from whether any child variation has `incoming > 0` in Inventory Position's own bulk result, never from inheriting a child's date. Request-scoped memoization only (a `static array` keyed by item ID — product and variation IDs share the WordPress post-ID space per the Position Service's own documented reliance on this), flushed on the plugin's four existing `wc_io_purchase_order_*` hooks. `on_hand` is always supplied as `0.0` to the Position Service — the Result exposes no quantity and `available_now` comes from `is_in_stock()`, so `on_hand` is genuinely unused, and passing `0.0` sidesteps `WC_Product_Variation::get_manage_stock() === 'parent'` leaking the parent's own quantity onto every variation.

**`WC_Inventory_Overview_Expected_Delivery_Renderer`** (new): the **only** file in `includes/` touching `woocommerce_get_availability` — the storefront-facing hook noted at the top of this document. Seven-step cheapest-first bail ladder (cron → admin-non-AJAX → setting disabled → in-stock/forced-class → empty availability text → `wc_io_storefront_render_expected_delivery` opt-out → `STATE_IN_STOCK`/`STATE_UNAVAILABLE`) so an opt-out never costs a query. Gates on `! is_in_stock()` (a fact) rather than the separately-filterable `class` string, with a deliberate escape hatch: if a third party already forced `class` to `'in-stock'`, the renderer respects it. `$availability['class']` is never modified in any code path — theme CSS keeps working unchanged. `woocommerce_get_variation` runs on `admin-ajax.php` where `is_admin()` is `true`, so the gate is `! ( is_admin() && ! wp_doing_ajax() )`, not a bare `! is_admin()`. Two preload hooks (`woocommerce_before_single_product`, `woocommerce_before_shop_loop`) are pure optimization — correctness never depends on either firing (Invariant M7-3 below).

**One new setting:** `wc_io_expected_delivery_renderer_enabled` (default `'yes'` — a feature that ships off by default ships untested in production), on the existing Settings tab's new **Storefront** section, following the house `OPTION_*`/`is_yes()`/`normalize_yes_no()` radio-Yes/No pattern exactly. No new admin page.

**Two extension filters, no more:** `wc_io_storefront_render_expected_delivery( bool $render, WC_Product $product )` (generic per-render opt-out, checked before any Position lookup) and `wc_io_expected_delivery_text( string $text, Result_Interface $result, WC_Product $product )` (copy override). Three candidates from earlier design direction were cut: `wc_io_availability_html` (redundant with the opt-out), `wc_io_expected_delivery_rendered` (a notification hook with no consumer, D16), and any structured-data hook (out of scope — see below).

**Structured data (`woocommerce_structured_data_product`) is deliberately not implemented.** The `offers` key is not guaranteed to exist when the filter fires (a zero-priced product with a review can reach it with no `offers` key at all), `@type` is not reliably discriminated by product type, `availabilityStarts` is undocumented by Google for Product/merchant listings, and Rank Math already emits a competing Product entity on this install. Shipping it would be a D16 violation for zero measurable benefit; purely additive later, under its own plan, if a concrete consumer ever appears.

**Sole-entry-point rule (extends the D3/INV-2 and D12 sole-owner entries above to presentation):** only `Expected_Delivery_Service` may call `Expected_Delivery_Resolver`. Any future REST route, Store API extension, GraphQL resolver, Blocks integration, or headless endpoint must delegate to the Service — never call the Resolver directly, and never re-derive the answer from Inventory Position or PO data. Guard-enforced now, before a second consumer exists, rather than left as prose for the first REST/Blocks integration to (possibly) violate.

**Storefront ownership:** this plugin now owns customer-facing expected-delivery rendering — see `docs/adr/0003-storefront-expected-delivery-ownership.md` for the full rationale (the previously-intended external consumer, sibling plugin "Biopentra Storefront," was verified to be an empty directory with nothing filtering `woocommerce_get_availability`).

**Architecture guards:** `tests/unit/expected-delivery/test-expected-delivery-architecture.php` — the set of `includes/` files referencing `Inventory_Position_Service::` is exactly `{ class-wc-inventory-overview-list-table.php, class-wc-inventory-overview-expected-delivery-service.php }` (D12 extended); the only file referencing `Expected_Delivery_Resolver::` is the Service (sole-entry-point rule); the Resolver contains none of `$wpdb`/`wc_get_product`/`WC_Product`/`get_option`/`update_option`/`apply_filters`/`add_filter`/`do_action`/`current_time`/`date_i18n`/a bare `date()`or `gmdate()` call/`time()`/`mktime`/`strtotime`/`DateTime`/`DateTimeImmutable`/`WP_Error`/`is_wp_error`/`__(`/`esc_html`; the Renderer contains no `$wpdb`, no PO domain class, no `Inventory_Position_Service` reference; `Result` is `final`, implements the interface, and its public method set is exactly the five accessors plus `create()` (sorted-set equality, both directions); neither `Result` nor the interface contains the forbidden accessor names `on_hand`/`incoming`/`position`/`supplier`/`po_number`/`po_id`/`outstanding`/`is_delayed`; the Renderer is the only file containing `woocommerce_get_availability` or registering a `woocommerce_*` filter; no M7 file contains `remove_filter(` or a `class_exists()`/`function_exists()` check against a non-WooCommerce third-party symbol; no M7 file contains `set_stock_quantity`/`update_post_meta`/`->insert(`/`->update(`/`->delete(`/`qty_received` (zero mutation, whole surface).

**Testing:** `tests/unit/expected-delivery/` (Result/Result_Interface contract, Resolver table-driven algorithm coverage including Invariant M7-1's three scenarios, architecture guards), `tests/integration/expected-delivery/` (Service — single/bulk consistency, Invariant M7-2, same-instance memoization, memo-flush-on-place, negative/unmanaged stock, deleted product ID; Renderer — the full bail ladder, both filters, ISO-week year boundary; settings — default/round-trip; performance — Invariant M7-3's equality-based query-scaling acceptance criterion, 20 vs 40 mixed products issuing the same SELECT count). `Test_WC_IO_Expected_Delivery_` is registered in `tests/docker/run-phpunit.sh`'s blocking filter.

---

## Milestone M8 — Hardening & GA

**Status:** Complete, v1.25.0. **Schema unchanged (v10), zero new tables, zero new columns.** Not a feature milestone — a hardening/cleanup/conformance pass over the M0–M7 platform. Zero new domain concepts, zero new public API surface.

**Scope:** four independently-justified fixes, all cited from concrete, pre-existing commitments the project made to itself (M6's deprecated-code retirement governance rule, M5's documented `PO_Delay` gap, M7's explicitly-deferred conformance-audit item, and `docs/testing.md`'s own named test-content backlog) plus CI hardening and a GA-readiness confirmation pass. No item was speculative; every item was evaluated against the repository first and only included once independently verified.

**Batch Intake physical removal:** `WC_Inventory_Overview_Batch_Intake_Service`'s five `@deprecated` methods (`build_preview_from_post()`, `apply_batch_from_post()`, `rollback_batch_apply()`, `build_movement_note_for_line()`, `render_preview_markup()`) and the private helpers that existed only to serve them (`parse_batch_fx()`, `allowed_batch_currencies()`, `parse_header()`, `parse_product_lines()`, `parse_landed_costs()`, `format_product_line_label()`, `format_amount_currency()`) are deleted — the class now holds only `landed_cost_type_labels()`/`allowed_cost_types()`, the live delegation shims to `WC_Inventory_Overview_Landed_Cost_Types` still exercised by the M6 extraction characterization test. `WC_Inventory_Overview_Batch_Intake_UI` (whose only method, `render_panel()`, had zero remaining callers once the above were removed) is deleted entirely. `WC_Inventory_Overview_Plugin::ajax_batch_preview()`/`handle_batch_apply_post()`, the now-unreachable `RESTOCK_VIEW_BATCH` constant, and the `wc_io_batch_msg`/`_err`/`_id` admin-notice block (which read query args only that redirect ever set) are removed. **Prerequisite, done first:** `tests/includes/test-case.php`'s `create_legacy_batch()` fixture builder — the one remaining caller of the apply path, used 47 times across the M6 batch-migration suite — was rewritten to build the same batch header/lines/costs rows and stock/cost mutation directly (via `Restock_Service::apply_purchase_line_change()`, the same live mutator the deprecated method called internally), verified green before any production code was touched. Legacy `wc_io_purchase_batches*` tables and data are untouched (D14, frozen forever) — this removes code, not history. `tests/integration/batch-intake/test-batch-intake-characterization.php` (2 tests, both permanently skipped by a pre-existing wrong-class-name guard, both calling the now-deleted methods) is retired. One M4 architecture guard (`test_only_service_calls_restock_mutation_methods`) explicitly whitelisted `Batch_Intake_Service` as `apply_purchase_line_change()`'s second, pre-existing caller — narrowed to a literal single-caller assertion now that the exception no longer exists.

**`PO_Delay` `partially_received` gap closed:** both `is_line_delayed()` (PHP) and `sql_line_delayed_predicate()` (SQL) gated delayed-detection on `status = 'placed'` only; a partially-received PO's remaining outstanding could be genuinely overdue and never flagged — the admin-visible root cause M7's storefront Resolver defended against independently (Invariant M7-1) without fixing. Extended to accept `'placed'` or `'partially_received'`, mirroring the M5 precedent already applied to `query_open_lines()`. Deliberately not extended to `'received'` — a fully received line always has `outstanding = 0` (INV-4), so the existing `outstanding > 0` check already excludes it regardless of status. New cases added to the shared table-driven `delay_cases()` fixture exercised by both the pure-PHP truth-table test and the PHP/SQL equivalence integration test.

**Repo-wide sibling-plugin-coupling conformance guard:** `tests/unit/conformance/test-no-sibling-plugin-coupling.php` — the guard M7 explicitly deferred ("codebase hygiene belonging to M8's conformance audit"). Three assertions across the entire `includes/` tree: every `class_exists()`/`function_exists()` symbol is on a closed WordPress/WooCommerce/PHP-core allowlist; zero `remove_filter()`/`remove_action()` calls anywhere; zero hardcoded identifiers for either named sibling plugin (Biopentra Storefront per ADR-0003, MP Commerce Fulfillment per `docs/OWNERSHIP.md`). Passes cleanly with zero violations found, mechanically confirming ADR-0003's audit claim instead of leaving it as prose.

**Golden/characterization test-content repair:** all 11 remaining pre-existing bugs in `docs/testing.md`'s "Known test-content issues" table (FX: wrong seed column name, stale bare-float return-shape assumption; Movements: stale positional-argument signature and `qty_change`/`quantity_change` column-name mismatch; Costing: wrong unprefixed class name in every `class_exists()` guard plus a stale 4-argument signature; Cost Adjustment: same stale-signature bug) fixed as test-code corrections verified against current, unmodified production behavior — zero production code changed, per the M0.14 golden-fixture governance rule. The integration suite is now clean for the first time (245 tests / 834 assertions, 0 errors, 0 failures) and `tests.yml`'s `continue-on-error: true` exception is removed — the integration step is a normal blocking CI gate like unit and the M1–M8-focused suite, closing `docs/testing.md`'s own named unblock condition.

**CI pipeline hardening:** `Test_WC_IO_Close_Short_With_Qty_Received` (an M5 audit-remediation test that matched no blocking-filter alternative) added to `run-phpunit.sh`'s filter; the one live PHP 8.4 deprecation notice in the codebase (`Suppliers::validate()`'s implicitly-nullable parameter) fixed; `ci.yml`/`release.yml` aligned to PHP 8.4, the version `tests.yml` already exercised (previously 8.2, validating against a version nothing else in the pipeline tested).

**GA-scale performance confirmation:** the existing query-scaling guards for Inventory Position (D12) and Expected Delivery (Invariant M7-3) — proven at ~20–40 items — re-run once more at 200 mixed simple/variable items using the identical technique (bounded-count / equality assertions), confirming both hold at a size closer to a real catalog. Confirmatory only — no caching or optimization work, explicitly out of M8's scope.

**Public API conformance review:** `Inventory_Position_Service` and `Expected_Delivery_Service` re-read against their documented contracts. Found and corrected two real PHPDoc inaccuracies in `docs/ARCHITECTURE_BASELINE_v1.24.0.md` (introduced when that document was first authored): `get_position()`/`get_positions_bulk()`'s actual signatures take caller-supplied On Hand and two separate product/variation ID-keyed maps, not the single combined list previously documented; `get_for_product()` accepts `WC_Product|int`, not `WC_Product` only. Zero production code changed. Confirmed by direct grep: zero call sites anywhere branch on `Result::api_version()`.

**Explicitly excluded from M8 (evaluated, not justified for a hardening milestone):** splitting the `class-wc-inventory-overview-plugin.php` "god class" into tab controllers — real, documented tech debt with a named remediation direction, but a large, invasive, whole-admin-surface refactor is the opposite of hardening under GA time pressure; recorded here as a deliberate post-1.0 deferral, not a silent drop. PHPCS-clean/baseline wiring (~559 pre-existing errors/634 warnings — disproportionate to a hardening pass). Retiring the legacy `docker-compose.test.yml` harness (already correctly documented as manual-only). Pinning `WC_VERSION` in `run-phpunit.sh` (no concrete failure on record).

**Architecture guards:** all seven guard files (the six pre-existing per-milestone guards plus this milestone's own repo-wide conformance guard) pass — 64 tests / 818 assertions, 0 failures.

**Testing:** `tests/includes/test-case.php` (fixture rewrite), `tests/unit/conformance/` (new), `tests/unit/purchase-orders/test-po-delay.php` (extended), `tests/unit/goods-receipt/test-goods-receipt-architecture.php` (guard narrowed), `tests/integration/exchange-rates/`, `tests/integration/movements/`, `tests/integration/costing/`, `tests/integration/cost-adjustment/` (all repaired), `tests/integration/expected-delivery/test-expected-delivery-performance.php` and `tests/integration/inventory-position/test-inventory-position-service.php` (GA-scale additions). Final counts: unit suite 216 tests / 1456 assertions (0 failures, 7 pre-existing risky `Test_DB_Transaction` tests); integration suite 245 tests / 834 assertions (0 errors, 0 failures — clean, now CI-blocking); M1–M8-focused suite 450 tests / 2247 assertions (0 failures).

**GA readiness statement:** with M8 complete, `DB_VERSION` unchanged at 10, all seven architecture guards passing, the full test suite (unit + M1–M8-focused + integration) green with the integration suite now a normal blocking CI gate for the first time, and every prior milestone's validation-checklist item confirmed unaffected, this platform (M0–M8) is considered **production-finished and Version 1.0 / GA ready**.

---

## Milestone M9 — Supplier Observed Lead-Time Statistics

**Status:** Complete, v1.26.0. **Schema unchanged (v10), zero new tables, zero new columns.** The first post-GA milestone: a product-evolution addition, not a hardening pass, but scoped deliberately narrow — one read-only reporting feature filling an already-reserved architectural slot (D8's "observed lead-time statistics are a designed-for-later capability"). Zero new public API surface (the new service is Internal, not Public — no concrete external consumer exists yet, D16).

**Scope:** compute, per supplier, from posted (non-voided, non-migrated) Goods Receipts linked to that supplier's fully-`received` Purchase Orders: average/fastest/slowest delivery days and completed-order sample count. Display read-only on the Supplier admin screen, alongside the existing merchant-editable `default_lead_time_days` configured fallback. Named explicitly in `docs/admin-guide-suppliers.md`'s "Not Yet Available" backlog before this milestone — not a speculative addition.

**`WC_Inventory_Overview_Supplier_Lead_Time_Service`:** new sole-owner boundary (`get_stats_for_supplier()`/`get_stats_bulk()`, single defined as bulk-of-one, same discipline as Inventory Position/Expected Delivery). One grouped aggregate SQL query per call — `DATEDIFF()` between each qualifying PO's `placed_at` and the *latest* linked receipt's `posted_at` (never the first partial receipt, never `MAX(id)`/insertion order), `AVG()`/`MIN()`/`MAX()`/`COUNT()` grouped by `supplier_id`. Observation qualification: `po.status = 'received'`, `po.placed_at IS NOT NULL`, at least one posted (`gr.status = 'posted'`), non-migrated (`gr.source != 'migrated'`) receipt line referencing one of the PO's lines. Migrated (M6) receipts are excluded both naturally (their lines never carry a `po_line_id`, so the join alone excludes them) and explicitly (belt-and-suspenders `gr.source` check). No statistic is ever persisted — every call recomputes from current Purchase Order / Goods Receipt / Receipt Line data, the platform's established "derived, not stored" pattern (D11, D12).

**Admin UI:** a new read-only "Observed Lead Time" panel on the Supplier edit screen (`class-wc-inventory-overview-purchasing-page.php`), directly beneath the existing "Default Lead Time (days)" field. Shows average (rounded to the nearest whole calendar day for display only — the service's internal return value keeps full float precision), fastest, slowest, and a "Based on N completed orders" count, or an explicit "not enough data yet" state below 2 completed orders (`Supplier_Lead_Time_Service::MINIMUM_SAMPLE_COUNT_FOR_DISPLAY`). Renders unchanged for archived suppliers — archiving never removes historical Purchase Order / Goods Receipt evidence.

**A real bug found and fixed by the test suite itself:** the initial implementation validated supplier IDs with `absint()`, which takes the *absolute value* of a negative number rather than rejecting it — `get_stats_bulk([-1])` would have silently looked up supplier `1` instead of correctly filtering out an invalid ID. Caught by a unit test asserting invalid IDs return an empty result; fixed by switching to an explicit `(int)` cast plus `> 0` check.

**Insertion-order independence:** a dedicated integration test constructs a Purchase Order fulfilled by two receipts inserted in the *reverse* of their `posted_at` order (the earlier-inserted, lower-ID receipt forced to the *later* date; the later-inserted, higher-ID receipt forced to the *earlier* date) and confirms the service still selects `MAX(posted_at)`, never `MAX(id)`/last-inserted — guarding specifically against an `ORDER BY id DESC LIMIT 1` implementation shortcut that would pass every other fixture (where insertion order and `posted_at` order naturally coincide) while silently violating the "latest posted receipt" rule.

**Performance:** one query regardless of scale, proven at 10, 40, and 200 suppliers (GA-scale point, same precedent M8 established for Inventory Position/Expected Delivery) via the same equality-based query-count technique already proven for those two services.

**Explicitly excluded from M9 (deliberate non-goals, not oversights):** wiring observed lead time into PO-creation expected-date suggestions (D9's existing configured-lead-time suggestion is untouched); Inventory Position's Coverage/Forecast fields (D11 — needs sales-velocity data this plugin doesn't have yet, a materially larger initiative); a public API/REST endpoint (no concrete consumer exists, D16 — but the service may be promoted later without changing the computation it wraps); the supplier merge tool and supplier analytics/reliability scoring (named in the same backlog entry but deliberately left for their own future milestones — reliability scoring is a plausible future *consumer* of this milestone's data, not part of it); a list-table (bulk, every-supplier-at-once) column, kept to the single-supplier detail screen for this first slice; a median statistic (average/fastest/slowest/count is the complete, deliberately final set for this milestone).

**Architecture guards:** `tests/unit/supplier-lead-time/test-supplier-lead-time-architecture.php` — sole-owner computation-signature scan (`DATEDIFF(`/`observed_days`, not the returned array-key names), zero-write token scan, `$wpdb->get_results()`-only check, sole-caller allowlist (only `class-wc-inventory-overview-purchasing-page.php`), bulk-first delegation check for `get_stats_for_supplier()`.

**Testing:** `tests/unit/supplier-lead-time/` (architecture guard, pure input-shape edge cases), `tests/integration/supplier-lead-time/` (avg/fastest/slowest/count correctness, every exclusion rule, "latest qualifying receipt" selection, insertion-order independence, bulk/single consistency, archived-supplier behavior, 10/40/200-supplier performance). 26 new tests: unit suite 226 tests / 1486 assertions (0 failures, same 7 pre-existing risky `Test_DB_Transaction` tests); integration suite 261 tests / 930 assertions (0 errors, 0 failures); M1–M9-focused suite 476 tests / 2373 assertions (0 failures).

---

## Milestone M10 — Purchase Order Expected-Date Suggestion

**Status:** Complete, v1.27.0. **Schema unchanged (v10), zero new tables, zero new columns.** First milestone of the "feature train" model (`docs/process/milestone-lifecycle.md`, adopted after M9): implemented, and frozen after a lightweight completion review (WP4) rather than a full independent audit, but **intentionally not released individually** — batched with M9 into one future combined release. Zero new public API surface (Internal, D16).

**Scope:** on the **new** Purchase Order creation screen only, pre-fill Expected Date and Confidence from a priority-ordered suggestion: usable observed lead time (M9) → configured `default_lead_time_days` → no suggestion. Calendar days only. Confidence is always `estimated` when a suggestion exists, never `exact`. Always overridable; never runs on the edit-PO screen.

**A discovered documentation/implementation gap, corrected by this milestone:** `docs/admin-guide-suppliers.md` (and `CLAUDE.md` D8) had claimed since before M9 that creating a new PO "suggests" the configured lead time — this was verified false at M10 planning time (`class-wc-inventory-overview-po-admin.php`'s Expected Date field had zero auto-suggestion logic of any kind). M9's own WP6 documentation pass carried this claim forward unverified. M10 is the first milestone to actually build the behavior the documentation had been describing, closing the gap rather than perpetuating it.

**`WC_Inventory_Overview_Expected_Date_Suggestion_Service`:** new sole-owner boundary for the *recommendation policy* (`get_suggestion_for_supplier()`/`get_suggestions_bulk()`, single defined as bulk-of-one). Deliberately kept a separate class from `Supplier_Lead_Time_Service` — the M9 service owns *statistics* (what happened), this one owns *policy* (what to suggest given the statistics plus the configured fallback) — the same "one sole owner per computed concept" discipline as Inventory Position vs. Expected Delivery. Never queries `$wpdb` directly at all; its only data access is one delegated call to `Supplier_Lead_Time_Service::get_stats_bulk()` plus reading already-loaded supplier rows passed in by its caller.

**`Supplier_Lead_Time_Service::is_observed_value_usable()`:** one small, additive, backward-compatible predicate method added to M9's service, so M10 never independently knows or duplicates the `MINIMUM_SAMPLE_COUNT_FOR_DISPLAY` threshold — that decision stays owned by M9. M9's own architecture guard test's sole-caller allowlist was deliberately extended to include the new service (its docstring had explicitly anticipated exactly this). No existing M9 method's behavior changed; `docs/milestones/m9-implementation-plan.md` itself was not touched.

**Admin UI wiring:** no AJAX, no new endpoint. `class-wc-inventory-overview-po-admin.php`'s `enqueue_assets()` computes suggestions for every active supplier in one bulk pass, gated by an explicit `action=new` check (not merely "does the field exist" — an existing `draft` PO is also editable and renders identical field IDs, so a naive DOM-presence check would have risked clearing an existing PO's stored date when its supplier was changed; this was caught and fixed before any test/manual pass), and adds them to the existing `wp_localize_script('wc-io-po-admin', 'wcIoPoAdmin', …)` payload alongside an explicit `isNewPurchaseOrder` boolean the client-side code gates on.

**Client-side behavior (`assets/po-admin.js`):** on supplier change, pre-fills Expected Date (`order_date` or today, plus N calendar days) and sets Confidence to `estimated`, unless the operator has already manually edited either field this page session — a permanent, one-way switch (INV-M10-1), never reset, never re-enabled by further supplier changes. Switching to a supplier with no suggestion clears a still-untouched prior auto-fill rather than leaving it stale.

**Architecture guards:** `tests/unit/expected-date-suggestion/test-expected-date-suggestion-architecture.php` — sole-owner policy-signature scan (`is_observed_value_usable(`), a check that M9's own computation tokens (`DATEDIFF(`/`observed_days`) never appear in this service (proving it never duplicates the statistic itself), zero-write scan, zero-direct-`$wpdb`-usage scan, no-public-API-surface scan, sole-caller allowlist (only `class-wc-inventory-overview-po-admin.php`), bulk-first delegation check. Plus one small, deliberate update to M9's own guard test's allowlist.

**Testing:** `tests/unit/expected-date-suggestion/` (architecture guard, pure resolution-rule/input-shape tests, plus one M9 regression test for `is_observed_value_usable()`), `tests/integration/expected-date-suggestion/` (real observed suggestion end-to-end and winning over configured, configured fallback, no-suggestion state, bulk path with per-supplier independence, an explicit regression check that `Supplier_Lead_Time_Service`'s own output is byte-for-byte unchanged by this milestone, 10/40/200-supplier performance with an explicit per-scale query count of 1). 26 new tests: unit suite 244 tests / 1535 assertions (0 failures, same 7 pre-existing risky `Test_DB_Transaction` tests); integration suite 269 tests / 983 assertions (0 errors, 0 failures); M1–M10-focused suite 502 tests / 2475 assertions (0 failures).

**Explicitly excluded from M10 (deliberate non-goals, not oversights):** editing an existing PO (suggestion never fires there); per-line expected-date override suggestions (header-level only, first slice); any change to `Expected_Delivery_Resolver`/the storefront renderer; business-day arithmetic (calendar days only, matching M9's own semantics); persisting the suggestion or its source anywhere; any consumer besides PO creation (Quick Restock, a supplier dashboard, and a reorder assistant are named as plausible future consumers of the now-generic service, none built here); supplier reliability scoring, spend analysis, order-history reporting.

---

## Milestone M11 — Supplier On-Time Delivery Rate

**Status:** Complete, v1.28.0. **Schema unchanged (v10), zero new tables, zero new columns.** Second milestone frozen after a lightweight completion review (WP4) rather than a full independent audit, **intentionally not released individually** — batched with M9 and M10 into one future combined release. Zero new public API surface (Internal, D16).

**Scope:** a single new read-only statistic, **on-time delivery rate**, on the Supplier detail admin screen — the fraction of a supplier's completed orders that had a known expected date (Exact or Estimated confidence) and were fully received on or before `expected_date + grace_days` (the same grace period `PO_Delay` already applies to live delay-flagging). Header-level granularity only, matching M9/M10's own precedent.

**Deadline-ownership redesign (the central architectural decision of this milestone):** the original draft plan proposed extending `PO_Delay` with a SQL fragment shaped for `Supplier_Lead_Time_Service`'s aggregate query — rejected during plan review as the start of `PO_Delay` becoming a general SQL-expression provider for whichever service asks next, and as unwanted cross-service coupling. Resolved instead by introducing **`WC_Inventory_Overview_Expected_Deadline`**: a new, deliberately narrow, pure, stateless class owning exactly one atomic rule — "given an expected date, confidence, and grace-day count, what is the deadline, and is one knowable at all" — as four methods (`has_known_date()`, `deadline()`, `sql_deadline_expression()`, `sql_has_known_date_expression()`), guard-enforced closed to exactly that surface. Both `PO_Delay` (refactored internally) and `Supplier_Lead_Time_Service` (new usage) consume this one shared primitive; neither depends on the other (INV-M11-2).

**`PO_Delay` internal refactor:** `is_line_delayed()`'s inline eligibility checks and deadline computation, and `sql_line_delayed_predicate()`'s inline `DATE_ADD(...)`/eligibility SQL fragments, now compose `Expected_Deadline`'s methods instead of inlining the formula themselves. `add_days()` becomes a one-line delegate (kept public for backward compatibility). **Zero change to any public method's signature or behavior** — proven by re-running `PO_Delay`'s complete pre-existing unit test suite, including its PHP/SQL equivalence test, unmodified and green.

**`Supplier_Lead_Time_Service` extension:** its single grouped aggregate query (unchanged in count — still exactly one, per the zero-additional-query performance requirement) is widened to also select `po.expected_date`/`po.expected_confidence` and compute `on_time_count`/`rated_order_count`, composing `Expected_Deadline`'s SQL micro-fragments. `get_stats_bulk()`/`get_stats_for_supplier()` gain a backward-compatible optional `$grace_days` parameter (default `0`); M10's `Expected_Date_Suggestion_Service` call sites are unaffected (regression-tested). New `is_on_time_rate_usable()` mirrors M10's `is_observed_value_usable()`, gated on the independently-sized `rated_order_count` denominator (an unknown-confidence completed order contributes to `sample_count` but never to `rated_order_count`, INV-M11-1).

**Admin UI:** one new row in the existing "Observed Lead Time" `<table class="form-table">` panel on the Supplier detail screen (`Purchasing_Page::render_observed_lead_time()`), sourcing `grace_days` from `PO_Delay::grace_days_from_option()`. No new screen, no new capability.

**Architecture guards:** `tests/unit/expected-deadline/test-expected-deadline-architecture.php` — purity (zero `$wpdb`, zero writes, zero WordPress option access), closed-surface check (exactly the four approved methods via reflection), sole-caller allowlist (`PO_Delay` and `Supplier_Lead_Time_Service` only). `Supplier_Lead_Time_Service`'s own existing guard test continues to pass against the widened (still single) query with no new caller boundary.

**Testing:** unit (`tests/unit/expected-deadline/`: pure predicate/SQL-fragment tests; extended `tests/unit/supplier-lead-time/test-supplier-lead-time-service.php`: new fields, `is_on_time_rate_usable()`, backward-compatible `$grace_days` default); integration (`tests/integration/supplier-lead-time/test-supplier-on-time-rate-observations.php`: before/at/after-deadline inclusive boundary, EXACT/ESTIMATED eligibility, UNKNOWN-confidence and missing-date exclusion, `grace_days` 0 and >0, independent multi-supplier resolution, `rated_order_count` smaller than `sample_count`, archived-supplier retention, and explicit regression checks that M9's statistics, M10's suggestion service, and `PO_Delay`'s live result are all unaffected); performance (extends M9's own suite with an explicit zero-additional-query regression assertion at 10/40/200-supplier scale, per the corrected requirement that M11 must add **zero** additional queries, not merely "still low").

**Explicitly excluded from M11 (deliberate non-goals, not oversights):** spend analysis and order-history reporting (the other two thirds of `docs/admin-guide-suppliers.md`'s original compound "Supplier analytics" bullet, still open); the supplier merge tool; per-line reliability; surfacing on the Suppliers list table; a dedicated on-time-rate grace-days setting distinct from the existing delay grace-days option; any change to `Expected_Date_Suggestion_Service`'s (M10) resolution rule; any storefront, REST, AJAX, or capability change.

---

## Milestone M12: Supplier List Performance Surface (v1.29.0)

**Scope:** Presentation-only. Add read-only **Observed Lead Time** and **On-Time Rate** columns to the Purchasing → Suppliers list table so merchants can compare suppliers without opening each detail screen. Reuses `Supplier_Lead_Time_Service::get_stats_bulk()` and the same usability predicates as the detail panel (`is_observed_value_usable` / `is_on_time_rate_usable`). Zero schema change (`DB_VERSION` stays 10), zero mutation, zero new public API, zero new statistics engine.

**UI:** `WC_Inventory_Overview_Suppliers_List_Table` column order becomes Name → Currency → Lead Time (configured, unchanged) → Observed Lead Time → On-Time Rate. New columns are non-sortable. Insufficient data shows an em dash (—). Grace days come from `PO_Delay::grace_days_from_option()`.

**Data access:** Exactly one `get_stats_bulk()` call during `prepare_items()` for the current page's supplier IDs. Empty page → no stats work. No per-row `get_stats_for_supplier()`. No direct PO/GR SQL in the list-table class.

**Architecture guards:** INV-M12-1 (list must not duplicate lead-time/on-time computation or deadline SQL); INV-M12-2 (one bulk call per prepare; no N+1). Lead Time service allowlist deliberately extended to `class-wc-inventory-overview-suppliers-list-table.php`. Query invariant: the existing service contract guarantees **exactly one** `observed_days` SQL statement per non-empty `get_stats_bulk()` call (asserted at 10/40/200 page sizes); empty ID list → zero stats SQL.

**Testing:** `tests/unit/suppliers/test-suppliers-list-performance.php`, `tests/integration/suppliers/test-suppliers-list-performance.php`, `tests/integration/suppliers/test-suppliers-list-performance-queries.php`; architecture coverage in `tests/unit/supplier-lead-time/test-supplier-lead-time-architecture.php`.

**Explicitly excluded from M12:** spend analysis, order-history reporting, supplier merge, grace-days Settings UI, expected-date suggestion source UI, Inventory Position supplier column, storefront Expected Delivery confidence changes, printable PO, Coverage/Forecast, warehouse locations, Plugin god-class refactor, unrelated PHPCS cleanup. Released together with M9–M11 as the M9–M12 feature train, `v1.29.0`.

---

## Milestone M13 — Printable Purchase Order (1.30.0, released as v1.32.0)

**Status:** Complete, development version `1.30.0`. **Schema unchanged (v10), zero new tables, zero new columns.** First milestone of the feature train opened after the M9–M12 train released as `v1.29.0`. Frozen with a Level A completion review (see `docs/checklists/m13-release-readiness.md`); released bundled with M14/M15 as tag `v1.32.0`. Zero new public API surface (Internal, D16); zero new capability; zero new public hook.

**Scope:** a read-only, standalone HTML printable view of a single Purchase Order, reachable from the existing PO detail screen for any PO in a printable status. Architecturally reserved since Architecture v1.0 (`CLAUDE.md` D17, §11.2 — "printable-PO reserved capability") but never built until now.

**New presentation-only class:** `WC_Inventory_Overview_PO_Print_Renderer` (`includes/class-wc-inventory-overview-po-print-renderer.php`) takes an already-composed plain array and formats/escapes it into a standalone HTML document — zero `$wpdb`, zero calls into `Purchase_Orders`/`Purchase_Order_Lines`/`Suppliers`/any repository class, zero product lookup, zero authorization/lifecycle logic (INV-M13-2). Its only computation is trivial display arithmetic: `qty_ordered * unit_cost` per line, and the plain sum of those for the PO total.

**`PO_Admin` composition:** new `handle_print()`, registered as `admin_post_wc_io_po_print` via the existing action-map pattern in `init()`. Strict order (INV-M13-4): `VIEW_PO` capability → PO-and-action-scoped nonce (`wc_io_po_print_<id>`) → PO id validation → PO read (`Purchase_Orders::get()`) → existence check → printable-status check → line read (`Purchase_Order_Lines::list_for_po()`) → supplier read (`Suppliers::get()`) → render-model composition → renderer output. No PO/line/supplier data is read before both capability and nonce pass — verified by source position in the architecture guard, not just presence.

**Printable statuses:** `placed`, `partially_received`, `received`, `cancelled`, `closed_short`. **Not printable:** `draft` (never placed/sent; still fluid, no commitment behind it) — enforced both by omitting the "Print" link on the detail screen and by the handler's own server-side status check.

**Resilience to deleted/unresolvable references:** product/variation identity on the printed document always comes from the PO line's own historical `name_snapshot`/`sku_snapshot` columns — the same columns `PO_Admin::render_line_row()` already uses for the existing detail screen — never a live `wc_get_product()` lookup, so a since-deleted product/variation cannot break printing. Supplier name always falls back to the PO header's own `supplier_name_snapshot`; contact/reference fields (`email`/`phone`/`supplier_reference`) are populated only when `Suppliers::get()` still resolves and are simply omitted, never a failed render, when it does not.

**Document content:** store name (`get_bloginfo('name')`), PO number, status label (`PO_Statuses::label()`), order date, expected date/confidence, currency; supplier name/reference/email/phone; per-line product/SKU/supplier-SKU/qty-ordered/qty-received/unit-price/line-total; PO total. No tax/shipping/discount fields (none exist in the PO domain). Money formatted as `number_format(..., 2)` plus a bare currency code — never `wc_price()`, which would format in the store's base currency rather than the PO's own supplier currency.

**Print UX:** a screen-only "Print" button calling `window.print()`, hidden from print output via `@media print`; the page remains fully printable via the browser's native Print command with JavaScript disabled. Browser print → Save as PDF is the entire PDF mechanism — no PDF library, no generated/stored file, no email/attachment feature.

**Architecture guards:** `tests/unit/po-print/test-po-print-architecture.php` — renderer has zero repository/product-lookup tokens, zero write tokens, zero authorization/lifecycle tokens; only `PO_Admin` calls the renderer (sole-consumer allowlist); the handler uses only the three approved read owners plus `PO_Statuses::label()`; capability and nonce checks textually precede the first repository read; the printable-status set is locked to exactly the five non-draft statuses.

**Testing:** unit (`tests/unit/po-print/test-po-print-renderer.php`: every approved field renders, line/PO total arithmetic, em-dash fallback, unresolvable-supplier fallback, snapshot-based line identity, HTML escaping of injected markup, money formatting, print-button/`@media print` contract, standalone document, no external resources); handler/security matrix (`tests/unit/po-print/test-po-print-admin.php`: every printable status succeeds; draft denied; missing/invalid/wrongly-scoped nonce denied with nothing rendered; unauthorized user denied; nonexistent PO denied; a deleted product line still prints via its snapshot; an unresolvable (hard-deleted) supplier still prints via the header snapshot with contact fields omitted).

**Explicitly excluded from M13:** spend analysis, order-history reporting, supplier merge, grace-days Settings UI, expected-date suggestion source UI, Inventory Position supplier column, storefront Expected Delivery confidence changes, printable Goods Receipt, Coverage/Forecast, warehouse locations, Plugin god-class refactor, unrelated PHPCS cleanup, any PDF-generation dependency, any new public hook/API/capability. Next process step: planning M14, or closing this new train, only with explicit approval.

---

## Milestone M14 — Supplier Order History (1.31.0, released as v1.32.0)

**Status:** Complete, development version `1.31.0`. **Schema unchanged (v10), zero new tables, zero new columns.** Second milestone of the same feature train M13 opened after the M9–M12 train released as `v1.29.0`. Frozen with a Level A completion review (see `docs/checklists/m14-release-readiness.md`); released bundled with M13/M15 as tag `v1.32.0`. Zero new public API surface (Internal, D16); zero new capability; zero new public hook.

**Scope:** a read-only, paginated list of every Purchase Order for a supplier — every status included — on the existing Supplier detail admin screen, below the Observed Lead Time panel. Closes the longest-standing named gap in `docs/admin-guide-suppliers.md`'s "Not Yet Available" list (order-history reporting, named since M9).

**New Internal sole-owner class:** `WC_Inventory_Overview_Supplier_Order_History_Service` (`includes/class-wc-inventory-overview-supplier-order-history-service.php`) composes one page of a supplier's order history — `get_page( $supplier_id, $page, $per_page )` — exclusively through `Purchase_Orders::count()`/`list()`/`values_bulk()` (INV-M14-3); never `$wpdb` directly, never `Purchase_Order_Lines`/`Goods_Receipts`/`Receipt_Lines`/`Receipt_Costs`/`Suppliers` directly. Zero mutation (INV-M14-1). Every PO status appears — `draft`, `placed`, `partially_received`, `received`, `cancelled`, `closed_short` (INV-M14-4) — unlike M13's print feature, which deliberately excludes `draft`.

**New additive read method:** `WC_Inventory_Overview_Purchase_Orders::values_bulk( array $po_ids )` — one grouped `SUM(qty_ordered*unit_cost)`/`SUM(qty_received*unit_cost)` query over the given page's PO ids, keyed by `po_id`. Never sums across POs (INV-M14-2); no new table.

**Presentation:** new private `render_order_history_section( $supplier_id )` on `WC_Inventory_Overview_Purchasing_Page`, called from `render_supplier_detail()` after `render_observed_lead_time()`. Dedicated `wc_io_supplier_order_history_page` pagination parameter (never the generic `paged`); default page size 20; reuses the existing `manage_woocommerce` gate — no new capability.

**Value semantics:** Ordered Value / Received Value (PO Cost) are PO-line cost only (`qty × unit_cost`, that PO's own currency) — never landed cost (`Receipt_Costs`), never the weighted-average inventory-value figure Goods Receipt posting maintains, never converted or totaled across orders (INV-M14-2). Column labels ("Ordered Value" / "Received Value (PO Cost)") are deliberately chosen to avoid any landed-cost/valuation implication.

**Accepted limitation (not a defect):** the stated `order_date DESC, id DESC` tie-break is not fully enforced — `Purchase_Orders::list()` accepts only a single `ORDER BY` column, its existing, unmodified contract. Ties on identical `order_date` fall back to that pre-existing, non-guaranteed ordering (see `CHANGELOG.md` 1.31.0 entry and `docs/checklists/m14-release-readiness.md`).

**Architecture guards:** `tests/unit/supplier-order-history/test-supplier-order-history-architecture.php` — zero unapproved read tokens in the service; service uses only `Purchase_Orders::count()`/`list()`/`values_bulk()`; zero write tokens in both the service and `values_bulk()`'s own method body; sole-consumer allowlist (only `Purchasing_Page` may call the service).

**Testing:** unit (pagination math, status inclusion, empty/out-of-range pages, `values_bulk()` formula/edge cases, query-count contract); integration (rendered admin section: links, currency display, capability gate, pagination, all-statuses rendering); performance (`tests/integration/supplier-order-history/test-supplier-order-history-performance.php`: exactly 3 queries for a non-empty page, exactly 1 for a zero-PO supplier, independent of page size/number/history size, proven at 200-PO scale).

**Explicitly excluded from M14:** spend analysis/totals, cross-PO or cross-currency aggregation, trend charts, changes to PO write paths/lifecycle/statuses/events, supplier merge, grace-days Settings UI, expected-date suggestion source UI, Inventory Position supplier column, storefront Expected Delivery confidence changes, Coverage/Forecast, Reservations, Inbound Shipment, warehouse locations, REST/Store API/GraphQL, any new public hook/API/capability, sortable columns beyond the fixed `order_date DESC`. Next process step: planning M15, or closing this train, only with explicit approval.

---

## Milestone M15 — Supplier Spend Summary (1.32.0, released as v1.32.0)

**Status:** Complete, development version `1.32.0`. **Schema unchanged (v10), zero new tables, zero new columns.** Third milestone of the same feature train M13 opened. Frozen with a Level A completion review (see `docs/checklists/m15-release-readiness.md`); released bundled with M13/M14 as tag `v1.32.0`. Zero new public API surface (Internal, D16); zero new capability; zero new public hook.

**Scope:** a read-only, per-currency total of Ordered Value and Received Value (PO Cost) across a supplier's *committed* Purchase Orders, on the existing Supplier detail admin screen, rendered before the Observed Lead Time / Order History sections. Closes the one remaining named gap in `docs/admin-guide-suppliers.md`'s "Not Yet Available" list (supplier spend analysis) by resolving M14's stated currency-normalization blocker: the policy is "never blend or convert," not "normalize later."

**New Internal sole-owner class:** `WC_Inventory_Overview_Supplier_Spend_Service` (`includes/class-wc-inventory-overview-supplier-spend-service.php`) owns the "committed spend" status rule — `committed_statuses()` returns exactly `placed`/`partially_received`/`received`/`closed_short` (BR-M15-1, INV-M15-1); `draft` and `cancelled` are always excluded, a genuinely new business decision distinct from M14's status-inclusive Order History. `get_summary( $supplier_id )` composes the result exclusively through `Purchase_Orders::spend_summary_for_supplier()` (INV-M15-3) — never `$wpdb` directly, never `Purchase_Order_Lines`/`Goods_Receipts`/`Receipt_Lines`/`Receipt_Costs`/`Suppliers` directly.

**New additive, self-contained read method:** `WC_Inventory_Overview_Purchase_Orders::spend_summary_for_supplier( $supplier_id, $statuses )` — unlike M14's `values_bulk()`, this does **not** compose through `list()`/`build_where()`; it issues its own parameterized `SELECT ... JOIN ... GROUP BY pol.currency` directly against `wc_io_purchase_order_lines`/`wc_io_purchase_orders`, a true database-level aggregate over the supplier's *entire* history (not page-scoped). Returns one row per currency: `ordered_total`, `received_total` (`SUM(qty_ordered*unit_cost)`/`SUM(qty_received*unit_cost)`), and `po_count = COUNT(DISTINCT po.id)` — evaluated *within* each currency's `GROUP BY` bucket (BR-M15-5), so a PO with lines in two currencies is correctly counted once in each of the two resulting rows, never double-counted within one row and never meant to be summed across rows into a supplier-wide count.

**Presentation:** new private `render_spend_summary_section( $supplier_id )` on `WC_Inventory_Overview_Purchasing_Page`, called from `render_supplier_detail()` before `render_observed_lead_time()`. Table columns: Currency, Ordered Value, Received Value (PO Cost), Committed POs. Reuses the existing `manage_woocommerce` gate and the existing `format_po_cost_value()` money-formatting helper — no new capability, no new hook.

**Value/currency semantics:** identical PO-line-cost-only formula to M14 (never landed cost, never the weighted-average inventory-value figure). Currencies are never blended or converted (INV-M15-2) — a supplier invoiced in more than one currency shows one row per currency, side by side.

**Query/performance contract:** exactly 1 query for a non-empty result and exactly 1 query for an empty result (no separate existence-check query) — a genuinely different shape from M14's paginated 3-query contract, since Spend Summary has no pagination step at all. Proven at 200-PO/3-currency scale, independent of history size (`tests/integration/supplier-spend/test-supplier-spend-performance.php`).

**Architecture guards:** `tests/unit/supplier-spend/test-supplier-spend-architecture.php` — zero unapproved read tokens in the service; service uses only `Purchase_Orders::spend_summary_for_supplier()`; zero write tokens in both the service and `spend_summary_for_supplier()`'s own method body; zero FX/currency-blending tokens in either; sole-consumer allowlist (only `Purchasing_Page` may call the service).

**Testing:** unit (`tests/unit/purchase-orders/test-po-spend-summary.php`: formula correctness, committed-status filtering, currency-row isolation, a required mixed-line-currency fixture proving `po_count` semantics, empty-result short-circuit, scoped-to-one-supplier correctness, single-query proof; `tests/unit/supplier-spend/test-supplier-spend-service.php`: committed-status constant, delegation correctness, multi-currency pass-through); integration (`tests/integration/supplier-spend/test-supplier-spend-admin.php`: totals rendering, empty state, draft/cancelled exclusion scoped to the Spend Summary section specifically (not the whole page, since Order History legitimately shows a draft PO's own value elsewhere), currency isolation, section ordering, capability gate); performance (200-PO/3-currency exactly-1-query proof, uncommitted-only and zero-PO exactly-1-query proofs).

**Explicitly excluded from M15:** cross-supplier/storewide spend rollup or "top suppliers" view, FX conversion or a single blended cross-currency total, trend charts/time-bucketing/date-range filtering, changes to PO write paths/lifecycle/statuses/events, changes to `Supplier_Order_History_Service`/M14's per-PO rows/pagination, a Suppliers-list-table spend column, supplier merge, grace-days Settings UI, expected-date suggestion source UI, Inventory Position supplier column, storefront Expected Delivery confidence changes, Coverage/Forecast, Reservations, Inbound Shipment, warehouse locations, REST/Store API/GraphQL, any new public hook/API/capability. Next process step: close and release the M13–M15 train, only with explicit approval (see `docs/milestones/m15-implementation-plan.md` Part G).

---

## Milestone M16 — PO Expected-Date & Delay Transparency (1.33.0, frozen but unreleased)

**Status:** Complete, development version `1.33.0`. **Schema unchanged (v10), zero new tables, zero new columns.** First milestone of a new, not-yet-named post-v1.32.0 train. Frozen with a Level A completion review (see `docs/checklists/m16-release-readiness.md`) on `feature/m16-po-expected-date-delay-transparency`; `main` remains at `v1.32.0` — **not merged, tagged, or released**. Zero new public API surface (Internal, D16); zero new capability; zero new public hook.

**Scope:** closes two backlog items previously rejected in every M9–M15 discovery pass as "too small alone" (grace-days Settings UI, expected-date suggestion source UI), combined with a trivial third surface in the same theme (the Inventory Position supplier column previously named in every prior milestone's exclusion list) — three touchpoints of one coherent problem: the plugin already computes expected-date suggestion provenance and already displays a delay indicator, but the merchant could not see *why*, nor configure the one policy knob (grace days) controlling one of them.

**Surface 1 — suggestion provenance:** `WC_Inventory_Overview_Expected_Date_Suggestion_Service`'s return shape gains `sample_count`/`average_days` (BR-M16-2), both `null` unless `source === 'observed'`, both read from the same `Supplier_Lead_Time_Service::get_stats_bulk()` result the service already fetches for `is_observed_value_usable()` — no additional query, no duplicated computation. `WC_Inventory_Overview_PO_Admin::lead_time_suggestions_for_localize()` passes `source`/`sample_count`/`average_days` through to the existing `wcIoPoAdmin.leadTimeSuggestions` localized array; `assets/po-admin.js` builds an advisory-only hint text (never submitted, BR-M16-3) using `sample_count`/`average_days` for an observed suggestion or `days` for a configured one — never `days` as observed-history evidence (BR-M16-1/BR-M16-2). The pre-existing observed→configured→none resolution algorithm and `is_observed_value_usable()` threshold are unchanged.

**Surface 2 — grace-days Settings field:** `WC_Inventory_Overview_Settings::get_po_delay_grace_days()` (new getter, delegates to `PO_Delay::grace_days_from_option()`) and `maybe_save_po_delay_grace_days()` (new private helper called from `save_from_post()`) implement an explicit validate-or-preserve contract (BR-M16-4) on the **pre-existing** `WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS` option — no duplicate constant (BR-M16-5). Missing, non-numeric, non-clean-integer, or out-of-range (`<0` or `>365`) input always leaves the stored value untouched (no `update_option()` call); a clean integer 0–365 saves exactly as submitted. Deliberately not the `absint()` pattern `OPTION_DEFAULT_LOW_STOCK_THRESHOLD` uses, since `absint()` would silently turn e.g. `-5` into `5`. New "Purchasing" section on the Settings tab (`class-wc-inventory-overview-plugin.php::render_settings_panel()`) renders the field via the existing `wc_io_save_settings` admin-post action and nonce — no new mutation path, no new capability.

**Surface 3 — Inventory Position drilldown columns:** `WC_Inventory_Overview_Purchase_Order_Lines::query_open_lines()` (the single query behind both `list_open_lines_for_product_ids()`/`list_open_lines_for_variation_ids()`, M3) gains two additional `SELECT` columns — `po.supplier_name_snapshot AS supplier_name`, `po.status AS po_status` — both already available in the already-joined `po` row; no new join, no new query. `WC_Inventory_Overview_List_Table::render_position_drilldown_section()` renders them as Supplier/Status columns in the fixed order **PO number, Supplier, Status, Outstanding, Expected date, Confidence, Delayed** (BR-M16-8). Supplier uses the immutable PO-time snapshot (BR-M16-6, survives supplier archive, same convention as every other PO-level supplier display); Status reuses the existing `PO_Statuses::label()` map (BR-M16-7). The edit stays entirely inside the already-whitelisted repository file, so the M3 sole-caller architecture guard (`test-inventory-position-architecture.php`) needed zero changes.

**Query/performance contract:** all three surfaces add zero new repository/SQL queries — verified by re-running (unmodified) `Test_WC_IO_Expected_Date_Suggestion_Performance` (still exactly 1 query regardless of supplier count) and `test_position_query_count_bounded_for_twenty_plus_rows` (still ≤2 SELECTs against the PO-lines join). The Settings grace-days field uses ordinary WordPress `get_option()`/`update_option()` persistence, outside this repository-query invariant.

**Testing:** unit (`Test_WC_IO_Expected_Date_Suggestion_Service`: evidence-field presence/nullability/`days`-unaffected; new `Test_WC_IO_Settings_PO_Delay_Grace_Days`: table-driven validate-or-preserve contract covering missing/negative/`>365`/non-numeric/decimal/scientific-notation/trailing-character/`0`/`365`/mid-range, plus a sole-mutator architecture guard); integration (`Test_WC_IO_PO_Admin`: localized-data provenance for configured/observed/none sources, i18n template presence, edit-screen no-op; `Test_WC_IO_Inventory_Position_List_Table`: fixed column order, supplier-snapshot-survives-archive, shared status label). Full unit (362 tests) and integration (316 tests) suites green, 0 failures/errors/risky; default M1–M16 filtered suite (667 tests) green.

**Explicitly excluded from M16:** supplier merge, cross-supplier/storewide spend rollup, Coverage/Forecast (still blocked — no sales/consumption-velocity data anywhere in the schema), Reservations/Available Stock (still blocked — D16, no concrete consumer), Inbound Shipment, warehouse locations, REST/Store API/GraphQL, Plugin god-class refactor, unrelated PHPCS cleanup, any change to the suggestion-resolution algorithm or to how `PO_Delay`/`Expected_Deadline` compute the deadline itself, any storefront-facing change. Next process step: the release-timing/train decision for M16 (standalone release vs. opening a new feature train with, e.g., the supplier merge tool as M17), only with explicit approval.

---

## Known risks / tech debt

1. **Large god class:** `class-wc-inventory-overview-plugin.php` centralizes UI, handlers, and exports — harder to test and review. **Phase 1 (M18) complete:** Dashboard and Settings tabs extracted into dedicated `WC_Inventory_Overview_Dashboard_Controller` and `WC_Inventory_Overview_Settings_Controller` classes (~42% of the original 2,706 lines moved out). Remaining five tabs (Overview, Restock, Movements, Order Profit, Product Profitability) and their shared CSV-export/bulk-action machinery remain on Plugin — reserved for Phase 2 in a future dedicated milestone.
2. **Custom SQL surface:** Profitability and movements list tables build dynamic SQL; most paths use `$wpdb->prepare`, but complexity increases regression risk.
3. **`posts_clauses` filter:** Global filter at priority 999; scoped by query depth and admin context — avoid front-end product queries while filter is active.
4. **Danger zone reset:** Can bulk-delete plugin tables/meta snapshots; gated by capability + nonces + preview token — still high impact for operators.
5. **Inline stock AJAX:** Uses `edit_products` (broader than `manage_woocommerce`) with per-product `edit_product` — intentional for catalog editors.
6. **Automated tests:** PHPUnit unit/integration suites (M0 golden + M1 suppliers + M2 purchase orders + M3 Inventory Position + M4 Goods Receipts + M5 PO Receiving + M6 Batch Migration + M7 expected-delivery + M8 conformance/hardening + M9 supplier-lead-time + M10 expected-date-suggestion + M11 expected-deadline/on-time-rate + M12 suppliers-list-performance + M13 po-print + M14 supplier-order-history + M15 supplier-spend), PHPCS (local, not CI-gated — ~559 pre-existing errors/634 warnings as of M8/M9, not re-measured since; evaluated and deliberately excluded from M8 as disproportionate to a hardening pass; M13's/M14's/M15's own touched files are PHPCS-clean), and GitHub Actions CI (PHP lint + release ZIP + the full integration suite, blocking since M8). PHPUnit runs via Docker harness under `tests/docker/`. See `docs/testing.md`.
7. **Monorepo mirror:** A development copy may exist under `biopentra-custom-plugins/plugins/wc-inventory-overview/`; this standalone repo is canonical for releases.

---

## Recommended follow-ups (non-blocking)

- Split `Plugin` into tab controllers or modules — **Phase 1 (M18) complete:** Dashboard + Settings extracted; **Phase 2 (future milestone):** remaining five tabs (Overview, Restock, Movements, Order Profit, Product Profitability) + CSV export + bulk actions.
- Extend schema-shape assertion per milestone (M3 introduced no schema change; M4 added Goods Receipt tables/columns; M5 added `qty_received`; M6 added `wc_io_purchase_batches.migrated_receipt_id`/`migrated_at`; M8–M15 introduced no schema change — next relevant whenever a future milestone next changes schema).
- ~~Observed lead-time statistics (average/minimum/maximum delivery times, computed from actual receiving history)~~ — **done in M9** (see the M9 section above).
- ~~Wiring observed lead time into PO-creation expected-date suggestions~~ — **done in M10** (see the M10 section above).
- ~~Supplier reliability scoring~~ — **done in M11** as On-Time Delivery Rate (see the M11 section above).
- ~~Surfacing Observed Lead Time / On-Time Rate on the Suppliers list~~ — **done in M12** (see the M12 section above).
- ~~Printable Purchase Order (D17 §11.2's reserved-since-v1.0 capability)~~ — **done in M13** (see the M13 section above).
- ~~Order-history reporting~~ — **done in M14** (see the M14 section above).
- ~~Spend analysis~~ — **done in M15** as Spend Summary (see the M15 section above), resolving the currency-normalization blocker M14 named by choosing to never blend or convert. The supplier merge tool remains unaffected and still open.
- ~~Physically delete the M6-deprecated Batch Intake code~~ — **done in M8** (see the M8 section above).
- ~~`PO_Delay`'s "Delayed" detection does not extend to `partially_received` POs~~ — **fixed in M8** (see the M8 section above).
- PHPCS-clean the codebase, or actually wire up the empty `.phpcs-baseline.xml` ratchet — evaluated for M8 and deliberately excluded (disproportionate scope for a hardening pass); still a reasonable future initiative on its own.
- Pin `WC_VERSION` in `tests/docker/run-phpunit.sh` (WooCommerce is currently downloaded as `latest-stable`, unpinned, unlike `WP_VERSION`) — a genuine reproducibility question, evaluated for M8 and left open for lack of any concrete failure on record.
