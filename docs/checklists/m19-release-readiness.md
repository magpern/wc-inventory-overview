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

## Final CI Closure Evidence

### Full local gate results (single comprehensive pass, after all implementation WPs)

| Gate | Result |
|---|---|
| `docker compose -f tests/docker/docker-compose.phpunit.yml config` | Valid |
| PHP Parallel Lint (206 files, full repo) | 0 syntax errors |
| `composer validate --strict` | `./composer.json is valid` |
| PHPCS (`phpcs.xml.dist`, full repo) | Not CI-gated per `docs/testing.md`; 1484 errors / 932 warnings (repo-wide baseline, up from M18's 1472/843 by exactly the 5 new M19 files — same pre-existing sniff categories, e.g. 15 violations in the new controller: `WordPress.WP.Capabilities`/`file_system_operations`/doc-comment sniffs, no new category introduced) |
| **Full unit suite** (`--testsuite=unit`) | **417 tests, 2162 assertions — OK, 0 failures/errors** |
| **M1–M19 focused blocking suite** (default filter) | **819 tests, 3620 assertions — OK, 0 failures/errors** |
| **Full integration suite** (`--testsuite=integration`) | **413 tests, 1501 assertions — OK, 0 failures/errors** |
| M19-specific suite alone (25 characterization + 11 architecture) | **36 tests — OK, 0 failures/errors** |
| `--list-tests` discovery | All 36 M19 tests confirmed present and matched by the CI filter regex (`run-phpunit.sh`) |
| `scripts/release-audit.sh --development` | Passed — version 1.36.0, ZIP built with 102 entries under `wc-inventory-overview/`, exit 0 |

### GitHub Actions (draft PR #23, `feature/m19-admin-controller-decomposition-phase2` → `main`, not merged)

Base branch note: initially opened against `feature/m18-admin-controller-decomposition` (stacked-PR pattern, since M18 is unreleased), but this repo's `tests.yml`/`ci.yml` workflows only trigger on push/PR events targeting `main`/`develop` — a PR based against a feature branch never fires CI. Re-based to `main` (matching M18's own draft PR #22) via the GitHub REST API (`gh pr edit`'s GraphQL call failed on an unrelated Projects-classic deprecation bug), then closed/reopened to force the "opened" event workflows listen for. This PR's diff now includes both M18's and M19's commits (21 commits total), same as PR #22 plus the 6 new M19 commits — this is expected for a stacked, unreleased pair of milestones and does not affect correctness.

| Check | Run | Result |
|---|---|---|
| PHP Parallel Lint | [run 31644325426](https://github.com/magpern/wc-inventory-overview/actions/runs/31644325426) | pass (15s) |
| PHP lint and build ZIP | [run 31644325388](https://github.com/magpern/wc-inventory-overview/actions/runs/31644325388) | pass (21s) |
| PHPUnit | [run 31644325426](https://github.com/magpern/wc-inventory-overview/actions/runs/31644325426), job 94274205450 | pass (3m34s) — green on first attempt, no transient failures this time |

All required checks green. PR remains **draft**; not merged, no tag, no release, no deploy.

### Manual Acceptance

**Not performed in this session** — no browser/dev-environment access available to this agent run. The successful CSV-export streaming path (Movements/Order Profit/Product Profitability `export_*_csv()` methods' `exit()` after writing to `php://output`) and the visual/functional regression spot-checks for Dashboard/Overview/Restock (per the plan's Manual Acceptance section) require a real browser session against `https://dev.biopentra.eu` or an equivalent dev stack, which this implementation pass did not have. This is recorded accurately, not fabricated. Recommended before this milestone is considered fully done in practice: load each of the three tabs, trigger each CSV export, confirm downloaded file content matches the on-screen table, and spot-check Dashboard/Overview/Restock for regressions.

---

**Frozen:** 2026-08-12
**Freeze Authority:** M19 Implementation Complete — Level A Review Passed — CI-Green
**Next Action:** Manual acceptance (browser-based CSV/UI verification) when a dev environment is available; release train composition / Phase 3 planning per business decision
