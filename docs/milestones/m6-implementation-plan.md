# Milestone M6 Implementation Plan — Migration & Retirement

**Materialization target:** `docs/milestones/m6-implementation-plan.md`. Target release: plugin **v1.23.0**, `DB_VERSION` **10** (from 9). Roadmap ordering unchanged — M6 precedes M7 exactly as `CLAUDE.md`'s frozen status table already states.

---

## Context

M4 introduced Goods Receipts as the sole live stock/cost mutator (D3/INV-2); M5 connected them to Purchase Orders. Since then, two receiving mechanisms have coexisted: the new Goods Receipt engine, and the original **Batch Intake** feature it was designed to replace — still fully live today, still able to create new batches, still writing to its own `wc_io_purchase_batches*` tables with its own hand-rolled (non-transactional) apply/rollback logic and its own regex-based movement linkage (`Batch ID: (\d+)` parsed out of a movement note string).

Architecture v1.0 §1 committed to closing this gap explicitly: *"Migration is additive: historical applied batches become direct posted Goods Receipts (never synthetic POs); old tables are frozen, not destroyed; the note-regex movement linkage is replaced by typed references."* (D14). M4's own plan left a named remediation trigger for this moment: `Goods_Receipt_Costing`'s landed-cost formula was deliberately *duplicated* from `Batch_Intake_Service` rather than shared, "because the latter is `protected`/internal to a feature slated for M6 removal... when M6 retires Batch Intake, extract the shared formula into one class." And M5's own plan left a binding constraint addressed directly to this milestone: *"M6 must never infer or reconstruct `qty_received` for any PO line from historical Batch Intake data... never fabricating a retroactive PO↔receipt linkage that never existed."*

M6 is the milestone that finally does this work. It is deliberately **not** a feature milestone — it introduces no new purchasing capability, extends no domain concept, and adds no customer-facing behavior. Its entire job is to consolidate the plugin onto one receiving history (Goods Receipts) before M7 exposes that history's derived data (via M3's Inventory Position) to the storefront, and to retire the code path that Goods Receipts has already functionally superseded since M4. Getting this right matters disproportionately to its feature size: it is the one milestone in this plugin's history that writes new rows into an audit-sensitive table at volume, from data that was never designed to be migrated, without a live user watching each write happen.

---

## Objectives

1. Migrate every existing Batch Intake record into the Goods Receipt model, as a **historical fact being recorded**, not a **receiving event being replayed** — current WooCommerce stock and average cost must be numerically identical before and after migration, for every affected product.
2. Replace the batch↔movement regex linkage with the typed `reference_type`/`reference_id` columns M4 already added to `wc_io_inventory_movements`, exactly as Architecture v1.0 §1 promised.
3. Retire Batch Intake's ability to create new batches — the one thing it can still do that Goods Receipts (M4/M5) doesn't already do better — while leaving the underlying legacy tables frozen and readable, per D14.
4. Extract the landed-cost type vocabulary (`allowed_cost_types()`/`landed_cost_type_labels()`) out of `Batch_Intake_Service` into a small, neutral, shared class, closing M4's own flagged remediation trigger.
5. Ship a safe, operator-controlled, idempotent, resumable migration tool — not an automatic upgrade-time side effect — with dry-run, verification, single-batch targeting, and rollback modes.
6. Leave the plugin with exactly one receiving mechanism going forward, with no functional or architectural loose ends for M7 to inherit.

---

## Required analysis — findings

Verified in source before drafting the rest of this plan:

1. **Which parts of Batch Intake remain active?** All of it. `admin_post_wc_io_batch_apply` (→ `handle_batch_apply_post()`) and `wp_ajax_wc_io_batch_preview` are both live hooks (`includes/class-wc-inventory-overview-plugin.php:64,73`), still able to create new batches today, four milestones after Goods Receipts shipped.
2. **Which parts have already been superseded by Goods Receipt?** Functionally, all of it — receiving, landed-cost allocation, weighted-average posting, and movement recording are all done better by `Goods_Receipt_Service` (transactional, idempotent, PO-aware). Nothing Batch Intake does is not already done by the Goods Receipt engine.
3. **Which parts must remain permanently for historical reasons?** The three legacy tables (`wc_io_purchase_batches`, `wc_io_purchase_batch_lines`, `wc_io_purchase_batch_costs`) themselves — frozen, never dropped, per D14. They are the durable source-of-truth for the migration and the permanent audit trail behind it.
4. **Which parts should be migrated?** Every row currently in `wc_io_purchase_batches` — see §Migration model for why "every row" is a verified fact, not an assumption.
5. **Which parts should simply be retired?** The create/apply/preview code path: `Batch_Intake_Service::apply_batch_from_post()`, `rollback_batch_apply()`, `build_movement_note_for_line()`, `build_preview_from_post()`, `render_preview_markup()`, the two admin hooks, and `Batch_Intake_UI`'s create screens.
6. **Automatic, CLI-only, admin-driven, or optional migration?** CLI-only, operator-initiated, dry-run by default — see §Migration model for the justification.
7. **How should existing customers safely upgrade?** Schema (tracking columns) upgrades automatically like every prior `DB_VERSION` bump; data migration is a deliberate, separate, documented operator action taken after upgrading — never bundled into the upgrade itself.
8. **Is reconciliation tooling still required after migration?** Yes, permanently — folded into the same CLI command as a `--verify` mode, mirroring M5's `reconcile-qty-received` precedent exactly.
9. **Do legacy APIs/classes need deprecation phases?** Yes — see §Retirement strategy for why immediate deletion was rejected in favor of disable-in-M6/delete-in-M8.
10. **Does M7 depend on any M6 output beyond version sequencing?** No — verified explicitly in §Why M6 must precede M7.

---

## Milestone boundaries

**Hard prohibitions — must not be implemented in M6:**

- Any mutation of current WooCommerce stock (`set_stock_quantity()`) or current `_wc_io_average_unit_cost`/`_wc_io_inventory_value` product meta, by any migration code path, under any circumstances (§Migration model — this is the single most important prohibition in this entire plan)
- Fabricating a PO↔receipt linkage for migrated data (`po_line_id` stays `NULL` on every migrated receipt line; `source` is never `'po'`/`'mixed'` for migrated rows) — binding per M5's own note and D7
- Inferring, backfilling, or reconstructing `qty_received` on any Purchase Order line from Batch Intake data — binding per M5's own note
- Fuzzy-matching a batch's free-text `supplier_name` to a `wc_io_suppliers` row and backfilling `supplier_id` — an invented relationship the original record never had (§Migration model)
- Dropping, truncating, or otherwise destroying `wc_io_purchase_batches`/`wc_io_purchase_batch_lines`/`wc_io_purchase_batch_costs` — frozen per D14, permanently, not just through M6
- Any new purchasing feature, reporting, dashboard, analytics, forecasting, storefront work, supplier enhancement, warehouse improvement, barcode/ASN support, or mobile workflow (all explicitly out of scope per the task brief)
- Automatic migration as a side effect of the `DB_VERSION` upgrade routine (§Migration model)
- New REST endpoints (D16, unchanged discipline)

**Hard prohibitions — must not be modified:**

- `Goods_Receipt_Service::post()`, `PO_Receiving_Sync`, `Inventory_Position_Resolver`/`Service`, `PO_Delay`/`PO_Quantities`/`PO_Expected`/`PO_Confidence` — M6 is a new, narrow write path (migration) plus a narrow guard addition to `void()`; it does not touch the receiving engine's mutation logic
- `Restock_Service::apply_purchase_line_change()`/`apply_purchase_line_reversal()` — migration never calls either
- Quick Restock, Cost Adjustment — verified independent of `Batch_Intake_Service` (grep-confirmed: only `batch-intake-service.php`, `batch-intake-ui.php`, `plugin.php`'s hook registrations, and `goods-receipt-costing.php` reference it); both must be unaffected by Batch Intake's retirement

---

## Data ownership

| Milestone | Owns |
|---|---|
| M1–M5 | Suppliers, Purchase Orders, Inventory Position, Goods Receipts, PO Receiving — all frozen, all unmodified except the one narrow `void()` guard addition below. |
| **M6 (this plan)** | **Migration and retirement only.** Owns exactly two new things: (1) the batch→receipt migration mapping (two new nullable columns on `wc_io_purchase_batches`), and (2) the decision of which legacy code paths are reachable going forward. Owns zero new business capabilities — `source = 'migrated'` is a new *value*, not a new capability; every fact a migrated receipt carries was already decided by whoever ran the original batch, and M6 only re-expresses it in the current schema. |

---

## Architecture

### Migration model

**Central decision: migration is record materialization, not receiving.** A migrated Goods Receipt is a *historical fact being written down in today's schema*, not *a new receiving event being processed today*. This distinction drives every other decision in this section, and it is worth stating why it's correct rather than asserting it:

When a batch was originally applied (however long ago), `Batch_Intake_Service::apply_batch_from_post()` already did the one thing that matters — it called `set_stock_quantity()` and wrote the weighted-average cost meta, once, at that moment. That mutation is **already fully reflected in current WooCommerce stock and cost**, because nothing since has reversed it. If migration re-mutated stock/cost today — by calling `Goods_Receipt_Service::post()`, or by re-deriving a delta and applying it — it would apply that same historical change a **second time**, corrupting every affected product's current stock and cost. This is not a hypothetical risk; it is the default behavior of every existing write path in this codebase, all of which are designed for *live* receiving, not *historical replay*. Migration must therefore be a dedicated, minimal write path — direct, transactional INSERTs into the new schema shape, using the batch's own already-stored before/after snapshot columns — that never calls `set_stock_quantity()`, never touches `_wc_io_average_unit_cost`/`_wc_io_inventory_value` meta, and never goes through `Goods_Receipt_Service`, `Restock_Service`, or any other live mutation path.

**"Every row is eligible" is a verified fact, not an assumption.** `CLAUDE.md` §2 confirms Batch Intake "has no lifecycle — no status column, no draft, no edit/void." Apply is atomic today: it either fully writes batch header/lines/costs and mutates stock, or `rollback_batch_apply()` undoes a partial write on failure. A surviving row in `wc_io_purchase_batches` is therefore, by construction, a batch that was successfully and completely applied. No filtering, no status check, no "only migrate applied batches" logic is needed — every existing row qualifies.

**Field-by-field mapping** (§Domain model below) is mechanical wherever the schemas already align (`wc_io_purchase_batches` and `wc_io_goods_receipts` share the large majority of their columns by name and meaning, since Goods Receipt's schema was built by repositioning Batch Intake's own fields per D3). Three places require an explicit, justified decision rather than a straight copy:

1. **Unit cost derivation.** `wc_io_purchase_batch_lines` stores `entered_line_cost`/`converted_line_cost_eur` (line totals); `wc_io_receipt_lines` stores `entered_unit_cost`/`converted_unit_cost_eur` (per-unit). Migration divides by `quantity` (batch quantities are always positive by existing Batch Intake validation, so no zero-division guard is a business decision — it's simply asserting an invariant that already holds). This is a unit-of-measure conversion, not a business-logic decision.
2. **`sku_snapshot`/`name_snapshot`.** `wc_io_purchase_batch_lines` never captured these (a gap in the old schema — it only stored `product_id`/`variation_id`). Migration derives them best-effort from the product's **current** state at migration time if it still exists (`wc_get_product()`), or leaves them `NULL` with a note in the migration report if the product has since been deleted. This is explicitly best-effort/non-authoritative and documented as such — it was never captured historically, so nothing migration does can make it authoritative.
3. **Supplier linkage.** `wc_io_purchase_batches.supplier_name` is free text (M1's supplier autocomplete on Batch Intake was additive, never a hard FK — confirmed in `CLAUDE.md` §2). Migrated receipts get `supplier_id = NULL`, `supplier_name_snapshot = ` the original free text, verbatim. Fuzzy-matching free text to a `wc_io_suppliers.id` would fabricate a relationship the original record never asserted — explicitly prohibited (§Milestone boundaries), for the same reason D7/the M5 binding note prohibit fabricating PO linkage.

**`source = 'migrated'`.** `wc_io_goods_receipts.source` already reserves `'po'`/`'mixed'` (unwritten until M5) alongside the M4-era default `'direct'`. M6 adds a fourth value, `'migrated'`, distinguishing this milestone's historical-replay rows from any live receipt in every report, audit, and the `void()` guard below. No schema change is needed for this — it's a new string value in an existing `varchar(20)` column — only new PHP constants (`WC_Inventory_Overview_Goods_Receipts::SOURCE_DIRECT`/`SOURCE_PO`/`SOURCE_MIXED`/`SOURCE_MIGRATED`) alongside the definition site of the existing source-string literals, and updating every place that switches on `source` to handle the new value explicitly (never falling through to a default that assumes a live receipt).

**Receipt numbering: new numbers, in the original year.** `WC_Inventory_Overview_Goods_Receipt_Numbering::allocate( $year = null )` already accepts an explicit year — no code change needed here. Migration allocates each migrated receipt's number using the **batch's original `created_at` year**, not the migration's own year. This produces historically faithful numbers (e.g. `GR-2025-0001` for a 2025 batch, migrated in 2026) and is safe precisely because the `GR-{YYYY}-{NNNN}` scheme didn't exist before M4 (v1.21.0) — no real receipt could ever have been issued for any year before M6's own migration run, so there is no collision risk in "back-dating" the sequence.

**Timestamps preserved, migration time recorded separately.** `created_at`, `posted_at`, and `updated_at` on the migrated receipt are all set to the batch's original `created_at`; `posted_by`/`created_by`/`updated_by` are set to the batch's original `user_id`. The migration's own execution time lives on the *tracking* column (`wc_io_purchase_batches.migrated_at`, §Domain model), not on the receipt — this keeps "when did this receiving event happen" (the receipt) and "when was this record materialized" (the tracking column) as two honestly distinct facts instead of conflating them.

**Provenance without new schema.** `wc_io_goods_receipts.reference` (an existing free-text column, already used for general PO/reference text) is set to `sprintf( 'Migrated from legacy Batch #%d', $batch_id )` on every migrated receipt — giving a human-readable, UI-visible trail back to the source batch without any new column on the receipt table itself. The reverse direction (batch → receipt) is the tracking column.

**Movement linkage becomes typed, in place.** Every existing `wc_io_inventory_movements` row with `movement_type = 'purchase_batch'` belonging to a given batch (identified precisely via the same "Batch ID: {id}" first note-line convention `build_movement_note_for_line()` already writes and `movements-list-table.php`'s regex already reads) gets exactly one `UPDATE`, setting `reference_type` and `reference_id = <new receipt id>` — never a new row (that would double the ledger), never touching any other column (the note text, quantities, and everything else remain the original historical record, untouched). The `reference_type` value used is the **existing** `WC_Inventory_Overview_Movements::REFERENCE_TYPE_GOODS_RECEIPT` constant (`'goods_receipt'`) — the same value M4's own `insert_goods_receipt()`/`insert_goods_receipt_void()` already write — not a new spelling, so every receipt-referencing movement (live or migrated) shares one uniform `reference_type` vocabulary. This requires one new narrow method, `WC_Inventory_Overview_Movements::backfill_reference( int $movement_id, string $reference_type, int $reference_id ): bool`, used exclusively by the migration service — the same "one narrow, purpose-built write method" discipline `increment_qty_received()` established in M5. The pre-existing regex fallback in `movements-list-table.php` (`preg_match('/^Batch ID:\s*(\d+)\s*$/', ...)`) is left in place, untouched — it becomes permanently dead code the moment every `purchase_batch` movement has a typed reference, and removing it is a zero-value, non-zero-risk change this plan explicitly declines to make (nothing reads it once references are populated; deleting it buys nothing).

**Migration tracking: two columns, not a new table.** `wc_io_purchase_batches` gains `migrated_receipt_id bigint(20) unsigned NULL` (indexed) and `migrated_at datetime NULL`. A separate mapping table was considered and rejected: the relationship is strictly 1:1, the batches table is small and finite (no new rows are possible once Batch Intake's apply path is retired in this same milestone), and a column-based typed reference is the established pattern this codebase already uses everywhere else (Movement's `reference_type`/`reference_id`, Receipt Line's `po_line_id`) rather than an options-array indirection. This is the smallest additive schema change that satisfies "idempotent, resumable" — `WHERE migrated_receipt_id IS NULL` is both the query for "what's left to migrate" and the guard against double-migration.

**Migration invariants.** Two properties this design deliberately guarantees, elevated from prose to explicit invariants because both are load-bearing for the operational guidance (§Testing, §Deployment) that depends on them:

- **Invariant M6-1 — one transaction per batch, never one transaction for a run.** The shape is always:
  ```
  foreach batch:
      begin transaction
          migrate this one batch (receipt + lines + costs + movement backfill + tracking columns)
      commit
  ```
  never a single transaction wrapping the whole run. A CLI invocation over a customer's entire multi-year batch history must not hold one long-lived transaction — every batch's migration is independently atomic and independently committed. This is what makes resumability, `--limit`, `--batch=<id>`, low lock duration, and clean interruption-recovery (§Testing — partial/interrupted migration) sound properties of the design rather than incidental behavior of whatever the implementation happens to do.
- **Invariant M6-2 — order independence.** Batches may be migrated in any order — ascending id, descending id, arbitrary `--batch=<id>` selection, a `--limit`-truncated subset resumed later — and the result is identical regardless of order. This holds because migration never reads current stock, current cost, or any other batch's rows to do its work (§Migration model's central prohibition, restated here as a direct corollary): each batch's migration is a pure function of that batch's own already-stored rows. Nothing about this design would need to change if a future operator ran migrations out of order, in parallel-safe (though not parallel-executed) chunks, or resumed a run interrupted for days.

**Rollback of a migration is not a receipt void.** Voiding a Goods Receipt through `Goods_Receipt_Service::void()` reverses stock/cost **relative to current state** (`apply_purchase_line_reversal()`, M4) — correct for a live receipt, wrong for a migrated one, for exactly the same reason forward-migration must not call `post()`: the "reversal" would mutate *today's* stock for a change that happened, and was never meant to be undone, at some point in the past. `Goods_Receipt_Service::void()` therefore gains one new guard, placed immediately after the existing status check (`includes/class-wc-inventory-overview-goods-receipt-service.php`, right after the `STATUS_POSTED` check in `void()`): if `$receipt['source'] === WC_Inventory_Overview_Goods_Receipts::SOURCE_MIGRATED`, return a `WP_Error` — *"Migrated historical receipts cannot be voided; use the migration CLI's `--rollback` mode instead."* Reversing a migrated record is instead a dedicated CLI mode (§Implementation work packages, WP-M6-3/4) that deletes the specific receipt/lines/costs rows, reverts the movement rows' `reference_type`/`reference_id` back to `NULL`, and clears the batch's `migrated_receipt_id`/`migrated_at` — a true undo of the *migration action*, never touching current stock either, symmetric with forward migration.

### Retirement strategy

Four categories, per the task's own framing, each with its rationale:

| Status | What | Why |
|---|---|---|
| **Removed (in M6)** | The two admin entry points: `admin_post_wc_io_batch_apply` → `handle_batch_apply_post()`, and `wp_ajax_wc_io_batch_preview`. No new batch can be created after M6 ships. | This is the actual retirement — Batch Intake's write path is the one thing Goods Receipts hasn't already superseded since M4; removing it is the entire point of "retirement" in this milestone's name. |
| **Disabled, not deleted (in M6)** | `Batch_Intake_Service::apply_batch_from_post()`, `rollback_batch_apply()`, `build_movement_note_for_line()`, `build_preview_from_post()`, `render_preview_markup()`, and `Batch_Intake_UI`'s create/apply screens — code retained, unreachable, explicitly marked `@deprecated` in a doc comment naming this plan and M8 as the removal point. | The task's own retirement philosophy prefers staged retirement when immediate deletion isn't clearly safer — and here it isn't: M6 is already the highest-risk milestone to date (first at-volume historical migration). Pairing that with permanently deleting the code that produced the source data, in the same release, removes a low-cost safety margin (re-reading the exact original apply logic during a migration audit) for no benefit. M8 ("Hardening & GA") is the already-roadmapped, natural point to physically delete now-provably-dead code after a full release cycle with zero incidents. |
| **Extracted, not deleted (in M6)** | `allowed_cost_types()`/`landed_cost_type_labels()` — moved out of `Batch_Intake_Service` into a new, small, neutral class (`WC_Inventory_Overview_Landed_Cost_Types`); `Goods_Receipt_Costing`'s two pass-through wrapper methods are repointed to call the new class instead of `Batch_Intake_Service` directly. | Closes M4's own named remediation trigger: *"when M6 retires Batch Intake, extract the shared formula into one class both `Goods_Receipt_Costing` (still needed) and nothing else depend on."* `Goods_Receipt_Costing` cannot keep cross-referencing a class whose write path is being disabled — that cross-reference is exactly the "hidden coupling" M4's own plan flagged as accepted, tracked debt with an explicit trigger condition. This milestone is that trigger. |
| **Frozen forever (never deleted, any milestone)** | `wc_io_purchase_batches`, `wc_io_purchase_batch_lines`, `wc_io_purchase_batch_costs` — the tables themselves. | D14, verbatim: "old tables are frozen, not destroyed." They are the permanent audit trail behind every migrated receipt and the input to the `--verify`/`--rollback` CLI modes forever. No future milestone plan in this roadmap proposes dropping them, and this plan doesn't either. |

**Quick Restock and Cost Adjustment are unaffected** — verified independent of `Batch_Intake_Service` by direct grep; this plan's Definition of Done includes an explicit regression check that both remain fully functional and untouched, matching every prior milestone's "milestone leakage" scope-boundary discipline.

---

## Domain model

### Schema change (`DB_VERSION` 9 → 10)

**Stated explicitly, because it's a fair question for a future reader to ask: `DB_VERSION` 10 exists solely to carry migration bookkeeping, not a new business-domain schema.** No new business fact becomes representable that wasn't already representable in v9's Goods Receipt schema (§Field mapping below confirms the migrated data fits M4's existing shape exactly) — the version bump exists only so these two tracking columns pass through the same `assert_schema_shape()`/`expected_schema_vN()` discipline every prior schema change has used. The alternative — creating the columns lazily on first migration-CLI invocation, keeping `DB_VERSION` at 9 — was considered and rejected: it would carve out a one-off exception to a verification path that has been uniform across five milestones (a schema-shape assertion that's sometimes authoritative and sometimes not is a worse property than "one version bump whose entry explicitly says why it's small"). `DB_VERSION` 10's changelog/architecture-audit entry must carry this same sentence, so nobody has to re-derive it from the diff.

One additive `ALTER TABLE`, in the same style M5 used for `qty_received`:

```sql
ALTER TABLE {wc_io_purchase_batches}
  ADD COLUMN migrated_receipt_id bigint(20) unsigned NULL,
  ADD COLUMN migrated_at datetime NULL,
  ADD KEY migrated_receipt_id (migrated_receipt_id);
```

`expected_schema_v10()` extends `expected_schema_v9()`'s column list for `wc_io_purchase_batches` to include the two new columns; no `forbidden_columns` entry is needed (unlike `qty_received`'s multi-milestone reservation, there is no prior placeholder being "unlocked" here — this is a single, self-contained additive change). No other table changes. No column on `wc_io_goods_receipts`/`wc_io_receipt_lines`/`wc_io_receipt_costs` — the migrated data fits the existing M4 schema shape exactly, which is itself confirmation that D3's "repositioned, not discarded" framing was correct.

### Field mapping (batch → receipt, mechanical unless noted)

| `wc_io_purchase_batches` | → | `wc_io_goods_receipts` | Note |
|---|---|---|---|
| `id` | → | *(not copied — becomes `reference` text + `migrated_receipt_id` tracking column, both directions covered without a new column)* | |
| — | → | `receipt_number` | New allocation, batch's original year (see above) |
| — | → | `status` | Always `'posted'` |
| — | → | `source` | Always `'migrated'` (new constant) |
| — | → | `supplier_id` | Always `NULL` (never inferred) |
| `supplier_name` | → | `supplier_name_snapshot` | Verbatim |
| `purchase_currency` | → | `currency` | Verbatim |
| `exchange_rate_to_eur` | → | `exchange_rate_to_eur` | Verbatim |
| `exchange_rate_date` | → | `exchange_rate_date` | Verbatim |
| `product_subtotal_entered` | → | `product_subtotal_entered` | Verbatim |
| `landed_total_entered` | → | `landed_total_entered` | Verbatim |
| `batch_total_entered` | → | `receipt_total_entered` | Verbatim |
| `product_subtotal` | → | `product_subtotal` | Verbatim |
| `landed_total` | → | `landed_total` | Verbatim |
| `batch_total` | → | `receipt_total` | Verbatim |
| — | → | `reference` | `"Migrated from legacy Batch #{id}"` |
| `note` | → | `note` | Verbatim |
| `created_at` | → | `posted_at`, `created_at`, `updated_at` | All three, same value (see above) |
| `user_id` | → | `posted_by`, `created_by`, `updated_by` | All three, same value |

`wc_io_purchase_batch_lines` → `wc_io_receipt_lines`: every column maps by name 1:1 except `entered_line_cost`/`converted_line_cost_eur` → `entered_unit_cost`/`converted_unit_cost_eur` (divide by `quantity`), `po_line_id` (always `NULL`), and `sku_snapshot`/`name_snapshot` (best-effort derivation, see above). `line_index` is assigned in the original lines' stored order (batch lines have no explicit index column — order is preserved via `id ASC`, the same implicit ordering the original Apply used).

`wc_io_purchase_batch_costs` → `wc_io_receipt_costs`: every column maps by name 1:1; `post_hoc` is always `0` (these were the original costs entered at the batch's own creation time, not added afterward).

---

## Implementation work packages

- **WP-M6-1 — Schema.** `expected_schema_v10()`, `ALTER TABLE` for the two tracking columns, `DB_VERSION = '10'`. No data migration in this package — schema-only, ships with the normal plugin upgrade like every prior `DB_VERSION` bump.
- **WP-M6-2 — `WC_Inventory_Overview_Landed_Cost_Types`.** New class housing `allowed_cost_types()`/`landed_cost_type_labels()`, extracted verbatim from `Batch_Intake_Service`. `Goods_Receipt_Costing`'s two wrapper methods repointed to it. Characterization test asserting identical output before/after the extraction (nothing about the UI should change).
- **WP-M6-3 — `WC_Inventory_Overview_Batch_Migration_Service`.** The migration engine itself: `migrate_batch( int $batch_id ): array|WP_Error` (one batch, one `DB_Transaction::run()`), `verify_batch( int $batch_id ): array` (read-only drift check), `rollback_batch( int $batch_id ): true|WP_Error` (undo, per §Migration model). Depends on `WC_Inventory_Overview_DB_Transaction` (reused, same `run()`/`throw_if_error()` pattern as M4/M5), `Goods_Receipt_Numbering::allocate( $year )`, and the new `Movements::backfill_reference()`. Never touches `set_stock_quantity()`, product meta, `Goods_Receipt_Service`, or `Restock_Service` — enforced by an architecture guard (§Testing).
- **WP-M6-4 — `WC_Inventory_Overview_Migrate_Batches_CLI_Command`.** `wp wc-io migrate-batches [--apply] [--verify] [--batch=<id>] [--rollback=<id>] [--limit=<n>]`, modeled directly on `Reconcile_CLI_Command`'s dry-run-by-default shape: no `--apply` flag means read-only preview (what *would* be migrated), matching the reconcile tool's own "strictly read-only unless told otherwise" default. `--verify` runs `verify_batch()` across every already-migrated batch (or one, via `--batch`) and reports drift with no writes, ever — this is the permanent reconciliation tooling (§Required analysis, point 8). `--rollback=<id>` invokes `rollback_batch()` for exactly one batch, with an explicit confirmation prompt.
- **WP-M6-5 — `Goods_Receipt_Service::void()` guard.** The one-guard addition described in §Migration model, immediately after the existing `STATUS_POSTED` check.
- **WP-M6-6 — Retirement.** Remove `admin_post_wc_io_batch_apply`/`wp_ajax_wc_io_batch_preview` hook registrations and the Batch Intake tab from the Restock/Cost Adjustment tab group; mark the now-unreachable `Batch_Intake_Service`/`Batch_Intake_UI` methods `@deprecated` (naming this plan and M8); regression-verify Quick Restock and Cost Adjustment are untouched.
- **WP-M6-7** — Testing and documentation (§Testing, §Documentation).

WP-M6-1 and WP-M6-2 can be built and reviewed independently and in parallel (schema and cost-type extraction don't depend on each other). WP-M6-3/4/5 must follow WP-M6-1 (need the tracking columns) and WP-M6-2 (nothing depends on it functionally, but sequencing it first avoids two things touching `Goods_Receipt_Costing`-adjacent code in overlapping commits). WP-M6-6 must be last — retiring the create/apply path before the migration service exists would strand any batch created between deploy and first migration run with no historical Goods Receipt counterpart yet (a `--verify` gap, not a correctness bug, but avoidable by sequencing).

---

## Testing

- **Unit:** `Batch_Migration_Service`'s field-mapping/derivation logic (unit-cost division, `source`/`po_line_id` always correct, provenance-string formatting, timestamp copying) — pure-function-style tests against fixture batch rows, no DB required for the mapping logic itself.
- **Architecture guard:** source-scan/reflection assertion that `Batch_Migration_Service` contains no call to `set_stock_quantity()`, no write to `_wc_io_average_unit_cost`/`_wc_io_inventory_value` meta, and no call into `Goods_Receipt_Service::post()`/`Restock_Service`'s mutation methods — the single most important guard in this milestone, directly enforcing §Migration model's central prohibition.
- **Integration — golden/historical-integrity test (headline test of this milestone):** for a representative fixture of batches (simple products, variations, multiple currencies, multiple landed-cost types), assert every affected product's `_stock`, `_wc_io_average_unit_cost`, and `_wc_io_inventory_value` are **byte-for-byte identical** immediately before and immediately after a full migration run.
- **Integration — mapping correctness:** every migrated receipt/line/cost field matches the mapping table above; `receipt_number` allocated in the batch's original year; `reference` provenance string correct; `po_line_id` always `NULL`; `source` always `'migrated'`; `supplier_id` always `NULL`.
- **Integration — movement backfill:** every `purchase_batch` movement row gets exactly one `reference_type`/`reference_id` update, no new movement rows inserted, no other movement column changed.
- **Integration — idempotency/resumability:** running migration twice with no new batches is a no-op (zero writes on the second run); a batch already carrying `migrated_receipt_id` is never re-processed.
- **Integration — partial/interrupted migration:** force a mid-transaction failure on batch N and assert (a) batch N's transaction fully rolls back — zero partial receipt/line/cost rows, `migrated_receipt_id` still `NULL`, movement rows for that batch still untouched — and (b) batches migrated before N in the same CLI run remain fully committed (per-batch transactions, not one transaction for the whole run — the resumability requirement's actual implementation, verified here).
- **Integration — ordering invariant:** the same independent batch fixture, migrated in different orders (ascending id, descending id, arbitrary `--batch=<id>` subset), produces equivalent per-batch Goods Receipt representations and leaves stock/cost identical regardless of order (Invariant M6-2, verified behaviorally).
- **Integration — rollback:** `--rollback=<id>` deletes exactly the migrated receipt/lines/costs rows for that batch, reverts its movement rows' `reference_type`/`reference_id` to `NULL`, clears `migrated_receipt_id`/`migrated_at`; asserts current stock/cost meta unchanged by the rollback (symmetric with the forward-migration golden test).
- **Integration — void guard:** attempting `Goods_Receipt_Service::void()` on a `source = 'migrated'` receipt returns the documented `WP_Error` and performs zero mutation; voiding a normal `'direct'`/`'po'`/`'mixed'` receipt is unaffected.
- **Integration — CLI:** dry-run (no `--apply`) performs zero writes and reports accurately; `--verify` detects a deliberately-introduced drift (e.g. a hand-edited receipt total) and reports it without repairing; `--batch=<id>` targets exactly one batch; `--limit=<n>` caps a run; `--rollback=<id>` undoes one migration.
- **Integration — retirement regression:** `admin_post_wc_io_batch_apply`/`wp_ajax_wc_io_batch_preview` no longer register; Batch Intake tab absent from the admin UI; Quick Restock and Cost Adjustment fully functional, unmodified, unaffected.
- **Integration — cost-type extraction:** `Goods_Receipt_Costing::allowed_cost_types()`/`landed_cost_type_labels()` return identical output before and after WP-M6-2 (characterization-style — the extraction must be behavior-preserving).
- **Performance:** 200+ synthetic batches (mixed simple/variation, mixed currencies); assert bounded, non-N+1 query behavior per batch during a full migration run.
- **Fresh install:** zero batches → migration CLI reports "0 batches found, nothing to migrate," schema-shape assertion for v10 passes, no errors.
- **Upgrade path:** existing v9 install with real batch data → schema ALTER applies cleanly, `wc_io_purchase_batches` gains the two new (all-`NULL`) columns, no data loss, migration CLI then run separately.
- `tests/docker/run-phpunit.sh`'s blocking filter gains the `Test_WC_IO_Batch_Migration_`/`Test_WC_IO_Landed_Cost_Types_` prefix families, alongside the existing M1–M5 prefixes.

---

## Quality gates

At minimum, executed and individually classified (PASS / FAIL / PASS WITH KNOWN PRE-EXISTING FAILURES / CONFIGURED — NOT EXECUTED / NOT APPLICABLE):

- PHP syntax lint; Composer validation; Docker Compose config
- Unit suite; M1–M6-focused blocking suite; cumulative integration suite (existing legacy failures individually classified, never hidden)
- Batch Migration tests in isolation
- PHPCS; actionlint if workflow/config files changed
- Schema verification confirming `DB_VERSION` 10 and the two new `wc_io_purchase_batches` columns
- **Historical-integrity guard** (the golden stock/cost-unchanged test) — a release blocker on its own, distinct from the general suite, given what's at stake
- Architecture guard (no live-mutation call in `Batch_Migration_Service`)
- Retirement regression suite (admin hooks gone, Quick Restock/Cost Adjustment untouched)
- Release ZIP build and inspection; git diff review against the pre-M6 tag; working-tree verification

Any new test failure introduced by this milestone is a release blocker.

---

## Documentation

1. `docs/milestones/m6-implementation-plan.md` (this document)
2. `CLAUDE.md` milestone status table — updated only after implementation is complete
3. `docs/checklists/validation-checklist.md` — new approved M6 subsection
4. `docs/testing.md` — new test directories and focused-suite coverage
5. `CHANGELOG.md` — v1.23.0 entry, explicitly calling out the historical-integrity guarantee (stock/cost unchanged) as the headline claim
6. `readme.txt` and all repository version references, updated consistently
7. `docs/architecture-audit.md` — migration model, retirement staging (disabled-in-M6/removed-in-M8), the `Landed_Cost_Types` extraction, the `void()` guard, no-live-mutation guarantee
8. New `docs/migration-guide-batch-intake.md` — **operator-facing** runbook: pre-migration DB backup instructions, dry-run walkthrough, `--apply` walkthrough, `--verify` walkthrough, `--rollback` walkthrough, and what "successful migration" looks like (§Definition of Done's completion criteria, restated for an operator audience)
9. `docs/rollback-plan.md` — new M6 section, distinct from the CLI's own `--rollback` mode: how to fully revert the *plugin version* (not a single batch) if v1.23.0 needs to be rolled back to v1.22.0 after some batches have already been migrated (answer: safe — migrated Goods Receipts are additive rows the v1.22.0 code simply never reads; the v1.22.0 codebase has no code path that queries `source = 'migrated'` receipts, so a version rollback leaves them present but inert, and Batch Intake's tables/data were never touched by migration either)

---

## Deployment

1. Deploy v1.23.0 normally — the `DB_VERSION` 9→10 `ALTER` runs automatically, exactly like every prior schema bump. This step alone migrates **zero data** and changes **zero visible behavior** (Batch Intake is still reachable at this point — WP-M6-6's retirement ships in the same release, but sequenced after the tracking columns exist, per §Implementation work packages).
2. Operator takes a database backup (standard practice, called out explicitly in the migration guide, not automated by this plugin).
3. Operator runs `wp wc-io migrate-batches` (no `--apply`) to preview the full migration — read-only, shows exactly what will be created.
4. Operator runs `wp wc-io migrate-batches --apply`.
5. Operator runs `wp wc-io migrate-batches --verify` to confirm zero drift.
6. Batch Intake's create/apply entry points are already retired as of this same release — no further action needed; the admin UI reflects this from the moment v1.23.0 is deployed.

---

## Rollback

Two distinct meanings, both covered:

- **Rolling back one migrated batch** (a mistake in that specific migration, discovered after the fact): `wp wc-io migrate-batches --rollback=<batch_id>` (§Migration model, §Implementation work packages WP-M6-4).
- **Rolling back the whole v1.23.0 release** (reverting to v1.22.0 after deployment): safe by construction — see §Documentation, point 9. Migrated Goods Receipts are purely additive rows a v1.22.0 codebase never queries (no v1.22.0 code path filters or joins on `source = 'migrated'`); the legacy batch tables were never modified by migration (only two new nullable columns were added, which v1.22.0 simply ignores); reverting code without reverting the schema leaves the database in a strict superset of what v1.22.0 expects. A full schema rollback (dropping the two new columns) is optional and never required for a safe code rollback.

---

## Why M6 must precede M7

Stated honestly rather than assumed: **the primary reason is roadmap/version sequencing**, not a hard technical dependency. M7's storefront feature reads Inventory Position (M3), which aggregates **open Purchase Order lines only** — it never reads Goods Receipts or Batch Intake data directly, migrated or not. Verified: nothing in M3's resolver, service, or repository methods queries `wc_io_goods_receipts`, `wc_io_receipt_lines`, or `wc_io_purchase_batches*` at all. **M7 would function identically today, before M6, if the version numbering allowed it** — a fact this plan states plainly rather than inventing a false coupling to justify the sequencing.

The secondary, substantive reason is hygiene, not correctness: shipping M7's customer-facing storefront feature while two parallel, partially-redundant receiving histories still coexist in the admin UI is a worse state to freeze into a "final production baseline before customer-facing work" (the task brief's own framing) than consolidating first. M6 exists to make that baseline genuinely final — one receiving mechanism, one historical record, no open architectural debt — before M7 builds on top of it.

---

## Definition of Done

- [ ] `DB_VERSION` is `10`; `wc_io_purchase_batches` has `migrated_receipt_id`/`migrated_at`, both `NULL` by default; schema-shape assertion for v10 passes.
- [ ] `WC_Inventory_Overview_Landed_Cost_Types` exists; `Goods_Receipt_Costing` depends on it, not on `Batch_Intake_Service`; output identical to pre-extraction (characterization-verified).
- [ ] `Batch_Migration_Service` never calls `set_stock_quantity()`, never writes `_wc_io_average_unit_cost`/`_wc_io_inventory_value` meta, never calls `Goods_Receipt_Service::post()` or `Restock_Service`'s mutation methods (architecture-guard enforced).
- [ ] Running full migration against a representative fixture leaves every affected product's stock and cost meta byte-for-byte unchanged (golden test passes).
- [ ] Every existing `wc_io_purchase_batches` row, after `--apply`, has a non-`NULL` `migrated_receipt_id` pointing at a `status='posted'`, `source='migrated'` Goods Receipt with `po_line_id = NULL` on every line and `supplier_id = NULL`.
- [ ] Every `purchase_batch` movement row has a typed `reference_type`/`reference_id`; no new movement rows were inserted; no other movement column changed.
- [ ] `wp wc-io migrate-batches` supports dry-run-by-default, `--apply`, `--verify`, `--batch=<id>`, `--rollback=<id>`, `--limit=<n>`; `--verify` is documented as the permanent reconciliation tool for this data going forward.
- [ ] Migration is per-batch-transactional: an interrupted run leaves earlier-migrated batches committed and the failed batch fully rolled back; a rerun resumes correctly with zero duplicate writes.
- [ ] `--rollback=<id>` correctly undoes one migrated batch (receipt/lines/costs deleted, movement references cleared, tracking columns cleared) without mutating current stock/cost.
- [ ] `Goods_Receipt_Service::void()` rejects `source='migrated'` receipts with the documented error; all other receipt sources are unaffected.
- [ ] `admin_post_wc_io_batch_apply` and `wp_ajax_wc_io_batch_preview` no longer register; the Batch Intake create/apply UI is gone; Quick Restock and Cost Adjustment are verified untouched.
- [ ] Legacy `wc_io_purchase_batches*` tables are unmodified in shape (only additive) and un-dropped; no code path deletes or truncates them.
- [ ] All required unit, integration, architecture-guard, and performance tests exist and pass; M0 golden suite and existing characterization fixtures unchanged; `run-phpunit.sh` blocking filter updated.
- [ ] All required documentation deliverables complete, including the operator-facing migration guide and the version-rollback safety note.
- [ ] All quality gates executed and individually classified; every gate PASS or PASS WITH KNOWN PRE-EXISTING FAILURES; no new failure introduced.
- [ ] Version prepared as `1.23.0`; not tagged, not released, as part of plan authorship.
- [ ] Implementation branch left committed, clean, unpushed/unmerged, ready for independent audit.

---

READY FOR REVIEW
