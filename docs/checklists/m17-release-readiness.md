# M17 Release Readiness — Supplier Merge

**Status:** Level B freeze complete.
**Date:** 2026-08-11
**Branch tip (at freeze):** `feature/m17-supplier-merge` (branched from `main` at commit `2c9e280`)
**CI proof PR:** https://github.com/magpern/wc-inventory-overview/pull/20 (**DRAFT — DO NOT MERGE**)

## Freeze record

| Item | Value |
|------|--------|
| M17 implementation (WP1) | Complete — WP-M17-0 through WP-M17-10 |
| Independent (Level B) audit (WP2) | Complete — fresh Claude instance, no memory of implementation. Found 0 CRITICAL, 1 MAJOR (M17-F1), 3 MINOR (M17-F2/F3/F4) |
| WP3 remediation | Complete — all four WP2 findings remediated in commit `bbc9a1a` |
| Manual/browser acceptance | Complete — performed against the live `dev.biopentra.eu` WordPress instance (see below) |
| Canonical implementation base | `2c9e280` (main, verified `== origin/main`) |
| Plugin development version | `1.34.0` |
| `DB_VERSION` | `11` (new `merged_into_supplier_id` column on `wc_io_suppliers`; new `wc_io_supplier_merges` table) |
| GitHub Actions | Green — PR #20 @ `bbc9a1a`: "PHP Parallel Lint" pass (20s), "PHP lint and build ZIP" pass (19s), "PHPUnit" pass (3m46s) |
| Schema change | Yes — `DB_VERSION` 10 → 11 (Release Trigger per this repo's own lifecycle rule) |
| Domain/operational mutation | Yes — the merge operation itself (Release Trigger: ownership-boundary change, ownership of Purchase Order/Goods Receipt supplier association moves atomically) |
| New public API | None |
| New capability | `WC_Inventory_Overview_Purchasing_Caps::MERGE_SUPPLIER` |
| New public hook/filter | None (INV-M17-10; the only new extension-adjacent surface is a private, test-bootstrap-gated failure-injection seam, structurally inert in production) |
| New dependency | None |
| Immutable plan | `docs/milestones/m17-implementation-plan.md` @ `5199b8f` — untouched after materialization (single commit touches this file; verified again at this freeze via `git log -p`) |
| Individually released | **No** — intentional |
| Feature train | **M17 releases standalone**, not via a train (this repo's own Release Triggers rule: schema change + ownership-boundary change) |
| Next authorized process step | **The standalone release decision for M17 (WP6: push, PR merge, tag `v1.34.0`, GitHub Release, deploy) — only with explicit approval.** Do **not** merge, tag, release, or deploy without one. Do **not** start M18 before that decision. |

## WP2 independent audit summary

A fresh Claude instance (no memory of the implementation session) audited the branch independently, tracing every claim from source rather than trusting any report. Verdict: **WP3 remediation required** before freeze eligibility. No CRITICAL findings — the core merge transaction (highest-risk area) was found correctly built: atomic, exception-safe, deterministically lock-ordered, empirically verified via a live test run (60/60 tests green at the time of audit, 11 queries constant at 500/2,000/5,000-PO scale).

**Findings and remediation:**

| Finding | Severity | Description | Remediation commit |
|---|---|---|---|
| M17-F1 | MAJOR | `PO_Service::update_draft()`/`update_placed()` and `Goods_Receipt_Service::update_draft_from_post()` (existing-record edit paths) used an unlocked `Suppliers::get()` with no eligibility check, unlike the two creation paths hardened in WP-M17-4 — a merged supplier could theoretically be attached to an *existing* PO/receipt via a crafted POST (not reachable through the rendered UI, which already filters to active suppliers only) | `bbc9a1a` |
| M17-F2 | MINOR | The concurrency test suite's own docblock implied more than it proved — it is strictly sequential (proves post-commit rejection empirically) and does not exercise or prove true in-flight blocking/deadlock-avoidance under genuine dual-connection concurrency. Corrected to state precisely what is empirically tested vs. reasoned-only from code inspection + InnoDB/MariaDB locking semantics. The immutable plan document was **not** modified. | `bbc9a1a` |
| M17-F3 | MINOR | WP-M17-2's three new `Suppliers` methods introduced 3 net-new PHPCS findings beyond `suppliers.php`'s pre-existing baseline | `bbc9a1a` |
| M17-F4 | MINOR | Stale `IMPLEMENTATION-STATUS-M17.md` mid-implementation checkpoint doc described later work packages as pending when they were complete | `bbc9a1a` |

All four findings closed in a single WP3 remediation commit. No second independent audit was performed (none required by the lifecycle for WP3 remediation of WP2's own findings) — the remediation was scoped exactly to the four findings, validated with targeted regression tests plus the full gate sequence below, not a fresh audit pass.

### M17-F1 remediation detail

Applied the identical `get_for_update()` + `STATUS_ACTIVE`/`merged_into_supplier_id` eligibility check already used at draft-creation time (WP-M17-4) to the existing-record update paths, scoped to fire only when `supplier_id` is actually present in the update payload — ordinary saves that don't touch the supplier field are unaffected. New test file `tests/integration/supplier-merge/test-supplier-merge-existing-record-protection.php` (5 tests): draft PO update rejects merged supplier, placed PO update rejects merged supplier, draft Goods Receipt update rejects merged supplier, plus two regression tests proving ordinary (non-merged) supplier corrections on existing drafts still work.

## Manual/browser acceptance evidence

Performed against the live `dev.biopentra.eu` WordPress instance (`/opt/biopentra/dev/wc-inventory-overview`, the exact `feature/m17-supplier-merge` checkout, live-mounted into the running `wordpress` container at `/var/www/html/wp-content/plugins/wc-inventory-overview`) — not the ephemeral PHPUnit Docker harness. Driven via `wp eval` (WP-CLI) exercising the exact same PHP methods the HTTP admin handlers call, plus a real rendered-HTML check of the admin page, both against the real MySQL/MariaDB database.

**Procedure followed** (per the plan's Part L fixture policy):
1. **Database backup taken first**: `wp db export` → `pre-m17-acceptance-backup.sql` (20.4 MB), copied out of the container before any mutation.
2. **Disposable fixtures created**, clearly named `M17 Acceptance — ...`: two suppliers (source id 7, target id 8), one archived-extra supplier (id 9, to prove archived-exclusion against real data), one test product, one draft Purchase Order, one draft Goods Receipt.
3. **Real admin workflow exercised** end to end via the actual `Purchasing_Page` handler/render methods (not a synthetic test double).
4. **Database result verified directly** via `wp db query`/`wp eval` against the live table rows.
5. **All fixtures cleaned up by exact recorded ID** afterward; verified zero residue.

**Incidental environment finding (not a code defect):** the live dev database's `wc_io_db_version` option already read `'11'` before this session's first `maybe_upgrade()` invocation could have set it — meaning it had been set by an earlier, unrelated process without the physical schema ever being created (the `wc_io_supplier_merges` table and `merged_into_supplier_id` column were both genuinely absent despite the option reading `'11'`). This is an environment-drift issue, not a plugin defect — `maybe_upgrade()`'s own version-guard logic is correct; something external to this session set the option out of band. Fixed by explicitly re-running `WC_Inventory_Overview_Install::create_tables()` (idempotent, safe) and confirming `assert_schema_shape()` returns `true`. This is exactly the class of drift `docs/release-runbook.md`'s M17 appendix's "pre-tag v11 schema check" step exists to catch before a real production deploy.

**Checklist results, all verified against real data:**

| Check | Result |
|---|---|
| Eligible source supplier shows Merge UI | ✅ Confirmed via real rendered HTML (`wc-io-supplier-merge-form`, heading, warning copy, all present) |
| Target picker AJAX works and excludes source/archived targets | ✅ Confirmed — search for target suppliers returned only the eligible target (id 8), correctly excluding the source (id 7, via `exclude_supplier_id`) and a freshly-archived extra supplier (id 9, via the active-status filter) |
| Typed confirmation field required, nonce/token fields present | ✅ Confirmed in rendered HTML |
| Wrong confirmation rejected server-side | ✅ Confirmed — crafted request with correct nonce/token but wrong confirmation text redirected to the error notice; source supplier verified unchanged (`status=active`, `merged_into_supplier_id=NULL`) |
| Successful merge redirects correctly | ✅ Confirmed — redirected to `supplier_id=8&wc_io_supplier=merged` (the target) |
| PO/GR references move | ✅ Confirmed — PO's `supplier_id` and GR's `supplier_id` both moved from 7 to 8 |
| Snapshots remain unchanged | ✅ Confirmed — both PO and GR `supplier_name_snapshot` still read "M17 Acceptance — Source Supplier" post-merge |
| Source becomes permanently dissolved | ✅ Confirmed — `status=archived`, `merged_into_supplier_id=8` |
| Exactly one audit record with correct counts | ✅ Confirmed — 1 row, `purchase_orders_reassigned=1`, `goods_receipts_reassigned=1`, `performed_by=1` |
| Reactivate is unavailable | ✅ Confirmed both ways — `Suppliers::reactivate(7)` returns `false`; rendered detail-screen HTML shows the "merged into" notice with no Reactivate link |
| Existing-PO update back to the dissolved source is rejected | ✅ Confirmed — `PO_Service::update_draft(13, ['supplier_id' => 7])` returned `wc_io_po_supplier_inactive` (proves the M17-F1 fix works against real data, not just the test harness) |
| Target's derived statistics reflect reassignment | ✅ Confirmed — target's Order History count moved from 0 to 1, source's moved from 1 to 0 (Spend Summary correctly showed 0 for both, since the fixture PO was left in `draft` status, which M15's own committed-status business rule excludes by design — not a gap) |
| Cleanup complete, zero residue | ✅ Confirmed — all fixture rows (suppliers 7/8/9, PO 13 + lines, GR 8 + lines, the one merge-audit row, product 6442) deleted by exact ID; re-queried and confirmed absent |

No destructive action was taken against any real operational supplier, PO, or receipt. The pre-acceptance backup was retained in case cleanup had failed; it was not needed.

## Local quality gates (final state at `bbc9a1a`)

| Gate | Result |
|------|--------|
| PHP lint (`vendor/bin/parallel-lint`, every non-vendor file) | Pass — 193 files, 0 syntax errors |
| Composer validate (`--strict`) | Pass — `./composer.json is valid` |
| Docker Compose config validation | Pass |
| PHPCS (repo-wide) | Not CI-gated (per `docs/testing.md`) — 1325 errors / 728 warnings across 130 files, consistent with this repo's long-documented pre-existing baseline; M17's own new/modified files independently verified to introduce **zero net new drift** beyond pre-M17 baseline (`suppliers.php`: 21/12, exactly matching pre-M17; `po-service.php`/`goods-receipt-service.php`: 0 findings) |
| Unit suite (full, unfiltered) | OK — 392 tests, 2028 assertions, 0 risky |
| M1–M17 focused suite | OK — 732 tests, 3345 assertions, 0 risky (verified `Test_WC_IO_Supplier_Merge_*`/`Test_WC_IO_Schema_V11_Upgrade` — all 10 M17 test classes, 65 test methods — discovered via `--list-tests` against the exact default `run-phpunit.sh` filter, not inferred from the regex alone) |
| Integration suite (full, unfiltered) | OK — 351 tests, 1360 assertions, 0 risky |
| `release-audit.sh --development` | Pass — version `1.34.0` consistent, ZIP built (100 entries) |
| GitHub Actions (draft PR #20, final commit `bbc9a1a`) | Pass — PHP Parallel Lint, PHP lint and build ZIP, PHPUnit all `pass` |
| Manual/browser acceptance | Pass — see evidence above |

## Explicit non-actions at this freeze

- Do not merge PR #20 into `main`
- Do not tag `v1.34.0`
- Do not publish a GitHub Release
- Do not deploy
- Do not perform a second independent (Level B) audit — WP2 already complete, findings already remediated
- Do not start M18
- Do not open or close a feature train (M17 releases standalone)

## Irreversible-data warning (carried into the release runbook)

A completed supplier merge is **irreversible at the product level by design** — there is no in-app "undo merge" feature. A plugin-code rollback does not undo a completed merge's data effects (reassigned Purchase Orders/Goods Receipts, the archived+dissolved source, the audit record) — see `docs/rollback-plan.md`'s M17 section. The release runbook's M17 appendix recommends a database backup before the first production merge post-release.
