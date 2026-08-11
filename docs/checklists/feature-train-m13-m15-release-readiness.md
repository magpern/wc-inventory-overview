# Feature Train M13–M15 — Release Readiness (Pre-Release Review)

**Status:** Readiness review complete — **APPROVED for bundled release, NOT YET executed.**
**Date:** 2026-08-11
**Review type:** Comprehensive train-level review (not a repeat of three milestone audits — each milestone already passed its own Level A completion review; see `docs/checklists/m13-release-readiness.md` / `m14-release-readiness.md` / `m15-release-readiness.md`).
**Last released baseline:** `main` / tag **`v1.29.0`** (`67321bb`) — unchanged throughout this train.
**Canonical train branch / SHA:** `feature/m15-supplier-spend-summary` @ `7006235`.
**No merge, tag, GitHub Release, or deployment has occurred as part of this review.**

## Train composition

| Milestone | Capability | Development version | Freeze tip | Draft PR |
|-----------|------------|---------------------|------------|----------|
| M13 | Printable Purchase Order | 1.30.0 | `9632215` on `feature/m13-printable-purchase-order` | [#14](https://github.com/magpern/wc-inventory-overview/pull/14) (open, draft) |
| M14 | Supplier Order History | 1.31.0 | `0780ba7` on `feature/m14-supplier-order-history` (includes the accepted post-freeze DOING_AJAX test-isolation remediation) | [#15](https://github.com/magpern/wc-inventory-overview/pull/15) (open, draft) |
| M15 | Supplier Spend Summary | 1.32.0 | `7006235` on `feature/m15-supplier-spend-summary` (Level A freeze) | [#16](https://github.com/magpern/wc-inventory-overview/pull/16) (open, draft, CI green) |

**Plugin development version:** `1.32.0`
**`DB_VERSION`:** `10` (unchanged across the entire train)
**Previous published release:** `v1.29.0` (M0–M12)

Immutable plans (materialized once, never edited after): `docs/milestones/m13-implementation-plan.md` (`73b0880`), `m14-implementation-plan.md` (`0331c39`), `m15-implementation-plan.md` (`5aca80c`). Confirmed one commit each touches each file — no drift.

## Train ancestry

```
v1.29.0 / main (67321bb)
  └─ feature/m13-printable-purchase-order (9632215, Level A freeze)
       └─ feature/m14-supplier-order-history
            ├─ (M14 freeze)
            └─ 0780ba7  accepted DOING_AJAX test-isolation remediation (maintenance, inherited)
                 └─ feature/m15-supplier-spend-summary
                      ├─ 5aca80c  docs(m15): materialize plan
                      ├─ ad51ca8 / 519befd / 679fca0 / 5229fff  M15 implementation + tests
                      ├─ fda7d96 / 1f44029  M15 docs + version bump
                      ├─ 3565cd7 / cf5cc99  M15 style + CI-filter fix
                      └─ 7006235  docs(m15): freeze + release readiness   ← current train tip
```

`main` remains exactly at `67321bb` (v1.29.0) throughout — zero M13/M14/M15 commits merged. `0780ba7` (accepted DOING_AJAX remediation) confirmed in ancestry. M13's historical freeze tip (`9632215`) and M14's (`0780ba7`) both remain intact as-is on their own branches, neither rewritten nor force-pushed.

## Combined diff summary (`main..7006235`, i.e. the actual train payload — `v1.29.0..7006235` includes one no-op post-tag doc commit already on `main`)

**39 files changed, 5977 insertions(+), 57 deletions(-).**

| Category | Files |
|---|---|
| Production (`includes/`, root plugin file) | 7 — `class-wc-inventory-overview-po-admin.php` (M13), `class-wc-inventory-overview-po-print-renderer.php` (M13, new), `class-wc-inventory-overview-purchase-orders.php` (M14 `values_bulk()` + M15 `spend_summary_for_supplier()`), `class-wc-inventory-overview-purchasing-page.php` (M14 + M15 UI), `class-wc-inventory-overview-supplier-order-history-service.php` (M14, new), `class-wc-inventory-overview-supplier-spend-service.php` (M15, new), `wc-inventory-overview.php` (requires + version bumps) |
| Tests | 17 — 3 M13 (`tests/unit/po-print/`), 6 M14 (`tests/unit/purchase-orders/test-po-values-bulk.php`, `tests/unit/supplier-order-history/*` ×2, `tests/integration/supplier-order-history/*` ×2), 6 M15 (`tests/unit/purchase-orders/test-po-spend-summary.php`, `tests/unit/supplier-spend/*` ×2, `tests/integration/supplier-spend/*` ×2), 1 maintenance (`tests/integration/expected-delivery/test-expected-delivery-renderer.php` — the accepted DOING_AJAX remediation) |
| Documentation | 14 — `CHANGELOG.md`, `CLAUDE.md`, `readme.txt`, `docs/ARCHITECTURE_BASELINE_v1.24.0.md`, `docs/architecture-audit.md`, `docs/admin-guide-purchase-orders.md` (new, M13), `docs/admin-guide-suppliers.md`, 3× `docs/checklists/m1{3,4,5}-release-readiness.md` (new), 3× `docs/milestones/m1{3,4,5}-implementation-plan.md` (new), `docs/checklists/validation-checklist.md` |
| CI/tooling | 1 — `tests/docker/run-phpunit.sh` (filter additions for M13/M14/M15 prefixes) |
| Version metadata | Folded into `wc-inventory-overview.php` and `readme.txt` above — not separately counted |

**Scope-creep check:** every file is attributable to M13, M14, M15, or the one accepted maintenance commit (DOING_AJAX remediation, explicitly authorized to be inherited into this baseline). No stray file. `.gitignore`/`README.md` differences seen in a `v1.29.0..HEAD` diff are pre-existing — already on `main` via `67321bb`, not part of this train (`git diff main..7006235` shows zero diff for either file).

## Cross-milestone architecture review

Verified directly against source, not by inference:

- **No duplicated SQL ownership:** `Purchase_Orders::values_bulk()` (M14) and `Purchase_Orders::spend_summary_for_supplier()` (M15) are independent methods with disjoint query shapes (`GROUP BY po_id` bounded to a page of ids vs. `GROUP BY pol.currency` unbounded aggregate). Neither calls the other or composes through `list()`/`build_where()`.
- **No conflicting currency semantics:** both never blend/convert currencies; M14 groups per-PO (one PO = one currency, its own), M15 groups per-line-currency across a supplier's whole history. Compatible, not overlapping, arithmetic (`SUM(qty × unit_cost)` in both).
- **No accidental landed-cost interpretation:** both explicitly documented (`admin-guide-suppliers.md`, `CHANGELOG.md`) as PO-line cost only — never `Receipt_Costs`, never the weighted-average/EUR inventory-value figure.
- **No mutation introduced:** grepped `Supplier_Spend_Service`, `Supplier_Order_History_Service`, `PO_Print_Renderer` for `->insert(`/`->update(`/`->delete(`/`INSERT INTO`/`UPDATE `/`DELETE FROM` — zero matches in all three.
- **No schema change:** `class-wc-inventory-overview-install.php` has zero diff in `main..7006235`; `DB_VERSION` unchanged at `'10'`.
- **No public API expansion:** all three new services are Internal (D16) — no hook, filter, or REST route added (confirmed no `add_action`/`add_filter`/`register_rest_route` in any new file).
- **No capability/security regression:** M13 adds a new capability check (`VIEW_PO` + PO-scoped nonce) that is strictly additive to a new endpoint; M14/M15 both reuse the pre-existing `manage_woocommerce` gate already enforced before any supplier-specific rendering — no gate weakened or bypassed anywhere.
- **No lifecycle/status mutation:** none of the three milestones write PO/receipt status.
- **No N+1 introduced:** each milestone's own read is O(1) queries per page load (M13: fixed small read set per print; M14: 3 queries, page-bounded; M15: exactly 1 query, unbounded history) — none loop per-PO/per-line in PHP.
- **No circular dependency:** `Supplier_Spend_Service` and `Supplier_Order_History_Service` do not reference each other (only a shared docblock classification note); both depend only downward on `Purchase_Orders`.
- **M15 does not undermine `values_bulk()`'s contract:** `values_bulk()` is untouched (zero diff to that method across the M15 commits) and remains page-scoped, never summed — M15 added a sibling, not a modification.
- **M14 does not attempt to act as the supplier-wide spend aggregate:** `Supplier_Order_History_Service` has no total/sum method; its own docs (`admin-guide-suppliers.md`) explicitly say "This is not a spend report... For the totaled view... see Spend Summary below."
- **M13 remains presentation-only:** `PO_Print_Renderer` has zero repository access of its own (composed by `PO_Admin::handle_print()` from existing read owners) — confirmed by its own architecture guard (INV-M13-2/3).

**M14-vs-M15 distinction, confirmed intentional and non-contradictory:** M14 = per-PO Ordered/Received Value, every status (historical/audit visibility, INV-M14-4). M15 = supplier-level totals grouped by currency, committed statuses only (BR-M15-1). Both use identical line-level arithmetic (`qty_ordered/qty_received × unit_cost`) so the two views are always reconcilable by hand, never contradictory — documented cross-referencing both directions in `docs/admin-guide-suppliers.md` (Order History → "see Spend Summary below"; Spend Summary → contrasts with Order History's inclusiveness).

## Business-semantics review

- M13 printable statuses (`placed`/`partially_received`/`received`/`cancelled`/`closed_short`, never `draft`) and snapshot behavior (product/supplier snapshots survive deletion) — unchanged since M13 freeze, re-confirmed by reading `PO_Print_Renderer`/`PO_Admin::handle_print()` current source.
- M14 Order History is deliberately status-inclusive (INV-M14-4) — a full audit trail, explicitly not a spend report.
- M15 Spend Summary deliberately restricts to `placed`/`partially_received`/`received`/`closed_short`, excluding `draft` (not yet committed) and `cancelled` (never fulfilled) — a genuinely new business decision (BR-M15-1), documented as such, not silently reused from M13/M14.
- This difference (audit visibility vs. committed spend) is intentional, stated in both the M15 implementation plan and `docs/admin-guide-suppliers.md`, and not contradictory — the two sections coexist on the same screen with clearly distinct captions.
- M15 currency semantics re-verified from `spend_summary_for_supplier()`'s own SQL: line-level `pol.currency`, one `GROUP BY` row per currency, no FX normalization, no blended total, `COUNT(DISTINCT po.id)` scoped inside each currency's `GROUP BY` bucket (BR-M15-5) — matches the approved plan exactly.

## Data / mutation / schema review

No schema change, no mutation, across all three milestones (see Cross-milestone architecture review above). `composer.json`/`composer.lock` unchanged.

## Query / performance review

- M13: fixed, small read set (one PO, its lines, its supplier) per print request — no scaling concern.
- M14: 3 queries (`count()`/`list()`/`values_bulk()`), bounded to one page of POs — unaffected by total history size.
- M15: exactly 1 query, `SELECT`, regardless of history size — proven at 200-PO/3-currency scale (measured, not asserted by inspection).
- Combined page load (`render_supplier_detail()`): Spend Summary (1) + Observed Lead Time (M9, unaffected) + Order History (3, page-bounded) — a small, fixed number of queries per page view, no compounding, no N+1 across the three milestones together.

## CI discovery verification

Test discovery was verified directly via `--list-tests` against the default `run-phpunit.sh` filter (not by inspecting the regex alone). All 11 M13/M14/M15 test classes are discovered:

```
Test_WC_IO_PO_Print_Admin
Test_WC_IO_PO_Print_Architecture
Test_WC_IO_PO_Print_Renderer
Test_WC_IO_Supplier_Order_History_Admin
Test_WC_IO_Supplier_Order_History_Architecture
Test_WC_IO_Supplier_Order_History_Performance
Test_WC_IO_Supplier_Order_History_Service
Test_WC_IO_Supplier_Spend_Admin
Test_WC_IO_Supplier_Spend_Architecture
Test_WC_IO_Supplier_Spend_Performance
Test_WC_IO_Supplier_Spend_Service
```

This directly re-confirms the M15-freeze finding that `Test_WC_IO_Supplier_Spend_` was missing from the filter (fixed in `cf5cc99`) — the fix is verified working, not merely re-asserted.

## Existing test/CI evidence (reused, not re-run)

No executable code changed during this review (only documentation), and HEAD (`7006235`) has not changed since PR #16's last CI run — per this review's own instructions, existing fresh evidence is used rather than re-running expensive full suites:

| Gate | Result | Source |
|---|---|---|
| GitHub Actions, PR #16 @ `7006235` | **Green** — PHP Parallel Lint, PHP lint and build ZIP, PHPUnit all `pass` | `gh pr checks 16`, confirmed this session |
| Unit suite | 343 tests / 1868 assertions, 0 risky | M15 freeze run |
| M1–M15 focused suite | 645 tests / 3049 assertions, 0 risky | M15 freeze run; re-confirmed 645 via this session's own re-run |
| Integration suite (full) | 313 tests / 1224 assertions, 0 risky | M15 freeze run; re-confirmed clean via this session's own re-run |
| M13/M14 regression spot-check (isolated `--filter`) | 57 tests / 215 assertions, 0 risky | M15 freeze run |
| M15-only tests | 32 tests / 103 assertions | M15 freeze run |

Test discovery (above) was independently re-verified this session via `--list-tests`, not merely reused.

## Documentation review

- `CHANGELOG.md`, `readme.txt`: three consistent, correctly-dated entries (`1.30.0`/`1.31.0`/`1.32.0`), each explicitly stating "not individually released" — no claim of publication for any of the three.
- `CLAUDE.md`: Implementation Status table has accurate M13/M14/M15 rows; "Release note" paragraph correctly states all three are frozen, unreleased, forming one train; correctly names the next decision point as closing M13+M14+M15 together.
- `docs/architecture-audit.md`: M13, M14, and M15 each have their own section, correctly sequenced and cross-referenced; no stale "still open" claim survives for order-history/spend (M11/M12-era "still open" notes are historically accurate as written at the time).
- `docs/ARCHITECTURE_BASELINE_v1.24.0.md`: milestone table, frozen-boundaries table, and invariants table all include M13/M14/M15 (and M13's own invariants, added for completeness); explicitly notes the M14 documentation-currency gap and that it was closed retroactively during M15.
- No `docs/GITHUB_RELEASE_NOTES_1.32.0.md` exists yet — correct at this stage; it is the single bundled release-note artifact to be created during the actual WP6 release-preparation operation (not this review), per Part 6 of this review's brief.
- No historical draft release notes need to be marked superseded — none exist for `1.30.0`/`1.31.0`/`1.32.0` yet (nothing to supersede).

**Two narrow, pre-existing documentation gaps found and fixed in this review** (see Findings/Remediations below): `docs/testing.md`'s CI-gate description was stale at "M1–M13" (never refreshed at M14 or M15's own freeze, despite the underlying filter being current); `docs/rollback-plan.md` and `docs/release-runbook.md` and `docs/checklists/validation-checklist.md` had per-milestone sections for M13 but not M14/M15, despite M14/M15's own checklists asserting "identical rollback profile to M13."

## Release-version recommendation

**`v1.32.0`** — bundling M13+M14+M15 in one release, matching this repository's own M9–M12 precedent (four milestones bundled as one `v1.29.0`). No separate `v1.30.0` or `v1.31.0` releases: no repository evidence (CHANGELOG, checklists, plan files) suggests either milestone was ever intended to ship alone — every M13/M14 artifact explicitly states "not individually released" and names itself a step in the same train.

## Release-topology recommendation

```
v1.29.0/main
  → M13 (frozen, draft PR #14)
  → M14 (frozen, draft PR #15)
  → accepted DOING_AJAX maintenance remediation (0780ba7)
  → M15 (frozen, draft PR #16, CI green)
  → train-level release-preparation commit(s)  ← this review's docs fix is a precursor, not this step
  → real release PR into main
```

The three draft milestone PRs (#14/#15/#16) are CI-proof contexts only — closed without merging when the real release PR is opened, exactly as PR #11/#12 were closed unmerged at the M9–M12 train's own closure (see `feature-train-m9-m12-release-readiness.md`).

**Recommended procedure for the eventual release (not executed in this pass):**

1. Prepare bundled `docs/GITHUB_RELEASE_NOTES_1.32.0.md` covering M13+M14+M15.
2. Run final release gates (`release-audit.sh`, full suites if HEAD has changed since this review).
3. Open a new non-draft release PR from the canonical train tip (`7006235`, or a later train-closure commit) to `main`.
4. Require green CI on that PR.
5. Merge using the repository's normal history-preserving strategy (matching PR #13's merge for the M9–M12 train).
6. Tag `v1.32.0` on the release merge commit.
7. Publish the GitHub Release and production ZIP/checksum.
8. Deploy to `dev.biopentra.eu`.
9. Perform live validation (`docs/checklists/deployment-checklist.md`).
10. Perform a rollback rehearsal if required by `docs/rollback-plan.md` (all three milestones are code-only/nothing-to-reverse per their own rollback-plan entries — likely a short rehearsal, consistent with M9–M12's).
11. Record post-release documentation (mirroring `feature-train-m9-m12-release-readiness.md`'s own closure record, to be written as a new `feature-train-m13-m15-release-readiness.md` closure update, or a fresh closure file, after the fact).

## Rollback profile

All three milestones are code-only, read-only additions with nothing to reverse: no `wp_options` row, no new table/column/setting, no data write anywhere in any of the three surfaces. `docs/rollback-plan.md` now carries M13, M14, and M15 entries (M14/M15 added by this review), each stating a code rollback is unconditionally safe. Identical, cumulative rollback safety to the M9–M12 train.

## Findings by severity

**CRITICAL:** None.
**MAJOR:** None.
**MINOR (fixed in this pass):**
1. `docs/testing.md` CI-gate description was stale at "M1–M13-focused suite" / "Counts rise with M13..." — never updated at M14 or M15's own freeze, even though `run-phpunit.sh`'s actual filter was kept current through M15. Fixed to reflect the current M1–M15 state.
2. `docs/rollback-plan.md`, `docs/release-runbook.md`, and `docs/checklists/validation-checklist.md` each had a per-milestone section for M13 but no corresponding M14/M15 sections, despite M14 and M15's own release-readiness checklists asserting "identical rollback profile to M13/M14." Added matching M14/M15 sections to all three files, following each file's own existing per-milestone template exactly.

**OBSERVATION:**
- No `docs/GITHUB_RELEASE_NOTES_1.32.0.md` exists yet — expected; correctly deferred to the actual release-preparation step, not this review.
- `docs/checklists/feature-train-development-head.md` still describes the M9–M12 train as the "current published baseline" — this remains factually correct (nothing has merged to `main` since), so it was left unmodified; it is a historical/published-baseline pointer, not a training-in-progress record.

## Remediations performed

Single dedicated commit (docs-only, no executable code changed): fixes to `docs/testing.md`, `docs/rollback-plan.md`, `docs/release-runbook.md`, `docs/checklists/validation-checklist.md`, plus this new artifact. No milestone plan file (`docs/milestones/m1{3,4,5}-implementation-plan.md`) was touched. No product behavior changed. No suite re-run was required beyond the `--list-tests` discovery check (already performed) since no executable file changed.

## Exact next operation

**Awaiting explicit approval to execute the WP6 bundled release** (Release-topology recommendation above, steps 1–11). No further action is authorized in this pass — do not merge, tag, publish a Release, deploy, or start M16 without a separate, explicit instruction to do so.
