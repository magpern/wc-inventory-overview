# Rollback plan — WC Inventory Overview

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
