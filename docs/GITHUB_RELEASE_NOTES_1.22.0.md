# WC Inventory Overview 1.22.0

**Canonical standalone release** from [magpern/wc-inventory-overview](https://github.com/magpern/wc-inventory-overview).

## Prerequisite

Upgrade from **1.21.0** (M4 Receipt Engine, schema v8).

## What changed

**Milestone M5 — Purchase Order Receiving** — connects Purchase Orders (M2) to the Goods Receipt engine (M4). `qty_received` becomes a real, maintained column on `wc_io_purchase_order_lines` (the full INV-4 formula: `qty_outstanding = GREATEST(0, qty_ordered - qty_received - qty_cancelled)`). **Schema v9** — one column addition, zero new tables (M4 had already prepared `receipt_line.po_line_id` and `goods_receipt.source` for this moment).

- **`Goods_Receipt_Service` remains the sole stock/cost mutator** (D3/INV-2, unchanged) and gains a second responsibility: sole business orchestrator for `qty_received` changes. It never writes `qty_received` itself — it delegates to a new class, `PO_Receiving_Sync`, the sole owner of that mutation, which in turn is the only caller of `Purchase_Order_Lines::increment_qty_received()`, the sole physical writer. No second mutation path was introduced anywhere — every tier of this three-tier chain is architecture-guard enforced.
- **Two new PO statuses**, `partially_received`/`received`, auto-transitioned only via a pure, direction-agnostic recompute function — never operator-selected, never reachable through the manual transition table.
- **Receiving against a PO**: a "Receive" button on the PO detail page pre-fills a new Goods Receipt draft from the PO's outstanding lines, reusing the existing M4 draft/post/void flow unchanged. Mixed receipts (PO-linked + direct lines) and multi-PO receipts both work.
- **Over-receipt is allowed, never blocked** (per D5) — warned at post-confirmation, recorded in the PO event log with an `over_receipt`/`qty_over` marker.
- **Correct, order-independent void reversal**: both mandatory regression scenarios pass — post A, post B, void A (B's contribution survives); and post A, post B, void B, void A (order-independence, `qty_received`/outstanding/PO status correct at every one of the four steps).
- **Reconciliation CLI**: `wp wc-io reconcile-qty-received [--fix] [--po=<id>]` — read-only drift report by default; `--fix` repairs through the sole-owner class only, every repair individually logged and recorded as its own PO event.
- **The M3 Incoming regression fix**: Inventory Position's "Incoming" figure now correctly decreases as receipts post against PO lines — the recomputation M3's own plan explicitly deferred to this milestone.
- 60 new dedicated tests (full INV-4 formula, status-recompute direction-agnostic behavior, `PO_Receiving_Sync` end-to-end, PO-linked post/void including the forced-failure rollback test and both mandatory void-order regressions, pre-transaction validation, `po_line_id` persistence, the M3 regression, architecture guards) — **290 tests / 1,305 assertions total in the M1–M5 focused suite, all passing.**

**Explicitly not in this release:**

- No new stock/cost mutation path — `Restock_Service`'s caller set gained zero new entries.
- No header-level `po_id` column on `wc_io_goods_receipts` (D6: line-level linkage only, unchanged from M4).
- No new value written to the per-line `wc_io_purchase_order_lines.status` column — "line completion" is derived from `qty_outstanding == 0`, never stored as a line-level enum.
- No legacy Batch Intake migration or retirement (M6). No ASN, barcode receiving, or warehouse scanning.
- No changes to Batch Intake, Quick Restock, Cost Adjustment, or Supplier admin business logic.

## Install / upgrade

1. Download **`wc-inventory-overview-1.22.0.zip`** from this release.
2. Upload via **Plugins → Add New → Upload**, or use **Dashboard → Updates** on production.
3. `DB_VERSION` bumps from **8** to **9** — the `qty_received` column addition runs automatically on activation/upgrade. Every existing PO line starts at `qty_received = 0` (correct-by-construction — no PO line created before M5 could ever have had a receipt posted against it).

## Before tagging

Per [docs/release-runbook.md](release-runbook.md#m5-po-receiving): confirm CI (unit, M1–M5 blocking, and cumulative integration suites) passes with zero new failures, confirm `DB_VERSION` is `9` and the schema assertion is `ok: true` (dispatcher-routing double-checked — the same v(n-1)/v(n) trap M4 already flagged repeats identically at v8/v9), walk through receive-against-PO (partial → complete → void) and verify `qty_received`/PO status/Inventory Position match by hand, confirm over-receipt is possible and audited (not blocked), and confirm Batch Intake / Quick Restock / Cost Adjustment / M4's Quick Receive Without PO remain unaffected.

## Rollback

**Read this before rolling back a site that has posted any PO-linked receipts.** Like 1.21.0, this release mutates state a code-only rollback cannot reverse — a plugin-code rollback to 1.21.0 does **not** reverse the `qty_received`/PO-status effects of receipts already posted under 1.22.0. Use **Void** (on the current version) to reverse a specific receipt instead of rolling back code. See [docs/rollback-plan.md](rollback-plan.md) for the full explanation and the catastrophic full-restore path if a genuine rollback is unavoidable.

Changelog: [CHANGELOG.md](../CHANGELOG.md)
