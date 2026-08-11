# M16 Discovery and Definitive Implementation Planning — wc-inventory-overview

## Context

The M13–M15 feature train shipped as v1.32.0 (main == origin/main == `61488e9`, tag `v1.32.0` → merge commit `33dee1d`). Every governance document in the repo (CLAUDE.md, `docs/ARCHITECTURE_BASELINE_v1.24.0.md`, `docs/checklists/feature-train-development-head.md`) states the same thing: the only authorized next step is planning M16, pending explicit approval, from current `main`. This task is that planning pass: a fresh, read-only discovery of the released system to determine the single best next capability, followed by a complete, implementation-ready specification for it — no code, branches, or docs were touched to produce this.

This is not a continuation of the M13–M15 supplier-reporting arc by default; the brief explicitly requires evaluating the whole backlog (forecasting, reservations, inbound/warehouse, supplier merge, maintenance debt, etc.) fresh, and picking whichever one is genuinely ready and valuable — supplier-adjacent or not.

---

# PART A — Verified v1.32.0 Baseline

| Check | Result |
|---|---|
| Current branch | `main`, working tree clean |
| `main` HEAD | `61488e9aa90fcf06f2a2cdc879d2859de8a3ba0c` — matches expected `61488e9` |
| `origin/main` (via `ls-remote`, no mutating fetch) | same commit — main == origin/main |
| Tag `v1.32.0` | annotated, dereferences to `33dee1df6fcbc652e0f8b623b554cbfb3ef6967f` — matches expected `33dee1d` |
| Merge commit `33dee1d` | present: "Release v1.32.0 — M13–M15 Purchasing & Supplier Insights (#17)" |
| Plugin version | `Version: 1.32.0` in `wc-inventory-overview.php` |
| DB_VERSION | `const DB_VERSION = '10';` (`includes/class-wc-inventory-overview-install.php:15`) |
| M13/M14/M15 plans | present in `docs/milestones/`, immutable, undisturbed |
| M16 branch / plan file | none exist anywhere (local or remote) |
| GitHub Release / release notes | `docs/GITHUB_RELEASE_NOTES_1.32.0.md` present, documents the bundled train |

**No mismatches. Preflight passes cleanly — safe to proceed with M16 discovery.**

---

# PART B — Repository / Deferred-Work Findings

## Architecture conventions that constrain every M16 candidate
- No DI container: every domain is a static-method service class, singleton controllers (`::instance()`), hook-wired in `plugins_loaded`.
- **Single-mutator-per-domain** is pervasive and *mechanically enforced* by one "architecture guard" test per feature (12 such files) that scans `includes/` for illegitimate callers of sensitive methods.
- Bulk-read, no-N+1 discipline is non-negotiable — every list/report has its own performance test asserting a bounded query count at a fixed fixture scale.
- New services are internal-only (D16); the sole versioned/public surface is `Expected_Delivery_Service` (storefront `woocommerce_get_availability` hook).
- D16 (CLAUDE.md): "No speculative schema or APIs... until a concrete consumer exists." This single rule is the reason Reservations, REST/GraphQL, and Inbound Shipment are all still unbuilt.

## Deferred-work inventory (classified)

**A — merchant-facing capability, genuinely unbuilt:**
- **Supplier merge tool** — the one item literally advertised as "planned" in `docs/admin-guide-suppliers.md:266` and `readme.txt:266`. Verified: zero merge-related code exists anywhere in `includes/` (grep hits are all `array_merge`/unrelated). Classified Level B in every prior milestone's backlog table (cross-table FK reassignment across POs, receipts, movements — destructive/irreversible if wrong).
- **Cross-supplier / storewide spend rollup ("top suppliers")** — newly named as an explicit non-goal in M15, not yet evaluated for its own milestone. Verified: `WC_Inventory_Overview_Supplier_Spend_Service` has exactly one public method, `get_summary( int $supplier_id )` — single-supplier only, no aggregate/rollup exists.
- **PO delay grace-days Settings UI** — "rejected twice as too small alone." Verified: `WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS` (`wc_io_po_delay_grace_days`) is read in 6+ places (`purchase-order-lines.php`, `po-admin.php`, `purchase-orders-list-table.php`, `suppliers-list-table.php`, `purchasing-page.php`) but has **no UI anywhere** — only editable via raw `update_option()`. An existing, precedented Settings tab (`WC_Inventory_Overview_Settings` + `class-wc-inventory-overview-plugin.php` settings-tab renderer, `save_from_post()`) already exposes ~10 comparable options; grace days simply isn't one of them.
- **Expected-date suggestion source transparency** — "rejected twice as too small alone." Verified: `WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestions_bulk()` already computes and returns `source` (`observed`|`configured`|`none`) per supplier, but `class-wc-inventory-overview-po-admin.php`'s New PO template never reads or displays that key — only `expected_confidence` is shown. The data is computed and thrown away.

**B — architecture/maintenance debt:**
- Plugin god-class (`class-wc-inventory-overview-plugin.php`) — deliberately deferred every milestone since M8, "remains open for a future, dedicated milestone."
- PHPCS not CI-gated — ~559 errors/634 warnings, no baseline recorded, explicitly non-blocking.
- Custom dynamic-SQL surface in Profitability/Movements list tables — standing known risk, not milestone-scoped.
- PO-number allocation atomicity (ADR-0002) — accepted, non-atomic-under-race gap; ADR explicitly says "do not change runtime behavior solely to address it."
- Order/Movements list `ORDER BY` single-column limitation — accepted, narrow, carried forward as context only.
- Stale test docs (`tests/README.md`, `docs/testing.md` "Running tests locally" section describe an outdated filter) — doc-hygiene only.

**C — release/process/test debt:** `docker-compose.test.yml` legacy harness (manual-only, not removed), no dedicated PHPCS/performance CI gate, `run-phpunit.sh` regex-filter convention (verify via `--list-tests`, not by inspection) — all process-only, none milestone-worthy alone.

**D — deferred because prerequisites aren't ready** (verified, not assumed):
- **Inventory Coverage / Forecast** — blocked on sales-velocity data. Verified via schema: `wc_io_inventory_movements` only ever writes `purchase`/`purchase_batch`/`cost_adjustment`/goods-receipt movement types — **no sales/refund/consumption movement is ever recorded anywhere in the plugin.** The plugin owns zero demand-side history. Forecasting would be pseudo-forecasting from supply data alone. **Correctly still premature.**
- **Reservations / Available Stock** — blocked by D16, no concrete consumer named anywhere. No `reserved`/`available_stock` column or concept exists. Introducing reservation mutation now would be net-new persistent state and concurrency risk with no identified caller. **Correctly still premature.**
- **Inbound Shipment entity** — "no concrete need; tracking/carrier already live on the PO" (verified: `wc_io_purchase_orders`/lines already carry expected_date/confidence; nothing points to a gap only a separate entity could fill).
- **Warehouse location hierarchy** — registered in `docs/OWNERSHIP.md` as owned by this plugin, purely as a *defensive* registration (to keep a sibling plugin from claiming it) — "zero code/schema exists; a large new initiative on its own," explicitly "not planned work." Single-warehouse shop; no multi-location workflow exists to serve.
- **REST/Store API/GraphQL** — blocked by D16, same "no concrete consumer" rule; `Expected_Delivery_Service`'s own doc-comment names itself as the future integration seam, still unbuilt by design.

## Candidate already effectively shipped (important correction)
Initial evaluation treated **"Inventory Position supplier/incoming drilldown"** as a live backlog candidate. Direct code verification (`includes/class-wc-inventory-overview-list-table.php:757`, `render_position_drilldown_section()`) shows this was already built in M3 (v1.20.0): the drilldown already shows PO number (linked), outstanding qty, expected date, confidence, and a delayed badge, fully bulk-fetched (≤2 queries regardless of row count), covered by its own performance and characterization tests. The only two facts missing are **supplier name** and **PO status** — both already sit unused in the same already-joined SQL row (`po.supplier_name_snapshot`, `po.status`, confirmed in `class-wc-inventory-overview-purchase-order-lines.php`'s `query_open_lines()`). This is not a standalone milestone-sized candidate; it is folded into the recommended M16 below as a two-column, zero-new-query extension of the same underlying transparency problem.

---

# PART C — Candidate Comparison

| Candidate | Merchant value | Freq. | Reuses existing data | Arch. fit | Size | Risk | Schema | Mutation | New domain? | Prereqs | Class | Verdict |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Expected-Date & Delay Transparency** (suggestion source + grace-days settings + drilldown 2 cols) | High — closes a repeated, previously-rejected-as-too-small gap across 3 touchpoints in the core PO workflow | High (every PO created/reviewed) | 100% — all values already computed, just not shown/settable | Perfect — extends existing owners only | Small | Very low | None | Settings-option write only (existing pattern) | No | None | LOW-RISK EXTENSION | **Recommended now** |
| Supplier merge tool | High but occasional — genuine data-hygiene pain, and the one item literally advertised as "planned" | Low (rare, admin cleanup task) | Reuses supplier/PO/receipt/movement tables | Good, but needs new conflict-resolution/audit-trail policy decisions | Medium–Large | Medium–High — irreversible cross-table FK rewrite | None (or a small merge-audit log) | Yes — destructive, multi-table | No | A currency/ownership-conflict resolution policy must be decided | MEDIUM-SCOPE CAPABILITY | Later (strong M17 candidate) |
| Cross-supplier/storewide spend rollup ("top suppliers") | Medium — nice-to-have reporting, partially redundant with per-supplier M15 view | Medium | 100% — extends `Supplier_Spend_Service` aggregate | Good | Small–Medium | Low | None | None | No | None | LOW-RISK EXTENSION | Later |
| Inventory Position supplier/incoming drilldown | N/A — already shipped M3; only 2 columns missing | — | — | — | Trivial | Trivial | None | None | No | None | (folded into recommended M16) | Included as stretch surface |
| Inventory Coverage / Forecast | Very high if buildable | High | **No** — no demand/velocity data exists in schema at all | Would require new persistent demand-capture domain | Large | High | Likely yes | Yes | **Yes** | Sales/consumption-velocity capture (doesn't exist) | NEW DOMAIN INITIATIVE | **Reject now** — prerequisite missing |
| Reservations / Available Stock | High if a consumer existed | — | Partially (WC order data exists but unused for this) | Would require new persistent domain + concurrency correctness | Large | High | Yes | Yes, and concurrency-sensitive | **Yes** | A named concrete consumer (none exists) | NEW DOMAIN INITIATIVE | **Reject now** — D16 violated otherwise |
| Inbound Shipment entity | Low-Medium | Low | PO already carries tracking/dates | New entity, new lifecycle | Medium-Large | Medium | Yes | Yes | **Yes** | No concrete need identified | NEW DOMAIN INITIATIVE | Reject — no need |
| Warehouse location hierarchy | Low (single-warehouse shop today) | — | None | New entity | Large | Medium | Yes | Yes | **Yes** | Multi-location demand (none) | NEW DOMAIN INITIATIVE | Reject — defensive registration only |
| REST/Store API/GraphQL | Speculative | — | N/A | `Expected_Delivery_Service` is the designed seam | Medium | Medium (public API commitment) | No | No | Debatable | A named external consumer (none) | NEW DOMAIN INITIATIVE (public-surface) | Reject — D16 |
| PO-number allocation atomicity fix | Low — accepted, non-critical gap | Very low | N/A | N/A | Small | Low | No | Small | No | None, but ADR-0002 explicitly advises against changing it without new evidence | MAINTENANCE | Reject — no new evidence of a problem |
| Plugin god-class decomposition | None directly merchant-facing | — | N/A | Pure refactor | Large | Medium (regression surface) | No | No | No | None | MAINTENANCE/ARCHITECTURE | Reject — not materially limiting |
| PHPCS cleanup | None directly merchant-facing | — | N/A | Hygiene | Large (559/634 findings) | Low | No | No | No | None | MAINTENANCE/ARCHITECTURE | Reject — not materially limiting |

---

# PART D — Recommended M16 and Rationale

## Selected capability: **"PO Expected-Date & Delay Transparency"**

Three surfaces of one coherent problem — the plugin already computes expected-date suggestion provenance and already displays a delay indicator, but the merchant can't see *why* those values are what they are, nor configure the one policy knob (grace days) that controls one of them:

1. **Suggestion source display** on the New PO screen — show which source (observed lead time / configured supplier default / none) produced the pre-filled expected date, with the supporting stat when observed (e.g., "based on 12 prior orders, avg 9 days").
2. **PO delay grace-days Settings field** — add the missing UI field to the plugin's existing Settings tab, replacing direct-database-edit as the only way to change delay sensitivity.
3. **Drilldown Supplier + Status columns** — add the two missing columns to the already-shipped Inventory Position drilldown mini-table, using data already present in the same already-executing query.

**Why this and not something else:** every other "A"-class candidate is either not yet ready (forecast/reservations — missing prerequisite data, confirmed not assumed), a new domain with no identified consumer (inbound shipment/warehouse/REST — D16 violation), or larger/riskier with real but lower urgency (supplier merge — Level B, occasional use, needs its own policy design first). This candidate is the only one that is 100% architecturally ready today, purely additive, zero schema/mutation risk, and closes out backlog debt that has been explicitly rejected twice before for being *too small alone* — combining the two related, previously-rejected items with a trivial third surface in the same theme is exactly large enough to be a coherent, worthwhile milestone without inventing unrelated scope.

---

# PART E — Complete Definitive M16 Implementation Plan

## 1. Executive summary
M16 makes three already-computed-but-hidden facts visible/configurable in the Purchase Order workflow: expected-date suggestion provenance (New PO screen), PO delay grace-days (Settings tab), and supplier/status in the Inventory Position drilldown. **Zero domain/operational mutation; one existing settings-option write** (`WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS`, through the existing `Settings::save_from_post()` mechanism) — not a new domain mutation, not a PO/receipt/stock/cost/Inventory-Position/storefront mutation. No schema change, no DB_VERSION bump, no new domain, no storefront/API impact. Level A lifecycle.

## 2. Verified released baseline
See Part A. `main` = `61488e9`, `v1.32.0` = `33dee1d`, version 1.32.0, DB_VERSION 10, clean.

## 3. Repository discovery findings
See Part B.

## 4. Deferred-work inventory
See Part B.

## 5. Candidate comparison
See Part C.

## 6. Selected M16 and rationale
See Part D.

## 7. Problem statement
Purchasing agents currently: (a) see a pre-filled expected date on new POs with no explanation of its source or reliability, reducing trust and sometimes causing agents to type a manual override unnecessarily; (b) cannot adjust how many grace days a PO gets before being flagged "delayed" without a developer editing the database directly, despite the flag being visible throughout the PO/supplier UI; (c) see a per-product "incoming" breakdown that omits the two most basic identifying facts (which supplier, what PO status) a merchant would want at a glance.

## 8. Goals
- G1: Show expected-date suggestion source + supporting stat on the New PO screen.
- G2: Add a Settings-tab field to view/edit PO delay grace days.
- G3: Add Supplier and Status columns to the existing Inventory Position drilldown.

## 9. Non-goals
- No change to the suggestion resolution algorithm itself (observed→configured→none order, thresholds) — display only.
- No change to how `Expected_Deadline`/`PO_Delay` compute the deadline/delay flag — only how `grace_days` is set.
- No new capability constants, no capability-model change.
- No REST/GraphQL/Store API, no storefront-facing change (does not touch `woocommerce_get_availability`).
- No changes to Supplier Spend or Supplier Order History scope.
- No supplier merge tool, no forecast/coverage, no reservations, no inbound shipment, no warehouse locations — all explicitly out of scope, see Part B/C.
- No editing of PO/receipt/stock data anywhere in this milestone — the drilldown extension stays strictly read-only.
- No pagination/UI redesign of the drilldown table itself — only two additional columns to the existing structure.

## 10. Current architecture (relevant slice)
- `WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestion_for_supplier()` / `get_suggestions_bulk()` — sole owner of suggestion resolution; already returns `array{days, confidence, source}`; internally calls `WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_bulk()` (one query) for observed stats, which returns `sample_count`, `average_days`, etc., but currently discards them in `resolve_one()` after checking usability.
- `WC_Inventory_Overview_PO_Admin` — renders the New PO form (`class-wc-inventory-overview-po-admin.php`), calls `Expected_Date_Suggestion_Service::get_suggestions_bulk()` (line ~115) to build the supplier-keyed suggestion map consumed by JS/template, but only ever surfaces `expected_confidence`, never `source`.
- `WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS` = `'wc_io_po_delay_grace_days'`; `grace_days_from_option(): int` is the sole reader, called from `purchase-order-lines.php`, `po-admin.php`, `purchase-orders-list-table.php`, `suppliers-list-table.php`, `purchasing-page.php`. No writer exists outside raw `update_option()`.
- `WC_Inventory_Overview_Settings` — existing options registry (`class-wc-inventory-overview-settings.php`) with ~10 `OPTION_*` constants, getters, and a `save_from_post( $post )` method; rendered by a "Settings" tab in `class-wc-inventory-overview-plugin.php` (~line 652 `render` method, ~line 391 save handling). This is the established pattern for any new plugin-wide option.
- `WC_Inventory_Overview_Purchase_Order_Lines::query_open_lines()` (`class-wc-inventory-overview-purchase-order-lines.php:408`) — the single query behind both `list_open_lines_for_product_ids()` and `list_open_lines_for_variation_ids()`, already `INNER JOIN`s `wc_io_purchase_orders po`, which has `supplier_name_snapshot` and `status` columns already in the row but not in the current `SELECT` list.
- `WC_Inventory_Overview_Inventory_Position_Service::get_position()` / `get_positions_bulk()` — pass `incoming_lines` through unchanged from the repository query; no Service/Resolver code touches individual line fields, so any new SELECT column automatically appears in `incoming_lines` with zero Service change.
- `WC_Inventory_Overview_List_Table::render_position_drilldown_section()` (`class-wc-inventory-overview-list-table.php:757`) — renders the existing mini-table (PO number, outstanding, expected date, confidence, delayed) from `$this->position_map`, which is bulk-fetched once in `prepare_items()` (line ~1331) before any HTML is emitted — the extension point for the two new columns.
- `WC_Inventory_Overview_PO_Statuses::label( string $status ): string` (`class-wc-inventory-overview-po-statuses.php:123`) — existing status→label translator, already used at equivalent call sites (`po-admin.php:279`, `goods-receipt-service.php:446`) — reuse for the new Status column, never re-derive the label elsewhere.

## 11. Ownership model
- **Business-rule owner (suggestion):** `WC_Inventory_Overview_Expected_Date_Suggestion_Service` — extend its return shape additively; no new owner needed.
- **Business-rule owner (grace days interpretation):** `WC_Inventory_Overview_PO_Delay` — unchanged; still the sole authority on how `grace_days` affects the deadline/delay computation.
- **Read owner (drilldown):** `WC_Inventory_Overview_Purchase_Order_Lines` — extend the existing whitelisted query, no new caller introduced.
- **Mutation owner (grace-days value):** `WC_Inventory_Overview_Settings::save_from_post()` — extend the existing sole settings-mutation entry point with one new sanitized field; do **not** introduce a second mutation path.
- **Presentation owner:** `class-wc-inventory-overview-plugin.php` settings-tab renderer (grace days field), `WC_Inventory_Overview_PO_Admin` New-PO template (suggestion source), `WC_Inventory_Overview_List_Table::render_position_drilldown_section()` (drilldown columns).

**Consumes:** `Expected_Date_Suggestion_Service`, `Supplier_Lead_Time_Service` (transitively, unchanged), `PO_Delay`, `Settings`, `Purchase_Order_Lines`, `PO_Statuses::label()`.

**Must NOT bypass:** Presentation classes must not compute suggestion source or grace-days interpretation themselves (no duplicated logic in `PO_Admin` or the settings-tab renderer); the drilldown extension must not add any new caller of `list_open_lines_for_product_ids()`/`list_open_lines_for_variation_ids()` — the edit stays inside the already-whitelisted repository file, so `tests/unit/inventory-position/test-inventory-position-architecture.php`'s `assertSame` sole-caller guard needs **zero changes**.

## 12. Domain / data flow
```
New PO screen:
  PO_Admin → Expected_Date_Suggestion_Service::get_suggestions_bulk()
    → (extended) returns {days, confidence, source, sample_count, average_days}
      (sample_count/average_days populated only when source === 'observed',
       both read from the same already-fetched $stats array — see BR-M16-2)
  → template renders source-aware message using sample_count/average_days
    (never the bare days value as evidence), no new query

Settings tab:
  Admin submits form → Settings::save_from_post() (extended)
    → validate-or-preserve per BR-M16-4 → update_option( PO_Delay::OPTION_GRACE_DAYS, ... )
      only on valid input; invalid/missing input leaves the option untouched
  Settings tab GET → get_option( PO_Delay::OPTION_GRACE_DAYS, 0 ) → pre-filled field
  (all existing PO_Delay call sites keep reading via grace_days_from_option() — untouched)

Drilldown:
  List_Table::prepare_items() → Inventory_Position_Service::get_positions_bulk()
    → Purchase_Order_Lines::query_open_lines() (extended SELECT: + supplier_name_snapshot, + status)
  → render_position_drilldown_section() renders 2 new <td> using PO_Statuses::label()
```

## 13. Exact business rules

- **BR-M16-1**: The New PO screen must display, for each line with a non-`none` suggestion source, one of exactly two messages, using the explicit evidence fields defined in BR-M16-2 (never the bare `days` value as evidence):
  - `source === 'observed'`: "Suggested from this supplier's delivery history ({sample_count} orders, avg {average_days} days)."
  - `source === 'configured'`: "Suggested from supplier's configured default ({days} days)."
  - `source === 'none'`: no suggestion message is shown (existing "no suggestion" behavior unchanged).
- **BR-M16-2**: `Expected_Date_Suggestion_Service::resolve_one()`'s return shape is extended additively to `array{days:?int, confidence:?string, source:string, sample_count:?int, average_days:?int}`:
  - `source === 'observed'`: `sample_count` = `$stats['sample_count']` (int); `average_days` = `(int) round( $stats['average_days'] )` — the exact same rounding/representation already used for this figure at `class-wc-inventory-overview-purchasing-page.php:579-580` and `class-wc-inventory-overview-suppliers-list-table.php:168`, not a new calculation. Both values are read from the same `$stats` array already fetched via `Supplier_Lead_Time_Service::get_stats_bulk()` inside `get_suggestions_bulk()` — no second query.
  - `source === 'configured'` or `source === 'none'`: `sample_count = null`, `average_days = null`.
  - `days` remains exactly what it is today (the resolved value used to prefill the form field) and is never repurposed as display evidence — the New PO template must read `average_days`/`sample_count` for the observed-provenance message, not `days`, keeping "the value we suggest" and "the evidence we show" as distinct, independently-sourced fields.
  - The existing suggestion-selection algorithm (observed → configured → none, and the `is_observed_value_usable()` threshold) is unchanged.
- **BR-M16-3**: The suggestion-source message is advisory text only; it must never be submitted as form data and must never influence which value is saved to `expected_date`/`expected_confidence` (INV-M16-1 covers this).
- **BR-M16-4**: Grace-days validation is an explicit validate-or-preserve contract, not `absint()`-style silent coercion (deliberately deviating from the `OPTION_DEFAULT_LOW_STOCK_THRESHOLD` precedent, which unconditionally accepts via `absint()` and is not reused here):
  - Read `$post['wc_io_po_delay_grace_days']` if present.
  - **Missing field** → preserve the previously stored value: skip the `update_option()` call for this field entirely.
  - **Present but not `is_numeric()`** → invalid; preserve the previously stored value (skip the write).
  - **Present, numeric, but not a clean integer** (i.e. `(string) (int) $raw !== trim( (string) $raw )`, rejecting decimals, leading/trailing characters, or scientific notation) → invalid; preserve the previously stored value (skip the write).
  - **Present, a clean integer, but `< 0` or `> 365`** → invalid; preserve the previously stored value (skip the write).
  - **Present, a clean integer, `0 <= n <= 365`** → valid; `update_option( WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS, $n, false )`. `0` is a valid, saveable value (must not be treated as "empty"/falsy and skipped).
  - No value is ever clamped/coerced into range (e.g. `-5` must never become `5` or `0`) — invalid input always means "leave the stored option untouched," never "substitute a computed nearest-valid value."
- **BR-M16-5**: The option name/constant remains `WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS` — the Settings tab reads/writes that existing option directly; it is not renamed, moved, or duplicated into a second option.
- **BR-M16-6**: The drilldown Supplier column displays `po.supplier_name_snapshot` (the same denormalized, deletion-safe value already used everywhere else PO-level supplier identity is shown) — never a live join to `wc_io_suppliers`, and never blank-on-archived (the snapshot is immutable at PO-creation time regardless of the supplier's later archive/reactivate status).
- **BR-M16-7**: The drilldown Status column displays `WC_Inventory_Overview_PO_Statuses::label( $line['po_status'] )` — the existing shared label map; no new status labels are invented for this milestone.
- **BR-M16-8**: The Inventory Position drilldown column order is fixed, not left to implementer discretion: **PO number, Supplier, Status, Outstanding, Expected date, Confidence, Delayed.** Only Supplier and Status are new (inserted immediately after PO number, before Outstanding); the five existing columns keep their existing order relative to each other and their existing behavior unchanged.
- **BR-M16-9**: All three surfaces are purely additive to their existing DOM structure — no existing column, message, or field is removed or renamed.

## 14. Invariants

- **INV-M16-1**: Zero mutation from the suggestion-display and drilldown-column changes — no write, submit-time influence, or side effect from either read path (testable: assert no `$wpdb` write calls / no `update_option`/`update_post_meta` calls reachable from the new display code).
- **INV-M16-2**: The one legitimate mutation path for grace days is `Settings::save_from_post()`; no other file calls `update_option( WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS, ... )` (grep-testable, mirrors the existing sole-mutator pattern used elsewhere in the codebase).
- **INV-M16-3**: `tests/unit/inventory-position/test-inventory-position-architecture.php`'s sole-caller assertion for `list_open_lines_for_product_ids`/`list_open_lines_for_variation_ids` continues to pass unmodified — the drilldown edit stays inside the already-whitelisted repository file.
- **INV-M16-4**: The suggestion-provenance and Inventory Position drilldown changes introduce zero additional repository/SQL queries:
  - Suggestion evidence (`sample_count`/`average_days`) comes from statistics already fetched by `Supplier_Lead_Time_Service::get_stats_bulk()` inside the existing suggestion resolution — no second query.
  - Drilldown Supplier/Status come from additional `SELECT` columns on the existing `query_open_lines()` query — no new query, no new join.
  - The existing Inventory Position bounded-query test, `tests/integration/inventory-position/test-inventory-position-list-table.php::test_position_query_count_bounded_for_twenty_plus_rows` (≤2 SELECTs), continues to pass unmodified.
  - The Settings grace-days field is **explicitly outside this invariant**: it uses normal WordPress `get_option()`/`update_option()` persistence, whose actual database-query behavior depends on object-cache state and is not asserted or counted here (see section 26).
- **INV-M16-5**: Suggestion source display never appears inconsistent with the underlying resolution rule — if `Expected_Date_Suggestion_Service` returns `source === 'observed'`, the displayed message must always be the observed-history message, never the configured-default message (single-owner, no duplicated branch logic in the template).
- **INV-M16-6**: Deterministic display — repeated page loads with unchanged underlying data render identical suggestion text, grace-days value, and drilldown columns.
- **INV-M16-7**: Existing capability gates are preserved unchanged: Settings tab remains gated by its current `manage_woocommerce` check; drilldown columns are gated by the same existing check already covering the rest of `render_position_drilldown_section()` (list-table.php line ~100); New PO screen remains gated by its existing `Purchasing_Caps::VIEW_PO`/edit checks. No new capability constant is introduced anywhere in this milestone.

## 15. Schema / data impact
- Schema change: **No.**
- New columns/tables: **No.** `supplier_name_snapshot` and `status` already exist on `wc_io_purchase_orders`; `sample_count` already exists in `Supplier_Lead_Time_Service`'s stats array.
- Migration required: **No.**
- Persistent data introduced: **No** new persistent structure — the grace-days value already exists as an option; this milestone only adds a UI to edit the pre-existing option.
- Existing data mutated: only the single `wc_io_po_delay_grace_days` option, via the existing `update_option()` pattern already used by every other Settings-tab field.

## 16. DB_VERSION impact
**None. `DB_VERSION` stays `'10'`.**

## 17. Version target
Development target: **1.33.0**, per repository versioning policy (`docs/process/milestone-lifecycle.md`: additive milestones bump minor version; verified this is the pattern for every M9–M15 dev bump). Actual release version/timing is decided at freeze — see Part G.

## 18. Mutation / transaction / concurrency contract
Zero domain/operational mutation; one existing settings-option write.
- The only mutation in this milestone is a single `update_option()` call for `wc_io_po_delay_grace_days`, made through `Settings::save_from_post()` on a standard `admin_post_*`-style form submission — the same pattern already used for ~10 other settings fields in the same method. This is not classified as a new domain mutation.
- No `DB_Transaction` wrapper is needed (`WC_Inventory_Overview_DB_Transaction` is reserved for the PO/Goods-Receipt mutation domains; a single WordPress option write is not a multi-statement transaction and follows existing Settings-tab precedent, which also does not wrap its writes in `DB_Transaction`).
- No concurrency risk: `update_option()` is WordPress's standard last-write-wins semantics, identical to every other setting on this tab; no financial or lifecycle state is affected by a lost race on this particular option.
- No PO/receipt/stock lifecycle mutation of any kind in this milestone.

## 19. Security / capability impact
None. No new capability constant. Settings tab, New PO screen, and Inventory Overview page all keep their existing, unchanged capability checks. The new Settings field is protected by the same nonce/capability gate already wrapping the rest of the Settings tab's `save_from_post()` submission.

## 20. Public API impact
None. No REST route, no Store API, no GraphQL, no versioned public PHP API touched. All three services involved (`Expected_Date_Suggestion_Service`, `PO_Delay`, `Purchase_Order_Lines`) remain internal-only per D16.

## 21. Storefront impact
None. Nothing in this milestone touches `woocommerce_get_availability`, `Expected_Delivery_Service`, or any customer-facing surface. Purely wp-admin.

## 22. Exact admin/UI behavior
- **New PO screen**: beneath (or immediately adjacent to) the existing expected-date/confidence fields for each line, render one line of advisory text per BR-M16-1/BR-M16-2, styled as a `description`/`hint` (matching existing hint styling elsewhere on the form), never as an editable field.
- **Settings tab**: add one new labeled numeric input ("PO delay grace period (days)") in the existing settings form, positioned near other PO-related options, with an inline description explaining it delays the "delayed" flag by N days past the expected date and stating the accepted range (0–365) and that invalid input is ignored (previous value kept — per BR-M16-4). Saves via the existing "Settings saved." success-notice flow; if the submitted grace-days value was invalid, the field re-displays the preserved (unchanged) stored value, not the rejected input.
- **Inventory Position drilldown**: insert two new `<th>`/`<td>` pairs ("Supplier", "Status") into the existing mini-table's header/rows in `render_position_drilldown_section()`. Column order is fixed per BR-M16-8: **PO number, Supplier, Status, Outstanding, Expected date, Confidence, Delayed** — Supplier and Status are inserted immediately after PO number and before Outstanding; values via BR-M16-6/BR-M16-7.

## 23. Production classes/files affected
- `includes/class-wc-inventory-overview-expected-date-suggestion-service.php` — extend `resolve_one()`/`empty_suggestion()` return shape to add `sample_count` and `average_days` (both null unless `source === 'observed'`, both sourced from the already-fetched `$stats` array per BR-M16-2).
- `includes/class-wc-inventory-overview-po-admin.php` — render the suggestion-source message on the New PO template using `sample_count`/`average_days` (never `days`) per BR-M16-1/BR-M16-2; no change to any mutation path in this file.
- `includes/class-wc-inventory-overview-settings.php` — read/write via `WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS` directly (do not introduce a duplicate constant): add a getter (e.g. `get_po_delay_grace_days()`) mirroring the existing default-read pattern, and implement the validate-or-preserve logic of BR-M16-4 inside `save_from_post()` — explicitly not `absint()`, since `absint()` would silently coerce out-of-range/negative input instead of preserving the prior value.
- `includes/class-wc-inventory-overview-plugin.php` — settings-tab render method: add the new field's markup and value; save-handling call already routes through `Settings::save_from_post()`, no separate change needed there beyond the new field being included in `$post`.
- `includes/class-wc-inventory-overview-purchase-order-lines.php` — extend `query_open_lines()`'s `SELECT` list with `po.supplier_name_snapshot AS supplier_name, po.status AS po_status`.
- `includes/class-wc-inventory-overview-list-table.php` — extend `render_position_drilldown_section()` with the two new columns per section 22.

## 24. New classes/files
**None.** This milestone is additive-only within existing classes; no new domain class, no new AJAX handler, no new file is warranted (see Part B's correction re: the drilldown — an earlier AJAX-based design was considered and explicitly rejected once verification showed the data is already bulk-fetched inline).

## 25. Hooks/integration points
None new. Existing `admin_post_*`/settings-save hook wiring in `class-wc-inventory-overview-plugin.php` is reused unchanged.

## 26. Query/performance contract

The performance contract distinguishes operational repository/SQL reads (bounded, asserted) from WordPress option persistence (not query-counted, per INV-M16-4):

**A. Expected-date suggestion provenance** — adds zero new SQL/repository queries. `sample_count`/`average_days` come from the same already-fetched `Supplier_Lead_Time_Service::get_stats_bulk()` stats already used by the existing suggestion resolution; `Expected_Date_Suggestion_Service::get_suggestions_bulk()` remains exactly one additional query regardless of supplier count (per its own existing doc-comment guarantee) — extending the return shape does not add a query.

**B. Inventory Position drilldown** — adds zero new SQL/repository queries. Supplier and Status are additional `SELECT` columns on the existing `query_open_lines()` query, not a new join or a second query. The existing bounded Inventory Position query contract must remain unchanged: `test_position_query_count_bounded_for_twenty_plus_rows` (≤2 SELECTs against the PO-lines join, page-load-independent of row count) must continue to pass unmodified with the two extra SELECT columns.

**C. Settings grace-days field** — uses the repository's existing WordPress option read/write pattern (`get_option()`/`update_option()`), identical to every other field already on the Settings tab. No additional repository/domain query is introduced by this milestone. This plan does **not** assert an exact SQL-query count for this option access, because WordPress object-cache state determines whether `get_option()`/`update_option()` actually reaches the database — that is standard WordPress option-persistence behavior, not a new performance concern introduced by M16, and is outside the scope of the repository-query invariant (INV-M16-4).

## 27. Backward compatibility
Fully additive. No removed fields, no renamed options, no changed method signatures (only extended return-array shapes, which is a non-breaking addition consistent with every prior milestone's extension pattern, e.g. M11's `Expected_Deadline` integration into M9's `Supplier_Lead_Time_Service`). Default behavior for a fresh/unconfigured install is unchanged: grace days defaults to 0 exactly as today; suggestion source display simply degrates to "no message" if `source === 'none'`, identical to current behavior.

## 28. Rollback strategy
Code-only, read-only-plus-one-option-field change — no schema, no data migration. Per the established pattern in `docs/rollback-plan.md` for comparable milestones (M7/M13/M14/M15: "code-only, nothing to reverse"), rollback is a plain code revert/redeploy; the one persisted option (`wc_io_po_delay_grace_days`) is harmless to leave in place even after a rollback (it simply becomes unreachable via UI again, exactly as it is today pre-M16), and safe to leave at whatever value it holds.

## 29. WP-M16-0 plan materialization
(For the implementation phase, after approval — not performed now.) Fetch origin → verify `main` == `origin/main` == current post-v1.32.0 head → create feature branch `feature/m16-po-expected-date-delay-transparency` from `main` → create `docs/milestones/m16-implementation-plan.md` → materialize this entire approved plan (Parts A–G) verbatim → commit that file alone → treat it as immutable → begin implementation.

## 30. Remaining work packages in implementation order
1. **WP-M16-1**: Extend `Expected_Date_Suggestion_Service` return shape (+`sample_count`, +`average_days`, per BR-M16-2) and its unit tests.
2. **WP-M16-2**: Render suggestion-source message on New PO screen (`PO_Admin`) using `sample_count`/`average_days` per BR-M16-1 + tests.
3. **WP-M16-3**: Add grace-days field to `Settings` (getter + the exact validate-or-preserve `save_from_post()` logic of BR-M16-4) + unit tests (table-driven, all cases in section 31).
4. **WP-M16-4**: Render grace-days field on the Settings tab (`Plugin` class) + integration test of the save round-trip, including invalid-input-preserves-prior-value cases.
5. **WP-M16-5**: Extend `query_open_lines()` SELECT + drilldown template columns in the fixed BR-M16-8 order + tests.
6. **WP-M16-6**: Full regression pass on the feature branch (see sections 31–37) + documentation updates (section 38) + Level A completion review + freeze/readiness artifact committed + feature branch pushed to `origin`, per the Definition of Done in section 40 (no merge to `main`).

## 31. Unit-test plan
- `Expected_Date_Suggestion_Service`: assert `sample_count` and `average_days` are both present and correct (matching `(int) round( $stats['average_days'] )`) when `source === 'observed'`; assert both are `null` when `configured`/`none`; assert no additional query is issued (mock/count `Supplier_Lead_Time_Service` calls); assert `days` is unchanged/unaffected by the new fields (no accidental coupling between the resolved-suggestion value and the evidence fields).
- `Settings`: table-driven test of `save_from_post()` against BR-M16-4's exact contract — missing field preserves prior value; `-5` preserves prior value (never becomes `5` or `0`); `366` preserves prior value; `"abc"` preserves prior value; `"1.5"` preserves prior value; `"0"` saves `0`; `"365"` saves `365`; a mid-range valid integer saves that integer. Assert the previously-stored value is read via `get_option()` before the decision, not a hardcoded fallback.
- Architecture guard additions: a small assertion (can live in the existing `test-inventory-position-architecture.php` or a new adjacent test) confirming `query_open_lines()`'s SELECT list includes `supplier_name` and `po_status` aliases, and that no new file calls the open-lines repository methods.

## 32. Integration-test plan
- New PO screen: render with a supplier that has usable observed stats → assert the observed message renders with the correct `sample_count`/`average_days` (not `days`); render with a supplier lacking observed stats but a configured default → assert the configured message renders with `days`; render with neither → assert no message (existing behavior).
- Settings round-trip: submit the settings form with a valid grace-days value → assert `PO_Delay::grace_days_from_option()` reflects it immediately (no cache/stale-read); submit an invalid value (negative, `>365`, non-numeric) against a previously-saved value → assert the stored option is unchanged and the re-rendered field shows the preserved value, not the rejected input; submit with the field omitted entirely → assert the stored option is unchanged; submit `0` → assert it saves and persists as `0` (not treated as empty).
- Drilldown: extend `tests/integration/inventory-position/test-inventory-position-list-table.php` with assertions that the rendered mini-table (a) includes the correct supplier name (matching `supplier_name_snapshot`, including on an archived supplier per BR-M16-6) and correct status label (matching `PO_Statuses::label()`) for each contributing line, and (b) renders the seven columns in the exact fixed order from BR-M16-8 (PO number, Supplier, Status, Outstanding, Expected date, Confidence, Delayed) — mirroring the existing `test_drilldown_shows_delayed_indication` style.

## 33. Architecture guards
- `tests/unit/inventory-position/test-inventory-position-architecture.php` must continue to pass unmodified (INV-M16-3).
- Add/extend one assertion confirming no file outside `Settings` calls `update_option( WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS, ...)` (INV-M16-2), following the same grep-based pattern used by other sole-mutator guard tests in the suite.

## 34. Performance tests
No new performance test class required for the repository-query paths (A/B in section 26): the existing `test_position_query_count_bounded_for_twenty_plus_rows` assertion (≤2 SELECTs) is the regression gate for the drilldown change and must be re-run, not rewritten, as part of freeze (INV-M16-4). The Settings grace-days field (C in section 26) is not subject to a query-count performance test — WordPress option persistence via `get_option()`/`update_option()` is not asserted at a specific query count, consistent with how every other existing field on the same Settings tab is (and is not) tested.

## 35. Manual/browser acceptance
- Create a new PO for a supplier with ≥2 prior fully-received POs → confirm the observed-history suggestion message renders with correct order count/avg days.
- Create a new PO for a supplier with only a configured default → confirm the configured-default message renders.
- Visit Settings tab → change grace-days value → save → confirm success notice and that the new value persists on reload.
- Open Inventory Overview, expand a row's drilldown with ≥1 open PO line → confirm Supplier and Status columns render correctly, including for a line whose supplier has since been archived.
- Confirm no regression: existing drilldown columns (PO number link, outstanding, expected date, confidence, delayed badge) still render exactly as before, in the fixed order PO number → Supplier → Status → Outstanding → Expected date → Confidence → Delayed.
- Submit an invalid grace-days value (e.g. `-5`, `400`, `"abc"`) on the Settings tab → confirm the previously stored value is preserved and re-displayed (not silently coerced into range, not reset to a default).
- Submit `0` as the grace-days value → confirm it saves and persists as `0`.

## 36. Regression requirements
Full M1–M15 focused suite green, unit suite green, integration suite green — no prior milestone's tests may be touched (only the specific files listed in section 23, plus their own test files).

## 37. CI/test-discovery requirements
New/modified test classes must be verified discovered via `--list-tests` against the default `run-phpunit.sh` filter (per the documented convention — do not infer from the regex alone), consistent with the M13/M14/M15 freeze procedure. `scripts/release-audit.sh --development` and GitHub Actions (`ci.yml`, `tests.yml`) must be green. `failOnRisky` must remain satisfied (be mindful of the documented DOING_AJAX test-isolation convention — filter-based simulation only, never a raw `define('DOING_AJAX', true)` — though this milestone doesn't touch AJAX at all, so it's not expected to interact with that guard).

## 38. Documentation deliverables
- Update `docs/admin-guide-suppliers.md` if the grace-days/suggestion-source UI is documented there (check for an existing "Not Yet Available" list entry to remove/adjust — note the "Supplier merge tool" entry stays, as merge is explicitly out of scope for M16).
- Update any purchasing/PO admin guide describing the New PO screen to mention the new suggestion-source hint.
- Update `CHANGELOG.md` / `readme.txt` per standard milestone convention.
- Fix the stale filter-description text in `tests/README.md` and `docs/testing.md` while touched, as low-risk incidental doc hygiene (optional, not a hard requirement of this milestone — flag if deferred).

## 39. Acceptance criteria
- All three surfaces (suggestion source, grace-days settings, drilldown columns) behave per BR-M16-1 through BR-M16-9, including the exact grace-days validate-or-preserve contract (BR-M16-4) and the fixed drilldown column order (BR-M16-8).
- All invariants INV-M16-1 through INV-M16-7 hold, verified by tests.
- Zero schema change, DB_VERSION unchanged at 10.
- Full test suite green (unit + integration + M1–M16 focused filter), 0 failures/errors/risky, on the M16 feature branch.
- `release-audit.sh --development` green; GitHub Actions green on the feature branch/draft CI PR.

## 40. Definition of Done
Per the Level A completion contract (Part F), M16 is Done **on the M16 feature branch, without requiring a merge to `main`**:
- Implementation of all three surfaces (WP-M16-1 through WP-M16-5) is complete and matches BR-M16-1 through BR-M16-9 and INV-M16-1 through INV-M16-7.
- `docs/milestones/m16-implementation-plan.md`, committed verbatim at WP-M16-0, remains immutable and unedited.
- All gates in section 37 are green **on the feature branch**: full test suite (unit + integration + M1–M16 focused filter) green with 0 failures/errors/risky, M16 test classes proven discovered via `--list-tests`, `release-audit.sh --development` green, GitHub Actions green on the feature branch or its draft CI PR.
- A Level A completion review has been performed and passed.
- A freeze/readiness artifact (mirroring `docs/checklists/m9-release-readiness.md` through `m15-release-readiness.md`) is committed to the feature branch.
- The feature branch is clean (no uncommitted changes) and pushed to `origin`.
- No unresolved M16 blocker remains open.
- **Explicitly NOT required for Done**: merge to `main`, an annotated tag, a GitHub Release, or deployment — those are WP6 release-train decisions, made separately per Part G, not part of M16 completion.

## 41. Risks and mitigations
- **Risk**: Settings-tab field validation is implemented as `absint()`-style silent coercion instead of the explicit validate-or-preserve contract, causing e.g. `-5` to silently become `5`. **Mitigation**: BR-M16-4 spells out the exact contract (missing/non-numeric/non-integer/out-of-range all preserve the prior value; nothing is clamped or coerced) and section 31/32 include a table-driven test enumerating every case.
- **Risk**: Suggestion-source message wording confuses merchants unfamiliar with "observed vs. configured," or the message conflates the resolved `days` value with the `average_days` evidence value. **Mitigation**: keep language plain per BR-M16-1's exact wording, using `sample_count`/`average_days` (never `days`) for the observed message per BR-M16-2; validate against admin-guide review during freeze.
- **Risk**: Drilldown column addition silently breaks the existing `test_position_query_count_bounded_for_twenty_plus_rows` if a future implementer adds a join instead of using already-available columns, or renders columns out of the fixed BR-M16-8 order. **Mitigation**: explicit note in section 10/23 that `supplier_name_snapshot`/`status` are already present in the joined row — no new join needed; integration test asserts the exact column order.

## 42. Explicit deferred work
Supplier merge tool, storewide/cross-supplier spend rollup, Inventory Coverage/Forecast (blocked on missing sales-velocity data), Reservations/Available Stock (blocked on D16 — no concrete consumer), Inbound Shipment entity (no concrete need), Warehouse location hierarchy (defensive registration only), REST/Store API/GraphQL (blocked on D16), PO-number allocation atomicity (ADR-0002, no new evidence), Plugin god-class refactor, PHPCS cleanup — all explicitly out of scope for M16, see Part C for individual rationale.

## 43. Commit strategy
WP-M16-0 (plan file) as its own immutable commit on the M16 feature branch; then one commit per work package (WP-M16-1 through WP-M16-5) or a small number of logically grouped commits, following the same granularity precedent as M13–M15; final freeze/readiness-doc commit at WP-M16-6. All commits land on the feature branch; per section 40, merging that branch to `main` is a separate, later WP6 release-train decision, not part of this commit strategy or of M16 completion.

## 44. Stop conditions
Stop and escalate if: the New PO template's suggestion rendering turns out to require duplicating resolution logic rather than displaying the already-returned `source` field (would indicate the architecture assumption in section 10 is wrong); if `query_open_lines()`'s existing SELECT cannot in fact reach `supplier_name_snapshot`/`status` without a new join (would invalidate INV-M16-4); if the Settings tab's `save_from_post()` cannot cleanly accept one more field without broader refactor; if the validate-or-preserve contract in BR-M16-4 cannot be implemented without a broader rework of `save_from_post()`'s current per-field pattern.

## 45. Final implementation-report contract
On completion (feature branch, per section 40 — not contingent on any merge), the implementation agent reports: which BRs/INVs were satisfied vs. any deviation with rationale (explicitly confirming the BR-M16-4 validate-or-preserve behavior was implemented exactly, not via `absint()`-style coercion, and that the BR-M16-8 column order was implemented exactly), final test counts (unit/integration/total, all green), confirmation of `--list-tests` discovery, confirmation DB_VERSION remains 10, confirmation no new files were created beyond what section 24 anticipates (or explicit note if one became necessary), GitHub Actions status on the feature branch/draft CI PR, and the freeze/readiness checklist status. The report explicitly does not include a merge, tag, release, or deployment status, as none of those are in scope for M16 completion.

---

# PART F — Lifecycle / Risk Classification

**LEVEL A — completion review + freeze.**

Zero domain/operational mutation (zero PO mutation, zero PO-line mutation, zero receipt mutation, zero stock mutation, zero cost mutation, zero Inventory Position mutation, zero storefront mutation); one existing settings-option write (`WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS`, through `Settings::save_from_post()`), which is not classified as a new domain mutation — it follows an already-established, low-risk, already-audited pattern (the existing Settings tab). No schema/migration, no destructive operation, no security/capability change, no public API, no storefront behavior change, no transaction/concurrency correctness concern, no new persistent domain. Level B is not warranted — none of the strong Level B indicators from the lifecycle doc apply.

---

# PART G — Post-M16 Release/Train Recommendation

**Recommendation: (C) Decide release timing only after M16 freeze**, per the milestone-lifecycle default. Per section 40, "M16 freeze" means the Level A completion contract satisfied on the M16 feature branch (implementation complete, all gates green, freeze/readiness artifact committed, branch pushed) — it does not itself require or imply a merge to `main`; merge, tag, release, and deploy are all WP6-time decisions made after freeze, not part of freeze. Nothing about this milestone trips a Release Trigger (`docs/process/milestone-lifecycle.md`: schema/migration, public API, ownership-boundary change, storefront behavior change, security fix, breaking change — none apply here), so M16 is eligible to either open a new feature train (continuing with, e.g., the supplier merge tool or storewide spend rollup as M17) or release standalone as a small 1.33.0, whichever the maintainers prefer once M16 is frozen and its actual size/risk is confirmed in practice. Given its small size, bundling into a short train with one follow-up milestone (most likely the supplier merge tool, now that it's the clear next A-class candidate) is a reasonable default to suggest at freeze time — but that decision, including the merge itself, is explicitly deferred to WP6, not made now.

---

M16 PLAN CORRECTED — READY FOR IMPLEMENTATION
