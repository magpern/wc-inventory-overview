# WC Inventory Overview 1.23.0

**Canonical standalone release** from [magpern/wc-inventory-overview](https://github.com/magpern/wc-inventory-overview).

## Prerequisite

Upgrade from **1.22.0** (M5 PO Receiving, schema v9).

## What changed

**Milestone M6 — Migration & Retirement** — the headline guarantee: migrating legacy Batch Intake history into Goods Receipts leaves current WooCommerce stock and cost **byte-for-byte unchanged** for every affected product, because migration is historical record materialization, not receiving — it never mutates current stock/cost, only writes a historical record in today's schema (verified by a dedicated golden/characterization test, a release blocker in its own right). Replaces the batch↔movement regex linkage with the typed `reference_type`/`reference_id` columns M4 already added, exactly as Architecture v1.0 §1 (D14) promised. Retires Batch Intake's ability to create new batches — the one thing it still did that Goods Receipts (M4/M5) hadn't already superseded — while leaving the legacy tables frozen, readable, and permanently the audit trail behind every migrated receipt. **Schema v10** — two migration-tracking columns on `wc_io_purchase_batches` (`migrated_receipt_id`, `migrated_at`); no new business-domain schema.

- **`wp wc-io migrate-batches [--apply] [--verify] [--batch=<id>] [--rollback=<id>] [--limit=<n>]`** — operator-initiated, dry-run by default (modeled on `reconcile-qty-received`'s shape). `--apply` migrates one batch at a time through `WC_Inventory_Overview_Batch_Migration_Service::migrate_batch()`, each call its own transaction (Invariant M6-1 — never a shared transaction across batches, which is what makes an interrupted run safely resumable: earlier-migrated batches stay committed, the failed batch fully rolls back, and a rerun picks up exactly where it stopped). `--verify` is the permanent, read-only reconciliation tool for this data going forward. `--rollback=<id>` undoes one batch's migration (deletes its migrated receipt/lines/costs, clears its movement reference, clears its tracking columns) after an operator confirmation prompt — never a Goods Receipt void, and never touches current stock/cost either.
- **`WC_Inventory_Overview_Batch_Migration_Service`** — the migration engine. Every batch's migration is a pure function of that batch's own already-stored rows (Invariant M6-2 — order independence: batches may be migrated in any order with an identical result, since migration never reads current stock, current cost, or any other batch's data). Migrated receipts always carry `source = 'migrated'`, `supplier_id = NULL` (never fuzzy-matched from the batch's free-text supplier name), and `po_line_id = NULL` on every line (never a fabricated PO linkage, per D7 and M5's own binding note). Receipt numbers are allocated in the batch's *original* year, not the migration year; timestamps (`posted_at`/`created_at`/`updated_at`) are the batch's original `created_at`, not migration time (`wc_io_purchase_batches.migrated_at` records that separately).
- **`Goods_Receipt_Service::void()` migrated-source guard** — rejects voiding a `source = 'migrated'` receipt with a clear error. Voiding reverses stock/cost relative to *current* state, which is wrong for a historical replay row for the same reason forward-migration never calls `post()`.
- **`WC_Inventory_Overview_Landed_Cost_Types`** — the landed-cost-type vocabulary (7 slugs/labels, unchanged), extracted out of `Batch_Intake_Service` into a small, neutral class both `Goods_Receipt_Costing` and (while retained) `Batch_Intake_Service` depend on — closing the hidden-coupling remediation trigger M4's own plan flagged for this exact moment.
- **`WC_Inventory_Overview_Movements::backfill_reference()`** — the sole, purpose-built writer that updates one existing movement row's `reference_type`/`reference_id` in place (never inserts a new row, never touches quantity/cost/note/timestamp columns), used exclusively by migration.
- **81 new dedicated tests** (architecture guards, pure field-mapping, the historical-integrity golden test, movement backfill, idempotency/resumability, deterministic forced-failure transactional-rollback, order independence, rollback symmetry, retirement regression, the landed-cost-type extraction characterization, and per-batch query-cost performance) — **371 tests / 1,547 assertions in the M1–M6 focused suite, all passing**.
- **Batch Intake's create/apply entry points** (`admin_post_wc_io_batch_apply`, `wp_ajax_wc_io_batch_preview`) — no longer registered, no new batch can be created after this release. The "Batch Intake" tab is gone from the Restock / Cost Adjustment admin nav (default subview becomes Quick Restock); a stale `restock_view=batch` bookmark now falls back to Quick Restock instead of erroring.

**Explicitly not in this release:**

- No new stock/cost mutation path — migration never calls `set_stock_quantity()`, never writes cost meta, never goes through `Goods_Receipt_Service::post()` or `Restock_Service` (a new, narrow, direct-write path for historical materialization only).
- No synthetic POs fabricated for migrated batches — `po_line_id` stays `NULL` on every migrated receipt line, per D7 and M5's own binding note.
- No inferred `qty_received` on Purchase Order lines from historical Batch Intake data — migrated receipts carry no PO linkage by construction.
- No fuzzy supplier name matching — `supplier_id` is always `NULL` on migrated receipts; the batch's free-text supplier name is preserved in `supplier_name_snapshot`.
- No dropping or truncating of legacy tables — `wc_io_purchase_batches`, `wc_io_purchase_batch_lines`, `wc_io_purchase_batch_costs` remain frozen, readable, and the permanent audit trail (D14).
- No changes to Quick Restock, Cost Adjustment, Goods Receipts (M4), PO Receiving (M5), Inventory Position (M3), or Supplier admin — all remain unaffected.
- No automatic migration as a side effect of the `DB_VERSION` upgrade routine — migration is a deliberate, separate, operator-initiated action taken *after* upgrading.

## Install / upgrade

1. Download **`wc-inventory-overview-1.23.0.zip`** from this release.
2. Upload via **Plugins → Add New → Upload**, or use **Dashboard → Updates** on production.
3. `DB_VERSION` bumps from **9** to **10** — the two migration-tracking columns run automatically on activation/upgrade. Every existing batch starts with `migrated_receipt_id = NULL` and `migrated_at = NULL` (correct-by-construction — no batch can be migrated until the schema is ready and the operator runs the migration CLI).
4. After upgrading to 1.23.0: take a database backup, then run `wp wc-io migrate-batches` (dry-run, no writes) to preview the migration. Run `wp wc-io migrate-batches --apply` to perform it. Run `wp wc-io migrate-batches --verify` to confirm zero drift. See [docs/migration-guide-batch-intake.md](../docs/migration-guide-batch-intake.md) for the full operator runbook.

## Before tagging

Per [docs/release-runbook.md](release-runbook.md#m6-migration--retirement): confirm CI (unit, M1–M6 blocking, and cumulative integration suites) passes with zero new failures, confirm `DB_VERSION` is `10` and the schema assertion is `ok: true` (dispatcher-routing triple-checked against the M4→M5 trap), walk through the migration dry-run → apply → verify → rollback sequence on a staging copy of production data and verify stock/average-cost/inventory-value are byte-for-byte identical before and after the full migration, confirm that a deliberately-interrupted mid-migration run resumes cleanly with zero duplicates, confirm that Batch Intake can no longer create new batches (admin hooks gone, UI tab gone), and confirm that Quick Restock / Cost Adjustment / M4's Quick Receive Without PO / M5's Receive-Against-PO remain fully functional.

## Rollback

**Read this before rolling back a site that has migrated any batches.**

**Unlike M4/M5, this release does NOT introduce a new "code rollback is unsafe" risk class.** Migrated Goods Receipts are purely additive rows a pre-M6 codebase never reads. A plugin-code rollback to 1.22.0 is safe by construction even after batches have been migrated — the older version simply never queries `source = 'migrated'` receipts, so they remain present but inert. The legacy `wc_io_purchase_batches*` tables were never modified by migration (only two new nullable columns were added, which 1.22.0 ignores), so the old version's "no batches migrated yet" assumption remains valid. **This is the strongest rollback story in the program.**

If you absolutely must roll back a site after migrating batches:
1. Code rollback (plugin version 1.23.0 → 1.22.0) is safe and sufficient — migrated receipts remain in the database, inert and invisible to 1.22.0 code.
2. A full restore (code + schema rollback, dropping the two new columns) is optional and never required for data safety — schema rollback is only needed if you want to reclaim disk space on a very large database. See [docs/rollback-plan.md](rollback-plan.md) for the full explanation.
3. Use the CLI's `--rollback=<id>` mode (on v1.23.0) to undo a specific batch's migration before rolling back code, if you discover a migration mistake.

Changelog: [CHANGELOG.md](../CHANGELOG.md)
