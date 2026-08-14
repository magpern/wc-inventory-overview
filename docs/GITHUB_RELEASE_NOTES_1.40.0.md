# WC Inventory Overview 1.40.0 — Reorder-to-Draft Purchasing Workflow

**Release Date:** August 2026
**Plugin Version:** 1.40.0
**Database Version:** 11 (unchanged from v1.38.0)

## Overview

This release closes the loop on the plugin's existing "Needs reorder" signal (v1.38.0): a low-stock item that genuinely needs reordering can now go straight to a draft Purchase Order, prefilled from the supplier information the plugin already knows about it — with an optional layer of merchant-set defaults to make repeat purchasing even faster.

**You stay in control at every step.** Nothing is ever ordered automatically. The new "Create Draft PO" action only opens the existing New Purchase Order screen with some fields pre-filled; you review, edit anything you like, and submit it yourself exactly as you always have.

## What's new

- **"Create Draft PO" quick action** — on any Inventory Overview row already showing "Needs reorder," a new button takes you straight to a New Purchase Order form for that item, with the product and (where clear) a supplier already selected.
- **Automatic supplier suggestion from purchase history** — if an item has been ordered from exactly one supplier before, that supplier is pre-selected. If it's been ordered from more than one, or never, you'll see a short note and pick one yourself — nothing is guessed.
- **Preferred supplier** — you can now set a specific "go-to" supplier for any product or product variation, on the product's own edit screen. When set, it's used directly instead of the purchase-history guess. If that supplier is later archived or merged into another, the form safely falls back to the purchase-history behavior and lets you know why — your saved preference is never silently lost.
- **Default replenishment quantity** — you can also set a standard reorder quantity for any product or variation, so the New PO form starts with the amount you usually order instead of a plain default of 1. You can always change it before submitting.
- **Product and variation defaults are independent.** Each variation keeps its own preferred supplier and quantity — setting one on a variation never affects its parent product or sibling variations.

## Why this matters to you

- **Fewer clicks for repeat purchasing.** Once you've set a preferred supplier and usual quantity for your regular items, reordering them is close to a two-click action: click "Create Draft PO," review, submit.
- **Nothing happens without your review.** The quick action never creates a Purchase Order by itself — it only opens the existing form, pre-filled. Every draft is still created only when you submit the form, through the exact same validated purchasing pipeline as before.
- **Safe by default.** An out-of-date preferred supplier (archived or merged) is never silently used — you'll see a clear notice and a sensible fallback instead.
- **Nothing is removed.** Every existing Purchase Order, Supplier, and Inventory Overview screen behaves exactly as it did in v1.38.0, with this new action added alongside.

## Visibility

The "Create Draft PO" quick action is visible to users who can manage Purchase Orders (the same access level already required to create one manually). The new preferred-supplier and default-quantity fields on the product edit screen are visible to anyone who can edit that product — the same access level WooCommerce already uses for all other product fields.

## What to expect after upgrading

- No database change — `DB_VERSION` stays at `11`. The upgrade is a plain code swap with nothing to migrate. The two new settings (preferred supplier, default quantity) are stored as ordinary product data, the same way WooCommerce stores any other product field.
- No new settings to configure and no migration step to run. Every existing screen, filter, bulk action, and workflow behaves exactly as it did in v1.38.0 until you choose to set a preferred supplier or default quantity somewhere.
- Existing Purchase Orders, Suppliers, and their history are completely unaffected.

## Compatibility

- Requires PHP 7.4+
- Requires WordPress 6.0+
- Compatible with WooCommerce 6.0–8.5+
- HPOS-compatible (High-Performance Order Storage)

## Upgrade notes

Because this release changes no schema and no existing stored data, it carries the same low-risk profile as a typical plugin update: back up as you normally would before any update, then upgrade normally. No feature-specific migration or setup is required — the new fields simply appear, unset, on your existing products.

## Known limitations

Purchase quantity suggestions are always a fixed number you set yourself — the plugin does not forecast demand, sales velocity, or lead time, and does not calculate a target stock level. Supplier suggestions come only from your own explicit choice or from this item's own purchase history; the plugin never learns or infers a preference on its own. Setting a preferred supplier or quantity for many product variations at once is not included in this release.

## Additional information

For questions or issues, see the plugin's **Settings** tab and the built-in admin help sections.

---

**This release bundles two milestones (M22, M23) as one feature train**, following the same release pattern used for the M9–M12 (`v1.29.0`), M13–M15 (`v1.32.0`), and M18–M19 (`v1.36.0`) trains. Intermediate development version `1.39.0` (M22 alone) was never tagged or published. It builds directly on `v1.38.0`. Previous releases (M0–M8 GA, M9–M12 feature train, M13–M15 feature train, M16, M17, M18–M19 feature train, M20, M21) remain in their respective version tags.
