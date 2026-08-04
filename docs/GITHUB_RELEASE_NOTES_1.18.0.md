# WC Inventory Overview 1.18.0

**Canonical standalone release** from [magpern/wc-inventory-overview](https://github.com/magpern/wc-inventory-overview).

## What changed

**Milestone M1 — Suppliers** (schema v6):

- First-class `wc_io_suppliers` entity with CRUD, archive/reactivate, normalization.
- New **WooCommerce → Purchasing** submenu with **Suppliers** tab (list, create, edit, search).
- Idempotent seed migration from historical supplier strings in batches and movements.
- Schema-shape assertion (`assert_schema_shape()`) gating the Purchasing menu.
- Supplier autocomplete on Batch Intake and Quick Restock (additive; legacy free-text fields unchanged).
- Action hook: `wc_io_supplier_created`.

No intentional changes to weighted-average costing, FX, batch allocation, or movement semantics beyond M1 infrastructure.

## Install / upgrade

1. Download **`wc-inventory-overview-1.18.0.zip`** from this release.
2. Upload via **Plugins → Add New → Upload**, or use **Dashboard → Updates** on production.

## Before tagging (schema-bumping release)

Follow [docs/release-runbook.md](release-runbook.md) **M1: Suppliers** section:

1. Confirm `DB_VERSION = '6'` after upgrade.
2. Verify schema-shape assertion passes on a production-data copy.
3. Review `wc_io_supplier_seed_migration_report` — no errors; batch/movement row counts unchanged.
4. Confirm **WooCommerce → Purchasing → Suppliers** is available for `manage_woocommerce` users.

## Rollback

Restore prior plugin folder from backup (e.g. **1.17.3** or **1.17.2**). Schema v6 tables remain unless manually removed; see [docs/rollback-plan.md](rollback-plan.md).

Changelog: [CHANGELOG.md](../CHANGELOG.md)
