# Administrator Guide — Purchase Orders

## Overview

The **Purchase Orders** tab (WooCommerce → Purchasing → Purchase Orders) manages purchasing commitments to your suppliers — what you've ordered, at what price, expected when. A Purchase Order (PO) never touches WooCommerce stock by itself; stock and cost only change when a Goods Receipt is posted against it. See `docs/admin-guide-suppliers.md` for supplier setup and `CLAUDE.md` for the full purchasing architecture.

This guide covers printing a Purchase Order (Milestone M13). For creating, placing, receiving, and closing POs, see the in-screen help and the existing PO detail screen fields.

## Printing a Purchase Order

Every Purchase Order can be printed as a clean, standalone document — useful for sending to a supplier, handing to a warehouse team, or keeping a paper/PDF record.

### How to print

1. Open the Purchase Order's detail screen (Purchasing → Purchase Orders → click the PO).
2. Click **Print** in the Actions section.
3. A standalone printable page opens in a new tab, showing the store name, PO number, status, dates, currency, supplier details, every line item, and the PO total.
4. Click the on-screen **Print** button, or use your browser's own Print command (Ctrl/Cmd+P) — both open your browser's native print dialog, where **Save as PDF** is available like any other page. The plugin does not generate or store a PDF file itself; the button is a convenience, not a requirement — the page prints correctly even with JavaScript disabled.

### When Print is available

The **Print** link appears for a PO in any of these statuses: **Placed**, **Partially Received**, **Received**, **Cancelled**, or **Closed Short**.

It does **not** appear for a **Draft** PO — a draft has not been placed or sent to the supplier yet, so there is nothing to print. Attempting to print a draft directly (e.g. by URL) is also blocked.

### What's on the page

- **Header**: your store name, PO number, status, order date, expected date and confidence, currency.
- **Supplier**: name, reference, email, phone (see note below on resilience).
- **Lines**: product, SKU, supplier SKU, quantity ordered, quantity received, unit price, line total.
- **Total**: the sum of every line's total, in the PO's own currency.

There are no tax, shipping, or discount fields — the Purchase Order data model doesn't track them, so the printable document doesn't invent them.

### Historical accuracy

Product names and SKUs on the printed page always come from what was recorded on the PO line at the time it was created (the same values already shown on the PO detail screen), never a live product lookup. If a product or variation is later deleted from your catalog, previously placed POs referencing it still print correctly.

Likewise, the supplier name on the printed page always comes from the PO's own record of the supplier at order time. If a supplier's contact details are no longer available for any reason, the PO still prints — the supplier name is shown, and the reference/email/phone fields are simply left off rather than causing an error.

### What printing does not do

Printing is entirely read-only. It never changes the PO's status, quantities, dates, or any other field, and it never affects stock, cost, or Inventory Position. Viewing the printable page requires the same permission as viewing the PO itself (Shop Manager / Administrator).

### Access control

Only users who can view Purchase Orders can print them, and each print link is valid only for its specific Purchase Order — a link for one PO cannot be reused to print a different one.
