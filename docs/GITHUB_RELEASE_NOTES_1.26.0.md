# WC Inventory Overview 1.26.0

**Canonical standalone release** from [magpern/wc-inventory-overview](https://github.com/magpern/wc-inventory-overview).

## Prerequisite

Upgrade from **1.25.0** (M8 Hardening & GA, schema v10).

## What changed

**Milestone M9 — Supplier Observed Lead-Time Statistics.** The first post-GA milestone: one narrowly-scoped, read-only reporting feature filling an architecturally-reserved slot named since M1 (`CLAUDE.md` Decision D8: "observed lead-time statistics are a designed-for-later capability") and explicitly promised in `docs/admin-guide-suppliers.md`'s own "Not Yet Available" backlog. **Zero new domain concepts, zero schema change (`DB_VERSION` stays `10`), zero new public API surface.**

- **New `WC_Inventory_Overview_Supplier_Lead_Time_Service`** — computes, per supplier, average/fastest/slowest delivery time and completed-order sample count from posted Goods Receipts linked to fully-`received` Purchase Orders. One grouped aggregate SQL query, no N+1, proven at 10/40/200-supplier scale. Nothing is ever persisted — every call recomputes from current Purchase Order / Goods Receipt / Receipt Line data, the same "derived, not stored" pattern already established by Inventory Position and Expected Delivery.
- **Correctly handles partial deliveries:** when a supplier ships one order in several shipments, the lead time is measured to the *last* shipment — the one that actually completed the order — never the first partial delivery, which would systematically understate lead time for every supplier who ships in batches.
- **Read-only "Observed Lead Time" panel** on the Supplier admin screen (Purchasing → Suppliers), displayed alongside the existing merchant-editable "Default Lead Time (days)" field so planned and actual can be compared at a glance. Below 2 completed orders, shows "not enough data yet" rather than a misleadingly precise figure.
- **Internal, not a public API** — a deliberate architectural decision, not an oversight: no concrete external consumer exists yet (D16), so no `API_VERSION`-carrying interface is frozen prematurely. A future milestone may promote the service to a versioned public API without changing the computation it wraps, following the exact precedent `Expected_Delivery_Service` already set.
- **A real bug caught by its own test suite:** input validation for supplier IDs used `absint()`, which takes the absolute value of a negative number rather than rejecting it — a negative ID would have silently become a lookup for a different, positive supplier ID. Fixed before shipping.
- **Insertion-order independence, explicitly tested:** a dedicated test proves the computed lead time depends only on each receipt's `posted_at` timestamp, never on its database row ID or insertion order — guarding against a subtle implementation shortcut that would pass every "normal" fixture while silently producing wrong answers whenever insertion order and chronological order diverge.

**Testing:** 26 new tests. Unit suite 226 tests / 1,486 assertions; M1–M9-focused (CI-blocking) suite 476 tests / 2,373 assertions; full integration suite 261 tests / 930 assertions, 0 errors, 0 failures, 0 skips. All suites 0 failures except the same 7 pre-existing, documented risky `Test_DB_Transaction` tests unrelated to M9.

**Explicitly not in this release:**

- No schema change of any kind — `DB_VERSION` stays `10`.
- No new settings, no new hooks or filters, no new public API surface.
- No change to Purchase Order creation's expected-date suggestion, Inventory Position, or Expected Delivery — the new service is purely additive and consumed by nothing else in the platform.
- No supplier merge tool, supplier analytics, or reliability scoring — named in the same backlog entry as this milestone's feature but deliberately left for their own future milestones.
- No median statistic — average/fastest/slowest/completed-order count is the complete, deliberately final set for this milestone.
- No REST/Store API/GraphQL expansion, no forecasting, no inventory-coverage wiring, no purchasing recommendations — all explicitly out of scope.

## Install / upgrade

1. Download **`wc-inventory-overview-1.26.0.zip`** from this release.
2. Upload via **Plugins → Add New → Upload**, or use **Dashboard → Updates** on production.
3. No schema step — `DB_VERSION` stays `10`, no `ALTER` runs, no upgrade routine fires.
4. Merchant-visible change: a new read-only "Observed Lead Time" panel appears on each supplier's edit screen (Purchasing → Suppliers) once that supplier has at least one fully-received purchase order on record. Nothing else changes.

## Before tagging

Per [docs/release-runbook.md](release-runbook.md#m9-supplier-observed-lead-time-statistics): confirm `DB_VERSION` is still `10` and the schema assertion is `ok: true`; verify the Observed Lead Time panel against a manual `DATEDIFF()` spot-check on a real supplier; confirm the panel is read-only everywhere; confirm the architecture guard passes; confirm the full test suite (unit, M1–M9-focused, and integration) passes with zero failures; confirm Suppliers, Purchase Orders, Inventory Position, Goods Receipts, PO Receiving, batch migration CLI, and Storefront Expected Delivery all remain fully functional.

## Rollback

**M9 is code-only — as clean as M7/M8's rollback story, nothing to reverse.**

- **Code rollback 1.26.0 → 1.25.0:** unconditionally safe. M9 wrote no data, changed no schema, and mutated nothing anywhere in its surface (`Supplier_Lead_Time_Service` is read-only by construction, guard-enforced). Rolling back simply removes the new admin panel; every other screen is untouched.
- Historical Purchase Order and Goods Receipt data is untouched either direction — M9 only ever reads those tables.

See [docs/rollback-plan.md](rollback-plan.md) for the full explanation.

Changelog: [CHANGELOG.md](../CHANGELOG.md)
