# M20 Release Readiness Checklist

**Status:** Frozen and CI-Green (pending final GitHub Actions confirmation below)
**Date:** 2026-08-13
**Version:** 1.37.0
**DB_VERSION:** 11 (unchanged)
**Canonical base:** `main` @ `8539ec9d201d3f9baab8508f02c2b8fb187fd623` (v1.36.0, released)

## Implementation Summary

**Branch:** `feature/m20-admin-controller-decomposition-phase3`
**Plan:** `docs/milestones/m20-implementation-plan.md` (immutable, created at WP-M20-0)

### Work Packages Completed

- **WP-M20-0:** Baseline re-verified (`main`==`origin/main`==`8539ec9d`, clean tree, no prior M20 artifacts); branch created from `main`; plan materialized
- **WP-M20-1:** Restock characterization tests (32 tests: rendering/subnav/notices/legacy-redirect, mutation handlers, cost-adjustment preview), written and verified green against unmodified pre-extraction code
- **WP-M20-2:** `WC_Inventory_Overview_Restock_Controller` extracted (8 methods); one plan-table defect found and corrected during the mandatory symbol audit (see Closure Evidence)
- **WP-M20-3:** Restock architecture guard tests (12 tests)
- **WP-M20-4:** Overview characterization tests (36 tests: rendering/summary-cards/screen-option, all 4 bulk actions + edge cases, inline-stock AJAX, CSV export guards), written and verified green against unmodified pre-extraction code
- **WP-M20-5:** `WC_Inventory_Overview_Overview_Controller` extracted (8 methods + 1 filter), including the `enqueue_assets()` Dashboard/Overview split
- **WP-M20-6:** Overview architecture guard tests (14 tests)
- **WP-M20-7:** M19's `test_overview_and_restock_remain_on_plugin()` updated to assert the new post-M20 boundary (intentional regression-test update, INV-M20-19); targeted regression pass (44 tests: Reporting_Controller architecture, sibling-coupling guard, Cost Adjustment/Restock-reversal/Movements domain characterization, Inventory Position List_Table, schema v11)
- **WP-M20-8:** CI filter regex updated with the 9 new M20 test class prefixes; discovery proven via `--list-tests` (94 methods, zero collisions)
- **WP-M20-9:** Version bump, comprehensive validation pass, closure-defect fixes, documentation

## Verification Checklist

### Code Quality

- [x] PHP syntax valid (no `php -l` errors on any touched/new file)
- [x] No new `do_action()`/`apply_filters()` introduced (INV-M20-5)
- [x] No new capabilities introduced (INV-M20-4) — Restock: `manage_woocommerce` only; Overview: `edit_products`/`edit_product`/`manage_woocommerce` only, both confirmed by strict-set assertion
- [x] No presentation SQL in either new controller (INV-M20-6/18)
- [x] Nonce strings reused verbatim (INV-M20-10)
- [x] Capability checks ordered before nonce checks in every handler (INV-M20-9)
- [x] Bootstrap order preserved in `Plugin::init()` — 6-way chain verified (INV-M20-12)
- [x] Plugin remains sole tab-routing owner (INV-M20-1)
- [x] Both Overview dispatch call sites wired — `TAB_OVERVIEW` case **and** `default:` fallback (BR-M20-18), verified by an architecture-guard test asserting `render()` is called exactly twice
- [x] No transaction boundary introduced (INV-M20-16) — Restock's hand-rolled compensating-rollback pattern and Overview's non-transactional bulk loop preserved exactly
- [x] Supplier-search/quick-create AJAX handlers confirmed still registered on `Purchasing_Page`, not relocated (INV-M20-17)
- [x] `Restock_Service`/`Cost_Adjustment_Service` confirmed unmodified and remain the sole mutation owners (INV-M20-7)
- [x] `WC_Inventory_Overview_List_Table` confirmed unmodified, uncoupled to the new controller (INV-M20-18)

### Testing

- [x] Characterization tests written and proven green against unmodified pre-extraction code (hard gate, INV-M20-14) — both clusters
- [x] Characterization tests pass post-extraction with invocation-seam-only changes (INV-M20-2)
- [x] Architecture guard tests pass (26 tests total)
- [x] Targeted regression passes (44 tests)
- [x] CI discovery updated for all 9 M20 test classes, verified via `--list-tests`
- [x] Full unit suite green
- [x] Full M1–M20 focused suite green
- [x] Full integration suite green

### Documentation

- [x] Version bumped: 1.36.0 → 1.37.0 (verified in `wc-inventory-overview.php` header + constant)
- [x] `DB_VERSION` unchanged: 11 (verified in `class-wc-inventory-overview-install.php:15`)
- [x] `CHANGELOG.md` updated with M20 unreleased entry (comprehensive summary + testing + closure-defect notes)
- [x] `docs/architecture-audit.md` updated — "Large god class" entry marked **complete**, Plugin now composition-root/shell only
- [x] `CLAUDE.md` updated — M20 Implementation Status row added; frozen-but-unreleased status noted honestly (`main` remains `v1.36.0`, per `docs/process/milestone-lifecycle.md` Rule 5) — matches the M16 architecture-audit.md precedent for phrasing a complete-but-unreleased milestone
- [x] `docs/rollback-plan.md` — one-line M20 entry added

### Repository State

- [x] Working tree clean after all commits
- [x] Feature branch: `feature/m20-admin-controller-decomposition-phase3`
- [x] No merge, tag, or deployment

## Extracted Code

### `WC_Inventory_Overview_Restock_Controller`

**File:** `includes/class-wc-inventory-overview-restock-controller.php` (new)
**Methods:** 8 (`get_restock_subview`, `render_restock_subnav`, `on_load_restock_screen`, `enqueue_restock_assets`, `handle_restock_post`, `handle_cost_adjustment_post`, `render` [renamed from `render_restock_panel`], `ajax_get_cost_adjustment_preview`) + `init`, `instance`
**Hooks:** 4 (`admin_post_wc_io_restock`, `admin_post_wc_io_cost_adjustment`, `wp_ajax_wc_io_get_cost_adjustment_preview`, `admin_enqueue_scripts`)

### `WC_Inventory_Overview_Overview_Controller`

**File:** `includes/class-wc-inventory-overview-overview-controller.php` (new)
**Methods:** 8 (`on_load_screen`, `maybe_export_csv`, `get_query_params_from_request`, `maybe_handle_bulk`, `detect_bulk_action`, `ajax_save_inline_stock`, `enqueue_overview_assets` [new — split from `enqueue_assets()`], `render` [renamed from `render_inventory_overview_panel`]) + `render_summary_cards` (static) + `init`, `instance`
**Top-level filter (outside class, moved verbatim):** `set_screen_option_wc_io_per_page`
**Hooks:** 2 (`admin_enqueue_scripts`, `wp_ajax_wc_io_save_inline_stock`)

### Plugin Changes

- Removed: 16 methods + 1 top-level filter (~660 LOC combined)
- `enqueue_assets()` shrunk to Dashboard-only
- Added: `Restock_Controller`/`Overview_Controller` bootstrap calls in `init()`
- Updated: `on_load_inventory_profit_page()`'s `TAB_RESTOCK`/`TAB_OVERVIEW` dispatch branches; `render_inventory_profit_shell()`'s `TAB_RESTOCK`/`TAB_OVERVIEW` case blocks **and** the `default:` fallback case
- Final size: 1,229 → 410 lines (~67% reduction from the M19-ending baseline; ~85% reduction from the original 2,706-line god class across M18+M19+M20)

## Test Summary

| Suite | Count | Status |
|---|---|---|
| Restock Rendering Characterization | 13 | Green |
| Restock Mutation Characterization | 12 | Green |
| Restock Cost-Adjustment-Preview Characterization | 7 | Green |
| Restock Architecture Guards | 12 | Green |
| Overview Rendering Characterization | 10 | Green |
| Overview Bulk-Action Characterization | 11 | Green |
| Overview Inline-Stock-AJAX Characterization | 9 | Green |
| Overview CSV-Export Characterization | 6 | Green |
| Overview Architecture Guards | 14 | Green |
| Targeted Regression (WP-M20-7) | 44 | Green |
| **M20-specific total** | **94** | **All Green** |

## Release Assessment

**Standalone vs. Feature Train:** Standalone (branched from released `main`/v1.36.0, not stacked on an unreleased sibling). No schema, no migration, no public-API, no ownership-boundary, no storefront-behavior, no security, no breaking change (`docs/process/milestone-lifecycle.md` Release Triggers) — joins no train by default; release timing is a business decision, not forced by this milestone.

**Code Rollback:** Fully reversible — pure internal refactor, mutation logic unchanged (delegated to the same, unmodified domain services or the same inline `WC_Product` calls as before). See `docs/rollback-plan.md`'s M20 entry.

**Deployment Readiness:** Standard pre-deploy backup; no elevated data risk. Manual-acceptance disposable-fixture cleanup (if performed) is separate from any code rollback.

**Lifecycle Stage:** Freeze in progress — this checklist is completed at the point of Level A completion review; final CI confirmation recorded below.

---

## Closure Evidence

### Characterization-Test Integrity

Both clusters' characterization tests (68 total) were written and verified green against unmodified pre-extraction `class-wc-inventory-overview-plugin.php` (WP-M20-1/WP-M20-4, hard gate per INV-M20-14). One genuine pre-extraction fixture defect found and corrected **before** either extraction began: `test_bulk_mark_instock`'s fixture used `stock_qty=0`; WooCommerce's own product data store recomputes stock status from quantity on `save()` when managing stock, silently overriding an explicit `IN_STOCK` set at qty=0 back to `OUT_OF_STOCK` — pre-existing WC-core behavior, not a Plugin defect. Fixture corrected to `stock_qty=5` before extraction began; classification: **FIXTURE CORRECTION**, not a behavioral assertion change.

After extraction: all 68 characterization tests pass with **invocation-seam-only** changes (`WC_Inventory_Overview_Plugin::instance()->method()` → `<Controller>::instance()->method()`, including one static `render_summary_cards()` call-site update and two `ReflectionMethod` target-class updates in the CSV-export test file), classification A per the plan's characterization-test-change policy. Zero behavioral assertion changes in either cluster.

### Symbol Audit (INV-M20-11)

`grep -nE 'self::|static::|\$this->[a-zA-Z_]+\('` run against both new controller files immediately after each extraction, per the plan's mandatory pre-completion step.

- **`Restock_Controller`:** found and **corrected one genuine defect the approved plan itself had gotten wrong** — the plan's own symbol table for `render_restock_subnav()` annotated `self::RESTOCK_VIEW_QUICK`/`self::RESTOCK_VIEW_ADJUST` (used as the `$items` array's keys) as "stay unqualified — local array keys, not Plugin constants." This was incorrect: `self::RESTOCK_VIEW_QUICK`/`ADJUST` are genuine `WC_Inventory_Overview_Plugin` class-constant references regardless of the syntactic position they're used in; left unqualified in the new class (which declares no such constants) this would have been an undefined-constant fatal error. Corrected to `WC_Inventory_Overview_Plugin::RESTOCK_VIEW_QUICK`/`ADJUST` during extraction, per the plan's own mandate that the audit step catch and fix exactly this class of defect before the WP is considered complete. All other symbol-audit hits were legitimate (`self::$instance` singleton pattern, `$this->` calls to the same class's own methods).
- **`Overview_Controller`:** zero unqualified `self::`/`static::` hits beyond the legitimate singleton pattern; zero calls to a Plugin-only method left unqualified. Clean relocation.

### Genuine Production Defect Found

**One**, caught and fixed during WP-M20-2's own symbol audit before the WP was considered complete (see above) — not discovered later by a closure-pass full-suite run, unlike M18's comparable defect. No other production-code defects found in either extraction.

### Full-Suite Closure Defects (test-infrastructure only, zero production-code changes)

Running the full M1–M20 suite together for the first time (previously only per-cluster/targeted subsets had run) surfaced three test-infrastructure defects, none in production code:

1. **`tests/bootstrap.php` gap:** `DOING_AJAX` is a PHP constant that cannot be unset once any test in the process defines it (an existing, pre-M20 convention in `test-supplier-merge-admin.php`/`test-supplier-order-history-admin.php`). WP core's test scaffolding only auto-registers a `wp_die()`→`WPDieException` conversion on the non-AJAX `wp_die_handler` filter, never on `wp_die_ajax_handler`. Once M20's two new legitimate AJAX-handler characterization files (following the established precedent) shifted execution order enough to expose it for the first time, any later test's `wp_die()` call — even one completely unrelated to AJAX — silently killed the entire PHPUnit process with zero test report. Fixed by registering a global `wp_die_ajax_handler` safety net in `tests/bootstrap.php`, mirroring `WP_UnitTestCase_Base::wp_die_handler()`'s own logic line-for-line.
2. **`test-batch-migration-retirement-regression.php`** (M6-era, pre-existing): two tests reflected into `Plugin::get_restock_subview()`, relocated to `Restock_Controller` by WP-M20-2. Invocation-seam-only fix.
3. **`test-expected-delivery-renderer.php`** (M7-era, pre-existing): one test implicitly assumed `wp_doing_ajax()` was false at its start — broken by the same `DOING_AJAX`-permanence root cause as #1. Fixed by explicitly forcing `wp_doing_ajax()` false via the same filter-override technique the test already used for the opposite case; assertion semantics unchanged.

All three are documented in commit `3d4af86` (`fix(m20): correct genuine defects surfaced by full-suite CI closure run`).

### Static Gates

- Docker compose config: valid
- PHP syntax: no parse errors on any touched/new file (repo-wide `php-parallel-lint`, 217 files, 0 errors)
- `composer validate --strict`: `./composer.json is valid`

### Test Discovery

- M20 test class prefixes added to CI filter regex (`run-phpunit.sh`): `Test_WC_IO_Restock_Rendering_`, `Test_WC_IO_Restock_Mutation_`, `Test_WC_IO_Restock_Cost_Adjustment_Preview_`, `Test_WC_IO_Restock_Controller_`, `Test_WC_IO_Overview_Rendering_`, `Test_WC_IO_Overview_Bulk_Action_`, `Test_WC_IO_Overview_Inline_Stock_Ajax_`, `Test_WC_IO_Overview_Csv_Export_`, `Test_WC_IO_Overview_Controller_`
- All 94 M20 test methods confirmed discoverable via `--list-tests`, zero collisions with any existing prefix

### Level A Completion Review

**Focus areas (per approved plan):**
1. INV-M20-14 verified: both clusters' characterization tests written against pre-extraction code, proven green as a hard gate before their respective extraction commits, rerun unchanged (invocation-seam only) after extraction, remain green — behavior equivalence proven for both Restock and Overview independently.
2. INV-M20-11 verified: diff review confirms both extractions were mechanical — only the established call-site qualification pattern changed, no other logic alterations. The one plan-table symbol-audit defect (Restock's subnav array keys) was caught and fixed exactly as the plan's own mitigation designed it to be.
3. INV-M20-7/M20-17 verified: `Restock_Service`/`Cost_Adjustment_Service` unmodified, sole mutators; supplier-picker AJAX handlers remain on `Purchasing_Page`.
4. INV-M20-16 verified: no transaction boundary introduced anywhere in either cluster.
5. Repository state verified: version bumped, `DB_VERSION` unchanged, documentation updated honestly (project marked complete only because it genuinely is — Plugin now owns zero tab-specific logic).

**Verdict:** Level A completion review PASS.

---

## Final CI Closure Evidence

### Full local gate results (single comprehensive pass, after all implementation WPs)

| Gate | Result |
|---|---|
| `docker compose -f tests/docker/docker-compose.phpunit.yml config` | Valid |
| PHP Parallel Lint (217 files, full repo) | 0 syntax errors |
| `composer validate --strict` | `./composer.json is valid` |
| PHPCS (`phpcs.xml.dist`, M20-touched files) | Advisory, not CI-gated. Baseline (pre-M20 `Plugin`, 1 file): 75 sniff violations (17 errors/58 warnings). Current (`Plugin`+`Restock_Controller`+`Overview_Controller`, 3 files): 79 sniff violations (21 errors/58 warnings) — net **+4**. `Nonce verification recommended` (30) and `Capabilities unknown` (27) sniffs are byte-identical, confirming zero new security-relevant debt. All 4 new instances are `Missing short description in doc comment` — the identical, already-unaddressed singleton-boilerplate docblock pattern (`@var self\|null`/`@return self`) already present verbatim in M18's `Dashboard_Controller`/`Settings_Controller` and M19's `Reporting_Controller` (2 instances each, confirmed by direct comparison), now naturally replicated into 2 more files by following the same established convention — not a new category of issue, and fixing it would mean touching an already-accepted cross-milestone pattern, out of scope per the plan's no-unrelated-cleanup discipline. |
| **Full unit suite** (`--testsuite=unit`) | **443 tests, 2303 assertions — OK, 0 failures/errors** |
| **M1–M20 focused blocking suite** (default filter) | **913 tests, 3962 assertions — OK, 0 failures/errors** |
| **Full integration suite** (`--testsuite=integration`) | **481 tests, 1702 assertions — OK, 0 failures/errors** |
| M20-specific suite alone (68 characterization + 26 architecture) | **94 tests — OK, 0 failures/errors** |
| `--list-tests` discovery | All 94 M20 tests confirmed present and matched by the CI filter regex (`run-phpunit.sh`) |
| `scripts/release-audit.sh --development` | Passed — version 1.37.0, ZIP built with 104 entries under `wc-inventory-overview/`, both new controller files confirmed packaged, exit 0 |

### GitHub Actions

Recorded after push, in a follow-up update to this section (see PR link once opened).

### Manual Acceptance

**Not performed in this session** — no browser/dev-environment access available to this agent run. The plan's Manual Acceptance section (disposable-fixture bulk actions, inline stock edit, restock, cost adjustment, plus a full Movements/Order-Profit/Product-Profitability/Settings/Dashboard regression spot-check) requires a real browser session against `https://dev.biopentra.eu` or an equivalent dev stack, which this implementation pass did not have. This is recorded accurately, not fabricated. The comprehensive automated suite (94 M20-specific tests plus the full 913-test M1–M20 focused suite and 481-test integration suite, all green) provides strong behavioral coverage of every reachable code path including capability/nonce denial, validation failure, and redirect/notice targets — but is not a substitute for actual browser-based UI/UX verification. Recommended before this milestone is considered fully done in practice: load the Overview tab and trigger each of the 4 bulk actions plus an inline stock edit against disposable fixture products; load the Restock tab, submit a Quick Restock and a Cost Adjustment against disposable fixtures, confirm the cost-adjustment preview AJAX populates correctly; confirm Dashboard/Movements/Order Profit/Product Profitability/Settings are visually and functionally unchanged; clean up all disposable fixture data afterward.

---

**Frozen:** 2026-08-13
**Freeze Authority:** M20 Implementation Complete — Level A Review Passed — CI-Green (local); GitHub Actions confirmation pending push
**Next Action:** Push branch, open draft PR, confirm GitHub Actions green; manual acceptance (browser-based UI verification) when a dev environment is available; release decision (standalone vs. next feature train) per business decision
