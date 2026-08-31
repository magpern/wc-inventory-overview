# ADR-0004 — Outbound stock adjustment ownership

## Status

Accepted. Implemented in M27 (v1.44.0).

## Context

Architecture v1.0 (INV-2, D3) states that Goods Receipt posting/voiding is the
sole path through which this plugin mutates WooCommerce stock and weighted-average
cost in the purchasing domain. Quick Restock remains a legacy inbound path via
`Restock_Service::process_purchase_restock()`.

After M26, operators had no controlled, audited way to record owner personal use
(withdrawals that reduce physical stock and inventory cost at WAC without creating
customer orders or order-profit COGS). Inline stock edit bypasses the movement
ledger and uses a broader capability (`edit_products`).

D15 deferred full sales/refund/manual-edit ledger reconstruction to a later
initiative. M27 delivers the first slice: **personal use** outbound adjustments
only.

## Decision

1. **This plugin owns outbound non-sale stock adjustments** (starting with
   `personal_use`) via `WC_Inventory_Overview_Stock_Adjustment_Service` — the
   sole orchestrator for M27 outbound mutation.

2. **Stock and cost meta mutation** for outbound adjustments flows through new
   `Restock_Service::apply_outbound_line_change()` /
   `apply_outbound_line_reversal()` methods, callable **only** from
   `Stock_Adjustment_Service` (architecture guard enforced).

3. **INV-2 is amended in prose, not replaced:** inbound receiving remains
   `Goods_Receipt_Service` (+ Quick Restock inbound). Outbound personal use is
   an additive, separate mutator with its own entity (`wc_io_stock_adjustments*`)
   and movement types (`personal_use`, `personal_use_void`).

4. **Cost valuation** reuses the existing EUR weighted-average model at post
   time — not FIFO, not lot-based, not retail price.

5. **No WooCommerce orders, no order-line snapshots, no PO/incoming changes**
   for personal-use postings.

6. **Compensating snapshot restore** on partial failure — SQL transactions do
   not roll back WooCommerce product meta; M27 documents and implements explicit
   `restore_snapshot()` recovery (see M27 plan §15.1).

## Consequences

- Positive: Audited personal-use withdrawals; movement ledger evidence for
  accounting export; stricter capability gate than inline stock edit.
- Positive: Schema v12 adds `wc_io_stock_adjustments` / `wc_io_stock_adjustment_lines`
  without changing existing tables' semantics.
- Neutral: Generic stock corrections (`adjustment` movement type) remain deferred
  to M28+.
- Negative: Historical unrecorded consumption requires operator reconciliation
  (M27 plan §4.1) — the workflow records withdrawals from current on-hand, not
  backdated consumption dates.
- Risk: Residual TOCTOU on concurrent stock edits (documented, same class as M25).

Cross-reference: [ADR-0001](0001-inbound-domain-ownership.md) (inbound ownership);
MPCF ADR-0007 (fulfillment/outbound customer orders — separate domain).
