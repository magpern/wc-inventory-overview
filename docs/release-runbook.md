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

Update `readme.txt` and/or `CHANGELOG.md` with a concise entry for this release:

```
= 1.18.0 – 2026-08-XX =

* Feature: [Description of major feature or fix]
* Enhancement: [Secondary feature]
* Internal: [Infrastructure changes, not user-visible]
```

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

**Before tagging the release:**

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

## Post-release communication

After a successful release:

1. Confirm the release is reflected in the update-checker metadata (GitHub releases or configured update channel).
2. Monitor site logs for any error spikes (via Biopentra's existing monitoring).
3. Notify stakeholders of the release via appropriate channels (team Slack, etc.).

## See also

- [Deployment Checklist](checklists/deployment-checklist.md)
- [Rollback Checklist](checklists/rollback-checklist.md)
- [Validation Checklist](checklists/validation-checklist.md)
