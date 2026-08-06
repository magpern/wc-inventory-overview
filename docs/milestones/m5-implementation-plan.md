# Context

This is a **planning-only** deliverable for `/opt/biopentra/dev/wc-inventory-overview` (WordPress/WooCommerce plugin "WC Inventory Overview"), currently on `main` at **v1.21.0, DB_VERSION 8**, with M0–M4 released and merged (M4 "Receipt Engine" merged via PR #4). No repository files are modified by this task.

The user has asked for the definitive implementation plan for **Milestone M5 — Purchase Order Receiving**, following the same materialization discipline used for M1–M4: a fully-specified plan document produced and approved *before* any code is written. M5's job is to connect Purchase Orders (M2) to the already-built Goods Receipt engine (M4) — **orchestration only**. `Goods_Receipt_Service` must remain the sole stock/cost mutator (D3/INV-2); M5 adds a second responsibility to it — acting as the sole **business orchestrator** that initiates a credit to a PO line's `qty_received` as part of posting/voiding a receipt, delegating the mutation itself to a new, single-purpose owner class — without ever introducing a second mutation path.

This plan was produced after direct source verification of the current M2 (PO) and M4 (Goods Receipt) codebases — every method signature, column, and constant cited below was read from the actual files, not inferred. The single hardest open design question — **who is allowed to write `qty_received` and recompute PO status, and inside which transaction** — is resolved explicitly below (§Receiving-status ownership), mirroring how M4's plan resolved void-correctness as its one hard question.

---

# Milestone M5 Implementation Plan — Purchase Order Receiving

**Status:** Draft — freshly authored, not yet human-reviewed. Target release **v1.22.0** on a future `feature/m5-po-receiving` branch.

**Prerequisite:** v1.21.0 (M4 Receipt Engine) on schema v8.

**Architecture context:** `CLAUDE.md` Part I §1–§5, specifically **D3, D4, D5, D6, D9, D11, D12, D17, D18, D19** and **INV-1, INV-2, INV-3, INV-4, INV-5, INV-6, INV-7, INV-8**. Roadmap context: Implementation Status table, M5 row ("Receive-Against-PO, PO line completion").

---

## Authoritative specifications

Binding, frozen — nothing below may contradict these:

- Architecture v1.0 (`CLAUDE.md` Part I, §1–§5)
- Delivery Roadmap v1.0 (`CLAUDE.md` Part II — Implementation Status table)
- `CLAUDE.md` in full, as currently checked in
- Released M0 (v1.17.3), M1 (v1.18.0), M2 (v1.19.0), M3 (v1.20.0), M4 (v1.21.0)
- `docs/milestones/m1-implementation-plan.md` through `m4-implementation-plan.md`

M0–M4 are complete and frozen. This plan does not redesign or reinterpret their contracts — specifically, M4's **Implementation freeze statement** (lifecycle states, transaction semantics, mutation algorithms, movement provenance, audit behavior, posting semantics) stays frozen; M5 only *extends* `Goods_Receipt_Service` additively, exactly as M4's own "Service ownership freeze" anticipated: *"M5 extending this service (e.g. by adding a `post_against_po()` variant that still funnels through the same underlying transaction machinery) is consistent with this rule."*

---

## Objective

Produce a complete, unambiguous engineering specification so implementation can proceed with **zero further architectural decisions**. M5 must let an operator receive against one or more open Purchase Order lines — partially, fully, or in several separate receipts — while `Goods_Receipt_Service` remains the only code path that ever mutates stock or cost, and — as of M5 — the sole business orchestrator through which any `qty_received` change is ever initiated (the mutation itself is owned and performed by a new, single-purpose class, §Receiving-status ownership). PO status must reflect receiving progress automatically, correctly, and reversibly (a void must be able to walk status back down), without ever becoming a second, independent state machine that can drift from what was actually received.

---

## Scope-boundary ruling (read first)

D5 states plainly: *"over-receipt is allowed with warning and audit."* The user's directive lists "over-receipt prevention" as a primary responsibility. These are reconciled as follows, **decided, not left open**:

> **M5 does not hard-block over-receipt.** "Over-receipt prevention" is satisfied as *over-receipt protection*: the UI surfaces remaining quantity prominently, defaults the receive quantity to exactly what's outstanding, and requires an explicit, separate confirmation when the entered quantity exceeds the line's current outstanding — but the service layer never rejects `qty > outstanding` outright. Every over-receipt is captured in the PO event log with an explicit `over_receipt: true` / `qty_over` marker (§Audit model). This is the only reading consistent with D5, which this plan cannot reinterpret.

A second scope question the roadmap gloss doesn't resolve on its own: does M5 touch `wc_io_purchase_order_lines.status` (the existing per-line `varchar(20) DEFAULT 'open'` bookkeeping column, confirmed present but whose only observed values in M2's code are `'open'`)? **Decision: no.** M5 adds no new per-line status value and no new meaning to that column. "Line completion" is derived entirely from `qty_outstanding == 0` (computed, per INV-4), never stored as a line-level enum. Only the **header** `status` column gains new values (`partially_received`, `received`) — this mirrors D11/INV-3's existing pattern (position is derived; only the outcome that needs list/filter queries — header status — is persisted).

### In Scope

1. `qty_received` becomes a real, maintained column on `wc_io_purchase_order_lines` (INV-4's full formula: `qty_outstanding = GREATEST(0, qty_ordered − qty_received − qty_cancelled)`).
2. `Goods_Receipt_Service` gains the ability to accept a `po_line_id` per receipt line — populating the column M4 deliberately left present-but-unused.
3. Posting/voiding a receipt whose lines carry `po_line_id` atomically updates each PO line's `qty_received` and recomputes/persists the owning PO's header `status`, inside the **same** transaction as the stock/cost mutation.
4. New PO header statuses `partially_received`/`received`, auto-transitioned (never operator-selected) alongside the existing operator-driven `place`/`cancel`/`close_short`.
5. New PO event types recording per-line receipt-posted/receipt-voided facts and header-level status auto-transitions (closing the exact gap M4's Audit-trail decision flagged as M5's future home for INV-6's "PO event log" clause).
6. Receiving history: PO detail page shows every receipt (and the exact quantity) posted against each of its lines; Goods Receipt detail page shows the PO line(s) each of its lines fulfils.
7. Admin UI: "Receive" entry point on the PO detail page that starts a new Goods Receipt draft pre-populated from one PO's outstanding lines; remaining-quantity indicators; receiving history panel; navigation both directions (PO ↔ Receipt).
8. Mixed receipts: one receipt may contain PO-linked lines (possibly from more than one PO) and direct (`po_line_id = NULL`) lines together — `source` gains `'po'`/`'mixed'` alongside M4's `'direct'`.
9. `qty_received` reconciliation tooling (WP-CLI, read-only by default, `--fix` to repair), satisfying INV-4's "asserted by a reconciliation check" clause — full behavior in §Reconciliation tooling.
10. Schema v9, `expected_schema_v9()`, `qty_received` column, `forbidden_columns` entry removed.
11. Full test suite, documentation, release preparation for v1.22.0.

### Out of Scope / Explicit Non-Goals

- Any new stock or cost mutation path. `Restock_Service::apply_purchase_line_change()`/`apply_purchase_line_reversal()` are called **only** from `Goods_Receipt_Service`, exactly as M4 froze — M5 adds zero new callers.
- Any change to the weighted-average costing formula, landed-cost allocation, or void-correctness algorithm (all frozen by M4).
- Any change to `wc_io_purchase_order_lines.status` (per-line bookkeeping column) — untouched, as decided above.
- Hard over-receipt blocking — explicitly rejected by D5 (see Scope-boundary ruling).
- An `Inbound Shipment` entity, carrier/tracking UI beyond what M2 already has (D10, still frozen).
- Reservations, storefront changes (M7), legacy Batch Intake retirement (M6) — `wc_io_purchase_batches*` untouched. **Binding note for M6's own future plan:** M6 must never infer or reconstruct `qty_received` for any PO line from historical Batch Intake data. Legacy migration's job is exactly what D14/M4 already scoped it as — converting historical applied *batches* into direct posted Goods Receipts (`po_line_id` NULL, per D7/M4) — never fabricating a retroactive PO↔receipt linkage that never existed. Purchase Order receiving history, in the `qty_received`/PO-status sense this plan defines, begins with M5; nothing before it is eligible for backfill (see also §Database — Migration assumption).
- New REST endpoints (D16) — service methods + WP hooks only, matching every prior milestone.
- Reopening a `received`/`cancelled`/`closed_short` PO to `placed` — no such transition exists or is added; a `received` PO can only move *backward* automatically via voiding a receipt (§Receiving-status ownership), never by operator action.
- Row-level locking, distributed locks, queue-based serialization — M4's "no locking" decision (§Idempotency & concurrency, M4 plan) is not revisited; the same single-warehouse/1–3-admin justification applies unchanged.

### Dependencies

- `WC_Inventory_Overview_Goods_Receipt_Service` (M4, extended, not replaced).
- `WC_Inventory_Overview_DB_Transaction` (reused, same `run()`/`throw_if_error()` pattern M4 established).
- `WC_Inventory_Overview_Purchase_Order_Lines`, `WC_Inventory_Overview_Purchase_Orders`, `WC_Inventory_Overview_PO_Quantities`, `WC_Inventory_Overview_PO_Lifecycle`, `WC_Inventory_Overview_PO_Events`, `WC_Inventory_Overview_PO_Statuses` (all M2, extended).
- `WC_Inventory_Overview_PO_Request_Token` (reused directly, new context string).
- `WC_Inventory_Overview_Purchasing_Caps` (extended with one new constant).
- `WC_Inventory_Overview_Receipt_Lines`, `WC_Inventory_Overview_Goods_Receipts`, `WC_Inventory_Overview_Movements` (M4, each gets one small, additive change — see §Database/§Class changes).

### Architecture constraints (carried forward, unchanged)

- D3/INV-2: only `Goods_Receipt_Service::post()`/`void()` may mutate stock or weighted-average cost. M5 does not touch this.
- **New, elevated rule for M5 (see §Receiving-status ownership → Formal invariant):** `qty_received` and PO header status auto-transitions follow exactly one chain, no tier skippable: `Goods_Receipt_Service` (via `post()`/`void()` operating on a line whose `po_line_id` is set) is the sole business orchestrator that *initiates* the change; `PO_Receiving_Sync` is the sole owner of the mutation itself; `Purchase_Order_Lines::increment_qty_received()` is the sole physical writer. No controller, admin page, AJAX handler, repository, or `PO_Service` method may write `wc_io_purchase_order_lines.qty_received` directly or bypass any tier of this chain. The one documented exception — the reconciliation CLI's `--fix` mode — still passes through `PO_Receiving_Sync` (via its own `reconcile_line()` entry point), never around it.
- D6: PO↔receipt linkage stays line-level only (`receipt_line.po_line_id`); no header-level `po_id` is added to `wc_io_goods_receipts` (M4's schema guard test already asserts this table has no such column — it must keep passing).
- D19/INV-8: unchanged; `po_line_id` always points at a PO line whose own product/variation reference already satisfies INV-8 (enforced at PO-line-creation time by M2, not re-validated by M5).

### Success criteria

- Receiving against a PO line (fully or partially) posts correctly through the unmodified M4 mutation path, updates `qty_received`, and updates PO status to the correct value.
- Multiple receipts against the same PO line/PO compose correctly regardless of order (INV-1's independence of incoming supplies, still true — each receipt is its own record).
- Voiding a PO-linked receipt reverses `qty_received` and walks PO status back down correctly, even when other receipts have posted against other lines of the same PO in between.
- Over-receipt is possible, warned, confirmed, and audited — never silently rejected.
- A receipt may mix PO-linked lines (from one or more POs) with direct lines in one transaction.
- All M0–M4 tests and quality gates remain green; the specific M4 architecture guards that structurally forbid `po_line_id`/`qty_received`/PO-table writes in M4's files are deliberately and visibly revised (not silently broken) as part of this milestone.

---

## Receiving-status ownership (the one hard design question, resolved)

### The problem

INV-4 requires `qty_received` to be a **maintained counter** ("must always equal the sum of posted receipt-line quantities... asserted by a reconciliation check"), not computed at read time — this is different from `qty_outstanding`, which stays purely computed. A maintained counter needs exactly one writer, updated transactionally, exactly like M4 established for stock/cost. But `qty_received` lives on a **different table** (`wc_io_purchase_order_lines`, owned by the PO domain) than what `Goods_Receipt_Service` currently writes (`wc_io_goods_receipts`/`wc_io_receipt_lines`/`inventory_movements`/WooCommerce stock). And the PO header's `status` must recompute from `qty_received` in the same atomic step, or PO status could observably lag behind what was actually received.

### Rejected approach

Have `Goods_Receipt_Service` call `WC_Inventory_Overview_PO_Service`'s existing higher-level methods (`update_line()`, etc.) to record the receipt. **Rejected**: `PO_Service` uses the older manual `begin()/commit()/rollback()` transaction pattern (confirmed by source read — it does not use `DB_Transaction::run()`), and its methods are operator-driven (each does its own validation, editability checks, and PO-event authorship suited to manual edits, not system-driven receiving deltas). Nesting `PO_Service`'s own transaction inside `Goods_Receipt_Service`'s `run()` closure would create two independent commit/rollback authorities over what must be one atomic operation — exactly the "second mutation path" this plan must not introduce.

### Chosen approach — a new, narrow, single-purpose class: `WC_Inventory_Overview_PO_Receiving_Sync`

A stateless service. Its primary entry point — the one used for every normal receiving operation, and the only one referenced in the design walkthrough below — is called only from inside `Goods_Receipt_Service::post()`/`void()`'s existing `$txn->run()` closure, immediately after that line's `Restock_Service` call and `Movements` insert succeed (same per-line ordering M4 already established — stock mutation, then movement, then now: PO sync). (A second, narrower entry point exists solely for CLI-driven counter repair, `reconcile_line()` — specified in full in §Class/service changes and §Reconciliation tooling, not part of the normal receiving flow described in this subsection.)

```php
// WC_Inventory_Overview_PO_Receiving_Sync::apply_line_delta(
//     int $po_line_id, float $qty_delta, int $receipt_id, string $receipt_number,
//     int $user_id, bool $is_void
// ): array|WP_Error
```

Internally, in order, all inside the caller's already-open transaction (no `begin()`/`commit()` of its own — this class never opens a transaction, it only issues plain UPDATE/INSERT statements that participate in whatever transaction is already open, exactly like `Restock_Service`'s mutators do):

1. **Direct, narrow UPDATE** on the PO line — a new repository method `WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received( int $line_id, float $delta ): int` (returns rows affected), doing `UPDATE ... SET qty_received = qty_received + %f, updated_at = %s WHERE id = %d`. `$delta` is positive when posting, negative (the receipt line's own exact stored `qty`) when voiding — the same current-state-relative-delta pattern M4 already proved correct for stock reversal, applied here to a second running total. No negative-floor guard is needed: `qty_received` is *only* ever written by this one method, so a void's delta is always exactly a previously-applied post's own delta and can never drive the counter negative (proof is structurally identical to why M4's void reversal composes correctly regardless of intervening receipts — see M4 plan §Inventory mutation).
2. **Re-fetch all lines for the owning PO** (`Purchase_Order_Lines::list_for_po( $po_id )`) and compute `$total_outstanding = array_sum( GREATEST(0, ordered-received-cancelled) )` and `$total_received = array_sum(received)` across every line.
3. **Compute the new header status** via a pure function (see §Status recompute function below), compare to the PO's current stored `status`; if different, write it with `Purchase_Orders::update_fields( $po_id, ['status' => $new_status, 'updated_at' => now, 'updated_by' => $user_id] )` (the same repository method `PO_Service` already uses for header writes — reused directly, not duplicated).
4. **Write two PO events** via the existing, unmodified `WC_Inventory_Overview_PO_Events::add()` (M2's sole mutation method for that table, untouched): one line-level event (`po_line_received` or `po_line_receipt_voided`, `po_line_id` set, `data` includes `receipt_id`, `receipt_number`, `qty_delta`, `over_receipt` flag), and — only if the header status actually changed — one header-level event (`po_partially_received` / `po_received`, mirroring the existing `po_placed`/`po_cancelled` shape).
5. Every fallible call above (`increment_qty_received` returning 0 rows, `update_fields`, `PO_Events::add()`) is wrapped in the **same** `Goods_Receipt_Service::throw_if_error()` bridge the caller already uses — `PO_Receiving_Sync` itself never catches anything; a failure here throws out through the caller's `run()` closure exactly like a `Restock_Service`/`Movements` failure already does, producing a full `ROLLBACK` of the stock mutation, the movement row, *and* this PO-side update together. This is the entire reason this logic must run inside `Goods_Receipt_Service`'s transaction rather than as a post-commit follow-up call: a receipt that fails cannot leave `qty_received` credited (or debited) while the stock mutation it was supposed to accompany rolled back.

Returns `array{po_id, po_line_id, old_qty_received, new_qty_received, old_status, new_status}` (or `WP_Error`), which `Goods_Receipt_Service` folds into the per-line result it already builds (§Class/service changes).

### Status recompute function — pure, deterministic, no side effects

`WC_Inventory_Overview_PO_Statuses::recompute_for_receiving( string $current_status, float $total_outstanding, float $total_received ): string`

```
if current_status not in {placed, partially_received, received}:
    return current_status   // never touches draft/cancelled/closed_short — those are terminal
                             // or pre-receiving; receiving against them is prevented earlier (below)
if total_outstanding <= 0:
    return RECEIVED
if total_received > 0:
    return PARTIALLY_RECEIVED
return PLACED   // total_received == 0 (can happen after a void brings received back to exactly 0)
```

This function is symmetric — it produces the correct answer whether called after a post (received went up) or a void (received went down), because it never looks at *direction*, only the current totals. This is the same "current-state-relative, not directional" design principle M4 used for void correctness, applied to status.

**Which PO statuses can receiving be attempted against at all?** `Goods_Receipt_Service` (via a new pre-transaction validation step, §Receiving workflow) rejects — before opening any transaction — an attempt to receive against a PO line whose owning PO's status is `draft`, `cancelled`, or `closed_short`. `placed`, `partially_received` are receivable. **`received` is also receivable** (over-receipt against an already-fully-received PO is explicitly permitted by D5 — the recompute function above simply returns `RECEIVED` again, a no-op status-wise, while `qty_received` still legitimately increases and an event is still logged).

### PO lifecycle transition table — updated

`WC_Inventory_Overview_PO_Lifecycle::transitions()` becomes:

```
DRAFT              → [PLACED, CANCELLED]
PLACED             → [CANCELLED, CLOSED_SHORT, PARTIALLY_RECEIVED*, RECEIVED*]
PARTIALLY_RECEIVED → [CANCELLED, CLOSED_SHORT, RECEIVED*, PARTIALLY_RECEIVED*]
RECEIVED           → [PARTIALLY_RECEIVED*, PLACED*]        (void-only, see below)
CANCELLED          → []
CLOSED_SHORT       → []
```
`*` = **auto-transition only**, reachable exclusively through `PO_Receiving_Sync`, never through `PO_Lifecycle::assert_transition()`'s operator-gated path and never offered as a button/action in the UI. `assert_transition()` itself is **not** used for these — they bypass the operator state-machine gate entirely, exactly as `compare_and_swap_post()`/`_void()` already bypass `Goods_Receipt_Lifecycle::assert_transition()` for the same reason (system-driven, not operator-driven). `can_perform()`/`available_actions()` for manual actions (`place`, `cancel`, `close_short`) are extended so `cancel`/`close_short` remain available from `PARTIALLY_RECEIVED` (closing out a partial PO) but not from `RECEIVED` (nothing left to cancel) — `RECEIVED`'s only exit is the automatic downgrade via void. `is_editable()` gains no new `true` cases: `partially_received`/`received` are **not** editable (header/line edits stay `draft`/`placed`-only, unchanged from M2) — this deliberately mirrors M4's own "posted is immutable" precedent one level up: once real stock has moved against a PO line, that PO's ordered-quantity/price/date fields freeze for that line's purposes, same as a posted receipt freezes.

### Why this is not a second mutation path

`Restock_Service` gains zero new callers — `PO_Receiving_Sync` never touches stock, cost, or WooCommerce product meta; it only writes `wc_io_purchase_order_lines.qty_received` and `wc_io_purchase_orders.status`, columns `Goods_Receipt_Service` (via M4) has never touched and `PO_Service` will now *stop* touching for these two specific columns (`PO_Service`'s own `update_fields()` calls never set `status` to `partially_received`/`received` — only `PO_Receiving_Sync` does; `PO_Service`'s manual status transitions for `place`/`cancel`/`close_short` remain exactly as M2 built them, calling the same `update_fields()` repository method but never producing these two new values). One column, one owner, one physical writer, one transaction — the identical invariant D3/INV-2 already enforces for stock, extended by name to `qty_received`/PO-receiving-status, and given its precise three-tier shape immediately below.

### Formal invariant — sole mutator of `qty_received` (elevated rule, mirrors D3/INV-2)

Stated here as a binding, standalone architectural invariant — not merely an implementation consequence of how `PO_Receiving_Sync` happens to be written, exactly as D3/INV-2 elevates "only Goods Receipt posting/voiding mutates stock" from an implementation detail to a named architectural rule. The invariant has three tiers, each with exactly one holder for the normal receiving path — no tier's responsibility may be exercised by any other class, and no tier may be skipped or reached around:

```
Goods_Receipt_Service              (sole business orchestrator — initiates the change,
        │                           as an integral part of posting/voiding a receipt)
        ▼
PO_Receiving_Sync                  (sole owner of the qty_received mutation — decides the
        │                           delta, recomputes PO status, authors the PO event)
        ▼
Purchase_Order_Lines::increment_qty_received()   (sole physical database writer)
```

> **`qty_received` is a maintained counter, and this plugin recognizes exactly one mutation chain for it — not one mutating class.** `WC_Inventory_Overview_Goods_Receipt_Service` is the **sole business orchestrator** for normal warehouse receiving operations: the only code path permitted to *initiate* a `qty_received` change, as an integral part of posting or voiding a PO-linked receipt. It does not itself perform the mutation. `WC_Inventory_Overview_PO_Receiving_Sync` is the **sole owner of the `qty_received` mutation**: the only class permitted to compute the delta, recompute PO status, and author the corresponding PO event; `Goods_Receipt_Service` calls into it rather than duplicating any of that logic. `WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received()` is the **sole physical writer**: the only method anywhere in the codebase that issues the actual database write against this column. No controller, admin page, AJAX handler, repository other than `Purchase_Order_Lines` itself (and then only through that one method), `PO_Service` method, or any future milestone's code may write `qty_received` directly — and no class may skip a tier (a hypothetical `Goods_Receipt_Service` call straight to `increment_qty_received()`, bypassing `PO_Receiving_Sync`'s status-recompute and event-authoring responsibilities, would be exactly as much a violation of this invariant as an unrelated file calling it).

**The one explicitly-named, narrow exception:** the read-only-by-default reconciliation CLI tool (§Reconciliation tooling) remains the *only* documented maintenance exception to this chain — and even it does not bypass the chain, only its first tier. Only when invoked with `--fix` does it correct a drifted counter, and it does so **through `PO_Receiving_Sync`**, never around it: a second, distinctly-named public method on the same sole-owner class, `PO_Receiving_Sync::reconcile_line()`, which itself still funnels down to the one physical write method, `increment_qty_received()`. It never calls `increment_qty_received()` directly and never issues raw SQL against the column. This exception is itself enforced by an architecture guard (§Testing, Guard 2b) requiring the reconciliation CLI to be the only caller of `reconcile_line()` anywhere in the codebase — exactly as `Goods_Receipt_Service` is architecture-guard-enforced (§Testing, Guard 2) to be the only caller of `apply_line_delta()`.

---

## Numeric precision & completion comparison

`qty_ordered`, `qty_received`, `qty_cancelled`, and the derived `qty_outstanding` are all `decimal(19,4)` — the same quantity precision already used everywhere else in this plugin (M4's plan: *"quantities and monetary totals → 4 decimals; unit costs and averages → 6 decimals"*). M5 introduces no new precision or rounding convention.

**Completion test.** Any comparison used to decide "is this line/PO fully received" — `qty_outstanding == 0`, feeding `recompute_for_receiving()`'s `total_outstanding <= 0` branch — must compare at this same 4-decimal precision, using the rounding policy already applied throughout the codebase at write time (`round( $value, 4 )` / `wc_format_decimal( $value, 4 )`, the same convention `Restock_Service` and `Goods_Receipt_Costing` already use). `increment_qty_received()`'s computed sum must be rounded to 4 decimals before persisting, exactly like every other quantity write in the codebase — this is not a new rule, just this column's instance of an existing one. Because every quantity column is always written already-rounded, the values read back from the database, and the PHP arrays built from them, are already snapped to 4-decimal precision by the time `recompute_for_receiving()` or the `GREATEST(0, ...)` SQL expression compares them.

**Forbidden:** direct floating-point equality or inequality on raw, unrounded PHP floats (`$outstanding === 0.0`, `$outstanding < 0.0001` as an improvised epsilon, etc.) anywhere in M5's completion/outstanding logic. Because the inputs are already rounded at the boundary, no epsilon/tolerance logic is needed or introduced — comparisons are plain numeric comparisons (`<=`, `==`) against already-4-decimal-rounded values, matching exactly how M2's existing `qty_outstanding` comparisons already work today.

---

## Database

**New `DB_VERSION = '9'`.**

### `wc_io_purchase_order_lines` — ALTER

```sql
ALTER TABLE wp_wc_io_purchase_order_lines
  ADD COLUMN qty_received decimal(19,4) NOT NULL DEFAULT 0 AFTER qty_cancelled;
```

No new index required — `qty_received` is never filtered/sorted on independently; existing `po_id`/`status` indexes already serve every M5 query (`list_for_po`, open-line scans already used by M3's Inventory Position). Nullable: no — matches `qty_ordered`/`qty_cancelled`'s existing `NOT NULL DEFAULT 0` convention exactly; upgrade backfills every existing row to `0` (correct — no M2/M3-era PO line has ever had a receipt against it, since M4's Quick Receive never set `po_line_id` and M5 is the first code able to write it).

### `wc_io_purchase_orders` — no ALTER

`status varchar(20)` already accommodates the two new string values (`'partially_received'`, `'received'`) without a column-width or type change (max existing value length is `'partially_received'` = 19 chars ≤ the column's 20-char limit — verified against the schema, no ALTER needed here).

### `wc_io_receipt_lines` / `wc_io_goods_receipts` — no ALTER

`po_line_id` (already `bigint(20) unsigned NULL`, already indexed) and `source` (already `varchar(20) NOT NULL DEFAULT 'direct'`, wide enough for `'po'`/`'mixed'`) were both already schema-ready from M4. Zero DDL changes needed on either table — confirmed by direct read of M4's `install.php` DDL.

### `wc_io_po_events` — no ALTER

Existing columns (`po_id`, `po_line_id` nullable, `event_type`, `summary`, `data`, `reason_code`, `user_id`, `created_at`) already accommodate the new event types below; no schema change.

### `forbidden_columns` — removed

`expected_schema_v9()`'s `forbidden_columns['purchase_order_lines']` becomes `array()` (was `['qty_received']` in v7/v8). This is the one entry M5 is *permitted* to change — every other `forbidden_columns` entry (e.g. anything M4/M6 established) carries forward unchanged.

### `expected_schema_v9()` and dispatcher — exact pattern (mirrors M4's v7→v8 pattern precisely)

```php
private static function expected_schema( $version ) {
    if ( version_compare( (string) $version, '9', '>=' ) ) {
        return self::expected_schema_v9();
    }
    if ( version_compare( (string) $version, '8', '>=' ) ) {
        return self::expected_schema_v8();
    }
    if ( version_compare( (string) $version, '7', '>=' ) ) {
        return self::expected_schema_v7();
    }
    return self::expected_schema_v6();
}

private static function expected_schema_v9() {
    $base = self::expected_schema_v8();
    $base['columns']['purchase_order_lines'][] = 'qty_received';
    $base['forbidden_columns']['purchase_order_lines'] = array();
    return $base;
}
```
No new `tables` entries (no new table), no new unique-index block needed in `assert_schema_shape()` (no new unique constraint introduced by M5).

**Concrete trap to avoid (identical to the one M4's plan flagged for v7→v8):** forgetting the dispatcher's `version_compare( $version, '9', '>=' )` branch silently routes DB_VERSION 9 to `expected_schema_v8()`, which still lists `qty_received` as *forbidden* — every fresh v9 install would then fail its own schema-shape assertion at boot. This must be the first thing verified once WP1 lands.

### Migration assumption: every existing PO line starts at `qty_received = 0`

Stated explicitly, not left implicit: the v8→v9 ALTER backfills `qty_received = 0` on **every** pre-existing row of `wc_io_purchase_order_lines`, with no reconstruction attempt. This is correct, not a gap, because no PO line created before M5 could ever have had a receipt posted against it — `po_line_id` did not exist as a settable field anywhere in the codebase until this milestone (M4 built the column but structurally prevented any code path from writing it; M2's PO lines predate `po_line_id` entirely). There is no historical receiving data to migrate into this column, and none is attempted. Every PO placed under M2/M3/M4 and still open at the moment of the v9 upgrade simply begins its receiving history from zero, exactly as if it were newly placed — its `qty_outstanding` is unaffected (the old 2-term formula and the new 3-term formula agree exactly when `qty_received = 0`), so upgrading produces no visible change to any existing PO's outstanding/incoming figures until the first M5-era receipt posts against it.

---

## Class/service changes

### `WC_Inventory_Overview_Purchase_Order_Lines` (M2, extended)

- New method: `public static function increment_qty_received( int $line_id, float $delta ): int` — direct `UPDATE ... SET qty_received = qty_received + %f WHERE id = %d`, returns `$wpdb->rows_affected`. This is the **only** write path to this column anywhere in the codebase (enforced by an M5 architecture guard, §Testing).
- `create()`/`update()` gain no new accepted key for `qty_received` — it is never operator-settable through the normal line CRUD path, only through `increment_qty_received()`.

### `WC_Inventory_Overview_PO_Quantities` (M2, extended)

- `outstanding( $qty_ordered, $qty_cancelled ): float` becomes `outstanding( $qty_ordered, $qty_received, $qty_cancelled ): float` implementing the **full** INV-4 formula: `max(0.0, ordered - received - cancelled)`. **Every existing caller must be updated** — grep-confirmed callers: `WC_Inventory_Overview_Purchase_Order_Lines::outstanding()`, `WC_Inventory_Overview_Purchase_Orders::qty_outstanding()`, and (per M3's own source, `list_open_lines_for_product_ids()`/`_variation_ids()`'s inline SQL literal `GREATEST(0, pol.qty_ordered - pol.qty_cancelled)`) — **M3's Inventory Position resolver's raw SQL must also be updated** to `GREATEST(0, pol.qty_ordered - pol.qty_received - pol.qty_cancelled)`, or M3's "Incoming" figures will silently overstate outstanding supply the moment any receipt posts against a PO line. This is the one required, narrow touch to M3 code — flagged explicitly here so it isn't missed (M3's own plan explicitly deferred this exact recomputation to M5: *"Incoming is untouched under \[M4's] scope ruling... only changes once qty_received exists, in M5"*).

### `WC_Inventory_Overview_PO_Statuses` (M2, extended)

- New constants: `const PARTIALLY_RECEIVED = 'partially_received'; const RECEIVED = 'received';`
- `all()` includes both. `terminal()` stays `[CANCELLED, CLOSED_SHORT]` — `received` is **not** terminal (a void can still walk it back down, per §Receiving-status ownership).
- New method: `recompute_for_receiving( string $current_status, float $total_outstanding, float $total_received ): string` (pure function, §Receiving-status ownership).

### `WC_Inventory_Overview_PO_Lifecycle` (M2, extended)

- `transitions()` updated per §Receiving-status ownership's table.
- `can_perform()`/`available_actions()` updated: `cancel`/`close_short` remain valid from `partially_received`; not valid from `received`.
- No change to `is_editable()`'s *return values* for existing statuses; `partially_received`/`received` both return `false` (not editable) — same shape, two new `false` cases.

### `WC_Inventory_Overview_PO_Events` (M2, extended)

New constants, appended to `known_types()` (existing 12 stay unchanged):
```php
const TYPE_LINE_RECEIVED             = 'po_line_received';
const TYPE_LINE_RECEIPT_VOIDED       = 'po_line_receipt_voided';
const TYPE_PARTIALLY_RECEIVED        = 'po_partially_received';
const TYPE_RECEIVED                  = 'po_received';
const TYPE_QTY_RECEIVED_RECONCILED   = 'po_qty_received_reconciled';
```
No change to `add()`'s signature or behavior — these are just new values for the existing `event_type` parameter, exactly how M2 itself added `TYPE_LINE_CANCELLED` etc. alongside `TYPE_LINE_CHANGED`. The fifth constant, `TYPE_QTY_RECEIVED_RECONCILED`, is written only by `PO_Receiving_Sync::reconcile_line()` (§Reconciliation tooling) — never by `apply_line_delta()` — so a repaired counter is always visibly distinguishable in the PO's timeline from a normal receipt-driven change.

### `WC_Inventory_Overview_PO_Receiving_Sync` (new class, M5)

As specified in full in §Receiving-status ownership. **Two** public methods, both the sole owner's only mutating entry points, each with exactly one permitted caller (§Testing architecture guards):

- `apply_line_delta( int $po_line_id, float $qty_delta, int $receipt_id, string $receipt_number, int $user_id, bool $is_void ): array|WP_Error` — the receiving path, called only by `Goods_Receipt_Service`, as specified above.
- `reconcile_line( int $po_line_id, float $correct_qty_received, int $user_id ): array|WP_Error` — the reconciliation-CLI path (§Reconciliation tooling), called only by the reconciliation CLI command file. Computes `$delta = $correct_qty_received - $current_stored_value` internally, then reuses the *same* internal private steps `apply_line_delta()` uses (physical UPDATE via `increment_qty_received()`, re-fetch lines, recompute/write header status if changed) — the two public methods share one private implementation, differing only in which `PO_Events` type they write (`po_line_received`/`po_line_receipt_voided` vs. `po_qty_received_reconciled`) and in not requiring a receipt id/number (reconciliation has no originating receipt). Unlike `apply_line_delta()`, `reconcile_line()` is not necessarily called from inside someone else's already-open transaction — the CLI command wraps each call in its own `DB_Transaction::run()`, one per corrected line, so a mid-run failure only rolls back that one line's repair, not the whole reconciliation pass.

No `table_name()`, no CRUD — it is a thin orchestrator over three already-existing repositories' methods, not a repository itself.

### `WC_Inventory_Overview_Receipt_Lines` (M4, extended)

- `create( int $receipt_id, array $data )`: the hardcoded `'po_line_id' => null` (M4's structural guarantee) becomes `'po_line_id' => isset( $data['po_line_id'] ) && (int) $data['po_line_id'] > 0 ? (int) $data['po_line_id'] : null`. This is the **only** change to this class.
- `update()`'s `$allowed` whitelist: **not** extended with `po_line_id` — a draft line's PO linkage is set once at creation (when the operator picks "receive against PO line X" or "direct") and is not independently editable after; changing which PO a line fulfils is a delete-and-re-add on the still-draft receipt, not an in-place edit (this avoids ever needing to re-run `PO_Receiving_Sync` logic against an edited-in-place draft, since drafts have zero stock/PO effect until posted — simpler, and consistent with D2's "independent line records" philosophy).

### `WC_Inventory_Overview_Goods_Receipt_Service` (M4, extended — the central change)

- `create_draft_from_post()`/line-building path: now accepts an optional `po_line_id` per submitted line (from the admin form, §Receiving workflow). When present, at draft-build time the service calls `Purchase_Order_Lines::get( $po_line_id )` to snapshot `sku_snapshot`/`name_snapshot`/product identity consistency (defense-in-depth: the line's product must match the PO line's product — mismatch is rejected before any draft is even saved) and to compute the **default** receive quantity (= that line's current outstanding) for the UI preview, but performs **no** PO mutation at draft time (drafts remain zero-stock-effect, zero-PO-effect — unchanged M4 invariant).
- `post()`: after the existing per-line `Restock_Service::apply_purchase_line_change()` + `Movements::insert_goods_receipt()` pair for a line, **if that line's `po_line_id` is not null**, calls `WC_Inventory_Overview_PO_Receiving_Sync::apply_line_delta( $po_line_id, $line['qty'], $receipt_id, $receipt_number, $user_id, false )`, wrapped in the existing `throw_if_error()`. Same per-line loop, same transaction, no new transaction boundary.
- `void()`: symmetric — after `apply_purchase_line_reversal()` + `Movements::insert_goods_receipt_void()`, if `po_line_id` is set, calls `PO_Receiving_Sync::apply_line_delta( $po_line_id, -$line['qty'], ..., true )`.
- `source` computation: at draft-save time, derived (not operator-chosen) from the line set — `'direct'` if every line has `po_line_id === null` (M4's existing behavior, unchanged for pure Quick Receives), `'po'` if every line has `po_line_id` set, `'mixed'` otherwise. Implemented as a small pure helper, e.g. `self::derive_source( array $lines ): string`.
- New pre-transaction validation (before `$txn->begin()`, alongside M4's existing draft/status/non-empty checks): for every line with `po_line_id` set, the referenced PO line must exist and its owning PO's status must be one of `{placed, partially_received, received}` — otherwise `WP_Error( 'wc_io_gr_po_not_receivable' )`, zero DB writes, exactly the same "cheap check before opening a transaction" discipline M4 established for its own draft/status checks.
- Return shape of `post()`/`void()`: unchanged header row, **plus** each line's result array (already built per-line by M4) gains `po_line_id`, and — when set — `po_sync` (the `PO_Receiving_Sync` result: old/new `qty_received`, old/new PO status), so the admin PRG layer can show "PO-2026-0004 line 2: 8 of 10 now received; PO status: Partially Received" in the post-confirm success notice.

**What does *not* change in this file:** the `throw_if_error()` bridge, the transaction lifetime boundary, the compare-and-swap header UPDATE, the cache-invalidation-after-commit rule, the idempotency token consumption point — all inherited byte-for-byte from M4.

### `WC_Inventory_Overview_Movements` (M4, extended — additive only)

No new constants needed for the *movement type* (a PO-linked post is still, from the stock ledger's point of view, exactly a `goods_receipt`/`goods_receipt_void` row — the mutation is identical regardless of PO linkage). One additive change: `insert_goods_receipt()`/`insert_goods_receipt_void()`'s `$r` array gains an **optional** key the caller may pass through: nothing new is required here at all, in fact — `reference_type`/`reference_id` already point at the receipt header (not the PO), which is sufficient; a movement row's PO linkage is always discoverable by joining `receipt_lines.po_line_id`, so no direct `po_id`/`po_line_id` column is added to `inventory_movements`. **Confirmed: zero changes to this class.**

### `WC_Inventory_Overview_Purchasing_Caps` (M2, extended)

- New constant: `const RECEIVE_PO = 'receive_po';` (default `manage_woocommerce`, via the existing filterable map — same pattern as every other cap). Gates only the **entry point** (the "Receive" button/link on the PO detail page, and the PO-line-picker step of building a PO-linked draft). The actual mutating actions continue to be gated by M4's existing `EDIT_RECEIPT`/`POST_RECEIPT`/`VOID_RECEIPT` inside `Goods_Receipt_Admin`/`Goods_Receipt_Service` — unchanged, not duplicated.

---

## Idempotency & concurrency

Unchanged mechanism, new context strings only — no new class, no new pattern:

- Receipt-level idempotency (post/void) is **already** fully covered by M4's `PO_Request_Token` (`'gr_post'`/`'gr_void'` contexts) + compare-and-swap header UPDATE — a PO-linked post/void is still, mechanically, one `Goods_Receipt_Service::post()`/`void()` call, so it inherits this protection automatically. **No new token context is needed for posting/voiding itself.**
- The one new idempotency-adjacent surface is the **draft-creation-from-PO** entry point (clicking "Receive" on a PO detail page pre-fills a new draft) — this is a plain `create_draft_from_post()` call (already idempotent-safe in the sense that clicking twice just creates two drafts, exactly as clicking "New Receipt" twice already does today; a stray extra draft has zero stock/PO effect and can be deleted, so no token is needed here, consistent with M4 never token-protecting draft creation either).
- Concurrency for `qty_received`: the **same** MySQL-transaction-serializes-the-actual-write argument M4 used for stock applies unchanged — `increment_qty_received()`'s `UPDATE ... SET x = x + %f` is itself safe under concurrent transactions (it's a read-modify-write expressed as a single atomic SQL statement, not a read-then-write-in-PHP race), and two receipts posting against the same PO line at the same moment simply serialize at the database row level like any other concurrent UPDATE — no row locking is introduced, consistent with M4's explicit no-locking decision.

---

## Reconciliation tooling

Satisfies INV-4's closing clause (*"asserted by a reconciliation check"*) and is the one explicitly-named exception to the sole-mutator invariant above — a new WP-CLI command extending the existing `cli/` scaffold: `wp wc-io reconcile-qty-received [--fix] [--po=<id>]`.

- **Default execution (no `--fix`) is strictly read-only.** For every PO line in scope (all lines, or one PO's lines with `--po=<id>`), it independently recomputes the expected `qty_received` — the net sum of posted receipt-line quantities against that `po_line_id` across `wc_io_receipt_lines`/`wc_io_goods_receipts` (posted receipts add, voided receipts are already net-zero since `Goods_Receipt_Service::void()` already reversed their contribution at void time) — and compares it against the currently stored column value. It writes nothing to the database in this mode, regardless of what it finds.
- **`--fix` is required to perform any write.** Only with this flag does the command call `PO_Receiving_Sync::reconcile_line()` (never `increment_qty_received()` directly, never raw SQL — see the sole-mutator invariant above) with the correct value for each line found to have drifted.
- **Every repaired line is individually logged**, one line of CLI output per correction (`PO-2026-0004 line 2: qty_received 8.0000 -> 10.0000 (drift +2.0000)`), and — only when `--fix` is passed — one corresponding `PO_Events::add()` entry per correction (`TYPE_QTY_RECEIVED_RECONCILED`), so a repair becomes part of the PO's permanent, visible audit history rather than a silent database patch.
- **Summary output** at the end of every run, dry-run or `--fix`, reports two counts: `Verified: <n>` (lines checked with no drift) and either `Drift found: <n>, would repair with --fix` (dry run) or `Repaired: <n>` (with `--fix`) — e.g. `Verified: 412, Drift found: 2, Repaired: 0 (dry run — pass --fix to apply)` or `Verified: 412, Drift found: 2, Repaired: 2`.

This is a diagnostic/repair tool for operational drift (a direct database edit, a restored backup, a bug in an earlier plugin version) — it is never invoked automatically as part of posting or voiding, only run by an administrator on demand.

---

## Audit model

Preserves and **closes** the gap M4's own Audit-trail decision explicitly reserved for M5 (point 6 of that decision: *"once M5 populates `receipt_line.po_line_id`, a receipt posting/voiding against a PO line is exactly the kind of 'quantity change' INV-6 already requires the PO event log to capture... via the already-built `PO_Events::add()` API... zero new schema required"*) — confirmed exactly as anticipated:

- **Movement provenance:** unchanged from M4 — `reference_type='goods_receipt'`, `reference_id=<receipt id>` on every `inventory_movements` row. A PO-linked movement's PO relationship is discoverable via `receipt_lines.po_line_id → purchase_order_lines.po_id`, a join, not a duplicated column (avoids two sources of truth for the same fact).
- **PO event log:** two new event types per successful post/void of a PO-linked line (`po_line_received`/`po_line_receipt_voided`, per-line, `po_line_id` set, `data` = `{receipt_id, receipt_number, qty, over_receipt:bool, qty_over:float}`), plus a header-level event (`po_partially_received`/`po_received`) only on an actual status transition — mirroring the exact shape of M2's existing `po_placed`/`po_line_changed` events.
- **Goods Receipt's own audit surface** (header timestamps/actors + typed movements, per M4's Audit-trail decision) is unchanged and still the canonical record of *what physically happened*; the PO event log is the canonical record of *what this meant for the PO's commitment* — two audiences, zero duplication, exactly the division M4's decision anticipated.
- **Receiving history views:** PO detail page's timeline (`render_timeline()`, already built by M2) needs no new rendering *mechanism* — it already iterates `PO_Events::list_for_po()`; it only needs label/format additions for the four new event types (`action_labels()`-equivalent). Goods Receipt detail page gains one new read-only field per PO-linked line: "Fulfils: PO-2026-0004, line 2" (a link, built from `po_line_id → Purchase_Order_Lines::get()` → `po_id → Purchase_Orders::get_by_number()`-style lookup, read-only, computed at render time, not stored redundantly).

No duplicate audit model is introduced — this was the explicit thing to guard against per the user's directive, and the design above adds exactly the two event types INV-6 already named as the mechanism, nothing else.

---

## Receiving workflow

### Entry point: "Receive" from the PO detail page

New button/link on `PO_Admin::render_detail()` (only rendered when PO status ∈ `{placed, partially_received, received}` and `Purchasing_Caps::RECEIVE_PO` passes) → routes to a new `Goods_Receipt_Admin` entry action (`wc_io_gr_new_from_po&po_id=<id>`) that:

1. Loads the PO and its lines via existing M2 repositories (read-only).
2. Builds an **in-memory** (not yet persisted) draft-line proposal: one line per PO line with `qty_outstanding > 0` (lines already fully covered are omitted by default, but remain pickable — see below), default `qty` = that line's current outstanding, `po_line_id` pre-set, cost pre-filled from the PO line's `unit_cost`/`currency` (operator may override — the receipt's own `entered_unit_cost` is authoritative for costing per M4's frozen formula, the PO's `unit_cost` is only a sensible default).
3. Renders the **existing** M4 receipt-detail editor (`render_detail()`, unchanged template shape) with these lines pre-populated and `po_id` carried as a query/hidden-field context for "add another line from this PO" — the operator can still add extra direct lines (making the receipt `'mixed'`) or lines from a *second* PO by using the product picker's "from PO line" mode again (§UI below), or remove/adjust any pre-filled line before saving the draft.
4. Saving this draft calls the **same** `Goods_Receipt_Service::create_draft_from_post()` M4 already built, now simply receiving `po_line_id` values in the submitted line data (§Class/service changes) — no new persistence method.

### Product/line picker — PO mode vs. direct mode

The existing M4 line-add UI (product picker restricted to simple/variation, INV-8) gains a second mode alongside "Direct" (M4's only mode today): **"From Purchase Order"** — a searchable list of open PO lines (product name, PO number, outstanding qty), scoped to `placed`/`partially_received`/`received` POs. Picking one pre-fills product, default qty (= outstanding), and sets `po_line_id`; picking "Direct" behaves exactly as M4 today (`po_line_id` stays `null`). Both modes may be mixed freely on one draft.

### Remaining-quantity display

Every PO-linked line in the receipt editor shows, next to the qty field: `Outstanding: 8.0000` (read at render time from `Purchase_Order_Lines::get()`, **not** cached/stale — always the current value, since another receipt could have posted against the same line moments ago). If the operator's entered qty exceeds this figure, an inline warning renders immediately (client-side arithmetic, mirrors M4's existing preview-before-apply pattern) — **this is advisory only**, not a form-blocking validation.

### Over-receipt confirmation (D5)

At the **post** confirmation screen (`post_confirm`, the same dedicated confirm-before-mutate screen M4 already built for every post), any line whose `qty > outstanding-at-preview-time` is called out explicitly: *"This line exceeds the current outstanding quantity by 2.0000 units. Posting will over-receive this PO line."* The confirm form requires no *additional* checkbox beyond the existing single post-confirmation submit (the confirm screen itself, already a deliberate extra step per M4's design, is the confirmation) — but the warning text is mandatory and cannot be suppressed. `Goods_Receipt_Service::post()` computes `over_receipt`/`qty_over` **at post time** (re-reading current outstanding inside the same pre-transaction validation pass, not trusting the draft-time snapshot, since outstanding may have shrunk further due to another receipt posting in the interim) and passes it to `PO_Receiving_Sync::apply_line_delta()` for the event-log record.

### Receive All / Receive multiple lines

"Receive against PO" pre-fills *every* outstanding line by default (§Entry point step 2) — there is no separate "Receive All" button; receiving all outstanding lines is simply the default un-edited path through the same single flow. Removing a pre-filled line from the draft before posting is how an operator does a genuinely partial receive (some lines now, rest later in a second receipt) — no special "partial mode" toggle exists, matching D5's framing that receipts naturally contain "only the lines actually received."

### PO completion indicator

PO detail page (list and detail) shows status pill values including the two new ones (`Partially Received`, `Received`), using the existing status-badge rendering M2 already built (`WC_Inventory_Overview_PO_Statuses::label()`, extended with the two new labels). Per-line remaining-quantity is shown in the PO's own line table (`Ordered 10 / Received 8 / Cancelled 0 / Outstanding 2`), reusing the existing line-row rendering with one new column.

### Navigation

- PO detail → each receiving-history entry links to the specific Goods Receipt (`Goods_Receipt_Admin::detail_url( $receipt_id )`, already exists).
- Goods Receipt detail → each PO-linked line links back to that PO (`PO_Admin`'s existing detail URL builder).
- Both link builders already exist in their respective admin classes; M5 only adds the cross-linking calls, no new URL-building infrastructure.

---

## Performance

Bulk receiving (one receipt, many PO-linked lines, or "Receive against PO" pre-filling a PO with many lines) must not introduce N+1 queries:

- **Entry-point pre-fill (step 2 above):** one `Purchase_Order_Lines::list_for_po( $po_id )` call (already bulk, already exists) — not one `get()` per line.
- **Post-time PO sync:** `PO_Receiving_Sync::apply_line_delta()` is called once per PO-linked line (unavoidable — each line may touch a different PO or a different line, and each write must be attributable/auditable per-line per INV-1/INV-7's "never merge independent records"). Within one call: one `increment_qty_received` UPDATE, one `list_for_po` SELECT (bulk, all lines for that PO in one query), one conditional `update_fields` UPDATE, one or two `PO_Events::add()` INSERTs. **When a receipt has multiple lines against the *same* PO**, this naively re-runs `list_for_po` once per line — bounded, not eliminated (see the measurable criterion below).

**Measurable acceptance criterion (replaces qualitative "acceptable at this scale" framing):** receiving a Purchase Order containing approximately **100 lines** in a single "Receive against PO" operation must execute without N+1 query growth — i.e., the repository query count for the PO-side sync work must remain **bounded and driven only by bulk operations** (one bulk `list_for_po`-style fetch per distinct PO touched, not one query per line for any read that can legitimately be batched), rather than scaling as a multiple of line count for anything that isn't inherently a required per-line write (the per-line `increment_qty_received`/`PO_Events::add()` calls are expected and unavoidable, per INV-1/INV-7 above — the guard is against *additional*, avoidable per-line reads, not against the necessarily-per-line writes). This plan does not specify an exact numeric query-count ceiling, since no existing repository method in this codebase documents one — the test (§Testing) instead asserts **linear, not superlinear, growth** as line count increases (mirroring M3's own query-scaling test pattern), which is the same shape of guarantee this plugin already enforces elsewhere without inventing a new performance-budget convention.
- **PO detail page's receiving-history panel:** one query — `SELECT receipt_lines.* , goods_receipts.receipt_number, goods_receipts.status FROM receipt_lines JOIN goods_receipts ... WHERE receipt_lines.po_line_id IN (<all this PO's line ids>)` (new repository method, `Receipt_Lines::list_for_po_line_ids( array $po_line_ids ): array`, mirroring M3's existing `list_open_lines_for_product_ids()` bulk-IN pattern) — not one query per PO line.
- **Repository responsibility:** bulk-by-IDs methods live on the repository (`Receipt_Lines`, `Purchase_Order_Lines`) exactly as M3 established; **service responsibility**: `PO_Receiving_Sync`/`Goods_Receipt_Service` never loop-and-query where a bulk repository method exists; **aggregation responsibility**: summing `qty_received`/`outstanding` across a PO's lines happens once per `apply_line_delta()` call over an already-fetched line array (in-PHP `array_sum`), never via a second per-line query.

---

## Testing

Directory convention (mirrors M3/M4): `tests/unit/po-receiving/`, `tests/integration/po-receiving/`. PHPUnit class prefix `Test_WC_IO_PO_Receiving_`, added to `tests/docker/run-phpunit.sh`'s blocking filter alongside the existing `Test_WC_IO_Goods_Receipt_` term.

**Required test files:**

- `tests/unit/po-receiving/test-po-quantities-full-formula.php` — `outstanding( ordered, received, cancelled )`'s full 3-arg INV-4 formula; every existing 2-arg call site updated and re-tested with `received=0` as a regression baseline (must equal the old 2-arg answer exactly).
- `tests/unit/po-receiving/test-po-statuses-recompute.php` — `recompute_for_receiving()` as a pure function: placed→partially_received (some received, some outstanding), placed→received (fully covered by one receipt), partially_received→received, received→partially_received (after a void), partially_received→placed (after a void that zeroes received back out), non-receiving statuses (draft/cancelled/closed_short) always pass through unchanged.
- `tests/unit/po-receiving/test-po-lifecycle-receiving-transitions.php` — the updated transition table: `cancel`/`close_short` still valid from `partially_received`, not valid from `received`; `partially_received`/`received` not `is_editable()`; auto-transitions are never exposed via `available_actions()` (no "Receive" *action button* pretending to be a lifecycle action — receiving is its own workflow, not a `PO_Lifecycle` action).
- `tests/integration/po-receiving/test-purchase-order-lines-increment.php` — `increment_qty_received()` in isolation: additive delta, negative delta (void), concurrent-UPDATE-safety characterization (two sequential deltas sum correctly), confirms it's the *only* place in the codebase writing this column (source-scan, see architecture guards below).
- `tests/integration/po-receiving/test-po-receiving-sync.php` — `PO_Receiving_Sync::apply_line_delta()` end-to-end against a real PO/line: single-line post (placed→partially_received), full-line post (placed→received), second line same PO (partially_received→received), void (received→partially_received, or →placed if it was the only contribution), event rows written with correct type/data, header status write skipped (no-op, no event) when status doesn't actually change. **Also covers `reconcile_line()`** in the same file: given a deliberately-drifted stored `qty_received`, asserts it computes the correct delta, writes through the same physical `increment_qty_received()` path, writes a `po_qty_received_reconciled` event (not `po_line_received`), and correctly recomputes header status exactly as `apply_line_delta()` would.
- `tests/integration/po-receiving/test-goods-receipt-service-po-linked-post.php` — `Goods_Receipt_Service::post()` with `po_line_id`-bearing lines: happy path (qty_received/PO status updated inside the same commit as stock); **forced-failure rollback** (PO sync insert fails mid-loop → stock mutation for that same line also rolls back, `qty_received` unchanged, PO status unchanged — the single most important test in this milestone, mirroring M4's own highest-priority rollback test); mixed receipt (one PO-linked line + one direct line, only the PO-linked one touches `qty_received`); multi-PO receipt (lines from two different POs in one receipt, both POs' statuses update independently and correctly); over-receipt (qty > outstanding, PO status still correctly reaches `received`, `qty_outstanding` floors at 0 via `GREATEST`, event carries `over_receipt:true`).
- `tests/integration/po-receiving/test-goods-receipt-service-po-linked-void.php` — symmetric to the post test; **the critical regression test** (mirroring M4's own intervening-receipt void test, one level up the stack): post receipt A against PO line X (qty 6 of 10 ordered) → post receipt B against the *same* PO line (qty 4, PO line now fully received, PO status `received`) → void A → assert PO line's `qty_received` is now exactly 4 (not 0, not negative), PO status correctly recomputes to `partially_received` (not `received`, not `placed`) — i.e., B's contribution survives A's void, exactly as M4 required for stock. **Additional mandatory scenario in the same file, run after the one above and kept alongside it (not a replacement):** post receipt A (qty 6) → post receipt B (qty 4, line now fully received) → **void receipt B** → void receipt A — asserting at every one of the four steps that `qty_received`, `qty_outstanding`, and PO status are all correct: after A posts, `received=6, outstanding=4, status=partially_received`; after B posts, `received=10, outstanding=0, status=received`; after voiding B, `received=6, outstanding=4, status=partially_received` (back to exactly A's contribution, not zero); after voiding A, `received=0, outstanding=10, status=placed` (back to the PO's original pre-receiving state). This exercises void-in-reverse-order specifically, the scenario the single A/B/void-A test above does not cover, and proves the current-state-relative delta composes correctly regardless of which receipt is voided first, not just that one specific order works.
- `tests/integration/po-receiving/test-po-receiving-validation.php` — pre-transaction rejection of receiving against `draft`/`cancelled`/`closed_short` POs (zero DB writes, cheap-check-before-transaction per M4's established discipline); product-mismatch rejection (submitted product doesn't match the referenced PO line's product).
- `tests/integration/po-receiving/test-receipt-lines-po-line-id.php` — `Receipt_Lines::create()` now correctly persists a non-null `po_line_id`; `update()` still refuses to change it post-creation (whitelist unchanged).
- `tests/unit/po-receiving/test-po-receiving-architecture.php` — the M5 architecture guard suite, source-scanning (comment-stripped, brace-matched — reusing M4's hardened extraction helper, not the naive regex M4 itself had to fix mid-implementation):
  - **Guard 1 (sole physical writer):** `increment_qty_received()` is called from exactly one file, `class-wc-inventory-overview-po-receiving-sync.php`, anywhere in `includes/` (both of `PO_Receiving_Sync`'s public methods funnel through it internally, but no *other* file may call it).
  - **Guard 2 (sole orchestrator of the receiving path):** `PO_Receiving_Sync::apply_line_delta()` is called from exactly one file, `class-wc-inventory-overview-goods-receipt-service.php` — i.e. `Goods_Receipt_Service` is the only caller of `PO_Receiving_Sync`'s receiving-path method anywhere in the codebase. This is verified as its own, separate assertion from Guard 1 (a naive test could pass Guard 1 while missing a second, unauthorized caller of `apply_line_delta()` that itself goes through `PO_Receiving_Sync` rather than around it).
  - **Guard 2b (sole caller of the reconciliation path):** `PO_Receiving_Sync::reconcile_line()` is called from exactly one file, the reconciliation CLI command file (§Reconciliation tooling) — never from `Goods_Receipt_Service` or anywhere else.
  - `Restock_Service::apply_purchase_line_change()`/`apply_purchase_line_reversal()` still have exactly the same caller set as M4 established — `PO_Receiving_Sync` must **never** appear in that caller list (proves M5 didn't accidentally create a second stock-mutation path).
  - No bare `WP_Error` return left inside `Goods_Receipt_Service`'s `run()` closures for any of the new `PO_Receiving_Sync`/`Purchase_Order_Lines`/`PO_Events` calls (extends M4's existing closure-scan test rather than duplicating it).
  - `PO_Receiving_Sync`'s own source contains no `begin()`/`commit()`/`rollback()`/`new WC_Inventory_Overview_DB_Transaction` call anywhere (proves it never opens its own transaction).
  - `wc_io_purchase_order_lines.status` (the per-line column) is never assigned a new value anywhere in M5's new files (proves the "no new per-line status" scope decision holds).
- **Explicitly revised M4 guards** (not silently left failing, not silently deleted — each gets a stated replacement): `test_po_line_id_never_populated()` → replaced with `test_po_line_id_populated_only_by_receipt_lines_create()` (asserts the column is now legitimately settable through exactly one path: `Receipt_Lines::create()`'s `$data['po_line_id']`, and nowhere else). `test_no_qty_received_in_m4_surface()` (M4's 9-file scan) → the M4 files themselves still must not reference `qty_received` directly (that stays true — only the *new* `Purchase_Order_Lines`/`PO_Receiving_Sync` files touch it), so this M4 test is **kept as-is, unchanged**, and a new, separate M5 test (`test_qty_received_written_only_by_increment_method`) covers the new files. `test_no_po_table_writes_in_m4_files()` → **kept as-is** for the original M4 file list (M4's `goods-receipt-service.php` now legitimately calls `PO_Receiving_Sync`, not the PO tables *directly* — the test's actual assertion, "no direct `WC_Inventory_Overview_Purchase_Orders::`/`Purchase_Order_Lines::`/`PO_Events::`/`PO_Service::` call," **still holds true** and needs no change, since `Goods_Receipt_Service` calls `PO_Receiving_Sync`, never those classes directly — confirmed by design). `test_no_m5_receive_against_po_functionality()` → **retired**, replaced by this milestone's own positive test suite (its entire premise, "M5 functionality doesn't exist yet," is now false by construction).
- Extend `tests/integration/install/test-schema-shape-assertion.php` — v9 assertion: `qty_received` present on `purchase_order_lines`, no longer in `forbidden_columns`; fresh-install-at-v9 and upgrade-from-v8 both produce identical schema; dispatcher-routing test (v9 doesn't silently fall through to v8's assertion, mirroring the exact trap M4 flagged for v8/v7).
- **Regression tests:** M3's Inventory Position "Incoming" figures — a new test posting a PO-linked receipt and asserting Incoming decreases by exactly the received quantity (this is the one behavior M3's own plan explicitly deferred to M5 and must now be positively verified, not just "not broken").
- **Performance test:** query-count-grows-linearly assertion for a receipt with N PO-linked lines against the same PO (§Performance).

---

## Quality gates

Extends M4's exact taxonomy (EXECUTED — PASS / FAIL / PASS WITH KNOWN PRE-EXISTING FAILURES / CONFIGURED — NOT EXECUTED / NOT APPLICABLE):

- PHP syntax lint, Composer validation, Docker Compose config
- Unit suite; M1–M5-focused blocking suite (filter gains `Test_WC_IO_PO_Receiving_`)
- Cumulative integration suite (must not add to the documented pre-existing failure list)
- PO-Receiving tests in isolation
- PHPCS (informational only, unchanged policy); actionlint if workflow files changed
- Schema v9 verification (`qty_received` present, no longer forbidden)
- **Sole-writer verification** (new, mandatory) — the architecture guards proving `qty_received`/PO-receiving-status have exactly one writer each
- **PO-linked rollback verification** (new, mandatory) — the forced-mid-transaction-failure test proving stock, `qty_received`, and PO status all roll back together
- **Intervening-receipt status regression** (new, mandatory) — the post-A/post-B/void-A test
- **M3 Incoming regression** (new, mandatory) — Inventory Position correctly reflects `qty_received` the moment it's introduced
- **M4 guard-revision audit** (new, mandatory) — confirms each M4 architecture test was either kept unchanged (with its assertion still true) or deliberately, visibly replaced — never silently deleted or silently broken
- Release ZIP build and inspection; git diff review against v1.21.0; working-tree verification

Any new test failure introduced by M5 is a release blocker.

---

## Documentation

1. `docs/milestones/m5-implementation-plan.md` (this document, once approved and materialized)
2. `CLAUDE.md` milestone status row updated to Complete only **after** implementation
3. `docs/checklists/validation-checklist.md` — new "For M5" subsection, extended into a positive, release-blocking deployment/operational validation checklist (mirrors M4's own deployment-validation precedent, not just a development-time smoke check). Must explicitly require, on the target environment, before M5 is signed off as deployed:
   - **Receive against PO** — a real receipt posted against a real, previously-placed PO line, end-to-end through the admin UI.
   - **Partial receipt** — a receipt covering less than a PO line's full outstanding quantity; PO status correctly reads `Partially Received`; `qty_outstanding` correctly reflects the remainder.
   - **Complete receipt** — a receipt (or the sum of several) that brings a PO line's outstanding to exactly zero; PO status correctly reads `Received`.
   - **Void one receipt** — voiding a posted PO-linked receipt and confirming `qty_received`/PO status walk back down correctly (not just that the void "succeeds").
   - **Inventory Position updates correctly after every one of the above operations** — the M3 "Incoming" figure for the affected product/variation is re-checked after the partial receipt, the complete receipt, and the void, confirming it tracks `qty_outstanding` at each step, not just at the end (the WP8 regression fix, §Class/service changes, verified live).
   
   General-case items retained from the original scope: qty_received/status recompute correctly under multi-receipt and void scenarios generally; over-receipt is possible-and-audited (not blocked); M4's Quick Receive path still works unmodified.
4. `docs/release-runbook.md` — new "M5: PO Receiving" subsection mirroring M4's pattern, plus a step verifying the schema-v9 dispatcher routes correctly (the concrete trap called out in §Database)
5. `docs/testing.md` — new test directories, updated counts
6. `CHANGELOG.md` — v1.22.0 entry
7. `readme.txt` and all repository version references
8. `docs/architecture-audit.md` — new M5 section: schema v9, `PO_Receiving_Sync` design, sole-writer enforcement, status-recompute function, audit-trail closure (cross-referencing M4's Audit-trail decision point 6 as now fulfilled)
9. `docs/GITHUB_RELEASE_NOTES_1.22.0.md` — created proactively, before tagging
10. `tests/docker/run-phpunit.sh` — blocking filter gains `Test_WC_IO_PO_Receiving_`
11. `docs/rollback-plan.md` — new note, parallel to M4's: a code rollback to pre-M5 does not reverse `qty_received`/PO-status effects of receipts already posted under M5 (same risk class M4 introduced for stock, now extended to the PO domain)

---

## Implementation sequence

- **WP1 — Schema v9.** `qty_received` column, `expected_schema_v9()` + dispatcher fix, `forbidden_columns` update, `DB_VERSION` bump. *Validation:* schema-shape assertion extended and passing, fresh-install and upgrade-from-v8 both verified. *Dependencies:* none.
- **WP2 — `PO_Quantities` full formula + `Purchase_Order_Lines::increment_qty_received()`.** *Validation:* WP2 unit/integration tests pass, including the 2-arg-vs-3-arg regression baseline. *Dependencies:* WP1.
- **WP3 — `PO_Statuses`/`PO_Lifecycle` receiving states.** New constants, `recompute_for_receiving()`, transition table update. *Validation:* pure-function and transition-table unit tests pass. *Dependencies:* none (pure logic, no schema dependency).
- **WP4 — `PO_Events` new types.** Four new constants. *Validation:* existing PO-events tests still pass unmodified; new types accepted by `add()`. *Dependencies:* none.
- **WP5 — `PO_Receiving_Sync`.** The new orchestrator class, both `apply_line_delta()` and `reconcile_line()`. *Validation:* `test-po-receiving-sync.php` passes end-to-end against real PO/line rows for both methods (not yet wired into `Goods_Receipt_Service` or the CLI). *Dependencies:* WP2, WP3, WP4.
- **WP6 — `Receipt_Lines::create()` po_line_id support.** *Validation:* repository test confirms persistence + `update()` still refuses it. *Dependencies:* none (schema already present from M4).
- **WP7 — `Goods_Receipt_Service` integration.** Pre-transaction validation, `derive_source()`, per-line `PO_Receiving_Sync::apply_line_delta()` calls inside `post()`/`void()`. *Validation:* full `test-goods-receipt-service-po-linked-post.php`/`-void.php` suites pass, including the forced-failure rollback test and the intervening-receipt regression test. *Dependencies:* WP5, WP6.
- **WP8 — M3 Incoming regression fix.** Update the raw-SQL `GREATEST()` literal in the M3 open-lines repository methods to the 3-term formula. *Validation:* M3 Incoming regression test passes; existing M3 test suite still green. *Dependencies:* WP1 (needs the column to exist to be meaningful, though the SQL change itself could land earlier — sequenced here to keep it next to its own dedicated regression test).
- **WP9 — Architecture-guard tests.** Sole-writer guards, revised/kept M4 guards, retired `test_no_m5_receive_against_po_functionality()`. *Validation:* guard tests pass against the real WP1–WP8 code; each M4 guard's disposition (kept/revised/retired) individually verified, not assumed. *Dependencies:* WP1–WP8 complete.
- **WP10 — Admin UI.** "Receive" entry point on PO detail, PO-mode product picker, remaining-quantity display, over-receipt warning, receiving-history panels (both directions), status labels/badges. *Validation:* capability tests pass; manual smoke test of the full operator workflow on a real dev environment. *Dependencies:* WP7.
- **WP11 — `qty_received` reconciliation CLI tool.** Read-only check by default, `--fix` to repair via `PO_Receiving_Sync::reconcile_line()`, per-line logging, verified/repaired summary counts (§Reconciliation tooling). *Validation:* unit-tested against deliberately-drifted fixture data, including a dry-run-makes-zero-writes assertion and a `--fix`-writes-exactly-the-expected-delta assertion. *Dependencies:* WP2, WP5.
- **WP12 — Documentation & release preparation.** All items in §Documentation. *Validation:* every quality gate individually classified; release ZIP builds and inspects clean; `docs/GITHUB_RELEASE_NOTES_1.22.0.md` exists before any tagging is attempted. *Dependencies:* WP1–WP11 complete.

---

## Git guidance

Mirrors M3/M4's precedent — small, single-purpose commits, not one large commit:

1. Schema v9 (`install.php`, dispatcher fix)
2. `PO_Quantities` full formula + `increment_qty_received()`
3. `PO_Statuses`/`PO_Lifecycle` receiving states
4. `PO_Events` new types
5. `PO_Receiving_Sync`
6. `Receipt_Lines` po_line_id support
7. `Goods_Receipt_Service` PO-linked post/void integration
8. M3 Incoming regression fix
9. Architecture-guard tests (can land with #7 if tightly coupled, acceptable deviation)
10. Admin UI
11. Reconciliation CLI tool
12. Documentation and release prep

Do not merge, push, tag, or deploy as part of the implementation task itself — implementation branch left committed, clean, unpushed, ready for independent audit, mirroring M3/M4's precedent exactly.

---

## Risk review

| Category | Risk | Mitigation |
|---|---|---|
| **Transaction** | `PO_Receiving_Sync` failure escapes `Goods_Receipt_Service`'s `Exception`-only catch, committing a stock mutation without its matching `qty_received` credit | Every call wrapped in the existing `throw_if_error()` bridge; architecture guard scans for bare `WP_Error` returns in the new call sites specifically |
| **Transaction** | Assuming `PO_Receiving_Sync` needs its own transaction "for safety," accidentally nesting a second `begin()/commit()` inside `Goods_Receipt_Service`'s `run()` closure | Explicit design rule stated in §Receiving-status ownership: the class never opens a transaction; architecture guard scans its source for `DB_Transaction`/`begin`/`commit`/`rollback` |
| **Status correctness** | Naive status recompute that looks at *direction* (post vs. void) instead of *current totals* drifts on multi-receipt scenarios | `recompute_for_receiving()` is deliberately direction-agnostic (current-state-relative, same principle as M4's void correctness); dedicated intervening-receipt regression test |
| **Status correctness** | A void on a fully-received PO doesn't correctly walk status back to `partially_received` or `placed` depending on what's left | Explicit three-way branch in the recompute function (`received`/`partially_received`/`placed`), tested for all three outcomes |
| **Audit** | Double-counting: both a Goods Receipt movement row *and* a duplicate PO-side ledger entry describing the same physical event | Explicitly rejected in §Audit model — the PO event log records the *commitment* fact, the movement ledger records the *physical* fact; no new schema duplicates the other |
| **Over-receipt** | Implementer reflexively adds a hard cap "to be safe," silently violating D5 | Called out explicitly in §Scope-boundary ruling and §Receiving workflow as a **frozen architecture constraint**, not an implementation choice |
| **M3 regression** | `Inventory Position`'s "Incoming" figure silently overstates supply once `qty_received` exists, because M3's raw SQL literal still only subtracts `qty_cancelled` | Explicit named fix (WP8) with its own dedicated regression test — flagged as the one required touch to M3-owned code |
| **Test-suite integrity** | M4's architecture guards (`test_po_line_id_never_populated`, `test_no_m5_receive_against_po_functionality`, etc.) are designed to fail the moment M5 lands — a careless implementer silently deletes or weakens them instead of deliberately revising each one | §Testing explicitly enumerates every M4 guard's disposition (kept unchanged / revised with a named replacement / retired with a named replacement) — "M4 guard-revision audit" is its own mandatory quality gate |
| **Migration/rollback** | A code rollback to pre-M5 doesn't reverse `qty_received`/PO-status effects of receipts already posted — new risk class for the PO domain (M2–M4 never had this for POs specifically) | New `docs/rollback-plan.md` note, parallel to M4's existing stock-effect warning |
| **Scope creep** | Implementer starts storing a per-line "received" status on `wc_io_purchase_order_lines.status`, duplicating what's already derivable from `qty_outstanding == 0` | Explicit scope decision in §Scope-boundary ruling; architecture guard asserts the column is never assigned a new value by M5 code |
| **Performance** | Bulk receiving against a PO with many lines triggers N+1 queries in the PO detail page's receiving-history panel or in per-line PO sync | New bulk repository method (`Receipt_Lines::list_for_po_line_ids()`); linear-query-growth regression test |

---

## Critical review

**Hidden coupling.** `Goods_Receipt_Service` now depends on three PO-domain classes it didn't touch before (`Purchase_Order_Lines`, `Purchase_Orders`, `PO_Events`, via `PO_Receiving_Sync`). This is real, new coupling — but it's the *entire point* of M5 (orchestration connecting the two domains) and is deliberately confined to one narrow, single-method intermediary class rather than scattered `PO_*::` calls throughout `Goods_Receipt_Service` itself — confirmed by the architecture guard requiring `PO_Receiving_Sync` to be the *only* file calling `increment_qty_received()`, and `Goods_Receipt_Service` to be the *only* file calling `PO_Receiving_Sync`.

**Milestone leakage — checked against M6/M7/M8.** No Batch Intake migration logic (M6), no storefront exposure of receiving data (M7), no hardening/hygiene work (M8) appears anywhere in this plan. `wc_io_purchase_batches*` is never referenced. **Pass.**

**Duplicate responsibilities — the one to watch hardest.** `PO_Service` already has line-update/event-authorship machinery (`update_line()`, `plan_header_event()` etc.) that superficially resembles what `PO_Receiving_Sync` needs to do. The temptation is to route through `PO_Service` "since it already does this." **Deliberately rejected** (§Receiving-status ownership, "Rejected approach") because `PO_Service`'s transaction model is incompatible with being nested inside `Goods_Receipt_Service`'s `run()` closure, and its methods are operator-intent-shaped, not delta-shaped. `PO_Receiving_Sync` duplicates a *small* amount of "write status, write event" shape from `PO_Service` — this is the same class of accepted, justified debt M4 accepted for `Goods_Receipt_Costing` duplicating `Batch_Intake_Service`'s landed-allocation formula, and for the same underlying reason (the existing implementation's internals aren't safely reachable from the new transaction context).

**Future migration problems.** `wc_io_purchase_order_lines.qty_received` and the four new `PO_Events` types are additive; nothing here needs to change shape for M6 (legacy migration never touches PO-linked receiving — M4's Quick Receives being migrated to posted Goods Receipts have no PO relationship, `po_line_id` stays `NULL` for all of them, unaffected by M5's column). M7 (storefront) would read `qty_outstanding`/Inventory Position, already correctly updated by WP8 — no further change anticipated.

**Performance risks.** Addressed in §Performance; the one accepted non-optimization (`list_for_po` re-run once per PO-linked line within a single multi-line-same-PO receipt) is explicitly bounded by a linear-growth test rather than engineered away, consistent with this plugin's stated low-volume, single-warehouse business context (same proportionality judgment M4 made for its own performance section).

**Audit weaknesses.** The one soft spot: `over_receipt`/`qty_over` is computed and stored only inside the PO event's `data` JSON blob (not a queryable column) — acceptable, because M2 already treats `PO_Events.data` as the append-only detail store for every other per-line fact (unit cost changes, quantity changes), and no other M2 event type has a dedicated column per fact either; consistent with existing precedent, not a new weaker pattern.

**Implementation shortcuts explicitly forbidden (binding):**
- No calling `Restock_Service`'s mutators, `Purchase_Order_Lines::increment_qty_received()`, or `PO_Receiving_Sync::apply_line_delta()` from anywhere except their one designated caller.
- No hard-blocking over-receipt "to be safe" — D5 is explicit and this plan may not reinterpret it.
- No storing a new per-line status value on `wc_io_purchase_order_lines.status`.
- No giving `PO_Receiving_Sync` its own transaction.
- No adding a header-level `po_id` column to `wc_io_goods_receipts` "for convenience" — D6 remains line-level-only linkage, unchanged from M4.
- No silently deleting or weakening an M4 architecture guard test without a named, deliberate replacement (§Testing).

---

## Definition of Done

- [ ] Schema v9: `qty_received` column on `wc_io_purchase_order_lines`, `expected_schema_v9()` + dispatcher fix, `forbidden_columns` entry removed, `DB_VERSION = '9'`.
- [ ] `PO_Quantities::outstanding()` implements the full 3-term INV-4 formula; every call site (including M3's raw-SQL `GREATEST()` literals) updated consistently.
- [ ] `Purchase_Order_Lines::increment_qty_received()` is the sole writer of that column anywhere in the codebase (architecture-guard enforced).
- [ ] `PO_Statuses` gains `partially_received`/`received`; `recompute_for_receiving()` is pure, direction-agnostic, and correctly handles all six transition directions (up and down).
- [ ] `PO_Lifecycle::transitions()` updated; `cancel`/`close_short` remain valid from `partially_received`, not from `received`; neither new status is editable.
- [ ] `PO_Events` gains four new types; existing 12 unchanged; `add()` signature unchanged.
- [ ] `PO_Receiving_Sync` implemented: exactly two public methods (`apply_line_delta()` called only from `Goods_Receipt_Service`; `reconcile_line()` called only from the reconciliation CLI), neither opens a transaction of its own, every fallible call inside `apply_line_delta()` wraps in `Goods_Receipt_Service`'s `throw_if_error()`.
- [ ] The `qty_received`/PO-status sole-mutator invariant holds with exactly one named exception (the reconciliation CLI's `reconcile_line()` path), itself architecture-guard-enforced to have exactly one caller.
- [ ] Numeric completion comparisons (`qty_outstanding == 0` and equivalents) use rounded 4-decimal values throughout, never raw PHP float equality.
- [ ] The Post-A/Post-B/Void-B/Void-A rollback sequence (in addition to the Post-A/Post-B/Void-A regression) passes with correct `qty_received`/outstanding/status at every step.
- [ ] `Receipt_Lines::create()` accepts and persists `po_line_id`; `update()` still refuses to change it.
- [ ] `Goods_Receipt_Service::post()`/`void()` correctly call `PO_Receiving_Sync` per PO-linked line, inside the same transaction as the stock mutation; `derive_source()` correctly computes `direct`/`po`/`mixed`; pre-transaction validation rejects receiving against non-receivable PO statuses with zero DB writes.
- [ ] Forced-failure test proves full rollback: stock, `qty_received`, and PO status all revert together when any step in the loop fails.
- [ ] Intervening-receipt regression test (post A, post B same PO line, void A, assert B's contribution and correct status survive) passes.
- [ ] Over-receipt is possible, produces a clear pre-post warning, and is captured in the PO event log with `over_receipt`/`qty_over` — never rejected by the service layer.
- [ ] M3's Inventory Position "Incoming" figure correctly decreases as receipts post against PO lines (WP8 regression test passes).
- [ ] Admin UI: "Receive" entry point from PO detail, PO-mode product picker, remaining-quantity display, over-receipt warning at confirm, receiving-history panels on both PO and Receipt detail pages, bidirectional navigation.
- [ ] `qty_received` reconciliation CLI tool exists, defaults to read-only, writes only with `--fix` (via `reconcile_line()`), logs every repaired line individually, reports verified/repaired summary counts, and is tested against deliberately-drifted fixture data.
- [ ] Every M4 architecture guard's disposition (kept unchanged / revised with named replacement / retired with named replacement) is individually verified — none silently broken or silently deleted.
- [ ] All required unit, integration, architecture-guard, and performance tests exist and pass; M0–M4 golden/characterization fixtures unchanged.
- [ ] `tests/docker/run-phpunit.sh` blocking filter includes the PO-Receiving test prefix.
- [ ] No new stock/cost mutation path introduced — `Restock_Service`'s caller set is unchanged from M4 plus zero new entries.
- [ ] No header-level `po_id` added to `wc_io_goods_receipts`; no new per-line status value on `wc_io_purchase_order_lines.status`.
- [ ] All 11 documentation deliverables complete, including `docs/GITHUB_RELEASE_NOTES_1.22.0.md` existing before any tag is attempted, and the new `docs/rollback-plan.md` PO-domain note.
- [ ] All quality gates executed and individually classified; the new sole-writer/rollback/intervening-receipt/M3-regression/guard-revision-audit gates all PASS outright (not eligible for "known pre-existing failure" status).
- [ ] Version prepared as `1.22.0`; not tagged, not released.
- [ ] Implementation branch left committed, clean, unpushed, unmerged, ready for independent audit.

---

## Implementation freeze statement

No implementation may introduce additional PO statuses, a second `qty_received` writer, a new stock/cost mutation path, a header-level PO↔receipt link, or receiving behavior beyond what this plan defines, unless this plan is formally revised and re-approved — mirroring the discipline carried through M1–M4. `Goods_Receipt_Service` remains, permanently, the sole orchestrator of every inventory mutation this plugin's purchasing domain performs (M4's Service ownership freeze, unmodified); M5 is the first, and this plan asserts the *only necessary*, additive extension of it for PO-linked receiving. Any future milestone needing to change `PO_Receiving_Sync`'s design, the status-recompute function, or the over-receipt policy is proposing a revision to this plan, not an extension of it.

**Forward-looking rule for every future receiving mechanism, not only M6–M8:** any future milestone introducing ASN (Advance Shipment Notice) workflows, barcode receiving, warehouse scanning, receiving automation, bulk import of receipts, or any other new *mechanism* for getting a receiving event into the system, must build on `Goods_Receipt_Service` and `WC_Inventory_Overview_PO_Receiving_Sync` exactly as this plan's own admin UI does (§Receiving workflow) — supplying inputs to the existing `post()`/`void()`/`apply_line_delta()` machinery, never re-implementing stock mutation, `qty_received` maintenance, or PO status recomputation alongside it. No alternative receiving pipeline may ever be introduced, in this milestone or any later one, regardless of how different its input mechanism (a scanner, an API import, a supplier ASN feed) looks from a human filling in an admin form — the *mechanism* by which a receiving event is initiated is free to vary; the *engine* that turns it into a stock/cost/qty_received mutation is not.

---

## READY FOR REVIEW

Every open design question was resolved with one explicit, justified answer — the qty_received/status ownership model (the hardest question, resolved in §Receiving-status ownership by direct structural analogy to M4's own void-correctness resolution), the D5 over-receipt tension, the M3 Incoming regression M3's own plan deferred here, the schema shape (confirmed requiring exactly one column addition, zero new tables — M4 having already prepared `po_line_id`/`source` for this moment), the audit-trail closure M4 explicitly reserved for this milestone, and the full non-goals list. No section of this plan leaves two options on the table for an implementer to choose between.

This plan is **READY FOR REVIEW.**
