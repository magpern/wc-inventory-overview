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

Operational only; not loaded by WordPress. Requires WP-CLI + WooCommerce.

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

## Known risks / tech debt

1. **Large god class:** `class-wc-inventory-overview-plugin.php` centralizes UI, handlers, and exports — harder to test and review.
2. **Custom SQL surface:** Profitability and movements list tables build dynamic SQL; most paths use `$wpdb->prepare`, but complexity increases regression risk.
3. **`posts_clauses` filter:** Global filter at priority 999; scoped by query depth and admin context — avoid front-end product queries while filter is active.
4. **Danger zone reset:** Can bulk-delete plugin tables/meta snapshots; gated by capability + nonces + preview token — still high impact for operators.
5. **Inline stock AJAX:** Uses `edit_products` (broader than `manage_woocommerce`) with per-product `edit_product` — intentional for catalog editors.
6. **Automated tests:** PHPUnit unit/integration suites (M0 golden + M1 suppliers + M2 purchase orders + M3 Inventory Position), PHPCS (local), and GitHub Actions CI (PHP lint + release ZIP). PHPUnit runs via Docker harness under `tests/docker/`. See `docs/testing.md`.
7. **Monorepo mirror:** A development copy may exist under `biopentra-custom-plugins/plugins/wc-inventory-overview/`; this standalone repo is canonical for releases.

---

## Recommended follow-ups (non-blocking)

- Split `Plugin` into tab controllers or modules.
- Extend schema-shape assertion per milestone (M3 introduced no schema change; next relevant at M4/M5 receiving).
