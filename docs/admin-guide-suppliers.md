# Administrator Guide — Supplier Management

## Overview

The **Suppliers** section of the WC Inventory Overview plugin manages your supplier register — the source of truth for contact information, currency preferences, and lead-time defaults. Suppliers are the foundation for purchase orders and incoming inventory tracking.

This guide covers the Suppliers interface, daily workflows, and integration with the broader purchasing system.

## Accessing Suppliers

1. In the WordPress admin, navigate to **WooCommerce → Purchasing**.
2. Click the **Suppliers** tab.

You will see a list of all suppliers, organized by status (Active / Archived).

### Capabilities

- **Access**: Requires `manage_woocommerce` capability (typically Shop Manager or Administrator).
- **Actions**: Create, edit, archive, and reactivate suppliers.

## Supplier Lifecycle

### Create a Supplier

1. Click **Add New** (or **+ Add Supplier**).
2. Fill in the required fields:
   - **Name**: The supplier's display name (e.g., "Nature Supply AB"). This is how the supplier appears in purchase orders, incoming inventory, and reports.
   - **Default currency**: Choose EUR, USD, or SEK. This is the currency in which this supplier invoices you. It determines FX conversion at receipt time.
3. Optionally, fill in:
   - **Default lead time (days)**: The typical number of days from order to receipt. This is a fallback; individual purchase orders can override it with a specific date instead.
   - **Email**: The supplier's contact email.
   - **Phone**: The supplier's contact phone number.
   - **Supplier reference**: Your account or customer number at this supplier (e.g., "ACC-12345").
   - **Note**: Internal notes (e.g., "Minimum order: 50 units" or "Payment terms: net 30").
4. Click **Save Supplier**.

The supplier is created and immediately appears in the Active suppliers list, ready to be referenced in purchase orders and receiving workflows.

### Edit a Supplier

1. From the Suppliers list, click **Edit** on the supplier you want to change.
2. Update any field except **Status** (which is read-only and changed only via archive/reactivate).
3. Click **Save Supplier**.

**Note:** Editing a supplier (name, currency, lead time, contact details) affects only *future* purchase orders and reports. Historical receipts and movements retain their supplier snapshots at the time they were recorded.

### Archive a Supplier

Archiving hides a supplier from the active list and from autocomplete suggestions (e.g., when creating purchase orders). It does not delete historical records — all past purchase orders, receipts, and movements remain intact and still link to the archived supplier.

1. From the Suppliers list, click **Archive** on the supplier (shown only for active suppliers).
2. Confirm the action.

**When to archive:**
- The supplier is no longer used (no new orders expected).
- The business relationship has ended.
- Duplicate or merged suppliers (consolidate into one, archive the old one).

Archived suppliers remain fully searchable and accessible in historical reports; they simply don't appear in active purchasing workflows.

### Reactivate a Supplier

If you archive a supplier by mistake, or if you later restart a relationship:

1. From the Suppliers list, filter by **Archived** to see archived suppliers.
2. Click **Reactivate** on the supplier.

The supplier returns to the Active list and is available for new purchase orders immediately.

---

## Supplier Autocomplete

When creating or editing a purchase order (or entering a supplier in older workflows like Batch Intake), you will see a **supplier search field** with a dropdown arrow.

### Using the Autocomplete

1. Click the search field or start typing the supplier's name.
2. The list filters to show matching active suppliers.
3. Click a supplier name to select it.
4. The supplier is filled in and the dropdown resets, ready for the next action.

**Tip:** The search is **name-based** and **case-insensitive**. Typing "nature" will match "Nature Supply AB".

### Creating a Supplier Inline

If you need a new supplier and don't want to navigate away:

1. In the supplier search field, type the new supplier's name.
2. Click **+ Add Supplier** (shown below the search box if no match is found).
3. A mini form appears with **Name** and **Default currency** fields (required).
4. Select the currency and click **Create**.
5. The new supplier is created and automatically filled into the search field.

You can edit the new supplier later to add lead time, contact details, and notes.

**Note:** Inline supplier creation sets only the name and currency. For contact details and lead time, edit the supplier from the Suppliers list after creation.

---

## Supplier Fields Reference

### Required Fields

| Field | Purpose | Example |
|---|---|---|
| **Name** | Display name for the supplier | "Nature Supply AB" |
| **Default currency** | Invoice currency (EUR, USD, or SEK) | EUR |

### Optional Fields

| Field | Purpose | Example |
|---|---|---|
| **Default lead time (days)** | Typical delivery time (days from order to receipt). Used as a fallback for purchase orders if not overridden. | 14 |
| **Email** | Supplier's contact email | contact@naturesupply.eu |
| **Phone** | Supplier's contact phone | +46 (0)8 123 456 |
| **Supplier reference** | Your account/customer number at the supplier | ACC-2024-001 |
| **Note** | Internal notes (payment terms, minimums, quirks, etc.) | "Minimum order: 25 units. Net 30 terms." |
| **Status** | Active or Archived (read-only; use Archive/Reactivate buttons to change) | Active |

---

## Currency Selection

Each supplier is assigned a **default currency** that indicates the currency in which they invoice you.

### Supported Currencies

- **EUR** (Euros)
- **USD** (US Dollars)
- **SEK** (Swedish Kronor)

### Why This Matters

- At receipt time, costs are converted from the supplier's currency to EUR at the exchange rate captured that day.
- Purchase orders inherit the supplier's default currency but can override it per order if needed.
- All internal valuation and reporting use EUR; the supplier currency is used only for FX conversion.

### Changing a Supplier's Currency

If a supplier changes their invoicing currency:

1. Edit the supplier and change the **Default currency** field.
2. Save.

**Note:** This applies only to *future* purchase orders and receipts. Historical records retain their original currency (EUR equivalent is immutable).

---

## Lead Time — Configured, Not Observed

The **Default lead time (days)** field is a **configured fallback** — a number you enter based on the supplier's typical delivery schedule. It is used:

- When creating a new purchase order for this supplier, the system suggests this lead time to estimate the expected receipt date.
- In future planning features, it will inform inventory coverage calculations.

**Current milestone limitations:**

- **Lead time is never computed automatically** (no observed statistics yet). It is always the number you enter.
- Observed lead-time statistics (average, fastest, slowest, completed orders) are a future feature (planned for a later milestone). For now, maintain the default lead time manually based on your experience with the supplier.

### Best Practice

Set the **Default lead time** to a conservative middle value:
- Too low: purchase orders may arrive late, causing stock-outs.
- Too high: you over-order early, tying up capital.
- Example: If a supplier usually arrives in 10–14 days, set the default to 12 days.

---

## Platform Status

As of v1.24.0 (Milestones M0–M7 complete — see
[`docs/ARCHITECTURE_BASELINE_v1.24.0.md`](ARCHITECTURE_BASELINE_v1.24.0.md)),
the purchasing platform built on top of Suppliers is fully shipped:

### What Is Available Now

✓ Supplier register with contact and currency information
✓ Supplier archive/reactivate lifecycle
✓ Supplier autocomplete in purchasing workflows
✓ Inline supplier creation
✓ Configured lead-time default
✓ **Purchase Orders** (M2): full creation, editing, and lifecycle tracking — WooCommerce → Purchasing → Purchase Orders
✓ **Inventory Position** (M3): live On Hand / Incoming / Position figures per item, driven by each supplier's open PO lines
✓ **Goods Receipts** (M4/M5): receiving against a PO or directly, with automatic PO status updates
✓ **Storefront Expected Delivery** (M7): customer-facing "Expected back around …" text, derived from each supplier's confirmed expected dates

### Not Yet Available

The following remain deferred to a future milestone:

- **Lead-time statistics**: Observed average/minimum/maximum delivery times (computed from actual receiving history) — the configured lead time is today's fallback.
- **Supplier analytics**: Spend analysis, reliability scoring, and order history reporting.
- **Supplier merge tool**: Consolidating duplicate suppliers into one record is a manual process for now; a dedicated merge tool is planned.

---

## Best Practices for Administrators

### Naming Convention

Keep supplier names **consistent and unambiguous**:
- ✓ Good: "Nature Supply AB", "VitaTrade GmbH", "Nordic Chemicals"
- ✗ Avoid: "Supplier 1", "NS", "Nature" (too generic; hard to distinguish from similar suppliers)

Consistency helps with:
- Autocomplete matching (fewer duplicates)
- Historical reporting (names remain meaningful in old orders)
- Team communication (everyone knows which "Nat" you mean)

### Archiving vs. Deleting

- **Archive**: Hides the supplier from active lists and autocomplete, but keeps all historical records intact.
- **Delete**: Not available. Use archive instead.

Archive suppliers when they're no longer used; never delete them. Historical integrity is permanent.

### Bulk Operations

For now, supplier management is one-at-a-time. If you need to:
- Bulk-archive inactive suppliers: Use the list filters and action buttons individually.
- Consolidate duplicates: Manually archive the duplicate; historical records on the archived supplier remain intact.
- Import suppliers from an external system: Not yet automated; manually create suppliers or contact support for assistance.

### Currency Accuracy

Before creating a purchase order, **verify the supplier's currency setting**:
- If you set it to USD but the supplier invoices in EUR, your costs will be incorrectly converted.
- Always confirm the supplier's actual invoicing currency before saving.

---

## Troubleshooting

### "A supplier named X already exists"

**Cause:** A supplier with the same name (after trimming whitespace and converting to lowercase) already exists.

**Solution:** Either:
- Edit the existing supplier instead of creating a duplicate.
- Use the Suppliers list to find and click **Edit** on the supplier.

### Supplier doesn't appear in autocomplete

**Cause:** The supplier is archived.

**Solution:**
1. Go to the Suppliers list and filter by **Archived**.
2. Click **Reactivate** on the supplier.
3. The supplier will now appear in autocomplete suggestions.

### "Supplier not found" error when viewing an old order

**Cause:** The supplier was deleted (or archived and historical records are orphaned). This should not happen under normal circumstances.

**Solution:**
1. Check the Suppliers list (including archived) for the missing supplier.
2. If it's archived, reactivate it.
3. If it's truly missing, a backup restore or contact with support may be necessary.

---

## Related Documentation

- [Architecture v1.0](../CLAUDE.md) — detailed design of the Suppliers entity and purchasing system.
- [Delivery Roadmap v1.0](../CLAUDE.md) — timeline for upcoming purchasing features (Purchase Orders, PO Receiving, etc.).
- [Milestone M1 Implementation Plan](../milestones/m1-implementation-plan.md) — technical details of the Suppliers feature.

---

## Contact & Feedback

For questions, issues, or feature requests related to Supplier management, please refer to the project's issue tracker or contact your development team.

Last updated: Milestone M1 (v1.18.0)
