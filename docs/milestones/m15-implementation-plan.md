# Milestone M15 Implementation Plan — Supplier Spend Summary

**Status:** Approved. This document is the immutable implementation specification for Milestone M15, materialized from the approved Plan-mode document before any implementation code was written, per `docs/process/milestone-lifecycle.md` WP0 step 5 / Permanent Repository Rule 1. Once committed, this file is never edited, replaced, or repurposed — any future freeze/readiness record belongs in `docs/checklists/m15-release-readiness.md`, not here.

## Materialization note

Materialized verbatim from the approved Plan-mode document
(`milestone-m15-discovery-purrfect-grove.md`, amended after user review to:
correct the canonical implementation base from the historical M14 freeze tip
to commit `0780ba7` — the accepted post-M14-freeze DOING_AJAX test-isolation
remediation; make the version-surface bump explicit, syncing the plugin
header, `WC_INVENTORY_OVERVIEW_VERSION`, and `readme.txt` `Stable tag` all to
`1.32.0`; define `po_count` precisely as `COUNT(DISTINCT po.id)` scoped per
currency row, including a required mixed-line-currency test fixture; and
correct the stop-condition language so it no longer references composing
through `Purchase_Orders::list()`, since the new aggregate is a
self-contained method) after pre-flight verification on
`feature/m14-supplier-order-history` at commit `0780ba7` (plugin `1.31.0`,
`DB_VERSION` `10`, last released `v1.29.0`, M13 and M14 both frozen Level A /
CI green / unreleased, `main` unchanged at the `v1.29.0` line).
`feature/m15-supplier-spend-summary` branches from this commit. No design
amendments at materialization time — this is the plan exactly as approved.

# M15 — Supplier Spend Summary (Definitive Plan)

## Context

M13 (Printable Purchase Order, dev `1.30.0`) and M14 (Supplier Order History, dev `1.31.0`) are both implemented, Level A frozen, CI-green, and **unreleased** on `feature/m14-supplier-order-history` (`main` is still at tagged `v1.29.0`). Per both the M14 plan's own §31 "Deferred work" and `docs/checklists/m14-release-readiness.md`, the explicitly authorized next step is to decide: continue the train with M15, or close and release M13+M14. This document performs that discovery from the actual current repository state and produces the complete, implementation-ready M15 specification.

---

## PART A — Verified baseline

| Check | Result |
|---|---|
| Repo path | `/opt/biopentra/dev/wc-inventory-overview` — confirmed |
| Branch | `feature/m14-supplier-order-history`, tracking `origin/feature/m14-supplier-order-history`, clean working tree |
| Plugin version | `1.31.0` (confirmed in `wc-inventory-overview.php` header + `WC_INVENTORY_OVERVIEW_VERSION`) |
| `DB_VERSION` | `10` (`includes/class-wc-inventory-overview-install.php:15`) |
| Last released | `v1.29.0`, tagged, `main` sits exactly on that line (`67321bb`) — no M13/M14 commits on `main` |
| M13 | Complete, Level A frozen, unreleased — `docs/milestones/m13-implementation-plan.md` immutable, `docs/checklists/m13-release-readiness.md` present |
| M14 | Complete, Level A frozen, unreleased — `docs/milestones/m14-implementation-plan.md` immutable, `docs/checklists/m14-release-readiness.md` present |
| M14 CI evidence | Full unit 322/322, M1–M14 focused 613/613, full integration 302/302, M14-only 30/129 — all green, 0 failures/errors/risky |
| GitHub Actions | `.github/workflows/ci.yml`, `release.yml`, `tests.yml` all present |
| M15 plan/branch | Confirmed **did not exist** prior to this materialization — only prospective mentions in `CLAUDE.md`, the M14 plan, and its freeze checklist |
| DOING_AJAX item | **Resolved in two steps, both maintenance-only, neither part of M15 product scope.** (1) An initial hardening landed on the M14 freeze tip (commit `4e0c5fb`, "harden capability-gate test against leaked DOING_AJAX") forcing the die-handler filters to throw for one test's duration. (2) A subsequent, separate, accepted remediation — commit **`0780ba7`** — replaced the underlying irreversible `define( 'DOING_AJAX', true )` in `tests/integration/expected-delivery/test-expected-delivery-renderer.php` with reversible `wp_doing_ajax` filter state, and proved test-order isolation directly (targeted A→B, B→A, and repeated-execution runs). Production code, plugin version (`1.31.0`), and `DB_VERSION` (`10`) were unchanged by either commit. |
| **Canonical M15 implementation base** | **`0780ba7`** — the accepted post-M14-freeze remediation tip. M15 branches from this exact commit, not from the historical M14 freeze commit alone and not from `4e0c5fb` directly. The M14 plan file remains immutable; this remediation is recorded as a separate maintenance commit layered on top of the M14 freeze. |

**Documentation gap found (not a pre-flight mismatch, a discovery finding):** `docs/architecture-audit.md` and `docs/ARCHITECTURE_BASELINE_v1.24.0.md` were never updated for M14 — no M14 section/mention in either, and `architecture-audit.md`'s M13-era "Recommended follow-ups" text still incorrectly says order-history reporting "remains unaffected and still open." `CLAUDE.md`, `CHANGELOG.md`, `readme.txt`, and `docs/admin-guide-suppliers.md` were all correctly updated for M14. This is carried into M15's documentation deliverables below rather than treated as a stop condition — it's a docs-currency debt, not an architecture/product mismatch.

No material mismatch. Proceeded to discovery.

---

## PART B — Discovery findings

**Live "Not Yet Available" backlog** (`docs/admin-guide-suppliers.md`, cross-checked against M14 plan §31): after M14 removed order-history reporting from this list, exactly two items remain:
1. **Supplier spend analysis** — needs a currency-normalization policy decision, explicitly named in the M14 plan as "the natural next milestone once order history exists to display it against."
2. **Supplier merge tool** — "a dedicated merge tool is planned"; Level B (cross-table FK reassignment risk).

**Everything else in the historical backlog remains blocked/rejected for reasons already on record and unchanged since M9–M14:** PO delay grace-days Settings UI / suggestion-source transparency / Inventory Position drilldown (all repeatedly called "too small alone" / "unrelated subsystem polish"); Expected Delivery confidence from supplier history (would trigger a storefront-behavior-change audit — an immediate release trigger, not a train addition); Inventory Coverage/Forecast (architecturally blocked — no sales-velocity data collected); Reservations (forbidden by D16 until a concrete consumer exists); Inbound Shipment entity and Warehouse location hierarchy (new-domain initiatives, no schema/demand, Warehouse locations is explicitly a defensive ownership registration only — `docs/OWNERSHIP.md`); REST/Store API/GraphQL (forbidden by D16 — no named consumer, confirmed zero routes registered anywhere in the codebase); PO-number allocation atomicity (formally accepted optimization gap, `docs/adr/0002-po-number-allocation-concurrency.md` — "not M2 scope unless product prioritizes it," an internal robustness item, not a merchant capability); Plugin god-class refactor / PHPCS debt (maintenance debt, non-blocking, not a capability).

**Repo-wide term search** (deferred/future/planned/TODO/FIXME/follow-up/accepted limitation) confirms zero literal code-level TODO/FIXME anywhere (a maintained repository convention, re-verified at every milestone) — all deferrals live in `docs/milestones/*.md` prose. One M14-introduced **accepted limitation** worth carrying forward as context (not M15 scope): `Purchase_Orders::list()` supports only a single-column `ORDER BY`, so Supplier Order History's stated `order_date DESC, id DESC` tie-break isn't actually enforced (`CHANGELOG.md:18`). Not touched by M15 — M15's own aggregate query does not sort by PO at all.

**No existing supplier-level spend aggregate exists anywhere in the codebase** (confirmed by grep across `purchase-orders.php`, `costing.php`, `supplier-lead-time-service.php`, product-profitability query code). The only per-PO value primitive is M14's `Purchase_Orders::values_bulk()`, deliberately page-scoped (current page's PO ids only) and never summed across POs (INV-M14-2) — it cannot answer "what has this supplier cost us in total," only "what did this one page of orders cost."

---

## PART C — Candidate comparison

| Candidate | Value/frequency | Reuse of existing data | Architecture fit | Size/risk | Schema | Verdict |
|---|---|---|---|---|---|---|
| **Supplier spend summary** (bounded, per-currency) | High — answers a real recurring merchant question ("what have we spent with this supplier") currently requiring manual PO-by-PO addition | Full reuse: same tables M14 already reads (`wc_io_purchase_orders`/`wc_io_purchase_order_lines`), same `Purchase_Orders` read-owner class, same Supplier detail screen, same capability gate | Low-risk extension — one new grouped-aggregate read method + one new thin Internal service + one new render section, exact same pattern as M9/M14 | Small; read-only; no mutation | None | **Selected — see Part D** |
| Supplier merge tool | Real but infrequent (duplicate cleanup, not a recurring workflow) | N/A — new mutation surface | New domain initiative — cross-table FK reassignment across Suppliers/POs/Receipts/Movements, needs an ADR-level ownership decision, audit trail design | Large; Level B; destructive-adjacent (data consolidation) | Possibly (audit/reassignment log) | Correct next bounded milestone of a **separate** initiative — not M15 |
| PO delay grace-days Settings UI | Low-medium, narrow | Reuses existing `grace_days` concept | Trivial UI-only extension | Tiny | None | Legitimate low-risk extension, but not the *next meaningful gap* — repeatedly deferred as "too small alone"; bundling it into M15 would violate "do not bundle unrelated backlog items" |
| Suggestion-source transparency | Low, cosmetic | Reuses M10 data | Trivial | Tiny | None | Same as above — too small alone, not selected |
| Inventory Position supplier/incoming drilldown | Medium, navigation convenience | Reuses M3 data | Small UI extension | Small | None | Legitimate future candidate, unrelated domain (Position, not Supplier/PO value) — not selected, no bundling |
| Expected Delivery confidence from supplier history | Medium | Would reuse M9 stats | **Storefront behavior change** — an explicit Release Trigger per `docs/process/milestone-lifecycle.md`, forces immediate WP6 release rather than train continuation | Medium risk, customer-facing | None | Not appropriate for train continuation right now |
| Inventory Coverage/Forecast | High long-term value | **Blocked** — no sales-velocity data collected anywhere in the plugin | New domain initiative | Large | Likely yes | Not implementable cleanly today; would need its own data-collection milestone first |
| Reservations/Available Stock | Medium | N/A | **Forbidden by D16** — no concrete consumer named | New domain | Large | Not selected |
| Inbound Shipment entity | Low evidenced demand | N/A | New domain, D10 reserves this as a later additive option only | Large | Yes | Not selected — no trigger for it now |
| Warehouse location hierarchy | None evidenced | N/A | Defensive ownership registration only, explicitly "not planned work" | Large | Yes | Not selected |
| REST/Store API/GraphQL | None evidenced | N/A | **Forbidden by D16** — no named consumer anywhere | New domain/API surface | N/A | Not selected |
| PO-number allocation atomicity | Internal robustness, not a merchant capability | N/A | Formally accepted gap (ADR-0002); a dedicated concurrency-hardening change, not a "next capability" | Small-medium but concurrency-sensitive | None | Not a capability milestone; revisit only if product prioritizes it explicitly |
| Plugin god-class refactor / PHPCS debt | Maintenance only | N/A | Non-blocking tech debt | N/A | None | Not a merchant capability — not M15 |

**Distinguishing low-risk extension from new-domain initiative:** Supplier spend summary is a **low-risk extension** — it adds no new domain concept (spend is a derived read over existing PO/line data, exactly like Observed Lead Time and Order History before it), no new table, no new mutation, no new ownership boundary. Supplier merge, Coverage/Forecast, Reservations, Inbound Shipment, and Warehouse hierarchy are all **new domain initiatives** and are correctly excluded from this already-accumulating M13+M14(+M15) train.

---

## PART D — Recommended M15 scope

**M15 = Supplier Spend Summary** — a bounded, read-only, per-currency total-ordered/total-received value summary for a single supplier's entire order history, surfaced on the existing Supplier detail admin screen.

**Rationale:** This is the one candidate that is simultaneously (a) explicitly flagged by M14 itself as the natural next step, (b) architecturally trivial given M14's read model, (c) answers a real, currently-unsupported merchant question (manual PO-by-PO addition today), and (d) resolves M14's stated blocker — the currency-normalization policy decision — by choosing the narrowest defensible policy: **never blend currencies; show one subtotal row per currency the supplier has actually been ordered in.** This is not a new capability invented for its own sake; it closes the one concretely-named gap in the current backlog.

M14's per-PO `values_bulk()` cannot be reused directly for this — it is deliberately page-scoped (bounded to ~20 PO ids at a time) and would require pulling a supplier's entire PO-id list into PHP and summing client-side, which does not scale and duplicates aggregation logic that the database already does better. M15 instead adds one new **true SQL aggregate** (`GROUP BY currency`, `SUM()` at the database level) — a single query regardless of how many hundreds of POs a supplier has, mirroring the O(1)-query discipline already established by `Supplier_Lead_Time_Service::get_stats_for_supplier()` (M9) and `Purchase_Orders::values_bulk()` (M14).

---

## Problem statement

A merchant viewing a supplier's detail page can now see that supplier's observed lead time, on-time rate, and a paginated list of individual POs each with their own ordered/received value (M9, M11, M14). To answer "how much have we actually spent with this supplier, and how much of that has actually arrived," the merchant must manually page through Order History and add every row's value by hand — the plugin holds all the necessary data but never totals it. This is the exact repeated-manual-task category the M15 discovery brief asks to find, and it is the one gap M14 itself named as unresolved.

## Goals

- Add one new, narrowly-scoped read: total ordered value and total received value ("PO Cost") for a supplier, computed correctly, grouped by currency (never blended or FX-converted), over the supplier's **committed** order history.
- Surface it as a compact summary panel on the existing Supplier detail screen, positioned above the Observed Lead Time panel (spend-to-date is the more immediate "how are we doing with this supplier financially" fact; performance stats and detailed order history follow below it).
- Reuse the exact ownership/query-cost discipline already established by M9 and M14: one new Internal service, one new additive read method on the existing `Purchase_Orders` read owner, zero mutation, zero schema change, zero new public API.

## Non-goals

- No cross-supplier / storewide spend rollup or "top suppliers" view (would need its own aggregation surface and is out of scope for a single-supplier detail-screen panel).
- No FX conversion or single blended total across currencies — resolved as "never blend" (see Business Rules), not deferred.
- No trend charts, time-bucketing, date-range filtering, or period-over-period comparison.
- No changes to PO write paths, lifecycle, statuses, or events.
- No changes to `Supplier_Order_History_Service`, its per-PO row values, or its pagination — M15 adds a sibling summary, it does not modify M14's code.
- No Suppliers-list-table column (unlike M12's pattern) — this stays a single-supplier detail-screen panel, matching M14's own scope discipline (Order History is also detail-screen-only, not list-table-wide). A list-wide spend column, if ever wanted, is a distinct future decision, not bundled here.
- No new capability, hook, filter, or public API.
- No supplier merge, grace-days Settings UI, suggestion-source transparency, Position drilldown, Expected Delivery changes, Coverage/Forecast, Reservations, Inbound Shipment, Warehouse locations, REST/API exposure — all explicitly out of scope, per Part C.

## Current architecture (relevant slice)

- `WC_Inventory_Overview_Purchase_Orders` (`includes/class-wc-inventory-overview-purchase-orders.php`) — sole read/write owner of `wc_io_purchase_orders`. Already holds `line_total()` (single-PO) and `values_bulk()` (M14, page-scoped multi-PO). No supplier-wide aggregate exists.
- `wc_io_purchase_order_lines` — holds `po_id`, `qty_ordered`, `qty_received`, `unit_cost`, `currency` (line-level currency, already used by M14's `values_bulk()` grouped query).
- `WC_Inventory_Overview_Supplier_Order_History_Service` (M14, Internal) — sole consumer allowlist enforced by `tests/unit/supplier-order-history/test-supplier-order-history-architecture.php`; M15 does not touch this class.
- `WC_Inventory_Overview_Purchasing_Page::render_supplier_detail()` (`includes/class-wc-inventory-overview-purchasing-page.php:254`) — already gated by `current_user_can( 'manage_woocommerce' )` before any supplier-specific rendering; calls `render_observed_lead_time()` then `render_order_history_section()` at lines 360–361.
- `WC_Inventory_Overview_PO_Statuses` — status constants/labels; M15 needs the "committed" status subset (see Business Rules).

## Ownership model

| Role | Owner |
|---|---|
| Business rule (which statuses count as spend; currency grouping policy) | New `WC_Inventory_Overview_Supplier_Spend_Service` (Internal, not Public — D16) |
| Read | `WC_Inventory_Overview_Purchase_Orders::spend_summary_for_supplier()` (new additive method on the existing read owner — same pattern as `values_bulk()`) |
| Mutation | N/A — zero mutation, no mutation owner needed |
| Presentation | `WC_Inventory_Overview_Purchasing_Page::render_spend_summary_section()` (new private method, same class/pattern as `render_order_history_section()`) |

No duplicated SQL/business-rule ownership: `Supplier_Spend_Service` calls only `Purchase_Orders::spend_summary_for_supplier()` — no direct `$wpdb`, no reading `Purchase_Order_Lines`/`Goods_Receipts`/`Receipt_Lines`/`Receipt_Costs`/`Suppliers` directly (mirrors INV-M14-3, enforced by an architecture guard test).

**Impact on other owners (all read-only / none):**
- Suppliers, Supplier Order History, Purchase Orders, PO Print, Goods Receipts, PO Delay, Inventory Position, Supplier Lead Time, Supplier On-Time Rate, Expected Deadline, Expected-Date Suggestion, Expected Delivery, Storefront — **zero code changes** to any of these. M15 is strictly additive (one new method on `Purchase_Orders`, one new service, one new render section, one new query-count assertion). No existing SQL, business rule, or ownership boundary is duplicated or touched.

## Domain/data flow

```
render_supplier_detail()
  → render_spend_summary_section( $supplier_id )
      → Supplier_Spend_Service::get_summary( $supplier_id )
          → Purchase_Orders::spend_summary_for_supplier( $supplier_id, $statuses )
              → SQL: JOIN wc_io_purchase_order_lines pol ON wc_io_purchase_orders po,
                     WHERE po.supplier_id = ? AND po.status IN (committed statuses),
                     GROUP BY pol.currency
                     (po_count = COUNT(DISTINCT po.id) within each currency group — see BR-M15-5)
          ← array<{currency, ordered_total, received_total, po_count}>
             po_count is scoped to that currency row only (never a supplier-wide total, never summed across rows)
      ← same array (service is a thin pass-through with the business-rule constant)
  → render one row per currency, or an empty-state message
```

## Exact business rules

**BR-M15-1 — "Committed spend" status set.** Ordered/received totals include only POs whose status is one of `placed`, `partially_received`, `received`, `closed_short` — i.e. the same "actually placed" set M13's print feature already uses (excludes `draft`, since a draft is not a commitment) **and additionally excludes `cancelled`** (a cancelled PO was never fulfilled and is not real spend — a new precedent for this milestone, distinct from M14's Order History which is deliberately status-inclusive for a full audit trail, not a spend total). This is a genuinely new business decision (not reused from M13/M14 verbatim) and is recorded as invariant INV-M15-1.

**BR-M15-2 — Never blend currencies.** Totals are grouped by the PO line's own `currency` column (the same `wc_io_purchase_order_lines.currency` column M14's `values_bulk()` already reads per line, though `values_bulk()` itself groups by `po_id`, not currency — M15's aggregate is the first read to group by this column), one row per currency actually present in that supplier's committed history. No FX conversion, no cross-currency sum, ever (INV-M15-2 — mirrors INV-M14-2's "never blend" precedent, extended from per-PO to per-supplier scope). This is the resolution of M14's stated currency-normalization blocker: the policy is "don't normalize," not "normalize later."

**BR-M15-3 — Ordered vs Received value formula**, identical arithmetic to M14, applied as a true aggregate instead of per-PO:
- Ordered value = `SUM(qty_ordered × unit_cost)` over lines belonging to committed-status POs.
- Received value ("PO Cost") = `SUM(qty_received × unit_cost)` over the same line set — naturally contributes 0 for lines not yet received; never re-derived from Goods Receipts/`Receipt_Costs` (keeps Goods Receipts/landed-cost domain untouched, same as M14).

**BR-M15-4 — Empty state.** A supplier with zero committed-status POs (e.g. only drafts/cancelled, or genuinely no orders yet) shows a plain "No committed purchase orders yet for this supplier." message — no zero-value row, no currency guess.

**BR-M15-5 — `po_count` is precisely `COUNT(DISTINCT po.id)` scoped to that currency row.** Because the aggregate groups by the PO **line's** own `currency` (not the PO header's currency), `po_count` for a given currency row is the count of distinct committed POs that have **at least one line** in that currency — computed as `COUNT(DISTINCT po.id)` within that `GROUP BY currency` bucket, a natural consequence of relational grouping, not a separate query. Precisely:
- It is **not** a supplier-wide unique-PO count.
- It is **not** meant to be summed across a supplier's currency rows (a PO with lines split across two currencies is correctly counted once in each of those two rows — it is not double-counted within a single row, and the two rows' counts are not meant to add up to "total distinct POs").
- It is **not** an all-status count — only the four committed statuses (BR-M15-1) ever contribute.
- UI label "Committed POs" means exactly this: the number of distinct committed purchase orders contributing value to *this* currency's row.

This is a genuinely new, deliberately precise definition for M15 (not reused verbatim from M14, which never counts POs per currency) — recorded as part of INV-M15-2's scope and verified by a dedicated mixed-line-currency fixture.

## New invariants

- **INV-M15-1** — Spend totals include only `placed`/`partially_received`/`received`/`closed_short` POs; `draft` and `cancelled` are always excluded. Enforced by unit tests with mixed-status fixtures.
- **INV-M15-2** — Spend totals are never summed or converted across currencies; one row per currency, always. Enforced by an architecture-guard regex scan (no FX/exchange-rate token in the new service or method) plus a unit test with a mixed-currency supplier fixture.
- **INV-M15-3** — Zero mutation: `Supplier_Spend_Service` and `spend_summary_for_supplier()` contain no write tokens (`->insert(`, `->update(`, `->delete(`, `INSERT INTO`, `UPDATE `, `DELETE FROM`). Enforced by an architecture guard test (same technique as `test-supplier-order-history-architecture.php`).
- **INV-M15-4** — Sole-consumer boundary: `Supplier_Spend_Service::` is called only from `Purchasing_Page` (grep-based allowlist test, same technique as INV-M14-3's enforcement).

## Schema impact

- Schema change: **No.**
- `DB_VERSION` impact: **None — stays `10`.**
- Migration: **None.**
- Persistent data introduced: **None** — fully derived at read time, same as Order History and Observed Lead Time.
- Existing data mutated: **No.**
- Transaction requirements: **None** — a single `SELECT`, no write, no transaction needed.
- Concurrency concerns: **None** — read-only aggregate; no locking implications.
- Rollback implications: **None beyond standard code rollback** — no data migration to reverse.

## Development version target

`1.32.0` (next minor after M14's `1.31.0`, following this train's established one-milestone-one-minor-version convention).

**Version contract (explicit — deviates from the M13/M14 pattern by instruction for this milestone):** at the version-bump work package, update all three version surfaces consistently to `1.32.0`:
- plugin header `Version:` in `wc-inventory-overview.php`
- `WC_INVENTORY_OVERVIEW_VERSION` constant in `wc-inventory-overview.php`
- `readme.txt` **`Stable tag`**

Unlike M13/M14 (which deliberately left `Stable tag` at `1.29.0` while `Version:`/the constant advanced), M15 keeps all three in sync at `1.32.0` — this repository does not distribute via the public WordPress.org directory (updates are served via `includes/class-github-updater.php`), so `Stable tag` carries no auto-push-to-users implication here; do not reintroduce a lagging-`Stable tag` convention for this milestone. `DB_VERSION` is unaffected and remains `10`. This is a version-surface bump only — it does **not** constitute a release: no git tag is created, no GitHub Release is published, and `main`/the last actual public release remain at `v1.29.0` throughout M15, documented separately in `CHANGELOG.md`/`readme.txt`'s changelog section exactly as M13's `1.30.0` and M14's `1.31.0` entries were ("not individually released").

## Public API impact

None. `Supplier_Spend_Service` is Internal, not Public (D16) — same classification as `Supplier_Lead_Time_Service`, `Expected_Date_Suggestion_Service`, and `Supplier_Order_History_Service`. No new hook, filter, or REST route.

## Security/capability impact

None. Reuses the existing `manage_woocommerce` gate already enforced at the top of `render_supplier_detail()` before any supplier-specific section renders (including the new one) — no new capability constant needed in `WC_Inventory_Overview_Purchasing_Caps`.

## Admin/UI behavior

New "Spend Summary" panel on the Supplier detail screen (edit view only — `$is_new` false, mirroring where Observed Lead Time / Order History already render), inserted at `render_supplier_detail()` **before** the existing `render_observed_lead_time()` call:

```php
$this->render_spend_summary_section( $supplier_id );
$this->render_observed_lead_time( $supplier_id, $supplier );
$this->render_order_history_section( $supplier_id );
```

Rendered as a small `<table class="widefat striped wc-io-mini-table">` (same CSS class as the Order History table, no new stylesheet needed), one row per currency:

| Currency | Ordered Value | Received Value (PO Cost) | Committed POs |
|---|---|---|---|
| EUR | 12,340.00 EUR | 9,870.00 EUR | 14 |

Column labels intentionally reuse M14's exact wording "Ordered Value" / "Received Value (PO Cost)" for consistency; a two-line caption clarifies scope: *"Totals include placed, partially received, received, and closed-short orders only; drafts and cancelled orders are excluded. Currencies are shown separately and never combined."* plus *"'Committed POs' counts distinct orders contributing to that currency row; a PO with lines in more than one currency may be counted in more than one row."* (BR-M15-5 — precise `po_count` semantics, exposed to the merchant, not just internally documented). Money formatting reuses the same `number_format( $amount, 2 ) . ' ' . $currency` convention as `format_po_cost_value()` in `Purchasing_Page` (private static, reused directly since it's in the same class).

No pagination needed (at most a handful of currency rows per supplier in practice — EUR/USD/SEK per `CLAUDE.md`'s stated business context). No sorting, no filtering, no AJAX.

## Production classes/files affected

- `includes/class-wc-inventory-overview-purchase-orders.php` — add `spend_summary_for_supplier( int $supplier_id, array $statuses ): array` (new public static method, additive only, no change to any existing method).
- `includes/class-wc-inventory-overview-purchasing-page.php` — add `render_spend_summary_section( int $supplier_id )` (new private method) and insert its call in `render_supplier_detail()`.
- `wc-inventory-overview.php` — add one `require_once` line for the new service file, in the same block as the `supplier-order-history-service.php` require.

## New classes/files

- `includes/class-wc-inventory-overview-supplier-spend-service.php` — new `WC_Inventory_Overview_Supplier_Spend_Service` (Internal), one method `get_summary( int $supplier_id ): array` returning `array<int,array{currency:string,ordered_total:float,received_total:float,po_count:int}>`, one row per currency actually present in that supplier's committed-status lines. `po_count` is `COUNT(DISTINCT po.id)` scoped to that row's currency only (BR-M15-5) — never a supplier-wide count, never intended to be summed across rows. Owns the `BR-M15-1` committed-status-set constant.

## Hooks/integration points

None new. No hook, filter, or admin-post action is introduced (unlike M13's `admin_post_wc_io_po_print` — M15 has no separate request/URL, it renders inline on an existing page load, same as Observed Lead Time and Order History).

## Query/performance contract

Exact query shape for `Purchase_Orders::spend_summary_for_supplier( int $supplier_id, array $committed_statuses ): array`:

```sql
SELECT pol.currency                                          AS currency,
       COALESCE( SUM( pol.qty_ordered  * pol.unit_cost ), 0 ) AS ordered_total,
       COALESCE( SUM( pol.qty_received * pol.unit_cost ), 0 ) AS received_total,
       COUNT( DISTINCT po.id )                                AS po_count
FROM   {$lines} pol
INNER JOIN {$orders} po ON po.id = pol.po_id
WHERE  po.supplier_id = %d
  AND  po.status IN (%s, %s, %s, %s)   -- committed statuses, parameterized (BR-M15-1)
GROUP BY pol.currency
```

`COUNT(DISTINCT po.id)` is evaluated **within** each `GROUP BY pol.currency` bucket by ordinary SQL semantics — a PO with lines in two currencies is counted once in each of the two resulting rows (BR-M15-5), with no second query and no PHP-side deduplication.

- Non-empty committed history: **exactly 1** query total, regardless of how many POs or lines the supplier has (proven at 200-PO, multi-currency scale) — a true database-level aggregate, not a per-page or per-PO shape. This does not inherit M14's "3-query" contract (that shape exists because Order History paginates via separate `count()`/`list()`/`values_bulk()` calls; M15 has no pagination step at all — one aggregate answers the whole question).
- Zero committed-status POs: **exactly 1** query — the same query simply returns zero rows; no separate existence-check query is needed or added.
- No N+1 under any circumstance — there is no per-PO or per-line loop in PHP; all aggregation, including the distinct-PO count, happens in SQL.
- Explicit empty-result behavior: `spend_summary_for_supplier()` returns `[]` for a supplier with no committed POs; `Supplier_Spend_Service::get_summary()` passes that through unchanged; `render_spend_summary_section()` renders the BR-M15-4 empty-state message.

## Backward compatibility

Fully additive — no existing method signature, table, hook, or return shape changes. No consumer of `Purchase_Orders`, `Supplier_Order_History_Service`, or any other existing class is affected.

## Rollback strategy

Standard code-level rollback (revert the commit(s)/branch) — no schema/data migration exists to reverse, no persisted state to clean up. Identical rollback profile to M13/M14.

## WP-M15-0 (materialization)

1. Verify the canonical feature-train head is exactly commit **`0780ba7`** — done, see Part A.
2. Branch `feature/m15-supplier-spend-summary` from that exact head (`0780ba7`) — done.
3. Materialize this approved plan verbatim to `docs/milestones/m15-implementation-plan.md`, commit alone, freeze as immutable (Permanent Rule 1) — this commit.
4. Implement per the sections above, one commit per work package (new read method → new service → new render section → tests → docs).

## Unit-test plan

New `tests/unit/supplier-spend/` directory:
- `test-supplier-spend-service.php` — `get_summary()` correctness: single-currency supplier, multi-currency supplier (rows never blended), supplier with only draft/cancelled POs (empty array), supplier with mixed committed/non-committed POs (non-committed excluded from totals), formula correctness vs. hand-computed fixtures (ordered vs received divergence when partially received).
- `test-supplier-spend-architecture.php` — mirrors `test-supplier-order-history-architecture.php`: (a) zero unapproved read tokens in the service; (b) service calls only `Purchase_Orders::spend_summary_for_supplier(`; (c) zero write tokens in both the service and the new `Purchase_Orders` method body; (d) sole-consumer allowlist — only `Purchasing_Page` may call `Supplier_Spend_Service::`.
- Extend the existing `Purchase_Orders` unit test file with `spend_summary_for_supplier()` cases: empty supplier, zero-currency-blend assertion, status-filter correctness (each excluded status individually verified absent from totals), and:
  - **Mixed-line-currency fixture (BR-M15-5, required):** one committed PO with two lines on the *same* PO — one line `currency = 'EUR'`, one line `currency = 'USD'` (the schema allows independent line-level currency; asserted structurally possible, not merely hypothetical). Assert: the PO's EUR line contributes to the EUR row's `ordered_total`/`received_total` and increments that row's `po_count` by exactly 1; the PO's USD line does the same for the USD row; the same PO's `id` therefore appears in **both** rows' `po_count`, each counted once (not twice within a row, and the two rows' `po_count` values are not asserted to sum to a "total distinct POs" figure — explicitly assert they are *not* combined anywhere in the return shape).

## Integration-test plan

New `tests/integration/supplier-spend/test-supplier-spend-admin.php` (mirrors `test-supplier-order-history-admin.php`):
- Full render through `render_supplier_detail()`: capability gate (denied for a user without `manage_woocommerce`), correct panel rendering for a seeded multi-PO/multi-currency/multi-status supplier, correct exclusion of draft/cancelled from displayed totals, empty-state message for a supplier with no committed POs, correct placement (before Observed Lead Time / Order History sections in output order).

## Architecture guards

- New guard test (unit, listed above) enforcing INV-M15-1 through INV-M15-4 via source-scanning, same technique as the existing M9/M14 architecture guards — no changes needed to any existing guard test.

## Performance tests

New `tests/integration/supplier-spend/test-supplier-spend-performance.php`:
- `test_200_pos_multi_currency_costs_exactly_one_query()` — seed 200 POs across 3 currencies and all 6 statuses for one supplier via direct `$wpdb` inserts (same fixture technique as M14's performance test); assert `$wpdb->num_queries` delta around `get_summary()` is exactly 1.
- `test_zero_committed_pos_costs_exactly_one_query()` — supplier with only draft/cancelled POs; assert exactly 1 query, empty array returned.

## Manual/browser acceptance

1. Open a Supplier detail page for a supplier with a mix of placed/received/partially-received/draft/cancelled POs in one currency — verify the Spend Summary panel shows correct ordered/received totals excluding draft/cancelled, positioned above Observed Lead Time.
2. Same for a supplier with POs in two different currencies — verify two separate rows, no blended total.
3. Open a brand-new supplier with zero POs — verify the empty-state message, no errors.
4. Log in as a user without `manage_woocommerce` — verify the whole Supplier detail screen (including this panel) is denied exactly as before M15 (no new access path).
5. Confirm no visible change to Observed Lead Time, On-Time Rate, or Order History sections/values on the same page.

## Regression requirements

Full unit suite, full integration suite, and the M1–M15 focused suite must all remain green with the same 0 failures/errors/risky bar M13/M14 held. Explicit spot-check: Order History (M14) and Printable PO (M13) sections/output byte-for-byte unchanged on a page that also now renders the Spend Summary panel.

## Documentation deliverables

- `docs/admin-guide-suppliers.md` — move "Supplier spend analysis" out of "Not Yet Available" into "What Is Available Now," documenting the panel, the committed-status-set rule, and the never-blend-currencies rule (same treatment M14 gave Order History).
- `CHANGELOG.md` — new `## [1.32.0]` entry, third milestone of the same still-unreleased post-v1.29.0 train.
- `readme.txt` — matching changelog entry; per the Version Contract above, `Stable tag` is bumped to `1.32.0` alongside the plugin header/constant (a deliberate deviation from M13/M14's lagging-`Stable tag` pattern for this milestone) — the changelog entry itself still states the version is "not individually released," matching M13's `1.30.0`/M14's `1.31.0` entries.
- **Close the discovery-phase documentation gap**: update `docs/ARCHITECTURE_BASELINE_v1.24.0.md` §6.2 ownership table and §3 milestone table to add M14 (retroactively) and M15, per its own Rule 7 ("No milestone plan ships without updating this document..."); add an M14 section (retroactively) and an M15 section to `docs/architecture-audit.md`, and correct its stale M13-era "order-history reporting... still open" line. This closes the gap identified in Part A without redesigning any M14 decision.
- `CLAUDE.md` Implementation Status table — new M15 row, in the same format as the existing M13/M14 rows.

## Acceptance criteria

- Spend Summary panel renders correctly for single-currency, multi-currency, all-draft/cancelled, and zero-PO suppliers.
- INV-M15-1 through INV-M15-4 all enforced by passing architecture-guard tests.
- Query contract (1 query, any history size) proven at 200-PO/multi-currency scale, not just asserted.
- Zero change to any M9/M11/M12/M13/M14 output or query count.
- Zero schema change; `DB_VERSION` unchanged at `10`.
- CI green: PHP lint, unit suite, M1–M15 focused suite, integration suite — 0 failures/errors/risky; intended M15 tests actually discovered (not silently skipped); `release-audit.sh --development` green; GitHub Actions green.

## Definition of Done

Implementation complete, full test suites green, Level A completion review passed, `docs/checklists/m15-release-readiness.md` created recording the freeze, documentation deliverables (including the retroactive M14 baseline/audit updates) landed, `docs/milestones/m15-implementation-plan.md` materialized and frozen immutable, dev version bumped to `1.32.0`, no merge/tag/release performed during this milestone itself.

## Risks/mitigations

- **Risk:** Merchants may expect "spend" to include drafts (money about to be committed) or expect FX-normalized totals. **Mitigation:** explicit, visible caption text on the panel stating the committed-status scope and never-blend policy; documented in the admin guide; this is a deliberate, narrow, defensible policy choice, not an oversight.
- **Risk:** A supplier with many distinct currencies (unlikely per stated EUR/USD/SEK business context, but not structurally prevented) could render many rows. **Mitigation:** acceptable — no cap needed at this scale; if it ever becomes a real problem it's a presentation tweak, not an architecture change.
- **Risk:** Retroactively updating `ARCHITECTURE_BASELINE_v1.24.0.md`/`architecture-audit.md` for M14 during M15's docs pass could be mistaken for redesigning M14. **Mitigation:** explicitly scope that documentation update as a factual sync only (add what M14 already, immutably, shipped) — never alter M14's plan file itself, never change M14 behavior.

## Explicit deferred work

Unchanged from Part B except order-history reporting and per-PO spend (now both delivered by M14/M15): supplier merge tool (Level B, still needs its own ADR-level design), PO delay grace-days Settings UI, suggestion-source transparency, Inventory Position drilldown, Expected Delivery confidence from supplier history (storefront-behavior release trigger), Coverage/Forecast (blocked — no sales-velocity data), Reservations (blocked by D16), Inbound Shipment, Warehouse location hierarchy, REST/Store API/GraphQL (blocked by D16), PO-number allocation atomicity (accepted gap, ADR-0002), Plugin god-class refactor, PHPCS cleanup, cross-supplier/storewide spend rollup (explicitly named as a non-goal above, a distinct future decision).

## Commit strategy

One commit per work package, per repository convention: (1) `Purchase_Orders::spend_summary_for_supplier()` + its unit tests, (2) `Supplier_Spend_Service` + its unit/architecture tests, (3) `Purchasing_Page` render section + integration tests, (4) performance tests, (5) documentation deliverables (including the retroactive M14 baseline sync), (6) freeze/checklist commit. No mixed-concern commits.

## Stop conditions

`spend_summary_for_supplier()` is a new, bounded, self-contained aggregate read method owned outright by `WC_Inventory_Overview_Purchase_Orders` — it issues its own parameterized `SELECT ... JOIN ... GROUP BY` directly against the tables the Purchase Order domain already owns, and does **not** compose through `Purchase_Orders::list()` or its `build_where()` helper. `Purchase_Orders::list()` is not modified by M15.

Stop and escalate (do not silently work around) if implementation-time inspection shows that the actual `wc_io_purchase_order_lines`/`wc_io_purchase_orders` schema, currency ownership (line-level vs. header-level), status representation, or supplier relationship differs materially from the assumptions this plan relies on (i.e., the exact SQL in "Query/performance contract" above no longer matches the real table shape). The committed-status set (BR-M15-1) must be parameterized safely (prepared statement placeholders, never string-interpolated). Also stop if any existing test breaks as a side effect of adding the new `require_once` line or the new render-order insertion point.

## Final implementation-report contract

The eventual M15 implementation report must state, factually and verifiably (not just claim): final query-count numbers at scale with the actual test output, full suite pass/fail counts, exact files touched, confirmation that M13/M14 output is byte-identical pre/post, and confirmation that `docs/ARCHITECTURE_BASELINE_v1.24.0.md`/`architecture-audit.md` were actually brought current for both M14 and M15 (not merely M15) — directly answering the gap this discovery pass found.

---

## PART F — Risk/lifecycle classification

**LEVEL A** — completion review + freeze only, no independent Level B audit.

Justification against the Level B triggers: no schema/migration; no stock/cost mutation; no PO/receipt lifecycle mutation; no destructive operation; no public API; no security/capability change; no storefront behavior change; no ownership-boundary change; no complex transaction/concurrency behavior (single read-only aggregate query, no transaction needed at all). Identical risk profile to M9, M11, M12, M13, and M14, all of which were Level A.

---

## PART G — Feature-train/release recommendation

**Recommendation: C — implement M15 on the current train, then close and release M13+M14+M15 together.**

Reasoning:
- **Product coherence:** M13 (print), M14 (order history), M15 (spend summary) are a tightly coherent "supplier order value" arc — all read-only extensions of the same Purchase Order data, all Level A, all zero-schema. Bundling them tells one coherent release story ("Supplier Order & Spend Visibility").
- **Cumulative risk:** still low — three Level A, zero-mutation, zero-schema milestones. No compounding architectural risk.
- **Cumulative diff size:** growing but still modest; each milestone is one small additive service + one render section. Not yet at a size where holding the train open longer meaningfully increases review burden.
- **Rollback safety:** unchanged — no data migrations exist to unwind at any point in this train.
- **Operational value:** real, waiting merchant-facing value (printable POs, order history, spend totals) is sitting unreleased; each additional milestone added to the train delays that value reaching production.
- **Release overhead:** batching three coherent milestones into one WP6 release is more efficient than three separate releases, and matches this repository's own established precedent (the M9–M12 train was bundled as four).

Do **not** recommend D (continuing beyond M15 before release) — after three accumulated unreleased milestones, closing the train is the disciplined choice; a fourth addition should only happen after this train ships. Do not recommend B (release M13+M14 now, before M15) — M15 is small, low-risk, and thematically continuous enough that splitting it into its own release adds overhead without reducing risk.
