# Rollback plan — WC Inventory Overview

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
