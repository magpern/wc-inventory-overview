# M25 Definitive Implementation Plan — Bulk Draft PO Creation

*Materialized at WP-M25-0 as the immutable architecture specification for M25. This document is Revision 2 of the approved plan (produced during planning, hardened against a full user review that identified and required fixing 13 issues in Revision 1) plus a final set of pre-implementation review amendments (§48, Amendments A–F) that supersede conflicting wording in Revision 2 without reopening the planning phase. This file is immutable from the moment of its dedicated plan commit — it is never rewritten to match implementation. Any implementation discoveries, deviations, measured decisions, or review findings belong in `docs/checklists/m25-release-readiness.md`.*

*Revision 2 — amended after user review. The review correctly rejected Revision 1 for implementation and required a targeted amendment addressing 13 issues, the most important being: Revision 1 explicitly left concurrent/duplicate replenishment "not prevented," which is exactly the race the original mission required M25 to solve. This revision adds a real, schema-free, item-level advisory-locking + conflicting-open-line-detection mechanism (§16) that serializes concurrent commits and catches immediate-retry duplication, corrects a POST-parsing contradiction (§7/§11), changes line-selection defaults from checked to unchecked to avoid an over-the-cap default state (§7), splits failure-injection testing into per-group-continuation vs. catastrophic-interruption seams (WP-M25-3), strengthens partial-success retry safety (§14, now structurally backed by §16's conflicting-line check), fixes a result-transient cross-tab collision risk (§21), freezes `commit()`'s return contract as `array|WP_Error` (WP-M25-2), removes a self-contradictory quantity-rounding claim (§6), adds a group-count performance-measurement gate (§24), documents the residual intra-commit TOCTOU window honestly instead of implying full coverage (§11/§15), adds a real preflight check that surfaced an actual repository-state discrepancy (§2/WP-M25-0), and corrects the WP1→WP2→WP3→WP4 agent-boundary sequencing and plan-immutability language (§28/§46/WP-M25-8).*

**M24 origin/CI precondition — satisfied at WP-M25-0 materialization time**: verified via `git ls-remote origin` and `gh pr list --state all` that no `feature/m24-replenishment-planning` ref or M24 PR existed on origin prior to this milestone. This was corrected as part of WP-M25-0: the M24 branch was pushed to origin and draft PR **#32** opened (`https://github.com/magpern/wc-inventory-overview/pull/32`), targeting `main`, explicitly marked "must not be merged." All three CI checks (PHP Parallel Lint, PHP lint and build ZIP, PHPUnit) passed green against the exact frozen M24 tip `3c21a69e6402d631575ded4653435dbaa6dbe435` before M25 branched. M24 remains unmerged, untagged, unreleased — only its CI-durability precondition was closed.

---

## Context

M24 (Replenishment Planning Screen) is implemented, frozen at `3c21a69e6402d631575ded4653435dbaa6dbe435`, now genuinely origin-backed and CI-green (PR #32), unreleased, dev version 1.41.0, DB_VERSION 11. It gives merchants a read-only, supplier-grouped worklist of everything that currently needs reordering. It deliberately created zero PO-creation capability — reserved for M25.

M25 closes the loop: an operator reviews the M24 plan, edits/selects quantities, and creates one Draft PO per supplier group in a single confirm action. This is the plugin's first *multi-record* mutation milestone, and its first milestone requiring any form of application-level concurrency serialization beyond a single row's `FOR UPDATE`.

---

## 1. Executive Architecture Verdict

M25 is buildable entirely from existing, proven primitives plus **one new, small, schema-free primitive**: MariaDB named advisory locks (`GET_LOCK`/`RELEASE_LOCK`), used to serialize concurrent commits touching the same catalog item. Everything else remains as Revision 1 established:

- A thin orchestrator (`WC_Inventory_Overview_Replenishment_Commit_Service`) rebuilds the plan fresh, server-side, scoped to submitted item ids (`Replenishment_Planning_Service::build_plan()`, unmodified), then — **now under item-level advisory locks held for the duration of the whole commit** — checks for items that already have an open-or-draft PO line, then calls `PO_Service::create_draft()` once per resolved supplier group, in ascending supplier-id order, each in its own independent transaction.
- **Partial success remains the correct, embraced per-group semantic** (§12, unchanged from Revision 1) — but Revision 2 now distinguishes this cleanly, in both design and tests, from a catastrophic mid-batch interruption (§ WP-M25-3).
- **Supplier and currency are still never taken from the browser** (§8/§9, unchanged).
- **Idempotency still reuses `WC_Inventory_Overview_PO_Request_Token` verbatim** (§14, unchanged mechanism, strengthened retry-safety story).
- **Concurrent/duplicate replenishment is now actually addressed**, not deferred wholesale: item-level locking prevents two simultaneous commits from both creating a line for the same item, and a new conflicting-open-line check (reusing the exact bulk-query shape already established by M24's `distinct_supplier_history_for_items_bulk()`) prevents an immediate retry from duplicating an item that a prior partially-successful commit already created a line for. What remains genuinely out of scope — a deliberate, later, unrelated re-order of an item whose earlier draft has since transitioned out of the conflict-status set — is correct business behavior, not a gap (§16, and see §48 Amendment A for the exact frozen conflict-status set).

**Repository-reality correction retained from Revision 1**: no "Level B" lifecycle tier is formally defined anywhere in this repo. M25 uses the same WP0–WP6 skeleton every milestone uses, with the WP1(implementation)/WP2(independent audit)/WP3(remediation)/WP4(freeze) boundary made explicit as **separate agent stops**, not a single continuous pass (§28/§46).

---

## 2. Repository Findings

- `main`/`origin/main` untouched. `feature/m24-replenishment-planning` frozen at `3c21a69e6402d631575ded4653435dbaa6dbe435`, now pushed to origin, PR #32 open (draft, must not merge), all CI checks green.
- `wc-inventory-overview.php` Version header: `1.41.0` (M24). `DB_VERSION = '11'`.
- Version-bump convention confirmed across four prior trains: each milestone in a train bumps dev version by exactly `+0.01`; the last milestone's own dev version becomes the literal release tag. M25 develops at **`1.42.0`**.
- `tests/docker/run-phpunit.sh:231` is the actual CI test-class allowlist (not any `.yml` file); a new prefix must be added there for M25.

---

## 3. M24 → M25 Ownership Boundary

| Concern | Owner | Touched by M25? |
|---|---|---|
| Needs-reorder discovery, supplier/qty resolution, grouping | `Replenishment_Planning_Service::build_plan()` | **No** — called unmodified |
| Supplier precedence decision | `Supplier_Preference_Resolver::decide()` | **No** — reused transitively |
| Planning tab read-only rendering | `Purchasing_Page::render_planning_tab()` | **Additive only** |
| PO header/line/event mutation | `PO_Service::create_draft()` and friends | **No** — called, never modified |
| Bulk-commit orchestration (new) | `Replenishment_Commit_Service` | **Yes — new file** |
| Commit-time admin POST/PRG (new) | `Replenishment_Commit_Admin` | **Yes — new file** |
| **Item-level advisory locking (new)** | `Replenishment_Item_Lock` | **Yes — new file** |
| **Conflicting-open-line existence check (new)** | `Purchase_Order_Lines::list_open_or_draft_item_ids_bulk()` (additive method) | **Yes — additive method only, existing class** |

`git diff 3c21a69..<m25-tip> -- includes/class-wc-inventory-overview-replenishment-planning-service.php includes/class-wc-inventory-overview-supplier-preference-resolver.php` must remain empty at every checkpoint (unchanged architecture guard).

---

## 4. Existing PO Creation Architecture

`WC_Inventory_Overview_PO_Service::create_draft( array $header, array $lines = array() ): int|WP_Error` — supplier `FOR UPDATE`-locked and rechecked internally, currency falls back to the supplier's own `default_currency` when omitted, `qty_ordered` must be `> 0` (`PO_Quantities::validate_quantities()`, no upper bound, decimals allowed, no decimal-place restriction enforced by the validator itself — see §6), `unit_cost` defaults to `0.0`, one transaction per call, one `PO_Events::add(TYPE_CREATED)` per PO. `PO_Product_Validator::validate()` runs internally per line as a second, independent identity check. Capability `EDIT_PO` (no `CREATE_PO` constant exists). Idempotency via `PO_Request_Token::issue()/consume()`, one-shot, 600s TTL. Only 3 `FOR UPDATE` sites exist repo-wide (PO, PO line, supplier), all single-row — no existing multi-row/multi-entity batch-locking precedent beyond M17's fixed two-supplier `min(id)`/`max(id)` ordering, which this plan's item-lock ordering (§16) generalizes to N items.

---

## 5. Commit-Time Source-of-Truth Contract

The POST is trusted only for **which concrete items** and **how much**. Everything else is recomputed via a single `build_plan( array(), $item_ids )` call. This rebuild happens *after* item-level locks are acquired (§16), so the rebuild itself is guaranteed not to race against another M25 commit touching the same items while it runs.

---

## 6. Quantity Contract

M25 performs no server-side rounding or precision truncation of any kind.

- Operator may edit every line's quantity; `qty_suggested` pre-fills but is never authoritative.
- Validation reuses `PO_Quantities::validate_quantities()` verbatim — `qty_ordered > 0`, decimals allowed, no upper bound, no decimal-place rule at that layer.
- A separate, M25-owned **request-shape** sanity cap (not a business rule, not a precision rule) rejects non-finite/non-numeric input and values `> 1,000,000` as a malformed-request guard, independent of `PO_Quantities`.
- The `step="0.0001" min="0"` HTML attributes on the quantity input are **presentation guidance only** (matching the exact, already-established convention used by PO `qty_ordered`, Goods Receipt `qty`, and M23's default-qty inputs) — they do not constrain what the server accepts or how the value is stored.
- **Zero or blank quantity on a selected line is a validation error, not an implicit skip** (see §7): the operator must either uncheck the line or supply a positive quantity.
- `Replenishment_Defaults::default_qty` is never rewritten by M25 (INV-M25-19).
- The submitted quantity is persisted exactly as entered (after validation), subject only to the existing `(float)` cast already performed by `Purchase_Order_Lines::create()` — no additional cast, round, or truncation.

---

## 7. Selection Contract

**Corrected POST-parsing contract** (also reflected in §11 step 4 and WP-M25-4):
- The browser submits **all** rendered rows' hidden `product_id`/`variation_id`/`qty` fields regardless of checkbox state — HTML omits only the checkbox field itself when unchecked, never the sibling fields in the same row.
- Server-side parsing proceeds in two strict phases: **(1)** iterate all submitted rows and filter to only those where `items[<idx>][selected]` is present and truthy — this filtered set is the *only* thing any subsequent step (identity/qty validation, `MAX_COMMIT_LINES` counting) ever operates on; **(2)** for each row that survived phase 1, validate `product_id`/`variation_id` (must be non-negative integers, not both zero) and `qty` (numeric, `> 0`, `<= 1,000,000`) — **a selected row with a blank, zero, non-numeric, or out-of-range quantity fails the whole request** (PRG back to the Planning tab with a specific validation notice identifying which product failed, not `wp_die`, since this is a normal, correctable operator mistake — distinguished from the structurally-malformed case, which *does* `wp_die(400)`).
- Rows never selected are read and immediately discarded — never validated, never counted toward the 100-line cap, never logged as an error.

**Default-unchecked state**: **every line defaults to unchecked.** Safer for a multi-PO financial mutation, and structurally avoids an over-the-cap-by-default problem (an unchecked line contributes nothing to the count regardless of how many resolved lines the catalog-wide plan displays).

- A "select all in this group" control remains pure client-side JS convenience — introduces no new server field.
- The form maintains a live running count of checked boxes across the *entire* document (all groups) and disables not-yet-checked boxes once 100 are checked, with an always-visible "`<n>` of 100 selected" indicator. UX assistance only — the server remains the sole authority on the 100-line cap (§24).
- Unresolved lines remain non-interactive — no checkbox, no input, byte-unchanged markup from M24.
- A stale-preferred-supplier line remains selectable on the same terms as any other resolved line (§22).

---

## 8. Supplier Authority

No `supplier_id` POST field exists at all; grouping is 100% server-derived from the rebuild (§5).

---

## 9. Currency Authority

`create_draft()` calls never populate `$header['currency']`; the supplier's freshly re-read, lock-held `default_currency` is used via `create_draft()`'s own existing fallback.

---

## 10. Product/Variation Identity

Falls out of reusing `build_plan()`'s scoped rebuild; `PO_Product_Validator::validate()` provides an independent second check inside `create_draft()`. See §48 Amendment D for the exact quantity/identity mapping contract.

---

## 11. Revalidation Pipeline

1. **Capability**: `EDIT_PO` — else `wp_die(403)`.
2. **Nonce**: `check_admin_referer('wc_io_replenishment_commit', 'wc_io_replenishment_commit_nonce')`.
3. **Idempotency token**: `PO_Request_Token::consume($token, 'replenishment_commit')` — else `wp_die(400)`.
4. **Request-shape validation** (§7's two-phase contract): filter to selected rows; reject the whole request with `wp_die(400)` if structurally malformed; reject with a PRG validation notice (not `wp_die`) if any *selected* row's quantity is invalid; reject with `wp_die(400)` if the selected-row count exceeds `MAX_COMMIT_LINES`.
5. **Compute `$item_post_ids`** from the surviving selected rows — normalized per §48 Amendment C (canonical derivation, validation, dedup, ascending sort).
6. **Acquire item-level advisory locks** (§16) — `Replenishment_Item_Lock::acquire($item_post_ids)`, ascending numeric order. Any item whose lock could not be acquired within the timeout is immediately moved to `skipped` (`reason: concurrent_commit_in_progress`) and excluded from every subsequent step (see §48 Amendment E — item-scoped exclusion, never downgraded to an unlocked read/retry).
7. **Bounded revalidation**: one `build_plan( array(), $item_ids )` call, scoped to the lock-acquired items only.
8. **Cross-reference & bucket**: resolved vs. unresolved/not-found → `skipped`, per §5.
9. **Check conflicting open/draft lines** (§16, exact status set frozen by §48 Amendment A): one bulk `Purchase_Order_Lines::list_open_or_draft_item_ids_bulk()` call against the resolved survivors; any hit → `skipped` (`reason: already_has_open_po_line`).
10. **Group** remaining survivors by fresh `supplier_id`, ascending order.
11. **Per group, sequentially**: `create_draft()` — no `currency`, no `expected_date`, no per-line `unit_cost`, `note = 'Created from Replenishment Planning'`. Quantities mapped to identities per §48 Amendment D (never by array index/order).
12. **Release all acquired locks** (`finally` — always runs, including on any exception in steps 7–11).
13. **Aggregate** `{created, failed, skipped}`; PRG redirect with result-transient (§21).

**Honest residual-TOCTOU documentation**: holding every selected item's lock for the *entire* commit (steps 6–12) fully closes the window against **other M25 commits** touching the same items. It does **not** close a window against **non-M25 mutations elsewhere in the system** (a manual Restock, a manual PO placement via the ordinary, lock-unaware PO edit screen, a direct stock edit) that happen to touch the same product *during* this commit's own processing time. This residual window is bounded by the commit's own total wall-clock duration (expected sub-second to low-single-digit seconds, confirmed by measurement at WP-M25-7) — far narrower than the original render-to-commit window (which could span minutes). Deliberately not addressed by re-running `build_plan()` once per group, since that would reintroduce a full rebuild cost per group for a marginal safety improvement against an already-small, already-bounded window. This residual limitation must be repeated verbatim in `docs/checklists/m25-release-readiness.md` — an accepted, bounded, documented gap, not an oversight.

---

## 12. Atomicity Decision

One transaction per supplier group, explicit partial-success semantics, justified by reuse of `create_draft()`'s own already-proven transaction rather than inventing cross-group transaction/locking machinery. Item-level locks (§16) are advisory (`GET_LOCK`/`RELEASE_LOCK`), entirely independent of and orthogonal to each group's own InnoDB transaction — a group's transaction still commits or rolls back independently; the item locks merely gate *whether a given item is allowed to reach the grouping/creation step at all* in this commit, and are released only after all groups have been attempted, never per-group.

---

## 13. Transaction Ownership

`Replenishment_Commit_Service` is a pure orchestrator — no `$wpdb`, no `DB_Transaction` construction. It owns calling `Replenishment_Item_Lock::acquire()/release()` and the conflicting-line check — both read/lock operations, not mutations.

---

## 14. Idempotency / Token Design

Mechanism: `PO_Request_Token`, context `'replenishment_commit'`, consumed before step 7 of §11.

Because §16's conflicting-open-line check runs fresh, under lock, on *every* commit — including an immediate retry — any item that a prior (even seconds-ago) commit already created a line for will be found by `list_open_or_draft_item_ids_bulk()` and automatically routed to `skipped` (`reason: already_has_open_po_line`) rather than silently duplicated. **A blind full retry is safe by construction.** The result-transient (§21) surfaces which items were skipped for this reason with a positive, reassuring message ("Already included in a recently created purchase order").

---

## 15. Concurrency Model

| Race | Outcome | Evidence class |
|---|---|---|
| Supplier merged/archived between plan-render and commit | That group's `create_draft()` call fails cleanly; other groups unaffected | Code-inspection/InnoDB-reasoned + sequentially-simulated test (see §48 Amendment B for the corrected Seam A mechanism) |
| Position/stock/incoming changed between plan-render and commit | Live rebuild reflects it; item silently skipped if no longer qualifying | Sequentially simulated |
| Two M25 commits select the same item concurrently | **Serialized** via item-level advisory locks; the losing request either waits (bounded timeout) or skips that item (`concurrent_commit_in_progress`) | Sequentially simulated by default; attempt a genuine dual-connection empirical test at WP-M25-6 if the harness supports it — label honestly either way |
| An immediate retry re-selects an item a prior commit already created a line for | **Prevented** via the conflicting-open-line check | Empirically tested (deterministic, no timing dependency) |
| A deliberate, later, unrelated re-order of an item whose earlier draft has since left the conflict-status set (§48 Amendment A) | **Allowed** — correct behavior, not a race | N/A, not a concurrency claim |
| Residual: a non-M25 mutation touches an item's Position *during* this commit's own multi-group processing time | **Not prevented** — bounded by the commit's own short wall-clock duration | Only the *boundedness* of the window is asserted, via WP-M25-7's measurements |
| Deadlock across supplier groups within one request | Structurally impossible (sequential transactions, single-threaded PHP request) | Stated as fact about request execution |
| Deadlock across item locks between two concurrent M25 requests with overlapping-but-differently-ordered item sets | **Prevented** by always acquiring locks in ascending numeric `item_post_id` order | Code-inspection-reasoned + a test confirming ascending order is actually applied |

---

## 16. Duplicate-Replenishment Prevention

### Mechanism

**New class `WC_Inventory_Overview_Replenishment_Item_Lock`**:

```php
public static function acquire( array $item_post_ids, int $timeout_seconds = 5 ): array
// Attempts MySQL/MariaDB GET_LOCK('wc_io_replen_item_<id>', $timeout_seconds)
// for each id, in ASCENDING numeric order. Returns the subset of
// $item_post_ids that were actually locked -- an id whose lock could not
// be acquired within the timeout is simply absent from the returned
// array, never a thrown exception or a hard failure for the whole batch.

public static function release( array $item_post_ids ): void
// RELEASE_LOCK('wc_io_replen_item_<id>') for each given id. Idempotent.
// MySQL also auto-releases any GET_LOCK held by a connection when that
// connection closes, providing a safety net against orphaned locks.
```

Lock name: `'wc_io_replen_item_' . $item_post_id` — deterministic, reuses the exact `item_post_id` convention established throughout M24 (`variation_id` if `> 0` else `product_id`).

**Why `GET_LOCK` rather than extending `FOR UPDATE`**: the three existing `FOR UPDATE` sites each lock one already-existing row inside one owning transaction. There is no `wc_io`-owned row representing "a catalog item" to lock. `GET_LOCK`/`RELEASE_LOCK` are MariaDB's built-in, connection-scoped, schema-free advisory-lock primitive, purpose-built for serializing an arbitrary business key across a logical operation. This is the plugin's first use of advisory locks — flagged explicitly as a new pattern.

**New additive method** on `Purchase_Order_Lines` (same bulk-query family as `distinct_supplier_history_for_items_bulk()`, same dual-branch shape, same index usage):

```php
public static function list_open_or_draft_item_ids_bulk( array $product_ids, array $variation_ids ): array
// Returns item_post_ids that currently have at least one PO line whose
// PO status is in the conflict-status set frozen by §48 Amendment A:
// draft, placed, partially_received -- deliberately INCLUDING 'draft',
// unlike the existing Position-facing list_open_lines_for_*() methods
// (which intentionally exclude drafts). Same dual-branch SQL shape
// (variation_id IN (...), and variation_id = 0 AND product_id IN (...))
// as the existing bulk supplier-history method; one bulk call, never
// per-item.
```

### Sequence (per §11, steps 6/9)

1. Acquire item-level locks for all selected item_post_ids (ascending order, canonical/deduplicated per §48 Amendment C) — items that can't be locked in time are skipped.
2. Rebuild the plan (`build_plan()`) scoped to the lock-acquired items only.
3. Cross-reference against resolved/unresolved (§5).
4. On the resolved survivors, run the bulk conflicting-line check — any item already carrying a line in the frozen conflict-status set is skipped.
5. Group and create as before.
6. Release all acquired locks (`finally`, unconditional).

### What this does and does not guarantee

- **Prevented**: two M25 commits racing to create a line for the same item at approximately the same time.
- **Prevented**: an operator's own immediate retry re-selecting an item whose draft PO line a prior commit already created.
- **Not prevented, and not claimed to be prevented**: a deliberate, later, unrelated decision to reorder an item whose earlier draft PO has since transitioned out of the conflict-status set (§48 Amendment A) — the fresh needs-reorder classification remains authoritative for that decision.
- **Not prevented**: the item advisory lock only serializes against *other M25 commits* — it does not lock out non-M25 mutations.

M27's remaining scope, after this mechanism, is limited to advisory *detection/warning* for the genuinely-unpreventable case above — not the whole duplicate-replenishment problem, which M25 substantially closes.

---

## 17. Lock Ordering

Ascending `supplier_id` for `create_draft()` calls (§12) and ascending `item_post_id` for advisory-lock acquisition (§16) — two independent, both-ascending, both-deterministic orderings. Item locks are held for the whole commit; supplier-row `FOR UPDATE` locks are held only within each group's own short-lived transaction.

---

## 18. Cost Contract

`unit_cost` always `0.0`, no historical-cost lookup exists or is introduced.

---

## 19. Expected-Delivery Contract

No invented expected date.

---

## 20. Audit/Provenance Contract

`header['note'] = 'Created from Replenishment Planning'`, reusing `create_draft()`'s existing optional field, zero new event type, zero schema change.

---

## 21. Success PRG

The result-transient key is `wc_io_replen_result_{user_id}_{result_id}`, where `result_id` is a short opaque random token (`bin2hex(random_bytes(6))`, 12 hex chars) generated fresh per commit. The redirect query arg is `wc_io_commit_result=<result_id>`. `render_planning_tab()` reads `wc_io_commit_result` from `$_GET`, validates its shape (fixed-length hex string, reject anything else silently), and constructs the transient key by prepending the **currently logged-in user's own `get_current_user_id()`** — never a user id sourced from the URL — so a user cannot view another user's result even by guessing a `result_id`. Read-once-and-deleted (120s TTL as a backstop). Payload shape: `{created: [...], failed: [...], skipped: [...]}`, with `created[]` entries carrying per-line detail (product/variation names) to support the §14 "already included in a recently created purchase order" messaging.

---

## 22. Stale Preferred Supplier — Commit-Eligible

A line with `preferred_supplier_stale = true` is commit-eligible on exactly the same terms as any other resolved line.

---

## 23. M24 Read-Only Contract Preserved

`Replenishment_Item_Lock` and `list_open_or_draft_item_ids_bulk()` are new/additive-only, never modifying `Replenishment_Planning_Service` or `Supplier_Preference_Resolver`.

---

## 24. Operation-Size Limits

`MAX_COMMIT_LINES = 100`, enforced at two layers (controller request-shape validation and `Replenishment_Commit_Service::commit()` itself).

WP-M25-7 must measure the actual 100-lines/100-groups worst case (wall-clock duration, total query count, total lock-acquisition overhead) and record it in `docs/checklists/m25-release-readiness.md`. **Decision rule**: if the measured wall-clock duration for that worst case exceeds a safe synchronous-HTTP-request budget (recommended threshold: 10 seconds), or if lock-acquisition overhead proves disproportionate, introduce **`MAX_COMMIT_GROUPS`** as a second, independent cap (recommended starting value: 20), enforced at the same two layers as `MAX_COMMIT_LINES`. If the measured worst case comfortably clears the threshold, no second cap is introduced and this section's decision is recorded as "measured, no additional cap needed" with the actual numbers. See §48 Amendment F for the exact fixture-integrity requirement for this measurement.

---

## 25. Query/Performance Contract

- **Read**: one `build_plan()` call, reuses M24's proven flat baseline. Plus exactly **one** additional bulk query per commit for the conflicting-open-line check — not per-item.
- **New overhead**: lock acquisition/release adds up to `2 × (number of selected items)` additional single-round-trip statements, linear in selected item count, never catalog size. Wall-clock contribution must be measured (not just counted).
- **Write**: `G × (create_draft()'s own per-call cost) + L × (one line insert)`.
- **Explicit invariant**: zero per-line supplier/history/default lookups during the write phase.

---

## 26. Schema Decision

No schema change, `DB_VERSION` stays 11. `GET_LOCK`/`RELEASE_LOCK` are session-scoped MySQL primitives requiring no table; the conflicting-line check is a new query against the *existing* `wc_io_purchase_order_lines`/`wc_io_purchase_orders` tables.

---

## 27. Public API Decision

No new public hooks/filters.

---

## 28. Exact WP1 Implementation Breakdown

**Corrected sequencing**:

```
Agent 1 (Implementation): WP-M25-0 → WP-M25-1 → ... → WP-M25-8
  Executes continuously, no approval pauses between packages.
  STOPS after WP-M25-8's full regression pass is green.

Agent 2 (Independent Audit, FRESH — no shared context with Agent 1):
  WP2 — re-derives every claim in this plan against actual repository
  state (diffs, greps, re-run tests), per §29's contract.
  STOPS after producing a classified findings list.

Agent 3 (Remediation — distinct from Agent 2):
  WP3 — fixes only Agent 2's findings, no scope expansion.
  STOPS after all findings are resolved or explicitly deferred with
  justification.

WP4 — Freeze: docs/checklists/m25-release-readiness.md written,
  recording Agent 1's implementation, Agent 2's findings, and Agent 3's
  remediation, branch frozen.

[Separate, later activity — not part of continuous execution]
WP5 — Combined M24+M25 train readiness (§32)
WP6 — Combined release (§33)
```

### WP-M25-0 — Preflight (satisfied)

1. M24 origin/PR/CI precondition satisfied: PR #32, all CI checks green.
2. `feature/m24-replenishment-planning` local/origin tip exactly `3c21a69`, working tree clean.
3. `feature/m25-bulk-draft-po-creation` branched from `3c21a69`.
4. Confirm full pre-existing suite green.
5. Materialize this plan to `docs/milestones/m25-implementation-plan.md`, commit alone.
6. **Stop** if any inherited test is red, or if the M24 tip has drifted from `3c21a69`.

### WP-M25-1 — Characterization tests

Freeze `PO_Service::create_draft()`'s exact fallback behavior (no `currency` → supplier default; no `expected_date` → null; no `unit_cost` → `0.0`); freeze `build_plan()`'s exact scoped-path output shape for a fixture that doubles as M25's own revalidation test bed; freeze `PO_Request_Token`'s context-isolation. Zero production files touched.

### WP-M25-2 — `WC_Inventory_Overview_Replenishment_Commit_Service`

**Frozen method signature**:
```php
public static function commit( array $items ): array|WP_Error
// $items: array<int, array{product_id:int, variation_id:int, qty:float}>
// -- already filtered to "selected" rows by the caller.
//
// Returns WP_Error ONLY for service-boundary/input-shape violations:
//   - wc_io_replen_commit_too_many   (count($items) > MAX_COMMIT_LINES)
//   - wc_io_replen_commit_malformed  (missing keys, non-numeric ids,
//                                      non-numeric/out-of-range qty)
//
// Returns array{created, failed, skipped} for every other outcome,
// including zero surviving items after revalidation/locking/conflict-
// checking, and including individual per-group create_draft() failures
// (which land in failed[], never escalate to a top-level WP_Error).
```

**Internal steps**: enforce `MAX_COMMIT_LINES` → `WP_Error` if exceeded; sanity-validate shape/qty → `WP_Error` if malformed; normalize identities per §48 Amendment C; `Replenishment_Item_Lock::acquire()`; `try`: `build_plan()` once, cross-reference, conflicting-line check, group by fresh supplier_id ascending, per-group `create_draft()` with quantities mapped by canonical identity key (§48 Amendment D); `finally`: release all acquired locks; return `{created, failed, skipped}`.

**Tests**: lock acquisition/release pairing including forced mid-method exception; lock-timeout → correct skip reason (item-scoped, §48 Amendment E); conflicting-open-line detection across the exact frozen status set (§48 Amendment A); ascending lock-acquisition order; `WP_Error` vs. array-result boundary; duplicate submitted identities (§48 Amendment C) do not double-lock or double-create; reordered submitted rows vs. reordered plan output still map quantities correctly (§48 Amendment D).

### WP-M25-3 — Test-only failure injection (two-seam model, per §48 Amendment B)

**Seam A — per-group business/DB failure, proves BR-M25-8**: a `WC_IO_PHPUNIT_RUNNING`-gated, production-inaccessible checkpoint in the commit orchestrator, capable of targeting a specific supplier/group, firing *after* the fresh plan has been rebuilt and groups established, *immediately before* the targeted group's `create_draft()` call. The seam itself never fabricates a `WP_Error` — it only mutates fixture state (e.g., archives the targeted group's supplier), and `create_draft()` must independently discover the real invalid state through its own normal production validation and return its own ordinary `WP_Error`. **Assert**: A → `create_draft()` succeeds; B → checkpoint fires, mutates supplier state; B → the real `create_draft()` call occurs and returns its own real `WP_Error`; C → `create_draft()` still executes and succeeds. This is the genuine, empirical proof of BR-M25-8.

**Seam B — catastrophic orchestrator interruption**: M17-pattern private armed-step flag + `WC_IO_PHPUNIT_RUNNING`-gated setter + named-checkpoint injector, firing a `RuntimeException` immediately after group A's `create_draft()` has already returned success but before group B's call begins. **Assert**: group A's PO persists unaffected; B/C never attempted; all item advisory locks nevertheless released via `finally`. A narrower, distinct claim from Seam A — never conflated with it.

### WP-M25-4 — `WC_Inventory_Overview_Replenishment_Commit_Admin`

Registers `admin_post_wc_io_replenishment_commit`. Implements §11's validation order exactly, including §7's two-phase parsing contract (structural-malformed → `wp_die(400)`; selected-row-invalid → PRG notice) and §21's `{user_id}_{result_id}` transient key.

### WP-M25-5 — Additive UI on `render_planning_tab()`

Every line checkbox defaults unchecked; client-side running-count-of-100 indicator/disable behavior; result-summary rendering reads `wc_io_commit_result=<result_id>` and constructs the transient key using the current user's own id. Zero changes to `Replenishment_Planning_Service` itself.

### WP-M25-6 — Security/capability/concurrency test consolidation

Full crafted-POST matrix including duplicate submitted identities (§48 Amendment C) and reordered-row quantity mapping (§48 Amendment D); capability/nonce/token isolation; concurrency race tests targeting the lock/conflict-check mechanism; an explicit attempt at a genuine dual-connection empirical test for the item-lock race, honestly labeled either way per its actual outcome.

### WP-M25-7 — Performance/architecture guards

Query-count matrix at 1/10/50/100 lines across supplier-distribution shapes, including the mandatory 100-lines/100-groups worst-case measurement (§48 Amendment F: fixtures must be genuinely commit-eligible, not accidentally pre-seeded into conflict); `MAX_COMMIT_GROUPS` decision rule applied from measured evidence; `git diff`-against-M24-tip architecture guards; grep-based invariant guards.

### WP-M25-8 — Full regression + docs/version

Full M1–M25 regression green. Version bump `1.41.0` → `1.42.0`. `DB_VERSION` unchanged at `11`. `CHANGELOG.md`, `CLAUDE.md`, CI filter update. **This plan file is not re-materialized or edited at this step** — any deviation goes into `docs/checklists/m25-release-readiness.md` only.

---

## 29. WP2 Independent-Audit Contract

A fresh agent, no shared context with the implementer, re-derives every claim in this plan against the actual repository state. Additional audit items: verify `Replenishment_Item_Lock::acquire()`/`release()` are genuinely paired in every code path including exception paths (read the actual `try`/`finally` structure); verify the conflicting-line check's SQL actually includes exactly the frozen conflict-status set (§48 Amendment A) — a one-status-away bug would silently defeat §16's entire purpose; verify `commit()`'s actual return type matches the frozen `array|WP_Error` contract at every return statement.

---

## 30. WP3 Remediation Contract

Fix only WP2's findings, no scope expansion. Distinct agent identity from WP2's auditor.

---

## 31. WP4 Freeze Contract

`docs/checklists/m25-release-readiness.md` records: the §16 locking mechanism's design and test evidence; the §24 `MAX_COMMIT_GROUPS` decision with actual measured numbers; the §11 residual-TOCTOU-window documentation repeated verbatim; confirmation of the M24-push/PR/CI-green precondition; and evidence for every Amendment A–F in §48.

---

## 32. WP5 Combined-Train Release Preparation

Once M25 freezes alongside M24's already-CI-verified state: write `docs/checklists/feature-train-m24-m25-release-readiness.md`, mirroring the M22+M23 precedent.

---

## 33. WP6 Combined Release Execution

Merge to `main`, tag `v1.42.0` (M25's own dev version, no separate release-only bump). **Not part of this milestone's scope.**

---

## 34. BR-M25 Matrix

- **BR-M25-1**: Only items explicitly selected (checked) by the operator, with a qty `> 0`, are submitted for creation.
- **BR-M25-2**: Operator-entered quantity is validated and persisted exactly as entered.
- **BR-M25-3**: A quantity of zero or blank **on a selected line is a validation error**, not an implicit skip — the line must either be unchecked or given a positive quantity.
- **BR-M25-4**: `Replenishment_Defaults::default_qty` is never rewritten by any M25 code path.
- **BR-M25-5**: Supplier assignment for every created PO line is derived exclusively from a fresh, server-side `build_plan()` rebuild — never from any POST field.
- **BR-M25-6**: PO currency is derived exclusively from `create_draft()`'s own existing supplier-current-currency fallback.
- **BR-M25-7**: An item that no longer appears in a resolved group in the fresh rebuild is silently skipped, never causes the whole commit to fail.
- **BR-M25-8**: One supplier group's creation failure never prevents or reverses other groups' successful creation in the same commit.
- **BR-M25-9**: Exactly one Draft PO is created per distinct resolved supplier among the surviving selected lines.
- **BR-M25-10**: A stale-preferred-supplier line is commit-eligible on exactly the same terms as any other resolved line.
- **BR-M25-11**: Unresolved items are never creatable via M25.
- **BR-M25-12**: A variable-parent product can never appear as a submittable line.
- **BR-M25-13**: A commit submitting more than `MAX_COMMIT_LINES` (100) items is rejected outright before any DB write.
- **BR-M25-14**: A second submission of the same one-shot commit token is rejected, never creates a second set of POs.
- **BR-M25-15**: No unit cost is invented or suggested.
- **BR-M25-16**: No expected delivery date is invented.
- **BR-M25-17**: Every PO created via M25 carries the fixed provenance note "Created from Replenishment Planning."
- **BR-M25-18**: Viewing the commit form/button requires `Purchasing_Caps::EDIT_PO`.
- **BR-M25-19**: The commit POST independently re-checks `EDIT_PO` server-side.
- **BR-M25-20**: M25 introduces no automatic/scheduled/background purchasing of any kind.
- **BR-M25-21**: The post-commit summary distinguishes created, failed, and skipped items explicitly.
- **BR-M25-22**: A refresh of the post-commit result page never re-triggers PO creation.
- **BR-M25-23**: Two M25 commits that select an overlapping set of items are serialized via item-level advisory locks, acquired in ascending order; a commit that cannot acquire a given item's lock within the timeout skips that item (`concurrent_commit_in_progress`) and never blocks indefinitely.
- **BR-M25-24**: An item that already has a PO line in the frozen conflict-status set (`draft`/`placed`/`partially_received`, §48 Amendment A) for the exact same product/variation is skipped (`already_has_open_po_line`), checked fresh, under lock, immediately before grouping, on every commit including an immediate retry.
- **BR-M25-25**: Item-level duplicate replenishment is prevented for concurrent double-commit (BR-M25-23) and immediate/near-immediate retry (BR-M25-24). It is not, and is not claimed to be, prevented for a deliberate, later, unrelated re-order of an item whose prior draft has since left the conflict-status set (received/cancelled/closed_short) — and only if the fresh needs-reorder classification independently confirms the item currently needs reorder.
- **BR-M25-26**: Every checkbox on the commit form defaults unchecked.
- **BR-M25-27**: A selected line with an invalid quantity fails the entire commit request with a specific, correctable notice.
- **BR-M25-28**: Every acquired item-level lock is released by the end of the commit request, regardless of success, per-group failure, or an unexpected exception.
- **BR-M25-29 (§48 Amendment C)**: Duplicate submitted item identities (same canonical `item_post_id` appearing more than once in one POST, via array-index tricks or otherwise) never result in duplicate `GET_LOCK` calls, duplicate PO lines, or a bypass of `MAX_COMMIT_LINES` — canonical identities are deduplicated before any lock/plan/creation step.
- **BR-M25-30 (§48 Amendment D)**: A submitted quantity is always associated with its exact submitted product/variation identity via a canonical identity key — never by array index, display order, group order, or result order — even when the plan/group output order differs from submission order.

---

## 35. INV-M25 Matrix

- **INV-M25-1**: `PO_Service::create_draft()` and friends are byte-identical to their M24-frozen-tip versions.
- **INV-M25-2**: `Replenishment_Planning_Service` and `Supplier_Preference_Resolver` are byte-identical to their M24-frozen-tip versions.
- **INV-M25-3**: No Position arithmetic is duplicated anywhere in M25's new code.
- **INV-M25-4**: No needs-reorder arithmetic is duplicated anywhere in M25's new code.
- **INV-M25-5**: No supplier-preference precedence logic is duplicated anywhere in M25's new code.
- **INV-M25-6**: Supplier is 100% server-derived.
- **INV-M25-7**: Currency is 100% server-derived.
- **INV-M25-8**: No client-submitted eligibility/needs-reorder/supplier-resolution value is ever trusted.
- **INV-M25-9**: No unresolved item can ever be persisted as a PO line.
- **INV-M25-10**: No variable-parent product can ever be persisted as a PO line.
- **INV-M25-11**: A consumed idempotency token can never be reused.
- **INV-M25-12**: Each supplier group's PO creation is fully atomic; never spans more than one group.
- **INV-M25-13**: Commit-time revalidation is bounded — exactly one `build_plan()` call per commit request.
- **INV-M25-14**: No N+1 read pattern exists anywhere in the commit path.
- **INV-M25-15**: No schema change — `DB_VERSION` stays 11.
- **INV-M25-16**: No new public hook/filter is registered.
- **INV-M25-17**: `EDIT_PO` gates both UI visibility and server authorization identically.
- **INV-M25-18**: Every PO created by M25 has exactly one `PO_Events::TYPE_CREATED` event, no M25-specific event type.
- **INV-M25-19**: `Replenishment_Defaults`'s stored values are never written by any M25 code path.
- **INV-M25-20**: Lock ordering (ascending `supplier_id`) is consistently applied.
- **INV-M25-21**: `Replenishment_Item_Lock::release()` is called for every id `acquire()` returned, on every code path.
- **INV-M25-22**: No advisory lock is ever held beyond the synchronous duration of a single commit HTTP request.
- **INV-M25-23**: The conflicting-open-line check is only ever called from inside `commit()`, after lock acquisition and before grouping.
- **INV-M25-24 (§48 Amendment C)**: Canonical `item_post_id` derivation (`variation_id > 0 ? variation_id : product_id`), validation, deduplication, and ascending sort are applied before any lock acquisition — never after.
- **INV-M25-25 (§48 Amendment D)**: The final PO line construction combines server-derived plan truth (eligibility/supplier/grouping/currency/product identity) with POST-derived truth (selected identity/quantity) only via exact canonical-identity-key matching, never positional/order-based association.
- **INV-M25-26 (§48 Amendment E)**: An item whose lock acquisition failed is excluded from `build_plan()` input, conflict checking, grouping, and PO creation — never downgraded to an unlocked read or retried within the same request.

---

## 36. Error-Code / Skip-Reason Matrix

**`WP_Error` codes**: `wc_io_replen_commit_too_many`, `wc_io_replen_commit_malformed` (new); existing token/capability/nonce codes reused verbatim; a new PRG-not-wp_die path for selected-row invalid quantity.

**Skip reasons**: `not_found`, `no_supplier`, `multiple_suppliers`, `no_longer_needs_reorder`, `concurrent_commit_in_progress` (new), `already_has_open_po_line` (new, conflict-status set frozen by §48 Amendment A).

**`create_draft()`-originated `WP_Error` codes** (per-group failures, land in `failed[]`): unchanged, existing codes (`wc_io_po_supplier_inactive`, `wc_io_po_insert_failed`, etc.).

---

## 37. Test Matrix

Full matrix: characterization; planning→commit parity; quantity validation; simple product creation; variation creation; variable-parent rejection; supplier grouping; multiple supplier groups; unresolved-line rejection; stale preferred-supplier fallback; stale plan; supplier merged/archive race (via §48 Amendment B's corrected Seam A mechanism); incoming-stock change; stock/Position change; crafted POST (12 items, including duplicate identities and unchecked-row sibling-field discard); capability; nonce; token replay; double submission; transaction rollback; partial-failure semantics (Seam A); DB failure injection; query/read scaling; existing PO workflow regression; M24 zero-mutation regression; architecture guards; item-level lock serialization; immediate-retry duplicate prevention; reordered-row quantity/identity mapping (§48 Amendment D).

---

## 38. Concurrency Evidence Matrix

Every concurrency claim is classified honestly as: empirically dual-connection tested (only if the harness genuinely supports it, attempted at WP-M25-6), sequentially simulated, or code-inspection/InnoDB-reasoned. No claim is upgraded beyond what was actually exercised.

---

## 39. Performance Test Matrix

Rows at 1/1, 10/1, 50/1, 50/10, 100/1, 100/20, and 100/100 (lines/suppliers), each recording read queries, write queries, lock round-trips, and total wall-clock time. The 100/100 row must use genuinely commit-eligible fixtures per §48 Amendment F and drives the `MAX_COMMIT_GROUPS` decision (§24).

---

## 40. Documentation Plan

`docs/milestones/m25-implementation-plan.md` (this file, immutable from its WP-M25-0 commit); `docs/checklists/m25-release-readiness.md` (WP4, all measured evidence); `docs/checklists/feature-train-m24-m25-release-readiness.md` (WP5, later); `CHANGELOG.md` `[1.42.0] - Unreleased`; `CLAUDE.md` M25 status row; `tests/docker/run-phpunit.sh:231` new prefix.

---

## 41. Version/Release Strategy

M25 develops at `1.42.0`. `DB_VERSION` stays 11. Combined train releases as `v1.42.0` once both M24 and M25 are frozen and a combined readiness review authorizes it. **Not part of this milestone.**

---

## 42. Rollback Strategy

Already-created Draft POs are never deleted as part of a rollback. A rolled-back deploy leaves no orphaned advisory locks — `GET_LOCK` is connection-scoped and auto-releases when a PHP request's DB connection closes.

---

## 43. Stop Conditions

- M24 frozen SHA differs from `3c21a69e6402d631575ded4653435dbaa6dbe435`.
- Any inherited M1–M24 test goes red.
- `PO_Service`, `Purchase_Orders`, `Purchase_Order_Lines`, `PO_Events`, `PO_Product_Validator`, `DB_Transaction`, `Replenishment_Planning_Service`, or `Supplier_Preference_Resolver` differ from their M24-frozen-tip content.
- Any acquired item-level lock is not released at the end of a commit request under any tested code path.
- The conflicting-open-line check's SQL fails to include exactly the frozen conflict-status set (§48 Amendment A).
- `commit()`'s return type deviates from the frozen `array|WP_Error` contract.
- The 100/100 worst case is measured and exceeds the safety threshold without `MAX_COMMIT_GROUPS` being introduced.
- Duplicate submitted identities bypass `MAX_COMMIT_LINES` or create duplicate lines (§48 Amendment C).
- A submitted quantity is ever mapped to the wrong identity via positional/order assumptions (§48 Amendment D).
- Any BR-M25/INV-M25 item is unsatisfied at the WP4 freeze gate.

---

## 44–47. Adversarial Review, Corrections, Sequence, Verdict

See the full Revision 2 planning record for the complete 13-point user review and corresponding corrections. Summary: the review's central requirement — that concurrent/duplicate replenishment be genuinely serialized, not merely deferred — is satisfied by §16's item-level advisory-lock plus conflicting-open-line mechanism. All 13 review points were resolved with concrete mechanisms or explicit, evidence-gated decision procedures, never a guessed number or an overstated guarantee. §48 below records a second, final pre-implementation adversarial pass (Amendments A–F) that further precision-corrected six aspects of this mechanism before implementation began.

---

## 48. Pre-Implementation Review Amendments (authoritative, supersede conflicting wording above)

A final adversarial review of this plan, conducted immediately before implementation began, found two material specification defects and four precision issues. These amendments are authoritative and supersede any conflicting wording elsewhere in this document.

### Amendment A — Open/Draft Conflict Status Contract

Several explanatory passages above loosely describe a prior draft becoming reorderable after transitioning to "placed/received/cancelled" — this is imprecise, since `placed` is explicitly part of the blocking set. **Frozen contract**: an exact product/variation item **must be excluded** from M25 creation whenever at least one matching PO line belongs to a PO whose status is `draft`, `placed`, or `partially_received`. A fresh M25 creation becomes possible only when no matching line remains in any of those three statuses — for example after the relevant prior PO reaches `received`, `cancelled`, or `closed_short` — **and only if** the normal fresh M24 needs-reorder classification independently confirms the item currently needs reorder. Eligibility is never inferred merely from a lifecycle transition; the fresh planning classification remains authoritative. This is the exact, sole conflict-status set for `list_open_or_draft_item_ids_bulk()`'s SQL filter.

### Amendment B — WP-M25-3 Seam A Correction

Archiving a supplier *before* `commit()` begins would be caught by the fresh `build_plan()` rebuild itself, preventing that group from ever reaching `create_draft()` — which would not prove "group B reaches `create_draft()` and receives a real `WP_Error`, then processing continues to group C." **Corrected mechanism**: a `WC_IO_PHPUNIT_RUNNING`-gated, production-inaccessible checkpoint inside the commit orchestrator, capable of targeting a specific supplier/group, firing *after* the fresh plan has been rebuilt and groups established, *immediately before* the targeted group's `create_draft()` call. The seam mutates fixture state at that exact moment (e.g., archives the targeted group's supplier) but never fabricates the `WP_Error` itself — `create_draft()` must independently discover the now-invalid state through its own normal internal validation and return its own genuine `WP_Error`. This is Seam A, kept strictly distinct from Seam B (catastrophic interruption via injected `RuntimeException`).

### Amendment C — Normalize Lock Identities

Before advisory-lock acquisition: (1) derive the canonical `item_post_id` (`variation_id > 0 ? variation_id : product_id`); (2) validate identity; (3) normalize to positive integers; (4) deduplicate identical `item_post_id`s; (5) sort numerically ascending. Each canonical item lock is acquired at most once; exactly the successfully-acquired canonical set is released. No duplicated POST row, duplicated identity representation, or ordering artifact may result in duplicate `GET_LOCK` calls, duplicate PO lines, a bypass of `MAX_COMMIT_LINES`, or processing of an item whose canonical lock was not acquired. The `MAX_COMMIT_LINES` cap applies to selected logical lines *before* this normalization; duplicate submitted identities collapse to one canonical entry and must not inflate or evade the cap. Crafted duplicate identities are explicitly tested (§37).

### Amendment D — Quantity/Identity Mapping

Submitted quantities must be associated with the exact submitted product/variation identity via a canonical identity key — never by array index, display order, group order, or result order. After `build_plan()` reconstructs authoritative supplier/grouping data, quantity lookup for each surviving item uses that same canonical identity key. The server-derived plan determines eligibility, supplier, grouping, currency, and product/variation truth; the POST determines only selected identity and requested quantity; the final PO line combines those two sources exclusively via exact identity matching. Tests include deliberately reordered submitted rows against deliberately reordered plan/group output.

### Amendment E — Lock Failure Is Item-Scoped

If an item's lock cannot be acquired within the bounded timeout, `skipped.reason = concurrent_commit_in_progress` and that exact item is excluded from `build_plan()` input, conflict checking, grouping, and PO creation. Other successfully-locked items continue normally. A lock-acquisition failure is never downgraded into an unlocked read or retry within the same request.

### Amendment F — Performance Fixture Integrity

The 100-lines/100-suppliers performance measurement must exercise 100 genuinely commit-eligible lines. Fixtures must not accidentally pre-seed `draft`/`placed`/`partially_received` PO lines that would cause the conflict detector (§16) to skip the workload before `create_draft()` is ever measured. The measurement records, separately: selected lines, acquired locks, surviving lines after conflict detection, supplier groups actually attempted, POs actually created, total SQL/query count, `GET_LOCK` calls, `RELEASE_LOCK` calls, lock-acquisition wall time, and total commit wall time. The `MAX_COMMIT_GROUPS` decision (§24) is based on an actual 100-group commit workload that reaches `create_draft()`, not a workload mostly absorbed by revalidation/conflict-skipping.

---

# M25 PLANNING COMPLETE (REVISION 2 + PRE-IMPLEMENTATION AMENDMENTS A–F) — ARCHITECTURE FROZEN — READY FOR IMPLEMENTATION
