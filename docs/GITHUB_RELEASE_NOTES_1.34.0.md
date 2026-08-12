# WC Inventory Overview 1.34.0 — Supplier Merge

**Release Date:** August 2026  
**Plugin Version:** 1.34.0  
**Database Version:** 11 (migrated from v10)

## Overview

This release introduces Supplier Merge — a controlled operation to consolidate redundant or duplicate supplier records while preserving all associated purchasing history, costs, and audit trails.

## New Feature: Supplier Merge

### What is Supplier Merge?

Suppliers can now be merged into another active supplier. This consolidation operation:

- **Reassigns all Purchase Orders** from the source supplier to the target supplier
- **Reassigns all Goods Receipts** from the source supplier to the target supplier
- **Preserves historical snapshots** — supplier name at the time of order/receipt remains unchanged
- **Preserves inventory movements** — all cost records, landed-cost allocations, and stock movements remain intact
- **Does not overwrite target metadata** — the target supplier's name, lead time, and other settings are never modified by the merge
- **Permanently dissolves** the source supplier — it becomes archived and cannot be reactivated
- **Records audit history** — every merge operation is logged with source/target, reassigned counts, timestamp, and who performed it

### Using Supplier Merge

1. Navigate to **Purchasing → Suppliers**
2. Click the supplier name you wish to merge (source supplier)
3. If eligible, a "Merge Supplier" button appears on the supplier detail screen
4. Select a target supplier from the dropdown
5. Review the warning (the operation is irreversible)
6. Type the confirmation text to enable the submit button
7. Click "Merge"

The system verifies your confirmation server-side and processes the merge atomically.

### What Moves, What Stays

| Item | Behavior |
|---|---|
| **Purchase Orders** | Reassigned to target supplier; supplier snapshots remain unchanged |
| **Goods Receipts** | Reassigned to target supplier; supplier snapshots remain unchanged |
| **Inventory Movements** | Remain tied to their original costs/receipts; stock figures and valuations are unaffected |
| **Expected Delivery Dates** | Follow reassigned POs; end-customer messaging is unchanged |
| **Supplier Spend/Stats** | Reflect reassigned PO ownership; historical totals transfer |
| **Source Supplier** | Archived; cannot be reactivated; cannot be selected for new operations |

### Eligibility Rules

- **Source supplier** must be active (not already merged or archived)
- **Target supplier** must be active (not merged, archived, or the source itself)
- A supplier cannot be selected if it has already been merged into another supplier

### Irreversible Operation — Important

⚠️ **CODE ROLLBACK DOES NOT UNDO COMPLETED SUPPLIER MERGES.**

A completed supplier merge is irreversible at the product level by design. If you rollback plugin code from v1.34.0 to v1.33.0:

- The schema additions (new columns, tables) remain in your database
- Previously completed merges remain — reassigned POs/GRs stay reassigned
- The dissolved source supplier remains archived
- There is **no automatic "undo merge" feature** in the plugin

**Recommendation:** Take a database backup before your first supplier merge in production. In the unlikely event you need to undo a completed merge, restore the database backup; do not attempt manual SQL corrections on uncertain state.

### Server-Side Security

The merge operation:
- Requires explicit server-side confirmation of the selected target
- Uses nonces/tokens to prevent CSRF
- Enforces atomic transactions — if any part fails, all changes roll back
- Protects against concurrent new Purchase Order/Goods Receipt creation against a dissolving supplier
- Is logged in the audit trail

## Database Schema Changes

### Version Migration (v10 → v11)

The `wc_io_db_version` option is automatically upgraded to `11` on plugin activation or update.

**New Column:**
- `wc_io_suppliers.merged_into_supplier_id` (nullable INT) — tracks which supplier this one was merged into

**New Table:**
- `wc_io_supplier_merges` — audit log of all merge operations
  - Columns: `id`, `source_supplier_id`, `target_supplier_id`, `purchase_orders_reassigned`, `goods_receipts_reassigned`, `performed_by_user_id`, `performed_at`

All historical data is preserved; no data is deleted during the schema upgrade.

## Compatibility

- Requires PHP 7.4+
- Requires WordPress 6.0+
- Compatible with WooCommerce 6.0–8.5+
- HPOS-compatible (High-Performance Order Storage)

## Known Limitations

- A supplier can only be merged once (into one target); subsequent merges of that target are not supported within the same merge operation
- The merge operation requires a network roundtrip; very large operations (1,000s of POs/GRs) may take several seconds
- Concurrent new PO/GR creation against a dissolving supplier is protected, but timing is tight — avoid manual splits during an ongoing merge in production

## Upgrade Notes

**Before upgrading to v1.34.0 in production:**

1. Take a full database backup (recommended for all updates, mandatory if you plan to use the merge feature)
2. Test the merge feature in a staging environment first
3. Document your supplier hierarchy before performing any merges

**After upgrade:**

1. Verify the schema upgrade completed: check **Settings** for the new "Supplier Merge" confirmation option
2. Test the Supplier Merge workflow on a disposable test supplier pair before using it on production data
3. The merge feature is available immediately after activation; no additional configuration is needed

## Additional Information

For questions or issues, see the plugin's **Settings** tab and the built-in admin help sections.

---

**This is a standalone release.** Previous releases (M0–M8 GA, M9–M12 feature train, M13–M15 feature train, M16 PO Expected-Date & Delay Transparency) remain in their respective version tags.
