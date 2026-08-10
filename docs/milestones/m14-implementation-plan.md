# Milestone M14 Implementation Plan — Supplier Order History

**Status:** Approved. This document is the immutable implementation specification for Milestone M14, materialized from the approved Plan-mode document before any implementation code was written, per `docs/process/milestone-lifecycle.md` WP0 step 5 / Permanent Repository Rule 1. Once committed, this file is never edited, replaced, or repurposed — any future freeze/readiness record belongs in `docs/checklists/m14-release-readiness.md`, not here.

## Materialization note

Materialized verbatim from the approved Plan-mode document
(`milestone-m14-discovery-humble-wave.md`, amended after user review to keep
M13+M14 on the same unreleased feature train, scope the performance
contract to the Order History code path specifically, use a dedicated
`wc_io_supplier_order_history_page` pagination parameter, and label the
value columns "Ordered Value" / "Received Value (PO Cost)" to exclude any
landed-cost/inventory-valuation implication) after pre-flight verification
on `feature/m13-printable-purchase-order` at `9632215549cb0605b999646409da9c7f87c285ad6`
(plugin `1.30.0`, `DB_VERSION` `10`, last released `v1.29.0`, M13 frozen
Level A / CI green / PR #14 open draft and unmerged, `main` unchanged at the
`v1.29.0` line). `feature/m14-supplier-order-history` branches from this
commit. No design amendments at materialization time — this is the plan
exactly as approved.

# M14 — Supplier Order History (Definitive Plan)

## Context

M13 (Printable Purchase Order) is implemented and frozen on
`feature/m13-printable-purchase-order`, dev version `1.30.0`, CI green, PR #14
open as CI-only/draft. Per `docs/checklists/m13-release-readiness.md`, the
only authorized next process step is "Plan M14, or close this new train —
only with explicit approval." This task performs that discovery from a clean
read of the actual repository state (not from memory of prior milestones),
selects one coherent M14 scope, and produces the complete implementation
specification for review before any code is written.

---

## PART A — Verified baseline

| Check | Result |
|---|---|
| Path | `/opt/biopentra/dev/wc-inventory-overview` |
| Branch | `feature/m13-printable-purchase-order` |
| Working tree | Clean |
| Sync | `origin/main` is an ancestor of the branch tip; branch matches its remote |
| Plugin version | `1.30.0` |
| `DB_VERSION` | `10` |
| Last released | `v1.29.0` (tagged, published; `main` tip is `67321bb`) |
| M13 plan | `docs/milestones/m13-implementation-plan.md` exists, immutable, single commit `73b0880` |
| M13 freeze record | `docs/checklists/m13-release-readiness.md` exists, Level A complete |
| PR #14 | Open, **draft**, base `main`, head `feature/m13-printable-purchase-order`, all 3 checks (`PHP lint`, `PHP Parallel Lint`, `PHPUnit`) `SUCCESS` |
| `main` | Still at `v1.29.0` line — not advanced past M13 |
| M14 branch | None exists |
| `docs/milestones/m14-implementation-plan.md` | Does not exist |

No material mismatch. Proceeded to discovery.

---

## PART B — Discovery findings

Read in full: `CLAUDE.md`, `README.md`, `CHANGELOG.md`, `readme.txt`,
`docs/ARCHITECTURE_BASELINE_v1.24.0.md`, `docs/architecture-audit.md`,
`docs/OWNERSHIP.md`, `docs/process/milestone-lifecycle.md`,
`docs/admin-guide-purchase-orders.md`, `docs/admin-guide-suppliers.md`,
`docs/testing.md`, `docs/release-runbook.md`, `docs/rollback-plan.md`,
`docs/checklists/validation-checklist.md`,
`docs/milestones/m13-implementation-plan.md`,
`docs/checklists/m13-release-readiness.md`, plus every milestone plan
`m1`–`m13` and a repo-wide grep for deferred/TODO/future markers, and direct
inspection of the relevant production classes.

**Zero literal `TODO`/`FIXME`/`@todo` in code.** This project's convention is
that all deferrals live in docs/plans, never as code comments — confirmed
again here.

**Every open backlog item has already been evaluated on its merits at least
once**, most more than once (M9, M11, M12, M13 discovery passes all
re-examined the same list). Classified:

| Item | Class | Status |
|---|---|---|
| Supplier order-history reporting | A (product) | Deferred every time since M9 ("undesigned"/"separate screen design", never rejected on merits) — **selected, see below** |
| Supplier spend analysis | A (product) | Deferred alongside order-history; needs a currency-normalization policy decision order-history doesn't need — narrower-scoped, correctly left for a later milestone |
| Supplier merge tool | A/C | Named in `docs/admin-guide-suppliers.md:239-240` as "planned"; cross-table FK reassignment (POs, receipts, movements) = high mutation risk, Level B, own milestone |
| PO delay grace-days Settings UI | D (maintenance) | Rejected twice as "too small alone" (`docs/milestones/m12-implementation-plan.md:88`) |
| Expected-date suggestion source transparency | B (workflow, tiny) | Rejected twice as "too small alone" |
| Inventory Position supplier/incoming drilldown | B (workflow, small) | Rejected as "unrelated subsystem polish" (`m12-implementation-plan.md:87`) |
| Expected Delivery confidence from supplier history | C (new domain, storefront) | Flagged high risk — "needs usage + audit" before any change to customer-facing wording |
| Inventory Coverage/Forecast | C (new domain) | Architecturally blocked — needs sales-velocity data this plugin does not collect (D11 reserved slot, not ready) |
| Reservations / Available Stock | C (new domain) | Forbidden by D16 until a concrete consumer exists — none does yet |
| Inbound Shipment entity | C (new domain) | No concrete need (D10); tracking/carrier already live on the PO |
| Warehouse location hierarchy | C (new domain) | Reserved in `docs/OWNERSHIP.md` registry but zero code/schema exists; a large new initiative on its own |
| REST / Store API / GraphQL | C (new domain) | D16 forbids speculative API surface; no consumer named anywhere |
| PO-number allocation atomicity | D (maintenance) | Formally accepted as an optimization gap, not a defect (`docs/adr/0002-po-number-allocation-concurrency.md`) — correctness intact via the `UNIQUE` constraint |
| Plugin god-class split | D (maintenance) | Deferred at M8 and every milestone since; blocks no capability |
| PHPCS/deferred maintenance | E/F | `.phpcs-baseline.xml` currently empty (no recorded violations); "reasonable future initiative," not blocking |

**Workflow review (Supplier → PO → arrival → print → receiving → Position →
storefront → performance → comparison → next PO):** M9–M12 answered "is this
supplier reliable, and how does it compare to others?" entirely from
*statistics* (lead time, on-time rate). M13 answered "can I hand this PO to
someone as a document?" What no screen answers today: **"what has this
supplier actually sold us, and when?"** — the PO-by-PO history behind those
statistics. An operator deciding whether to reorder, dispute an invoice, or
renegotiate terms has no way to see a supplier's order list without manually
filtering the main Purchase Orders screen by supplier name each time.

**Which deferred capability is ready now?** Direct inspection of
`includes/class-wc-inventory-overview-purchase-orders.php` shows
`list()`/`count()` **already accept a `supplier_id` filter**
(lines 64–113, 389–424) — built for the PO admin screen's own supplier filter
and never exposed on the Supplier side. `line_total( $po_id )` (lines
135–146) already computes ordered value. `qty_received` is already
maintained per INV-4 by `PO_Receiving_Sync`. **No new query capability, no
new table, and no new domain owner are needed** — this is the single
best-supported "ready to implement" item on the entire list, more so even
than M13 was at its own selection.

---

## PART C — Candidate comparison

| Candidate | Value | Size | Risk | Schema | Level | Same train as M13? |
|---|---|---|---|---|---|---|
| **Supplier order history** | High — closes the named comparison/audit gap | Small | Low | None | A | No (different capability domain) |
| Supplier spend analysis | Medium-high | Medium-large (needs FX-normalization policy) | Medium | Prefer none | A/B | No |
| Supplier merge tool | Medium (hygiene) | Medium | **High** (cross-table FK reassignment) | None | B | No |
| PO delay grace-days Settings UI | Low | Tiny | Low | None | A | Unrelated |
| Suggestion-source transparency | Low | Tiny | Low | None | A | Unrelated |
| Position supplier drilldown | Low-medium | Small | Low | None | A | Unrelated |
| Expected Delivery confidence from history | Medium | Medium | **High** (storefront) | None | B | Unrelated |
| Coverage/Forecast | High long-term | Very large | High | Likely | B | New domain, wrong train |
| Reservations | Medium | Large | High | Likely | B | New domain, blocked by D16 |
| Inbound Shipment | Low today | Large | Medium | Likely | B | New domain, no consumer |
| Warehouse locations | Medium long-term | Very large | Medium | Yes | B | New domain initiative on its own |
| REST/Store API/GraphQL | Low today | Large | Medium | None | B | New domain, no consumer |
| PO-number atomicity | Low (already accepted) | Small | Low | None | A | Maintenance, non-blocking |
| Plugin god-class split | None (pure refactor) | Large | Medium | None | A | Maintenance, non-blocking |
| PHPCS cleanup | None (pure hygiene) | Unknown (0 recorded) | Low | None | A | Maintenance, non-blocking |

**LOW-RISK EXTENSION:** Supplier order history — composes existing sole
owners (`Purchase_Orders`, `Purchase_Order_Lines`) only, no new domain
concept, no schema.

**NEW DOMAIN INITIATIVES** (explicitly not M14): spend analysis (needs a
cross-currency valuation policy this milestone should not improvise),
supplier merge (needs Level B and a reassignment design), Coverage/Forecast,
Reservations, Inbound Shipment, Warehouse locations, REST/API.

---

## PART D — Selected M14 and rationale

**M14 — Supplier Order History.** A new read-only, paginated list of a
supplier's Purchase Orders (all statuses, including `draft`/`cancelled`) on
the existing Supplier detail admin screen, directly below the existing
Observed Lead Time / On-Time Delivery Rate panel.

Why this one, specifically, and not the runner-up (spend analysis) bundled
in: order-history has been named as deferred without ever being rejected on
its merits since M9; it is fully supported by existing `Purchase_Orders`
read methods (`list()`/`count()` already filter by `supplier_id`); it
requires zero new domain owner, zero schema change, and zero currency-policy
decision (each row shows its own PO's value in its own PO's currency — never
summed). Spend analysis is the natural *next* milestone once order history
exists to display it against, but it requires a currency-normalization
policy (POs for one supplier can each carry a different `currency` — see
`purchase-orders.php:71`, `orderby` includes `currency`) that deserves its
own explicit design decision rather than being improvised inside M14. Adding
it now would repeat the exact "compound, undesigned feature" mistake M9's
and M11's discovery passes already flagged and avoided for supplier merge.

M13 and M14 are different capability domains (document presentation vs.
supplier reporting) sharing no files and no schema, but both are low-risk,
read-only, Level A milestones on the same still-unreleased post-v1.29.0
train. Per Part G, M14 continues that train rather than forcing an
interim release of M13 alone — the repository's own process deliberately
batches small milestones rather than releasing each individually.

---

## PART E — Definitive implementation plan

### 1. Problem statement

A merchant deciding whether to reorder from, renegotiate with, or dispute an
invoice from a supplier has no way to see that supplier's order history
without manually filtering the main Purchase Orders list by supplier name
every time. The observed-lead-time and on-time-rate statistics (M9, M11)
summarize this history but never show it.

### 2. Goals

- On the existing Supplier detail screen (`render_supplier_detail()` in
  `includes/class-wc-inventory-overview-purchasing-page.php`), add an
  **Order History** section listing every Purchase Order for that supplier
  (all six statuses — draft included, per INV-6 auditability), newest
  `order_date` first, paginated.
- Each row: PO number (linked to the existing PO detail screen), order date,
  status (using the existing `PO_Statuses::label()`), expected date +
  confidence, **Ordered Value** (`qty_ordered × unit_cost`, in that PO's
  own `currency`), **Received Value (PO Cost)** (`qty_received × unit_cost`,
  same currency — see §7 for why this label, not a generic "value").
- Zero new query per row: bulk-computed ordered/received values for the
  page's POs in one additional query (see §9).
- Zero mutation. Zero new capability. Zero schema change.

### 3. Non-goals (explicit)

- No spend analysis, no cross-PO or cross-currency totals/aggregates, no
  trend charts, no time-bucketing — a row never sums with another row.
- No changes to `Purchase_Orders`/`Purchase_Order_Lines` write paths, PO
  lifecycle, statuses, or events.
- No supplier merge, no grace-days Settings UI, no suggestion-source UI, no
  Position drilldown change, no storefront change, no Coverage/Forecast,
  Reservations, Inbound Shipment, or Warehouse locations work.
- No new public API, hook, filter, or capability.
- No sortable columns beyond the fixed `order_date DESC` default (same
  reasoning M12 used to reject sortable observed/on-time columns: a
  page-local sort would mislead, a SQL sort duplicates aggregate ownership
  for no proven need yet).

### 4. Current architecture (relevant slice)

- `WC_Inventory_Overview_Purchase_Orders` — sole read/write owner of PO
  headers; `list()`/`count()` already accept `supplier_id` (and `order_date`
  is already an allowed `orderby`); `line_total( $po_id )` already computes
  ordered value for one PO (used by the PO detail screen).
- `WC_Inventory_Overview_Purchase_Order_Lines` — sole owner of PO lines;
  `qty_received` already maintained per INV-4 by `PO_Receiving_Sync`.
- `WC_Inventory_Overview_PO_Statuses::label()` — existing status-label
  helper, already reused by M13's print renderer.
- `WC_Inventory_Overview_Purchasing_Page::render_supplier_detail()` —
  existing Supplier detail screen, already gated by
  `current_user_can( 'manage_woocommerce' )`; already renders the Observed
  Lead Time / On-Time Rate panel via `render_observed_lead_time()`.

### 5. Ownership model

- **Business-rule owner:** none new — no new business rule; `qty_outstanding`
  (INV-4), status transitions, and confidence remain entirely owned by
  existing PO classes.
- **Read owner:** new `WC_Inventory_Overview_Supplier_Order_History_Service`
  (Internal, not Public — D16). Justification for a new class rather than
  extending `Purchase_Orders` directly: this mirrors the established pattern
  of `Supplier_Lead_Time_Service` (M9) — a thin, Internal, sole-owner
  composition layer over existing read owners for a *supplier-centric*
  projection, keeping `Purchase_Orders` itself focused on PO-centric
  CRUD/list rather than accumulating supplier-report-shaped methods.
  It calls only `Purchase_Orders::list()`, `Purchase_Orders::count()`, and
  the one new bulk-value method below — no direct `$wpdb`.
- **Mutation owner:** none — zero mutation surface.
- **Presentation owner:** `Purchasing_Page::render_order_history_section()`,
  a new method on the existing class, called from
  `render_supplier_detail()` immediately after
  `render_observed_lead_time()`.

Impact check against every listed domain: Suppliers (read-only extension of
the detail screen), Purchase Orders (one new additive read method), PO
Print (untouched), Goods Receipts (untouched — received value is computed
from the PO line's own `qty_received` counter per INV-4, never by reading
Goods Receipts directly, so no new cross-domain read dependency is
introduced), PO Delay (untouched), Inventory Position (untouched), Supplier
Lead Time (untouched — a sibling Internal service, not reused, not
modified), Expected Deadline (untouched), Expected-Date Suggestion
(untouched), Supplier On-Time Rate (untouched), Expected Delivery
(untouched), Storefront (untouched).

### 6. Data/domain flow

```
Supplier detail screen (existing, manage_woocommerce-gated)
  → Supplier_Order_History_Service::get_page( $supplier_id, $page, $per_page )
      → Purchase_Orders::count( ['supplier_id' => $id] )                 (existing)
      → Purchase_Orders::list( ['supplier_id' => $id,
                                 'orderby' => 'order_date', 'order' => 'DESC',
                                 'per_page' => $n, 'offset' => $o] )      (existing)
      → Purchase_Orders::values_bulk( $po_ids )                          (NEW, additive)
  → render_order_history_section()  (presentation only; no writes)
```

### 7. Exact business rules

- A supplier's order history includes **every** PO for that `supplier_id`
  regardless of status — `draft`, `placed`, `partially_received`,
  `received`, `cancelled`, `closed_short` all appear (INV-6: nothing is
  hidden from its own audit trail). This differs deliberately from M13's
  print feature, which excludes `draft` — printing a draft is a customer-
  facing-document risk; *viewing* your own draft in your own order history
  is not.
- Ordered value for a PO = `SUM( qty_ordered × unit_cost )` over its lines —
  identical formula to the existing `line_total()`, computed in bulk here.
- Received value for a PO = `SUM( qty_received × unit_cost )` over its
  lines — new, but the same shape, using the already-maintained
  `qty_received` counter (INV-4); never re-derives from Goods Receipts.
- **Value semantics are deliberately narrow.** Both figures use the PO
  line's own `unit_cost` — the price committed to on the Purchase Order, in
  that PO's own `currency`. Neither figure is a financial valuation: they
  exclude landed costs (freight, duty, etc. — owned by `Receipt_Costs`,
  never read here), exclude the weighted-average/EUR inventory-value figure
  Goods Receipt posting writes to product meta, and are never FX-normalized
  or summed across POs. To make this boundary visible rather than implied,
  the received-value column is labeled **"Received Value (PO Cost)"** in
  the UI — not "Received Value" or "Cost" alone — so it cannot be mistaken
  for the landed/weighted-average cost the Goods Receipt domain separately
  owns and displays elsewhere. The ordered-value column is labeled
  **"Ordered Value"**.
- Sort order is fixed: `order_date DESC`, ties broken by `id DESC`
  (deterministic pagination — same tie-break convention already used
  elsewhere in the PO list `build_where`/`orderby` logic).

### 8. New invariants

- **INV-M14-1:** The order-history section performs zero PO, line, or
  receipt mutation — presentation- and read-only, exactly as M13's INV-M13-2
  established for the print renderer.
- **INV-M14-2:** Ordered/received values are never summed or displayed
  across POs of different currencies, and never conflated with landed cost
  or inventory valuation. Each row shows its own PO's `currency` beside its
  own PO-cost-only value (labeled "Ordered Value" / "Received Value (PO
  Cost)"). No blended/aggregate total, and no landed-cost or weighted-
  average figure, is ever rendered here.
- **INV-M14-3:** `Supplier_Order_History_Service` reads exclusively through
  `Purchase_Orders`'s public static methods — no direct `$wpdb` access, no
  read of `Purchase_Order_Lines`, `Goods_Receipts`, or any other table
  directly (mirrors INV-M13-3's "approved read owner only" rule).
- **INV-M14-4:** History is supplier-scoped and status-inclusive — every PO
  for the supplier appears regardless of status; the section never filters
  out `draft` or terminal statuses.

### 9. Schema / mutation

- Schema change: **No.**
- `DB_VERSION` impact: **None — stays `10`.**
- Migration: **None.**
- Persistent data: **None new.**
- Mutation paths: **None.** Read-only feature.
- Transaction requirements: **None** (no writes).
- Rollback implications: **None beyond reverting code** — no data was
  written that would need reversal.

### 10. Development-version target

`1.31.0`, bumped from the M13 development baseline `1.30.0` on
`feature/m13-printable-purchase-order` (see Part G). `DB_VERSION` stays
`10`. Last released version remains `v1.29.0` — no release/tag/merge/deploy
action occurs during M14 implementation.

### 11. Public API impact

None. `Supplier_Order_History_Service` is Internal, not Public (D16), same
classification as `Supplier_Lead_Time_Service` and `Expected_Date_Suggestion_Service`.

### 12. Security / capability impact

None new. The section renders inside `render_supplier_detail()`, already
gated by `current_user_can( 'manage_woocommerce' )`
(`purchasing-page.php:56`,`84`,`95`). Each PO-number link navigates to the
existing PO detail screen, which independently re-checks `VIEW_PO` on
arrival — no new trust boundary is created or bypassed.

### 13. Admin/UI behavior

New "Order History" `<h3>` section on the Supplier detail screen, styled
consistently with the existing Observed Lead Time panel (definition-style
stat rows above, a plain HTML table below — following the same lightweight
convention M3's Inventory Position drill-down uses, not a new
`WP_List_Table` subclass, since this is one section of an existing
single-entity edit screen, not a standalone list screen). Empty state:
"No purchase orders yet for this supplier." when count is 0.

**Pagination state (dedicated, not shared):** a namespaced GET parameter
`wc_io_supplier_order_history_page` (repository convention: this plugin's
admin-page GET/POST params are already prefixed `wc_io_`/`wc-io-`, e.g.
`wc_io_supplier_lead_time`) — never the generic `paged`, which WordPress
list-table screens and any future paginated section on this same page may
independently use. Rules:

- Read via `isset( $_GET['wc_io_supplier_order_history_page'] ) ? absint( $_GET['wc_io_supplier_order_history_page'] ) : 1`, then clamped to a minimum of `1` (so `0`, negative, or non-numeric input all resolve to page 1 — no validation error, just a safe default).
- `offset = ( $history_page - 1 ) * $per_page`, with `$per_page = 20` (a class constant on `Supplier_Order_History_Service`, matching `Supplier_Lead_Time_Service::MINIMUM_SAMPLE_COUNT_FOR_DISPLAY`'s convention of a named constant rather than a magic number).
- Out-of-range pages (`offset >= count`) degrade safely: `Purchase_Orders::list()` returns an empty array (no error, no fatal — the same behavior the existing PO list screen already relies on for its own out-of-range pages), and the section renders its normal empty-state row *for that page* rather than "no purchase orders yet" (the two messages are worded differently — the latter is reserved for `count === 0`, an out-of-range page with `count > 0` gets a distinct "no results on this page" message).
- Pagination links preserve the current Supplier detail URL/context (`page=wc-io-purchasing&tab=suppliers&action=edit&supplier_id=…`) and vary only `wc_io_supplier_order_history_page` — implemented via `add_query_arg()` against the current URL, the same helper already used elsewhere in this admin (e.g. the Suppliers list screen's own pagination), never a hand-built query string.

### 14. Production files/classes affected

- `includes/class-wc-inventory-overview-purchase-orders.php` — add
  `values_bulk( array $po_ids ): array` (additive method, same class,
  same pattern as the existing `line_total()`).
- `includes/class-wc-inventory-overview-purchasing-page.php` — add
  `render_order_history_section( int $supplier_id )`, called from
  `render_supplier_detail()`.

### 15. New classes/files

- `includes/class-wc-inventory-overview-supplier-order-history-service.php`
  — new Internal service, sole owner of the supplier-order-history read
  projection.

### 16. Hooks/integration points

None new. No hook, filter, or hookable hookpoint is introduced.

### 17. Query/performance contract

This contract covers only the queries **added by the Order History
section itself** — it is not a claim about the total query count of the
Supplier detail page load, which already runs its own unrelated
supplier/statistics queries (e.g. `render_observed_lead_time()`'s call into
`Supplier_Lead_Time_Service`) untouched by M14.

**Non-empty history page** (`count > 0`): exactly **3** M14-added queries,
independent of page size (bounded `per_page`, default 20) and independent
of total history size:
1. `Purchase_Orders::count( ['supplier_id' => $id] )` — existing method,
   reused as-is.
2. `Purchase_Orders::list( [...] )` — existing method, reused as-is.
3. `Purchase_Orders::values_bulk( $po_ids )` — **new**, one
   `SUM(qty_ordered*unit_cost)`/`SUM(qty_received*unit_cost)` query grouped
   by `po_id` over the current page's PO ids only (never all of a
   supplier's history at once) — no N+1, matching the project's standing
   discipline (D12; M9's/M12's bulk-query precedent).

**Empty history** (`count == 0`): exactly **1** M14-added query —
`Purchase_Orders::count()` only. `Purchase_Orders::list()` is short-circuited
(no rows to fetch, so `Supplier_Order_History_Service::get_page()` returns
an empty page without calling `list()`), and `values_bulk()` is never
called with an empty `$po_ids` array — it returns `[]` immediately without
issuing SQL, mirrored by a unit test asserting zero queries for an
empty-array input.

A performance test at 200-PO-for-one-supplier scale (mirroring M9's
200-supplier scale test) asserts the M14-added query count stays at
exactly 3 for a non-empty page, and a separate test on a zero-PO supplier
asserts exactly 1. Both tests count only the queries issued inside the
Order History code path (isolated via a query-counting wrapper scoped to
the `Supplier_Order_History_Service` call), not the full page request.

### 18. Backward compatibility

Fully additive. No existing method signature changes; no existing behavior
changes. The Supplier detail screen gains a new section; nothing currently
rendered there is altered or removed.

### 19. Rollback strategy

Revert the commit(s). No data written, no schema changed — rollback is
code-only, matching M13's own "no schema, no mutation" rollback shape (see
`docs/rollback-plan.md`'s existing pattern for zero-schema milestones).

### 20. WP breakdown

- **WP-M14-0:** Branch from `feature/m13-printable-purchase-order` (the
  current feature-train head; M13 itself remains untouched on that
  history). Materialize this approved plan into
  `docs/milestones/m14-implementation-plan.md`, commit alone, immutable
  from that point (per `docs/process/milestone-lifecycle.md` WP0.5). Bump
  development version `1.30.0` → `1.31.0` as part of this commit or
  WP-M14-6 (consistent with M13's own convention of a dedicated version-bump
  commit).
- **WP-M14-1:** `Purchase_Orders::values_bulk()` + unit tests.
- **WP-M14-2:** `Supplier_Order_History_Service` (paginated read
  projection) + unit tests + architecture guard (sole-consumer allowlist,
  read-owner-only sourcing, mirroring `test-po-print-architecture.php`'s
  shape).
- **WP-M14-3:** `render_order_history_section()` UI + integration tests
  (rendering, pagination, empty state, capability gate, status inclusion
  including `draft`).
- **WP-M14-4:** Performance test at 200-PO-for-one-supplier scale; CI
  filter update (`tests/docker/run-phpunit.sh` — add
  `Test_WC_IO_Supplier_Order_History_`).
- **WP-M14-5:** Documentation — `docs/admin-guide-suppliers.md` (move
  "order-history reporting" from "Not Yet Available" to a documented
  feature), `CHANGELOG.md`, `CLAUDE.md` Implementation Status table,
  `readme.txt`.
- **WP-M14-6:** Version bump to `1.31.0`, freeze checklist
  (`docs/checklists/m14-release-readiness.md`).

### 21. Unit tests

- `tests/unit/supplier-order-history/test-supplier-order-history-service.php`
  — pagination math, sort order/tie-break, empty-supplier case, formula
  correctness (`ordered`/`received` values vs. hand-computed fixtures),
  status-inclusive behavior (draft/cancelled/closed_short all present).
- `tests/unit/supplier-order-history/test-supplier-order-history-architecture.php`
  — INV-M14-3 guard: `Supplier_Order_History_Service` calls only
  `Purchase_Orders::` methods, never `$wpdb`/`Purchase_Order_Lines`/
  `Goods_Receipts` directly; sole-consumer allowlist for `values_bulk()`.
- Extend `tests/unit/purchase-orders/test-po-service.php` (or a focused new
  file) with `values_bulk()` correctness/edge cases (empty array, single PO,
  mixed currencies — confirms no cross-PO summing happens inside the method
  itself either).

### 22. Integration tests

- `tests/integration/supplier-order-history/test-supplier-order-history-admin.php`
  — full render through `render_supplier_detail()`: capability gate
  (non-`manage_woocommerce` user sees nothing), pagination across pages,
  PO-number links point at the correct existing PO detail URL, currency
  displayed per-row (never blended), empty state message.

### 23. Architecture guards

- Sole-consumer allowlist for `Supplier_Order_History_Service` (same
  mechanism already used for `Supplier_Lead_Time_Service`,
  `Expected_Date_Suggestion_Service`, `PO_Print_Renderer`).
- Static guard confirming `values_bulk()` contains no write statement
  (`INSERT`/`UPDATE`/`DELETE`) — read-only method on a class that otherwise
  legitimately does write.

### 24. Performance tests

- 200-PO-for-one-supplier fixture; assert exactly 3 queries for one page
  load regardless of `per_page` value tested (10/20/50).

### 25. Manual/browser acceptance

1. Open a supplier with >20 historical POs across mixed statuses and
   currencies. Confirm the Order History section lists them newest-first,
   paginates correctly, shows each PO's own currency next to its own
   value, and never shows a summed total.
2. Confirm a `draft` PO for that supplier appears in the history (unlike
   M13's print feature, which excludes drafts) and a `cancelled`/
   `closed_short` PO also appears.
3. Click a PO number; confirm it opens the existing PO detail screen.
4. Log in as a user without `manage_woocommerce`; confirm the whole
   Supplier detail screen (unchanged pre-existing gate) remains
   inaccessible.
5. Open a supplier with zero POs; confirm the empty-state message.

### 26. Regression requirements

Full M1–M14-focused suite green; existing Supplier detail screen
(Observed Lead Time, On-Time Rate, archive/reactivate) unaffected; existing
Purchase Orders admin screen (list, filter by supplier, create/edit/place/
cancel/close-short/duplicate, print) unaffected — `values_bulk()` is
additive and untouched by any existing call site.

### 27. Documentation deliverables

- `docs/admin-guide-suppliers.md` — move order-history reporting out of
  "Not Yet Available" into a documented, described feature.
- `CHANGELOG.md` — new `[1.31.0]` entry in the established format.
- `CLAUDE.md` — new M14 row in the Implementation Status table.
- `readme.txt` — changelog stub entry.
- New `docs/milestones/m14-implementation-plan.md` (materialized at WP0,
  immutable thereafter) and `docs/checklists/m14-release-readiness.md`
  (at freeze).

### 28. Acceptance criteria

- Order History section renders on the Supplier detail screen for every
  supplier, correctly paginated, status-inclusive, currency-correct,
  zero cross-PO totals.
- `values_bulk()` and `Supplier_Order_History_Service` covered by passing
  unit/integration/architecture/performance tests.
- Zero schema change confirmed (`install.php` diff-clean against `main`).
- Zero mutation confirmed by architecture guard.
- Full test suite green, 0 risky, 0 failures, 0 errors.
- `scripts/release-audit.sh --development` green.
- GitHub Actions green on the M14 branch/PR.

### 29. Definition of Done

Implementation complete, Level A completion review complete (see Part F),
documentation updated and consistent, freeze checklist recorded, CI green,
no document claims M14 is released/merged/tagged before it actually is
(Rule 5, `milestone-lifecycle.md`).

### 30. Risks/mitigations

| Risk | Mitigation |
|---|---|
| Mixing PO currencies into a misleading total | INV-M14-2 forbids any summed/blended value; enforced by architecture guard + test |
| N+1 queries as history grows | Bulk `values_bulk()` query, capped by pagination; performance test at 200-PO scale |
| Scope creep into spend analysis | Explicit non-goal (§3); spend analysis is named as a deliberately separate future milestone, not folded in |
| Accidentally exposing drafts as if they were placed orders | Status label always rendered per row (via existing `PO_Statuses::label()`); no visual treatment implies a draft is a real commitment |

### 31. Deferred work (explicitly, for a later milestone — not M14)

- Supplier spend analysis (needs a currency-normalization policy decision).
- Supplier merge tool (needs Level B, cross-table FK design).
- PO delay grace-days Settings UI, suggestion-source transparency, Position
  supplier drilldown (all previously rejected as "too small alone" —
  unchanged by this discovery).
- Expected Delivery confidence from supplier history (needs a storefront-
  behavior-change audit).
- Coverage/Forecast, Reservations, Inbound Shipment, Warehouse locations,
  REST/Store API/GraphQL — all still blocked or undesigned per Part B.
- Plugin god-class split, PHPCS cleanup — maintenance, non-blocking.

### 32. Commit strategy

One commit per WP (WP-M14-0 through WP-M14-6), matching this plan's own
breakdown, per `docs/process/milestone-lifecycle.md` WP1.

### 33. Stop conditions

Stop and return to the user (no silent scope compensation) if: `Purchase_Orders::list()`'s
existing `supplier_id`/`orderby` support turns out to behave differently
under load than this plan assumes; any existing call site of `line_total()`
would need to change to accommodate `values_bulk()` (it should not — they
are independent, siblings); or CI cannot be made green without touching
code outside this plan's file list.

### 34. Final implementation-report contract

The implementation report must state, verified against the repository
itself (not the plan's own claims): exact files touched, exact query count
measured (not assumed) at 200-PO scale, confirmation `install.php` has zero
diff against `main`/the branch base, confirmation no existing test file was
modified to accommodate this feature (only new files + the two additive
methods), and full CI run URLs (CI + Tests workflows) with conclusions.

---

## PART F — Risk / lifecycle classification

**LEVEL A** — lightweight completion review, no independent Level B audit.

Justification: no schema/migration, no stock/cost mutation, no PO/receipt
lifecycle mutation, no destructive operation, no public API, no
security/capability change, no storefront behavior change, no complex
transaction/concurrency surface, no ownership-boundary change. This is a
strict subset of what M13 already qualified as Level A for, and lower risk
than M13 in one respect (M13 introduced a new user-facing `admin_post_`
action; M14 introduces no new request-handling entry point at all — it is
rendered inline on an existing, already-gated page load).

---

## PART G — Feature-train recommendation

**Recommendation: A — continue the current unreleased train. M14 branches
from `feature/m13-printable-purchase-order` and becomes the new
feature-train head. Do not release M13 standalone.**

Reasoning:

- M13 (Level A, frozen, CI green) and M14 (Level A, read-only, zero schema)
  are both low-risk milestones on the same still-unreleased post-v1.29.0
  train. This repository's own process (`docs/process/milestone-lifecycle.md`
  WP5/WP6) deliberately stopped releasing every small milestone
  individually — M9–M12 were batched into one `v1.29.0` release precisely
  to avoid tag/release churn for a sequence of small, low-risk additions.
  M13+M14 fit that same pattern.
- Sequence:
  1. M13 remains exactly as frozen — untouched, unmerged, PR #14 unchanged.
  2. M14 branches from `feature/m13-printable-purchase-order` (the current
     feature-train head), carrying M13's work forward.
  3. Development version bumps from `1.30.0` to **`1.31.0`** for M14;
     `DB_VERSION` stays `10`; last released version remains **`v1.29.0`**
     throughout M14's implementation.
  4. M14 follows the same WP0→WP4 lifecycle as M13 (plan → implement →
     Level A completion review → freeze) and stops at WP5 — no merge, tag,
     release, or deployment during or immediately after M14.
  5. **After M14's freeze**, make a fresh, explicit release-timing decision
     (continue the train with an M15, or close and release M13+M14
     together via WP6) — exactly the same decision point M13's own freeze
     record deferred to "plan M14, or close this new train, only with
     explicit approval." This plan does not pre-decide that future choice.
- No release, tag, merge, or deploy action is taken during this planning
  task or is authorized to happen during M14 implementation.
