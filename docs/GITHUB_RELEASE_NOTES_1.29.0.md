# WC Inventory Overview 1.29.0

**Canonical bundled release** from [magpern/wc-inventory-overview](https://github.com/magpern/wc-inventory-overview).

This release publishes the complete **M9–M12 Supplier Performance feature train** as a single version. Intermediate development bumps `1.26.0` / `1.27.0` / `1.28.0` were never tagged and are **not** published as separate GitHub Releases.

## Prerequisite

Upgrade from **1.25.0** (M8 Hardening & GA, schema v10).

## Overview

**v1.29.0** ships four post-GA milestones that improve supplier performance visibility and purchase-order planning, plus the CI recovery work that restored a genuinely green GitHub Actions baseline:

| Milestone | Capability |
|-----------|------------|
| **M9** | Supplier Observed Lead-Time Statistics |
| **M10** | Purchase Order Expected-Date Suggestion |
| **M11** | Supplier On-Time Delivery Rate |
| **M12** | Supplier List Performance Surface |

**Zero schema change across the entire train (`DB_VERSION` stays `10`).** No new public API, no new settings UI, no inventory/cost mutation, no storefront Expected Delivery semantic change.

## M9 — Supplier Observed Lead-Time Statistics

Read-only supplier delivery history computed from posted Goods Receipts linked to fully-`received` Purchase Orders:

- **Average**, **fastest**, and **slowest** observed lead time (calendar days)
- **Completed-order sample count**
- Displayed on the Supplier admin edit screen alongside the merchant-editable configured lead time
- Below 2 completed orders: “not enough data yet”
- Partial shipments measure lead time to the shipment that **completed** the order, not the first partial
- **Internal** sole-owner service (`Supplier_Lead_Time_Service`) — not a public API; one bulk aggregate query, no persistence, no N+1

## M10 — Purchase Order Expected-Date Suggestion

When creating a **new** Purchase Order and selecting a supplier, Expected Date / Confidence are pre-filled:

1. **Observed** average lead time when usable (≥ 2 completed orders)
2. Else **configured** default lead time
3. Else no suggestion

- Confidence is suggested as **Estimated** (never Exact)
- Advisory only — always editable; once the operator edits, the suggestion never overwrites again for that form (INV-M10-1)
- Does **not** run on the edit-PO screen
- Uses calendar days only; never writes its own persistence (ordinary form submit path only)

## M11 — Supplier On-Time Delivery Rate

Read-only reliability figure on the Supplier detail screen:

- Of completed orders with a **known** expected date (**Exact** or **Estimated**), what fraction were fully received on or before the deadline?
- **UNKNOWN** confidence (and missing dates) are **excluded** from both numerator and denominator — never assumed late or on-time
- Deadline = expected date + **grace days** (same option already used for live “Delayed” flagging)
- Shared policy primitive: `Expected_Deadline` (consumed by both historical on-time rate and live `PO_Delay`)
- Below 2 rated orders: “not enough data yet” (independent of observed-lead-time sample size)
- Still exactly **one** bulk statistics query (zero additional queries vs M9)

## M12 — Supplier List Performance Surface

Purchasing → Suppliers list gains two read-only columns after configured Lead Time:

- **Observed Lead Time**
- **On-Time Rate**

- Same usability thresholds as the detail panel; insufficient data shows an em dash (—)
- One `get_stats_bulk()` call per list page (no per-row statistics / no N+1)
- Configured Lead Time column unchanged; new columns not sortable

## CI recovery included

This release also includes the CI recovery work required for a trustworthy green GitHub Actions baseline:

- Deterministic MariaDB `DROP`/`CREATE` before each PHPUnit suite
- Fixed `Test_DB_Transaction` temporary-table leakage (0 risky tests)
- `release-audit.sh --development` / `--release` modes (feature-train compatible)
- Actions `checkout`/`cache` upgraded off Node 20 deprecation path

## Explicitly not included

- Spend analysis / order-history reporting
- Supplier merge tool
- Forecasting / coverage
- Warehouse locations
- Storefront Expected Delivery confidence changes
- Printable PO
- Grace-days Settings UI redesign
- Expected-date suggestion source UI
- Inventory Position supplier column
- Any other deferred roadmap work outside M9–M12

## Schema / data

- **`DB_VERSION` remains `10`**
- No migrations, no new tables/columns/indexes
- No persisted derived statistics — every M9/M11 figure is recomputed from operational PO/GR history
- No stock, cost, Goods Receipt lifecycle, or Inventory Position mutation from this train

## Install / upgrade

1. Download **`wc-inventory-overview-1.29.0.zip`** from this release.
2. Upload via **Plugins → Add New → Upload**, or use **Dashboard → Updates** where the GitHub updater is configured.
3. No schema step — `DB_VERSION` stays `10`; no `ALTER` runs; no upgrade routine fires for this release.
4. Merchant-visible additions: Observed Lead Time + On-Time Rate on supplier detail; list columns; new-PO expected-date suggestion. Everything else continues as in 1.25.0.

## Validation checklist

Per [docs/release-runbook.md](release-runbook.md) and [docs/checklists/feature-train-m9-m12-release-readiness.md](checklists/feature-train-m9-m12-release-readiness.md):

- Confirm `DB_VERSION` is still `10` and schema assertion is `ok: true`
- M9: Observed Lead Time panel matches a manual spot-check where history exists
- M10: new-PO suggestion prefers observed → configured; Estimated; edits latch; edit-PO unaffected
- M11: On-Time Rate excludes UNKNOWN; grace days agree with Delayed semantics
- M12: list columns agree with detail; insufficient data shows —; no list N+1
- Regression: Suppliers, POs, Goods Receipts, PO Receiving, Inventory Position, storefront Expected Delivery, Quick Restock, Cost Adjustment, Batch Migration CLI, PO Delay
- Full automated suites green (unit, M1–M12 focused, integration) with 0 risky

## Rollback

**The entire M9–M12 train is code-only relative to v1.25.0 — nothing to reverse in the database.**

- **Code rollback 1.29.0 → 1.25.0:** unconditionally safe. Remove the new panels/columns/suggestion prefill; purchasing, receiving, inventory, and storefront Expected Delivery continue to function.
- No schema/data cleanup is required.
- Historical PO/GR rows are untouched either direction.

See [docs/rollback-plan.md](rollback-plan.md).

Changelog: [CHANGELOG.md](../CHANGELOG.md)
