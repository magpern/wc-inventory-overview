# WC Inventory Overview 1.36.0 — Admin Controller Decomposition, Phases 1–2

**Release Date:** August 2026
**Plugin Version:** 1.36.0
**Database Version:** 11 (unchanged from v1.34.0)

## Overview

This release is an **internal admin-architecture improvement**. It contains no new merchant-facing features, no workflow changes, and no schema migration. It bundles two milestones (M18 and M19) that together reorganize how the plugin's admin screens are implemented internally, without changing what you see or how you use them.

**If you use this plugin day-to-day, you should not notice any difference after upgrading.** Every screen, button, filter, CSV export, and notice behaves exactly as it did in v1.34.0.

## What changed (internally)

The plugin's main admin controller class had grown large over several years of feature additions, making it harder to test and safely extend. This release splits five of its seven admin tabs out into dedicated, independently-tested controller classes:

- **M18 — Dashboard and Settings tabs** extracted into `WC_Inventory_Overview_Dashboard_Controller` and `WC_Inventory_Overview_Settings_Controller`.
- **M19 — Movements, Order Profit, and Product Profitability tabs** extracted into a new `WC_Inventory_Overview_Reporting_Controller`.

Two tabs — **Inventory Overview** and **Restock / Cost Adjustment** — remain on the main controller for now and are unaffected by this release. They may be addressed in a future release; there is no committed timeline.

## Why this matters to you

- **Behavior-preserving by design.** Both milestones were built characterization-tests-first: automated tests captured the exact existing behavior of each screen *before* any code moved, then verified byte-for-byte identical behavior *after* the move. 66 new automated tests were added specifically to prove this (36 characterization + 25 architecture-guard style checks across the two milestones, plus 30 targeted regression checks against everything else).
- **No database change.** `DB_VERSION` stays at `11` — there is nothing to migrate, and the upgrade is a plain code swap.
- **No new integration surface.** No new WordPress hooks, filters, REST endpoints, or capabilities were introduced. If you have custom code integrating with this plugin, nothing about that integration changes.
- **No new settings, fields, or options.** Every screen's controls, defaults, and validation are unchanged.

## What to expect after upgrading

- Dashboard, Settings, Inventory Movements, Order Profit, and Product Profitability all load and behave exactly as before, including their CSV exports, screen options ("items per page"), filters, and notices.
- Inventory Overview and Restock / Cost Adjustment are entirely unaffected.
- No manual action is required after upgrading. There is no new option to configure and no migration step to run.

## Compatibility

- Requires PHP 7.4+
- Requires WordPress 6.0+
- Compatible with WooCommerce 6.0–8.5+
- HPOS-compatible (High-Performance Order Storage)

## Upgrade notes

Because this release changes no schema and no stored data, it carries the same low-risk profile as a typical plugin update: back up as you normally would before any update, then upgrade normally. No feature-specific testing checklist is needed — this is not a new-capability release.

## Known limitations

- Two admin tabs (Overview, Restock / Cost Adjustment) are not yet part of this internal reorganization and remain on the original controller class. This is intentional scoping, not an oversight — see the plugin's `docs/architecture-audit.md` for the tracked follow-up.

## Additional information

For questions or issues, see the plugin's **Settings** tab and the built-in admin help sections.

---

**This release bundles two milestones (M18, M19) as one feature train**, following the same release pattern used for the M9–M12 (`v1.29.0`) and M13–M15 (`v1.32.0`) trains. Intermediate development version `1.35.0` (M18 alone) was never tagged or published. Previous releases (M0–M8 GA, M9–M12 feature train, M13–M15 feature train, M16, M17) remain in their respective version tags.
