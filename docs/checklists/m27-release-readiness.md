# M27 Release Readiness Checklist

**Status:** **Frozen on feature branch — awaiting review / release authorization**  
**Date:** 2026-08-31  
**Version:** 1.44.0 (development target)  
**DB_VERSION:** 12 (new `wc_io_stock_adjustments`, `wc_io_stock_adjustment_lines`)  
**Branch:** `feature/m27-personal-use-stock-adjustment`  
**Plan:** `docs/milestones/m27-implementation-plan.md` (immutable; WP-M27-0 commit `b009eea`)  
**ADR:** `docs/adr/0004-outbound-stock-adjustment-ownership.md`

## Implementation Summary

### Work packages

- **WP-M27-0:** Plan + ADR-0004 frozen (`b009eea`)
- **WP-M27-1:** Schema v12, repositories, SA numbering (`37c4f4c`)
- **WP-M27-2/3:** Outbound WAC mutators + `Stock_Adjustment_Service` post/void (`555b29b`)
- **WP-M27-4/5:** Admin tab, preview AJAX, movements drill-down, Overview prefill (`99f293b`, `010a970`)
- **WP-M27-6:** Tests, admin guide §4.1, release docs (this commit)
- **Additive, non-M27:** Inventory & Profit hub gains linked nav-tabs to the existing Purchasing page (Purchase Orders / Receive Stock / Suppliers / Planning) (`30227ff`); unrelated pre-existing expected-delivery test-fixture date time-bomb fixed (`bb542f1`) — see below

### Mandatory plan sections verified

- [x] **§4.1** Historical reconciliation in admin guide + pre-post UI notice
- [x] **§7.1** Authoritative WAC read conventions in `Restock_Service` outbound path
- [x] **§15.1** Compensation model documented; tests prove restore behaviour (not naive SQL rollback)
- [x] **§17.1** Void uses immutable posted delta + current-state restoration
- [x] No REST/CLI in M27; internal hooks only
- [x] 100-line cap enforced in service and admin UI

### Test gates (Docker PHPUnit)

- [x] Focused M27 filter: `Test_WC_IO_Stock_Adjustment_`, `Test_WC_IO_Outbound_WAC_`
- [x] Full suite green (run before tag) — 2026-09-13/14: unit 572/572, default M1–M27 suite 1359/1359, integration `--exclude-group performance` 808/808. Found and fixed one pre-existing, unrelated test-content bug in the process: `tests/integration/expected-delivery/test-expected-delivery-{renderer,service}.php` hardcoded fixture dates (`2026-09-01`/`2026-09-15`) that had lapsed into the past, flipping 5 tests to see "Expected soon" instead of the literal date/week they asserted — a test time-bomb, not a production defect. Fixed via a `future_customer_safe_date()` helper (+180 days from now) in both files (`bb542f1`); production code untouched.
- [x] PHPCS / architecture guards green — architecture-guard tests (part of the `unit` testsuite) pass. Note: full repo-wide WPCS style linting (`phpcs.xml.dist`) is **not** an actual CI gate for this repo (only `parallel-lint` runs in CI) and carries ~2,615 pre-existing style findings across 240 files, unrelated to M27; not a release blocker.
- [x] `scripts/release-audit.sh --development` — passed, version 1.44.0. `--release` mode also passed (release notes present at `docs/GITHUB_RELEASE_NOTES_1.44.0.md`).

## Release actions

- [ ] PR review + merge to `main`
- [ ] Tag `v1.44.0` + GitHub Release ZIP
- [ ] DEV deploy (explicit request only)
