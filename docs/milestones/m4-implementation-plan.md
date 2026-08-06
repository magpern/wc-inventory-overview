# Context

This is a **planning-only** deliverable for `/opt/biopentra/dev/wc-inventory-overview` (WordPress/WooCommerce plugin "WC Inventory Overview", currently v1.20.0, schema `DB_VERSION = 7`, M0–M3 released). No repository files are modified by this task. The user has asked for the definitive implementation plan for **Milestone M4 — Receive Stock** (the Goods Receipt engine), following the same materialization discipline used for M1/M2/M3: a fully-specified plan document is produced and approved *before* any code is written.

M4 is explicitly called out by the user as "the highest-risk milestone in the roadmap" because it is the **first** milestone that mutates WooCommerce stock and weighted-average cost through this plugin (D3/INV-2) — every prior milestone (M1 Suppliers, M2 Purchase Orders, M3 Inventory Position) was either schema-additive-only or strictly read-only. This plan was produced after exhaustive, direct verification of the current codebase (schema, transaction helper, existing costing mutator, movement ledger, PO lifecycle/quantities, event log, numbering/idempotency helpers, capability map, test/CI/release infrastructure) — every concrete fact below (method signatures, column types, formulas) was confirmed by reading the actual source, not inferred. The one genuinely open design risk found during this research — how "void" must behave correctly when other receipts have posted against the same product in between — is resolved explicitly in §Inventory mutation / §Critical review, not left as an implementation-time decision.

Below is the complete plan content, structured to satisfy every section the user's directive requested.

---

# Milestone M4 Implementation Plan — Receive Stock (Goods Receipt Engine)

**Status:** Draft — freshly authored, not yet human-reviewed. Target release **v1.21.0** on a future `feature/m4-receive-stock` branch.

**Prerequisite:** v1.20.0 (M3 Inventory Position) on schema v7.

**Architecture context:** [`CLAUDE.md`](../../CLAUDE.md) Part I §1–§5, specifically **D1–D7, D9, D10, D14, D15, D18, D19** and **INV-1, INV-2, INV-4, INV-6, INV-7, INV-8**. Roadmap context: Implementation Status table, M4 row ("Receipt Engine... Goods Receipt as stock mutator, Quick Receive").

---

## Authoritative specifications

Binding, frozen — nothing below may contradict these:

- Architecture v1.0 (`CLAUDE.md` Part I, §1–§5 — the only sections with committed body text; §6–§20 are stub placeholders per CLAUDE.md's own closing note and are **not** authoritative content, only section-number references)
- Delivery Roadmap v1.0 (`CLAUDE.md` Part II — Implementation Status table is the authoritative baseline; the detailed R1–R9 body was never committed)
- `CLAUDE.md` in full, as currently checked in
- Released M0 (v1.17.3), M1 (v1.18.0), M2 (v1.19.0)
- Released v1.19.1 (Infrastructure Hotfix)
- Released M3 (v1.20.0)

---

## Objective

Produce a complete, unambiguous engineering specification for the Goods Receipt engine so that implementation can proceed with **zero further architectural decisions**. Receiving must be deterministic, transactional, auditable, rollback-safe, and architecture-compliant. Receiving must never partially succeed: either everything a posting or voiding operation touches commits, or all of it rolls back.

---

## Primary design goals

| Goal | How this plan achieves it |
|---|---|
| Deterministic | One formula (the existing weighted-average formula), one code path (`Goods_Receipt_Service`), reused unmodified from `Restock_Service` |
| Transactional | Every stock/cost/movement/status write for a single post or void happens inside one `WC_Inventory_Overview_DB_Transaction::run()` closure |
| Auditable | Immutable header timestamps/actors, typed `reference_type`/`reference_id` movement rows, `receipt_number` never reused |
| Rollback-safe | Real SQL `ROLLBACK` (not hand-rolled compensating undo like today's Batch Intake) via a mandatory `WP_Error`→`Exception` bridging pattern (§Transaction model) |
| Architecture-compliant | D3/INV-2 (sole stock mutator), INV-8 (variation, never parent), D6/D7 (line-level PO linkage, direct receipts first-class) all enforced structurally, not by convention |

---

## Scope

### Scope-boundary ruling (read first — resolves a real ambiguity in the source docs)

The Implementation Status table glosses M4 as *"Goods Receipt as stock mutator, Quick Receive"* and M5 as *"Receive-Against-PO, PO line completion."* But `docs/architecture-audit.md`, the M2 plan, and M2's CHANGELOG entry all separately say *"`qty_received`, Goods Receipt, and Receive-Against-PO arrive in M5."* These are reconciled as follows, **decided, not left open**:

> **M4 builds the Goods Receipt engine and "Quick Receive Without PO" (D7) only — direct receipts with `po_line_id` always `NULL`. M4 does not build PO-linked receiving, `qty_received`, or any PO-side status/outstanding-quantity change. Those are M5's "Receive-Against-PO, PO line completion."**

Justification (source-verified, not inferred):
- `class-wc-inventory-overview-install.php` line 192 (production code, not just docs): `// M2: Purchase Orders (schema v7). No receiving columns — qty_received arrives in M5.` — the schema-shape guard author's own stated intent.
- `docs/architecture-audit.md` independently states `inventory_movements` gains `reference_type`/`reference_id`/`supplier_id` **in M4** — confirming M4 is expected to be a real, movement-writing, stock-mutating milestone (i.e. Quick Receive really does ship in M4, it isn't deferred further).
- D6 defines PO↔receipt linkage **only** at line level (`receipt_line.po_line_id`); there is no header-level `po_id` anywhere in the entity model. "Receiving against a PO" can only mean populating `po_line_id` — the same column M2's pattern already protects (`qty_received`) until its dependent milestone ships.

**Consequence:** `wc_io_receipt_lines.po_line_id` **exists** in M4's schema (nullable, indexed) so M5 needs no second migration — but no M4 code path ever sets it; the M4 service's line-creation method has **no `po_line_id` parameter in its signature at all** (not a runtime-ignored optional arg — the possibility doesn't exist). The M2 `forbidden_columns` guard for `qty_received` on `wc_io_purchase_order_lines` is carried forward unchanged; M4 never touches that table.

### In Scope

1. New Goods Receipt entity: header, lines, landed costs (schema, repositories, lifecycle `draft → posted → voided`).
2. "Quick Receive Without PO" (D7): direct receipts, `po_line_id` always `NULL`.
3. Posting: the transactional stock + weighted-average-cost mutation (reusing the existing `Restock_Service::apply_purchase_line_change()` formula), typed Inventory Movement rows.
4. Voiding: transactional, current-state-relative reversal (see §Inventory mutation).
5. Landed cost allocation on receipts (ported, unchanged formula from Batch Intake).
6. Idempotent posting/voiding (reused `PO_Request_Token` pattern + compare-and-swap status update).
7. Admin UI: "Receive Stock" sub-view (draft list/detail, product picker, Post/Void actions).
8. Schema v8, `expected_schema_v8()`, new `inventory_movements` columns.
9. Full test suite, documentation, release preparation for v1.21.0.

### Out of Scope (this milestone does not build these; see also Explicit Non-Goals)

- Anything requiring `po_line_id` to be populated.
- Any PO-side status or quantity change.
- Legacy Batch Intake → Goods Receipt migration (explicitly **M6**, "Migration & Retirement").

### Explicit Non-Goals

- Receive-Against-PO UI/workflow; no PO picker anywhere on the receipt line screen.
- Populating `receipt_line.po_line_id` (column exists, always `NULL`).
- `qty_received` on `wc_io_purchase_order_lines`; the M2 forbidden-column guard stays enforcing, unmodified.
- Any PO-side status change (`partially_received`/`received`); `WC_Inventory_Overview_PO_Lifecycle` untouched.
- Any receiving-related **PO event** types — a Quick Receive has no PO relationship in M4 (no header `po_id`, no line `po_line_id`), so there is nothing to log an event against.
- Reconciling M3's "Incoming"/`qty_outstanding` against receipts — M3's Incoming figure is **unaffected** by M4 (it only changes once `qty_received` exists, in M5). Stated explicitly so nobody expects Inventory Overview's Position column to move when a Quick Receive posts.
- Legacy Batch Intake / Quick Restock — unmodified, stay fully live; M4 never reads, writes, or migrates `wc_io_purchase_batches*`.
- "Post-hoc" landed costs added to an already-posted receipt (the `post_hoc` column exists per §5.1's entity model, always written `0` in M4; no service/UI support).
- Inbound Shipment entity, carrier/tracking, revised-delivery-date fields (D10 — lifecycle is `draft → posted → voided` only, nothing else).
- New REST endpoints (D16 — service methods + WP hooks only, matching every prior milestone).
- Row-level locking, distributed locks, or queue-based write serialization (see §Idempotency & concurrency).
- Storefront changes (M7). Dashboard KPI changes. `wc_io_receipt_events` append-only audit table (evaluated and explicitly rejected — see §Audit-trail decision).

### Dependencies

- `WC_Inventory_Overview_DB_Transaction` (built M0, "inert until M4" — this is its first real consumer besides the PO service).
- `WC_Inventory_Overview_Restock_Service::apply_purchase_line_change()` (existing shared stock/cost mutator, reused unmodified).
- `WC_Inventory_Overview_Costing` (meta-key accessors, reused unmodified).
- `WC_Inventory_Overview_Movements` (extended, not replaced).
- `WC_Inventory_Overview_PO_Request_Token` (reused directly with new context strings).
- `WC_Inventory_Overview_Purchasing_Caps` (extended with new action-key constants).
- `WC_Inventory_Overview_Exchange_Rates::get_exchange_rate_to_eur()`, `WC_Inventory_Overview_Settings::allow_zero_supplier_cost()` (reused unmodified).
- `WC_Inventory_Overview_Suppliers` (read-only lookup for the optional supplier picker).

### Architecture constraints

- D3/INV-2: only Goods Receipt posting/voiding may mutate stock or weighted-average cost, in this plugin's purchasing domain.
- **Single inventory mutation entry point (binding, elevated from implementation detail to explicit architecture rule — see §Transaction model):** `Goods_Receipt_Service` is the *only* write path through which M4 inventory mutation may occur. No controller, repository, admin page, AJAX handler, REST endpoint, CLI command, or UI component may call `Restock_Service` directly.
- D6/D7: line-level PO linkage only; direct receipts first-class; no synthetic POs ever fabricated.
- D10: lifecycle is exactly `draft → posted → voided`; no other states.
- D18: internal classes `Goods_Receipt*`; UI text "Receive Stock" / "Quick Receive Without PO".
- D19/INV-8: receipt lines reference the purchasable item (variation for variable products) — never a variable parent. Enforced by reusing `Restock_Service`'s existing product-type guard, plus UI-layer defense in depth.
- INV-1/INV-7: receipt lines are independent, never merged; presentation aggregation (if any) never destroys per-line identity.
- INV-6: auditability via immutable posted/voided timestamps+actors and typed movement rows (resolved and closed — see §Audit-trail decision).

### Implementation constraints

- No schema/behavior changes to `wc_io_purchase_orders`, `wc_io_purchase_order_lines`, `wc_io_po_events`, `WC_Inventory_Overview_PO_Lifecycle`, `WC_Inventory_Overview_PO_Quantities`, `WC_Inventory_Overview_PO_Delay`.
- No changes to `wc_io_purchase_batches*` tables or `Batch_Intake_Service`/`Batch_Intake_UI` (stay live, unmodified, until M6).
- No changes to M3's `Inventory_Position_Resolver`/`_Service` (On Hand auto-reflects stock mutation on next read; Incoming is untouched under the scope ruling above).
- `DB_Transaction::run()` only catches `Exception`, not `Throwable`/`WP_Error` — every fallible call inside a posting/voiding transaction closure **must** be routed through a `throw_if_error()` bridge (binding rule, §Transaction model).

### Success criteria

- A Quick Receive can be created as a draft, edited freely, and either posted (mutating stock/cost atomically, writing typed movements) or deleted while still a draft.
- A posted receipt can be voided, correctly reversing *only its own* contribution regardless of what other receipts posted in between, or is cleanly rejected (never partially reversed) if intervening stock consumption makes that impossible.
- No forced mid-transaction failure (movement insert, second-line mutation, status race) ever leaves stock, cost meta, or movement rows in a partially-applied state.
- Double-submitting a post or void (refresh/back-button/network retry) never double-applies.
- All existing M0–M3 tests and quality gates remain green; PHPCS/CI/release-ZIP framework extended, not redesigned.

---

## Core responsibilities

This plan fully specifies every item below (cross-referenced to the section that owns it):

| Responsibility | Owned in |
|---|---|
| Goods Receipt (entity, lifecycle) | §Database, §Receiving workflow |
| Receive Against Purchase Order | **Not in M4** — see Scope-boundary ruling |
| Partial receipts / Complete receipts | §Receiving workflow ("Partial vs. complete," clarified below) |
| Average-cost updates | §Costing |
| Inventory movements | §Inventory mutation, §Database |
| Purchase Order updates | **None in M4** — no PO table is touched |
| Inventory Position updates | §Implementation constraints (On Hand auto-reflects; Incoming untouched) |
| Audit trail | §Inventory mutation, §Audit-trail decision (resolved) |
| Transaction boundaries | §Transaction model |
| Failure handling / Recovery | §Transaction model, §Risk review |
| Idempotency | §Idempotency & concurrency |
| Concurrency expectations | §Idempotency & concurrency |

---

## Transaction model

### Single inventory mutation entry point (binding architectural invariant)

**Every inventory mutation introduced by M4 must pass through exactly one public orchestration service: `WC_Inventory_Overview_Goods_Receipt_Service`.** This is stated here as an explicit, binding architecture rule — not merely an implied consequence of how `post()`/`void()` happen to be written.

- No controller, repository, admin page, AJAX handler, REST endpoint, CLI command, or UI component may call `WC_Inventory_Overview_Restock_Service::apply_purchase_line_change()` or `apply_purchase_line_reversal()` directly.
- `Goods_Receipt_Service` is the only caller of those two `Restock_Service` methods anywhere in M4's code.
- This is enforced structurally by WP9's architecture-guard test (source-scanning every M4 file except `Goods_Receipt_Service` itself for calls to those two method names) — the same enforcement mechanism M3 used for D12's sole-calculator rule, applied here to D3/INV-2's sole-mutator rule.
- This rule already appears as a forbidden shortcut in §Critical review; it is restated here, at the top of the Transaction model, as the primary invariant the rest of this section is built on — not an afterthought.

**Repository-write ownership (strengthened — every persistence operation, not just the costing call, is in scope):** during posting and voiding, `Goods_Receipt_Service` is the exclusive orchestrator of *every* write the operation performs, not only the stock/cost mutation. No controller, repository, admin page, AJAX endpoint, REST endpoint, CLI command, UI component, or future integration may directly persist, outside the one transaction `Goods_Receipt_Service` controls:
- receipt status
- receipt lines
- inventory movements
- WooCommerce stock
- inventory value
- average cost
- product meta

Repository classes (`Goods_Receipts`, `Receipt_Lines`, `Receipt_Costs`) retain their existing role as thin persistence helpers (§Class/service design) — the rule is about *who calls them and when*, not about removing their CRUD methods. Their write methods may only be invoked from inside `Goods_Receipt_Service::post()`/`void()`'s transaction (for the fields above) or from `Goods_Receipt_Service`'s draft-mutation methods (for draft-only, zero-stock-effect edits). No other caller — present or future — may reach a repository's write method directly to persist any of the seven items above.

### Transaction lifetime boundary (formalized)

The database transaction's lifetime is deliberately as short and as purely mechanical as possible:

- Begins **immediately before persistence** — after every pre-transaction check (token consumption, existence/status/non-empty-line validation) has already passed.
- Ends **immediately after persistence** — at the natural close of the `run()` closure, with no further logic executed while the transaction is still open.
- **Never spans user interaction** — no step inside the transaction waits on operator input; everything the operator provides (product/qty/cost selections, the confirm click) has already been captured into plain PHP values before `$txn->begin()` is called.
- **Never survives a redirect** — the transaction opens and closes entirely within one PHP request; the Post-Redirect-Get pattern used for the admin-facing response (§Receiving workflow) happens strictly after the transaction has already committed or rolled back.
- **Never includes a confirmation dialog** — the confirm step (§Receiving workflow) is a precondition checked *before* `post()`/`void()` is called at all, not a pause inside the transaction.
- **Never includes external network operations** — no HTTP/API call of any kind (FX lookups, webhooks, notifications) executes while the transaction is open; any such call, if ever needed, must happen either before `$txn->begin()` (to gather inputs) or after successful commit (§"No irreversible side effects before commit" below) — never during.
- **Never includes long-running processing** — the closure contains only the fixed, bounded sequence in §Inventory mutation (one conditional status UPDATE, then one `Restock_Service` call plus one `Movements` insert per line); nothing inside it is unbounded in the number of lines a single receipt reasonably contains, and nothing inside it does file I/O, image processing, or similar.

**When transactions begin:** exactly once per `post()` or `void()` call, immediately after (a) the idempotency token is consumed and (b) the receipt/lines are loaded and pre-validated (existence, current status, non-empty line list) — i.e. **cheap, side-effect-free checks happen before `$txn->begin()`**, so a request that's obviously invalid never opens a transaction at all.

**When they commit:** only when every line's stock/cost mutation *and* its movement-row insert *and* the header's conditional status flip have all succeeded, at the natural end of the `run()` closure.

**When they roll back:** on any thrown `Exception` inside the closure — real SQL `ROLLBACK`, not the hand-rolled per-line compensating undo Batch Intake uses today. Because `WC_Product::save()` writes through the same `$wpdb` connection, wrapping it inside `$txn->run()` means `set_stock_quantity()`/postmeta writes are ordinary statements inside the open transaction — a `ROLLBACK` undoes them exactly like any other row. **This is the single biggest simplification M4 gets over the existing Batch Intake code, and it eliminates the need for Batch Intake's `restore_snapshot()`-per-line rollback loop entirely.**

**What participates:** the conditional header status UPDATE; every line's `Restock_Service::apply_purchase_line_change()` (or, for void, the new `apply_purchase_line_reversal()`) call; every line's `Movements::insert_goods_receipt()`/`insert_goods_receipt_void()` call.

**What cannot participate — no irreversible side effect may occur before a successful commit (strengthened, explicit prohibition list):** product-transient/cache invalidation (`wc_delete_product_transients()`, `WC_Cache_Helper::get_transient_version()`) are **not** transactional WordPress operations and must run strictly *after* `$txn->run()` returns successfully, never inside the closure. This is one instance of a general rule: **any action that cannot itself be rolled back must wait until after a successful commit.** Explicitly covered by this rule, none of which exist in M4 today but all of which are bound by this rule the moment any future code path introduces them:
- cache invalidation (the one concrete case M4 has)
- WordPress hooks/actions intended for external consumers (`do_action()` calls other plugins may listen to)
- emails
- notifications (admin notices tied to the mutation's success are fine to *queue* pre-commit, since they only render on the next request, but must not themselves perform a side effect pre-commit)
- webhooks
- asynchronous/scheduled jobs (e.g. `wp_schedule_single_event()`)
- external logging (anything leaving this database, e.g. a remote log service)

Rationale: any of these firing before a commit that then rolls back would announce or act on a mutation that never actually happened — a strictly worse failure mode than the mutation simply not happening, since it can propagate the inconsistency outside this plugin's own transactional boundary where a `ROLLBACK` can no longer reach it.

**The binding gotcha and its fix — `DB_Transaction::run()` only catches `Exception`:**

```php
final class WC_Inventory_Overview_Goods_Receipt_Posting_Exception extends Exception {
    private WP_Error $wp_error;
    public function __construct( WP_Error $e ) { $this->wp_error = $e; parent::__construct( $e->get_error_message() ); }
    public function get_wp_error(): WP_Error { return $this->wp_error; }
}

private static function throw_if_error( $value ) {
    if ( is_wp_error( $value ) ) {
        throw new WC_Inventory_Overview_Goods_Receipt_Posting_Exception( $value );
    }
    return $value;
}
```

`Restock_Service`, `Movements`, and the new repository methods all return `WP_Error` (never throw) on failure — confirmed by direct source reading. **Non-negotiable rules, enforced by an architecture-guard test (WP9):**

1. No call inside a `run()` closure may `return`/leave-unhandled a `WP_Error` — every fallible call is wrapped in `throw_if_error()`.
2. `Goods_Receipt_Service` never calls `$txn->rollback()` directly — rollback is exclusively `DB_Transaction::run()`'s job, triggered only by a thrown `Exception`.
3. Cache/transient invalidation happens strictly after `run()` returns successfully.

### Optimistic concurrency control (compare-and-swap) — precise behavior

Posting performs the equivalent of the following as the **first write inside the transaction**, before any line is touched:

```sql
UPDATE wc_io_goods_receipts
SET status = 'posted', posted_at = NOW(), posted_by = %d
WHERE id = %d
  AND status = 'draft';
```

The implementation **must verify that exactly one row was affected** (`$wpdb->rows_affected === 1`, or the repository method's equivalent boolean/count return). Voiding performs the identical pattern with `SET status = 'voided', voided_at = NOW(), voided_by = %d, void_reason = %s ... WHERE id = %d AND status = 'posted'`.

**If zero rows are affected** (the receipt was not in the expected starting status — already posted, already voided, or concurrently modified by another request):
- Abort immediately (`throw_if_error()` fires on the spot).
- Perform no inventory mutation.
- Perform no movement creation.
- Perform no PO updates (moot in M4 — no PO table is ever touched — but stated for completeness and symmetry with the general rule).

This is the only concurrency-control mechanism M4 uses; see §Idempotency & concurrency for why no additional row-locking is introduced.

### Rollback invariant (architectural guarantee, not an implementation detail)

> **After any failed posting or void operation, the database must be observationally identical to its state immediately before the operation began.**

This is stated as a formal, binding invariant — not merely a description of what `DB_Transaction::run()` happens to do. "Observationally identical" means: every table row this milestone can touch (`wc_io_goods_receipts`, `wc_io_receipt_lines`, `wc_io_receipt_costs`, `wc_io_inventory_movements`, WooCommerce `_stock`/postmeta) reads back exactly as it would have if `post()`/`void()` had never been called at all — no partial row, no orphaned movement, no stock/cost drift, regardless of which line or which step the failure occurred at. Every forced-failure test in §Testing exists specifically to verify this invariant, not merely to verify "an error was returned."

**What happens on exception:** `run()` catches it, issues `ROLLBACK` (or `ROLLBACK TO SAVEPOINT` if nested — not expected in M4's usage, no nested `Goods_Receipt_Service` calls into another transactional service), then rethrows. The outer `post()`/`void()` method catches that rethrow, unwraps the original `WP_Error` via `get_wp_error()` (or wraps an unexpected `Exception` in a generic `WP_Error`), and returns it to the caller — the receipt header's status is exactly as it was before the call (still `draft` for a failed post, still `posted` for a failed void), because the conditional status UPDATE was inside the same rolled-back transaction.

**What happens if validation fails:** rejected before any transaction opens (empty lines, unknown receipt id, wrong status) — returns `WP_Error` immediately, zero DB writes.

**What happens if the status compare-and-swap fails** (0 rows affected — e.g. a concurrent duplicate request already flipped it): `throw_if_error()` fires as the very first thing inside the closure, before any stock mutation is attempted — cheapest possible failure path, and it rolls back cleanly (nothing to undo yet).

**What happens if stock update fails** (`Restock_Service::apply_purchase_line_change()` returns `WP_Error` — e.g. variable parent selected, insufficient guard on negative resulting stock): thrown via `throw_if_error()`, full `ROLLBACK`, receipt stays `draft`, zero movement rows written, zero stock/meta changes persisted.

**What happens if movement creation fails** (`Movements::insert_goods_receipt()` returns `false`): thrown via `throw_if_error()`, full `ROLLBACK` — critically, this also undoes the stock/cost mutation from the *same* line (and any prior lines in the loop), because it's still inside the one open transaction. No `restore_snapshot()` call is needed or made.

**What happens if a PO update fails:** not applicable — M4 never writes to any PO table.

---

## Idempotency & concurrency

### Idempotency token lifecycle

M4 reuses `WC_Inventory_Overview_PO_Request_Token` as-is (new contexts `'gr_post'`/`'gr_void'`), with the following lifecycle made fully explicit so no implementation behavior is left open to interpretation:

1. A token is **generated** (`PO_Request_Token::issue( 'gr_post' )` / `issue( 'gr_void' )`) when the draft-detail or void-confirmation screen is rendered — i.e. *before* the operator submits anything, not at submission time.
2. The token is **single-use**: `consume()` deletes the underlying transient on first read, regardless of whether it validates successfully.
3. The token is **consumed immediately before the transaction begins** — the very first statement of `post()`/`void()`, ahead of even the cheap pre-validation checks (§Transaction model, "When transactions begin"). A request whose token fails to consume never opens a transaction, never loads the receipt, and performs zero DB work of any kind.
4. The token is **never reusable**: once consumed (successfully or not), the same token value fails on every subsequent attempt.
5. This makes the flow **resilient against browser refresh and back-button resubmission** by construction: a refreshed/resubmitted form still carries the already-consumed token value, so `consume()` fails and the request is rejected before any side effect — independently of, and in addition to, the compare-and-swap check in §Transaction model (defense in depth: the token stops an accidental resubmission from a *browser*; the compare-and-swap stops a race between two *distinct* valid requests).

### Concurrency expectations — deliberate, not accidental

This plan **intentionally avoids explicit database row locking** (no `SELECT ... FOR UPDATE`, no `LOCK TABLES`, no distributed lock service). This is a deliberate architectural decision, stated here explicitly so no future contributor mistakes the omission for an oversight:

- The expected deployment is a **single warehouse** with 1–3 admin users (per CLAUDE.md's stated business context) — genuinely concurrent double-posting of the *same specific* receipt is a near-impossible event in practice, not a load-bearing scenario this plugin needs to defend against.
- Receipt-posting concurrency is very low by the nature of the workflow (an operator reviews a draft, then posts it — this is not a high-frequency, high-contention write path).
- WooCommerce's own product save path, combined with a real MySQL transaction wrapping every statement in a post/void (§Transaction model), already serializes the writes that matter sufficiently for this deployment model: two transactions touching the same product row will serialize at the database level regardless of application-level locking.
- The idempotency token (above) plus the compare-and-swap status UPDATE (§Transaction model) together already make the *specific* race this plugin must prevent — double-application of one post or void — safe, without needing row-level locking to achieve that safety.

**M4 explicitly does not introduce:** `SELECT ... FOR UPDATE`; explicit row locking of any kind; `LOCK TABLES`; distributed locks (Redis, etc.). Introducing any of these in M4 would be over-engineering for a deployment model that doesn't need it, not a correctness improvement — and would be scope creep against this plan, not a permitted implementation-time enhancement.

---

## Inventory mutation

**Stock mutation order, cost mutation order, movement creation order (posting):** for each receipt line, in ascending `line_index` order, within the single transaction:
1. Conditional header status UPDATE `draft → posted` (first write, once, before any line loop — fail-fast).
2. Per line: `Restock_Service::apply_purchase_line_change( $item_id, $line['qty'], $line['true_unit_cost'] )` → mutates `_stock`, `_wc_io_average_unit_cost`, `_wc_io_inventory_value` on that one product/variation.
3. Immediately after each line's mutation: `Movements::insert_goods_receipt(...)` for that same line, carrying `reference_type='goods_receipt'`, `reference_id=<receipt id>`.
4. Next line (steps 2–3 repeat) — **same product/variation appearing twice in one receipt is allowed** (see §Costing) and composes correctly because `apply_purchase_line_change()` re-fetches the product fresh on every call.

**Voiding order:** identical shape, in the same `line_index` order, using `apply_purchase_line_reversal()` (subtraction) and `Movements::insert_goods_receipt_void()` instead.

**Coding-standard clarification — named constants only, never string literals.** Wherever movement type is discussed or written in code, the implementation must reference `WC_Inventory_Overview_Movements::TYPE_GOODS_RECEIPT` and `::TYPE_GOODS_RECEIPT_VOID` (the named class constants, matching the existing `TYPE_PURCHASE`/`TYPE_PURCHASE_BATCH`/`TYPE_COST_ADJUSTMENT` convention already in that class) — never the bare strings `'goods_receipt'`/`'goods_receipt_void'` inlined at call sites. (Where this plan's prose above writes `reference_type='goods_receipt'` for readability, that denotes the *value* the constant resolves to, not permission to hardcode the literal in source.) This is a coding-standard clarification only — it does not change the movement-type design decided in §Risk review/§Critical review.

**Event ordering:** no PO events are written (§Scope — no PO relationship exists in M4). The Goods Receipt's own "event log" *is* its header timestamp/actor columns plus its own typed movement rows, written in the order above.

**Required invariants (must hold after every successful post or void, verified by tests):**
- `new_stock = old_stock ± line.qty` exactly, per line, in sequence (each line's `old_stock` is whatever the *previous* line or prior receipt left it at — not the receipt's own snapshot).
- `new_average_unit_cost = new_inventory_value / new_stock` (or `0.0` if `new_stock = 0`), always.
- Every posted line has exactly one corresponding `goods_receipt` movement row; every voided line has exactly one corresponding `goods_receipt_void` movement row.
- A receipt's status is exactly one of `draft|posted|voided`, and once `posted` or `voided`, `updated_at`/lines/costs are never mutated again (draft-only editability, enforced by `Goods_Receipt_Lifecycle::assert_editable()`, mirroring `PO_Lifecycle`).

### Receipt immutability after posting (explicit invariant)

Strengthened from the bullet above into a precise, binding rule: **after a receipt transitions to `posted`, every field on it becomes permanently read-only except:**
- `status`
- `voided_at`
- `voided_by`
- `void_reason`

**Everything else becomes immutable, with no code path permitted to edit it post-posting**, including but not limited to: `supplier_id`/`supplier_name_snapshot`, `currency`/`exchange_rate_to_eur`/`exchange_rate_date`, every landed-cost row in `wc_io_receipt_costs`, `note`/`reference`, and every receipt line in `wc_io_receipt_lines` (quantities, costs, product references — none of it). No "correct a typo" or "fix the reference number" edit path exists for a posted receipt; the only way to change a posted receipt's effect is to void it (which affects only the four fields above) and, if needed, post a new corrected receipt. This mirrors D17's living-document model for Purchase Orders in spirit (corrections are lifecycle transitions, not silent edits) while staying within Goods Receipt's simpler 3-state lifecycle (§Database, §Critical review).

**Post-conditions:** on success, `post()` returns the receipt id (its status is now `posted`, `posted_at`/`posted_by` set); on failure, returns `WP_Error` and the receipt is unchanged (still `draft`). Symmetric for `void()`.

### Voiding correctness — the one real design risk, resolved

**Rejected approach:** restoring a per-line snapshot captured at posting time (the same pattern Batch Intake's `restore_snapshot()` uses). This is unsafe here: *other receipts may post against the same product between this receipt's posting and its void* — jumping back to "state exactly as it was before this receipt" would silently erase those other receipts' contributions.

**Chosen approach — current-state-relative subtraction of this line's own immutable delta**, implemented as a new companion method:

```php
// New: Restock_Service::apply_purchase_line_reversal( $line_id, $reversal_qty, $reversal_value )
current_stock = wc_stock_amount(product.stock_quantity ?? 0)   // read NOW, not a stored snapshot
current_avg   = Costing::get_average_float(product)             // read NOW
current_value = current_avg === null ? 0.0 : round(current_stock * current_avg, 4)

reversal_qty   = receipt_line.qty                                       // exact, immutable, stored at posting time
reversal_value = round(reversal_qty * receipt_line.true_unit_cost, 4)   // this line's own contribution only

new_stock = round(current_stock - reversal_qty, 4)
if new_stock < 0: ABORT — WP_Error (actionable message: "units received by this receipt have since been sold/consumed")

new_value = max(0.0, round(current_value - reversal_value, 4))
new_avg   = new_stock > 0 ? round(new_value / new_stock, 6) : 0.0
```

Returns the same `{line_id, snapshot, movement}` shape as `apply_purchase_line_change()`, so `void()` is structurally identical to `post()`.

**Why this composes correctly regardless of intervening receipts:** stock and inventory value are pure running totals; every receipt (and every void) only ever adds or subtracts *its own* delta relative to whatever the current totals happen to be. It never reads or restores anyone else's before/after state, so it cannot stomp on another receipt's contribution no matter how many posted in between — it's the same incremental algebra the moving average is already built from, run in reverse.

**Irreducible remaining limitation, stated honestly, not engineered around:** this plugin's costing is a non-lot-tracked moving average (confirmed: "Batch lines are historical records, never consumed — no FIFO/lots" per CLAUDE.md §2) — units are fungible by design, so there is no way to know whether the units still physically in stock are "the same units" this receipt contributed. If enough stock has since been sold/consumed (via WooCommerce's own order-fulfillment reduction, entirely outside this plugin), `new_stock < 0` and the void is **blocked** with a clear, actionable operator message. This is the only sound answer in a non-lot-tracked system — not a gap this milestone could close by engineering harder, and explicitly not attempted.

**Multi-line receipt void:** processed within one `run()` closure exactly like posting; the first line whose reversal fails the negative-stock check throws, the whole void rolls back via real `ROLLBACK`, and the receipt stays `posted` (never left half-reversed).

---

## Costing

**Formula (unchanged, reused verbatim — this milestone does not redesign the costing model):**

```
old_stock = wc_stock_amount(product.stock_quantity ?? 0)          // null/'' -> 0.0
old_avg   = Costing::get_average_float(product)                    // null if unset
old_inventory_value = (old_avg === null) ? 0.0 : round(old_stock * old_avg, 4)

added_stock = wc_stock_amount(qty_added)
added_value = round(added_stock * true_unit_cost, 4)

new_stock = round(old_stock + added_stock, 4)
new_inventory_value = round(old_inventory_value + added_value, 4)
new_average = new_stock > 0 ? round(new_inventory_value / new_stock, 6) : 0.0
```

**Worked example (permanent characterization reference — the formula itself is unmodified; this is illustration only):**

```
Starting stock:     10 units @ 8.00   → old_inventory_value = 10 × 8.00  = 80.0000
Receive:              5 units @ 12.00 → added_value          =  5 × 12.00 = 60.0000

new_stock           = 10 + 5                = 15.0000
new_inventory_value = 80.0000 + 60.0000     = 140.0000
new_average         = 140.0000 / 15.0000    = 9.333333   (rounded to 6 decimals)
```

**Rounding/precision:** quantities and monetary totals → 4 decimals; unit costs and averages → 6 decimals (matches the existing convention exactly, everywhere in the codebase).

**Multiple receipts:** each receipt (and each line within it) applies the formula against whatever the *current* stock/average happens to be at the moment it posts — receipts compose sequentially and correctly regardless of order or how many have posted before, because the formula only ever needs the current running totals, never any receipt's own history.

**Partial receipts / receiving into existing inventory:** every M4 receipt naturally "receives into existing inventory" in the formula sense (`old_stock`/`old_avg` are simply whatever they currently are) — this requires no special-casing.

**Receiving into zero inventory:** when `old_stock = 0` and `old_avg` is `null` (never set) or the stock was fully depleted to 0, `old_inventory_value = 0.0`, and after the first line `new_average = true_unit_cost` exactly (the formula degenerates correctly to "the average is just this receipt's cost" when there was nothing before it) — verified by a dedicated test, no special-case code needed.

**"Partial vs. complete" receiving — clarified, since the user's directive explicitly asked for this to be specified:** D5's "partial receipt" concept (*"a receipt contains only the lines actually received; omitted PO lines simply remain outstanding"*) is fundamentally a **PO-fulfillment** concept — it describes a receipt's relationship to a PO's remaining outstanding quantity. Because M4 receipts have **no PO relationship at all** (Scope-boundary ruling above), that concept **does not apply to M4**. Every M4 (Quick Receive) receipt is simply complete-in-itself for whatever items/quantities the operator entered — there is no "outstanding" concept to be partial against. D5's actual partial/complete semantics become meaningful only in M5, once receipts can link to PO lines.

**Landed costs — included in M4, not deferred**, because: (1) D3 explicitly repositions landed allocation as receiving-domain behavior ("repositioned, not discarded") — omitting it would ship Quick Receive as a functional regression versus Batch Intake on day one; (2) landed costs have zero PO-linkage dependency, operating purely at receipt header/line level; (3) since M4 never populates `po_line_id`, every M4 receipt is homogeneous (pure direct lines), structurally identical to a Batch Intake batch today — there is no "mixed PO-linked and direct lines with no coherent subtotal" problem to solve in M4.

**Allocation formula (ported unchanged from `Batch_Intake_Service`, proportional-by-line-value with remainder-to-last-line):**

```
for each line i except the last:
    allocation[i] = round(landed_total * (line[i].base_line_cost / product_subtotal), 4)
    running_sum += allocation[i]
last line:
    allocation[last] = round(landed_total - running_sum, 4)   // remainder absorbs rounding drift
```
Guard (unchanged): if `landed_total > 0` and `product_subtotal <= 0`, reject with the same class of error Batch Intake already returns (`wc_io_batch_allocate`-equivalent, renamed for the receipt context).

**Why ported, not called-into `Batch_Intake_Service`:** `Batch_Intake_Service`'s allocation logic is `protected`, internal to a feature with a scheduled deletion date (M6). Reaching into it would couple M4 to code slated for removal. A small, stateless `Goods_Receipt_Costing` class carries the identical formula forward. This is the **one deliberate exception** to "reuse over duplicate" in this plan, and it's explicitly justified here rather than left as an unexplained inconsistency.

**Cost entry:** operator enters `entered_unit_cost` **per unit** (not per-line-total as Batch Intake does today) — decided for shape parity with `wc_io_purchase_order_lines.unit_cost`, ahead of M5's PO-linked lines needing to share the same representation. This is a deliberate, stated departure from Batch Intake's UI convention, not an oversight: `base_line_cost` is simply derived (`entered_unit_cost × qty`) rather than entered directly.

**Same product twice in one receipt: allowed, no uniqueness constraint.** Safe because `apply_purchase_line_change()` re-fetches the product fresh on every call (confirmed from source) — two lines for the same item compose exactly as if posted as two separate receipts back to back, in `line_index` order. No such uniqueness constraint exists today on PO lines within one PO either, so this stays consistent with D2's "independent line records, never merged" philosophy one level down, and matches a real operator workflow (mixed-cost sub-lots within one delivery).

**Product-type enforcement:** inherited for free from `Restock_Service` (rejects `variable`/`grouped`/`external` parents, requires `managing_stock()`) — duplicated as client+server draft-time validation on the Quick Receive product picker too, so operators get feedback while still editing a draft rather than only failing at post time (defense in depth, not a new invariant).

**Future extension points (not built in M4, schema/formula deliberately leaves room for):** `post_hoc` landed-cost column exists but unused; `po_line_id` column exists but unused; `source` enum reserves `'po'`/`'mixed'` values M4 never writes.

---

## Receiving workflow

**UI:** a new "Receive Stock" sub-view under the existing Inventory & Profit hub's Restock/Cost Adjustment tab group — **alongside**, not replacing, Batch Intake/Quick Restock/Cost Adjustment (D3's repositioning target; Batch Intake stays fully live until M6). M1's own CHANGELOG anticipated this exact tab ("Tab structure: extensible for M2+ (Purchase Orders, Receive Stock tabs)").

**Validation (draft-time and post-time, layered):** product must be simple or a variation (never variable/grouped/external parent), must have stock management enabled; `qty > 0`; `entered_unit_cost >= 0` (and `> 0` unless `Settings::allow_zero_supplier_cost()`); currency must be one of `Settings::allowed_purchase_currencies()`; FX rate `> 0`; landed total vs. product subtotal guard (mirrors Batch Intake's existing rule).

**Confirmation:** posting requires an explicit confirm step (mirrors Batch Intake's `wc_io_batch_confirm` pattern) plus the one-shot request token (§Idempotency) — a plain GET/refresh can never trigger a post.

**Permissions:** gated via `WC_Inventory_Overview_Purchasing_Caps`, extended with new action-key constants (`VIEW_RECEIPT`, `EDIT_RECEIPT`, `POST_RECEIPT`, `VOID_RECEIPT`, `DELETE_RECEIPT`), each defaulting to `manage_woocommerce` through the existing filterable map — **not** a new capability, and not M3's read-only view tier (receiving is a mutating purchasing action, closer in kind to PO place/cancel).

**Error reporting:** `WP_Error` throughout the service layer; admin-facing errors surface via the existing Purchasing admin PRG (Post-Redirect-Get) + notice pattern already used by PO admin — reused, not reinvented.

**Operator workflow:**
1. Create a draft receipt (optional supplier attribution, currency, FX date/rate).
2. Add one or more lines (product picker restricted to simple products/variations; qty; per-unit cost); optionally add landed cost rows.
3. Review a computed preview (mirrors Batch Intake's preview-before-apply pattern: old/new stock, old/new average, old/new inventory value per line) — pure computation, no writes, re-derivable at any time while still a draft.
4. Post (confirm + token) → atomic mutation (§Transaction model) → receipt becomes `posted`, immutable.
5. Optionally, later, Void a posted receipt with a free-text reason → atomic reversal, or a clear rejection if blocked by insufficient remaining stock.

**Partial receipt workflow / Completed receipt workflow:** as clarified in §Costing, M4 has no PO-relative "partial" concept. Every receipt the operator creates is, by construction, "complete" for the lines it contains — there is no separate partial-vs-complete workflow to design in M4; this becomes meaningful only in M5.

---

## Database

**New `DB_VERSION = '8'`.**

### `wc_io_goods_receipts` (header)

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint(20) unsigned` PK AUTO_INCREMENT | |
| `receipt_number` | `varchar(32)` NOT NULL | UNIQUE KEY; `GR-{YYYY}-{NNNN}`, never-reuse (mirrors PO numbering exactly) |
| `status` | `varchar(20)` NOT NULL DEFAULT `'draft'` | `draft`\|`posted`\|`voided` only |
| `source` | `varchar(20)` NOT NULL DEFAULT `'direct'` | M4 only ever writes `'direct'`; `'po'`/`'mixed'` reserved for M5 |
| `supplier_id` | `bigint(20) unsigned` NULL | Optional — D7 requires no synthetic PO, not a mandatory supplier |
| `supplier_name_snapshot` | `varchar(190)` NULL | Denormalized, mirrors PO's snapshot pattern |
| `currency` | `char(3)` NOT NULL DEFAULT `'EUR'` | |
| `exchange_rate_to_eur` | `decimal(19,8)` NOT NULL DEFAULT 1 | |
| `exchange_rate_date` | `date` NULL | |
| `product_subtotal_entered`, `landed_total_entered`, `receipt_total_entered` | `decimal(19,4)` NOT NULL DEFAULT 0 | Entered-currency triplet |
| `product_subtotal`, `landed_total`, `receipt_total` | `decimal(19,4)` NOT NULL DEFAULT 0 | EUR-converted triplet |
| `reference` | `varchar(190)` NULL | Supplier invoice/delivery reference |
| `note` | `text` NULL | |
| `posted_at` / `posted_by` | `datetime` NULL / `bigint(20) unsigned` NULL | |
| `voided_at` / `voided_by` | `datetime` NULL / `bigint(20) unsigned` NULL | |
| `void_reason` | `text` NULL | Free text — a single transition, a coded-enum taxonomy would be schema speculation (D16) |
| `created_by` / `updated_by` | `bigint(20) unsigned` NOT NULL DEFAULT 0 | |
| `created_at` / `updated_at` | `datetime` NOT NULL DEFAULT CURRENT_TIMESTAMP | |

Keys: PK(`id`), UNIQUE(`receipt_number`), KEY(`status`), KEY(`supplier_id`), KEY(`created_at`).

**Receipt number immutability (explicit invariant):** `receipt_number` is:
- **allocated exactly once**, at draft-creation time, by `Goods_Receipt_Numbering::allocate()` (mirrors `PO_Numbering::allocate()` exactly, §Class/service design);
- **never modified** thereafter — no update path for this column exists at any lifecycle stage, including while still a `draft`;
- **never reused** — the underlying per-year sequence only ever advances (mirrors `PO_Numbering`'s stated "never-reuse invariant" precisely; a failed/abandoned draft's number is not recycled);
- **remains permanently attached to the receipt even after voiding** — voiding changes `status`/`voided_at`/`voided_by`/`void_reason` only (§Inventory mutation — Receipt immutability); `receipt_number` is not among the fields a void touches, and a voided receipt keeps the exact number it was allocated at creation, forever.

### `wc_io_receipt_lines`

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint(20) unsigned` PK | |
| `receipt_id` | `bigint(20) unsigned` NOT NULL | KEY |
| `line_index` | `int` NOT NULL DEFAULT 0 | |
| `po_line_id` | `bigint(20) unsigned` NULL | **Present, indexed, always NULL in M4** — no setter in the M4 service API |
| `product_id` | `bigint(20) unsigned` NOT NULL DEFAULT 0 | Parent id for a variation, self id for simple |
| `variation_id` | `bigint(20) unsigned` NOT NULL DEFAULT 0 | 0 for simple |
| `sku_snapshot` | `varchar(100)` NULL | |
| `name_snapshot` | `varchar(190)` NULL | |
| `qty` | `decimal(19,4)` NOT NULL DEFAULT 0 | |
| `entered_currency` | `char(3)` NOT NULL DEFAULT `'EUR'` | |
| `exchange_rate_to_eur` | `decimal(19,8)` NOT NULL DEFAULT 1 | |
| `entered_unit_cost` | `decimal(19,6)` NOT NULL DEFAULT 0 | Per-unit (see §Costing) |
| `converted_unit_cost_eur` | `decimal(19,6)` NOT NULL DEFAULT 0 | |
| `base_line_cost` | `decimal(19,4)` NOT NULL DEFAULT 0 | |
| `allocated_landed_cost` | `decimal(19,4)` NOT NULL DEFAULT 0 | |
| `true_line_cost` | `decimal(19,4)` NOT NULL DEFAULT 0 | |
| `true_unit_cost` | `decimal(19,6)` NOT NULL DEFAULT 0 | Fed into `Restock_Service` |
| `old_stock` / `new_stock` | `decimal(19,4)` NOT NULL DEFAULT 0 | |
| `old_average_unit_cost` | `decimal(19,6)` NULL | |
| `new_average_unit_cost` | `decimal(19,6)` NOT NULL DEFAULT 0 | |
| `old_inventory_value` / `new_inventory_value` | `decimal(19,4)` NOT NULL DEFAULT 0 | |
| `created_at` | `datetime` NOT NULL DEFAULT CURRENT_TIMESTAMP | |

Keys: PK(`id`), KEY(`receipt_id`), KEY(`product_id`), KEY(`variation_id`), KEY(`po_line_id`).

### `wc_io_receipt_costs`

Mirrors `wc_io_purchase_batch_costs`: `id`, `receipt_id`, `cost_type varchar(32)`, `entered_currency`, `exchange_rate_to_eur`, `entered_amount`, `converted_amount_eur`, `amount`, `note`, plus **`post_hoc tinyint(1) NOT NULL DEFAULT 0`** (always `0` in M4; reserved per §5.1's "post-hoc flag" for a future capability, unused/non-goal here). Keys: PK, KEY(`receipt_id`), KEY(`cost_type`). Cost-type slugs/labels: reuse `Batch_Intake_Service::allowed_cost_types()`/`landed_cost_type_labels()` directly (safe cross-reference — Batch Intake stays live until M6).

### `wc_io_inventory_movements` — ALTER

Add: `reference_type varchar(32) NULL`, `reference_id bigint(20) unsigned NULL`, `supplier_id bigint(20) unsigned NULL`. Add `KEY reference (reference_type, reference_id)`, `KEY supplier_id (supplier_id)`.

- Nullable — legacy `purchase`/`purchase_batch`/`cost_adjustment` inserts continue to omit them, zero behavior change to those paths (confirmed: `insert_purchase_like()` and `insert_cost_adjustment()` never pass them).
- `reference_id` points at the **receipt header id** (not the line id) — direct typed replacement for today's regex `Batch ID: (\d+)` note-parsing, and it makes void-time "every movement this receipt produced" trivially `WHERE reference_type='goods_receipt' AND reference_id=?`.
- Pre-existing, unrelated precision note carried forward unchanged: `unit_cost` on this table is `decimal(19,4)` even though `true_unit_cost` is computed to 6dp — an existing limitation for `purchase`/`purchase_batch` rows too, not something M4 introduces or is responsible for fixing (out of scope; flagged as a future improvement, not a defect of this milestone).

### `expected_schema_v8()` and the dispatcher — exact code-level guidance (eliminates a real implementation trap)

`expected_schema_v8()` extends `expected_schema_v7()` exactly as v7 extends v6: adds `tables` entries for the three new tables; adds `columns` entries for all three (full column lists); adds a **new** `columns['inventory_movements']` entry (today's `expected_schema_v7()` doesn't track this table's columns at all — v8 must add one asserting `reference_type`, `reference_id`, `supplier_id` exist, since that's the only ALTER against a pre-existing table); carries `forbidden_columns` forward **unchanged** (`'purchase_order_lines' => ['qty_received']`).

**Concrete trap to avoid:** `Install::expected_schema( $version )` currently dispatches as:
```php
private static function expected_schema( $version ) {
    if ( version_compare( (string) $version, '7', '>=' ) ) {
        return self::expected_schema_v7();
    }
    return self::expected_schema_v6();
}
```
Simply adding `expected_schema_v8()` without touching this dispatcher will **silently route DB_VERSION 8 to `expected_schema_v7()`**, meaning `assert_schema_shape()` would never check any of the new tables/columns exist — a false-green schema gate. The dispatcher must become:
```php
private static function expected_schema( $version ) {
    if ( version_compare( (string) $version, '8', '>=' ) ) {
        return self::expected_schema_v8();
    }
    if ( version_compare( (string) $version, '7', '>=' ) ) {
        return self::expected_schema_v7();
    }
    return self::expected_schema_v6();
}
```
Additionally, `assert_schema_shape()` has version-gated extra structural checks beyond the generic table/column loop (today: a unique-index check on `wc_io_purchase_orders.po_number`, gated by `version_compare( $version, '7', '>=' )`). M4 must add an analogous block gated by `version_compare( $version, '8', '>=' )` asserting a unique index exists on `wc_io_goods_receipts.receipt_number`.

---

## Testing

Directory convention (mirrors M3's `<milestone-noun>` pattern): `tests/unit/goods-receipt/`, `tests/integration/goods-receipt/`. PHPUnit class prefix `Test_WC_IO_Goods_Receipt_`, added as a new alternation term to `tests/docker/run-phpunit.sh`'s default blocking `--filter` regex.

**Required test files:**
- `tests/unit/goods-receipt/test-goods-receipt-numbering.php` — format, never-reuse, collision-retry (mirrors existing PO-numbering tests).
- `tests/unit/goods-receipt/test-goods-receipt-lifecycle.php` — transition table (`draft→posted→voided` only, draft hard-delete, no reopen).
- `tests/unit/goods-receipt/test-goods-receipt-costing.php` — landed allocation (remainder-to-last-line, zero-landed passthrough, `product_subtotal <= 0` guard).
- `tests/unit/goods-receipt/test-goods-receipt-architecture.php` — D3/INV-2 sole-mutator guard; `po_line_id` never set anywhere in M4 source; no direct `$txn->rollback()` calls outside `DB_Transaction::run()`; no un-wrapped `WP_Error` return left inside a `run()` closure (source-scan, mirrors M3's `strip_comments()`-hardened pattern).
- `tests/integration/goods-receipt/test-goods-receipts-repository.php` — header CRUD, draft-only mutability.
- `tests/integration/goods-receipt/test-receipt-lines-repository.php` — line CRUD, `po_line_id` always persists as `NULL`.
- `tests/integration/goods-receipt/test-restock-service-reversal.php` — `apply_purchase_line_reversal()` in isolation: exact subtraction math, negative-stock guard, zero-stock-after-void, average re-derivation, zero-value floor.
- `tests/integration/goods-receipt/test-goods-receipt-service-post.php` — happy paths (single line; multi-line same product; multi-line different products; with/without landed costs) asserting exact formula output and exact movement rows; failure paths (variable parent, zero qty, negative cost, forced movement-insert failure) asserting **full SQL rollback** — stock/avg/value unchanged, zero partial movement rows, receipt stays `draft`.
- `tests/integration/goods-receipt/test-goods-receipt-service-void.php` — happy-path reversal math; **the critical regression test**: post receipt A (qty 10 @ cost X) → post receipt B (qty 5 @ cost Y, same product) → void A → assert resulting stock/avg reflect *only* B's remaining contribution; insufficient-stock guard (simulate a WooCommerce order reducing stock below the receipt's contribution, attempt void, assert clean rejection with zero partial mutation).
- `tests/integration/goods-receipt/test-goods-receipt-idempotency.php` — double-post via reused token (rejected pre-transaction, zero DB writes); double-post via simulated status-race (conditional UPDATE observes 0 affected rows, aborts cleanly, zero partial writes).
- `tests/integration/goods-receipt/test-goods-receipt-capability.php` — `manage_woocommerce` users can post/void; lesser-privileged users cannot; UI/service both gated (not just one layer).
- Extend (not duplicate) `tests/integration/install/test-schema-shape-assertion.php` — schema-v8 assertion: new tables/columns present, `inventory_movements` gained exactly 3 new columns, `qty_received` still forbidden on `purchase_order_lines`, unique index present on `receipt_number`.

**Failure injection tests:** covered above — forced movement-insert failure mid-loop; forced second-line `Restock_Service` failure in a multi-line receipt (assert the *first* line's mutation is also rolled back, not just the second's).

**Performance tests:** intentionally lightweight — this is a low-volume, single-warehouse operational tool (per CLAUDE.md's stated business context), not a high-throughput system. The only required assertion is **bounded, not unbounded/quadratic, query growth per receipt line** (mirrors M3's query-scaling test pattern: assert query count grows linearly with line count, not that any absolute latency target is met).

**Idempotency tests:** see `test-goods-receipt-idempotency.php` above.

**Regression tests:** M0 golden suite unchanged; the itemized pre-existing cumulative-integration-suite failures (FX ×2, Movements ×3 errors, Costing ×4, Cost_Adjustment ×2, Batch_Intake ×2 skipped) unchanged in count and identity. **Any new M4 failure is a release blocker.**

---

## Quality gates

At minimum, executed and individually classified (EXECUTED — PASS / FAIL / PASS WITH KNOWN PRE-EXISTING FAILURES / CONFIGURED — NOT EXECUTED / NOT APPLICABLE), extending M3's exact taxonomy:

- PHP syntax lint
- Composer validation
- Docker Compose config
- Unit suite
- M1–M4-focused blocking suite (`run-phpunit.sh` filter gains `Test_WC_IO_Goods_Receipt_`)
- Cumulative integration suite (must not add to the documented pre-existing failure list)
- Goods Receipt tests in isolation
- PHPCS (not CI-gated, informational only — unchanged policy)
- actionlint, if workflow files changed
- Schema v8 verification (new tables/columns present)
- Forbidden-column verification (`qty_received` still absent from `wc_io_purchase_order_lines`)
- **Transaction-rollback verification** (new gate, mandatory, cannot be downgraded to "known pre-existing failure" status — the forced-mid-loop-failure tests proving full SQL rollback)
- **Void-correctness regression** (new gate, mandatory — the intervening-receipt scenario in `test-goods-receipt-service-void.php`)
- **Idempotency verification** (new gate, mandatory)
- **Stock-mutation correctness gate** — since M4 is the first milestone to mutate WooCommerce stock/cost through this plugin, end-to-end correctness (formula output matches hand-computed expected values across the full test matrix in §Testing) is mandatory, not optional
- Release ZIP build and inspection
- Git diff review against v1.20.0
- Working-tree verification

Any new test failure introduced by M4 is a release blocker.

---

## Documentation

1. `docs/milestones/m4-implementation-plan.md` (this document, once approved and materialized)
2. `CLAUDE.md` milestone status row updated to Complete only **after** implementation
3. `docs/checklists/validation-checklist.md` — new "For M4" subsection, **inverted** from M3's (M3's last bullet was "no receiving surface exists"; M4's must positively verify: post/void work correctly, transactional integrity holds under forced failure, idempotency holds, `po_line_id` stays NULL, `qty_received` guard still enforcing)
4. `docs/release-runbook.md` — new "M4: Receipt Engine" subsection, mirroring M2's numbered pre-tagging pattern (release-notes-file check; schema version bump verification; schema-shape assertion check; end-to-end Quick Receive check) **plus a new stock-mutation-correctness step**, since M4 — unlike M2 and M3 — actually mutates stock and must be checked for that explicitly before tagging
5. `docs/testing.md` — new test directories, updated counts, focused-suite coverage
6. `CHANGELOG.md` — v1.21.0 entry
7. `readme.txt` and all repository version references, updated consistently
8. `docs/architecture-audit.md` — new M4 section: schema, service map, transaction pattern, capability decision, void-correctness design
9. **`docs/GITHUB_RELEASE_NOTES_1.21.0.md`** — created proactively as part of the implementation deliverables, not reactively after a failed tag push (this file's absence was a genuine, reproducible release blocker discovered during M3's actual release; M4 must not repeat that)
10. `tests/docker/run-phpunit.sh` — blocking filter gains `Test_WC_IO_Goods_Receipt_`
11. **`docs/rollback-plan.md`** — new, explicit note: **a plugin-code rollback to a pre-M4 version does NOT reverse the stock/cost effects of receipts already posted under M4.** This is a genuinely new risk class M1–M3 never had (M1/M2 were schema-additive-only; M3 was strictly read-only), and must be documented prominently rather than left implicit — see also §Risk review.

---

## Implementation sequence

- **WP1 — Schema v8.** Three new tables, `inventory_movements` ALTER, `expected_schema_v8()` + dispatcher fix, `DB_VERSION` bump.
  *Deliverables:* updated `class-wc-inventory-overview-install.php`. *Validation:* schema-shape assertion test extended and passing; fresh-install and upgrade-from-v7 both verified. *Dependencies:* none.
- **WP2 — `Goods_Receipt_Numbering` + `Goods_Receipt_Lifecycle`.**
  *Deliverables:* two new classes, unit tests. *Validation:* WP2 unit tests pass. *Dependencies:* WP1 (numbering needs the header table for uniqueness checks).
- **WP3 — Repositories.** `Goods_Receipts`, `Receipt_Lines`, `Receipt_Costs` (draft CRUD).
  *Deliverables:* three new classes, integration tests. *Validation:* repository CRUD tests pass, zero-stock-effect assertions on every draft mutation. *Dependencies:* WP1.
- **WP4 — `Goods_Receipt_Costing`.** Ported landed-allocation formula, preview computation.
  *Deliverables:* one new class, unit tests. *Validation:* allocation-formula unit tests pass (remainder-to-last-line, guards). *Dependencies:* none (pure computation).
- **WP5 — `Movements` extension.** New type constants, `insert_goods_receipt()`/`insert_goods_receipt_void()`, extended `insert_purchase_like()`.
  *Deliverables:* modified `class-wc-inventory-overview-movements.php`. *Validation:* existing `purchase`/`purchase_batch`/`cost_adjustment` tests still pass unmodified (backward compatibility); new insert methods unit-tested. *Dependencies:* WP1 (new columns).
- **WP6 — `Restock_Service::apply_purchase_line_reversal()`.** Subtraction companion, unit-tested independently.
  *Deliverables:* new method on the existing class. *Validation:* `test-restock-service-reversal.php` passes, including the negative-stock guard. *Dependencies:* none.
- **WP7 — `Goods_Receipt_Service`.** Draft CRUD (zero-stock-effect guarded), `post()`, `void()`.
  *Deliverables:* the central orchestrator class. *Validation:* full `test-goods-receipt-service-post.php`/`-void.php` suites pass, including forced-failure rollback tests and the multi-receipt void regression. *Dependencies:* WP2–WP6.
- **WP8 — Idempotency.** Request-token reuse (`'gr_post'`/`'gr_void'` contexts) + conditional-UPDATE compare-and-swap.
  *Deliverables:* wiring inside WP7's `post()`/`void()`. *Validation:* `test-goods-receipt-idempotency.php` passes. *Dependencies:* WP7.
- **WP9 — Architecture-guard tests.** D3/INV-2 sole-mutator; `po_line_id`-always-NULL; rollback-only-via-`DB_Transaction`; no bare `WP_Error` inside `run()` closures.
  *Deliverables:* `test-goods-receipt-architecture.php`. *Validation:* guard tests pass against the real WP1–WP8 code; independently verified (mirroring M3's audit precedent) by injecting a deliberate violation and confirming the guard catches it. *Dependencies:* WP1–WP8 complete.
- **WP10 — Admin UI.** "Receive Stock" sub-view: draft list/detail, product picker, landed-cost entry, Post/Void actions, void-reason capture, capability gating via extended `Purchasing_Caps`.
  *Deliverables:* new `Goods_Receipt_UI` class + admin templates. *Validation:* capability tests pass; manual smoke test of the full operator workflow (§Receiving workflow) on a real dev environment. *Dependencies:* WP7, WP8.
- **WP11 — Documentation & release preparation.** All items in §Documentation.
  *Deliverables:* the 11 documentation artifacts listed above. *Validation:* every quality gate in §Quality gates individually classified; release ZIP builds and inspects clean; `docs/GITHUB_RELEASE_NOTES_1.21.0.md` exists before any tagging is attempted. *Dependencies:* WP1–WP10 complete.

---

## Git guidance

Recommended logical commit grouping (mirrors M3's precedent of small, single-purpose commits — do not collapse the milestone into one large commit):

1. Schema v8 (`install.php`, dispatcher fix)
2. Numbering + Lifecycle
3. Repositories (Goods_Receipts, Receipt_Lines, Receipt_Costs)
4. Goods_Receipt_Costing
5. Movements extension
6. Restock_Service reversal companion
7. Goods_Receipt_Service (post/void, transaction wiring)
8. Idempotency wiring
9. Architecture-guard tests (can land with #7/#8 if that's cleaner given tight coupling — acceptable deviation, unlike bundling unrelated concerns)
10. Admin UI
11. Documentation and release prep

Do not merge, push, tag, or deploy as part of the implementation task itself — mirrors M3's completion-state precedent exactly (implementation branch left committed, clean, unpushed, ready for independent audit).

---

## Risk review

Significantly more detailed than M1–M3's risk reviews, per the explicit instruction that M4 is the highest-risk milestone in the roadmap.

| Category | Risk | Mitigation |
|---|---|---|
| **Transaction** | `WP_Error` from `Restock_Service`/`Movements` silently escapes `DB_Transaction::run()`'s `Exception`-only catch, committing a partial receipt | Mandatory `throw_if_error()` wrapper on every fallible call inside `run()` closures; WP9 architecture-guard test forbids bare `WP_Error` returns there |
| **Transaction** | Assuming `WC_Product::save()` writes don't actually participate in the SQL transaction | Explicit forced-mid-loop-failure integration test proving full rollback of `stock_quantity`/postmeta; documented explicitly in §Transaction model as the key mechanism this milestone relies on |
| **Inventory** | Two lines in one receipt for the same product computed incorrectly (double-application or lost update) | Dedicated integration test proving sequential composition (`apply_purchase_line_change()` re-fetches fresh each call); documented as intentionally allowed, not an edge case to guard against |
| **Inventory** | Cache/transient invalidation accidentally placed inside the transaction closure, invalidating caches for a mutation that then rolls back | Explicit rule in §Transaction model: invalidation strictly after `run()` returns successfully; code-review checklist item |
| **Costing** | Landed-cost rounding drift across many lines | Formula ported unchanged from a production-proven implementation (Batch Intake), not reimplemented from scratch |
| **Costing** | Receiving into zero/never-set average produces a divide-by-zero or `null`-propagation bug | Formula already handles this (`new_stock > 0 ? ... : 0.0`); dedicated zero-inventory unit test |
| **Concurrency** | Double-submit posts or voids twice | Token consumed pre-transaction + conditional compare-and-swap UPDATE as the first transactional write; both paths independently tested |
| **Concurrency** | Genuine concurrent access beyond the single-user-double-click case (e.g. two admins posting different receipts against the same product simultaneously) | Accepted as safe without extra locking — real MySQL transactions serialize the actual row writes; explicitly not engineered beyond this given the single-warehouse/1–3-admin business context (over-engineering risk noted, not just under-engineering) |
| **Rollback (void)** | Naive snapshot-restore on void stomps other receipts' contributions posted in between | Rejected explicitly; replaced with current-state-relative subtraction of this line's own stored delta; dedicated intervening-receipt regression test (§Testing) |
| **Rollback (void)** | Void blocked when stock has been sold below the received quantity, due to non-lot-tracked costing | Honest, actionable guard (`WP_Error`, not a silent partial reversal); documented as an inherent moving-average limitation, not an M4 defect |
| **Rollback (deploy)** | **A plugin-code rollback to a pre-M4 version does not reverse posted receipts' stock/cost effects** — this is new; M1/M2 (schema-only) and M3 (read-only) never had this risk | Explicit new documentation requirement (`docs/rollback-plan.md`, §Documentation item 11); this must be surfaced to operators, not assumed obvious |
| **Audit** | No dedicated `wc_io_receipt_events` table — INV-6 satisfied via header timestamps/actors + typed movement rows only | **Resolved** (§Audit-trail decision): INV-6's "PO event log" clause is structurally inapplicable to PO-less M4 receipts and, per §5.1's entity list, the architecture's actual mechanism for Goods-Receipt auditability is the typed Inventory Movement ledger, which this plan already implements in full; no new information would be gained by a separate table |
| **Operator** | Variable/grouped/external parent selectable in the Quick Receive product picker | Defense in depth: draft-time picker validation + inherited post-time `Restock_Service` rejection (two independent layers) |
| **Operator** | Operator enters landed total exceeding product subtotal, producing a nonsensical allocation | Guard ported unchanged from Batch Intake (`product_subtotal <= 0` rejection when `landed_total > 0`) |
| **Migration** | M4's schema choices box in M5's PO linkage or M6's legacy-batch migration | New `goods_receipt`/`goods_receipt_void` movement types keep provenance distinguishable for M6's future backfill; `po_line_id` present-but-unused avoids a second M5 migration; `source` enum reserves `'po'`/`'mixed'` without M4 ever writing them |
| **Migration/scope** | Scope creep touches `wc_io_purchase_order_lines` or lifts the `qty_received` guard prematurely | `expected_schema_v8()` carries the v7 forbidden-column entry forward unchanged; WP9's architecture-guard scan explicitly checks for any `qty_received`/PO-line-write reference anywhere in M4's new files |
| **Schema** | `expected_schema()` dispatcher silently mis-routes v8 to v7's (incomplete) assertion, producing a false-green schema gate | Exact dispatcher code given in §Database; explicit test asserting the new tables/columns are actually checked, not just declared |

---

## Critical review

Self-challenge pass, performed before finalizing, specifically looking for what the user's directive named: hidden coupling, milestone leakage, transaction ambiguity, audit gaps, rollback weaknesses, costing edge cases, partial-receipt edge cases, implementation shortcuts.

**Hidden coupling.** `Goods_Receipt_Costing` duplicates `Batch_Intake_Service`'s landed-allocation formula rather than sharing it, because the latter is `protected` internal state of a feature scheduled for M6 deletion. This is accepted, justified debt — **explicit remediation trigger stated here:** when M6 retires Batch Intake, extract the shared formula into one class both `Goods_Receipt_Costing` (still needed) and nothing else depend on; do not extract it now, since there is currently no second caller that would justify the abstraction (three near-identical lines is not a premature-abstraction exception, per general engineering guidance — but *is* worth flagging as intentional, tracked debt rather than an oversight).

**Milestone leakage — re-verified against the Scope-boundary ruling:** `po_line_id` is never set by any M4 code path (no setter exists in the service signature — a structural guarantee, not a runtime check); `qty_received` is never referenced; no PO event types are added; no header-level `po_id` column exists on `wc_io_goods_receipts`. **Pass** — nothing from M5 (PO linkage/reconciliation) or M6 (legacy migration) leaks into this plan's scope.

**Transaction ambiguity.** The `DB_Transaction::run()` `Exception`-only-catch gotcha is the single highest-risk implementation detail in this entire milestone — a silent miss here (a code path that returns `WP_Error` without being wrapped in `throw_if_error()`) would produce exactly the "partially succeeds" failure mode this whole plan exists to prevent, and it would likely pass casual manual testing (the happy path is unaffected) while corrupting data on the first genuine failure in production. **This must be the single most scrutinized line item in code review** — every call site inside every `run()` closure, not just the ones this plan happened to enumerate.

**Audit gaps — RESOLVED, see §Audit-trail decision below.** The prior draft of this plan flagged the "no `wc_io_receipt_events` table" call as the weakest-defended decision in the document and left it open for a second human look. That review has now been performed against INV-6's exact text (specifically its "receipt post/void events are recorded in the PO event log" clause) and is recorded in full, as a standalone resolved decision, in **§Audit-trail decision (resolved): no `wc_io_receipt_events` table**, immediately following this section. The verdict is **APPROVE NO RECEIPT EVENTS TABLE**; this is no longer an open item.

**Rollback weaknesses.** Two identified, both already resolved with an explicit decision rather than left open: (1) void-blocked-by-insufficient-remaining-stock — accepted as inherent to non-lot-tracked costing, not fixable within this milestone's scope, and not attempted; (2) **deployment rollback does not reverse posted receipts' business effects** — this is a genuinely new risk class (M1–M3 never mutated stock, so a code rollback was always safe; M4 breaks that assumption) and is elevated to an explicit new documentation deliverable (§Documentation item 11) rather than left as an implicit reader-must-infer-it risk.

**Costing edge cases.** Two lines, same product, one receipt — resolved (sequential composition, tested). Receiving into zero/never-set average — resolved (formula degenerates correctly, tested). Landed total exceeding product subtotal — resolved (guard ported unchanged). Rounding drift across many lines — resolved (remainder-to-last-line formula, already production-proven, unchanged).

**Partial-receipt edge cases.** Resolved explicitly in §Costing/§Receiving workflow: D5's partial/complete concept is PO-relative and does not apply to M4 at all, since M4 receipts have no PO relationship. This is worth restating here because it is exactly the kind of gap that "sounds like it should apply" from the milestone's name ("Receive Stock") without actually applying given the scope ruling — a reader skimming only the roadmap gloss could easily assume otherwise.

**Implementation shortcuts explicitly forbidden (binding, not suggestions):**
- No calling `Restock_Service::apply_purchase_line_change()`/`apply_purchase_line_reversal()` directly from UI/controller code — always through `Goods_Receipt_Service`.
- No bypassing `DB_Transaction` "for just this one simple single-line case" — every post/void goes through `run()`, unconditionally, regardless of line count.
- No reusing `TYPE_PURCHASE`/`TYPE_PURCHASE_BATCH` movement types "since the math is identical" — new `TYPE_GOODS_RECEIPT`/`TYPE_GOODS_RECEIPT_VOID` constants only, for the provenance reasons stated in §Inventory mutation/§Risk review, and always referenced by name, never as inlined string literals (§Inventory mutation).
- No editing any field of a `posted` receipt other than `status`/`voided_at`/`voided_by`/`void_reason` — not even a "harmless" note/reference correction (§Inventory mutation — Receipt immutability).
- No introducing `SELECT ... FOR UPDATE`, table locks, or a distributed lock "to be extra safe" — the token + compare-and-swap combination is the complete, deliberately-scoped concurrency answer for this deployment model (§Idempotency & concurrency).
- No adding a header-level `po_id` column to `wc_io_goods_receipts` "just in case" — D6 is line-level only; an unused header FK would blur a decision this plan makes deliberately.

---

## Audit-trail decision (resolved): no `wc_io_receipt_events` table

This section resolves, as a standalone planning decision, the single item the prior draft of this plan left open (§Critical review — Audit gaps, now closed). It does not revisit or change any other part of this plan.

### The textual tension that had to be resolved

INV-6 (CLAUDE.md §4) reads in full: *"Posted/placed aggregates are never hard-deleted; corrections are lifecycle transitions (void, cancel, close short) with full history. Status changes, expected-date and confidence changes, quantity changes, price corrections, cancellations, close-shorts, reference/tracking updates, **and receipt post/void events are recorded in the PO event log (D17)**."*

Read in isolation, the final clause could seem to require every receipt post/void — including M4's PO-less Quick Receives — to be written into `wc_io_po_events`. Resolving that tension correctly requires reading it against the rest of the frozen architecture, not in isolation, which is what follows.

### Analysis

**1. Is `wc_io_po_events` structurally capable of recording a PO-less receipt's event at all?** No. Its `po_id` column is `NOT NULL DEFAULT 0` and the entity is explicitly described in §5.1 as *"append-only audit history for POs; the PO's complete revision history"* — i.e. it is scoped to a PO as its aggregate root, not to receiving in general. A Quick Receive under M4's scope (§Scope-boundary ruling) has no `po_id` of any kind — there is structurally nothing for such an entry to belong to. INV-6's "PO event log" clause can only be satisfiable for receipts that actually have a PO relationship — i.e. M5's future PO-linked receipts, not M4's.

**2. Did the architecture actually intend a *separate* event-log entity for Goods Receipt itself?** No — and this is the decisive textual signal. §5.1's entity list is thorough and explicit down to fine detail (it even calls out a "post-hoc flag" on Receipt Cost), and it lists **"PO Event"** as its own entity, scoped explicitly to POs. It does **not** list any "Receipt Event" or equivalent entity anywhere. If the architects had intended a parallel event log for Goods Receipt, the same entity list that named PO Event explicitly would have named it too. Its absence is deliberate, not an oversight — the architecture's one and only append-only event-log entity is, by design, PO-scoped.

**3. What auditability mechanism did the architecture actually design for Goods Receipt?** §5.1 states the Inventory Movement entity is *"the existing ledger, gaining typed references (`reference_type`/`reference_id`) and a `supplier_id`"* — this ALTER exists specifically to serve Goods Receipt's auditability needs (D15: *"typed references, receipt-post/void movements"*). This is the architecture's actual, explicit, textually-grounded answer for how receipt post/void events get durably recorded — and it is exactly what this M4 plan already builds (`TYPE_GOODS_RECEIPT`/`TYPE_GOODS_RECEIPT_VOID` movement rows, `reference_type='goods_receipt'`, `reference_id=<receipt id>`, §Database/§Inventory mutation).

**4. Does a `wc_io_receipt_events` table add any information the plan's existing mechanism doesn't already capture?** No, checked exhaustively against M4's exact lifecycle (`draft → posted → voided`, only three moments in a receipt's life):
- **Created** → `created_at`/`created_by` on the header.
- **Posted** → `posted_at`/`posted_by` on the header (the moment-in-time fact) plus one `TYPE_GOODS_RECEIPT` movement row per line (the exact effect: quantity, cost, old/new stock, old/new average, old/new inventory value, actor, timestamp).
- **Voided** → `voided_at`/`voided_by`/`void_reason` on the header (who, when, and — uniquely among the three transitions — *why*) plus one `TYPE_GOODS_RECEIPT_VOID` movement row per line (the exact reversal effect).

Every WHO/WHEN/WHAT/WHY fact INV-6's "full history" principle asks for is already captured, exactly once, non-redundantly, in the mechanism the architecture itself names for this purpose (movements) plus the header fields this plan already strengthened into an explicit invariant (§Inventory mutation — Receipt immutability). A `wc_io_receipt_events` table would not surface any new fact — it would, at best, offer a different *presentation* (a single chronological timeline view) of facts already durably stored elsewhere. That is a UI convenience, not an auditability gap.

**5. Would adding it be unnecessary schema speculation (D16)?** Given point 4, yes for M4's actual, narrow, two-transition lifecycle: D16 explicitly disfavors speculative schema *"until a concrete consumer exists."* Here, the "concrete consumer" test cuts the other way from typical D16 cases — the information already exists and is already durably queryable; a new table would duplicate it, not newly satisfy an otherwise-unmet requirement.

**6. Does M5's PO-linked receiving create a real future need — and where should it be met?** Yes, and this is where INV-6's "PO event log" clause becomes literally, structurally satisfiable: once M5 populates `receipt_line.po_line_id`, a receipt posting/voiding against a PO line *is* exactly the kind of "quantity change" INV-6 already requires the PO event log to capture. The architecturally consistent way to satisfy INV-6 there is for M5 to add new PO event type(s) (e.g. mirroring the existing `po_line_changed`/`po_line_cancelled` pattern) written via the **already-built** `WC_Inventory_Overview_PO_Events::add()` API when a PO-linked receipt posts or voids — zero new schema required, and it is the literal mechanism INV-6 names. This is a forward-looking note for M5's own future plan, not a commitment this M4 plan makes on M5's behalf.

**7. Cost, if added anyway:** another table participating in every `post()`/`void()` transaction (§Transaction model), another repository/write path to keep inside the single-entry-point invariant (§Transaction model — Repository-write ownership), another set of unit/integration tests, another schema-shape assertion entry, permanent additional surface for M6's eventual migration to reason about — all to record facts the plan's existing, architecture-named mechanism already records once. Pure overhead with no informational benefit.

### Decision

**APPROVE NO RECEIPT EVENTS TABLE.**

**Rationale (summary):** INV-6's "PO event log" clause is structurally inapplicable to PO-less receipts (point 1); the architecture's own entity list (§5.1) deliberately names only one event-log entity, scoped to POs, and does not name a Goods-Receipt equivalent (point 2); the architecture's actual, textually-explicit auditability mechanism for Goods Receipt is the typed Inventory Movement ledger (point 3), which this plan already implements in full; a dedicated events table would add no information beyond what the header fields and movement rows already capture for M4's exact three-moment lifecycle (point 4); adding one now would be unnecessary schema speculation under D16 given no unmet requirement exists (point 5); and INV-6's PO-event-log clause has a clean, zero-new-schema future home once M5 introduces PO-linked receiving (point 6).

**Confirmation:** the existing M4 plan — schema (§Database), transaction model (§Transaction model), inventory mutation design (§Inventory mutation), work packages (§Implementation sequence), Definition of Done, and every other section — **remains unchanged by this decision**. No section required amendment beyond closing out the two cross-references noted below, both of which restate this same now-closed decision rather than alter any design.

**M5 may revisit this decision only through a formally approved plan amendment**, and only if PO-linked receiving introduces audit requirements this analysis did not anticipate — specifically, if it turns out INV-6's PO-event-log clause cannot be fully satisfied by adding new PO event types to the existing `wc_io_po_events` mechanism (point 6) once `po_line_id` linkage exists. Absent such an amendment, this decision stands as frozen per §Implementation freeze statement / §Future-extension rule (audit behavior is one of the explicitly frozen items).

---

## Definition of Done

- [ ] Schema v8: three new tables, `inventory_movements` ALTER (3 new columns), `expected_schema_v8()` + dispatcher fix, unique-index check on `receipt_number`, `DB_VERSION = '8'`.
- [ ] `qty_received` still absent from `wc_io_purchase_order_lines` (forbidden-column verification passes, unchanged from v7).
- [ ] `wc_io_receipt_lines.po_line_id` exists, is indexed, and is never set by any M4 code path (structural guarantee — no setter parameter exists).
- [ ] `Goods_Receipt_Numbering`, `Goods_Receipt_Lifecycle` implemented and unit-tested, mirroring `PO_Numbering`/`PO_Lifecycle` patterns exactly.
- [ ] `Goods_Receipts`, `Receipt_Lines`, `Receipt_Costs` repositories implemented; draft mutations have zero stock effect (tested).
- [ ] `Goods_Receipt_Costing` implements the ported, unmodified landed-allocation formula.
- [ ] `Movements` gains `TYPE_GOODS_RECEIPT`/`TYPE_GOODS_RECEIPT_VOID`, `insert_goods_receipt()`/`insert_goods_receipt_void()`; existing `purchase`/`purchase_batch`/`cost_adjustment` behavior unchanged (regression-tested).
- [ ] `Restock_Service::apply_purchase_line_reversal()` implemented and independently unit-tested (subtraction math, negative-stock guard).
- [ ] `Goods_Receipt_Service::post()`/`void()` wrap all mutation in `WC_Inventory_Overview_DB_Transaction::run()`; every fallible call is routed through `throw_if_error()`; no direct `$txn->rollback()` calls exist outside `DB_Transaction` itself (architecture-guard test enforces this).
- [ ] Forced-failure tests prove full SQL rollback: stock/avg/value unchanged, zero partial movement rows, receipt status unchanged, for failures injected at every stage (status race, stock mutation, movement insert) and at every line position in a multi-line receipt.
- [ ] The intervening-receipt void regression test (post A, post B same product, void A, assert B's contribution intact) passes.
- [ ] Idempotency: reused `PO_Request_Token` contexts (`gr_post`/`gr_void`) plus conditional compare-and-swap UPDATE both independently tested.
- [ ] Capability gating via extended `Purchasing_Caps` (new action keys, default `manage_woocommerce`, no new capability registered).
- [ ] "Receive Stock" admin UI functional end-to-end: draft create/edit/delete, product picker excludes variable/grouped/external, landed-cost entry, preview, Post, Void with reason.
- [ ] All required unit, integration, architecture-guard, capability, idempotency, and rollback tests exist and pass; M0 golden suite and existing characterization fixtures unchanged.
- [ ] `tests/docker/run-phpunit.sh` blocking filter includes the Goods Receipt test prefix.
- [ ] No `po_line_id` population, no `qty_received`, no PO-side status/lifecycle change, no PO event types added, no legacy batch-table access, no Dashboard/storefront change, no new REST/AJAX endpoint, no new capability, no row-locking/queueing infrastructure anywhere in the diff.
- [ ] Batch Intake, Quick Restock, Cost Adjustment, PO admin, and Supplier admin behavior all unmodified.
- [ ] All 11 documentation deliverables complete, including `docs/GITHUB_RELEASE_NOTES_1.21.0.md` existing **before** any tag is attempted, and the new `docs/rollback-plan.md` note on deploy-rollback not reversing posted-receipt effects.
- [ ] All quality gates executed and individually classified; every gate is PASS or PASS WITH KNOWN PRE-EXISTING (legacy, pre-M4) FAILURES — no new failure introduced by M4; the new transaction-rollback/void-correctness/idempotency/stock-mutation-correctness gates all PASS outright (not eligible for "known pre-existing failure" status, since they test new M4 code).
- [ ] Version prepared as `1.21.0`; not tagged, not released.
- [ ] Implementation branch left committed, clean, unpushed, unmerged, ready for independent audit — mirroring M3's completion-state precedent.
- [ ] No alternative inventory mutation path was introduced — `Goods_Receipt_Service` remains the sole caller of `Restock_Service::apply_purchase_line_change()`/`apply_purchase_line_reversal()`, and no controller, repository, admin page, AJAX/REST endpoint, CLI command, or UI component persists receipt status, receipt lines, inventory movements, WooCommerce stock, inventory value, average cost, or product meta outside `Goods_Receipt_Service`'s own transaction (§Transaction model — Single inventory mutation entry point / Repository-write ownership).

---

## Implementation freeze statement

**No implementation may introduce additional receipt states, database tables, transaction boundaries, inventory mutation paths, Purchase Order integration, or architectural behavior beyond what is defined in this implementation plan, unless this implementation plan is formally revised and re-approved.** This mirrors the discipline carried through every previous milestone (M1–M3): the plan is the specification, not a starting point for in-flight redesign. Any implementer who believes a deviation is warranted stops and requests a plan revision — they do not silently extend scope, add a state, or introduce a second mutation path "while they're in there."

### Service ownership freeze

Mirroring the same discipline M3 established for `WC_Inventory_Overview_Inventory_Position_Service` as the sole D12 calculator: **`Goods_Receipt_Service` is, and remains, the only posting/voiding implementation for Goods Receipts — permanently, not just for the duration of M4.** All future receipt-capable work — including M5's Purchase-Order-linked receiving, any future REST endpoint, any future CLI command, any future import tool, any future automation, and any future scheduled job that posts or voids a receipt — **must call into `Goods_Receipt_Service`**, exactly as M4's own admin UI does (§Receiving workflow, §Transaction model — Single inventory mutation entry point). No alternative posting implementation may ever be introduced alongside it, in this milestone or any later one. M5 extending this service (e.g. by adding a `post_against_po()` variant that still funnels through the same underlying transaction machinery) is consistent with this rule; a second, parallel service that also mutates stock/cost is not, regardless of which milestone proposes it.

### Future-extension rule

Future milestones may extend Goods Receipt **only through additive behavior** — new capabilities layered on top of what M4 defines, never a parallel or divergent implementation of what M4 already defines. The following are **frozen as of this plan and remain frozen unless a future implementation plan explicitly revises them** (a future plan may extend or build upon them; it may not silently reinterpret or bypass them):
- lifecycle states (`draft → posted → voided`, D10 — no fourth state, no reopen path)
- transaction semantics (§Transaction model — one `DB_Transaction::run()` closure per post/void, the `throw_if_error()` bridging pattern, the transaction-lifetime boundary)
- mutation algorithms (the weighted-average formula and its void-time reversal, §Costing/§Inventory mutation — unmodified from what this plan specifies)
- movement provenance (`TYPE_GOODS_RECEIPT`/`TYPE_GOODS_RECEIPT_VOID`, `reference_type`/`reference_id` pointing at the receipt header — §Inventory mutation/§Database)
- audit behavior (header timestamp/actor columns + typed movement rows as the audit trail — resolved and frozen, §Audit-trail decision)
- posting semantics (the compare-and-swap/idempotency-token combination, §Idempotency & concurrency)

A milestone that needs to change any item on this list is proposing a *revision to this plan*, not an extension of it, and must be reviewed as such.

---

## READY FOR IMPLEMENTATION

Every open design question raised during research was resolved with one explicit, justified answer — the M4/M5 scope boundary, the schema shape, the transaction/rollback pattern (including the `Exception`-only-catch gotcha), the void-correctness algorithm (including the multi-receipt-in-between risk), the costing/landed-cost approach, idempotency/concurrency posture, movement-type/audit design, and the full non-goals list. No section of this plan leaves two options on the table for an implementer to choose between.

The one item previously flagged for a second human look — whether to add a `wc_io_receipt_events` table — has since been resolved against INV-6's exact text and closed as **APPROVE NO RECEIPT EVENTS TABLE** (§Audit-trail decision). No other open items remain.

This plan is unconditionally **READY FOR IMPLEMENTATION**.

---

## Summary of Final Refinements

This refinement pass added precision only — it did not alter scope, architecture, database design, transaction flow, lifecycle states, the implementation sequence, algorithms, or work packages. Every item below is a clarification of something already decided in the original plan, not a new decision.

1. **Single inventory mutation entry point** — elevated from an item in the Critical review's "implementation shortcuts" list into an explicit, binding architecture rule (new subsection at the top of §Transaction model, plus a strengthened bullet in §Architecture constraints): `Goods_Receipt_Service` is the only permitted caller of `Restock_Service`'s mutation methods, for any controller/repository/admin page/AJAX/REST/CLI/UI code path.
2. **Explicit optimistic locking** — the compare-and-swap behavior, previously described only in prose, is now given as exact SQL pseudocode for both posting and voiding, with the zero-rows-affected abort behavior (no inventory mutation, no movement creation, no PO updates) stated explicitly (new "Optimistic concurrency control" subsection in §Transaction model).
3. **Receipt immutability** — the existing brief mention ("`updated_at`/lines/costs are never mutated again") is strengthened into a precise invariant naming the exact four fields that remain mutable after posting (`status`, `voided_at`, `voided_by`, `void_reason`) and stating explicitly that everything else — supplier, currency, exchange rate, landed costs, notes, reference, receipt lines — becomes permanently read-only (new subsection in §Inventory mutation).
4. **Concurrency expectations** — the deliberate no-row-locking decision is now documented explicitly as intentional, with its justification (single-warehouse/1–3-admin deployment, low posting frequency, MySQL transaction serialization already sufficient) and an explicit list of what is *not* introduced (`SELECT ... FOR UPDATE`, row locks, table locks, distributed locks), so the omission cannot be mistaken for an oversight (new §Idempotency & concurrency section — this also fixes two previously-dangling `§Idempotency & concurrency` cross-references in §Core responsibilities and §Scope that had no matching section in the original draft).
5. **Weighted-average worked example** — one complete numerical example (10 units @ 8.00, receive 5 @ 12.00, → 140.0000 / 15 = 9.333333) added immediately after the formula as a permanent characterization reference. The formula itself is untouched.
6. **Idempotency token lifecycle** — the token's generate/single-use/consume-before-transaction/never-reusable/refresh-and-back-button-resilient behavior is now stated as an explicit, ordered lifecycle rather than left implicit in the transaction-model prose (new §Idempotency & concurrency section).
7. **Movement-type constants** — added an explicit coding-standard requirement that `TYPE_GOODS_RECEIPT`/`TYPE_GOODS_RECEIPT_VOID` must be referenced as named class constants, never as inlined string literals, with a cross-reference added to the Critical review's forbidden-shortcuts list. Coding-standard clarification only, no design change.
8. **Implementation freeze statement** — added immediately before the final verdict, mirroring the discipline used throughout M1–M3: no implementer may add states, tables, transaction boundaries, mutation paths, or PO integration beyond this document without a formal plan revision.

**Confirmed:**
- No scope changed — the Scope-boundary ruling, In Scope/Out of Scope/Explicit Non-Goals lists, Dependencies, and Success criteria are unmodified.
- No architecture changed — D3/INV-2 sole-mutator, D6/D7 line-level linkage, D10's three-state lifecycle, INV-8 variation-only referencing, and every other cited decision/invariant are unmodified; refinements only sharpened how they are expressed.
- No implementation sequence changed — WP1 through WP11, their objectives, deliverables, validation, and dependencies are unmodified; so is §Git guidance's commit grouping.
- No new functionality was introduced — every refinement documents a behavior the original plan already implied (sole entry point, compare-and-swap, immutability, no-locking posture, token lifecycle, constant usage) or illustrates an unmodified formula (the worked example); nothing new is built. (The judgment call referenced here — §Critical review — Audit gaps, the `wc_io_receipt_events` table question — was subsequently resolved in a dedicated follow-up review as **APPROVE NO RECEIPT EVENTS TABLE**; see §Audit-trail decision. It is no longer open.)

This document is now the definitive, frozen implementation specification for Milestone M4. (The one item flagged at the end of this pass — whether to add a `wc_io_receipt_events` table — was subsequently resolved in a dedicated follow-up review; see §Audit-trail decision.)

---

## Final Documentation Freeze

This second refinement pass incorporated the eight clarifications below. As with the first pass, every change makes an already-decided rule more explicit and harder to violate — none introduces new functionality, changes implementation behavior, or moves responsibility between milestones or classes.

1. **Repository-write ownership** — the "single inventory mutation entry point" invariant (§Transaction model) is strengthened from covering only the costing call to covering every persistence operation a post/void performs: receipt status, receipt lines, inventory movements, WooCommerce stock, inventory value, average cost, and product meta may all only be written from inside `Goods_Receipt_Service`'s own transaction — never by any other controller, repository caller, admin page, AJAX/REST endpoint, CLI command, UI component, or future integration.
2. **Transaction lifetime** — a new "Transaction lifetime boundary" subsection formalizes that the transaction begins immediately before persistence, ends immediately after, and never spans user interaction, redirects, confirmation dialogs, external network operations, or long-running processing.
3. **Receipt number immutability** — a new explicit invariant on `wc_io_goods_receipts.receipt_number`: allocated exactly once, never modified, never reused, and permanently attached to the receipt even after voiding.
4. **Service ownership freeze** — a new subsection states that `Goods_Receipt_Service` is permanently the sole posting/voiding implementation; all future receipt-capable work (M5's PO-linked receiving, any future REST/CLI/import/automation/scheduled-job surface) must call into it, and no alternative posting implementation may ever be introduced alongside it.
5. **No irreversible side effects before commit** — the existing "what cannot participate" rule is expanded into an explicit prohibition list (cache invalidation, external-consumer hooks, emails, notifications, webhooks, async/scheduled jobs, external logging), all bound to run only after a successful commit, with the rationale stated (a pre-commit side effect can propagate outside the transaction boundary a `ROLLBACK` can no longer reach).
6. **Rollback invariant** — a new formal, quoted invariant: *"After any failed posting or void operation, the database must be observationally identical to its state immediately before the operation began,"* stated as an architectural guarantee that every forced-failure test exists to verify, not merely a description of what the transaction helper happens to do.
7. **Future-extension rule** — the Implementation freeze statement gains an explicit list of what stays frozen unless a future plan formally revises it: lifecycle states, transaction semantics, mutation algorithms, movement provenance, audit behavior, and posting semantics. Future milestones may only extend Goods Receipt additively, never through a parallel or divergent implementation.
8. **Definition of Done** — one new checklist item added: "No alternative inventory mutation path was introduced," cross-referencing the strengthened single-entry-point/repository-write-ownership invariants.

**Confirmed:**
- **No scope changed** — In Scope, Out of Scope, Explicit Non-Goals, Dependencies, and Success criteria are byte-for-byte as they were before this pass.
- **No architecture changed** — D3/INV-2 sole-mutator, D6/D7 line-level linkage, D10's three-state lifecycle, INV-8, and every cited decision/invariant remain exactly as decided; this pass only sharpened their expression and closed off future circumvention.
- **No implementation sequence changed** — WP1–WP11 (objectives, deliverables, validation, dependencies) and §Git guidance are unmodified from both the original plan and the first refinement pass.
- **No transaction semantics changed** — the transaction still begins/commits/rolls back exactly as originally specified (§Transaction model); this pass added a formal lifetime boundary and a formal rollback-invariant statement around the *same* mechanism, not a different one.
- **No functionality changed** — nothing new is built; every item above documents a rule the plan already implied (ownership, timing, immutability, freeze) or restates an existing mechanism (rollback) as a named, quotable invariant.

This document — the original plan plus both refinement passes and the subsequent §Audit-trail decision — is the definitive, frozen implementation specification for Milestone M4, suitable to be committed as the canonical implementation plan before coding begins. The single item carried forward as open through both refinement passes (§Critical review — Audit gaps) has since been resolved as **APPROVE NO RECEIPT EVENTS TABLE**; no open items remain.
