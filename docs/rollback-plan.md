# Rollback plan — WC Inventory Overview

---

## ⚠ M17 (v1.34.0, frozen/unreleased): code rollback does not undo completed merges

**Starting with M17 (v1.34.0), a plugin-code rollback to a pre-M17 version does NOT reverse the effects of any supplier merges already completed under M17.** This is a schema-change milestone (`DB_VERSION` 10 → 11: new `wc_io_suppliers.merged_into_supplier_id` column, new `wc_io_supplier_merges` table) as well as a "code rollback doesn't undo the operation" risk class, joining M4/M5's precedent below for a different domain (supplier identity/PO-GR ownership, not stock/cost).

If a merge has been **completed** after upgrading to v1.34.0+, its effects are real, committed state:
- The source supplier's `status = 'archived'` and `merged_into_supplier_id = {target}` are real column values. A pre-M17 version's code has no concept of `merged_into_supplier_id` at all — it simply won't read or display it, but the column and its value remain in the database (schema additions are always left in place harmlessly on a code rollback, per this document's general M6 precedent, never dropped).
- Every Purchase Order and Goods Receipt that was reassigned from the source to the target during the merge **stays reassigned** — a pre-M17 version has no merge concept and no way to walk that reassignment back; it simply sees those records as always having belonged to the target supplier.
- The one `wc_io_supplier_merges` audit row for that merge remains in the database, inert to pre-M17 code (which doesn't know the table exists).

**This is genuinely irreversible at the product level — there is no "undo merge" feature in M17, by design** (Business Rule BR-M17-9's "operation is irreversible at the product level" is a deliberate scope decision, not a gap). A plugin-code rollback does not create an undo path that didn't already not exist.

**If a rollback is needed after M17 merges have been completed:**

1. **There is no in-app undo.** Do not attempt to manually re-point `supplier_id` values back via direct database edits without fully understanding the consequence — any Purchase Orders/Goods Receipts placed or received *after* the merge, against what the operator believed was the (dissolved) source supplier, will actually have been created against the target (BR-M17-18's concurrent-create closure guarantees this), so a naive "move them back" edit can misattribute genuinely-new records.
2. If a genuine code-level rollback is required for an unrelated reason, the merge's effects (reassigned POs/receipts, archived+merged source, audit row) **remain in effect** — the older code simply won't have any UI surface for them (no merge form, no "Merged into X" notice), but nothing is lost or corrupted.
3. A full DB restore to a pre-M17 backup **does** reverse a merge, but also reverses every other interim change (new orders, receipts, other supplier edits) — treat this as the "Full restore (catastrophic)" path below, not a targeted undo. **Recommendation: take a database backup before the first production merge post-release** (see `docs/deployment-checklist.md`'s standard `wp db export` step, and `docs/release-runbook.md`'s M17 appendix).

**Schema rollback is optional and never required for a safe code rollback**, matching this document's general M6 precedent — the `merged_into_supplier_id` column and `wc_io_supplier_merges` table are purely additive and inert to older code.

---

## ✓ M16 (v1.33.0, frozen/unreleased): code-only, plus one pre-existing settings option — safe to roll back

**M16 changed no schema and mutated no domain/operational data.** It adds a provenance hint on the New PO screen, Supplier/Status columns on the Inventory Position drilldown (both purely additive read/presentation), and one Settings-tab field for a **pre-existing** option (`WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS`) that already existed and was already read throughout the codebase before this milestone — M16 only adds a UI to edit it, via the exact same `Settings::save_from_post()` mutation path every other Settings field already uses.

- **Code rollback is unconditionally safe.** The provenance hint, the Settings field, and the two drilldown columns simply disappear; every existing New PO / Settings / Inventory Position behavior is untouched, proven by the pre-existing M3/M9/M10 test suites passing unmodified alongside the new M16 tests.
- **No new `wp_options` row, no new table, no new column** — the one option this milestone can write (`wc_io_po_delay_grace_days`) already existed pre-M16 (read by `PO_Delay::grace_days_from_option()` since M8) and defaults to `0`; rolling back the code leaves that option unreachable via UI again, exactly as it was before M16, and safe to leave at whatever value it holds.
- **Historical Purchase Order / Purchase Order Line data is untouched** — the drilldown extension only adds `SELECT` columns to the already-existing `query_open_lines()` query; the suggestion provenance hint only reads already-computed `Supplier_Lead_Time_Service` stats. Neither ever writes.
- Like M7–M15, **M16 leaves nothing behind** beyond the absence of its three UI additions after rollback.

---

## ✓ M15 (v1.32.0, frozen/unreleased): code-only, read-only spend aggregate — as clean as M7–M14, nothing to reverse

**M15 wrote no data, changed no schema, and mutated nothing.** It only adds one new, self-contained aggregate read method (`Purchase_Orders::spend_summary_for_supplier()`), one new Internal service (`Supplier_Spend_Service`), and one new "Spend Summary" render section on the existing Supplier detail screen. No new option, table, column, setting, capability, or public API/hook.

- **Code rollback is unconditionally safe.** The "Spend Summary" section and its supporting method/service simply disappear; every existing Observed Lead Time / Order History / Purchase Order behavior is untouched, proven by the pre-existing M9/M11/M13/M14 test suites passing unmodified alongside the new M15 tests (isolated `--filter` regression spot-check, 57/57 green).
- **No `wp_options` row, no new table, no new column, no new setting** — nothing for a rollback to leave behind.
- **Historical Purchase Order / Purchase Order Line data is untouched** — M15 only reads via `spend_summary_for_supplier()`'s own `SELECT`, never writes.
- Like M7–M14, **M15 leaves nothing behind** beyond the absence of the Spend Summary section after rollback.

---

## ✓ M14 (v1.31.0, frozen/unreleased): code-only, read-only order-history list — as clean as M7–M13, nothing to reverse

**M14 wrote no data, changed no schema, and mutated nothing.** It only adds a standalone, read-only, paginated "Order History" section on the existing Supplier detail screen (`Supplier_Order_History_Service`, composed exclusively through `Purchase_Orders::count()`/`list()`/`values_bulk()`). No new option, table, column, setting, capability, or public API/hook.

- **Code rollback is unconditionally safe.** The "Order History" section and its supporting service/method simply disappear; every existing Supplier admin behavior (Observed Lead Time, On-Time Rate, PO Admin) is untouched, proven by the pre-existing test suites passing unmodified alongside the new M14 tests.
- **No `wp_options` row, no new table, no new column, no new setting** — nothing for a rollback to leave behind.
- **Historical Purchase Order / Purchase Order Line data is untouched** — M14 only reads via the existing `Purchase_Orders` read owner, never writes.
- Like M7–M13, **M14 leaves nothing behind** beyond the absence of the Order History section after rollback.

---

## ✓ M13 (v1.30.0, frozen/unreleased): code-only, read-only print view — as clean as M7–M12, nothing to reverse

**M13 wrote no data, changed no schema, and mutated nothing.** It only adds a standalone, read-only printable HTML view of a Purchase Order (`PO_Print_Renderer`, composed by `PO_Admin::handle_print()`) reachable via one new `admin_post_wc_io_po_print` action. No new option, table, column, setting, capability, or public API/hook.

- **Code rollback is unconditionally safe.** The "Print" link and the `admin_post_wc_io_po_print` handler simply disappear; every existing PO admin behavior (save/place/cancel/close-short/duplicate/receiving history/timeline) is untouched, proven by the pre-existing PO Admin test suite passing unmodified alongside the new M13 tests.
- **No `wp_options` row, no new table, no new column, no new setting** — nothing for a rollback to leave behind.
- **Historical Purchase Order / Goods Receipt / Supplier data is untouched** — M13 only reads via the three existing repositories (`Purchase_Orders`, `Purchase_Order_Lines`, `Suppliers`), never writes.
- Like M7–M12, **M13 leaves nothing behind** beyond the absence of the Print link/document after rollback.

---

## ✓ M12 (v1.29.0): code-only, read-only list columns — as clean as M7–M11, nothing to reverse

**M12 wrote no data, changed no schema, and mutated nothing.** It only adds two read-only columns on the Suppliers list table that call the existing `Supplier_Lead_Time_Service::get_stats_bulk()` once per page. No new option, table, column, setting, or public API.

- **Code rollback v1.29.0 → v1.28.0 is unconditionally safe.** The list simply stops showing Observed Lead Time and On-Time Rate; the configured Lead Time column and the supplier detail panel remain as in M11.
- **No `wp_options` row, no new table, no new column, no new setting** — nothing for a rollback to leave behind.
- **Historical Purchase Order / Goods Receipt data is untouched** — M12 only reads via the existing service.
- Like M7–M11, **M12 leaves nothing behind** beyond the absence of the two list columns after rollback.

---

## ✓ M11 (v1.28.0): code-only, read-only feature — as clean as M7/M8/M9/M10's, nothing to reverse

**M11 wrote no data, changed no schema, and mutated nothing anywhere in its surface.** The new `Expected_Deadline` class is pure (guard-enforced — zero `$wpdb`, zero writes, zero WordPress option access); the extended `Supplier_Lead_Time_Service` remains read-only by construction, computing `on_time_count`/`rated_order_count` fresh on every call from the exact same underlying Purchase Order / Goods Receipt data M9 already reads — nothing is ever persisted.

- **Code rollback v1.28.0 → v1.27.0 is unconditionally safe.** The Supplier detail screen simply stops showing the "On-Time Delivery Rate" row; the Observed Lead Time panel above it, and everything else on the page, is unaffected. No other screen is touched.
- **No `wp_options` row, no new table, no new column, no new setting** — there is nothing for a rollback to leave behind or need to clean up. `PO_Delay`'s existing `wc_io_po_delay_grace_days` option is read, never written, by the new feature.
- **`PO_Delay`'s live delay-flagging behavior is provably unaffected either way** — its internal refactor to consume `Expected_Deadline` changed no public method's signature or output (proven by its complete pre-existing test suite passing unmodified); rolling back simply removes the `Expected_Deadline` class and reverts `PO_Delay` to its pre-M11 internals, with identical behavior throughout.
- **Historical and in-flight Purchase Order / Goods Receipt data is untouched** in either direction — M11 never writes to any of it; rolling back changes nothing about already-recorded history.
- Like M7, M8, M9, and M10, **M11 leaves nothing behind** — the plugin is byte-for-byte back to pre-M11 behavior the moment the code is rolled back; a rolled-back site simply stops showing the on-time delivery figure, which is not a functional regression a merchant would notice beyond the report's absence (Observed Lead Time and every other feature are unaffected).

---

## ✓ M10 (v1.27.0): code-only, advisory-only feature — as clean as M7/M8/M9's, nothing to reverse

**M10 wrote no data, changed no schema, and mutated nothing anywhere in its surface.** `Expected_Date_Suggestion_Service` is read-only by construction (guard-enforced — zero writes, and it never touches `$wpdb` directly at all). The only stored values it ever influences (`expected_date`/`expected_confidence` on a new Purchase Order) are written through the exact same, unchanged form-submission path a manually-typed value already used — nothing about the suggestion mechanism itself is persisted (plan §5.2 return shape).

- **Code rollback v1.27.0 → v1.26.0 is unconditionally safe.** The new-PO creation screen simply stops pre-filling Expected Date/Confidence; the fields go back to being blank/`unknown` by default, exactly as before M10. No other screen, including the M9 Observed Lead Time panel and PO editing, is touched.
- **No `wp_options` row, no new table, no new column, no new setting** — there is nothing for a rollback to leave behind or need to clean up.
- **Historical and in-flight Purchase Order data is untouched** in either direction — a PO created while M10 was active has ordinary `expected_date`/`expected_confidence` values indistinguishable from a manually-entered PO; rolling back changes nothing about already-submitted POs.
- **The one small M9 code change (`is_observed_value_usable()`, an additive method) is also safe to roll back** — no other code calls it once M10's own service is removed, so removing it removes no behavior anything else depends on.
- Like M7, M8, and M9, **M10 leaves nothing behind** — the plugin is byte-for-byte back to pre-M10 behavior the moment the code is rolled back; a rolled-back site simply stops offering the pre-fill suggestion, which is not a functional regression a merchant would notice beyond the convenience's absence (the underlying manual-entry workflow is unchanged).

---

## ✓ M9 (v1.26.0): code-only, read-only feature — as clean as M7/M8's, nothing to reverse

**M9 wrote no data, changed no schema, and mutated nothing anywhere in its surface.** `Supplier_Lead_Time_Service` is read-only by construction (guard-enforced — no `set_stock_quantity`/`update_post_meta`/`->insert(`/`->update(`/`->delete(` anywhere in the file), and every statistic it returns is computed fresh from existing Purchase Order / Goods Receipt / Receipt Line data on every call — nothing is ever persisted (plan §6.1 "Source of Truth").

- **Code rollback v1.26.0 → v1.25.0 is unconditionally safe.** The new admin panel (Purchasing → Suppliers → edit screen) simply disappears; the existing "Default Lead Time (days)" field and every other supplier/PO/receipt screen is untouched, since M9 added a new read-only display and changed no existing code path's behavior.
- **No `wp_options` row, no new table, no new column, no new setting** — there is nothing for a rollback to leave behind or need to clean up.
- **Historical Purchase Order and Goods Receipt data is untouched** in either direction — M9 only ever reads those tables; rolling back removes the code that reads them, never the data itself.
- Like M7 and M8, **M9 leaves nothing behind** — the plugin is byte-for-byte back to pre-M9 behavior the moment the code is rolled back; a rolled-back site simply stops showing the Observed Lead Time panel, which is not a functional regression a merchant would notice beyond the panel's absence.

---

## ✓ M8 (v1.25.0): code/test/CI-only — as clean as M7's, nothing to reverse

**M8 wrote no data, changed no schema, and mutated nothing anywhere in its surface.** Unlike M1–M7, M8 is not a feature milestone: it removed already-unreachable dead code, fixed a computed-value predicate, added test-only guards, and hardened CI configuration — no new `wp_options` row, no new table, no new column.

- **Code rollback v1.25.0 → v1.24.0 is unconditionally safe.** The physically-removed Batch Intake code (`Batch_Intake_Service`'s five deleted methods, `Batch_Intake_UI`, `Plugin::ajax_batch_preview()`/`handle_batch_apply_post()`) was already unreachable before M8 (no admin-post/AJAX hook has pointed at it since M6) — rolling back simply restores that same, still-unreachable code. There is no operational difference for a merchant either way.
- **The `PO_Delay` fix is purely a computed-value change** (INV-5: delayed is always computed, never stored) — rolling back reverts to the pre-M8 predicate; no data needs to be un-flagged, since "Delayed" was never written anywhere, only rendered fresh on each admin page load.
- **Legacy `wc_io_purchase_batches*` data and tables are untouched** either direction — M8 removed code that read them for a now-retired UI path, never their data (D14, frozen forever, unchanged since M6).
- The new conformance guard, GA-scale performance tests, and CI configuration changes are testing/tooling artifacts with no runtime footprint on a production site at all.
- Like M7, **M8 leaves nothing behind** — the plugin is byte-for-byte back to pre-M8 behavior the moment the code is rolled back, with the one difference that a rolled-back site simply stops benefiting from the `partially_received` delay fix and the (already-unreachable) code stops being unreachable-but-present — neither is a functional regression a merchant would notice.

---

## ✓ M7 (v1.24.0): the cleanest rollback of any milestone — leaves nothing behind

**M7 has the cleanest rollback story of any milestone in this program — cleaner even than M6's.**

- **Instant, no deploy:** set `wc_io_expected_delivery_renderer_enabled` to `no` (Inventory & Profit → Settings → Storefront → "Enable Expected Delivery display" → No). Storefront output returns to stock WooCommerce immediately. Nothing else in the plugin changes behavior.
- **Code rollback v1.24.0 → v1.23.0 is unconditionally safe.** M7 wrote no data, changed no schema, and mutated nothing — no stock, no PO, no Goods Receipt, no product meta. The only persistent artifact of M7 is one `wp_options` row (`wc_io_expected_delivery_renderer_enabled`) that v1.23.0 simply never reads. There is no data-safety reason to remove it, and no schema to reverse.
- Unlike M4/M5 below (stock/`qty_received` effects survive a code rollback) and unlike M6 above (additive migrated rows survive, harmlessly inert), **M7 leaves nothing behind at all** — the storefront is byte-for-byte back to pre-M7 behavior the moment either the setting is toggled off or the code is rolled back.

---

## ✓ M6 (v1.23.0): code rollback after batch migration is safe by construction

Unlike M4/M5 below, **M6 does not add a new "code rollback is unsafe" risk class.** A plugin-code rollback to a pre-M6 version (v1.22.0) after `wp wc-io migrate-batches --apply` has already migrated some batches is safe:

- Migrated Goods Receipts (`source = 'migrated'`) are purely **additive rows**. No v1.22.0 code path filters, joins, or otherwise queries `source = 'migrated'` — that value, and the two `wc_io_purchase_batches.migrated_receipt_id`/`migrated_at` tracking columns, simply did not exist before M6 and v1.22.0 code never reads them. Rolling back leaves them present in the database but entirely inert to the older code.
- The legacy `wc_io_purchase_batches`/`wc_io_purchase_batch_lines`/`wc_io_purchase_batch_costs` tables were **never modified** by migration — only the two new nullable tracking columns were added (schema v10), which v1.22.0 code simply ignores (dbDelta-created columns are additive; older code never selects them by name).
- Reverting **code** without reverting the **schema** leaves the database in a strict superset of what v1.22.0 expects — nothing v1.22.0 does breaks, errors, or behaves differently because those extra rows/columns exist.
- A schema rollback (dropping `migrated_receipt_id`/`migrated_at`) is **optional and never required** for a safe code rollback.

**This is a distinct concept from the migration CLI's own `--rollback=<batch_id>` mode** (`wp wc-io migrate-batches --rollback=<id>`), which undoes *one specific batch's migration* (deletes its migrated receipt/lines/costs, clears its movement reference, clears its tracking columns) while the plugin is still running the *current* code — see `docs/milestones/m6-implementation-plan.md` §Migration model / §Rollback and `docs/migration-guide-batch-intake.md` for that operator workflow. The section here is about reverting the *plugin version* itself, not undoing an individual migration.

Batch Intake's retirement (the admin_post/AJAX entry points removed in M6) also introduces no rollback risk: no batch data was deleted, and a code rollback to v1.22.0 simply restores the old Batch Intake UI, unaffected by anything M6 did.

---

## ⚠ M5 and later: code rollback does not reverse qty_received/PO-status effects

**Starting with M5 (v1.22.0), a plugin-code rollback to a pre-M5 version does NOT reverse the `qty_received` or Purchase Order status effects of PO-linked receipts already posted under M5** — in addition to the stock/cost effects M4 already introduced this same risk for (see the M4 section immediately below, which still applies unchanged and in full to any receipt, PO-linked or direct).

This extends the same new risk class M4 introduced to a second domain: the Purchase Order's own `qty_received` counter and `status` (`placed → partially_received → received`) are, as of M5, real committed state maintained by `WC_Inventory_Overview_PO_Receiving_Sync` — the exact same "sole mutator" argument M4 made for stock/cost applies here. If a receipt with `po_line_id` set has been **posted** after upgrading to v1.22.0+, the PO line's `qty_received` and the PO's `status` are real, committed state; rolling the *plugin code* back to a pre-M5 version does not, and cannot, undo those changes — the older code simply has no PO-linked receiving UI to view or void them with (`wc_io_purchase_order_lines.qty_received` and `wc_io_purchase_orders.status` remain exactly as posting left them, and the older code's PO admin screen would render `partially_received`/`received` as an unrecognized status).

**If a rollback is needed after M5 PO-linked receipts have been posted:**

1. **Do not roll back plugin code as a way to "undo" a bad PO-linked receipt.** Use **Void** (in the still-current version) instead — voiding correctly walks both `qty_received` and PO status back down, regardless of what else has posted against the same PO in between (see `docs/milestones/m5-implementation-plan.md` §Receiving-status ownership).
2. If a genuine code-level rollback is required for an unrelated reason, the `qty_received`/PO-status effects of any PO-linked receipts posted while on v1.22.0+ **remain in effect** — reconcile manually (the M5 reconciliation CLI, `wp wc-io reconcile-qty-received`, can verify `qty_received` against actual posted receipt history even from a rolled-back-code state, since it only reads `wc_io_receipt_lines`/`wc_io_goods_receipts`, tables the older code doesn't touch but can still read via raw SQL if needed).
3. A full DB restore to a pre-M5 backup **does** reverse everything, including `qty_received`/PO-status effects, but also reverses every other interim change — treat this as the "Full restore (catastrophic)" path below, not a targeted undo.

---

## ⚠ M4 and later: code rollback does not reverse posted-receipt stock effects

**Starting with M4 (v1.21.0), a plugin-code rollback to a pre-M4 version does NOT reverse the stock, average-cost, inventory-value, or movement effects of Goods Receipts already posted under M4.**

This is a genuinely new risk class. M1 (Suppliers) and M2 (Purchase Orders) were schema-additive-only; M3 (Inventory Position) was strictly read-only. Neither ever mutated WooCommerce stock or costing meta, so rolling their code back was always safe — the data a rolled-back version would read was never touched by the newer code in the first place.

M4 is the first milestone that mutates stock and cost (D3/INV-2: Goods Receipt posting is the sole stock mutator). If a receipt has been **posted** after upgrading to v1.21.0+, its stock/cost/value changes are real, committed WooCommerce state — rolling the *plugin code* back to a pre-M4 version does not, and cannot, undo those changes; the older code simply has no receipts UI to view or void them with (the `wc_io_goods_receipts`/`wc_io_receipt_lines`/`wc_io_receipt_costs` tables and `_stock`/`_wc_io_average_unit_cost`/`_wc_io_inventory_value` remain as posting left them).

**If a rollback is needed after M4 receipts have been posted:**

1. **Do not roll back plugin code as a way to "undo" a bad receipt.** Use **Void** (in the still-current version) instead — voiding is the only correct, current-state-relative reversal mechanism (see `docs/milestones/m4-implementation-plan.md` §Inventory mutation — Voiding correctness).
2. If a genuine code-level rollback is required for an unrelated reason (a PHP fatal error, a regression elsewhere), the stock/cost effects of any receipts posted while on v1.21.0+ **remain in effect** — reconcile manually against physical inventory if needed, the same way a full DB restore would be reconciled (see "Full restore" below).
3. A full DB restore to a pre-M4 backup **does** reverse everything, including receipt effects, but also reverses every other change made in the interim (orders, other inventory movements) — treat this as the "Full restore (catastrophic)" path below, not a targeted undo.

---

## When to roll back

- PHP fatal errors on admin inventory pages after deploy
- Incorrect costing/movements after a bad release (prefer restore + code fix)
- Failed DB upgrade (`wc_io_db_version` mismatch symptoms)

---

## Plugin-only rollback (preferred)

1. **Deactivate** (optional, if site unstable):
   ```bash
   ./wp plugin deactivate wc-inventory-overview
   ```

2. **Install previous ZIP** from `builds/wc-inventory-overview-{previous}.zip` or GitHub Release.

3. **Activate:**
   ```bash
   ./wp plugin activate wc-inventory-overview
   ./wp cache flush
   ```

4. Verify admin hub and one report tab.

**Data:** Older plugin versions generally read the same custom tables. Downgrading across `DB_VERSION` bumps may leave schema newer than code expects — avoid downgrading across major DB version changes without DBA review.

---

## Database considerations

| Data | Rollback impact |
|------|-----------------|
| Custom tables `wc_io_*` | Retained; not removed on deactivate |
| Options `wc_io_*` | Retained |
| Order line snapshot meta | Retained on order items |
| Movement / batch history | Retained |

Plugin deactivation does **not** drop tables. Uninstall hook (if added later) should be documented separately.

---

## Full restore (catastrophic)

If deploy corrupted data or wrong danger-zone reset was applied:

1. Stop writes (maintenance mode if needed)
2. Restore MariaDB from pre-deploy dump
3. Restore `wp-content/plugins/wc-inventory-overview/` from known-good ZIP
4. `./wp cache flush`
5. Reconcile WooCommerce stock with physical inventory if movements were lost

---

## Danger zone mistake

If **Settings → Danger zone** delete was applied in error:

- Restore DB backup (only reliable recovery)
- Plugin cannot reconstruct deleted movement/batch rows from WC core alone

---

## Prevention

- Always export DB before deploy and before danger-zone operations
- Tag releases: `v1.17.0` matching plugin header
- Keep at least two ZIP versions in `builds/` or GitHub Releases
