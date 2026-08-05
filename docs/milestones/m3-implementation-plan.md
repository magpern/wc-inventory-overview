# Milestone M3 Implementation Plan — Inventory Position

**Status: Approved — target release v1.20.0. Implementation not started.**

**Prerequisite:** v1.19.1 (M2 test-infrastructure hotfix) on schema v7.

**Architecture context:** [`CLAUDE.md`](../../CLAUDE.md) Part I §1–§5, specifically D11/D12/D13/D19 and INV-1/INV-3/INV-5/INV-7/INV-8. Roadmap context: Part II (Delivery Roadmap v1.0), M3 row of the Implementation Status table.

> **Provenance note (added during materialization, not part of the approved source):**
> This document was materialized from an operational implementation brief supplied to the implementing agent, structured as 22 numbered execution directives (scope, prohibitions, work packages, test/quality-gate/documentation requirements, git discipline) rather than as a traditional plan document. The sections **Context**, **Domain model**, **Risk review**, **Plan review notes**, and **READY FOR IMPLEMENTATION verdict** below have **no corresponding content in that source** — they were drafted by the implementing agent, at the requester's explicit direction, to complete the plan structure used by M1/M2. They are clearly marked below and require separate human review/approval; they are not themselves part of the approved plan content. Every other section is a faithful reorganization of the supplied source content under the M1/M2 heading structure, without shortening, redesign, or added implementation decisions.

---

## Context

> *Drafted by the implementing agent — not part of the supplied source. Pending review.*

M2 (v1.19.0, schema v7) delivered the Purchase Order aggregate: a four-state lifecycle, append-only PO events, expected-receipt dates with confidence, and delayed detection — but Purchase Orders remain purely a purchasing-commitment record. Nothing in M2 surfaces that commitment back onto the Inventory Overview screen, and no component today answers Architecture v1.0's central question for an item: *what is our stock situation?*

D11 defines **Inventory Position** as a first-class domain concept with the current model `{On Hand, Incoming, Position = On Hand + Incoming}`, explicitly designed to gain `Reserved`, `Available`, `Coverage`, and `Forecast` fields in later milestones without changing the surrounding architecture. D12 requires exactly one authoritative calculator for position/aggregation values, used identically for single-item and bulk reads, with no N+1 query behavior. M3 is the milestone that introduces that calculator and its first consumer (Inventory Overview), while explicitly deferring everything receiving-related (Goods Receipt, `qty_received`, stock/cost mutation) to M4/M5 per the Delivery Roadmap's sequencing.

M3 is scoped as **read-only**: it aggregates already-stored PO-line data (from M2) with caller-supplied On Hand figures; it writes nothing, mutates nothing, and adds no schema.

---

## Milestone M3 Implementation Plan — Inventory Position

*(Section header preserved per the required document structure; the milestone's full plan follows below as Summary through Definition of Done.)*

---

## Summary

Milestone M3 introduces the Inventory Position domain concept end-to-end: a stateless Resolver that computes `Position = On Hand + Incoming`, a Service that is the sole authoritative calculator (single and bulk, D12), two new bulk repository read methods over open PO lines, and Incoming/Position columns with per-supply drill-down on the Inventory Overview screen — including presentation-only rollup for variable-product parents and reuse of the existing M2 delayed-line predicate. **M3 introduces no schema change, no migration, no receiving, no stock or cost mutation, and no new REST/AJAX/admin-post surface.** `DB_VERSION` remains `7`.

M3 concepts in scope:

1. Inventory Position Resolver
2. Inventory Position Service
3. Bulk open-PO-line repository reads
4. Incoming and Position columns in Inventory Overview
5. Per-supply drill-down
6. Variable-parent presentation rollup
7. Delayed-incoming indication, reusing the existing M2 delay predicate
8. Unit, integration, architecture-guard, capability, and query-scaling tests
9. M3 documentation and release preparation

## Milestone boundaries

**Hard prohibitions — must not be implemented in M3:**

- Goods Receipts; Quick Receive; Receive Against Purchase Order; any receiving workflow
- Stock mutation; cost mutation; `qty_received`; Inventory Movements changes
- Schema changes; migrations; `DB_VERSION` changes
- Dashboard KPIs; storefront expected dates
- Reserved; Available; Coverage; Forecast; reservations (D11's later Position fields)
- New REST endpoints; new AJAX handlers; new admin-post handlers; new capabilities
- Caching
- M4-or-later placeholders of any kind

**Hard prohibitions — must not be modified:**

- Supplier behavior
- PO lifecycle or mutation behavior
- Purchase Order admin screens
- `WC_Inventory_Overview_PO_Delay`, `WC_Inventory_Overview_PO_Quantities`, `WC_Inventory_Overview_PO_Expected` behavior
- M0 golden expected values; existing characterization fixtures

**M3 must be entirely read-only.** This is a stronger constraint than "no schema change" — no M3 code path may write to any table, mutate stock, mutate cost, or update PO records, single-item or bulk.

## Domain model

> *Drafted by the implementing agent — not part of the supplied source. Pending review.*

M3 implements exactly one new derived concept and touches no new entities or tables. It is best understood as an aggregation layer sitting on top of already-existing M1/M2 storage:

```
                     ┌────────────────────────────┐
Caller-supplied      │  Inventory Position         │
On Hand ─────────────▶  Resolver (stateless,       │──▶ {on_hand, incoming, position,
                     │  read-only, no I/O)         │     incoming_delayed}
                     └────────────────────────────┘
                                  ▲
                                  │ per item, called by
                     ┌────────────────────────────┐
                     │  Inventory Position         │
                     │  Service (D12: sole          │──▶ {..., incoming_lines[]}
                     │  authoritative calculator)  │
                     └────────────────────────────┘
                                  ▲
                     bulk reads (2 queries: product-scoped,
                     variation-scoped), grouped by item key
                                  │
                     ┌────────────────────────────┐
                     │  PO Lines repository         │
                     │  (existing table, M2)        │
                     │  status = placed only        │
                     └────────────────────────────┘
```

- **Purchasable item addressing (INV-8):** Position is always computed per simple product or per variation, never against a variable parent. The Resolver and Service have no notion of "parent product" at all; parent-level figures are a presentation-only sum computed by the list table (see Admin UI → Variable-product behavior below), consistent with INV-7 ("presentation never destroys identity").
- **Independent incoming supplies (INV-1):** each qualifying PO line is its own incoming record; the Service sums outstanding quantities in PHP but retains every contributing line individually as `incoming_lines`, so two lines sharing a date or supplier remain two rows in the drill-down.
- **Derived aggregation (INV-3):** no product-level "incoming" or "position" value is ever stored. Every read recomputes from currently-open PO lines, so Position always reflects current qualifying supply — never a snapshot taken at PO creation time.
- **Sole authoritative calculator (D12):** the Service is the only caller of the two new bulk repository methods; the architecture-guard tests (WP4) exist specifically to enforce this so no future code path grows a second, divergent position calculation.
- **Composable, reused delay condition (INV-5, D13):** "delayed incoming" is not a new predicate — M3 reuses `WC_Inventory_Overview_PO_Delay::sql_line_delayed_predicate()` verbatim at the line level, and low-stock/Incoming/Position/delayed states are shown simultaneously, never as mutually exclusive UI states.

No new tables, columns, or value objects are introduced. The Resolver's return shape (`on_hand`, `incoming`, `position`, `incoming_delayed`) and the Service's extended shape (adding `incoming_lines`) are the complete M3 data contract; per the supplied source, no value object may be introduced without a formal plan revision.

## Database

**No schema change. No migration. `DB_VERSION` remains `7`.**

M3 reads exclusively from tables already created in M1/M2:

| Table | Role in M3 |
|-------|-----------|
| `wc_io_purchase_order_lines` | Source of open-line quantities (`qty_ordered`, `qty_cancelled`, line-level expected-date/confidence overrides); read-only |
| `wc_io_purchase_orders` | Joined for `status = 'placed'` qualification and PO number/expected-date context for drill-down; read-only |

No `qty_received` column exists (M2's schema assertion forbids it until M5) and M3 introduces no dependency on it — outstanding quantity is computed from `qty_ordered` and `qty_cancelled` only, consistent with the M2-era formula. Forbidden-column verification (confirming `qty_received` absent) is a required M3 quality gate.

## Implementation map

| Layer | Primary classes / methods |
|-------|---------------------------|
| Resolver | `WC_Inventory_Overview_Inventory_Position_Resolver` — stateless, read-only, independent of `$wpdb`, product loading, and PO repositories |
| Service | `WC_Inventory_Overview_Inventory_Position_Service` — `get_position()`, `get_positions_bulk()`; sole authoritative calculator (D12) |
| Repository (modified) | `WC_Inventory_Overview_Purchase_Order_Lines` — adds `list_open_lines_for_product_ids()`, `list_open_lines_for_variation_ids()` (two separate queries, not one OR-based query) |
| Reused, unmodified | `WC_Inventory_Overview_PO_Delay::sql_line_delayed_predicate()` (line-level delayed predicate) |
| Admin integration (modified) | `WC_Inventory_Overview_List_Table` — Incoming/Position columns, capability gating, bulk-fetch sequencing, drill-down, variable-parent rollup |
| Install (unmodified) | `Install::DB_VERSION` stays `'7'`; no `expected_schema_v7()` change |

## Admin UI

**Columns.** Add **Incoming** and **Position** columns to Inventory Overview, placed adjacent to the existing Stock column.

**Capability gating.** Visible only to `manage_woocommerce` users; `edit_products`-only users must not see the columns, and drill-down data must not be rendered for unauthorized users. No new capability is introduced — M3 uses the same sensitivity tier already applied to average cost and inventory value (not the Stock column's visibility precedent, which is broader).

**Bulk-fetch sequencing (binding constraint).** Position data must **not** be fetched immediately after the initial product query. The list table discovers variation children later while building groups. Required sequence:

1. Build the complete groups structure.
2. Include every simple product and every discovered variation.
3. Collect the complete product/variation input list.
4. Call `get_positions_bulk()` exactly once.
5. Store the returned map in memory for column rendering.

No per-row Position queries are permitted. The integration test suite must prove that variations discovered by the later per-parent query still receive correct Position data.

**Drill-down.** Reuses the existing details-toggle / expandable-details pattern already present in the list table — no AJAX, no REST, no admin-post, no new page, no new modal framework. Shows each contributing PO line independently: PO number, PO/detail link, outstanding quantity, expected date, expected confidence, delayed indication. Lines are never merged or collapsed — two separate PO lines for the same item remain two separate drill-down rows even when they share a date or supplier. All output and URLs are escaped.

**Variable-product behavior.** Inventory Position is always calculated per purchasable item (simple product or variation) — never directly against a variable parent (INV-8). The existing presentation-aggregation logic for variable-parent rows is extended so that parent Incoming is the sum of child-variation Incoming, and parent Position is the sum of child-variation Position; child variations retain their individual figures and individual drill-downs. The parent rollup is presentation-only — no parent-level incoming record is ever created.

**Composable states.** Existing low-stock logic and badge rendering are unchanged. A product may simultaneously display low/out-of-stock state, an Incoming value, a Position value, and a delayed-incoming indication — these states are never made mutually exclusive. Delay detection reuses the existing predicate; no new delay logic is introduced.

## Implementation sequence

Work packages, in required order (no skipping ahead; no stopping after an intermediate package):

- **WP1** — Inventory Position Resolver
- **WP2** — Bulk open-line repository methods
- **WP3** — Inventory Position Service
- **WP4** — Architecture guard tests (D12 enforcement)
- **WP5** — Inventory Overview columns, capability gating, bulk sequencing, and drill-down
- **WP6** — Variable-parent presentation aggregation
- **WP7** — Query-scaling / no-N+1 regression guard
- **WP8** — Documentation and release preparation

## Testing

Ordinary PHPUnit tests; no golden fixtures added or changed. Required test files:

- `tests/unit/inventory-position/test-inventory-position-resolver.php`
- `tests/unit/inventory-position/test-inventory-position-architecture.php`
- `tests/integration/inventory-position/test-inventory-position-lines-repository.php`
- `tests/integration/inventory-position/test-inventory-position-service.php`
- `tests/integration/inventory-position/test-inventory-position-list-table.php`

**Resolver:** zero On Hand / zero Incoming; positive values; decimal precision; delayed-flag propagation; exact Position calculation.

**Repository:** draft POs excluded; placed POs included; cancelled POs excluded; closed-short POs excluded; multiple independent lines preserved; simple-product keying; variation keying; delayed flag matches the existing PHP delay calculation; empty ID lists (no malformed SQL); no `qty_received` dependency.

**Service:** single/bulk consistency; correct aggregation; independent incoming lines retained; no-contributing-lines case; delayed aggregation; caller-supplied On Hand used (no product refetch).

**List table:** authorized user sees columns; `edit_products`-only user does not; simple product rendering; variation rendering; late-discovered-variation sequencing case; variable-parent presentation sum; drill-down preserves line identity; low-stock badge remains visible alongside Incoming; no write side effects.

**Query-scaling:** create at least 20 mixed simple/variation items; verify Position query count does not grow with row count (bounded/query-scaling assertion, not a single brittle total-query-count check); verify the Service performs at most one product-line query and one variation-line query per bulk call.

**Architecture:** only the Position Service calls the new repository methods; no stock or cost mutation; no `qty_received` reference; no receiving code; no M4+ concept leakage.

## Quality gates

At minimum, executed and individually classified (EXECUTED — PASS / FAIL / PASS WITH KNOWN PRE-EXISTING FAILURES / CONFIGURED — NOT EXECUTED / NOT APPLICABLE):

- PHP syntax lint
- Composer validation
- Docker Compose config
- Unit suite
- M1/M2/M3-focused blocking suite (`tests/docker/run-phpunit.sh` blocking filter updated to include the Inventory Position test prefix alongside existing M1/M2 prefixes)
- Cumulative integration suite (documented legacy failures remain visible and honestly classified — never presented as a false green gate)
- Inventory Position tests in isolation
- PHPCS
- actionlint, if workflow/config files changed
- Schema verification confirming `DB_VERSION` 7
- Forbidden-column verification confirming `qty_received` absent
- Query-scaling test
- Release ZIP build and inspection
- Git diff review against v1.19.1
- Working-tree verification

Any new test failure introduced by M3 is a release blocker.

## Documentation

Required M3 documentation deliverables:

1. `docs/milestones/m3-implementation-plan.md` (this file)
2. `CLAUDE.md` milestone status updated only **after** implementation is complete
3. `docs/checklists/validation-checklist.md` — new approved M3 subsection
4. `docs/testing.md` — new test directories and focused-suite coverage
5. `CHANGELOG.md` — v1.20.0 entry
6. `readme.txt` and all repository version references, updated consistently
7. `docs/architecture-audit.md` — Resolver, Service, repository reads, Inventory Overview integration, capability decision, no-schema statement, no-N+1 guard, architecture guard
8. Explicit documentation that: `DB_VERSION` remains 7; no migration exists; M3 is read-only; M4/M5 will later extend the Incoming formula once receiving exists

No schema-specific release-runbook section is added, since M3 has no schema change.

## Implementation guidance

**Versioning.** Target `1.20.0`; keep `DB_VERSION = '7'`; update all established version references consistently. Do not tag or release.

**Git discipline.** Small, logical commits — do not collapse the milestone into one large commit. Recommended grouping: (1) resolver, (2) bulk repository reads, (3) service, (4) architecture/service test coverage, (5) Inventory Overview columns, (6) list-table/query-scaling coverage, (7) documentation, (8) release prep. Do not merge, push, tag, or deploy.

**Scope audit before completion.** Before declaring M3 complete, inspect the full branch diff directly (not solely via tests) and confirm: no schema change; `DB_VERSION` still 7; no `qty_received`; no receipt classes or tables; no stock mutation; no cost mutation; no Dashboard modification; no storefront modification; no REST/AJAX/admin-post additions; no Supplier modification; no PO lifecycle/mutation modification; no M0 fixture changes; no caching; only approved `includes/` files modified.

**Repository scope for implementation.** Only these files may be created or modified for M3:
- New: `includes/class-wc-inventory-overview-inventory-position-resolver.php`, `includes/class-wc-inventory-overview-inventory-position-service.php`, and the five test files listed under Testing
- Modified: `includes/class-wc-inventory-overview-purchase-order-lines.php` (two new methods only), `includes/class-wc-inventory-overview-list-table.php`, `tests/docker/run-phpunit.sh`, and the documentation files listed above

**Completion state.** On completion, the implementation branch should be left with: all M3 changes committed; `main` unchanged; nothing pushed, merged, tagged, or deployed; ready for independent audit.

## Risk review

> *Drafted by the implementing agent — not part of the supplied source. Pending review.*

| Risk | Mitigation already designed into this plan |
|------|---------------------------------------------|
| N+1 queries as Inventory Overview grows | Two bulk repository methods (product-scoped, variation-scoped) called at most once per bulk operation (WP2, WP3); WP7 adds an explicit query-scaling regression guard with a 20+ item fixture |
| A second, divergent Position calculation path emerges over time | D12 architecture-guard tests (WP4) assert only the Service calls the two new repository methods; guards are explicitly not to be weakened to accommodate implementation shortcuts |
| Position data fetched too early misses variations discovered later in group-building | Binding bulk-fetch sequencing constraint (Admin UI section) plus a dedicated "late-discovered variation" integration test |
| Incoming/Position data leaks to `edit_products`-only users | Explicit capability-gating requirement using the existing average-cost/inventory-value sensitivity tier, with a dedicated authorized-vs-unauthorized test |
| Drill-down accidentally collapses two independent PO lines into one row (violating INV-1/INV-7) | Line-level (not aggregated) SQL in the repository, `incoming_lines` retained individually in the Service, explicit test asserting drill-down line identity is preserved |
| Delayed-incoming logic silently diverges from M2's PO-level delay logic | Explicit reuse requirement for `WC_Inventory_Overview_PO_Delay::sql_line_delayed_predicate()` (not the PO-scoped predicate) plus a test asserting the SQL flag matches the existing PHP delay calculation |
| `qty_received` dependency creeps in ahead of M5 | Explicit prohibition, forbidden-column verification quality gate, and a dedicated architecture-guard assertion |
| Empty ID-list inputs produce malformed SQL | Explicit requirement that empty inputs return an empty result set without issuing SQL |

No risk identified above requires a scope change to this plan; all mitigations are already specified as binding requirements in the sections above, not proposed additions.

## Plan review notes

> *Drafted by the implementing agent — not part of the supplied source. Pending review.*

- This document is a **materialization**, not an independent plan review. The requester stated the underlying content was approved externally before being supplied to the implementing agent; no separate review thread, reviewer comments, or sign-off record exists in this repository to reproduce here.
- Section-by-section mapping performed: Summary ← source §2 (Scope); Milestone boundaries ← source §3 (Hard prohibitions) + §4's read-only statement; Database ← source preamble + §6; Implementation map ← source §5–§9; Admin UI ← source §9–§13; Implementation sequence ← source §4 (WP1–WP8, verbatim ordering); Testing ← source §14; Quality gates ← source §18 + §15; Documentation ← source §16; Implementation guidance ← source §17, §19, §20, §21. No content from these sections was shortened, summarized, or altered in meaning; only reformatted under M1/M2-style headings.
- Cross-checked against `CLAUDE.md` frozen Architecture v1.0 during materialization (not a modification — Architecture v1.0 and Delivery Roadmap v1.0 text are unchanged by this document): the plan's read-only scope, per-item (not per-parent) Position calculation, single-authoritative-calculator requirement, and reuse of the existing delay predicate are consistent with D11, D12, D13, D19, INV-1, INV-3, INV-5, INV-7, and INV-8 as currently frozen. No conflict was found between the supplied source content and the frozen architecture.
- The **Context**, **Domain model**, **Risk review**, **Plan review notes**, and this verdict were authored by the implementing agent to satisfy the required document structure and have not been reviewed by the plan's original approver. They should be reviewed on their own merits before implementation begins; the requester may accept, edit, or discard them independently of the source-derived sections.

## Definition of Done

- [ ] No schema change; `DB_VERSION` remains `'7'`; no migration exists.
- [ ] `qty_received` absent from `wc_io_purchase_order_lines` (forbidden-column verification passes).
- [ ] Resolver (`WC_Inventory_Overview_Inventory_Position_Resolver`) is stateless, read-only, and independent of `$wpdb`, product loading, and PO repositories; returns exactly `{on_hand, incoming, position, incoming_delayed}`.
- [ ] Repository gains exactly `list_open_lines_for_product_ids()` and `list_open_lines_for_variation_ids()` as two separate queries, qualifying on `status = 'placed'` only, reusing `WC_Inventory_Overview_PO_Delay::sql_line_delayed_predicate()`, with safe handling of empty ID lists.
- [ ] Service (`WC_Inventory_Overview_Inventory_Position_Service`) is the sole caller of the two new repository methods (enforced by architecture-guard tests); `get_position()`/`get_positions_bulk()` produce identical results for the same item; no caching; no writes; no product refetch.
- [ ] Inventory Overview shows Incoming and Position columns adjacent to Stock, gated to `manage_woocommerce` at the same sensitivity tier as average cost/inventory value, with no new capability introduced.
- [ ] Bulk-fetch sequencing constraint honored: `get_positions_bulk()` called exactly once, after the complete groups structure (including late-discovered variations) is built.
- [ ] Drill-down reuses the existing expandable-details pattern, preserves individual PO-line identity, and renders only for authorized users.
- [ ] Variable-parent Incoming/Position are presentation-only sums of child-variation figures; no parent-level incoming record is created.
- [ ] Low-stock badges, Incoming, Position, and delayed indication are simultaneously displayable (never mutually exclusive).
- [ ] All required unit, integration, architecture-guard, capability, and query-scaling tests exist and pass; M0 golden suite and existing characterization fixtures unchanged.
- [ ] `tests/docker/run-phpunit.sh` blocking filter includes the Inventory Position test prefix.
- [ ] No Goods Receipts, Quick Receive, Receive-Against-PO, receiving workflow, stock mutation, cost mutation, Inventory Movements change, Dashboard KPI, storefront expected date, Reserved/Available/Coverage/Forecast, new REST/AJAX/admin-post surface, new capability, or M4+ placeholder exists anywhere in the diff.
- [ ] Supplier behavior, PO lifecycle/mutation behavior, Purchase Order admin screens, and `PO_Delay`/`PO_Quantities`/`PO_Expected` behavior are unmodified.
- [ ] All required documentation deliverables (plan, `CLAUDE.md` status, validation checklist, testing docs, changelog, readme/version references, architecture audit) are complete.
- [ ] All quality gates executed and individually classified; every gate is PASS or PASS WITH KNOWN PRE-EXISTING (legacy, pre-M3) FAILURES — no new failure introduced by M3.
- [ ] Version prepared as `1.20.0`; not tagged, not released.
- [ ] Implementation branch left committed, clean, unpushed, unmerged, unmerged into `main`, ready for independent audit.

## READY FOR IMPLEMENTATION verdict

> *Drafted by the implementing agent — not part of the supplied source. Pending review.*

**READY FOR IMPLEMENTATION**, subject to the caveat below.

The source-derived sections of this plan (Summary through Definition of Done, excluding the sections explicitly marked above) are internally consistent with each other and, per the cross-check recorded in Plan review notes, consistent with the frozen Architecture v1.0 and Delivery Roadmap v1.0 in `CLAUDE.md`. Scope is bounded by an explicit, extensive prohibition list; the work-package sequence (WP1–WP8) is concrete and ordered; test and quality-gate coverage is specified per work package; and the milestone introduces no schema change, consistent with its "read-only" framing.

**Caveat:** this verdict is the implementing agent's assessment, not an independent architectural review. The requester has stated the underlying plan content was approved externally prior to being supplied here; this document does not add a second, competing approval — it records that no inconsistency was found during materialization. The **Context**, **Domain model**, **Risk review**, and **Plan review notes** sections were authored by the implementing agent and should be confirmed or corrected by the requester before implementation begins, independent of confirming the source-derived sections.
