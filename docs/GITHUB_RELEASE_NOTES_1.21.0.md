# WC Inventory Overview 1.21.0

**Canonical standalone release** from [magpern/wc-inventory-overview](https://github.com/magpern/wc-inventory-overview).

## Prerequisite

Upgrade from **1.20.0** (M3 Inventory Position, schema v7).

## What changed

**Milestone M4 — Receipt Engine (Goods Receipt)** — the first milestone that mutates WooCommerce stock and weighted-average cost through this plugin (D3/INV-2). Implements "Quick Receive Without PO" (D7): direct receipts only, no Purchase Order linkage. **Schema v8** — three new tables (`wc_io_goods_receipts`, `wc_io_receipt_lines`, `wc_io_receipt_costs`) plus an ALTER on `wc_io_inventory_movements`.

- **Goods Receipt Service**: the sole entry point for every M4 inventory mutation (structurally enforced by an architecture-guard test). `post()`/`void()` each run inside one database transaction, with a `throw_if_error()` bridge ensuring no fallible call can silently escape the transaction unrolled-back.
- **Transactional integrity**: forced-failure tests prove full rollback — a failure mid-post or mid-void leaves stock, cost meta, and movement rows completely unchanged, and the receipt status unchanged.
- **Correct voiding**: reverses only the voided receipt's own contribution, current-state-relative — proven by a regression test that posts receipt A, posts receipt B against the same product, voids A, and asserts B's contribution survives exactly. Void is rejected (not partially applied) if units have since been sold below the receipt's contribution.
- **Idempotency**: one-shot request tokens plus a compare-and-swap status guard — the complete concurrency model, deliberately without row locking (a single-warehouse, 1–3-admin deployment doesn't need it).
- **"Receive Stock" admin tab**: alongside — not replacing — Batch Intake, Quick Restock, and Cost Adjustment. Draft create/edit/delete, product/variation picker, landed costs, computed preview, explicit post-confirmation screen, void with mandatory reason.
- 230 dedicated tests (numbering, lifecycle, repositories, costing/allocation, Restock reversal, transactional post/void, idempotency, capability, 16-test architecture guard).

**A real bug found and fixed during implementation:** WordPress's `update_post_meta()` writes through to the object cache synchronously, independent of the surrounding SQL transaction — on a persistent cache backend (Redis, this deployment), a rolled-back mutation could otherwise leave a phantom, never-committed value cached. Fixed by invalidating the object cache (`clean_post_cache()`) on both the commit and the rollback path.

**Explicitly not in this release:**

- No Receive-Against-PO, no `qty_received`, no PO-side status/quantity change, no PO events — `receipt_line.po_line_id` exists in the schema for M5 but is never populated by any M4 code path.
- No legacy Batch Intake migration or retirement (M6).
- No changes to Purchase Orders, Suppliers, or Inventory Position business logic.
- No new REST endpoints, no new WordPress capability, no row-locking/queueing infrastructure.

## Install / upgrade

1. Download **`wc-inventory-overview-1.21.0.zip`** from this release.
2. Upload via **Plugins → Add New → Upload**, or use **Dashboard → Updates** on production.
3. `DB_VERSION` bumps from **7** to **8** — the three new tables and the `inventory_movements` ALTER run automatically on activation/upgrade.

## Before tagging

Per [docs/release-runbook.md](release-runbook.md#m4-receipt-engine): confirm CI (unit, M1–M4 blocking, and M4-only suites) passes, confirm `DB_VERSION` is `8` and the schema assertion is `ok: true`, walk through a Quick Receive draft → post → void cycle and verify stock/average-cost/inventory-value match the weighted-average formula by hand, and confirm Batch Intake / Quick Restock / Cost Adjustment / Purchase Orders remain unaffected.

## Rollback

**Read this before rolling back a site that has posted any Goods Receipts.** Unlike 1.18.0–1.20.0, this release mutates WooCommerce stock — a plugin-code rollback to 1.20.0 does **not** reverse the stock/cost/value effects of receipts already posted under 1.21.0. Use **Void** (on the current version) to reverse a specific receipt instead of rolling back code. See [docs/rollback-plan.md](rollback-plan.md) for the full explanation and the catastrophic full-restore path if a genuine rollback is unavoidable.

Changelog: [CHANGELOG.md](../CHANGELOG.md)
