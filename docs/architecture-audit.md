# Architecture audit — WC Inventory Overview 1.17.0

**Date:** 2026-05-15  
**Scope:** Standalone repo `magpern/wc-inventory-overview` (copy of monorepo plugin, no behavior changes)

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

Created/upgraded via `WC_Inventory_Overview_Install` (`DB_VERSION = 5`, option `wc_io_db_version`):

| Table | Purpose |
|-------|---------|
| `{prefix}wc_io_inventory_movements` | Stock/cost movement ledger |
| `{prefix}wc_io_purchase_batches` | Batch purchase headers |
| `{prefix}wc_io_purchase_batch_lines` | Per-SKU batch lines |
| `{prefix}wc_io_purchase_batch_costs` | Landed cost lines per batch |
| `{prefix}wc_io_exchange_rates` | FX rate history |

Schema via `dbDelta()` on activate and version bump.

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

| Tab | Capability | Primary classes |
|-----|------------|-----------------|
| Dashboard | `edit_products` | `Dashboard_Inventory_Metrics`, `Dashboard_Charts_Data` |
| Inventory Overview | `edit_products` | `List_Table`, `Repository`, `Summary` |
| Restock / Cost Adjustment | `manage_woocommerce` | `Restock_Service`, `Batch_Intake_*`, `Cost_Adjustment_Service` |
| Movements | `manage_woocommerce` | `Movements`, `Movements_List_Table` |
| Order Profit | `manage_woocommerce` | `Order_Profit_Query`, `Order_Profit_List_Table` |
| Product Profitability | `manage_woocommerce` | `Product_Profitability_Query`, list table |
| Settings | `manage_woocommerce` | `Settings`, `Exchange_Rates`, `Data_Reset` |

Legacy slugs `wc-inventory-overview` and `wc-inventory-restock` redirect into the hub.

---

## AJAX actions (`wp_ajax_*`)

| Action | Handler | Capability + nonce |
|--------|---------|-------------------|
| `wc_io_save_inline_stock` | `ajax_save_inline_stock` | `edit_products` + `edit_product`; nonce `wc_io_inventory` |
| `wc_io_get_cost_adjustment_preview` | `ajax_get_cost_adjustment_preview` | `manage_woocommerce`; nonce `wc_io_cost_adj_preview` |
| `wc_io_batch_preview` | `ajax_batch_preview` | `manage_woocommerce`; nonce `wc_io_batch_preview` |
| `wc_io_get_exchange_rate` | `ajax_get_exchange_rate` | `manage_woocommerce`; nonce `wc_io_get_exchange_rate` |

All are **admin-only** (`wp_ajax_*`, not `nopriv`).

---

## admin_post actions

| Hook | Purpose |
|------|---------|
| `wc_io_restock` | Quick restock line |
| `wc_io_batch_apply` | Commit batch intake |
| `wc_io_cost_adjustment` | Average cost adjustment |
| `wc_io_save_settings` | Plugin settings |
| `wc_io_add_exchange_rate` | Add FX row |
| `wc_io_delete_exchange_rate` | Delete FX row |
| `wc_io_danger_reset_preview` | Danger zone preview |
| `wc_io_danger_reset_apply` | Danger zone delete |

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

| Class | Responsibility |
|-------|----------------|
| `Repository` | Product list queries, `posts_clauses` |
| `Costing` | Average cost meta |
| `Movements` | Insert/query movement rows |
| `Restock_Service` | Quick restock transactions |
| `Batch_Intake_Service` / `Batch_Intake_UI` | Multi-line purchase batches |
| `Cost_Adjustment_Service` | Manual avg cost changes |
| `Exchange_Rates` | FX table CRUD + lookup |
| `Order_Profit_Query` / `Product_Profitability_Query` | Reporting SQL |
| `Data_Reset` | Scoped delete of plugin analytics data |
| `Summary` | Hub totals |
| `*List_Table` | WP_List_Table admin UIs |

---

## Assets

| Path | Enqueued on |
|------|-------------|
| `assets/admin.css`, `admin.js` | Inventory hub (overview tab) |
| `assets/batch-intake.js` | Restock batch view |
| `assets/restock-cost-adj.js` | Restock quick/adjust views |
| `assets/movements-table.js` | Movements tab |
| `assets/settings-shipping-fx.js` | Settings |
| `assets/dashboard-charts.js` | Dashboard |
| `assets/vendor/chart.umd.min.js` | Dashboard (Chart.js, vendored) |

Scripts receive localized nonces and AJAX URLs from `Plugin::enqueue_*`.

---

## CLI scripts

| File | Use |
|------|-----|
| `cli/set-low-stock-threshold.php` | WP-CLI `eval-file`: bulk-set variation `_low_stock_amount` to 3 |

Operational only; not loaded by WordPress. Requires WP-CLI + WooCommerce.

---

## Known risks / tech debt

1. **Large god class:** `class-wc-inventory-overview-plugin.php` centralizes UI, handlers, and exports — harder to test and review.
2. **Custom SQL surface:** Profitability and movements list tables build dynamic SQL; most paths use `$wpdb->prepare`, but complexity increases regression risk.
3. **`posts_clauses` filter:** Global filter at priority 999; scoped by query depth and admin context — avoid front-end product queries while filter is active.
4. **Danger zone reset:** Can bulk-delete plugin tables/meta snapshots; gated by capability + nonces + preview token — still high impact for operators.
5. **Inline stock AJAX:** Uses `edit_products` (broader than `manage_woocommerce`) with per-product `edit_product` — intentional for catalog editors.
6. **No automated tests** in repo; PHP lint only.
7. **Monorepo drift:** Production copy under `woocommerce/wp-content/plugins/` may diverge from this repo until deploy process is formalized.

---

## Recommended follow-ups (non-blocking)

- Split `Plugin` into tab controllers or modules.
- Add PHPUnit for costing math and batch allocation.
- Add `readme.txt` for WordPress.org-style metadata if ever published.
- CI: `php -l`, `build-zip.sh`, optional PHPCS WordPress ruleset.
