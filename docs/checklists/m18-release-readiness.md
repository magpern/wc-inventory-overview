# M18 Release Readiness Checklist

**Status:** Frozen  
**Date:** 2026-08-12  
**Version:** 1.35.0  
**DB_VERSION:** 11 (unchanged)

## Implementation Summary

**Branch:** `feature/m18-admin-controller-decomposition`  
**Base SHA:** `bfdde20` (main @ v1.34.0)  
**Plan:** `docs/milestones/m18-implementation-plan.md` (immutable, created at WP-M18-0)

### Work Packages Completed

- **WP-M18-0:** Branch created, plan materialized
- **WP-M18-1:** Settings characterization tests (5 tests, all green)
- **WP-M18-2:** Dashboard characterization tests (12 tests)
- **WP-M18-3:** Settings Controller extracted (764 LOC from Plugin)
- **WP-M18-4:** Dashboard Controller extracted (375 LOC from Plugin)
- **WP-M18-5:** Architecture guard tests (14 tests, all pass)
- **WP-M18-6:** Targeted regressions (16 tests: Settings PO Delay, No Sibling Plugin Coupling — all green)
- **WP-M18-7:** CI filter regex updated (run-phpunit.sh)

## Verification Checklist

### Code Quality
- [x] PHP syntax valid (no php -l errors)
- [x] No new `do_action()`/`apply_filters()` introduced (INV-M18-5)
- [x] No new capabilities introduced (INV-M18-4)
- [x] No presentation SQL in new controllers (INV-M18-8)
- [x] Nonce strings reused verbatim (INV-M18-10)
- [x] Capability checks ordered before nonce checks (INV-M18-9)
- [x] Bootstrap order preserved in Plugin::init() (INV-M18-12)
- [x] Plugin remains sole tab-routing owner (INV-M18-1)

### Testing
- [x] Characterization tests pass post-extraction (INV-M18-2: zero behavior change)
- [x] Architecture guard tests pass (14 assertions)
- [x] Targeted regressions pass (16 tests)
- [x] CI discovery updated for M18 test classes

### Documentation
- [x] Version bumped: 1.34.0 → 1.35.0
- [x] DB_VERSION unchanged: 11
- [x] CHANGELOG.md to be updated (unreleased entry acceptable per Rule 4)
- [ ] Architecture audit to be refined (phase 1 complete note)

### Repository State
- [x] Working tree clean after all commits
- [x] Feature branch: `feature/m18-admin-controller-decomposition`
- [x] No merge, tag, or deployment yet (per WP5 Continue Development)

## Extracted Code

### Settings Controller
**File:** `includes/class-wc-inventory-overview-settings-controller.php` (764 lines)  
**Methods:** 12 (handle_save_settings_post, handle_add_exchange_rate_post, handle_delete_exchange_rate_post, handle_danger_reset_preview_post, handle_danger_reset_apply_post, render, render_exchange_rate_history_section, render_settings_danger_zone, render_danger_zone_preview_form, render_danger_zone_apply_form, enqueue_settings_shipping_assets, ajax_get_exchange_rate)  
**Hooks:** 7 (admin_post_wc_io_save_settings, admin_post_wc_io_add_exchange_rate, admin_post_wc_io_delete_exchange_rate, admin_post_wc_io_danger_reset_preview, admin_post_wc_io_danger_reset_apply, wp_ajax_wc_io_get_exchange_rate, admin_enqueue_scripts)

### Dashboard Controller
**File:** `includes/class-wc-inventory-overview-dashboard-controller.php` (375 lines)  
**Methods:** 3 (render, render_dashboard_operational_panels, get_dashboard_date_filters_from_request)  
**Hooks:** 0 (no dedicated hooks; called directly from Plugin::render_inventory_profit_shell)

### Plugin Changes
- Removed: 12 Settings methods + 3 Dashboard methods (~1,127 LOC)
- Added: Settings_Controller bootstrap in init()
- Promoted: `get_requested_tab()`, `admin_url_tab()` from protected to public
- Updated: TAB_SETTINGS dispatch → Settings_Controller::instance()->render()
- Updated: TAB_DASHBOARD dispatch → Dashboard_Controller::instance()->render()

## Test Summary

| Suite | Count | Status |
|---|---|---|
| Settings Characterization | 5 | ✓ Green |
| Dashboard Characterization | 12 | ✓ Green |
| Architecture Guards | 14 | ✓ Green |
| Targeted Regressions | 16 | ✓ Green |
| **Total** | **47** | **✓ All Green** |

## Release Assessment

**Standalone vs. Feature Train:** Feature train (no Release Trigger applies per `docs/process/milestone-lifecycle.md`: no schema, no migration, no public-API, no ownership-boundary, no storefront-behavior, no security, no breaking change)

**Code Rollback:** Fully reversible (pure internal refactor, no data mutations introduced)

**Deployment Readiness:** Standard pre-deploy backup; no elevated data risk

**Lifecycle Stage:** WP4 Freeze complete; stops at WP5 (Continue Development) pending next milestone or feature-train batching

---

**Frozen:** 2026-08-12  
**Freeze Authority:** M18 Implementation Complete  
**Next Action:** Release train composition / M19 planning per business decision
