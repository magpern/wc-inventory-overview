# Milestone M10 — Purchase Order Expected-Date Suggestion (Configured + Observed Lead Time)

## Materialization note

This is the definitive, approved M10 implementation plan, materialized
verbatim from the plan-mode draft per `docs/process/milestone-lifecycle.md`
WP0 step 5, with the following corrections/elaborations applied from the
final approved implementation task before materialization (none change
scope, schema, APIs, settings, or work packages — all are naming
precision or test-scenario elaboration):

- **Branch name corrected** throughout to `feature/m10-po-expected-date-suggestion`
  (the draft used `feature/m10-expected-date-suggestion`; the approved
  implementation task specifies the `-po-` form, which is what this
  milestone's branch was actually created as).
- **§10 Testing strategy elaborated** with the exact additional scenarios
  named in the approved implementation task: invalid supplier input,
  archived-supplier behavior, explicit stale-suggestion-clearing scenario
  (selecting a supplier with a suggestion, then one without, must clear
  the still-untouched fields), and a preference for asserting an explicit
  expected query count (not just cross-scale equality) in the performance
  test where practical.
- **Return shape note**: the service's internal return array may include
  `source` (`observed`/`configured`/`none`) for internal
  testing/debugging/presentation-wiring use, but this is never exposed as
  a documented or public-facing field/API — the class remains Internal
  (D16), consistent with the rest of this document.

From this point forward, this file is immutable per
`docs/process/milestone-lifecycle.md` Rule 1.

---

## 0. Pre-flight (verified, read-only)

- Repository path: `/opt/biopentra/dev/wc-inventory-overview` — confirmed.
- Working tree: clean.
- Branch this plan was written from: `feature/m9-supplier-observed-lead-time`,
  13 commits ahead of `main` (`5a62111`, matches `origin/main`). No `M10`
  branch existed at planning time.
- Plugin version `1.26.0` (header + `WC_INVENTORY_OVERVIEW_VERSION`
  constant, consistent). `DB_VERSION` `10`.
- Required docs present: `docs/ARCHITECTURE_BASELINE_v1.24.0.md`,
  `docs/milestones/m9-implementation-plan.md`,
  `docs/checklists/m9-release-readiness.md`,
  `docs/process/milestone-lifecycle.md`.
- `docs/ARCHITECTURE_BASELINE_v1.24.0.md` is treated as the canonical
  architecture baseline. `docs/milestones/*` (including this plan) are
  treated as immutable historical specs once approved and used for
  implementation — per `docs/process/milestone-lifecycle.md` Rule 1.
  Nothing under `docs/milestones/` was modified by this plan itself.
- **Branch strategy:** `docs/ARCHITECTURE_BASELINE_v1.24.0.md` §3 states
  M10 onward is unplanned, and `docs/process/milestone-lifecycle.md`
  introduces the "feature train" model (several milestones accumulate
  before one batched release) but doesn't specify branch topology. This
  plan adopts: **M10 gets its own branch, `feature/m10-po-expected-date-suggestion`,
  branched from `feature/m9-supplier-observed-lead-time`** (not from
  `main`) — preserving the existing one-branch-per-milestone naming
  convention while reflecting that M10 depends on M9's
  `Supplier_Lead_Time_Service`, which isn't on `main` yet. At WP6
  (feature-train release), the accumulated branches merge together in one
  batch.

## 1. Discovery — what M10 could be (per `docs/process/milestone-lifecycle.md` WP0)

Two read-only research passes canvassed the whole repository (docs + code)
for deferred/backlog work.

**Governance fact:** no document pre-names M10 the way CLAUDE.md's D8
pre-named M9. `docs/ARCHITECTURE_BASELINE_v1.24.0.md` §3: *"The next
milestone (M10 onward) is unplanned."* This is a genuinely open choice.

16 deferred items were catalogued. Of these, three had their calculus
**materially changed by M9 shipping** (a new dependency now satisfied):

| Candidate | Depends on M9? | Surface | Verdict |
|---|---|---|---|
| **PO expected-date suggestion using lead time** | Yes — consumes lead-time statistics | Admin-only (PO creation) | **Selected** |
| Supplier reliability scoring / analytics | Yes — consumes the same statistics | Admin-only | Rejected for M10 (see §2) |
| Expected Delivery confidence improvement from observed lead time | Yes — consumes the same statistics | **Customer-facing storefront** | Rejected for M10 (see §2) |

Everything else surveyed (Inventory Position Coverage/Forecast, Reservations,
Inbound Shipment entity, Printable PO, ASN/barcode/scanning, `Plugin`
god-class split, PHPCS baseline closure, warehouse location hierarchy,
REST/Store API for Expected Delivery, structured data, PO-number allocation
atomicity, supplier merge tool) is **unaffected by M9** — same rejection
reasoning already on record from M8/M9's own evaluations, no new unblocking
dependency. None are reconsidered here; see M9's own plan §2 comparison
table for the original reasoning, which still holds.

**A significant correction surfaced during discovery**, load-bearing for
this plan: `docs/admin-guide-suppliers.md`'s "Configured Lead Time" row
(and CLAUDE.md's D8) both currently claim *"When creating a new purchase
order for this supplier, the system suggests this lead time to estimate
the expected receipt date."* **This is false today** — verified directly:
`includes/class-wc-inventory-overview-po-admin.php:352` renders the
Expected Date field as a plain, always-empty-for-new-PO `<input
type="date">` with zero auto-suggestion logic anywhere in the codebase
(confirmed by grep across `includes/` for any `default_lead_time_days` +
PO-creation co-occurrence — none exists). This has apparently been true
since the field was documented and was carried forward, unverified,
through M9's own WP6 documentation pass. **M10, as scoped below, makes
this documentation claim true for the first time** rather than continuing
to carry a false claim — itself a meaningful, if secondary, justification.

## 2. Why this milestone, compared against the two runner-ups

**Selected: PO expected-date suggestion, sourced from observed lead time
when available, falling back to configured lead time, falling back to no
suggestion.**

- **Smallest, most surgical, most precedented of the three unblocked
  candidates.** M9's own plan named this exact follow-on as its Non-goal
  #1: *"Auto-suggesting from observed lead time instead of the configured
  default is a separate, later decision (it would change existing
  purchasing-workflow behavior, not just add a report)."* M10 is that
  later decision, taken deliberately and narrowly.
- **Admin-only surface.** Unlike the Expected Delivery confidence
  candidate, this never touches the customer-facing storefront renderer or
  `Expected_Delivery_Resolver` — it only affects what pre-fills a form
  field on the internal Purchase Order creation screen, which the merchant
  can freely overwrite before submitting.
- **Closes a real, currently-false documentation claim** (§1) rather than
  building on top of one.
- **Fully reuses existing architecture — no new pattern invented:**
  M9's lead-time statistics (already sole-owner, already guard-tested,
  already proven zero-N+1 at 200-supplier scale), `default_lead_time_days`
  (already on the Supplier entity), `expected_date`/`expected_confidence`
  (already columns on `wc_io_purchase_orders`, already governed by D9's
  Exact/Estimated/Unknown vocabulary), and the existing
  `wp_localize_script('wc-io-po-admin', 'wcIoPoAdmin', [...])` /
  `assets/po-admin.js` plumbing already used on this exact page for
  another progressive-enhancement behavior (product search). No new AJAX
  endpoint, no new REST route, no new schema, no new persistent field.

**Rejected runner-up 1 — Supplier reliability scoring / analytics.** Still
named in `docs/admin-guide-suppliers.md`'s "Not Yet Available" list, and a
real future consumer of M9's data — but it's a compound backlog bullet
("spend analysis, reliability scoring, and order history reporting" as one
line) that needs its own scoping decision, and "reliability" specifically
requires defining a new concept beyond what M9 computed — a real new
design question, not a pure extension of already-decided architecture.
Left for its own future milestone.

**Rejected runner-up 2 — Expected Delivery confidence improvement from
observed lead time.** Same new dependency as the selected candidate, but
modifies the customer-facing storefront confidence resolution — a
materially higher-stakes surface than an admin-only form default. Deferred
as a larger, riskier second step once the admin-facing version (this
milestone) has real usage to validate data quality against.

## 3. Architecture Impact Statement

| Question | Answer |
|---|---|
| Public API changes | **No** — new service is Internal (D16: no concrete external consumer). |
| Schema changes | **No** — no new table/column. Suggestion is computed at render time and only ever written through the existing `expected_date`/`expected_confidence` form submission path, exactly as a merchant-typed value is today. |
| `DB_VERSION` bump | **No** — stays `10`. |
| New extension points | **No** — no new hooks/filters. |
| New settings | **No** — suggestion behavior is automatic and always-on, matching how the (currently-false) documentation already describes it as automatic. |
| User-visible behavior | **Yes** — selecting a supplier on the PO creation screen pre-fills Expected Date and sets Confidence to "estimated," if a suggestion is available; both remain fully editable. |
| Backward compatibility | **None broken** — a supplier with neither observed nor configured lead time behaves exactly as today. Editing an *existing* PO is unaffected. |
| Existing frozen ownership boundary changed | **No architectural boundary is altered.** `Supplier_Lead_Time_Service` remains M9's sole owner of lead-time *computation* — this milestone adds one small, additive, backward-compatible predicate method to it (see §5.1) that M9's own guard test already anticipated needing extension for ("a future consumer must extend this allowlist deliberately, not silently"). No existing method's behavior changes. `PO_Service`, `Expected_Delivery_Resolver` are untouched entirely. |

**Release trigger check (`docs/process/milestone-lifecycle.md`):** none of
schema change / migration / public API change / ownership-boundary change
/ storefront behavior change / security fix / breaking change apply. **M10
joins the current feature train** (with M9) — no release, tag, or deploy
at the end of this milestone. Per the feature-train process, no GitHub
Release artifact is produced at milestone completion; release notes will
be generated once when the feature train itself is released (WP6),
covering everything accumulated in it at that time. This is a deliberate
process decision, not a missing artifact.

## 4. Milestone boundaries

**In scope:**
- A new, domain-generic suggestion service (§5) combining observed and
  configured lead-time signals into a suggested day-count + confidence,
  with **Purchase Order creation as its first and only consumer this
  milestone**.
- Apply this suggestion as a client-side pre-fill on the **new PO
  creation** form's Expected Date + Confidence fields when a supplier is
  selected, without an AJAX round-trip (all suppliers' suggestions
  computed server-side in one bulk pass, embedded via the existing
  `wp_localize_script` mechanism already used on this exact page).
- The suggestion is always overridable, and — once overridden — never
  reasserted for the rest of that page session (§6).
- Update `docs/admin-guide-suppliers.md` so its "suggests this lead time"
  claim becomes true.

**Out of scope (non-goals):**
1. Editing an **existing** PO — the suggestion only ever pre-fills a
   blank field on a brand-new, not-yet-submitted PO.
2. Per-line expected-date override suggestions — stays exactly as today
   (blank, manual). Keeping the first slice to the PO-header level only,
   matching M9's own "keep the first slice minimal" precedent.
3. Any change to `Expected_Delivery_Resolver`, the storefront renderer, or
   confidence *display* logic.
4. Any change to `Supplier_Lead_Time_Service`'s existing computation —
   consumed as-is, plus one small additive predicate method (§5.1); no
   existing method is modified.
5. Persisting the suggestion, or which source it came from, anywhere — a
   transient render-time value; once submitted, the stored
   `expected_date`/`expected_confidence` are indistinguishable from a
   manually-typed value.
6. Any other consumer of the new suggestion service besides PO creation
   (Quick Restock, Goods Receipt planning, a supplier dashboard, a reorder
   assistant — all plausible future consumers *of the generic service*,
   named explicitly to justify the generic design in §5, but none are
   built or wired in this milestone).
7. Business-day arithmetic (§5.2).
8. Supplier reliability scoring, spend analysis, order-history reporting,
   Expected Delivery confidence changes — all explicitly deferred (§2).
9. New public API, REST/Store API/GraphQL endpoint, new capability, new
   AJAX endpoint, sibling-plugin dependency, automatic reorder behavior,
   forecasting, purchasing recommendations — none of these are part of
   this milestone under any circumstance.

## 5. Domain / ownership model

**Two distinct responsibilities, deliberately kept as two separate
classes so a future contributor is never tempted to merge them:**

- **`WC_Inventory_Overview_Supplier_Lead_Time_Service` (M9) owns
  *observed statistics* — what actually happened, historically, per
  supplier.**
- **`WC_Inventory_Overview_Expected_Date_Suggestion_Service` (new, this
  milestone) owns *observed/configured fallback (recommendation) policy*
  — given the statistics (and the configured fallback), what should we
  suggest right now?**
- **`WC_Inventory_Overview_PO_Admin` owns *presentation/wiring only*** —
  it calls the suggestion service and renders/localizes its output; it
  never computes a lead-time value itself.

This split matters because the two questions have different owners in
principle: M9's statistics are a fact about the past (immutable in
retrospect once a PO is fully received); a suggestion is a policy decision
about how to turn available signals into a single recommended number, and
that policy could plausibly change (e.g. a future milestone might weight
recent orders more heavily, or blend multiple suppliers for a
multi-sourced product) without the underlying statistics changing at all.
Collapsing them into one class would make that future evolution harder to
reason about and would violate the same "one sole owner per computed
concept" discipline M9 itself relied on (Inventory Position vs. Expected
Delivery are likewise kept as separate services despite both reading
overlapping PO data). Neither computation may be duplicated in the other
component.

**Why one class, not a Resolver+Service split** (M7's
`Expected_Delivery_Result_Interface`/`Result`/`Resolver`/`Service`/`Renderer`
shape): M7's heavier shape is justified by being a versioned public API
with a customer-facing renderer — neither applies here. This milestone's
service is proportioned like M9's own `Supplier_Lead_Time_Service`: a
single class, static methods, Internal, no interface. If a future
milestone promotes this to a public API (mirroring M9's own documented
promotion path), splitting it at that point — not speculatively now — is
the right time, per D16.

**New sole-owner boundary:**
`WC_Inventory_Overview_Expected_Date_Suggestion_Service`
(`includes/class-wc-inventory-overview-expected-date-suggestion-service.php`)
is the only component allowed to combine observed and configured
lead-time signals into a suggestion. Ships with its own architecture guard
test in the same PR (`docs/ARCHITECTURE_BASELINE_v1.24.0.md` §12 rule 2).
Named generically, deliberately not `PO_...`, because the underlying
algorithm (observed → configured → none) is not PO-specific — Purchase
Order creation is this milestone's **first consumer**, not an intrinsic
part of the service's identity. Non-goal #6 above names plausible future
consumers precisely to justify this generic naming now rather than
needing a rename-and-migrate later.

### 5.1 A small, additive extension to M9's service (not a redesign)

The suggestion service must decide whether an observed average is
"good enough" to use. **That decision belongs to M9's service, not to
this one** — this milestone's service should never independently know or
duplicate *why* a given sample count is or isn't sufficient (today `2`,
`Supplier_Lead_Time_Service::MINIMUM_SAMPLE_COUNT_FOR_DISPLAY`); that
threshold is M9's own presentation-layer judgment call, and this
milestone's service should treat it as a black box. **No second threshold
constant may be introduced in M10; the resolution logic must not duplicate
the threshold comparison anywhere else.**

Concretely: `WC_Inventory_Overview_Supplier_Lead_Time_Service` gains one
new **pure, stateless, additive** public method:

```php
public static function is_observed_value_usable( array $stats ): bool {
    return $stats['has_data'] && $stats['sample_count'] >= self::MINIMUM_SAMPLE_COUNT_FOR_DISPLAY;
}
```

- Takes the already-fetched `$stats` shape `get_stats_bulk()` already
  returns — issues no new query.
- No existing method's signature, behavior, or return shape changes.
- M9's architecture guard test
  (`tests/unit/supplier-lead-time/test-supplier-lead-time-architecture.php`)
  needs one small, deliberate update: its sole-caller allowlist
  (`approved_callers()`) gains
  `class-wc-inventory-overview-expected-date-suggestion-service.php` as a
  second approved caller. The guard test's own docstring already
  anticipated exactly this: *"A future consumer... must extend this
  allowlist deliberately, not silently"* — this is that deliberate
  extension, not a silent one, and not a modification to M9's frozen
  *plan document* (`docs/milestones/m9-implementation-plan.md` is untouched)
  — only to M9's living *code*, which was always expected to evolve.
- This is the only change this milestone makes to any M9-authored
  production file. This is a narrow extension of an existing Internal
  service, not a public API change.

### 5.2 Date semantics (documented explicitly, per the same discipline M9 used)

**Calendar days, not business days.** A suggested date is `order_date (or
today) + N calendar days`, matching M9's own `DATEDIFF()`-based observed
statistic (already calendar-day, no timezone normalization, no
business-day logic) and the existing `default_lead_time_days` field (also
already a plain calendar-day count, used nowhere with business-day
semantics). Introducing business-day arithmetic here — while the
underlying statistic it's built from is calendar-day — would create an
internal inconsistency (a "12-day average" that then suggests a date 12
*business* days out is not the same 12 days the Supplier panel just
showed). No elapsed-hour or holiday-aware calculation is used either.
Business-day suggestion, if ever wanted, is a distinct, separate future
decision, not part of this milestone.

**Precise resolution rule:**

```
for each supplier:
  stats = Supplier_Lead_Time_Service::get_stats_bulk([...])[supplier_id]
  if Supplier_Lead_Time_Service::is_observed_value_usable(stats):
      days = (int) round(stats.average_days)
      source = 'observed'
  elseif supplier.default_lead_time_days is set and > 0:
      days = (int) supplier.default_lead_time_days
      source = 'configured'
  else:
      days = null
      source = 'none'
  confidence = (source in ['observed','configured']) ? PO_Confidence::ESTIMATED : null   # never EXACT — always a system guess, never supplier-confirmed
```

- Reuses M9's exact display-rounding convention (`(int) round(...)`) —
  the Supplier admin panel and this suggestion must never show mismatched
  numbers from two different rounding rules.
- `default_lead_time_days is set and > 0` mirrors the exact existing
  guard already used at
  `includes/class-wc-inventory-overview-purchasing-page.php` for
  displaying the Configured Lead Time value — reused, not reinvented.
- Confidence is always `estimated` when a suggestion exists, `unknown`
  otherwise (the field's existing default) — no new confidence level is
  introduced; D9's existing three-value vocabulary is reused unchanged.
  If no suggestion exists, the form's existing unknown/default behavior
  is left entirely intact.

**Bulk consistency**, same discipline as every prior derived-value service
in this codebase: `get_suggestion_for_supplier( array $supplier )` is
defined as `get_suggestions_bulk( [$supplier] )` returning the single
element.

**Input shape (resolved now — avoids a redundant query):** the service
takes **already-loaded supplier rows** (`array<int,array>` with at least
`id` and `default_lead_time_days`), not bare supplier IDs. The PO creation
page already calls `WC_Inventory_Overview_Suppliers::list()` once to
populate the Supplier `<select>` dropdown; that same result is passed
straight into the service. This avoids adding a new "get suppliers by
IDs" method to `WC_Inventory_Overview_Suppliers` (which doesn't currently
exist — only `get(int)` and `list(array $args)` do) and means the service
issues exactly **one** additional query
(`Supplier_Lead_Time_Service::get_stats_bulk()`) on top of a page load
that was already fetching the supplier list regardless.

**Return shape (Internal only, not a public API):** each element keyed by
supplier ID, containing at least `days` (`?int`) and `confidence`
(`?string`); `source` (`'observed'|'configured'|'none'`) may also be
included for internal testing/debugging/presentation-wiring convenience,
but is never documented or exposed as a public-facing field.

## 6. Data flow / interaction rule (client-side)

1. On the PO creation page load, the server computes suggestions for
   every supplier in the dropdown in one bulk pass and passes the result
   through `wp_localize_script('wc-io-po-admin', 'wcIoPoAdmin', [...])`
   (already called for i18n strings) — extended with a new
   `leadTimeSuggestions` key, e.g.
   `{ "<supplier_id>": { "days": 12, "confidence": "estimated" } }`, only
   entries with a non-null suggestion included.
2. `assets/po-admin.js` (already enqueued on this exact page) adds a
   `change` handler on `#wc_io_po_supplier_id`. On change, if a suggestion
   exists for the selected supplier **and suggestions have not been
   permanently disabled for this session (see rule 3)**, compute
   `expected_date = order_date (or today if order_date is blank) +
   suggestion.days` and set both the Expected Date and Confidence fields.
3. **Permanent-disable rule:** the moment the operator manually edits
   **either** the Expected Date field **or** the Confidence field (any
   user-driven `input`/`change` event, as opposed to a programmatic fill
   from step 2), automatic suggestions are **permanently disabled for the
   remainder of that PO-creation page session** for both fields — not just
   "don't overwrite this once." Changing the supplier again afterward
   never re-applies a suggestion, even to a still-blank field. This is a
   deliberate one-way switch: never try to be clever about detecting "the
   user cleared it back to blank, so maybe suggestions are welcome again"
   — once touched, always manual, for the rest of that session.
   Programmatic writes performed by step 2 itself must never be
   misinterpreted as a manual touch.
4. This logic only runs on the **create** form (`action=new`), never on
   edit — an existing PO's stored values are never touched, and no
   suggestion logic fires there at all.
5. Selecting a supplier with no suggestion available (and suggestions not
   yet permanently disabled) → any previously auto-filled, still-untouched
   suggestion is cleared and the fields return to their normal unsuggested
   state — a stale suggestion from a previously-selected supplier must
   never linger after switching to a supplier with no suggestion.

This is pure progressive enhancement: with JavaScript disabled, or before
any supplier is selected, the form behaves exactly as it does today.

## 7. Services

- **New:** `WC_Inventory_Overview_Expected_Date_Suggestion_Service`
  (§5) — pure, read-only (one call to
  `Supplier_Lead_Time_Service::get_stats_bulk()` +
  `is_observed_value_usable()`, zero writes, no hooks, no UI concerns).
- **Modified (additive only, see §5.1):**
  `WC_Inventory_Overview_Supplier_Lead_Time_Service` gains
  `is_observed_value_usable()`; its architecture guard test's caller
  allowlist gains one entry.
- **Consumed unchanged:** `WC_Inventory_Overview_Suppliers::list()`,
  `WC_Inventory_Overview_PO_Confidence::ESTIMATED`/`UNKNOWN`.
- **Modified:** `WC_Inventory_Overview_PO_Admin` — the new-PO render path
  calls the new service and passes its output into the existing
  `wp_localize_script()` call; no change to the edit-PO render path, no
  change to `handle_save()`/`create_draft()`'s validation or persistence
  logic (a suggestion arrives at the server exactly as a manually-typed
  value would).
- **Modified:** `assets/po-admin.js` — new supplier-`change` handler per
  §6, purely additive to the existing IIFE.

## 8. UI

- Purchasing → Purchase Orders → **New Purchase Order** only. No change
  to the PO list table, PO detail/edit view, or any other admin screen.
- No new fields, no new form controls — the existing Expected Date
  (`<input type="date">`) and Confidence (`<select>`) are pre-filled, not
  replaced or duplicated.
- No visual indicator distinguishing a suggested value from a manually
  entered one is introduced in this milestone — a natural, named, low-risk
  follow-on if usage shows merchants want to see *why* a date is
  pre-filled, but not required here.
- No new capability required — gated by the same `manage_woocommerce`
  capability already guarding this page.

## 9. Invariants

**New invariant, this milestone:**

> **INV-M10-1 — Suggestions assist, never govern.** Automatic expected-date
> suggestions may assist the operator but never become authoritative. A
> suggestion is always a pre-filled default, never a locked, forced, or
> silently-reasserted value; the operator's own entry always wins,
> permanently, once made (§6 rule 3).

Enforced through:
- A read-only suggestion service (no persistence of its own, §5).
- No persistence outside the existing form submission path.
- Create-form-only client-side assistance (§6 rule 4).
- Touched/manual-edit protection (§6 rule 3).
- No automatic change on edit forms whatsoever.

Existing invariants unaffected: INV-2 (single stock mutator — untouched),
INV-4/INV-5 (computed quantities/delay — untouched), D9's confidence
vocabulary (reused, not extended), D12/M9's sole-computation-owner
discipline (extended with one new, narrowly-scoped instance, per §5's
explicit two-responsibility split).

## 10. Testing strategy

Follows the established per-milestone pattern
(`tests/unit/<area>/` + `tests/integration/<area>/`,
`Test_WC_IO_Expected_Date_Suggestion_` added to
`tests/docker/run-phpunit.sh`'s CI-blocking filter from day one):

**Unit** (`tests/unit/expected-date-suggestion/`):
- Observed usable → observed wins.
- Observed below maturity threshold → configured fallback.
- Observed absent → configured fallback.
- No observed/configured → no suggestion.
- Observed average rounds exactly as M9's admin display convention.
- Confidence always `estimated` when a suggestion exists, never `exact`.
- Bulk/single consistency.
- Invalid supplier input (missing/zero/negative ID, malformed row).
- Archived-supplier input, where applicable to the resolution rule.
- M9's usability policy is reused via `is_observed_value_usable()`, never
  duplicated — asserted by an architecture-level check, not just by
  convention.

**M9 regression unit test**: `is_observed_value_usable()` itself gets a
small, direct unit test in M9's own test file
(`tests/unit/supplier-lead-time/test-supplier-lead-time-service.php`) —
the one place this milestone adds a test to an M9-authored *test* file,
matching the one small code addition in §5.1. M9's other existing tests
must continue to pass unmodified.

**Integration** (`tests/integration/expected-date-suggestion/`), real
Supplier + real PO/Goods-Receipt fixtures (reusing the same fixture
helpers M9's own integration tests established):
- Completed PO history → real observed suggestion end-to-end.
- Configured-only supplier → configured suggestion.
- Neither → no suggestion.
- Observed wins over configured when both are present.
- Multiple suppliers via the bulk path in one pass.
- M9's own existing stats remain unchanged by this milestone (regression
  check, not just "M9 tests still pass").

**Architecture guard**
(`tests/unit/expected-date-suggestion/test-expected-date-suggestion-architecture.php`):
- Sole owner of the suggestion/recommendation-policy computation.
- Zero writes; no stock mutation; no PO mutation; no receipt mutation.
- No duplicated lead-time aggregation (i.e. it never independently
  computes an average/min/max itself).
- Sole-caller allowlist (`WC_Inventory_Overview_PO_Admin` only).
- Single delegates to bulk.
- No public API/versioning surface added.

**Architecture guard update (M9's own)**: update
`test-supplier-lead-time-architecture.php`'s
`test_only_approved_files_call_the_service()` expected-caller list to
include the new service file, per §5.1.

**Performance**: bulk-query-count test at 10/40/200-supplier fixture
scale. Prefer asserting an explicit expected query count (not merely
cross-scale equality) where the existing test technique
(`tests/integration/supplier-lead-time/test-supplier-lead-time-performance.php`)
makes that practical; fall back to equality-only if an explicit count
proves impractical to pin down. Must confirm query count does not grow
with supplier count.

**Manual/browser verification** (no JS test infra exists in this repo):
1. New PO + a supplier with a usable observed average → Expected Date
   pre-filled correctly (rounded), Confidence = estimated.
2. New PO + configured-only supplier → configured fallback used.
3. New PO + supplier with no data → fields remain unsuggested.
4. Manually change Expected Date, then change supplier → manual value
   remains untouched.
5. Manually change Confidence, then change supplier → manual value
   remains untouched.
6. Change supplier before touching any field → auto-suggestion updates
   normally.
7. Select supplier A (has a suggestion), then supplier B (no suggestion)
   → the still-untouched auto-filled suggestion from A is cleared.
8. Open an existing PO for edit → confirm absolutely no suggestion logic
   fires.

## 11. Documentation

- `docs/admin-guide-suppliers.md`: correct the "Configured Lead Time" row
  (§1's discovered false claim) to accurately describe the new
  observed-then-configured-then-none priority rule; update the "Lead Time
  — Configured vs. Observed (M9)" section's closing note (currently:
  *"Observed Lead Time does not (yet) feed into the expected-date
  suggestion..."*) since M10 changes exactly that. Document: calendar
  days, confidence always estimated, advisory-only, operator-overwrite
  protection.
- `docs/ARCHITECTURE_BASELINE_v1.24.0.md`: in-place update — add M10 to
  the milestone table, note the new sole-owner boundary, its guard test,
  the small additive extension to M9's service (§5.1), and INV-M10-1, per
  §12's own consistency rule.
- `docs/architecture-audit.md`: new `## Milestone M10` section, same shape
  as every prior milestone section, explicitly documenting the
  Statistics-vs-Recommendation-Policy-vs-Presentation split (§5) so a
  future auditor doesn't mistake the services for redundant.
- `CLAUDE.md`: update the Implementation Status table (add the M10 row);
  update the "Platform status" summary line.
- `docs/release-runbook.md`, `docs/checklists/validation-checklist.md`,
  `docs/rollback-plan.md`: add M10 subsections following the established
  per-milestone template.
- `docs/testing.md`: update only if an actual changed fact (e.g. new test
  counts/suite names) requires it, per the same discretion M9 exercised.
- `docs/checklists/m10-release-readiness.md`: created at WP4 (Freeze),
  **not** at implementation time — out of scope for this plan/implementation
  task.
- **Release notes**: per the feature-train process
  (`docs/process/milestone-lifecycle.md`), no GitHub Release artifact
  (`docs/GITHUB_RELEASE_NOTES_*.md`) is produced at M10's completion. Per
  the approved wording: *"Per the feature-train process, no GitHub Release
  artifact is produced at M10 milestone completion. Release notes will be
  generated when the feature train containing M9 + M10 (+ later milestones
  if applicable) is released."* This must not be described as a missing
  release artifact.

## 12. Release impact

None at milestone completion — per §3's Release Trigger check, M10 joins
the feature train with M9. Plugin version bump (to `1.27.0`, following the
minor-per-milestone convention) happens at implementation time per
existing practice; `DB_VERSION` stays `10`. No tag, no GitHub Release, no
deploy until WP6 batches M9 (+M10, +whatever follows) together. Last
released version remains `v1.25.0`; `1.27.0` must never be described as
released.

## 13. Rollback strategy

Code-only, no data or schema written — same clean profile as M6-M9.
Reverting the milestone's commits (or simply not merging its branch)
leaves the PO creation form exactly as it behaves today: blank Expected
Date, "unknown" confidence, fully manual entry. Nothing was ever persisted
differently than a manual entry would be.

## 14. Risk assessment

| Risk | Mitigation |
|---|---|
| Suggestion silently overwrites a merchant's manual edit | §6 rule 3's permanent-disable-for-session rule, explicitly tested (manual QA step 4/5 in §10) |
| Suggestion logic accidentally fires on the **edit** form and corrupts an existing PO's stored date | Explicit non-goal (§4.1) + JS only binds on the create-form's supplier select; manual QA step 8 explicitly checks it |
| Stale suggestion lingers after switching to a supplier with no suggestion | §6 rule 5, explicitly tested (manual QA step 7) |
| Rounding mismatch between the Supplier panel's displayed average and the PO suggestion | Reuses the exact same `(int) round(...)` convention (§5.2), not reimplemented |
| New service becomes a second, competing computation of "lead time" instead of a thin combinator | Architecture guard test proves it never independently computes an average/min/max itself — only calls `Supplier_Lead_Time_Service::get_stats_bulk()`/`is_observed_value_usable()` and reads `default_lead_time_days`; §5's explicit responsibility split documents the boundary so it isn't merged later |
| The small M9 code extension (§5.1) is mistaken for "redesigning a completed system" | §5.1 explicitly documents it as additive-only (new method, no existing behavior change, only the guard test's caller allowlist grows) — matches what M9's own guard test docstring already anticipated |
| Documentation drift (the exact class of error M9's own audit caught) | §11's doc updates are scoped explicitly against the currently-false claim identified in §1, not just "add an M10 row" |
| Business-day vs. calendar-day ambiguity resurfacing later | §5.2 documents the calendar-day decision explicitly, with the reasoning, so it doesn't need re-litigating |

**Overall risk profile: low.** No schema change, no public API change, no
write-path change, no storefront change, no new persistent state — the
only "Yes" in the Architecture Impact Statement is a client-side form
pre-fill on one admin screen.

## 15. Implementation work packages

*(These are the milestone's own internal work packages — nested inside
`docs/process/milestone-lifecycle.md`'s outer WP1 "Implementation" phase;
not to be confused with that document's WP0-WP6 process labels.)*

**WP-A — `Expected_Date_Suggestion_Service`** (§5, §7): new class,
`get_suggestion_for_supplier()`/`get_suggestions_bulk()`, pure/read-only.
Includes the small additive `is_observed_value_usable()` method on M9's
`Supplier_Lead_Time_Service` (§5.1).

**WP-B — Architecture guard tests** (§10): new guard test for the new
service (sole-owner, zero-write, sole-caller allowlist, bulk-first
delegation) + the small, deliberate update to M9's own guard test's caller
allowlist — same PR as WP-A.

**WP-C — PO Admin wiring** (§7, §8): call the new service in the new-PO
render path, extend the existing `wp_localize_script()` payload.

**WP-D — Client-side suggestion behavior** (§6): `assets/po-admin.js`
supplier-`change` handler, permanent-disable-on-edit tracking,
stale-suggestion clearing, create-form-only binding.

**WP-E — Tests** (§10): unit (resolution rule + M9's new predicate
method), integration (real fixture end-to-end), performance (query-count
equality/explicit count).

**WP-F — Documentation** (§11).

**Sequence:** WP-A → WP-B (guard alongside implementation, no "add later")
→ WP-E unit tests (validate WP-A's rule) → WP-C → WP-D (UI, consuming a
proven service) → WP-E integration + performance tests → WP-F. One logical
commit per work package, matching M0-M9 discipline.

## 16. Acceptance criteria / Definition of Done

- [ ] WP-A: service implemented; single/bulk consistency holds; M9's
      `is_observed_value_usable()` addition is additive-only (M9's
      existing tests still pass unmodified except the one caller-allowlist
      line).
- [ ] WP-B: both architecture guard tests pass — sole ownership, zero
      writes, and M9's updated allowlist.
- [ ] WP-C/WP-D: creating a new PO and selecting a supplier with a usable
      observed average pre-fills Expected Date (rounded) and sets
      Confidence to "estimated"; a supplier with only a configured lead
      time falls back correctly; a supplier with neither leaves the
      fields untouched; a manual edit permanently disables further
      suggestions for that session (INV-M10-1); a stale suggestion is
      cleared when switching to a no-suggestion supplier; editing an
      **existing** PO is unaffected.
- [ ] WP-E: full unit + integration + performance coverage per §10.
- [ ] WP-F: all listed documentation updated, including the correction to
      `docs/admin-guide-suppliers.md`'s previously-false claim (§1).
- [ ] `DB_VERSION` confirmed unchanged at `10`; no new public API surface;
      no new settings/hooks/filters/capabilities/AJAX/REST endpoints; no
      storefront change.
- [ ] Full CI green (unit + M1-M10-focused blocking suite + integration).
- [ ] Branch `feature/m10-po-expected-date-suggestion` fully committed,
      clean, **not pushed, not merged, not tagged** — per the feature-train
      model, M10 does not trigger its own release.
- [ ] Per `docs/process/milestone-lifecycle.md`: independent audit (WP2),
      remediation (WP3), and freeze record `docs/checklists/m10-release-readiness.md`
      (WP4) all happen as separate, later steps — not part of this plan's
      or the implementation task's own completion.
