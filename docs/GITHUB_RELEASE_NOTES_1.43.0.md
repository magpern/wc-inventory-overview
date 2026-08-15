# WC Inventory Overview 1.43.0 — Apply Replenishment Defaults to Variations

**Release Date:** August 2026  
**Plugin Version:** 1.43.0  
**Database Version:** 11 (unchanged from v1.42.0)

## Overview

This release adds **bulk Set/Clear** for replenishment defaults on a variable product’s variations, using WooCommerce’s existing variation bulk-edit workflow.

You can apply a preferred supplier and/or a default replenishment quantity to all child variations in one action — or clear those fields in bulk. Each variation still keeps its own values afterward and remains independently editable.

This is **copy/apply now**, not inheritance. There is no ongoing parent-to-variation link, and the variable parent itself does not receive these replenishment defaults from this workflow.

## What's new

### Variation bulk apply (M26)

- **Set preferred supplier** across all variations of the product being edited (choose from active eligible suppliers).
- **Clear preferred supplier** on all variations.
- **Set default replenishment quantity** across all variations.
- **Clear default replenishment quantity** on all variations.
- Actions appear in the classic WooCommerce **variation bulk-edit** menu on the product edit screen.
- After apply, each variation keeps its own meta and can still be edited individually (same as before).

### Product limit

- Bulk apply supports products with **at most 100 variations**. Products with more than 100 variations must have defaults edited per variation.

## What this does *not* do

- Does **not** create Purchase Orders.
- Does **not** change stock quantities or product costs.
- Does **not** invent parent-level replenishment defaults or inheritance rules.
- Does **not** change Planning or Bulk Draft PO behavior from v1.42.0 — those features continue to use each variation’s own stored defaults.

## What to expect after upgrading

- No database change — `DB_VERSION` stays at `11`. No migration runs.
- Existing preferred-supplier and default-quantity settings on products/variations are unchanged until you run a bulk action.
- Inventory Overview, Planning, and Draft PO workflows from earlier releases remain available.

## Compatibility

- Requires PHP 7.4+
- Requires WordPress 6.0+
- Compatible with WooCommerce 6.0–8.5+
- HPOS-compatible (High-Performance Order Storage)

## Upgrade notes

Back up as you normally would, then upgrade. Because there is no schema change, this is a plain code update.

## Roadmap note

**v1.43.0 completes the currently scheduled replenishment roadmap.** Further ideas (for example advisory concurrency UI, hiding unused parent-level fields, or forecasting) remain optional backlog only and are not scheduled as a next numbered milestone.
