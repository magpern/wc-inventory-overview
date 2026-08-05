=== WC Inventory Overview ===
Contributors: magpern
Tags: woocommerce, inventory, stock, costing, dashboard
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.19.0
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

= 1.19.0 =
* Milestone M2 — Purchase Orders: schema v7, four-state lifecycle (draft/placed/cancelled/closed_short), PO events audit log, expected dates with confidence, delayed detection.
* Purchasing → Purchase Orders admin tab: list, create/edit, timeline, place/cancel/close short/duplicate/delete draft.
* Never-reuse PO numbering PO-{YYYY}-{NNNN}. No receiving, no stock changes (M5+).

= 1.18.1 =
* Fix supplier admin PRG: redirect after save/archive/reactivate instead of blank admin-post page.
* Nonce-safe Archive/Reactivate row actions; render Active/Archived list views.

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
