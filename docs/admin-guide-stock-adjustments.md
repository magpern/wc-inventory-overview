# Admin guide — Stock Adjustments (Personal Use)

**Milestone M27 · Plugin v1.44.0 · `DB_VERSION` 12**

Use **WooCommerce → Inventory & Profit → Stock Adjustments** to record audited outbound withdrawals for owner/operator personal use. Each posted adjustment reduces on-hand stock and inventory value at the product’s current EUR weighted average cost (WAC) and writes movement rows (`personal_use` / `personal_use_void`).

## When to use Stock Adjustments

- You consumed stock personally and need a **cost-tracked, movement-logged** withdrawal.
- You are reconciling **historical** personal consumption (see §4.1 below).

Do **not** use Stock Adjustments for:

- Inbound receiving (use **Receive Stock** / Quick Restock).
- Customer sales (handled separately via order profit snapshots).
- Quick quantity tweaks without cost audit (inline stock edit on Inventory Overview — unaudited).

## Workflow

1. Open **Stock Adjustments** and click **Record personal use** (or use **Record personal use** on an Inventory Overview row to prefill one line).
2. Add stock-managed simple or variation lines (max **100** per document; each product once).
3. Enter a **mandatory note** (e.g. “Owner samples for photography”).
4. Review the per-line **preview** (on-hand, WAC, value removed, resulting stock/value).
5. **Save draft**, then **Post personal use** on the confirmation screen.
6. To reverse a posted document in error: **Void personal use** with a mandatory void reason.

Posted and voided documents are read-only. Void restores stock and value using the **immutable posted delta** (not historical replay).

## §4.1 Historical reconciliation (already-consumed stock)

M27 posts a **single audited withdrawal** from **current recorded on-hand** to match physical reality. It does **not** backdate consumption.

**Procedure:**

1. **Count physical stock** for each affected simple product or variation.
2. **Read recorded on-hand** in Inventory Overview (or the product editor).
3. Compute: `qty_to_record = recorded_on_hand − physical_count_remaining`.
4. If `qty_to_record ≤ 0`: **do not post** personal use for that item (already aligned, or physical exceeds recorded — handle separately).
5. If `qty_to_record > 0`: add a line with **`qty = qty_to_record`**. Cost is valued at **current WAC at post time**.
6. **Do not** first reduce stock via inline edit or the WooCommerce product editor and then post the same quantity — that **double-reduces**.
7. **Do not** use Quick Restock or Receive Stock for withdrawals (inbound-only).

**Example:** Recorded on-hand = 8, physical remaining = 5 → post personal use **qty 3** once. Final on-hand becomes 5 with movement evidence at `3 × current_WAC`.

## Movements and reporting

- **Inventory Movements** lists `Personal use` and `Personal use void` as separate rows (full audit trail).
- **Economic outbound personal use** = net sum of both types (void rows carry opposite-sign values).
- CSV export includes `movement_type`; filter externally for net totals.
- Movement rows link to the Stock Adjustment detail when `reference_type = stock_adjustment`.

## Permissions

Default capability: `manage_woocommerce` (filterable via `wc_io_purchasing_capability_map`):

| Action | Cap key |
|--------|---------|
| View list/detail | `view_stock_adjustment` |
| Create/edit/delete draft | `edit_stock_adjustment` |
| Post | `post_stock_adjustment` |
| Void | `void_stock_adjustment` |

## Compensation failures

If posting fails after partial WooCommerce meta updates and compensation cannot restore a product, the operator sees `wc_io_compensation_failed`. Check the WooCommerce log (`wc-inventory-overview` source), reconcile that product’s stock and `_wc_io_*` meta manually, then retry from a fresh draft token.

## Rollback

Rolling back plugin code to pre-M27 does **not** undo posted adjustments. Void posted documents first, or reconcile inventory manually. See `docs/rollback-plan.md`.
