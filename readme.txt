=== WC Inventory Overview ===
Contributors: magpern
Tags: woocommerce, inventory, stock, costing, dashboard
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.23.0
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

= 1.23.0 =
* Milestone M6 — Migration & Retirement: legacy Batch Intake history is migrated into Goods Receipts as historical record materialization, not replay -- current stock and cost are byte-for-byte unchanged (verified by a dedicated golden test). Schema v10 (migration-tracking columns only, no new business schema).
* New WP-CLI command: wp wc-io migrate-batches [--apply] [--verify] [--batch=<id>] [--rollback=<id>] [--limit=<n>] -- dry-run by default; --verify is the permanent reconciliation tool for this data.
* Batch<->movement regex linkage replaced with typed reference_type/reference_id columns.
* Batch Intake's create/apply admin entry points are retired -- no new batch can be created; legacy tables/rows are frozen, never deleted.
* Migrated Goods Receipts cannot be voided through the normal admin action (use the CLI's --rollback mode instead); all other receipts are unaffected.
* Landed-cost-type vocabulary extracted into a small, neutral class shared by Goods Receipt costing.

= 1.22.0 =
* Milestone M5 — Purchase Order Receiving: qty_received becomes a real, maintained column on wc_io_purchase_order_lines (full INV-4 formula: outstanding = ordered - received - cancelled). Schema v9.
* Goods Receipt lines can now carry a po_line_id, populated only through Receipt_Lines::create() -- "Receive" against a Purchase Order pre-fills a draft from its outstanding lines; direct receipts (Quick Receive Without PO) are unchanged.
* New PO statuses partially_received / received, auto-transitioned only (never operator-selected) by the new WC_Inventory_Overview_PO_Receiving_Sync -- the sole owner of qty_received mutations, itself the only caller of Purchase_Order_Lines::increment_qty_received(). Goods_Receipt_Service remains the sole business orchestrator and sole stock/cost mutator; no second mutation path was introduced.
* Over-receipt is allowed, never blocked (per D5), warned at the post-confirm screen and recorded in the PO event log with an over_receipt / qty_over marker.
* Five new PO event types (po_line_received, po_line_receipt_voided, po_partially_received, po_received, po_qty_received_reconciled) close the audit-trail gap M4 explicitly reserved for this milestone.
* New wp wc-io reconcile-qty-received [--fix] [--po=<id>] CLI command: read-only drift report by default; --fix repairs through the same sole-owner class, never bypassing it.
* Purchase Order detail page gains a Receive button, a Received column, and a receiving-history panel; Goods Receipt lines show "Fulfils: PO-XXXX line N" back-links.
* M3's Inventory Position "Incoming" figure now correctly decreases as receipts post against PO lines (the recomputation M3's own plan deferred to this milestone).
* IMPORTANT: like M4, M5 mutates WooCommerce stock, cost, and now qty_received/PO status. Read docs/rollback-plan.md before rolling back a site with any M5-era receipts posted.

= 1.21.0 =
* Milestone M4 — Receipt Engine: Goods Receipt as the sole stock/cost mutator (Quick Receive Without PO). Schema v8: new wc_io_goods_receipts / wc_io_receipt_lines / wc_io_receipt_costs tables; wc_io_inventory_movements gains reference_type / reference_id / supplier_id.
* New "Receive Stock" tab on the Purchasing page: draft creation/edit/delete, product/variation picker, landed costs, computed preview, explicit post confirmation, void with mandatory reason.
* Posting/voiding run inside a single database transaction each, with a compare-and-swap status guard and a one-shot request token -- forced-failure tests confirm full rollback (zero partial stock/cost/movement changes) and that a void correctly reverses only its own receipt's contribution even when other receipts posted in between.
* Direct receipts only: po_line_id always NULL, no PO table is touched, no qty_received -- Receive-Against-PO remains M5.
* No changes to Batch Intake, Quick Restock, Cost Adjustment, Purchase Orders, or Inventory Position; all continue to function unmodified.
* IMPORTANT: unlike M1-M3, M4 mutates WooCommerce stock and cost. Read docs/rollback-plan.md before rolling back a site that has posted any Goods Receipts.

= 1.20.0 =
* Milestone M3 — Inventory Position: read-only Position ({On Hand, Incoming, Position}) for every simple product and variation, computed from open (placed-PO) purchase order lines. No schema change, no migration, DB_VERSION remains 7.
* New Incoming and Position columns on Inventory Overview, next to Stock, visible to manage_woocommerce users only (same tier as average cost / inventory value; no new capability).
* Per-supply drill-down (existing details-toggle pattern): each contributing PO line shown independently with PO number/link, outstanding quantity, expected date, confidence, and delayed indication.
* Variable-parent rows show a presentation-only sum of child-variation Incoming/Position; child variations retain individual figures and drill-downs. No incoming is ever recorded against a variable parent.
* Delayed-incoming reuses the existing M2 line-level delay predicate; no new delay logic.
* Bulk-fetch sequencing: Position is fetched in exactly one call after the complete product/variation group structure is built (no per-row queries, no N+1) -- verified by a query-scaling regression test over 20+ mixed items.
* No receiving, no stock/cost mutation, no qty_received, no Goods Receipts -- M3 is entirely read-only (M4/M5 will extend the Incoming formula once receiving exists).

= 1.19.1 =
* Test/CI infrastructure repair only -- no database schema, business-behavior, or UI changes. DB_VERSION remains 7.
* Fixed the Docker PHPUnit harness (`tests/docker/run-phpunit.sh`) never installing the plugin's own Composer dev dependencies, which made every run fail before executing a single test.
* Fixed the WordPress test bootstrap not granting the `manage_woocommerce` capability to the test administrator user, which made all Purchase Orders admin-handler tests fail with "Insufficient permissions."
* Removed an invalid `failOnDeprecated` attribute from `phpunit.xml.dist` (not a valid attribute for the installed PHPUnit 9.x schema).
* Added an explicit Docker Compose project name to avoid collisions with other ephemeral stacks on a shared host.
* GitHub Actions now actually executes the PHPUnit unit suite and the M2-focused suite (both blocking), plus the cumulative integration suite (visible, non-blocking pending known pre-existing test-content fixes -- see docs/testing.md).
* Corrected `tests/README.md` and `docs/testing.md` to document the test/CI setup as it actually exists.

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
