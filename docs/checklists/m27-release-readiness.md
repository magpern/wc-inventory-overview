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

### Mandatory plan sections verified

- [x] **§4.1** Historical reconciliation in admin guide + pre-post UI notice
- [x] **§7.1** Authoritative WAC read conventions in `Restock_Service` outbound path
- [x] **§15.1** Compensation model documented; tests prove restore behaviour (not naive SQL rollback)
- [x] **§17.1** Void uses immutable posted delta + current-state restoration
- [x] No REST/CLI in M27; internal hooks only
- [x] 100-line cap enforced in service and admin UI

### Test gates (Docker PHPUnit)

- [x] Focused M27 filter: `Test_WC_IO_Stock_Adjustment_`, `Test_WC_IO_Outbound_WAC_`
- [ ] Full suite green (run before tag)
- [ ] PHPCS / architecture guards green
- [ ] `scripts/release-audit.sh --development`

## Release actions (not performed in M27 implementation)

- [ ] PR review + merge to `main`
- [ ] Tag `v1.44.0` + GitHub Release ZIP
- [ ] DEV deploy (explicit request only)
