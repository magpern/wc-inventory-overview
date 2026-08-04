=== WC Inventory Overview ===
Contributors: magpern
Tags: woocommerce, inventory, stock, costing, dashboard
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.18.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Operational inventory dashboard for WooCommerce products and variations (HPOS-compatible).

== Description ==

WC Inventory Overview provides admin dashboards for inventory movements, costing, profitability views, purchasing (Suppliers), and related operational tools for WooCommerce stores.

== Installation ==

1. Upload `wc-inventory-overview-{version}.zip` via **Plugins → Add New → Upload**, or extract to `wp-content/plugins/wc-inventory-overview/`.
2. Activate the plugin.
3. Open the inventory screens under WooCommerce admin.

== Changelog ==

= 1.18.0 =
* Milestone M1 — Suppliers: first-class supplier entity, Purchasing admin page, seed migration from historical supplier strings, schema v6.
* Supplier autocomplete on Batch Intake and Quick Restock (additive; legacy free-text fields unchanged).

= 1.17.3 =
* Milestone M0 — Delivery Foundations: PHPUnit, PHPCS, CI, golden characterization tests, DB-transaction helper, release templates. No plugin behavior change vs 1.17.2.

= 1.17.2 =
* GitHub Release updater for production ZIP installs from biopentra-custom-plugins releases (tag wc-inventory-overview-v*).

= 1.17.1 =
* Production release ZIP hardening and monorepo GitHub Actions release on tag `wc-inventory-overview-v*`. Packaging only — no behavior change vs 1.17.0. `cli/` excluded from ZIP.

= 1.17.0 =
* Prior operational inventory feature set.
