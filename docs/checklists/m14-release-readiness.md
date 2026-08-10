# M14 Release Readiness — Supplier Order History

**Status:** Level A freeze complete.
**Date:** 2026-08-11
**Branch tip (at freeze):** `feature/m14-supplier-order-history` (branched from `feature/m13-printable-purchase-order`)
**CI proof PR:** https://github.com/magpern/wc-inventory-overview/pull/15 (**DRAFT — DO NOT MERGE**)

## Freeze record

| Item | Value |
|------|--------|
| M14 implementation | Complete |
| Level A completion review | Complete |
| Independent (Level B) audit | **Not performed** — Level A classification, no schema/migration/mutation/public-API/ownership-boundary/destructive/security/storefront/concurrency trigger applies |
| Plugin development version | `1.31.0` |
| `DB_VERSION` | `10` (unchanged; no schema migration) |
| GitHub Actions | Green — CI run `31438695616`, Tests run `31438695643` |
| Schema change | None |
| Mutation change | None |
| New public API | None |
| New capability | None |
| New public hook/filter | None |
| New dependency | None (`composer.json`/`composer.lock` unchanged) |
| Immutable plan | `docs/milestones/m14-implementation-plan.md` @ `0331c39` — untouched after materialization (single commit touches this file) |
| Individually released | **No** — intentional |
| Feature train | **M14 continues** the same unreleased train M13 opened after v1.29.0 |
| Next authorized process step | **Decide: continue with M15, or close and release the M13+M14 train — only with explicit approval.** Do **not** start M15 or release without one. |

## Level A completion review (focused)

Reviewed the full M14 diff (`git diff 9632215..HEAD`, 15 files, 1980 insertions / 12 deletions) against `docs/milestones/m14-implementation-plan.md`:

- **Scope matches exactly:** one additive read method (`Purchase_Orders::values_bulk()`), one new Internal service (`Supplier_Order_History_Service`), one new presentation method (`Purchasing_Page::render_order_history_section()` + its pagination helper + value formatter), 5 new test files, CI filter update, documentation, version bump. No spend analysis, no supplier merge, no grace-days Settings UI, no suggestion-source UI, no Position drilldown change, no storefront change, no Coverage/Forecast/Reservations/Inbound Shipment/Warehouse locations, no `Plugin` god-class refactor — none touched, none mentioned as done.
- **No schema / `DB_VERSION` change:** `includes/class-wc-inventory-overview-install.php` has zero diff against the M14 base (`9632215`, M13's frozen tip); `DB_VERSION` constant unchanged at `'10'`.
- **No mutation path (INV-M14-1):** architecture guard confirms `Supplier_Order_History_Service` and `values_bulk()` contain no `->insert(`/`->update(`/`->delete(`/`INSERT INTO`/`UPDATE `/`DELETE FROM`/`set_stock_quantity`/`update_post_meta`/`update_option` anywhere in their bodies.
- **Values never blended across POs or currencies, never conflated with landed cost or inventory valuation (INV-M14-2):** `values_bulk()` groups strictly by `po_id` (tested directly, including a mixed-EUR/USD fixture); the rendered UI labels the columns "Ordered Value" / "Received Value (PO Cost)" and a dedicated integration test (`test_multiple_currencies_never_blended_into_a_total`) asserts no summed figure (e.g. a blended `150.00`) ever appears on the page.
- **Approved-read-owner-only sourcing (INV-M14-3):** architecture guard confirms `Supplier_Order_History_Service` contains zero `$wpdb`/`Purchase_Order_Lines::`/`Goods_Receipts::`/`Receipt_Lines::`/`Receipt_Costs::`/`Suppliers::` tokens and calls only `Purchase_Orders::count()`/`list()`/`values_bulk()`; a second guard confirms `values_bulk()` itself contains no write statement.
- **Status-inclusive (INV-M14-4):** a dedicated unit test and a dedicated integration test both confirm `draft`, `placed`, `partially_received`, `received`, `cancelled`, and `closed_short` POs all appear in a supplier's history — unlike M13's print feature, which deliberately excludes `draft`.
- **Sole-consumer discipline:** architecture guard confirms only `class-wc-inventory-overview-purchasing-page.php` calls `Supplier_Order_History_Service::` anywhere in `includes/`.
- **Query/performance contract verified at scale, not assumed:** a 200-PO-for-one-supplier performance suite measures (not asserts by inspection) exactly 3 M14-added queries for a non-empty page (independent of `per_page` ∈ {10, 20, 50} and of page number), and exactly 1 for a zero-PO supplier — isolated to the `Supplier_Order_History_Service::get_page()` call itself via a query-counting wrapper, not the full Supplier detail page request.
- **Pagination uses its own dedicated parameter:** `wc_io_supplier_order_history_page`, never the generic `paged`; out-of-range pages degrade to a distinct "No results on this page." message (never confused with the zero-history "No purchase orders yet" message); links are built via `add_query_arg()`/`remove_query_arg()`, matching the existing admin convention.
- **No new capability; existing gate reused:** the section renders inside `render_supplier_detail()`, already gated by `current_user_can('manage_woocommerce')` — confirmed both by inspection and by a behavioral integration test denying a subscriber-role user.
- **No new dependency:** `composer.json`/`composer.lock` have zero diff against the M14 base.
- **Existing behavior unaffected:** the pre-existing Supplier detail screen (Observed Lead Time, On-Time Rate, archive/reactivate) and the existing Purchase Orders admin screen (list/filter/create/edit/place/cancel/close-short/duplicate/print) passed unmodified as part of the full 613-test M1–M14-focused run; `values_bulk()` is additive and has zero effect on the existing `line_total()` or any of its callers.
- **CI fully green** on the draft PR (CI + Tests workflows, both `completed`/`success`).
- **Documentation accurate; no document claims M14 released:** every mention of M14 alongside release-status language ("frozen", "unreleased", "not yet merged, tagged, or released") is consistent across `CLAUDE.md`, `CHANGELOG.md`, and `readme.txt`; `readme.txt`'s `Stable tag` remains `1.29.0`.

### One genuine issue found and fixed during this freeze pass

While running the full (unfiltered) integration suite locally — a step beyond what the M1–M14 focused CI filter alone exercises — `test_capability_gate_denies_unauthorized_user` was found to silently kill the entire PHPUnit process (no assertion failure, no summary; the whole run just stopped) when run after roughly 275+ preceding integration tests, but never in isolation or in short combinations. Bisection (excluding classes/tests one at a time against the otherwise-unmodified pre-existing suite, which itself passes 291/291 clean without any M14 test) isolated it to exactly this one test.

**Root cause:** `tests/integration/expected-delivery/test-expected-delivery-renderer.php` permanently `define()`s the `DOING_AJAX` constant `true` earlier in the same PHP process (constants cannot be unset). This is pre-existing, correct behavior for that test's own purpose. Once set, WordPress's `wp_die()` routes through the `wp_die_ajax_handler` filter instead of the plain `wp_die_handler` filter for every subsequent `wp_die()` call in the process. `WP_UnitTestCase` only wires the plain filter to throw `WPDieException`; the AJAX-specific filter is only ever wired by the separate `WP_Ajax_UnitTestCase` base class, which no test in this codebase's `integration` suite uses. My capability-gate test was the *only* test anywhere in `tests/integration/` exercising a `wp_die()` → `WPDieException` conversion at all, so it was the first (and only) test able to expose this latent, pre-existing ordering hazard.

**Fix (test-only, self-contained):** the test now forces both `wp_die_handler` and `wp_die_ajax_handler` to throw `WPDieException`, at `PHP_INT_MAX` priority, for its own duration only (removed in a `finally` block) — making it correct regardless of whether `DOING_AJAX` has leaked `true` by the time it runs. No production code changed. `tests/integration/expected-delivery/test-expected-delivery-renderer.php` was not modified (its behavior is correct for its own purpose; the hazard is a latent interaction, not a bug in that file, and fixing it there was not part of the approved M14 scope).

Re-verified after the fix: full unit suite (322/322), full integration suite (302/302), and the M1–M14 focused suite (613/613) all pass clean, 0 risky, 0 failures, 0 errors.

No other documentation/factual error requiring remediation was found. No genuine architecture discrepancy was found.

## Explicit non-actions at this freeze

- Do not merge PR #15 into `main`
- Do not merge PR #14 (M13) into `main`
- Do not tag `v1.30.0` or `v1.31.0`
- Do not publish a GitHub Release
- Do not deploy
- Do not perform an independent (Level B) audit — not triggered for this milestone
- Do not start M15
- Do not close the feature train

## Local quality gates (pre-push)

| Gate | Result |
|------|--------|
| PHP Parallel Lint | Pass |
| PHPCS (M14-touched files) | Pass on production files (0 errors/warnings); pre-existing-convention-matching findings only on new test files (file-doc/class-doc-spacing/naming conventions already present on every other test file in this repo — not CI-gated, see `docs/testing.md`) |
| Composer validate | Not re-run separately this freeze; `composer.json`/`composer.lock` have zero diff against the M14 base |
| Docker Compose config | Implicit pass (every local run below depends on it) |
| Unit suite | OK — 322 tests, 1786 assertions, 0 risky |
| M1–M14 focused suite | OK — 613 tests, 2940 assertions, 0 risky |
| Integration suite (full, unfiltered) | OK — 302 tests, 1197 assertions, 0 risky |
| M14-only tests (service + architecture + admin + performance + values_bulk) | OK — 30 tests, 129 assertions |
| `release-audit.sh --development` | Pass — version `1.31.0` consistent, ZIP built (95 entries) |
| GitHub Actions (draft PR #15) | Pass — CI `31438695616`, Tests `31438695643` |
