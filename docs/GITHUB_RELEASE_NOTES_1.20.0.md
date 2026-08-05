# WC Inventory Overview 1.20.0

**Canonical standalone release** from [magpern/wc-inventory-overview](https://github.com/magpern/wc-inventory-overview).

## Prerequisite

Upgrade from **1.19.1** (M2 test-infrastructure hotfix, schema v7).

## What changed

**Milestone M3 — Inventory Position** — a first-class, read-only Inventory Position (`{On Hand, Incoming, Position}`, D11) for every simple product and variation, surfaced on Inventory Overview. **No schema change, no migration** — `DB_VERSION` remains **7**.

- **Inventory Position Resolver + Service**: the sole authoritative calculator (D12) — `Position = On Hand + Incoming` — used identically for single-item and bulk reads, with no per-row (N+1) queries.
- **Bulk open-line repository reads** for products and variations: two separate, safely-prepared queries qualifying on PO header `status = placed`, reusing the existing delayed-line predicate.
- **Incoming and Position columns** on Inventory Overview, gated to `manage_woocommerce` (no new capability).
- **Per-supply drill-down**: each contributing open PO line renders independently — PO number/link, outstanding quantity, expected date, confidence, delayed indication — never merged (INV-1/INV-7).
- **Variable-parent presentation rollup**: parent Incoming/Position are a presentation-only sum of child-variation figures; no incoming record is ever created against a variable parent (INV-8).
- **Composable states**: low/out-of-stock badges, Incoming, Position, and delayed indication all display simultaneously.
- 44 dedicated tests (resolver, D12 architecture guards, repository, service, list-table integration, query-scaling).

**Explicitly not in this release:**

- No receiving, no Goods Receipts, no stock/cost mutation, no `qty_received` — M3 is entirely read-only (M4/M5 will extend the Incoming formula once receiving exists).
- No Supplier or Purchase Order business-logic changes.
- No new REST/AJAX/admin-post surface, no new capability.

## Install / upgrade

1. Download **`wc-inventory-overview-1.20.0.zip`** from this release.
2. Upload via **Plugins → Add New → Upload**, or use **Dashboard → Updates** on production.
3. `DB_VERSION` remains **7** — no schema migration runs on this upgrade.

## Before tagging (read-only, non-schema release)

Per [docs/release-runbook.md](release-runbook.md): confirm CI (unit, M1/M2/M3 blocking, and M3-only suites) passes, confirm `DB_VERSION` unchanged at `7`, confirm Incoming/Position columns render on Inventory Overview and drill-down/roll-up behave as documented in `docs/architecture-audit.md`.

## Rollback

Restore prior plugin folder from backup (**1.19.1**). No schema change to reverse — 1.19.1 and 1.20.0 share `DB_VERSION` 7. See [docs/rollback-plan.md](rollback-plan.md).

Changelog: [CHANGELOG.md](../CHANGELOG.md)
