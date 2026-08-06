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

### For M4 (Receipt Engine, v1.21.0)

Inverted from M3's checklist above: M4 positively verifies receiving now works correctly, transactionally, and idempotently — not that it's absent.

- [ ] **Schema v8**: `DB_VERSION` is `8`; `wc_io_goods_receipts`, `wc_io_receipt_lines`, `wc_io_receipt_costs` exist; `wc_io_inventory_movements` has `reference_type`/`reference_id`/`supplier_id`:
  ```bash
  wp option get wc_io_db_version
  wp option get wc_io_schema_assertion --format=json
  ```

- [ ] **`qty_received` still absent** from `wc_io_purchase_order_lines` (forbidden-column guard still enforcing, unchanged from v7).

- [ ] **Quick Receive Without PO works end-to-end**: create draft → add line(s) → save → post-confirmation preview shown → Confirm & Post → product stock/average cost/inventory value update exactly per the weighted-average formula.

- [ ] **Void works and is correctly reversible**: void a posted receipt with a reason; stock/cost/value revert to reflect only that receipt's own contribution (verified against the intervening-receipt scenario if multiple receipts exist for the same product).

- [ ] **Void correctly rejected when stock has since been sold** below the receipt's contribution — clear, actionable error; zero partial mutation.

- [ ] **Transactional integrity holds under forced failure**: a mid-post or mid-void failure (e.g., an invalid line product) leaves stock, cost meta, and movement rows completely unchanged and the receipt status unchanged (verified in CI by `Test_WC_IO_Goods_Receipt_Service_Post`/`_Void`, not just manually).

- [ ] **Idempotency holds**: resubmitting a post/void form (refresh/back-button) does not double-apply; a receipt cannot be posted or voided twice.

- [ ] **`po_line_id` stays NULL**: every receipt line's `po_line_id` is NULL; no PO is linked, no PO quantity/status/event is touched by receiving.

- [ ] **Movement provenance**: every posted line produces exactly one `goods_receipt` movement row with `reference_type='goods_receipt'` and `reference_id`=the receipt id; every voided line produces exactly one `goods_receipt_void` row.

- [ ] **Receipt number immutable**: `receipt_number` is unchanged after posting and after voiding.

- [ ] **Batch Intake, Quick Restock, Cost Adjustment, PO admin, Supplier admin unaffected** — all continue to function exactly as in v1.20.0.

- [ ] **No PO-linked receiving surface**: no "Receive Against PO" option, no PO-line picker, anywhere in the Receive Stock UI (M5 scope).

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
