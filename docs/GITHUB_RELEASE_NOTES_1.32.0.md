# WC Inventory Overview 1.32.0

**Canonical bundled release** from [magpern/wc-inventory-overview](https://github.com/magpern/wc-inventory-overview).

This release publishes the complete **M13–M15 Purchasing & Supplier Insights feature train** as a single version. Intermediate development bumps `1.30.0` / `1.31.0` were never tagged and are **not** published as separate GitHub Releases.

## Prerequisite

Upgrade from **1.29.0** (M9–M12 Supplier Performance feature train, schema v10).

## Overview

**v1.32.0** ships three milestones that round out purchase-order documentation and supplier financial visibility:

| Milestone | Capability |
|-----------|------------|
| **M13** | Printable Purchase Order |
| **M14** | Supplier Order History |
| **M15** | Supplier Spend Summary |

**Zero schema change across the entire train (`DB_VERSION` stays `10`).** No new public API, no new settings UI, no inventory/cost mutation, no storefront Expected Delivery semantic change.

## M13 — Printable Purchase Order

A standalone, read-only printable view of a Purchase Order, reachable from the existing PO detail screen:

- **"Print" entry point** on the PO detail screen for `placed`, `partially_received`, `received`, `cancelled`, and `closed_short` orders — never for `draft`
- Printed document includes store name, PO number/status/dates/currency, supplier name/reference/email/phone, every line (product/SKU/quantities/price/line total), and the PO total
- **Resilient to deleted references:** a line whose product/variation has since been deleted still prints correctly (via stored `name_snapshot`/`sku_snapshot`); a PO whose supplier record is unresolvable still prints (via the header's stored `supplier_name_snapshot`), with contact/reference fields simply omitted
- No PDF library and no generated/stored file — browser Print (or "Save as PDF") is the only mechanism, and the page prints correctly even with JavaScript disabled
- Requires the existing `VIEW_PO` capability plus a PO-and-action-scoped nonce before any purchasing/supplier data is rendered

## M14 — Supplier Order History

A read-only, paginated history of every Purchase Order ever placed with a supplier, on the existing Supplier detail admin screen:

- Lists **every status** — draft, placed, partially received, received, cancelled, and closed short all appear; nothing is filtered out — for full historical/audit visibility
- Newest order date first, with its own dedicated pagination (never interferes with any other pagination on the page)
- Each row shows that PO's own **Ordered Value** and **Received Value (PO Cost)** — PO-line cost only, in that PO's own currency, never summed across orders or currencies
- This is deliberately **not** a spend report — it is per-order historical visibility. For a totaled view, see Spend Summary (M15) below.

## M15 — Supplier Spend Summary

A read-only summary, above the Observed Lead Time panel on the Supplier detail screen, totaling what a supplier has actually cost so far:

- Reports **committed spend only** — includes `placed`, `partially received`, `received`, and `closed short` orders; **excludes `draft`** (nothing has actually been ordered yet) and **excludes `cancelled`** (never fulfilled, not real spend)
- **Currencies are always kept separate.** If a supplier has been ordered from in more than one currency, each currency gets its own row with its own totals — there is **no FX-normalized combined total**, ever
- **Received Value (PO Cost)** is the PO's own unit cost multiplied by quantity received — it is **not** a landed-cost figure and **not** the weighted-average inventory-valuation figure Goods Receipt posting maintains
- Each currency row also shows a **Committed POs** count — the number of distinct committed orders contributing to that row. Because a single order can rarely have lines in more than one currency, that order may be counted once in more than one row; these counts are never meant to be summed across rows into a supplier-wide order count
- A supplier with no committed orders shows a plain "No committed purchase orders yet for this supplier." message
- A true database-level aggregate — exactly one query regardless of how large the supplier's order history is

## Maintenance included

This release also includes a narrow, accepted test-infrastructure fix that landed between M14 and M15:

- **Integration-test isolation hardening:** a test that previously relied on an irreversible `DOING_AJAX` constant definition now uses the reversible `wp_doing_ajax` filter instead, eliminating a theoretical test-order-dependent state leak. This is a test-only change with **no effect on any merchant-facing behavior**.

## Explicitly not included

- Cross-supplier / storewide spend rollup ("top suppliers by spend")
- FX conversion or any blended multi-currency total
- Trend charts, date-range filtering, or period-over-period comparison
- Supplier merge tool
- PO delay grace-days Settings UI redesign
- Expected-date suggestion source UI
- Inventory Position supplier drilldown
- Storefront Expected Delivery confidence changes
- Coverage/Forecast, Reservations, Inbound Shipment, Warehouse locations
- Any REST/Store API/GraphQL exposure
- Any other deferred roadmap work outside M13–M15

## Schema / data

- **`DB_VERSION` remains `10`**
- No migrations, no new tables/columns/indexes
- No persisted derived data — every M13/M14/M15 figure is computed at read time from existing Purchase Order / Purchase Order Line records
- No stock, cost, Goods Receipt lifecycle, or Inventory Position mutation from this train

## Install / upgrade

1. Download **`wc-inventory-overview-1.32.0.zip`** from this release.
2. Upload via **Plugins → Add New → Upload**, or use **Dashboard → Updates** where the GitHub updater is configured.
3. No schema step — `DB_VERSION` stays `10`; no `ALTER` runs; no upgrade routine fires for this release.
4. Merchant-visible additions: a "Print" link on eligible Purchase Orders; an "Order History" section and a "Spend Summary" section on the Supplier detail screen. Everything else continues as in 1.29.0.

## Validation checklist

Per [docs/release-runbook.md](release-runbook.md) and [docs/checklists/feature-train-m13-m15-release-readiness.md](checklists/feature-train-m13-m15-release-readiness.md):

- Confirm `DB_VERSION` is still `10` and schema assertion is `ok: true`
- M13: Print link present/absent per status; printed document complete; deleted-product/unresolvable-supplier POs still print
- M14: Order History shows every status; per-order values correct and never blended
- M15: Spend Summary excludes draft/cancelled; currencies never blended; Committed POs count matches expectations per currency row
- Regression: Suppliers, Purchase Orders, Goods Receipts, PO Receiving, Inventory Position, storefront Expected Delivery, Quick Restock, Cost Adjustment, Batch Migration CLI, PO Delay, and the M9–M12 supplier performance surfaces
- Full automated suites green (unit, M1–M15 focused, integration) with 0 risky

## Rollback

**The entire M13–M15 train is code-only relative to v1.29.0 — nothing to reverse in the database.**

- **Code rollback 1.32.0 → 1.29.0:** unconditionally safe. Remove the Print link, Order History section, and Spend Summary section; purchasing, receiving, inventory, and storefront Expected Delivery continue to function.
- No schema/data cleanup is required.
- Historical PO/GR rows are untouched either direction.

See [docs/rollback-plan.md](rollback-plan.md).

Changelog: [CHANGELOG.md](../CHANGELOG.md)
