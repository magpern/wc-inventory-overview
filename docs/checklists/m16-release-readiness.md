# M16 Release Readiness — PO Expected-Date & Delay Transparency

**Status:** Level A freeze complete.
**Date:** 2026-08-11
**Branch tip (at freeze):** `feature/m16-po-expected-date-delay-transparency` (branched from `main` at commit `61488e9`)
**CI proof PR:** https://github.com/magpern/wc-inventory-overview/pull/18 (**DRAFT — DO NOT MERGE**)

## Freeze record

| Item | Value |
|------|--------|
| M16 implementation | Complete |
| Level A completion review | Complete |
| Independent (Level B) audit | **Not performed** — Level A classification, no schema/migration/domain-mutation/public-API/ownership-boundary/destructive/security/storefront/concurrency trigger applies |
| Canonical implementation base | `61488e9` (main, verified `== origin/main`, matches v1.32.0 baseline) |
| Plugin development version | `1.33.0` |
| `DB_VERSION` | `10` (unchanged; no schema migration) |
| GitHub Actions | Green — PR #18: "PHP Parallel Lint" pass, "PHP lint and build ZIP" pass, "PHPUnit" pass (run `31504039127`/`31504039247`) |
| Schema change | None — `includes/class-wc-inventory-overview-install.php` has zero diff against `61488e9` |
| Domain/operational mutation | None — one existing settings-option write only (`WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS`, via `Settings::save_from_post()`), not classified as a new domain mutation |
| New public API | None |
| New capability | None |
| New public hook/filter | None |
| New dependency | None (`composer.json`/`composer.lock` have zero diff against `61488e9`) |
| Immutable plan | `docs/milestones/m16-implementation-plan.md` @ `173f75f` — untouched after materialization (single commit touches this file) |
| Individually released | **No** — intentional |
| Feature train | **M16 is the first milestone** of a new, not-yet-named post-v1.32.0 train |
| Next authorized process step | **The release-timing/train decision for M16 — only with explicit approval** (standalone release vs. opening a train, e.g. with the supplier merge tool as M17; see `docs/milestones/m16-implementation-plan.md` Part G). Do **not** start M17 or release without one. |

## Level A completion review (focused)

Reviewed the full M16 diff (`git diff 61488e9..HEAD`, 26 files, 1196 insertions / 43 deletions) against `docs/milestones/m16-implementation-plan.md`:

- **Scope matches exactly:** three surfaces (suggestion provenance, grace-days Settings field, drilldown Supplier/Status columns), 6 production files (exactly section 23's list: `expected-date-suggestion-service.php`, `po-admin.php`, `settings.php`, `plugin.php`, `purchase-order-lines.php`, `list-table.php`), one JS asset (`assets/po-admin.js`, the necessary client-side half of the New PO screen work), 4 new/extended test files plus one new test file (`tests/unit/settings/test-settings-po-delay-grace-days.php` — a test file, not a production domain class; section 24's "no new class/file" scope is production `includes/` classes, which remains true), one CI-filter addition (`Test_WC_IO_Settings_`, narrow and additive, matching the section 37 CI-discovery contract), documentation, version bump. No supplier merge, no storewide spend rollup, no Coverage/Forecast, no Reservations, no Inbound Shipment, no warehouse locations, no REST/GraphQL, no Plugin god-class refactor, no unrelated PHPCS cleanup, no change to the suggestion-resolution algorithm itself, no change to how `PO_Delay`/`Expected_Deadline` compute the deadline, no storefront change — none touched, none mentioned as done.
- **No schema / `DB_VERSION` change:** `includes/class-wc-inventory-overview-install.php` has zero diff against `61488e9`; `DB_VERSION` constant unchanged at `'10'`.
- **BR-M16-1/BR-M16-2 (suggestion provenance) correct:** `Expected_Date_Suggestion_Service::resolve_one()`/`empty_suggestion()` return `sample_count`/`average_days`, both `null` unless `source === 'observed'`, both read from the same `$stats` array already fetched via `Supplier_Lead_Time_Service::get_stats_bulk()` — confirmed by unit test asserting no additional query and by the unmodified `Test_WC_IO_Expected_Date_Suggestion_Performance` suite still passing (exactly 1 query regardless of supplier count). `days` is unaffected (dedicated unit test `test_m16_evidence_keys_always_present_and_days_unaffected`). `PO_Admin::lead_time_suggestions_for_localize()` passes the new fields through to `wcIoPoAdmin.leadTimeSuggestions`; `assets/po-admin.js` builds the hint text from `sample_count`/`average_days` for `observed`, `days` for `configured`, never `days` as observed-history evidence — confirmed by integration tests for all three sources plus the edit-screen no-op case.
- **BR-M16-3 (provenance is advisory-only):** the hint renders in a plain `<p class="description">` with no `name` attribute — never part of `$_POST`; confirmed by inspection (no new form field was added) and the existing `manuallyEdited` gate in `po-admin.js` now also clears the hint on manual edit.
- **BR-M16-4 (grace-days validate-or-preserve) exact:** `Settings::maybe_save_po_delay_grace_days()` implements the precise contract — missing field, non-numeric, non-clean-integer (decimal/scientific-notation/trailing-characters), and out-of-range (`<0`/`>365`) all skip the `update_option()` call entirely, leaving the prior value untouched; a clean integer 0–365 (including both boundaries) saves exactly as submitted. Deliberately not `absint()`-style coercion. Verified by a 13-case table-driven unit test (`Test_WC_IO_Settings_PO_Delay_Grace_Days`) covering every case named in the plan, including the specific "-5 must never become 5 or 0" assertion.
- **BR-M16-5 (single option, no duplicate constant):** `Settings::get_po_delay_grace_days()` and `maybe_save_po_delay_grace_days()` both reference `WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS` directly; no new option constant was introduced. Confirmed by a dedicated test asserting the getter and `PO_Delay::grace_days_from_option()` always agree.
- **INV-M16-2 (sole mutator) enforced:** new architecture-guard test confirms no `includes/` file other than `class-wc-inventory-overview-settings.php` calls `update_option( WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS, ...)`.
- **BR-M16-6/BR-M16-7/BR-M16-8 (drilldown columns) exact:** `query_open_lines()` gains `po.supplier_name_snapshot AS supplier_name, po.status AS po_status` on the existing joined `po` row — no new join, no new query. `render_position_drilldown_section()` renders exactly seven columns in the fixed order PO number → Supplier → Status → Outstanding → Expected date → Confidence → Delayed. Supplier uses the immutable PO-time snapshot (verified to survive supplier archive by a dedicated integration test); Status uses `PO_Statuses::label()` (verified against the shared label map, not a locally re-derived string).
- **INV-M16-3 (M3 sole-caller guard unmodified):** `tests/unit/inventory-position/test-inventory-position-architecture.php` was not touched and continues to pass — the drilldown edit stayed entirely inside the already-whitelisted `class-wc-inventory-overview-purchase-order-lines.php`.
- **INV-M16-4 (zero new repository/SQL queries) verified at the query level, not asserted by inspection:** the existing `Test_WC_IO_Expected_Date_Suggestion_Performance` suite (exactly 1 query regardless of supplier count, 10/40/200-supplier scale) and `test_position_query_count_bounded_for_twenty_plus_rows` (≤2 SELECTs against the PO-lines join) were both re-run unmodified and remain green with the M16 additions in place. The Settings grace-days field's `get_option()`/`update_option()` calls are correctly outside this invariant per the plan.
- **INV-M16-5/INV-M16-6 (consistency/determinism):** the observed message only ever renders when `source === 'observed'` (single branch in `buildSuggestionMessage()`, no duplicated resolution logic); repeated renders with unchanged fixture data produce identical output (implicit in every integration test's deterministic assertions).
- **INV-M16-7 (capability gates unchanged):** no new capability constant anywhere in the diff (confirmed: zero diff on `class-wc-inventory-overview-purchasing-caps.php`); Settings tab, New PO screen, and Inventory Overview all keep their pre-existing capability checks (confirmed by inspection — no `current_user_can`/`Purchasing_Caps::` call was added, moved, or removed).
- **No new PHPCS violation:** all six touched production files' PHPCS output was diffed line-by-line against the changed-line ranges from `git diff main`; the only new finding (a Yoda-condition error introduced by the grace-days validation logic) was fixed in a follow-up commit, verified by re-running PHPCS (settings.php: 13 → 12 errors, exactly matching the pre-existing baseline with zero net-new findings across all six files).
- **CI fully green** on the draft PR, both before and after the PHPCS fix (`PHP Parallel Lint`, `PHP lint and build ZIP`, `PHPUnit`, all `pass`).
- **Documentation accurate; no document claims M16 released:** every mention of M16 alongside release-status language ("frozen", "unreleased", "not merged, tagged, or released") is consistent across `CLAUDE.md`, `CHANGELOG.md`, `readme.txt`, `docs/ARCHITECTURE_BASELINE_v1.24.0.md`, and `docs/architecture-audit.md` — scanned via `git diff main` for accidental "released as"/"tagged and published" phrasing near M16 text; none found. `docs/admin-guide-suppliers.md` gained a "Suggestion Provenance (M16)" section and an updated grace-days pointer; `docs/rollback-plan.md` and `docs/release-runbook.md` gained M16 sections following the exact M9–M15 precedent structure; `docs/checklists/validation-checklist.md` gained an M16 verification section. `docs/testing.md` and `tests/README.md`'s stale "M1–M6"/"M1–M11"/"M1–M15" filter-description wording was corrected to "M1–M16" (incidental doc hygiene, as the plan permitted).

### One genuine test bug found and fixed during this freeze pass

The GitHub Actions CI run for the initial push failed one test — `Test_WC_IO_Inventory_Position_List_Table::test_drilldown_column_order_is_fixed_per_br_m16_8` — that had passed locally. Root cause: the test used a bare `strpos($html, '<thead><tr>')` to locate "the" drilldown table's header, but `render_table_html()`/`prepare_items()` is not scoped to the test's own fixture — other products from earlier tests in the same class remain present (`dbDelta()`'s `CREATE TABLE` statements issue an implicit MySQL commit, which breaks `WP_UnitTestCase`'s per-test transaction rollback — a pre-existing quirk of this test suite, not introduced by M16). When a leftover variable-parent product's "Variations on this page" mini-table (also `<thead><tr>`, but with SKU/Attributes/Stock/Status columns, no "PO number") happened to render before this test's own product row, the assertion false-failed. **Fix:** scoped the header search to the test's own `wc-io-detail-panel-{product_id}` marker first. Re-verified: the fix reproduces and passes under the exact same accumulation condition (running the whole test class together, where the bug was actually triggered), and the full default suite remains green (667 tests, 3126 assertions). A second, unrelated fixture gap (missing `wc_io_receipt_lines` rows in a direct-`$wpdb`-insert observed-suggestion fixture, and a missing explicit `supplier_name_snapshot` in a drilldown-archive fixture, since the raw repository `create_draft()` does not auto-populate it the way `PO_Service::create_draft()` does) was found and fixed earlier, before the first CI push.

A follow-up PHPCS pass (not CI-gated, but checked per established milestone freeze precedent) found and fixed one Yoda-condition violation introduced by the grace-days validation logic (`class-wc-inventory-overview-settings.php`).

No other documentation/factual error requiring remediation was found. No genuine architecture discrepancy was found.

## Explicit non-actions at this freeze

- Do not merge PR #18 into `main`
- Do not tag `v1.33.0`
- Do not publish a GitHub Release
- Do not deploy
- Do not perform an independent (Level B) audit — not triggered for this milestone
- Do not start M17
- Do not open or close a feature train

## Local quality gates (pre-push, final state at `6aa6a7f`)

| Gate | Result |
|------|--------|
| PHP lint (`vendor/bin/parallel-lint`, every non-vendor file) | Pass — 181 files, 0 syntax errors |
| PHPCS (M16-touched production files) | Pass — zero net-new errors/warnings across all six files, verified line-by-line against `git diff main`'s changed-line ranges (settings.php: 12 errors, matching pre-existing baseline after fixing one newly-introduced Yoda-condition finding); not CI-gated (see `docs/testing.md`) |
| Composer validate | Not re-run separately this freeze; `composer.json`/`composer.lock` have zero diff against the M16 base |
| Docker Compose config | Implicit pass (every local run below depends on it) |
| Unit suite (full, unfiltered) | OK — 362 tests, 1916 assertions, 0 risky |
| M1–M16 focused suite | OK — 667 tests, 3126 assertions, 0 risky (verified `Test_WC_IO_Settings_` and all other M16-touched classes are discovered via `--list-tests` against the exact default `run-phpunit.sh` filter, not inferred from the regex alone) |
| Integration suite (full, unfiltered) | OK — 316 tests, 1252 assertions, 0 risky |
| `release-audit.sh --development` | Pass — version `1.33.0` consistent, ZIP built (96 entries) |
| GitHub Actions (draft PR #18, final commit `6aa6a7f`) | Pass — PHP Parallel Lint, PHP lint and build ZIP, PHPUnit all `pass` |
