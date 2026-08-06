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

## Post-release communication

After a successful release:

1. Confirm the release is reflected in the update-checker metadata (GitHub releases or configured update channel).
2. Monitor site logs for any error spikes (via Biopentra's existing monitoring).
3. Notify stakeholders of the release via appropriate channels (team Slack, etc.).

## See also

- [Deployment Checklist](checklists/deployment-checklist.md)
- [Rollback Checklist](checklists/rollback-checklist.md)
- [Validation Checklist](checklists/validation-checklist.md)
