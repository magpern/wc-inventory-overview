# Milestone M9 — Supplier Observed Lead-Time Statistics (target v1.26.0)

**Materialization note:** this plan was designed and approved while M8
("Hardening & GA") was still in-flight on `feature/m8-hardening-ga`, not yet
merged to `main`. §0 below is preserved exactly as written and approved at
that time — it is the record of what was true during planning, including the
explicit condition this milestone's branch-cut was gated on: *"branch M9
from `main` only after M8 merges and tags `v1.25.0`."* That condition is now
satisfied: `main` is at commit `5a62111` ("M8: Hardening & GA (v1.25.0) (#9)"),
plugin version `1.25.0`, `DB_VERSION` `10`, working tree clean, verified
immediately before this branch was cut. No other fact in this plan has
changed. Implementation proceeds exactly as approved, with no scope, design,
or work-package changes.

## 0. Context

M0–M7 shipped the frozen inbound-inventory platform baseline (plugin v1.24.0,
`DB_VERSION` 10), documented in `docs/ARCHITECTURE_BASELINE_v1.24.0.md`. M8
("Hardening & GA," target v1.25.0) is the mandatory closing milestone that
finishes GA readiness before any new product capability is added.

**M8 is not yet canonical on `main`.** This planning pass verified directly:

- `git fetch origin` — local `main` and `origin/main` are identical at
  `0d6257c` ("Architecture Baseline v1.24.0 (documentation-only, prerequisite
  for M8)"). `main` has **not** advanced past the v1.24.0 baseline.
- The current checkout is on `feature/m8-hardening-ga`, one commit ahead of
  `main` (`9f6ef5c`, adding `docs/milestones/m8-implementation-plan.md`),
  **plus an uncommitted, in-progress working tree**: WP1 (physical deletion
  of the M6-deprecated Batch Intake code) is actively underway — staged
  deletion of `class-wc-inventory-overview-batch-intake-ui.php` and its
  characterization test, unstaged edits stripping `ajax_batch_preview()` /
  `handle_batch_apply_post()` from `plugin.php` and rewriting
  `create_legacy_batch()` in `test-case.php`.
- Plugin version is still `1.24.0`, `DB_VERSION` is still `10` everywhere
  (main and working tree) — M8 has changed no shipped fact yet.

**Distinguishing what depends on M8 vs. what doesn't**, per this task's
instruction: M8's eight work packages (delete deprecated Batch Intake code,
fix `PO_Delay`'s `partially_received` gap, add a repo-wide sibling-plugin
conformance guard, repair golden tests, promote the integration suite to
CI-blocking, CI hardening, public-API conformance review, docs finalization)
touch Batch Intake, `PO_Delay`, CI config, and the two existing public
services. None of them touch the Supplier, Purchase Order, or Goods Receipt
*read* surface this milestone needs, and none of them change `DB_VERSION` or
any table this milestone reads from. **This milestone has no code
dependency on M8 landing.** It only needs the v1.24.0-frozen schema, which
M8 does not alter. However, this repository's own practice has been strictly
sequential, one milestone frozen before the next starts (M1→M2→…→M7→M8, one
`feature/m{N}-*` branch at a time, never overlapping). This plan recommends
following that same discipline: **branch M9 from `main` only after M8 merges
and tags `v1.25.0`.** The design below is fully valid to review and approve
now; only the branch-cut is gated on M8's completion.

---

## 1. Objective

Give merchants and the purchasing workflow **observed, evidence-based
supplier lead-time statistics** — average / fastest / slowest delivery time
and completed-order sample count, computed from actual receiving history —
alongside the existing manually-configured `default_lead_time_days`
fallback. Purely additive, read-only, admin-facing.

---

## 2. Why this milestone is next

This is not a speculative feature — it is the single most concretely
pre-committed item in the entire repository:

- **Architecturally pre-approved**, not a new decision: `CLAUDE.md` Decision
  D8 states "configured lead time is the present-day fallback, **observed
  lead-time statistics are a designed-for-later capability**." No new ADR or
  architecture reopening is required — this fills an already-reserved slot.
- **Named as a live product commitment**, not an internal engineering note:
  `docs/admin-guide-suppliers.md`'s "Not Yet Available" section — the
  closest thing this repo has to a live backlog — lists, verbatim: *"Lead-time
  statistics: Observed average/minimum/maximum delivery times (computed from
  actual receiving history) — the configured lead time is today's fallback,"*
  and separately, *"Observed lead-time statistics (average, fastest, slowest,
  completed orders) are a future feature (planned for a later milestone)."*
  This is the exact spec this plan implements.
- **Zero schema change required** — verified directly against
  `includes/class-wc-inventory-overview-install.php`: `wc_io_purchase_orders`
  already has `placed_at` and `supplier_id`; `wc_io_goods_receipts` already
  has `posted_at`; `wc_io_receipt_lines` already has the nullable `po_line_id`
  linking a receipt line back to its PO line. Every fact needed is already
  captured; nothing new needs to be written anywhere.
- **Architecturally the correct shape for this codebase**: a new read-only,
  derived, single/bulk-computed statistic — the exact same pattern already
  proven twice (Inventory Position's D12 sole-calculator; Expected Delivery's
  request-scoped, no-persistent-cache service). No new pattern is invented.
- **Feeds the platform's own stated next step without building it**:
  `docs/admin-guide-suppliers.md` notes lead time "will inform inventory
  coverage calculations" in future planning features (D11's Reserved /
  Available / Coverage / Forecast extension to Inventory Position). This
  milestone deliberately stops at *displaying* the statistic — it does not
  wire it into Position, PO-creation defaults, or expected-date confidence —
  but it is the necessary, low-risk first slice of that future direction,
  bounded to reporting only. It is a value-additive step even if Coverage/
  Forecast is never built next.

### Compared against the strongest alternative

**Supplier merge tool** — also explicitly named "planned" in the same
"Not Yet Available" section — was the closest competitor. Rejected as the
lead milestone because:

- It requires reassigning `supplier_id` across historical Purchase Orders
  and Goods Receipts, which sits in tension with this platform's strict
  auditability guarantee (INV-6: "posted/placed aggregates are never
  hard-deleted") and the denormalized `supplier_name_snapshot` /
  `sku_snapshot` convention that exists specifically so history survives
  supplier changes — a merge tool has to make a real, debatable design call
  about whether historical snapshots get rewritten or stay frozen, which
  lead-time statistics never has to touch (it only *reads*, never *writes*).
- Lower business value: it is data-hygiene tooling for "1–3 admin users,"
  not a purchasing-accuracy improvement. Nothing else in the roadmap builds
  on it.
- Lead-time statistics is a genuine prerequisite signal for the "supplier
  analytics" / "reliability scoring" item also named in the same backlog
  section — building it first is the natural sequencing; the merge tool has
  no such downstream dependency pointing at it.

Other candidates considered and rejected, briefly:

| Candidate | Why not next |
|---|---|
| Reserved/Available/Coverage/Forecast on Inventory Position (D11) | No concrete consumer or trigger yet; requires deciding what "reserved" means against WooCommerce orders — a real new schema/design question, not an additive slice. Too large and undercooked for one milestone. |
| Printable PO (D17 §11.2) | Reserved in architecture but **not** named anywhere in the live "Not Yet Available" backlog — zero operational demand on record, larger UI surface (document rendering) than lead-time stats. |
| ASN / barcode / scanning at receiving | Explicitly deferred "until a future milestone" (M5 note), but large scope (hardware workflow, ingestion pipeline) with no documented operator demand for a "1–3 admin users, single warehouse" shop. |
| `Plugin` god-class split | Explicitly excluded from scope by this task's own instructions ("do NOT split the Plugin god-class merely because it is documented debt") and by M8's own Context section, which already recorded it as a deliberate post-1.0 deferral. |
| PHPCS baseline closure (~559 errors/634 warnings) | Explicitly evaluated and rejected by M8's own WP6 as "a disproportionate, open-ended cleanup project" — this task's scope discipline forbids "absorbing open-ended cleanup." |
| Warehouse location/bin/shelf/aisle hierarchy | Confirmed (ADR-0001, `OWNERSHIP.md`) to be a **defensive ownership registration** against MPCF, not planned work — no schema, no milestone, no demand anywhere in the repo. |

---

## 3. Architecture Impact Statement

| Question | Answer |
|---|---|
| Public API changes | **No** — intentionally Internal; see §8 for the full rationale and the explicit future-promotion path. |
| Schema changes | **No** — fully computable from existing columns (`wc_io_purchase_orders.placed_at`/`supplier_id`, `wc_io_goods_receipts.posted_at`, `wc_io_receipt_lines.po_line_id`). |
| `DB_VERSION` bump | **No** — stays `10`. No `expected_schema_v11()` added. |
| New extension points | **No** — no new hooks/filters; nothing external consumes this yet (D16). |
| New settings | **No** — display-only; no configuration needed for v1. |
| User-visible behavior | **Yes** — new read-only "Observed lead time" panel on the Supplier admin screen. |
| Backward compatibility impact | **None** — purely additive; no existing behavior changes. |
| Existing frozen ownership boundary changed | **No** — stays entirely inside wc-inventory-overview's own Supplier/PO/Goods-Receipt domain (already owned per `OWNERSHIP.md`); no cross-plugin coupling. |

Every "Yes" is confined to one new admin-facing display. Nothing about the
frozen architecture (D1–D19, INV-1–INV-8) is reopened, extended, or
contradicted — this fills an already-reserved slot (D8) using an
already-proven pattern (D12-style derived, sole-calculator service).

**Scope-discipline verification (re-checked after each amendment pass,
including the second clarification pass — Source of Truth, average
rounding, date semantics, read-only/manual-editing statement, archived-
supplier behavior, and strengthened promotion wording):** none of these
amendments add a schema change, a `DB_VERSION` bump, a new setting, a
public API, a new extension point, an additional work package, new business
behavior, or expanded milestone scope. Every addition across both amendment
passes is **documentation of intent, boundary, and presentation detail
only** — §6.1 (Source of Truth), the date-semantics and archived-supplier
notes in §6, the rounding and read-only notes in §9, and §8's strengthened
promotion wording all describe how the *already-specified* WP1–WP6 behave
in more precise language; none of them introduces a new component, a new
table, a new setting, or a new work package. The rounding rule (§9) is a
display-layer clarification of WP3's existing "render the four statistics"
scope, not new functionality. The Architecture Impact Statement table above
is unchanged by either revision.

---

## 4. Milestone boundaries

**In scope:**
- Compute, per supplier, from posted (non-voided) Goods Receipts linked to
  that supplier's fully-`received` Purchase Orders: average days, fastest
  (min) days, slowest (max) days, and completed-order sample count.
- Display these four figures read-only on the Supplier detail/edit screen
  (Purchasing → Suppliers), next to the existing `default_lead_time_days`
  field.
- New internal service, unit + integration tests, one architecture guard
  test, one bulk-query performance test.
- Documentation updates (admin guide, architecture baseline, audit,
  `CLAUDE.md` status table, release runbook/checklist/rollback additions).

**Out of scope (explicit non-goals — see §5).**

---

## 5. Non-goals (explicit)

1. **Not wiring this into PO creation's expected-date suggestion.** The
   existing "suggest expected date from configured lead time" behavior
   (D9) is untouched. Auto-suggesting from *observed* lead time instead of
   the configured default is a separate, later decision (it would change
   existing purchasing-workflow behavior, not just add a report).
2. **Not building Inventory Position's Coverage/Forecast fields.** D11's
   future extension needs sales-velocity data (from WooCommerce orders) that
   doesn't exist in this plugin at all yet — a materially larger, separate
   initiative. This milestone stops at display.
3. **Not a public API.** No `API_VERSION`-carrying interface, no REST
   endpoint — no concrete external consumer exists (D16). See §8 for the
   full rationale and the explicit future promotion path, and §8.1 for the
   named future consumers this milestone deliberately does not build for.
4. **Not the supplier merge tool or supplier analytics/reliability
   scoring** — named in the same backlog section but deliberately left for
   their own future milestones (see §2 comparison).
5. **Not surfaced on the Suppliers *list* table in v1** — computed and shown
   only on the single-supplier detail/edit screen, to keep the first slice
   minimal; a list-table column (bulk display for every supplier at once) is
   a natural, low-risk follow-on but is left out here to keep this milestone
   small (list-table bulk display forces the bulk-query path to run on every
   page load of the list, which is a different, slightly larger performance
   surface than the detail screen's single-supplier query — worth its own
   pass once the single-supplier version is proven).

---

## 6. Domain / ownership model

New sole-owner boundary, matching the existing pattern (Inventory Position /
Expected Delivery): **`WC_Inventory_Overview_Supplier_Lead_Time_Service`** is
the only component ever allowed to compute observed lead-time statistics.
Per `docs/ARCHITECTURE_BASELINE_v1.24.0.md` §12 rule 2, this ships with its
own architecture guard test in the same PR (§13 below), not "added later."

**Precise computation, resolved now so the implementer doesn't have to
guess:**

- An **observation** exists for a Purchase Order only when:
  - The PO's `status = 'received'` (fully received — matches the admin
    guide's "completed orders" framing; a `closed_short` or still-open PO is
    not a completed delivery and is excluded).
  - At least one of its PO lines has at least one posted, non-voided
    Goods-Receipt line referencing it (`receipt_lines.po_line_id`).
  - `placed_at` is not null on the PO.
- **Observed delivery days** for that PO = `DATEDIFF(latest posted_at among
  all posted, non-voided receipt lines linked to any of this PO's lines,
  po.placed_at)`. Using the *latest* linked receipt matches the fact that a
  PO only reaches `received` once every line is fully received — the last
  contributing receipt is what actually completed the order.

  **Why the *first* receipt is deliberately not used** (worth stating
  explicitly, since it's the more intuitive-looking choice at a glance):
  D5 already establishes that suppliers frequently deliver a single PO in
  multiple partial shipments, each posted as its own Goods Receipt. The
  *first* receipt against a PO only marks the moment fulfillment *began* —
  it says nothing about when the supplier finished delivering everything
  that was ordered, and using it would systematically understate lead time
  for every supplier who ships in batches (i.e. skew the statistic
  optimistic, in exactly the cases where purchasing most needs an accurate
  number). What purchasing actually needs to know is "how long from placing
  the order until the order was fully in hand" — which is measured by the
  *last* qualifying receipt, the one whose posting is what transitions the
  PO's status to `received` (the four-state PO lifecycle documented in
  `docs/ARCHITECTURE_BASELINE_v1.24.0.md` §2 and M5's `PO_Receiving_Sync`
  auto-transition rule). This is purely an explanatory clarification of an
  already-correct decision; it requires no implementation change beyond
  what §11's WP1 already specifies.
- **Migrated (M6, `source = 'migrated'`) receipts are excluded** — they
  predate the PO/receiving flow and their lines never carry a `po_line_id`,
  so they are naturally excluded by the join condition. This is worth
  stating explicitly in the plan (and in code comments) so a future reader
  doesn't "fix" the join to include them — that would silently reintroduce
  pre-PO-era batch data with no real lead-time meaning into the statistic.
- **Per-supplier aggregate** = `AVG()`, `MIN()`, `MAX()`, `COUNT()` of the
  observed-delivery-days values across all qualifying POs for that
  `supplier_id`.
- Suppliers with zero qualifying observations return an explicit
  "not enough data yet" state (not zero, not null-as-if-computed) — the
  admin UI must never render `0 days` for a supplier with no completed
  history.

**Bulk consistency** follows the Inventory Position / Expected Delivery
precedent exactly: `get_stats_for_supplier( int $supplier_id )` is defined
as `get_stats_bulk( [$supplier_id] )` returning the single element, so
single and bulk can never disagree — the same discipline already
guard-tested for the two existing services.

**Date semantics (resolved now, so no ambiguity survives into
implementation):** `DATEDIFF(latest posted receipt, placed_at)` means a
**calendar-day difference** of MySQL `DATE`/`DATETIME` values, computed via
SQL `DATEDIFF()` (or the exact equivalent already used elsewhere in this
codebase for date-only comparisons) — **not** elapsed 24-hour periods, not a
`DateInterval`-style duration calculation. Timestamps are read and compared
exactly as stored by WordPress/MySQL (the same convention every other date
column in this schema already follows — `order_date`, `expected_date`,
`placed_at`, `posted_at` are all stored and compared without timezone
conversion elsewhere in the codebase, and this service introduces no new
convention). Explicitly **no timezone normalization or conversion** is
performed, and **no business-day calculation** (weekends/holidays) is
applied — this is a calendar-day count, full stop, matching how "Delayed"
(INV-5) and every other date-based computation in this platform already
works.

**Archived suppliers:** archiving a supplier (the existing archive/
reactivate lifecycle) does not remove or alter any historical Purchase
Order or Goods Receipt row — those are the canonical source of this
statistic (see §6.1) and are never touched by archiving. Consequently, an
archived supplier's observed lead-time statistics remain fully computable
and continue to display exactly as before archiving; the service applies
no active/archived filter of its own. This is expected, intentional
behavior — archiving hides a supplier from *active* lists and autocomplete
(per `docs/admin-guide-suppliers.md`), it does not erase purchasing
evidence. No implementation change beyond what §11's WP1 already specifies;
this is a documented behavioral consequence of a design already fixed by
§6.1, not new code.

### 6.1 Source of Truth

Observed supplier lead-time is a **derived statistic, never a stored
fact**. To keep this unambiguous for every future reader:

- The canonical source of truth is, and remains, the existing operational
  data: **Purchase Orders** (`wc_io_purchase_orders`), **Goods Receipts**
  (`wc_io_goods_receipts`), and **Receipt Lines** (`wc_io_receipt_lines`).
- `Supplier_Lead_Time_Service` owns only the **derivation** — the read-time
  computation defined above. It is not a second source of truth and does
  not compete with the tables it reads from.
- **No statistic is persisted.** No new column, option, or transient stores
  a computed average/min/max/count. No denormalized lead-time value is
  written anywhere, on the Supplier row or elsewhere.
- Every call recomputes from current operational history. This mirrors
  Inventory Position's own D11 framing exactly ("a first-class *derived*
  concept, not a table") and Expected Delivery's request-scoped-only
  memoization rule — the platform's established pattern for "answer must
  always reflect current reality," applied here for the same reason.

---

## 7. Database changes

None. No new table, column, or index. `DB_VERSION` stays `10`. The schema-
shape assertion continues to assert v10 unchanged (no `expected_schema_v11()`
added) — verified as part of the Release Readiness checks (§20).

---

## 8. Public API changes

None. `WC_Inventory_Overview_Supplier_Lead_Time_Service` is an Internal
class (direct PHP usage within `includes/`), not interface-typed, not
`API_VERSION`-carrying.

**Rationale — why Internal is the correct decision, not a placeholder:**
this is not "no API because no one asked yet." It is a deliberate
application of §10.3 of `docs/ARCHITECTURE_BASELINE_v1.24.0.md` and D16: a
class becomes a public API only when a concrete external consumer exists or
is being built in the *same* milestone. Every consumer of observed
lead-time statistics in M9 is internal to this plugin (the admin UI in the
same codebase, calling the service directly). Freezing an `API_VERSION`-
carrying interface now — before any real external or cross-service consumer
exists — would be exactly the kind of premature API surface D16 exists to
prevent: once a contract is versioned and public, `docs/architecture-audit.md`'s
own discipline (e.g. ADR-0003's sole-entry-point rule for
`Expected_Delivery_Service`) means it can never shrink and must be
maintained indefinitely, for consumers that don't exist yet.

Staying Internal is also **not a dead end**. **If a future milestone
introduces a genuine external consumer, `Supplier_Lead_Time_Service` may be
promoted to a versioned public API without changing the underlying
computation service.** Promotion (interface + own `API_VERSION`, following
the exact precedent `Expected_Delivery_Service` already set) wraps or
formalizes the existing service's contract — it does not rewrite, replace,
or duplicate the computation logic defined in §6/§6.1. **The service
remains the sole owner of observed lead-time computation regardless of
promotion; a future milestone extends *access* to that owner (a public,
versioned entry point) rather than duplicating the calculation in a second,
independently-derived component.** This is a well-precedented, low-risk
move in this codebase (Internal → Public is additive; nothing about
internal-only usage today prevents it). **That promotion is explicitly
outside M9** — no interface, no `API_VERSION`, no versioning scaffolding is
added speculatively in this milestone.

### 8.1 Future Consumers (Not Part of M9)

Observed supplier lead-time is being introduced now as **foundational,
derived data** — a durable fact about the business, computed once, correctly,
and exposed for display. It is plausible that future milestones will want to
*consume* this fact rather than re-derive it. Named candidates, none of
which are scoped, designed, or committed to by this plan:

- **Expected Delivery confidence improvements** — using observed lead time
  to refine `Exact`/`Estimated`/`Unknown` confidence resolution (D9) for POs
  without a supplier-specified date.
- **Purchase Order expected-date suggestions** — replacing or augmenting the
  configured-lead-time suggestion described in `docs/admin-guide-suppliers.md`'s
  "Lead Time — Configured, Not Observed" section with the observed figure
  when enough history exists.
- **Supplier reliability scoring** — part of the "supplier analytics" item
  already named (but deferred) in `docs/admin-guide-suppliers.md`'s "Not Yet
  Available" section.
- **Inventory Coverage** — D11's designed-for-later extension to Inventory
  Position (`{On Hand, Incoming, Position}` gaining a `Coverage` field).
- **Forecasting** — D11's `Forecast` field, which would need this data plus
  sales-velocity data this plugin does not yet have.
- **Purchasing recommendations** — any future "reorder suggestion" feature
  combining lead time with stock position.

**None of these integrations are part of M9.** Each would be its own
future milestone, individually scoped and justified against repository
evidence at the time, exactly as this milestone was. M9 is strictly:
**compute, display, validate.** It does not read from, write to, or alter
the behavior of any other service.

### 8.2 Future Work — Additional Statistics (Not Part of M9)

**Median lead time is deliberately excluded from M9.** A median is less
sensitive to a single unusually delayed delivery than a mean, and may
eventually be a useful fifth statistic once this feature has real usage —
but adding it now would be speculative statistical scope with no named
consumer or demand, the same discipline this plan applies everywhere else
(§2's rejection of oversized candidates, D16's "no speculative" principle).

**M9's statistic set is final for this milestone and is exactly the four
named in `docs/admin-guide-suppliers.md`'s own backlog entry — average,
fastest (min), slowest (max), and completed-order count. Nothing more.**
If usage later shows the mean is too sensitive to outliers, adding a median
is a small, additive, low-risk follow-up to `get_stats_bulk()`'s return
shape — not a reason to add it speculatively here.

---

## 9. Admin/UI impact

- **File:** `includes/class-wc-inventory-overview-suppliers-list-table.php`
  and/or the supplier edit form rendering inside
  `includes/class-wc-inventory-overview-purchasing-page.php` (exact
  insertion point to be confirmed against current form markup at
  implementation time — the design intent is fixed: a new read-only
  "Observed lead time" block on the single-supplier edit/detail view,
  directly beneath the existing `default_lead_time_days` field, so the
  configured fallback and the observed reality sit side by side).
- Displays: average days, fastest (min) days, slowest (max) days, sample
  count ("Based on N completed orders"). Below a minimum sample threshold
  (recommend: fewer than 2 completed orders), render the "not enough data
  yet" state instead of statistics that would be misleadingly precise from
  a single data point.
- **Average rounding (resolved now):** min/max/count are already whole
  numbers by construction, so only the average needs a presentation rule.
  Internal computation (`AVG()`) may retain fractional precision — nothing
  is rounded before or during storage, because nothing is stored (§6.1).
  The merchant-facing UI displays the average **rounded to the nearest
  whole calendar day** (e.g., "12 days," never "11.7 days") — this is a
  display-layer rounding only, applied at render time, and is scoped
  strictly to this milestone's admin UI. If `Supplier_Lead_Time_Service` is
  ever promoted to a public API (§8), that future API is free to expose the
  raw, unrounded value if a future consumer needs finer precision than the
  merchant UI does — this milestone's rounding choice constrains only the
  admin display built in WP3, not the service's internal return value.
- **Read-only, not editable.** Observed lead-time statistics are computed
  exclusively from operational purchasing history (Purchase Orders, Goods
  Receipts, Receipt Lines — §6.1) and cannot be edited, overridden, or
  manually entered anywhere in the admin UI. The existing
  `default_lead_time_days` field remains the merchant-editable *planning*
  value (today's configured fallback, D8); the new observed figures are
  read-only *historical evidence* sitting alongside it. The two are
  displayed together (§9 above) precisely so a merchant can compare
  "what I planned for" against "what actually happened," but nothing in
  this milestone lets one value influence or overwrite the other.
- No new capability required — gated by the same `manage_woocommerce`
  capability already guarding the Suppliers screen.
- No new WooCommerce admin menu entry; this extends the existing Purchasing
  → Suppliers screen.
- Archived suppliers: see §6's "Archived suppliers" note — the same
  read-only stats block renders unchanged whether the supplier is active or
  archived, since the underlying historical evidence is unaffected by
  archiving.

---

## 10. Storefront impact

None. This is an admin-only reporting feature; it never reaches
`Expected_Delivery_Service`, the storefront renderer, or any customer-facing
surface. `Expected_Delivery_Resolver`'s confidence logic (Exact/Estimated/
Unknown) is untouched — see Non-goal §5.1.

---

## 11. Implementation work packages

**WP1 — `Supplier_Lead_Time_Service` (core computation)**
- New class `WC_Inventory_Overview_Supplier_Lead_Time_Service` with
  `get_stats_for_supplier( int $supplier_id ): array` and
  `get_stats_bulk( array $supplier_ids ): array`, single query (no N+1),
  computing the observation set defined in §6.
- Pure, side-effect-free; request-scoped memoization only if profiling shows
  it's warranted (no persistent caching — consistent with the platform-wide
  prohibition already in force for Position/Expected-Delivery).

**WP2 — Architecture guard test**
- `tests/unit/supplier-lead-time/test-supplier-lead-time-architecture.php`:
  asserts `Supplier_Lead_Time_Service` is the only file in `includes/`
  computing observed lead-time (source-scan for the aggregate SQL
  pattern/method names), and that it performs zero writes
  (`set_stock_quantity`/`->insert(`/`->update(`/`->delete(` absent) — it is
  read-only by construction and the guard proves it stays that way.

**WP3 — Admin UI**
- Render the new stats block on the supplier edit/detail screen (§9).
- Handle the "not enough data yet" state explicitly.

**WP4 — Tests**
- `tests/unit/supplier-lead-time/` — pure calculation tests: correct
  avg/min/max/count; exclusion of `closed_short`/still-open POs; exclusion
  of voided receipt lines; exclusion of migrated (`source = 'migrated'`)
  receipts; exclusion of direct receipts with no `po_line_id`; correct
  "latest linked receipt" selection when a PO's lines are fulfilled by
  multiple receipts at different times; zero-observation "not enough data"
  state.
- **Insertion-order independence test:** prove that the computed lead time
  depends only on `posted_at` values, never on row-insertion order or
  auto-increment ID order. Construct a fixture where a PO's qualifying
  receipts are inserted (and therefore receive their primary-key IDs) in
  an order that is the *reverse* of their `posted_at` order — e.g. the
  receipt with the latest `posted_at` is inserted first and gets the
  lowest ID, while an earlier-`posted_at` receipt is inserted later and
  gets the highest ID — and assert the service still selects the row with
  `MAX(posted_at)`, not `MAX(id)` or "last inserted." This guards
  specifically against an implementation shortcut (`ORDER BY id DESC
  LIMIT 1`) that would happen to pass every other WP4 fixture (where
  insertion order and `posted_at` order naturally coincide) while
  silently violating the "latest posted receipt" rule §6 defines.
- `tests/integration/supplier-lead-time/` — end-to-end against real fixture
  data (PO → partial receipts → final receipt reaching `received`); bulk
  vs. single consistency test (same discipline as M7's equivalent test);
  the query-count equality performance test (WP5).

**WP5 — Performance test**
- One bulk query regardless of supplier count — extend the same
  equality-based query-scaling technique already proven in
  `tests/integration/inventory-position/*performance*` and
  `tests/integration/expected-delivery/test-expected-delivery-performance.php`
  (e.g., assert identical query count for 10 vs. 40 suppliers).

**WP6 — Documentation**
- `docs/admin-guide-suppliers.md`: move "Lead-time statistics" from "Not Yet
  Available" to "What Is Available Now"; document the exact computation
  rule and the "not enough data" threshold in merchant-facing language.
- `docs/ARCHITECTURE_BASELINE_v1.24.0.md`: in-place update (this milestone
  changes no frozen boundary, matching the M8 plan's own precedent for why
  it chose an in-place update over a new `_v1.26.0.md` file) — add M9 to the
  completed-milestones summary once shipped, note the new sole-owner
  boundary and its guard test.
- `docs/architecture-audit.md`: new `## Milestone M9 — Supplier Observed
  Lead-Time Statistics` section, same shape as every prior milestone
  section (Status/Scope/component-by-component/Architecture
  guards/Testing).
- `CLAUDE.md`: update the Implementation Status table, add the M9 row.
- `docs/release-runbook.md`, `docs/checklists/validation-checklist.md`,
  `docs/rollback-plan.md`: add M9 subsections following the exact
  established per-milestone template (§16–§20 below give the content).
- `docs/GITHUB_RELEASE_NOTES_1.26.0.md`, `CHANGELOG.md`, `readme.txt`:
  standard release-note pass per `docs/release-runbook.md`.

---

## 12. Implementation sequence

WP1 (service) → WP2 (guard test, alongside WP1 per the "no guard test later"
rule) → WP4 unit tests (validate WP1's calculation rules) → WP3 (admin UI,
consuming a proven service) → WP4 integration + WP5 performance tests →
WP6 (documentation, closing pass, mirrors M8's WP8 sequencing precedent).
One logical commit per work package, matching the discipline used in
M1–M8.

---

## 13. Migration strategy

None required. No schema change, no data backfill — the statistic is
computed at read time from data that already exists for every historical PO
and receipt since M2/M4/M5 shipped.

---

## 14. Testing strategy

Follows the established per-milestone pattern exactly (`tests/unit/<area>/`
+ `tests/integration/<area>/` pairing, per `docs/testing.md`'s own stated
convention for new milestones): new top-level `supplier-lead-time` test
directories, one architecture-guard file, unit tests for the pure
calculation algorithm (table-driven, matching the Expected-Delivery
Resolver's own test style), integration tests against real fixture PO/
receipt data, and the query-count-equality performance test. New test class
prefix (`Test_WC_IO_Supplier_Lead_Time_`) added to
`tests/docker/run-phpunit.sh`'s blocking filter regex, so this ships
CI-blocking from day one — no "temporary non-blocking exception" precedent
should be created here.

---

## 15. Performance criteria

- Exactly one SQL query (a single grouped aggregate join across
  `wc_io_purchase_orders` / `wc_io_receipt_lines` / `wc_io_goods_receipts`)
  regardless of how many suppliers are being queried in bulk — proven by
  the equality-based test in WP5, mirroring Invariant M7-3's technique.
- No N+1 on the supplier edit screen (single-supplier call still goes
  through the same one-query bulk path with a one-element array).

---

## 16. Architecture guards

- New guard test (WP2) enforcing sole ownership of the observed-lead-time
  computation, per `docs/ARCHITECTURE_BASELINE_v1.24.0.md` §12 rule 2.
- The repo-wide "no sibling-plugin coupling" guard M8's WP3 added
  automatically covers this milestone's new file (no separate action
  needed here — the new service contains no third-party
  `class_exists()`/`function_exists()` checks by design).
- No mutation guard needed beyond WP2's zero-write assertion — this service
  never touches WooCommerce stock, PO state, or Goods Receipt state, so
  INV-2 (single stock mutator) and D12 (Inventory Position sole calculator)
  are unaffected by construction, not by a new carve-out.

---

## 17. Security/capability implications

None beyond the existing `manage_woocommerce` gate already protecting the
Suppliers screen. No new capability, no new nonce/AJAX surface (server-side
rendered alongside the existing supplier edit form, not a separate AJAX
endpoint), no new user input accepted (read-only, no parameters beyond the
supplier ID already used to load the edit screen).

---

## 18. Documentation deliverables

See WP6 (§11) — full list: `docs/admin-guide-suppliers.md`,
`docs/ARCHITECTURE_BASELINE_v1.24.0.md`, `docs/architecture-audit.md`,
`CLAUDE.md`, `docs/release-runbook.md`, `docs/checklists/validation-checklist.md`,
`docs/rollback-plan.md`, `docs/GITHUB_RELEASE_NOTES_1.26.0.md`,
`CHANGELOG.md`, `readme.txt`.

---

## 19. Deployment validation

Follow `docs/checklists/validation-checklist.md`'s established per-milestone
template. New `### For M9 (Supplier Observed Lead-Time Statistics, v1.26.0)`
subsection:
1. Schema unchanged: `wp option get wc_io_db_version` still `10`;
   `wc_io_schema_assertion --format=json` still `ok: true`.
2. Feature walkthrough: open Purchasing → Suppliers → edit a supplier with
   ≥2 completed (fully `received`) POs; confirm the observed-lead-time block
   shows correct avg/min/max/count matching a manual SQL spot-check. Open a
   supplier with 0–1 completed POs; confirm the "not enough data yet" state
   renders, never `0 days`.
3. Mandatory correctness check: a `closed_short` PO and a still-`placed`
   (open) PO for the same supplier must not affect that supplier's
   statistics; a voided receipt's contribution must not count.
4. Standard closing bullet: Suppliers, Purchase Orders, Inventory Position,
   Goods Receipts, PO Receiving, Storefront Expected Delivery, and M8's
   hardening changes all continue to function exactly as before — no
   regression.

---

## 20. Rollback strategy

Code-only, no data or schema written — same clean profile as M6/M7/M8.
Revert the release commit(s); no compensating data migration, no schema
downgrade, nothing to undo on `wc_io_purchase_orders`, `wc_io_receipt_lines`,
or `wc_io_goods_receipts` since this milestone never writes to them.

---

## 21. Risk assessment

| Risk | Mitigation |
|---|---|
| "Latest linked receipt" join logic double-counts or misattributes a PO spanning a consolidated multi-PO receipt, or an implementation accidentally selects by `MAX(id)`/insertion order instead of `MAX(posted_at)` | Table-driven unit tests (WP4) explicitly covering multi-receipt, multi-PO-per-receipt, and partial-then-final-receipt scenarios, plus the dedicated insertion-order-independence test (WP4) that inserts receipts in the reverse of their `posted_at` order to prove ID/insertion order can never substitute for `posted_at` — all before the admin UI (WP3) ships |
| Misleading precision from a low sample count | Explicit "not enough data yet" threshold (§6, §9), never rendering statistics computed from 0–1 observations as if they were reliable |
| Accidentally including pre-PO-era migrated (M6) batch data | Naturally excluded by the `po_line_id IS NOT NULL` join condition; called out explicitly in code comments and this plan so a future edit doesn't "fix" it into inclusion |
| Bulk query becomes an N+1 or a slow join at scale | WP5's equality-based performance test, same technique already proven for Inventory Position and Expected Delivery |
| Documentation drift (a fact recorded in the baseline doc goes stale) | WP6 closing pass mirrors M8's own WP8 discipline — every doc the baseline's own §12 consistency rule requires gets updated in the same milestone |

**Overall risk profile: low.** No schema change, no public API change, no
write path, no change to any existing user-facing behavior — the only
"Yes" in the Architecture Impact Statement is a new read-only admin display.

---

## 22. Definition of Done

- [ ] WP1: `Supplier_Lead_Time_Service` implemented; single/bulk consistency
      holds (bulk of one element equals single-call result).
- [ ] WP2: Architecture guard test passes — sole ownership of the
      computation, zero writes.
- [ ] WP3: Admin UI displays the four statistics (or "not enough data yet")
      on the supplier edit screen.
- [ ] WP4: Full unit + integration coverage for every exclusion rule in §6.
- [ ] WP5: Query-count-equality performance test passes.
- [ ] WP6: All listed documentation updated; `Test_WC_IO_Supplier_Lead_Time_`
      registered in the CI-blocking filter from the start.
- [ ] `DB_VERSION` confirmed unchanged at `10`; no new public API surface;
      no new domain concept beyond the already-reserved D8 slot.
- [ ] Full CI green (unit + milestone-focused blocking suite + integration,
      integration blocking per M8's WP5 precedent).
- [ ] Tagged `v1.26.0`, GitHub Release published, deployed and validated on
      dev.biopentra.eu per §19.

---

## Process note

This plan was approved through an interactive planning session (three
amendment rounds: strengthened Internal-API rationale and future-promotion
wording, Source of Truth / date-semantics / rounding / archived-supplier
clarifications, and the insertion-order-independence test addition to WP4).
Per process: this document was committed by itself, as a standalone
documentation commit, before any WP1–WP8 implementation work began.
