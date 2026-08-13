# WC Inventory Overview 1.37.0 — Admin Controller Decomposition, Phase 3

**Release Date:** August 2026
**Plugin Version:** 1.37.0
**Database Version:** 11 (unchanged from v1.36.0)

## Overview

This release is an **internal admin-architecture improvement**. It contains no new merchant-facing features, no workflow changes, and no schema migration. It completes the Admin Controller Decomposition project that began with M18/M19 (`v1.36.0`) by extracting the two remaining, mutation-bearing admin tabs — **Inventory Overview** and **Restock / Cost Adjustment** — into their own dedicated controller classes.

**If you use this plugin day-to-day, you should not notice any difference after upgrading.** Every screen, button, filter, bulk action, inline stock edit, CSV export, restock form, and cost adjustment behaves exactly as it did in v1.36.0.

## What changed (internally)

- **Inventory Overview tab** — summary cards, table, filters/search, bulk actions (set draft, hide from catalog, mark in/out of stock), inline stock AJAX editing, and CSV export — extracted into `WC_Inventory_Overview_Overview_Controller`.
- **Restock / Cost Adjustment tab** — Quick Restock and Cost Adjustment sub-views, including their admin-post mutation handlers and the cost-adjustment preview AJAX endpoint — extracted into `WC_Inventory_Overview_Restock_Controller`.

This completes the reorganization started in v1.36.0 (M18: Dashboard/Settings; M19: Movements/Order Profit/Product Profitability). The plugin's former god-class admin controller is now primarily a **composition root / routing shell** that wires requests to dedicated controllers — it no longer contains tab-specific rendering or mutation logic itself.

## Why this matters to you

- **Behavior-preserving by design.** Like M18/M19, this milestone was built characterization-tests-first: automated tests captured the exact existing behavior of Overview and Restock *before* any code moved, then verified identical behavior *after* the move. 94 milestone-specific automated tests were added to prove this.
- **Mutation ownership unchanged.** The actual business logic that mutates stock and cost data was not touched or moved: `Restock_Service` and `Cost_Adjustment_Service` remain the sole owners of restock and cost-adjustment mutations, exactly as before. Overview's bulk/inline mutations remain the same product-level operations, relocated verbatim into the new controller.
- **No database change.** `DB_VERSION` stays at `11` — there is nothing to migrate, and the upgrade is a plain code swap.
- **No new integration surface.** No new WordPress hooks, filters, REST endpoints, or capabilities were introduced.
- **No new settings, fields, or options.** Every screen's controls, defaults, and validation are unchanged.

## What to expect after upgrading

- Inventory Overview and Restock / Cost Adjustment load and behave exactly as before, including bulk actions, inline stock editing, CSV export, Quick Restock, and Cost Adjustment.
- Dashboard, Settings, Inventory Movements, Order Profit, and Product Profitability are unaffected (already decomposed in v1.36.0).
- No manual action is required after upgrading. There is no new option to configure and no migration step to run.

## Compatibility

- Requires PHP 7.4+
- Requires WordPress 6.0+
- Compatible with WooCommerce 6.0–8.5+
- HPOS-compatible (High-Performance Order Storage)

## Upgrade notes

Because this release changes no schema and no stored data, it carries the same low-risk profile as a typical plugin update: back up as you normally would before any update, then upgrade normally. No feature-specific testing checklist is needed — this is not a new-capability release.

## Known limitations

None new in this release. This milestone completes the Admin Controller Decomposition project tracked in the plugin's `docs/architecture-audit.md`; no further phases are planned under that project.

## Additional information

For questions or issues, see the plugin's **Settings** tab and the built-in admin help sections.

---

**This release is a standalone milestone (M20)**, following the same release pattern used for M16 (`v1.33.0`) and M17 (`v1.34.0`). It builds directly on the M18–M19 train (`v1.36.0`). Previous releases (M0–M8 GA, M9–M12 feature train, M13–M15 feature train, M16, M17, M18–M19 feature train) remain in their respective version tags.
