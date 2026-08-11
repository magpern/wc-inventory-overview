# M15 Release Readiness — Supplier Spend Summary

**Status:** Level A freeze complete.
**Date:** 2026-08-11
**Branch tip (at freeze):** `feature/m15-supplier-spend-summary` (branched from `feature/m14-supplier-order-history` at commit `0780ba7`, the accepted post-M14-freeze DOING_AJAX test-isolation remediation)
**CI proof PR:** https://github.com/magpern/wc-inventory-overview/pull/16 (**DRAFT — DO NOT MERGE**)

## Freeze record

| Item | Value |
|------|--------|
| M15 implementation | Complete |
| Level A completion review | Complete |
| Independent (Level B) audit | **Not performed** — Level A classification, no schema/migration/mutation/public-API/ownership-boundary/destructive/security/storefront/concurrency trigger applies |
| Canonical implementation base | `0780ba7` |
| Plugin development version | `1.32.0` |
| `DB_VERSION` | `10` (unchanged; no schema migration) |
| GitHub Actions | Green — PR #16: "PHP Parallel Lint" pass, "PHP lint and build ZIP" pass, "PHPUnit" pass (run `31470688635`/`31470688623`) |
| Schema change | None — `includes/class-wc-inventory-overview-install.php` has zero diff against the M15 base |
| Mutation change | None |
| New public API | None |
| New capability | None |
| New public hook/filter | None |
| New dependency | None (`composer.json`/`composer.lock` have zero diff against the M15 base) |
| Immutable plan | `docs/milestones/m15-implementation-plan.md` @ `5aca80c` — untouched after materialization (single commit touches this file) |
| Individually released | **No** — intentional |
| Feature train | **M15 continues** the same unreleased train M13 opened, M14 joined |
| Version-surface convention | Deliberate deviation for M15 only: `readme.txt`'s `Stable tag` bumped to `1.32.0` alongside the plugin header/constant (unlike M13/M14, which left it at `1.29.0`) — this repo distributes via its own GitHub updater, not the WordPress.org directory |
| Next authorized process step | **Close and release the M13+M14+M15 train — only with explicit approval** (see `docs/milestones/m15-implementation-plan.md` Part G). Do **not** start M16 or release without one. |

## Level A completion review (focused)

Reviewed the full M15 diff (`git diff 0780ba7..HEAD`, 17 files, 1961 insertions / 44 deletions) against `docs/milestones/m15-implementation-plan.md`:

- **Scope matches exactly:** one new, self-contained aggregate read method (`Purchase_Orders::spend_summary_for_supplier()`), one new Internal service (`Supplier_Spend_Service`), one new presentation method (`Purchasing_Page::render_spend_summary_section()`), 5 new test files, one CI-filter fix (see below), documentation (including retroactive M14 baseline/audit sync), version bump. No spend rollup across suppliers, no FX conversion, no trend charts, no supplier merge, no grace-days Settings UI, no suggestion-source UI, no Position drilldown, no storefront change, no Coverage/Forecast/Reservations/Inbound Shipment/Warehouse locations, no `Plugin` god-class refactor, no change to `Supplier_Order_History_Service` or its output — none touched, none mentioned as done.
- **No schema / `DB_VERSION` change:** `includes/class-wc-inventory-overview-install.php` has zero diff against the M15 base (`0780ba7`); `DB_VERSION` constant unchanged at `'10'`.
- **No mutation path (INV-M15-3):** architecture guard confirms `Supplier_Spend_Service` and `spend_summary_for_supplier()`'s own method body contain no `->insert(`/`->update(`/`->delete(`/`INSERT INTO`/`UPDATE `/`DELETE FROM`/`set_stock_quantity`/`update_post_meta`/`update_option` anywhere.
- **Committed-status filtering correct (BR-M15-1, INV-M15-1):** unit tests confirm `draft`- and `cancelled`-only suppliers return an empty result at both the read-owner and service layers, and that a committed PO alongside a draft/cancelled one contributes only its own value; a dedicated integration test scopes this assertion to the Spend Summary section specifically (not the whole page, since Order History legitimately shows a draft PO's own value elsewhere).
- **Currencies never blended or converted (INV-M15-2):** unit and integration tests confirm a multi-currency supplier produces one row per currency with no summed figure (e.g. no blended `150.00`) anywhere; architecture guard confirms zero FX/currency-blending tokens (`Exchange_Rates::`, `exchange_rate`, `fx_rate`) in both the service and the read method's own body.
- **`po_count` semantics precise (BR-M15-5):** a required mixed-line-currency fixture (one committed PO with an EUR line and a USD line) proves the PO contributes `po_count = 1` to each of the two resulting currency rows independently — never double-counted within one row, never summed across rows into a supplier-wide count.
- **Approved-read-owner-only sourcing (INV-M15-3):** architecture guard confirms `Supplier_Spend_Service` contains zero `$wpdb`/`Purchase_Order_Lines::`/`Goods_Receipts::`/`Receipt_Lines::`/`Receipt_Costs::`/`Suppliers::` tokens and calls only `Purchase_Orders::spend_summary_for_supplier()`.
- **Sole-consumer discipline (INV-M15-4):** architecture guard confirms only `class-wc-inventory-overview-purchasing-page.php` calls `Supplier_Spend_Service::` anywhere in `includes/`.
- **Query/performance contract verified at scale, not assumed:** a 200-PO/3-currency performance suite measures (not asserts by inspection) exactly 1 query for `get_summary()` regardless of history size, and exactly 1 query for both an uncommitted-only and a zero-PO supplier — isolated to the `Supplier_Spend_Service::get_summary()` call itself via a query-counting wrapper.
- **Section ordering correct:** a dedicated integration test confirms Spend Summary renders before Observed Lead Time, which renders before Order History — matching the approved plan exactly.
- **No new capability; existing gate reused:** the section renders inside `render_supplier_detail()`, already gated by `current_user_can('manage_woocommerce')` — confirmed both by inspection and by a behavioral integration test denying a subscriber-role user.
- **No new dependency:** `composer.json`/`composer.lock` have zero diff against the M15 base.
- **Existing behavior unaffected (regression, isolated method):** because the Supplier detail page intentionally gains a new section, a whole-page diff would be the wrong comparison. Instead, M13 (`Test_WC_IO_PO_Print*`) and M14 (`Test_WC_IO_Supplier_Order_History*`) were re-run via an isolated `--filter` against the fresh, uncontended test database: **57/57 pass, 0 failures/errors/risky** — byte-for-byte behavior of both milestones' own test suites unchanged. `spend_summary_for_supplier()` is additive and has zero effect on `values_bulk()`, `line_total()`, `list()`, or any of their callers.
- **CI fully green** on the draft PR (`PHP Parallel Lint`, `PHP lint and build ZIP`, `PHPUnit`, all `pass`).
- **Documentation accurate; no document claims M15 released:** every mention of M15 alongside release-status language ("frozen", "unreleased", "not yet merged, tagged, or released") is consistent across `CLAUDE.md`, `CHANGELOG.md`, and `readme.txt`. `docs/ARCHITECTURE_BASELINE_v1.24.0.md` and `docs/architecture-audit.md` are brought current for both M14 (retroactively — a documentation-currency gap found during M15 discovery, not touched at M14's own freeze) and M15; the M14 plan file itself (`docs/milestones/m14-implementation-plan.md`) was not modified.

### One genuine issue found and fixed during this freeze pass

While preparing the local completion gates, the default focused-suite filter in `tests/docker/run-phpunit.sh` (the same filter CI's "Run M1–M12-focused suite" step invokes with no arguments) was found to be **missing** the new `Test_WC_IO_Supplier_Spend_` prefix. `Test_WC_IO_PO_Spend_Summary` was already covered by the pre-existing `Test_WC_IO_PO_` entry, but the four new `Supplier_Spend_*` classes (service, architecture, admin, performance — 22 of the 32 new M15 tests) would have been **silently skipped** by both the local default run and CI's focused-suite gate, with no failure and no warning, exactly the "intended M15 tests actually discovered" risk the approved plan's CI contract calls out.

**Root cause:** the filter is an explicit allowlist of class-name prefixes, updated by hand at each milestone (same pattern M9–M14 each followed) — M15's own prefix was never added.

**Fix:** added `Test_WC_IO_Supplier_Spend_` to the filter regex in `tests/docker/run-phpunit.sh`, with an inline comment explaining why (mirrors M14's own `Supplier_Order_History_` addition). Re-verified: the M1–M15 focused suite now runs **645 tests** (613 M1–M14 baseline + all 32 new M15 tests), confirming the fix actually pulls every new class in, not just asserting it should.

A second, unrelated hazard was self-inflicted and corrected during this freeze pass, not a defect in the implementation: an early validation run of a narrow `--filter` was accidentally executed *concurrently* with a separate full-integration-suite run against the same shared ephemeral MariaDB container, corrupting that run's result (spurious "table doesn't exist" errors and deadlocks from two PHPUnit processes resetting/reading the same database at once). The stack was torn down (`docker compose down -v`) and rebuilt clean; every suite reported in this checklist is from a sequential, non-concurrent re-run against the fresh container.

No other documentation/factual error requiring remediation was found. No genuine architecture discrepancy was found.

## Explicit non-actions at this freeze

- Do not merge PR #16 into `main`
- Do not merge PR #14 (M13) or PR #15 (M14) into `main`
- Do not tag `v1.30.0`, `v1.31.0`, or `v1.32.0`
- Do not publish a GitHub Release
- Do not deploy
- Do not perform an independent (Level B) audit — not triggered for this milestone
- Do not start M16
- Do not close the feature train

## Local quality gates (pre-push)

| Gate | Result |
|------|--------|
| PHP lint (`php -l`, every non-vendor file) | Pass — 0 failures |
| PHPCS (M15-touched files) | Pass on production files (`purchase-orders.php`/`supplier-spend-service.php`/`wc-inventory-overview.php`: 0 errors; `purchasing-page.php`: 0 errors, 15 pre-existing warnings unrelated to M15); new test files carry only the same pre-existing test-class file-naming/doc-comment sniff findings already present on their M14 siblings (not CI-gated, see `docs/testing.md`) — auto-fixable alignment/quoting issues corrected via `phpcbf` |
| Composer validate | Not re-run separately this freeze; `composer.json`/`composer.lock` have zero diff against the M15 base |
| Docker Compose config | Implicit pass (every local run below depends on it) |
| Unit suite | OK — 343 tests, 1868 assertions, 0 risky |
| M1–M15 focused suite | OK — 645 tests, 3049 assertions, 0 risky (was 613 before the CI-filter fix above; confirms all 32 new M15 tests are now discovered) |
| Integration suite (full, unfiltered) | OK — 313 tests, 1224 assertions, 0 risky |
| M13/M14 regression spot-check (isolated `--filter`) | OK — 57 tests, 215 assertions, 0 risky |
| M15-only tests (aggregate + service + architecture + admin + performance) | OK — 32 tests, 103 assertions |
| `release-audit.sh --development` | Pass — version `1.32.0` consistent, ZIP built (96 entries) |
| GitHub Actions (draft PR #16) | Pass — PHP Parallel Lint, PHP lint and build ZIP, PHPUnit all `pass` |
