# WC Inventory Overview v1.33.0

**Release:** PO Expected-Date & Delay Transparency (M16)  
**Release Date:** 2026-08-11  
**Schema Version:** 10 (unchanged)  
**Database Migration:** None

## Overview

Milestone M16 introduces three focused read-mostly surfaces for improving transparency around expected delivery dates and purchase order delays. No schema changes, no domain-level mutations, and zero impact on purchase order, goods receipt, or inventory position business logic.

## Features

### A. PO Expected-Date Suggestion Provenance

When creating a new Purchase Order, the pre-filled Expected Date suggestion now displays its source:

- **Observed delivery history:** "Suggested from this supplier's delivery history (N orders, avg D days)." — drawn from the supplier's historical on-time performance tracked across closed purchase orders, including the sample size and average historical days.
- **Configured supplier default:** "Suggested from configured supplier default (D days)." — falls back to the supplier's configured lead-time setting when no historical data exists.
- **No source available:** No suggestion message appears when neither observed nor configured defaults are available.

The suggestion remains fully advisory — merchants may freely override the expected date when creating the PO, and once manually edited, the hint disappears and is not restored on future edits.

**Implementation details:**
- `Expected_Date_Suggestion_Service` returns `sample_count` and `average_days` (both `null` when source is not `observed`)
- Both values derive from existing supplier statistics; no additional queries are performed
- The suggestion algorithm itself is unchanged; only the transparency layer is new

### B. PO Delay Grace-Days Setting

A new Settings field under the **Purchasing** section allows configuration of the purchase order delay grace period:

**Field:** "PO delay grace period (days)"  
**Valid range:** 0–365 (both inclusive; 0 is a valid grace period)  
**Behavior:**
- Invalid or missing input always **preserves the existing stored value** — no silent coercion to a default
- Non-numeric values, decimal numbers, scientific notation, and out-of-range values (`<0` or `>365`) leave the setting untouched
- A clean integer within 0–365 is saved exactly as submitted

**Implementation details:**
- Exposes the existing `WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS` option through the UI
- Uses `Settings::maybe_save_po_delay_grace_days()` with strict validation (no `absint()`-style coercion)
- Delay calculation itself is unchanged; this is a UI exposure of an existing, already-functional setting

### C. Inventory Position Drilldown Columns

When drilling down into the expected-delivery details for a line item in the Inventory Position grid, the detailed supply list now includes two additional columns:

**New columns:**
1. **Supplier** — the supplier name at the time the purchase order was placed (immutable snapshot, survives supplier archive/rename)
2. **Status** — the purchase order status (draft, placed, partially received, received, cancelled, closed short)

**Column order (fixed):**
1. PO number
2. Supplier
3. Status
4. Outstanding
5. Expected date
6. Confidence
7. Delayed

**Implementation details:**
- Both columns source from the existing `Purchase_Orders` query; no additional queries are performed
- Supplier uses the historical `supplier_name_snapshot` column to ensure deleted/archived suppliers do not break the display
- Status uses the canonical `PO_Statuses::label()` formatter (the same label map governing the Purchasing screens)

## What Did NOT Change

- **No schema change** — `DB_VERSION` remains at 10; all new data is presentation-time derived
- **No database migration** — no new columns, no table changes, no schema upgrade step
- **No PO/receipt/stock/cost mutations** — one existing settings-option write only (`po_delay_grace_days`)
- **No new public API** — all new services are Internal (D16); no public hooks or filters
- **No new capability** — existing `manage_woocommerce` gate governs all access
- **No storefront impact** — expected-delivery rendering remains unchanged; customers see no difference
- **Backward compatible** — the suggestion provenance fields are optional in the return shape; merchants relying on just `days` and `source` see no change

## Rollback

Because `DB_VERSION` remains 10 and no schema migration occurs, rollback is code-only:

1. Deactivate v1.33.0
2. Activate v1.32.0
3. No database cleanup, restoration, or reversal required
4. The grace-days option value may remain stored while v1.32.0 is active; it is safe and expected

## Testing

- ✅ All unit tests pass (362 tests / 1916 assertions)
- ✅ M1–M16 focused suite passes (667 tests / 3126 assertions)
- ✅ Integration suite passes (316 tests / 1252 assertions)
- ✅ Architecture guards pass (sole-mutator, query-count, schema-shape assertions)
- ✅ Performance baseline maintained (expected-date suggestion: 1 query regardless of supplier count; position drilldown: ≤2 queries)
- ✅ PHP lint, parallel lint, and build verification pass

## Related Documentation

- **Implementation Plan:** [`docs/milestones/m16-implementation-plan.md`](../milestones/m16-implementation-plan.md)
- **Release Readiness:** [`docs/checklists/m16-release-readiness.md`](../checklists/m16-release-readiness.md)
- **Rollback Plan:** [`docs/rollback-plan.md`](../rollback-plan.md)
- **Admin Guide — Suppliers:** [`docs/admin-guide-suppliers.md`](../admin-guide-suppliers.md) (Suggestion Provenance section)
- **Admin Guide — Purchase Orders:** [`docs/admin-guide-purchasing-purchase-orders.md`](../admin-guide-purchasing-purchase-orders.md)
- **Validation Checklist:** [`docs/checklists/validation-checklist.md`](../checklists/validation-checklist.md)
