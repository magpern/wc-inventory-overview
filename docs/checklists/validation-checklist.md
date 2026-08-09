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

### For M5 (PO Receiving, v1.22.0)

- [ ] **Schema v9**: `DB_VERSION` is `9`; `qty_received` exists on `wc_io_purchase_order_lines`; the `qty_received` forbidden-column guard is gone:
  ```bash
  wp option get wc_io_db_version
  wp option get wc_io_schema_assertion --format=json
  wp db query "SHOW COLUMNS FROM \`$(wp config get table_prefix)wc_io_purchase_order_lines\` LIKE 'qty_received'"
  ```
  (Non-empty result — the exact inverse of M3's/M4's check above.)

- [ ] **Receive against PO** — a real receipt posted end-to-end against a real, previously-placed PO line via the admin UI's "Receive" button: `qty_received` on the PO line increments by exactly the posted quantity; the PO's header status updates correctly (`placed → partially_received` or `placed → received`, per outstanding).

- [ ] **Partial receipt** — a receipt covering less than a PO line's full outstanding quantity: PO status reads "Partially Received"; the line's `qty_outstanding` (Ordered − Received − Cancelled) correctly reflects the remainder; a second receipt can still be created against the same line's remaining outstanding.

- [ ] **Complete receipt** — a receipt (or the cumulative effect of several) that brings a PO line's outstanding to exactly zero: PO status reads "Received"; the PO detail page's per-line Received/Outstanding columns confirm it.

- [ ] **Void one receipt** — voiding a posted PO-linked receipt with a reason: `qty_received` and PO status walk back down correctly (not just "the void succeeds") — verified against the intervening-receipt scenario if multiple receipts exist against the same PO line (voiding one must not erase another's contribution, regardless of order).

- [ ] **Inventory Position updates correctly after every one of the above operations**: the Incoming figure for the affected product/variation on Inventory Overview is re-checked after the partial receipt, the complete receipt, and the void — confirming it tracks `qty_outstanding` at each step, not just at the end (the M3 Incoming regression fix, verified live, not only in CI).

- [ ] **Over-receipt is possible, warned, and audited — never blocked**: posting a quantity exceeding a PO line's current outstanding succeeds; the post-confirmation screen shows an explicit over-receipt warning; the PO's event timeline records `over_receipt: true` for that line.

- [ ] **Mixed and multi-PO receipts work**: one receipt containing both a PO-linked line and a direct line (`source = mixed`), and one receipt with lines from two different POs (both POs' statuses update independently), both post correctly.

- [ ] **Reconciliation CLI available and read-only by default**:
  ```bash
  wp wc-io reconcile-qty-received
  ```
  (Reports verified/drift counts; makes zero writes without `--fix`.)

- [ ] **Receiving history visible**: the PO detail page's "Receiving History" panel lists every receipt line fulfilling any of that PO's lines, with working links to each Goods Receipt; a PO-linked Goods Receipt line shows a working "Fulfils: PO-XXXX line N" back-link.

- [ ] **Batch Intake, Quick Restock, Cost Adjustment, Supplier admin, and M4's Quick Receive Without PO all unaffected** — all continue to function exactly as in v1.21.0.

### For M6 (Migration & Retirement, v1.23.0)

- [ ] **Schema v10**: `DB_VERSION` is `10`; `migrated_receipt_id`/`migrated_at` exist on `wc_io_purchase_batches`, both `NULL` on every pre-existing row immediately after upgrade:
  ```bash
  wp option get wc_io_db_version
  wp option get wc_io_schema_assertion --format=json
  wp db query "SHOW COLUMNS FROM \`$(wp config get table_prefix)wc_io_purchase_batches\` LIKE 'migrated%'"
  ```

- [ ] **Batch Intake create/apply is gone**: the Restock / Cost Adjustment tab shows only "Quick Restock" and "Cost Adjustment" — no "Batch Intake" link; visiting an old `restock_view=batch` bookmark falls back to Quick Restock without an error.

- [ ] **Legacy batch data untouched**: `wc_io_purchase_batches`/`wc_io_purchase_batch_lines`/`wc_io_purchase_batch_costs` row counts are unchanged by the v1.23.0 deploy itself (before any migration CLI run) — the schema upgrade is additive-columns-only.

- [ ] **Dry-run migration preview makes zero writes**:
  ```bash
  wp wc-io migrate-batches
  ```
  (Lists what would be migrated; `wc_io_goods_receipts` row count is unchanged after running this.)

- [ ] **Migration apply, on a staging/test copy first** — never run `--apply` directly against production without a fresh backup (see `docs/migration-guide-batch-intake.md`):
  ```bash
  wp wc-io migrate-batches --apply
  ```
  Every migrated batch produces exactly one new `source='migrated'`, `status='posted'` Goods Receipt; `migrated_receipt_id` is set on the corresponding batch row.

- [ ] **Stock and cost unchanged by migration** — for at least one product affected by a migrated batch, `_stock`/`_wc_io_average_unit_cost`/`_wc_io_inventory_value` are identical before and after running `--apply` (the headline guarantee this milestone exists to provide).

- [ ] **Verify mode reports zero drift after a clean migration**:
  ```bash
  wp wc-io migrate-batches --verify
  ```

- [ ] **Movement provenance replaced**: every `purchase_batch` movement row for a migrated batch now carries `reference_type='goods_receipt'` and the correct `reference_id`; the movement note text and quantities are byte-for-byte unchanged.

- [ ] **Migrated receipts cannot be voided**: attempting to void a `source='migrated'` Goods Receipt through the normal admin Void action is rejected with a clear message; voiding a normal (`direct`/`po`/`mixed`) receipt is unaffected.

- [ ] **CLI rollback works and stays scoped to one batch**:
  ```bash
  wp wc-io migrate-batches --rollback=<batch_id>
  ```
  Deletes only that batch's migrated receipt/lines/costs, clears its movement reference, clears its tracking columns; current stock/cost are unchanged; the batch becomes eligible for migration again.

- [ ] **Quick Restock, Cost Adjustment, Goods Receipts (M4), PO Receiving (M5), Supplier admin, and Inventory Position all unaffected** — all continue to function exactly as in v1.22.0.

### For M7 (Storefront, v1.24.0)

- [ ] **No schema change**: `DB_VERSION` is unchanged at `10`; `wc_io_schema_assertion` reports `ok: true` at `version: "10"`:
  ```bash
  wp option get wc_io_db_version
  wp option get wc_io_schema_assertion --format=json
  ```

- [ ] **Setting present, defaults Yes**: Inventory & Profit → Settings → **Storefront** section shows "Enable Expected Delivery display" defaulting to **Yes** on a fresh install.

- [ ] **Out-of-stock product with a customer-safe exact date** shows "Expected back around {date}" on the product page (and on catalog cards, if the active theme renders stock text there).

- [ ] **Out-of-stock product with only an estimated date** shows "Expected during week {W}".

- [ ] **Out-of-stock product with incoming supply but no safe date** (all lines delayed/unknown-confidence/undated) shows "Expected soon" — never a fabricated date.

- [ ] **Out-of-stock variable parent with a customer-safe child** shows "Expected soon" on the parent's own card/page, never a specific date (Invariant M7-2); the specific variation shows its own precise wording once selected.

- [ ] **Toggle off**: setting "Enable Expected Delivery display" to **No** immediately restores stock WooCommerce's own "Out of stock" text with no deploy.

- [ ] **In-stock and backordered products are visually unchanged** in every case above.

- [ ] **No new admin page**: only the existing Settings tab gained a Storefront section; no new top-level or submenu page was added.

- [ ] **Quick Restock, Cost Adjustment, Inventory Overview, Goods Receipts (M4), PO Receiving (M5), batch migration CLI (M6), and Supplier admin all unaffected** — all continue to function exactly as in v1.23.0.

### For M8 (Hardening & GA, v1.25.0)

- [ ] **No schema change**: `DB_VERSION` is unchanged at `10`; `wc_io_schema_assertion` reports `ok: true` at `version: "10"`.
  ```bash
  wp option get wc_io_db_version
  wp option get wc_io_schema_assertion --format=json
  ```

- [ ] **Batch Intake removal is operationally invisible**: Restock / Cost Adjustment tab still shows only Quick Restock and Cost Adjustment (unchanged since M6); no PHP fatal/warning anywhere in the admin referencing a missing Batch Intake class or method.

- [ ] **`PO_Delay` fix works on a real PO**: a `partially_received` PO with a past-due expected date on its remaining outstanding line now shows "Delayed" (PO detail page, Inventory Overview drill-down) — it would not have before M8. A `partially_received` PO that is on-time does not show Delayed. `placed`/`received` PO delayed-badge behavior is unchanged.

- [ ] **Sibling-plugin conformance guard passes**:
  ```bash
  docker compose -f tests/docker/docker-compose.phpunit.yml run --rm phpunit --testsuite=unit --filter='Test_WC_IO_No_Sibling_Plugin_Coupling'
  ```
  (0 failures.)

- [ ] **Full test suite green, integration suite now a blocking CI gate** — unit, M1–M8-focused, and full integration suites all pass with 0 failures (previously the integration suite carried known pre-existing failures and ran `continue-on-error` in CI; both are resolved as of M8).

- [ ] **GA-scale (200-item) performance confirmation passes** — covered by the integration suite run above, not a separate manual step.

- [ ] **Quick Restock, Cost Adjustment, Goods Receipts (M4), PO Receiving (M5), batch migration CLI (M6), Supplier admin, Inventory Position (M3), and Storefront Expected Delivery (M7) all unaffected** — all continue to function exactly as in v1.24.0.

### For M9 (Supplier Observed Lead-Time Statistics, v1.26.0)

- [ ] **No schema change**: `DB_VERSION` is unchanged at `10`; `wc_io_schema_assertion` reports `ok: true` at `version: "10"`.
  ```bash
  wp option get wc_io_db_version
  wp option get wc_io_schema_assertion --format=json
  ```

- [ ] **Observed Lead Time panel shows correct figures**: on a supplier with ≥2 fully-`received` Purchase Orders, Purchasing → Suppliers → edit that supplier shows average/fastest/slowest/completed-order figures matching a manual `DATEDIFF()` spot-check between each PO's `placed_at` and its completing receipt's `posted_at`.

- [ ] **"Not enough data yet" below threshold**: a supplier with 0–1 completed orders shows this message, never `0 days` or a misleadingly precise figure from a single data point.

- [ ] **Read-only, not editable**: no field or admin-post action anywhere lets an operator type in or override an observed figure; only the existing "Default Lead Time (days)" field remains editable.

- [ ] **Excludes correctly**: a `closed_short` or still-open (`placed`/`partially_received`) PO never contributes; a voided receipt never contributes.

- [ ] **Archived suppliers keep their statistics**: archiving a supplier does not change or hide its observed lead-time figures.

- [ ] **Architecture guard passes**:
  ```bash
  docker compose -f tests/docker/docker-compose.phpunit.yml run --rm phpunit --testsuite=unit --filter='Test_WC_IO_Supplier_Lead_Time_Architecture'
  ```
  (0 failures.)

- [ ] **Full test suite green** — unit, M1–M9-focused, and full integration suites all pass with 0 failures.

- [ ] **Query-count-equality (10/40/200-supplier) performance confirmation passes** — covered by the integration suite run above, not a separate manual step.

- [ ] **Suppliers, Purchase Orders, Inventory Position (M3), Goods Receipts (M4), PO Receiving (M5), batch migration CLI (M6), and Storefront Expected Delivery (M7) all unaffected** — all continue to function exactly as in v1.25.0.

### For M10 (Purchase Order Expected-Date Suggestion, v1.27.0)

- [ ] **No schema change**: `DB_VERSION` is unchanged at `10`; `wc_io_schema_assertion` reports `ok: true` at `version: "10"`.
  ```bash
  wp option get wc_io_db_version
  wp option get wc_io_schema_assertion --format=json
  ```

- [ ] **Observed suggestion**: create a new Purchase Order for a supplier with a usable Observed Lead Time (≥2 completed orders); selecting that supplier pre-fills Expected Date (order date, or today if blank, plus the rounded observed average in calendar days) and sets Confidence to "Estimated," matching the same average shown on that supplier's own Observed Lead Time panel.

- [ ] **Configured fallback**: a supplier with no usable observed history but a nonzero "Default Lead Time (days)" gets a suggestion sourced from that configured value instead.

- [ ] **No suggestion**: a supplier with neither observed nor configured lead time leaves Expected Date/Confidence blank, exactly as before this milestone.

- [ ] **Manual edit is permanently respected**: manually change Expected Date (or Confidence) on the create form, then change the supplier — the manual value is never overwritten, and no further automatic suggestion applies for the rest of that page session even after additional supplier changes.

- [ ] **Stale suggestion clears**: select a supplier with a suggestion, then a supplier without one (without manually editing in between) — the previously auto-filled fields return to blank/"Unknown," not left showing the first supplier's stale figures.

- [ ] **Editing an existing PO is unaffected**: opening any existing Purchase Order (including a still-editable `draft`) never triggers a suggestion or alters its already-stored Expected Date/Confidence, even if its supplier is changed.

- [ ] **Architecture guards pass**:
  ```bash
  docker compose -f tests/docker/docker-compose.phpunit.yml run --rm phpunit --testsuite=unit --filter='Test_WC_IO_Expected_Date_Suggestion_Architecture|Test_WC_IO_Supplier_Lead_Time_Architecture'
  ```
  (0 failures.)

- [ ] **Full test suite green** — unit, M1–M10-focused, and full integration suites all pass with 0 failures.

- [ ] **Query-count (10/40/200-supplier) performance confirmation passes** — covered by the integration suite run above, not a separate manual step.

- [ ] **Suppliers, Purchase Orders, Inventory Position (M3), Goods Receipts (M4), PO Receiving (M5), batch migration CLI (M6), Storefront Expected Delivery (M7), and Supplier Observed Lead Time (M9) all unaffected** — all continue to function exactly as in v1.26.0.

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
