# WC Inventory Overview 1.42.0 — Replenishment Planning & Bulk Draft PO Creation

**Release Date:** August 2026  
**Plugin Version:** 1.42.0  
**Database Version:** 11 (unchanged from v1.40.0)

## Overview

This release completes the replenishment workflow started in v1.40.0. You can now review every item that currently needs reordering in one Planning screen, then create supplier-grouped **Draft** Purchase Orders from the lines you select — with quantities you control.

**You stay in control.** Nothing is ordered automatically. Drafts are created only when you explicitly confirm. Existing Purchase Order screens and the full place/receive workflow are unchanged.

> **Note:** Development version `1.41.0` (Planning screen alone) was never published. This train ships once as **v1.42.0**.

## What's new

### Replenishment Planning (M24)

- **Planning tab** under Purchasing — a catalog-wide (or selection-scoped) read-only plan of current needs-reorder items.
- **Grouped by supplier** with each supplier’s currency shown separately (never blended).
- **Suggested quantities** from your configured default replenishment quantity when set; otherwise shown as zero for you to decide.
- **Unresolved section** when no single eligible supplier can be determined (no history, or multiple possible suppliers).
- **Scoped planning** from Inventory Overview via a bulk action that opens Planning for the selected items only.

### Bulk Draft PO Creation (M25)

- On the same Planning tab, users who can edit Purchase Orders see **checkboxes** (default unchecked) and **editable quantities**.
- **Create Draft Purchase Orders** builds one Draft PO per resolved supplier group from your selection.
- The server **always re-checks** needs-reorder status, supplier, and product identity at confirm time — the browser only submits which lines you selected and the quantities you entered.
- **Duplicate / concurrent protection** for this workflow: same-form replay is blocked; overlapping commits on the same item are serialized; items that already have a draft/placed/partially-received PO line are skipped rather than duplicated.
- **Partial success:** if one supplier group cannot be created, other groups in the same confirm can still succeed. You see a clear created / failed / skipped summary.
- Drafts keep **unit cost 0** and **no invented expected date**; currency comes from the supplier; each PO notes it was created from Replenishment Planning.

## Why this matters to you

- See the full replenishment picture before creating any PO.
- Turn a reviewed plan into real Draft POs without re-typing lines per supplier.
- Keep the existing PO review / place / receive process as the path to stock.

## Visibility

- Viewing Planning requires Purchase Order view access.
- Creating Draft POs from Planning requires Purchase Order edit access. View-only users still see the read-only plan.

## What to expect after upgrading

- No database change — `DB_VERSION` stays at `11`. No migration runs.
- No automatic ordering and no background jobs for purchasing.
- Existing Purchase Orders, suppliers, and Inventory Overview behavior remain as in v1.40.0; Planning and bulk draft creation are additive.

## Compatibility

- Requires PHP 7.4+
- Requires WordPress 6.0+
- Compatible with WooCommerce 6.0–8.5+
- HPOS-compatible (High-Performance Order Storage)

## Upgrade notes

Back up as you normally would, then upgrade. Because there is no schema change, this is a plain code update. Intermediate development version `1.41.0` was never tagged or published.

## Known limitations

- Advisory locks used during bulk commit serialize other bulk commits on the same catalog items; they do not lock out unrelated stock or PO edits made outside this workflow.
- A deliberate later reorder after a prior draft has been received/cancelled/closed_short remains allowed when the item again needs reordering — that is intentional.
