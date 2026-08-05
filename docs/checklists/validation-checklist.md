# Validation Checklist

**Template produced by M0 release rehearsal. Used by every release.**

Functional verification steps to run after every successful deployment. These confirm the release is working as intended, not just that the container is running.

## Universal checks (every release)

These checks apply to every release in the program, regardless of milestone.

- [ ] **Site accessibility**: https://dev.biopentra.eu loads without errors.
- [ ] **Admin access**: WordPress admin interface (https://dev.biopentra.eu/wp-admin) is accessible.
- [ ] **Plugin active**: WC Inventory Overview plugin is listed as active in Plugins screen.
- [ ] **Error logs clean**: No new PHP errors in `/var/www/html/wp-content/debug.log` (or acceptable info-level messages only).
- [ ] **Database health**: WordPress can execute queries (e.g., `wp eval 'echo "DB OK"'` via WP-CLI succeeds).
- [ ] **No regressions**: Existing admin screens (Inventory Overview, etc.) render without errors.

## Milestone-specific checks

These checks are added by each milestone's implementation plan based on its scope.

### For M0 (this release)

- [ ] **Test infrastructure available**: Test suite can be run locally:
  ```bash
  cd tests/docker
  docker compose -f docker-compose.test.yml up -d
  ```
  (Containers start without errors; WordPress boots.)

- [ ] **PHPCS passes**: 
  ```bash
  ./vendor/bin/phpcs --standard=phpcs.xml.dist
  ```
  (No violations beyond the recorded baseline.)

- [ ] **No runtime change**: The plugin behaves identically to the prior release; no UI, admin page, or functional changes are observable.

- [ ] **No database schema change**: The database remains at the pre-deployment version:
  ```bash
  # Check the option (if a DB_VERSION constant exists):
  wp option get wc_inventory_overview_db_version
  ```
  (Should match the prior release's version.)

### For M3 (Inventory Position, v1.20.0)

- [ ] **No schema change**: `DB_VERSION` is unchanged at `7`:
  ```bash
  wp option get wc_io_db_version
  ```

- [ ] **No `qty_received` column**: still absent from `wc_io_purchase_order_lines`:
  ```bash
  wp db query "SHOW COLUMNS FROM \`$(wp config get table_prefix)wc_io_purchase_order_lines\` LIKE 'qty_received'"
  ```
  (Empty result.)

- [ ] **Incoming/Position columns visible** to a `manage_woocommerce` user on Inventory Overview, adjacent to Stock.

- [ ] **Incoming/Position columns absent** for an `edit_products`-only user (no new capability was introduced).

- [ ] **Drill-down works**: expanding a product/variation's Details panel shows each open PO line independently (PO number links to the PO detail screen, outstanding quantity, expected date, confidence, delayed indication where applicable).

- [ ] **Variable-parent rollup**: a variable product's parent row shows the sum of its variations' Incoming/Position; each variation still shows its own figures and its own drill-down.

- [ ] **Composable states**: a low-stock or out-of-stock product with open incoming supply shows its existing stock badge *and* the Incoming/Position values simultaneously (not one replacing the other).

- [ ] **No write side effects**: viewing Inventory Overview does not change any product stock quantity, PO, or PO line record.

- [ ] **No receiving surface**: no Goods Receipt, Quick Receive, or "Receive Against PO" UI exists anywhere in the plugin.

## Sign-off

Once all checks pass:

- [ ] Date/time of validation recorded.
- [ ] Deployed tag confirmed (e.g., `v1.18.0`).
- [ ] All checklist items marked.
- [ ] Release is approved for production use (if applicable; dev.biopentra.eu is always a staging environment).

## If any check fails

- [ ] Do **NOT** mark it as passed; investigate the failure.
- [ ] Document the failure (error messages, screenshots, reproduction steps).
- [ ] If the failure is critical (site inaccessible, data corruption), proceed to [Rollback Checklist](rollback-checklist.md) immediately.
- [ ] If the failure is non-critical, escalate to the development team for triage.

---

**If all checks pass**, the release is complete and healthy.

**If any check fails**, coordinate with the team on next steps (hotfix, rollback, or acceptance of a known issue).

## See also

- [Deployment Checklist](deployment-checklist.md)
- [Rollback Checklist](rollback-checklist.md)
- [Release Runbook](../release-runbook.md)
