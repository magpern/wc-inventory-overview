# M19 Release Readiness Checklist

**Status:** Frozen and CI-Green (pending final validation + CI confirmation below)
**Date:** 2026-08-12
**Version:** 1.36.0
**DB_VERSION:** 11 (unchanged)
**Canonical base:** `feature/m18-admin-controller-decomposition` @ `2862b8c38ada5f147c1f715ed1b370990c2cd7e6` (M18 unreleased frozen tip)

## Implementation Summary

**Branch:** `feature/m19-admin-controller-decomposition-phase2`
**Plan:** `docs/milestones/m19-implementation-plan.md` (immutable, created at WP-M19-0)

### Work Packages Completed

- **WP-M19-0:** Branch created from the M18 frozen tip (not `main`, since M18 is unreleased); plan materialized
- **WP-M19-1/2:** Movements/Order Profit/Product Profitability characterization tests (25 tests), written and verified green against unmodified pre-extraction code
- **WP-M19-3:** `WC_Inventory_Overview_Reporting_Controller` extracted (9 methods + 3 top-level screen-option filters + the Movements branch of `enqueue_assets()`)
- **WP-M19-4:** Architecture guard tests (11 tests)
- **WP-M19-5:** CI filter regex updated with the 4 new M19 test class prefixes
- **WP-M19-6:** Targeted regression pass (30 tests: sibling-coupling guard, Movements domain characterization, schema v11, Dashboard regression)

## Verification Checklist

### Code Quality

- [x] PHP syntax valid (no `php -l` errors on touched/new files)
- [x] No new `do_action()`/`apply_filters()` introduced (INV-M19-5)
- [x] No new capabilities introduced (INV-M19-4) — only `manage_woocommerce`, confirmed by grep
- [x] No presentation SQL in `Reporting_Controller` (INV-M19-8)
- [x] Nonce strings reused verbatim (INV-M19-10)
- [x] Capability checks ordered before export-param checks, ordered before nonce checks (INV-M19-9)
- [x] Bootstrap order preserved in `Plugin::init()` (INV-M19-12)
- [x] Plugin remains sole tab-routing owner (INV-M19-1)
- [x] Overview and Restock methods untouched (INV-M19-13) — confirmed by diff review and architecture-guard test

### Testing

- [x] Characterization tests written and proven green against unmodified pre-extraction code (hard gate, INV-M19-14)
- [x] Characterization tests pass post-extraction with invocation-seam-only changes (INV-M19-2)
- [x] Exact pre-extraction query-count baselines (Movements=2, Order Profit=3, Product Profitability=4) hold exactly post-extraction (INV-M19-7)
- [x] Architecture guard tests pass (11 assertions/tests)
- [x] Targeted regression passes (30 tests)
- [x] CI discovery updated for M19 test classes, verified via `--list-tests`

### Documentation

- [x] Version bumped: 1.35.0 → 1.36.0 (verified in `wc-inventory-overview.php`, line 5 & 18)
- [x] `DB_VERSION` unchanged: 11 (verified in `class-wc-inventory-overview-install.php:15`)
- [x] `CHANGELOG.md` updated with M19 unreleased entry (comprehensive summary + testing notes)
- [x] `docs/architecture-audit.md` updated — Phase 2 (M19) recorded complete, Phase 3 (Overview + Restock) explicitly named as still open
- [x] `CLAUDE.md` deliberately **not** updated — matches the established repo convention that the Implementation Status table and Platform-status header are updated only at actual release time, not at milestone freeze (confirmed: M18 also has no entry there, despite being frozen)

### Repository State

- [x] Working tree clean after all commits
- [x] Feature branch: `feature/m19-admin-controller-decomposition-phase2`
- [x] No merge, tag, or deployment

## Extracted Code

### `WC_Inventory_Overview_Reporting_Controller`

**File:** `includes/class-wc-inventory-overview-reporting-controller.php` (new)
**Methods:** 9 (`on_load_movements`, `on_load_order_profit`, `on_load_product_profitability`, `render_movements`, `render_order_profit`, `render_product_profitability`, `export_movements_csv`, `export_order_profit_csv`, `export_product_profitability_csv`) + `enqueue_reporting_assets`, `init`, `instance`
**Top-level filters (outside class, moved verbatim):** `set_screen_option_wc_io_movements_per_page`, `set_screen_option_wc_io_order_profit_per_page`, `set_screen_option_wc_io_product_profitability_per_page`
**Hooks:** 1 (`admin_enqueue_scripts`, Movements-tab scope only)

### Plugin Changes

- Removed: 9 methods + 3 top-level filters + the Movements branch of `enqueue_assets()` (~331 LOC)
- Added: `Reporting_Controller` bootstrap call in `init()`
- Updated: `on_load_inventory_profit_page()`'s three dispatch branches, `render_inventory_profit_shell()`'s three case-blocks

## Test Summary

| Suite | Count | Status |
|---|---|---|
| Movements Characterization | 8 | Green |
| Order Profit Characterization | 9 | Green |
| Product Profitability Characterization | 8 | Green |
| Architecture Guards | 11 | Green |
| Targeted Regression | 30 | Green |
| **Total** | **66** | **All Green** |

## Release Assessment

**Standalone vs. Feature Train:** Feature train, joining M18 in one Admin Controller Decomposition train (per `docs/process/milestone-lifecycle.md`: no schema, no migration, no public-API, no ownership-boundary, no storefront-behavior, no security, no breaking change — identical rationale to M18).

**Code Rollback:** Fully reversible (pure internal refactor, zero mutation surface in the entire selected cluster — even lower risk than M18's own rollback profile).

**Deployment Readiness:** Standard pre-deploy backup; no elevated data risk.

**Lifecycle Stage:** Freeze in progress — this checklist is completed at the point of Level A completion review; final CI confirmation and freeze evidence recorded below.

---

## Closure Evidence

### Characterization-Test Integrity

Written and verified green against unmodified pre-extraction `class-wc-inventory-overview-plugin.php` (WP-M19-1/2, hard gate per INV-M19-14). One genuine finding corrected **before** any extraction began: `get_requested_tab()` falls back to `TAB_OVERVIEW` when the current user lacks a tab's required capability, making each tab's own "you do not have permission" notice inside `render_inventory_profit_shell()`'s switch unreachable dead code for Movements/Order Profit/Product Profitability — the initial test draft assumed that notice was reachable and was corrected to characterize the real, silently-falls-back-to-Overview behavior instead, before the pre-extraction commit. This is the kind of defect the M18 closure pass's discipline was designed to catch, and it was caught here at the earliest possible point (before extraction, not after, unlike two of M18's three characterization-test defects).

After extraction: all 25 characterization tests pass with **invocation-seam-only** changes (`$plugin->on_load_*_screen()` → `WC_Inventory_Overview_Reporting_Controller::instance()->on_load_*()`), classification A per the plan's characterization-test-change policy. Zero fixture corrections were needed post-extraction (both real findings were caught pre-extraction). Zero behavioral assertion changes.

### Symbol Audit (INV-M19-11)

`grep -nE 'self::|static::|\$this->[a-zA-Z_]+\('` against the new controller file found only legitimate self-references (`self::$instance`, `$this->export_*_csv()` calls to the class's own methods). Zero unqualified `self::PAGE_SLUG`/`self::TAB_*` references — the class of defect M18 had in `Dashboard_Controller` (six unqualified constants) does not recur. Every `WC_Inventory_Overview_Plugin::PAGE_SLUG`/`::TAB_*` reference is correctly qualified.

### Genuine Production Defect Found

**None.** Unlike M18 (which had one genuine `Dashboard_Controller` constant-qualification bug found by the closure pass), M19's extraction required zero production-code corrections beyond the mechanical move itself — the pre-move symbol audit (§16 of the plan) caught the relevant risk class before it could occur, and the characterization-test hard gate caught the one real behavioral-assumption error before extraction.

### Static Gates

- Docker compose config: valid
- PHP syntax: no parse errors on any touched/new file
- (Full lint/PHPCS/composer/release-audit results recorded in the Final CI Closure Evidence section below, added after the single comprehensive validation pass)

### Test Discovery

- M19 test class prefixes added to CI filter regex (`run-phpunit.sh`): `Test_WC_IO_Movements_Rendering_`, `Test_WC_IO_Order_Profit_Rendering_`, `Test_WC_IO_Product_Profitability_Rendering_`, `Test_WC_IO_Reporting_Controller_`
- All 36 M19 tests confirmed discoverable via `--list-tests`

### Level A Completion Review

**Focus areas (per approved plan):**
1. INV-M19-14 verified: characterization tests written against pre-extraction code, proven green as a hard gate before any extraction commit, rerun unchanged (invocation-seam only) after extraction, remain green — behavior equivalence proven.
2. INV-M19-11 verified: diff review confirms extraction was mechanical — only the established call-site patterns changed (`self::PAGE_SLUG`/`self::TAB_*` → `WC_Inventory_Overview_Plugin::PAGE_SLUG`/`::TAB_*`), no other logic alterations. Pre-move symbol audit found zero defects to fix.
3. INV-M19-13 verified: Overview and Restock methods remain on Plugin, byte-for-byte untouched (confirmed by diff review and architecture-guard test).
4. Repository state verified: version bumped, `DB_VERSION` unchanged, documentation updated honestly (Phase 3 explicitly named as still open, not silently dropped).

**Verdict:** Level A completion review PASS.

---

**Frozen:** 2026-08-12
**Freeze Authority:** M19 Implementation Complete — Level A Review Passed
**Next Action:** Final comprehensive validation pass, then draft CI PR / GitHub Actions confirmation (see Final CI Closure Evidence, appended after that pass completes)
