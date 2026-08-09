# Rollback plan — WC Inventory Overview

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
