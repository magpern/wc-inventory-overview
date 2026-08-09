# Release Runbook

**Template produced by Milestone M0 release rehearsal. Reused by every subsequent release.**

A standardized, step-by-step process for releasing WC Inventory Overview to production. This runbook is the single source of truth for the release procedure; it is followed verbatim by every milestone.

## Pre-release checks

- [ ] Ensure the branch is fully up-to-date with `main`.
- [ ] Confirm all CI/CD gates pass (PHPUnit, PHPCS, PHP Lint).
- [ ] Review changelog for accuracy and completeness.
- [ ] Verify no secrets or sensitive data are in staged commits.

## Release steps

### 1. Version bump

Update the version constant and header in `wc-inventory-overview.php`:

```bash
# Identify the current version.
grep "Version:" wc-inventory-overview.php

# Update version in plugin header (follow semantic versioning).
# E.g., 1.17.2 → 1.18.0 for feature release, or 1.17.3 for patch.
```

Also update the `WC_INVENTORY_OVERVIEW_VERSION` constant to match.

### 2. Changelog entry

Update `readme.txt` and/or `CHANGELOG.md` with a concise entry for this release.

Create `docs/GITHUB_RELEASE_NOTES_{VERSION}.md` before tagging — the Release workflow requires this file (see `.github/workflows/release.yml`). For **v1.18.0 (M1)**, use `docs/GITHUB_RELEASE_NOTES_1.18.0.md`.

### 3. Git commit and push

Stage and commit the version and changelog changes:

```bash
git add wc-inventory-overview.php readme.txt CHANGELOG.md

git commit -m "Release 1.18.0: [short description]

[Longer description of what this release includes]

Co-Authored-By: Claude Haiku 4.5 <noreply@anthropic.com>"

git push origin <branch>
```

**Note:** For milestones that involve schema changes or migrations, additional commit setup may be required (see the milestone-specific implementation plan).

### 4. Create git tag

Tag the release for tracking and update-channel identification:

```bash
git tag -a "v1.18.0" -m "Release 1.18.0"
git push origin "v1.18.0"
```

### 5. Deploy to dev environment

Follow the [Deployment Checklist](checklists/deployment-checklist.md) to deploy the tagged release to the dev VPS.

### 6. Post-deploy validation

Follow the [Validation Checklist](checklists/validation-checklist.md) to confirm the release is healthy.

### 7. Rollback plan

If the release has issues post-deployment:

1. Follow the [Rollback Checklist](checklists/rollback-checklist.md) to revert to the prior release tag.
2. After successful rollback, file an issue documenting what went wrong.
3. Do **not** force-push tags or attempt to overwrite the release in git history.

## Milestone-specific additions

Each milestone may extend this runbook with additional steps (e.g., migration verification for M6, storefront-toggle validation for M7). Those additions are documented in the milestone's implementation plan and are called out in a milestone-specific section at the end of this runbook.

### M0: Delivery Foundations

No additional steps. The release is a pure-tooling change with no functional or database schema changes.

### M1: Suppliers

**Before tagging v1.18.0:**

0. **Release notes file:** Confirm `docs/GITHUB_RELEASE_NOTES_1.18.0.md` exists and matches `CHANGELOG.md` for 1.18.0.
1. **Verify schema version bump:** Check that `DB_VERSION = '6'` in `includes/class-wc-inventory-overview-install.php`.
2. **Test schema-shape assertion on a production-data copy:**
   - Upgrade to the M1 release on a copy of production database.
   - Verify `wp option get wc_io_db_version` returns `6`.
   - Verify `wp option get wc_io_schema_v6_assertion --format=json` shows `ok: true`.
3. **Review the seed migration report on the production-data copy:**
   - Verify `wp option get wc_io_supplier_seed_migration_report --format=json` contains no errors.
   - Cross-check `suppliers_created` + `suppliers_skipped_existing` against expected distinct supplier names.
   - Ensure no data loss: `wc_io_purchase_batches` and `wc_io_inventory_movements` row counts are identical before/after.
4. **Verify Purchasing menu availability:**
   - Log in as a `manage_woocommerce` user.
   - Confirm **WooCommerce → Purchasing** submenu appears.
   - Confirm the **Suppliers** tab is accessible and fully functional.

### M2: Purchase Orders

**Before tagging v1.19.0:**

0. **Prerequisite:** Site is on **v1.18.1** (M1 Purchasing PRG hotfix). Do not skip the 1.18.1 patch when upgrading from 1.18.0.
1. **Release notes file:** Confirm `docs/GITHUB_RELEASE_NOTES_1.19.0.md` exists and matches `CHANGELOG.md` for 1.19.0.
2. **Verify schema version bump:** Check that `DB_VERSION = '7'` in `includes/class-wc-inventory-overview-install.php`.
3. **Test schema-shape assertion on a production-data copy:**
   - Upgrade to the M2 release on a copy of production database.
   - Verify `wp option get wc_io_db_version` returns `7`.
   - Verify `wp option get wc_io_schema_assertion --format=json` shows `ok: true` and `version: "7"`.
   - Legacy mirror `wc_io_schema_v7_assertion` should match; `wc_io_schema_v6_assertion` is also updated for backward-compatible runbook snippets.
4. **Verify Purchasing → Purchase Orders:**
   - Log in as a `manage_woocommerce` user.
   - Confirm **Purchase Orders** tab is the default Purchasing view.
   - Walk through: create draft → add lines → place → cancel or close short; confirm event timeline records transitions.
5. **No-stock-change check (mandatory):**
   - Note a product's `_stock` and `_wc_io_average_unit_cost` before and after PO lifecycle actions (place, cancel, close short, duplicate).
   - Values must be identical — PO actions must not mutate WooCommerce stock or costing meta in M2.
6. **Confirm receiving is absent:**
   - PO line schema must not include `qty_received` (assertion forbids it until M5).
   - No Receive Stock tab or receiving UI should appear.

### M4: Receipt Engine

**Before tagging v1.21.0:**

0. **Release notes file:** Confirm `docs/GITHUB_RELEASE_NOTES_1.21.0.md` exists and matches `CHANGELOG.md` for 1.21.0.
1. **Verify schema version bump:** Check that `DB_VERSION = '8'` in `includes/class-wc-inventory-overview-install.php`.
2. **Test schema-shape assertion on a production-data copy:**
   - Upgrade to the M4 release on a copy of production database.
   - Verify `wp option get wc_io_db_version` returns `8`.
   - Verify `wp option get wc_io_schema_assertion --format=json` shows `ok: true` and `version: "8"`.
   - Verify `wc_io_goods_receipts`, `wc_io_receipt_lines`, `wc_io_receipt_costs` tables exist and `wc_io_inventory_movements` gained `reference_type`/`reference_id`/`supplier_id`.
3. **Verify Purchasing → Receive Stock:**
   - Log in as a `manage_woocommerce` user.
   - Confirm the **Receive Stock** tab is present alongside Purchase Orders and Suppliers.
   - Walk through: Quick Receive Without PO draft creation → add lines → save → post confirmation preview → Confirm & Post → verify stock/average-cost/inventory-value updated on the receiving product(s).
   - Void the same receipt with a reason; confirm the reversal and that `receipt_number` is unchanged.
4. **Stock-mutation-correctness check (mandatory — new for M4):** unlike M2/M3, M4 actually mutates stock. Before tagging:
   - Note a test product's `_stock`, `_wc_io_average_unit_cost`, `_wc_io_inventory_value` before posting a Quick Receive.
   - Post the receipt; verify the new values match the weighted-average formula by hand (`new_avg = (old_stock*old_avg + qty*true_unit_cost) / (old_stock+qty)`).
   - Void the receipt; verify stock/cost return to (or correctly reflect, if other receipts posted in between) the pre-posting state.
5. **Batch Intake, Quick Restock, Cost Adjustment unaffected:** confirm all three continue to function exactly as in v1.20.0 — Receive Stock is additive, not a replacement.
6. **No PO-linked receiving:** confirm no "Receive Against PO" option, no PO picker, and no `qty_received` column anywhere (M5 scope).
7. **Rollback awareness:** read `docs/rollback-plan.md`'s new M4 note before deploying — a code rollback does not reverse stock effects of receipts already posted under M4.

### M5: PO Receiving

**Before tagging v1.22.0:**

0. **Release notes file:** Confirm `docs/GITHUB_RELEASE_NOTES_1.22.0.md` exists and matches `CHANGELOG.md` for 1.22.0.
1. **Verify schema version bump:** Check that `DB_VERSION = '9'` in `includes/class-wc-inventory-overview-install.php`.
2. **Test schema-shape assertion on a production-data copy:**
   - Upgrade to the M5 release on a copy of production database.
   - Verify `wp option get wc_io_db_version` returns `9`.
   - Verify `wp option get wc_io_schema_assertion --format=json` shows `ok: true` and `version: "9"`.
   - Verify `qty_received` now exists on `wc_io_purchase_order_lines` and is no longer in the schema-shape assertion's forbidden-columns list.
3. **Dispatcher-routing check (mandatory — the exact trap M4's own runbook flagged for v7/v8, repeats identically at v8/v9):** confirm `DB_VERSION` 9 routes to `expected_schema_v9()`, not silently falling back to v8's (incomplete, still-forbids-`qty_received`) assertion. If schema-shape assertion reports `ok: true` at `version: "9"` with `qty_received` present, the dispatcher is correct; if it reports the column as forbidden despite the column existing, the dispatcher fell through to v8 and must be fixed before tagging.
4. **Verify PO Receiving end-to-end:**
   - Log in as a `manage_woocommerce` user.
   - On a placed Purchase Order's detail page, confirm the **Receive** button appears and pre-fills a new Goods Receipt draft from the PO's outstanding lines.
   - Post a partial receive; verify the PO line's `qty_received`/outstanding update, and the PO status reads "Partially Received".
   - Post a second receive covering the remainder; verify PO status reads "Received".
   - Void one of the two receipts; verify `qty_received`/PO status walk back down correctly (the other receipt's contribution survives, regardless of which one is voided — see the two mandatory regression scenarios in `docs/milestones/m5-implementation-plan.md` §Testing).
5. **qty_received-mutation-correctness check (mandatory — new for M5, mirrors M4's stock-mutation-correctness check):** unlike M4 (stock/cost only), M5 also mutates `qty_received` and PO status.
   - Note a PO line's `qty_ordered`/`qty_received`/`qty_cancelled` and the PO's `status` before posting a PO-linked receipt.
   - Post the receipt; verify `qty_received` incremented by exactly the posted quantity, and PO status matches `PO_Statuses::recompute_for_receiving()`'s expected output by hand.
   - Void the receipt; verify `qty_received`/status return to (or correctly reflect, if other receipts posted in between) the pre-posting state.
6. **Over-receipt check:** post a quantity exceeding a PO line's outstanding; confirm it succeeds (D5 forbids hard-blocking), the post-confirm screen warns explicitly, and the PO's event timeline records the over-receipt.
7. **Reconciliation CLI available:** `wp wc-io reconcile-qty-received` runs and reports verified/drift counts without `--fix`; confirm it makes zero writes in that mode.
8. **Batch Intake, Quick Restock, Cost Adjustment, and M4's Quick Receive Without PO unaffected:** confirm all continue to function exactly as in v1.21.0 — PO Receiving is additive.
9. **No alternative receiving pipeline:** confirm `Goods_Receipt_Service` remains the only code path that mutates stock/cost, and `PO_Receiving_Sync` remains the only code path that mutates `qty_received` (architecture-guard tests pass in CI; this is a documentation cross-check, not a new manual step).
10. **Rollback awareness:** read `docs/rollback-plan.md`'s new M5 note before deploying — a code rollback does not reverse `qty_received`/PO-status effects of receipts already posted under M5.

### M6: Migration & Retirement

**Before tagging v1.23.0:**

0. **Release notes file:** Confirm `docs/GITHUB_RELEASE_NOTES_1.23.0.md` exists and matches `CHANGELOG.md` for 1.23.0.
1. **Verify schema version bump:** Check that `DB_VERSION = '10'` in `includes/class-wc-inventory-overview-install.php`.
2. **Test schema-shape assertion on a production-data copy:**
   - Upgrade to the M6 release on a copy of production database.
   - Verify `wp option get wc_io_db_version` returns `10`.
   - Verify `wp option get wc_io_schema_assertion --format=json` shows `ok: true` and `version: "10"`.
   - Verify `migrated_receipt_id`/`migrated_at` now exist on `wc_io_purchase_batches`, both `NULL` on every pre-existing row.
3. **Dispatcher-routing check (mandatory — the exact trap M4/M5's own runbooks flagged, repeats identically at v9/v10):** confirm `DB_VERSION` 10 routes to `expected_schema_v10()`, not silently falling back to v9's assertion (which never checks the new tracking columns at all). If schema-shape assertion reports `ok: true` at `version: "10"`, the dispatcher is correct.
4. **Deploy makes zero data changes:** confirm that immediately after deploying v1.23.0 (before running the migration CLI), Batch Intake data is unchanged and no new Goods Receipts exist beyond what was already there — the schema upgrade is additive-columns-only; migration is a deliberate, separate operator action (see `docs/migration-guide-batch-intake.md`).
5. **Migration dry run, apply, verify — on a copy of production first:**
   - `wp wc-io migrate-batches` (no flags) — confirm it lists the expected batches and makes zero writes (spot-check `wc_io_goods_receipts` row count before/after).
   - `wp wc-io migrate-batches --apply` — confirm every eligible batch reports success; note the migrated/failed counts.
   - `wp wc-io migrate-batches --verify` — confirm `Drift found: 0`.
6. **Historical-integrity check (mandatory — the headline guarantee of this milestone, mirrors M4's stock-mutation-correctness check):** for at least one product affected by a migrated batch, note `_stock`/`_wc_io_average_unit_cost`/`_wc_io_inventory_value` before running `--apply`, and confirm they are **byte-for-byte identical** afterward.
7. **Movement provenance replaced:** confirm a migrated batch's `purchase_batch` movement row(s) now carry `reference_type='goods_receipt'` and the correct `reference_id`, with the note text and quantities unchanged.
8. **Migrated-void guard:** confirm attempting to void a migrated Goods Receipt through the normal admin action is rejected with a clear error; confirm voiding a normal receipt is unaffected.
9. **CLI rollback works:** `wp wc-io migrate-batches --rollback=<batch_id>` on one migrated batch — confirm it deletes only that batch's migrated receipt/lines/costs, clears its movement reference, and leaves current stock/cost unchanged.
10. **Batch Intake create/apply retired:** confirm `admin_post_wc_io_batch_apply`/`wp_ajax_wc_io_batch_preview` no longer register, the Batch Intake tab is gone from the Restock/Cost Adjustment nav, and a stale `restock_view=batch` bookmark falls back to Quick Restock without erroring.
11. **Quick Restock, Cost Adjustment, Goods Receipts, PO Receiving, Supplier admin unaffected:** confirm all continue to function exactly as in v1.22.0.
12. **Legacy tables frozen:** confirm `wc_io_purchase_batches`/`wc_io_purchase_batch_lines`/`wc_io_purchase_batch_costs` are never dropped or truncated by any code path (D14).
13. **Rollback awareness:** unlike M4/M5, M6 does **not** introduce a new "code rollback is unsafe" risk — read `docs/rollback-plan.md`'s new M6 note for why (migrated Goods Receipts are purely additive, invisible to pre-M6 code).

### M7: Storefront

**Before tagging v1.24.0:**

0. **Release notes file:** Confirm `docs/GITHUB_RELEASE_NOTES_1.24.0.md` exists and matches `CHANGELOG.md` for 1.24.0.
1. **No schema change:** confirm `DB_VERSION` is still `'10'` in `includes/class-wc-inventory-overview-install.php` — M7 adds no table, column, or index. `wp option get wc_io_db_version` returns `10`; `wp option get wc_io_schema_assertion --format=json` shows `ok: true` at `version: "10"`.
2. **Storefront-toggle validation (the addition promised above for M7):**
   - Confirm the **Storefront** section and "Enable Expected Delivery display" radio appear on Inventory & Profit → Settings, defaulting to **Yes**.
   - On a real out-of-stock product with a placed PO carrying an exact expected date, confirm the storefront reads "Expected back around …".
   - Toggle the setting to **No**; confirm WooCommerce's stock text returns to "Out of stock" exactly, with no deploy.
   - Toggle back to **Yes**; confirm the custom text returns.
3. **Four customer-visible states verified on a real product:** `STATE_IN_STOCK` (untouched), `STATE_UNAVAILABLE` (plain "Out of stock", no incoming), `STATE_EXPECTED_DATE` (exact and estimated wording), `STATE_EXPECTED_SOON` (incoming exists, no safe date).
4. **In-stock and backorder products untouched:** confirm both render exactly as stock WooCommerce would, with no plugin text substitution.
5. **Variable-parent rollup (Invariant M7-2):** an out-of-stock variable parent with at least one customer-safe child shows "Expected soon" on its own card/page, never a specific date; the specific variation shows its own wording once selected.
6. **No admin screen changed apart from the new Storefront section** on the Settings tab — confirm no new top-level or submenu page was added.
7. **Quick Restock, Cost Adjustment, Goods Receipts, PO Receiving, batch migration CLI, Supplier admin, and Inventory Position unaffected:** confirm all continue to function exactly as in v1.23.0.
8. **Rollback awareness:** M7 has the cleanest rollback story of any milestone in this program. The setting toggle is instant with no deploy; a code rollback 1.24.0 → 1.23.0 is unconditionally safe (M7 writes no data, changes no schema, and mutates nothing — see `docs/rollback-plan.md`'s M7 note).

### M8: Hardening & GA

**Before tagging v1.25.0:**

0. **Release notes file:** Confirm `docs/GITHUB_RELEASE_NOTES_1.25.0.md` exists and matches `CHANGELOG.md` for 1.25.0.
1. **No schema change:** confirm `DB_VERSION` is still `'10'` in `includes/class-wc-inventory-overview-install.php` — M8 adds no table, column, or index. `wp option get wc_io_db_version` returns `10`; `wp option get wc_io_schema_assertion --format=json` shows `ok: true` at `version: "10"`.
2. **Batch Intake removal is invisible operationally:** confirm the Restock/Cost Adjustment tab still shows only Quick Restock and Cost Adjustment (unchanged since M6 — this milestone removed already-unreachable code, not a UI change); confirm no PHP fatal/warning about a missing class anywhere in the admin.
3. **`PO_Delay` fix verified on a real PO:** find or create a Purchase Order that is `partially_received` with a past-due expected date on its remaining outstanding line; confirm it now shows "Delayed" in the PO detail page and the Inventory Overview drill-down (it would not have, pre-M8). Confirm a `partially_received` PO that is on-time does **not** show Delayed, and that `placed`/`received` PO delayed-badge behavior is unchanged.
4. **Sibling-plugin conformance guard passes:** `docker compose -f tests/docker/docker-compose.phpunit.yml run --rm phpunit --testsuite=unit --filter='Test_WC_IO_No_Sibling_Plugin_Coupling'` reports 0 failures.
5. **Full test suite green, integration now blocking:** unit suite, M1–M8-focused suite, and the full integration suite (no longer `continue-on-error` in `tests.yml`) all pass with 0 failures.
6. **GA-scale performance confirmation:** the 200-item Inventory Position and Expected Delivery query-scaling tests pass (part of the integration suite run above — no separate manual step, listed here for visibility).
7. **Quick Restock, Cost Adjustment, Goods Receipts, PO Receiving, batch migration CLI, Supplier admin, Inventory Position, and Storefront Expected Delivery unaffected:** confirm all continue to function exactly as in v1.24.0.
8. **Rollback awareness:** M8 is code/test/CI-only — no data written, no schema changed, no mutation anywhere in its surface. A code rollback 1.25.0 → 1.24.0 is unconditionally safe (see `docs/rollback-plan.md`'s M8 note).

### M9: Supplier Observed Lead-Time Statistics

**Before tagging v1.26.0:**

0. **Release notes file:** Confirm `docs/GITHUB_RELEASE_NOTES_1.26.0.md` exists and matches `CHANGELOG.md` for 1.26.0.
1. **No schema change:** confirm `DB_VERSION` is still `'10'` in `includes/class-wc-inventory-overview-install.php` — M9 adds no table, column, or index. `wp option get wc_io_db_version` returns `10`; `wp option get wc_io_schema_assertion --format=json` shows `ok: true` at `version: "10"`.
2. **Observed Lead Time panel verified on a real supplier:** find or create a supplier with at least 2 fully-`received` Purchase Orders on record; confirm Purchasing → Suppliers → edit that supplier shows average/fastest/slowest/completed-order figures matching a manual `DATEDIFF()` spot-check against `wc_io_purchase_orders.placed_at` and the linked `wc_io_goods_receipts.posted_at`. Confirm a supplier with 0–1 completed orders shows "not enough data yet", never `0 days`.
3. **Read-only, not editable:** confirm no form field or admin-post action anywhere lets an operator type in or override an observed figure — only the existing "Default Lead Time (days)" field remains editable.
4. **Architecture guard passes:** `docker compose -f tests/docker/docker-compose.phpunit.yml run --rm phpunit --testsuite=unit --filter='Test_WC_IO_Supplier_Lead_Time_Architecture'` reports 0 failures.
5. **Full test suite green:** unit suite, M1–M9-focused suite, and the full integration suite all pass with 0 failures (226 / 476 / 261 tests respectively as of this milestone).
6. **Query-count-equality performance confirmation:** the 10/40/200-supplier Supplier Lead-Time query-scaling test passes (part of the integration suite run above — no separate manual step, listed here for visibility).
7. **Suppliers, Purchase Orders, Inventory Position, Goods Receipts, PO Receiving, Batch Migration CLI, and Storefront Expected Delivery unaffected:** confirm all continue to function exactly as in v1.25.0.
8. **Rollback awareness:** M9 is code/test-only — no data written, no schema changed, no mutation anywhere in its surface (the new service is read-only by construction, guard-enforced). A code rollback 1.26.0 → 1.25.0 is unconditionally safe (see `docs/rollback-plan.md`'s M9 note).

### M10: Purchase Order Expected-Date Suggestion

**M9 and M10 are both part of the current unreleased "feature train"** (`docs/process/milestone-lifecycle.md`) — the steps below apply once the train (M9 + M10, + whatever else has joined by then) is actually tagged and released; they are not performed at M10's own implementation completion.

0. **Release notes file:** per the feature-train process, no standalone `docs/GITHUB_RELEASE_NOTES_1.27.0.md` is produced for M10 alone. When the train is released, its combined release notes file covers every milestone batched into that release (`CHANGELOG.md` entries accumulate per-milestone in the meantime).
1. **No schema change:** confirm `DB_VERSION` is still `'10'` in `includes/class-wc-inventory-overview-install.php` — M10 adds no table, column, or index. `wp option get wc_io_db_version` returns `10`; `wp option get wc_io_schema_assertion --format=json` shows `ok: true` at `version: "10"`.
2. **Expected-date suggestion verified on a real supplier:** create a new Purchase Order for a supplier with a usable Observed Lead Time (≥2 completed orders); confirm selecting that supplier pre-fills Expected Date/Confidence matching that supplier's own Observed Lead Time panel average. Confirm a configured-only supplier falls back correctly, and a supplier with neither leaves the fields blank.
3. **Advisory-only, never authoritative (INV-M10-1):** confirm a manual edit to Expected Date or Confidence is never overwritten by a later supplier change, and that opening an existing (including still-editable `draft`) Purchase Order never triggers a suggestion.
4. **Architecture guards pass:** `docker compose -f tests/docker/docker-compose.phpunit.yml run --rm phpunit --testsuite=unit --filter='Test_WC_IO_Expected_Date_Suggestion_Architecture|Test_WC_IO_Supplier_Lead_Time_Architecture'` reports 0 failures.
5. **Full test suite green:** unit suite, M1–M10-focused suite, and the full integration suite all pass with 0 failures (244 / 502 / 269 tests respectively as of this milestone).
6. **Query-count performance confirmation:** the 10/40/200-supplier Expected-Date Suggestion query-scaling test passes (part of the integration suite run above — no separate manual step, listed here for visibility).
7. **Suppliers, Purchase Orders, Inventory Position, Goods Receipts, PO Receiving, Batch Migration CLI, Storefront Expected Delivery, and Supplier Observed Lead Time unaffected:** confirm all continue to function exactly as in v1.26.0.
8. **Rollback awareness:** M10 is code/test-only — no data written, no schema changed, no mutation anywhere in its surface (the new service is read-only by construction, guard-enforced, and never touches `$wpdb` directly). A code rollback 1.27.0 → 1.26.0 is unconditionally safe (see `docs/rollback-plan.md`'s M10 note).

## Post-release communication

After a successful release:

1. Confirm the release is reflected in the update-checker metadata (GitHub releases or configured update channel).
2. Monitor site logs for any error spikes (via Biopentra's existing monitoring).
3. Notify stakeholders of the release via appropriate channels (team Slack, etc.).

## See also

- [Deployment Checklist](checklists/deployment-checklist.md)
- [Rollback Checklist](checklists/rollback-checklist.md)
- [Validation Checklist](checklists/validation-checklist.md)
