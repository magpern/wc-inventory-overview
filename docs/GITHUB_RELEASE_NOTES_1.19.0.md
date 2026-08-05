# WC Inventory Overview 1.19.0

**Canonical standalone release** from [magpern/wc-inventory-overview](https://github.com/magpern/wc-inventory-overview).

## Prerequisite

Upgrade from **1.18.1** (M1 Purchasing PRG hotfix on schema v6). If you are still on 1.18.0, apply 1.18.1 first, then 1.19.0.

## What changed

**Milestone M2 — Purchase Orders** (schema **v7**):

- Purchase Order aggregate: header, lines, append-only events table.
- Four-state lifecycle: draft → placed → cancelled | closed_short (terminal statuses absorbing).
- Never-reuse numbering: `PO-{YYYY}-{NNNN}` with database unique constraint (see ADR-0002 for concurrency notes).
- Expected receipt dates with confidence (exact / estimated / unknown) and delayed detection.
- **WooCommerce → Purchasing → Purchase Orders:** list, create/edit, event timeline, place/cancel/close short/duplicate/delete draft.
- Filterable purchasing capability map; request tokens on mutating actions.

**Explicitly not in this release:**

- No receiving, no `qty_received`, no Goods Receipt UI (M5).
- No WooCommerce stock or weighted-average cost changes from PO actions.
- No printable PO templates or print hooks.

No intentional changes to Batch Intake, Quick Restock, costing, FX, or movement semantics beyond M2 infrastructure.

## Install / upgrade

1. Download **`wc-inventory-overview-1.19.0.zip`** from this release.
2. Upload via **Plugins → Add New → Upload**, or use **Dashboard → Updates** on production.
3. On first load after upgrade, `DB_VERSION` bumps to **7** and schema-shape assertion runs automatically.

## Before tagging (schema-bumping release)

Follow [docs/release-runbook.md](release-runbook.md) **M2: Purchase Orders** section:

1. Confirm `DB_VERSION = '7'` after upgrade.
2. Verify `wp option get wc_io_schema_assertion --format=json` shows `ok: true` and `version: "7"`.
3. Confirm **WooCommerce → Purchasing → Purchase Orders** is available.
4. **No-stock-change check:** place/cancel/close-short a test PO; verify product `_stock` and `_wc_io_average_unit_cost` unchanged.

## Rollback

Restore prior plugin folder from backup (e.g. **1.18.1**). Schema v7 PO tables remain unless manually removed; they are inert without the new code. See [docs/rollback-plan.md](rollback-plan.md).

Changelog: [CHANGELOG.md](../CHANGELOG.md)
