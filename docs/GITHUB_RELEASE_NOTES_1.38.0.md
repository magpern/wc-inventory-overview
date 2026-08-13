# WC Inventory Overview 1.38.0 — Position-Aware Reorder Signal

**Release Date:** August 2026
**Plugin Version:** 1.38.0
**Database Version:** 11 (unchanged from v1.37.0)

## Overview

This release adds a new **Reorder Signal** to the plugin's existing Low Stock indicator. Until now, "Low stock" only looked at current on-hand quantity — a product could show as low stock even though a purchase order already on its way would cover it. This release tells you, for every low-stock line, whether it's already covered by incoming supply or still genuinely needs a reorder.

**Every screen you already use looks the same, with new information added alongside.** No existing button, filter, badge, column, or workflow was removed or changed — the new signal appears next to the information you already had.

## What's new

- **"Needs reorder" and "Covered by incoming" badges** on the Inventory Overview screen, shown next to the existing Low Stock badge for any line that's already low — never in place of it. For variable products, each variation is judged on its own; the parent row shows how many of its variations need reordering.
- **New "Needs Reorder" summary card** on the Inventory Overview screen.
- **New "Needs Reorder" KPI** on the Dashboard, alongside the existing Low Stock Items KPI.
- **Two new columns** — Incoming and Reorder status — on the Dashboard's "Recent Low Stock Items" table.

## Why this matters to you

- **Fewer false alarms.** If a purchase order is already inbound and large enough to cover a low-stock line, you'll see "Covered by incoming" instead of an unqualified low-stock warning — so you don't reorder something you've already reordered.
- **A clearer to-do list.** The new "Needs Reorder" counts (on both the Dashboard and Inventory Overview) show only the lines that genuinely need attention, separate from the broader Low Stock count.
- **Nothing is removed.** The existing Low Stock badge, count, and behavior are exactly as before, on every screen.
- **No purchasing action happens automatically.** This release only shows information — it never creates, modifies, or suggests a purchase order on your behalf.

## Visibility

The new Reorder Signal information (badges, cards, KPI, and columns) is visible to users who can manage WooCommerce (Shop Manager / Administrator). If your account only has product-editing access, you'll continue to see the Inventory Overview and Dashboard screens exactly as they looked before this release.

## What to expect after upgrading

- No database change — `DB_VERSION` stays at `11`. The upgrade is a plain code swap with nothing to migrate.
- No new settings to configure and no migration step to run.
- Every existing screen, filter, bulk action, inline stock edit, CSV export, restock form, and cost adjustment behaves exactly as it did in v1.37.0.

## Compatibility

- Requires PHP 7.4+
- Requires WordPress 6.0+
- Compatible with WooCommerce 6.0–8.5+
- HPOS-compatible (High-Performance Order Storage)

## Upgrade notes

Because this release changes no schema and no stored data, it carries the same low-risk profile as a typical plugin update: back up as you normally would before any update, then upgrade normally.

## Known limitations

The Reorder Signal is based on your configured low-stock threshold and currently open purchase orders — it does not forecast future demand or sales velocity. Creating a purchase order directly from a "Needs reorder" line is not included in this release and may be considered for a future update.

## Additional information

For questions or issues, see the plugin's **Settings** tab and the built-in admin help sections.

---

**This release is a standalone milestone (M21)**, following the same release pattern used for M16 (`v1.33.0`), M17 (`v1.34.0`), and M20 (`v1.37.0`). It builds directly on `v1.37.0`. Previous releases (M0–M8 GA, M9–M12 feature train, M13–M15 feature train, M16, M17, M18–M19 feature train, M20) remain in their respective version tags.
