# Milestone M11 Implementation Plan — Supplier On-Time Delivery Rate

**Status:** Approved. This document is the immutable implementation specification for Milestone M11, materialized from the approved plan before any implementation code was written, per `docs/process/milestone-lifecycle.md` WP0 step 5 / Permanent Repository Rule 1. Once committed, this file is never edited, replaced, or repurposed — any future freeze/readiness record belongs in `docs/checklists/m11-release-readiness.md`, not here.

## Materialization note

This plan went through one review-and-amendment round in plan mode before approval (see the "Amendment note" preserved below, itself part of the approved history) and is materialized here verbatim, with exactly one factual correction made at materialization time, per the implementation task's explicit instruction:

> Where the Risk section (§26) said M11 introduces "zero new classes and zero new query paths," this is corrected to accurately state: **M11 introduces one small internal pure value/policy class (`WC_Inventory_Overview_Expected_Deadline`) and zero new query paths.** This is not a design change — the approved architecture (§7) always included this one new class; the risk-summary sentence simply hadn't been updated to match after that design was adopted. The correction makes the plan internally consistent with its own approved architecture.

No other change was made at materialization time.

## Amendment note (preserved from the approved plan-mode revision)

The product scope (**Supplier On-Time Delivery Rate**) is approved and unchanged. Two design points were revisited per explicit review feedback:

1. **Deadline/SQL ownership redesigned.** The previous draft had `PO_Delay` grow a SQL boolean/CASE-fragment method purely so `Supplier_Lead_Time_Service` could embed it — this would have started turning `PO_Delay` into a general SQL-expression provider for other services' query shapes, and would have coupled `Supplier_Lead_Time_Service`'s aggregate query to `PO_Delay`'s live-delay-specific predicate shape. **Resolved by introducing one new, very small, pure, stateless internal class — `WC_Inventory_Overview_Expected_Deadline`** — whose entire responsibility is the atomic rule "given an expected date, confidence, and grace-day count, what is the deadline (or: is there no knowable deadline at all)." Both `PO_Delay` (refactored internally, zero public-contract change, zero behavior change) and `Supplier_Lead_Time_Service` (new usage) consume this one shared primitive; neither service depends on the other. See §6/§7/§8/§10/§15/§21/§22/§26 below for the full reasoning and the comparison against the three other options considered.
2. **Query-count requirement corrected.** §17 previously said "exactly one additional query" while simultaneously (correctly) describing that no new query is added — just contradictory wording. Corrected to the precise requirement: **zero additional queries**; `Supplier_Lead_Time_Service` must continue to execute exactly the same number of queries as before M11 (its one existing query becomes wider, not more numerous), and the performance test must assert this explicitly as a regression check, not just "still low."

Every other design decision (metric definition, header-level granularity, unknown-confidence exclusion, inclusive boundary, independent `rated_order_count` threshold, no persistence/schema/storefront/API/setting change, Supplier-detail-screen-only UI, feature-train/no-release posture, Level A lightweight-review classification) is preserved unchanged from the prior draft and is not reopened.

## PART A — Repository and Roadmap Findings

### Pre-flight (verified, read-only)

- Repository path: `/opt/biopentra/dev/wc-inventory-overview` — confirmed.
- Working tree: clean.
- Current branch: `feature/m10-po-expected-date-suggestion` — confirmed.
- Plugin version `1.27.0` (header + `WC_INVENTORY_OVERVIEW_VERSION` constant) — confirmed.
- `DB_VERSION` `10` — confirmed.
- Last released version: `v1.25.0` (M8) — confirmed via `git tag --sort=-creatordate`.
- M9 and M10 present in current branch history (`git log --oneline`: M10's 10 commits, including the freeze commit `aa7e214`, on top of M9's history) — confirmed.
- `feature/m9-supplier-observed-lead-time` frozen at `e918757`, unchanged — confirmed.
- `docs/checklists/m9-release-readiness.md`, `docs/checklists/m10-release-readiness.md`, `docs/process/milestone-lifecycle.md` all exist — confirmed.
- Repository reality matches the recorded state in every respect. No contradiction found; no STOP condition triggered.

Required documents were read in full (or, where already read verbatim earlier this session, re-verified against current file state): `CLAUDE.md`, `docs/process/milestone-lifecycle.md`, `docs/ARCHITECTURE_BASELINE_v1.24.0.md`, `docs/architecture-audit.md`, `docs/OWNERSHIP.md`, `CHANGELOG.md`, `README.md`, `readme.txt`, `docs/milestones/m9-implementation-plan.md`, `docs/milestones/m10-implementation-plan.md`, `docs/checklists/m9-release-readiness.md`, `docs/checklists/m10-release-readiness.md`, `docs/admin-guide-suppliers.md`, `docs/testing.md`, `docs/rollback-plan.md`, `docs/release-runbook.md`. Production code inspected directly (not just documentation): `includes/class-wc-inventory-overview-supplier-lead-time-service.php` (full), `includes/class-wc-inventory-overview-po-delay.php` (full), `includes/class-wc-inventory-overview-po-lifecycle.php`, `includes/class-wc-inventory-overview-po-statuses.php`, `includes/class-wc-inventory-overview-po-admin.php`, `includes/class-wc-inventory-overview-po-service.php`, `includes/class-wc-inventory-overview-po-events.php`, `includes/class-wc-inventory-overview-po-confidence.php`, `includes/class-wc-inventory-overview-po-receiving-sync.php`, `includes/class-wc-inventory-overview-purchasing-page.php`.

### Documentation-debt finding (noted, deliberately not bundled into M11 — see rationale below)

- `README.md` is stale: header states "Version: 1.25.0" / "Platform status: M0–M8 complete," not updated for M9/M10.
- `CHANGELOG.md`'s newest entry is `[1.26.0]` (M9); there is no `[1.27.0]` (M10) entry, even though `CLAUDE.md` and `docs/checklists/m10-release-readiness.md` both confirm M10 is implemented and frozen.

Both are real gaps, but neither blocks or is required by any candidate M11 capability, and bundling doc-debt cleanup into a feature milestone violates the discovery task's own instruction ("do not turn incidental cleanup into M11 unless it is required for the chosen capability"). Flagged here for the user's awareness; recommend a separate small doc-only pass (or folding into M11's own WP-M11-6 documentation step purely as a drive-by two-line fix, decided in the "M11 Plan Requirements" section below).

### Discovery — deferred/incomplete work catalogue

A repository-wide discovery pass (direct grep + full reads of `docs/milestones/m1`–`m10-implementation-plan.md`, `docs/admin-guide-suppliers.md`, `docs/ARCHITECTURE_BASELINE_v1.24.0.md`, `docs/OWNERSHIP.md`) found:

- **Zero literal `TODO`/`FIXME`/`@todo` tags anywhere in the codebase.** All "deferred/future/not-yet" language is prose in docblocks and docs, not marker comments — this repository's discipline is to resolve or explicitly document deferrals in the milestone plan / architecture baseline, not leave code-level markers.
- `docs/admin-guide-suppliers.md`'s "Not Yet Available" section (verbatim, the entire remaining backlog in that file):
  > - **Supplier analytics**: Spend analysis, reliability scoring, and order history reporting.
  > - **Supplier merge tool**: Consolidating duplicate suppliers into one record is a manual process for now; a dedicated merge tool is planned.
- `docs/ARCHITECTURE_BASELINE_v1.24.0.md` §2/§7.4 name, with no target milestone: Reservations/Available stock (D11), Inbound Shipment entity (D10), REST/Store API/GraphQL/Blocks integration (D16), a second `Expected_Delivery_Result` implementation, structured data (deliberately cut in M7, not reopened without a concrete consumer).
- `docs/OWNERSHIP.md` lists Warehouse location (hierarchy)/Bin/shelf/aisle/Item-to-location assignment as architecturally owned by this plugin, but **zero implementation exists** — a large, undesigned domain concept, not a small extension.
- M9's own plan §5 (Non-goals) and §8.1 name plausible future consumers of its statistics: Expected Delivery confidence improvements (→ customer-facing, higher risk), PO expected-date suggestions (→ **became M10**), **supplier reliability scoring** (still open), Inventory Coverage/Forecast (D11, "a materially larger, separate initiative" needing sales-velocity data the plugin doesn't collect), purchasing recommendations/reorder suggestions (still open, explicitly excluded from M10 too).
- M10's own plan §1 (Discovery) catalogued 16 backlog items; after M10 was selected, it explicitly left this remainder still open and unaffected by M9: *"Inventory Position Coverage/Forecast, Reservations, Inbound Shipment entity, Printable PO, ASN/barcode/scanning, `Plugin` god-class split, PHPCS baseline closure, warehouse location hierarchy, REST/Store API for Expected Delivery, structured data, PO-number allocation atomicity, supplier merge tool."* It also explicitly rejected, for M10 specifically (not permanently): **supplier reliability scoring / analytics** ("a compound backlog bullet... 'reliability' specifically requires defining a new concept beyond what M9 computed — a real new design question, not a pure extension of already-decided architecture. Left for its own future milestone.") and **Expected Delivery confidence improvement from observed lead time** ("deferred as a larger, riskier second step once the admin-facing version has real usage to validate data quality against").
- `docs/GITHUB_RELEASE_NOTES_1.25.0.md` (M8/GA) explicitly recorded, with no target milestone: no `Plugin` god-class split ("deliberately evaluated and deferred past GA"), no forecasting/purchasing recommendations/analytics/REST expansion/barcode/warehouse automation/mobile — "explicitly out of scope for a hardening milestone" (i.e., a GA-scope decision, not a specific next-milestone commitment).
- `docs/testing.md` PHPCS baseline (`.phpcs-baseline.xml`, empty, unreferenced) is explicit test/lint debt, not a product capability.

### Answering the ten discovery questions directly

1. **Explicitly deferred roadmap items:** catalogued above — the two live "Not Yet Available" items (supplier analytics/reliability, supplier merge tool) are the only ones with no larger prerequisite blocking them right now.
2. **TODO/FIXME/deferred statements in code:** none as literal tags; docblock prose only.
3. **Incomplete operator-visible workflows:** none found that block day-to-day use of Suppliers → POs → Receiving → Position → Storefront — the core purchasing loop (M1–M8) is complete and GA. What's visibly *thin*, not broken: the Supplier detail screen shows lead-time statistics (M9) and now expected-date suggestions feed off them (M10), but nothing yet answers "which suppliers should I trust/prioritize" — a real, named gap in the admin guide's own "Not Yet Available" list.
4. **Capabilities implied by M0–M10 but still missing end-to-end:** a merchant can see a supplier's *speed* (M9) and get help *estimating* a date from it (M10), but has no visibility into that supplier's *reliability* (did they actually meet the dates they were judged against) — the natural third leg of the same data M9 already assembled.
5. **Architecture boundaries prepared for a next consumer with none yet:** `Supplier_Lead_Time_Service` (M9) explicitly documents "a future milestone may promote this to a versioned public API" and is already extended once (M10); `PO_Delay` (M2/M8) computes "deadline = effective_date + grace_days" purely as a *live* predicate with no historical/statistical consumer yet.
6. **Legacy compatibility code genuinely blocking future work:** none found — Batch Intake is already fully retired (M6/M8); no legacy code is blocking any candidate below.
7. **Data collected but not yet surfaced usefully:** **this is the strongest finding.** `wc_io_purchase_orders.expected_date`/`expected_confidence` and `wc_io_goods_receipts.posted_at` are already fully collected for every historical order (used today only for the *live*, transient `PO_Delay` flag and M9's lead-time average) but no aggregate, historical "how often did this supplier actually meet the date they were judged against" figure is ever computed or shown — the data exists, the computation doesn't.
8. **Roadmap statements indicating what should follow M10:** M9's plan (§5, §8.1) and M10's plan (§1, §2) both independently name "supplier reliability scoring" as the next logical consumer of M9's data, each time declining to build it in-milestone specifically because "reliability" needed a real, careful definition — never because it was rejected as a bad idea.
9. **Do M9+M10 naturally imply a next purchasing capability?** Yes — directly. M9 built "what happened" (speed), M10 built "what to suggest next time" (a forward-looking estimate from that history). The one leg neither built is "how well did we do" — a backward-looking accountability figure over the exact same qualifying-order dataset M9 already assembles, gated against the exact deadline concept `PO_Delay` already owns.
10. **Natural release point after M11?** Per §3's Release Trigger check below, M11 as scoped introduces no release trigger, so no — it joins the feature train with M9+M10. See Part F for a size observation.

## PART B — M11 Candidate Scopes Considered

| Candidate | Merchant value | Depends on M9/M10 | Size | Risk | Schema | Mutation | UI | Verdict |
|---|---|---|---|---|---|---|---|---|
| **Supplier on-time delivery rate** (extends M9's statistics with a reliability figure, gated by `PO_Delay`'s existing grace-day concept) | High — closes the "which suppliers should I trust" gap named twice in prior plans | Directly, both | Small–medium | Low (read-only, admin-only) | None | None | Same Supplier detail panel as M9 | **Selected** |
| Supplier reliability *scoring/analytics* as originally named (spend analysis + reliability + order-history reporting, one compound feature) | Medium-high, but the compound bullet mixes three distinct concepts | Yes | Large | Medium (three separate designs) | Possibly (spend analysis may need new aggregation) | None | Multiple screens | Rejected as-is — violates "one coherent milestone, not several." On-time rate is the well-defined subset worth building now; spend analysis and order-history reporting remain their own future milestones, explicitly. |
| Expected Delivery confidence improvement from observed lead time (storefront) | Medium | Yes | Medium | **High** — customer-facing, would need a full audit | None | None | Storefront | Rejected again, same reasoning M10 already gave: needs real usage data from M10 first; too early, and storefront-facing risk is disproportionate to current milestone cadence. |
| Supplier merge tool | Low–medium (data hygiene, not growth) | No | Medium | **High** — cross-table FK reassignment across POs/Receipts/Movements, genuinely destructive/hard-to-reverse if done wrong | None | Yes, significant (reassigns `supplier_id` across multiple tables) | Suppliers admin | Rejected — real mutation/reversibility risk disproportionate to value, and not implied by M9/M10 at all; better suited to its own carefully-audited milestone later. |
| Inventory Position Coverage/Forecast | High long-term | No (needs new sales-velocity data source) | Very large | High (new data source, new domain concept) | Likely | None | Multiple | Rejected — explicitly named in M9's own plan as "a materially larger, separate initiative." Not implied by M9/M10; needs its own discovery pass. |
| Warehouse location hierarchy | Medium | No | Very large | High (new entities, likely schema) | Yes | Yes | New admin surface | Rejected — huge, undesigned new domain concept; `docs/OWNERSHIP.md` reserves the *ownership* but nothing designs it; far outside "smallest coherent milestone." |
| Printable PO (D17 reserved capability) | Medium (operational convenience) | No | Small–medium | Low | None | None | New admin view/print template | Considered, not selected — legitimate small candidate, but not implied by M9/M10 and no repository evidence names it as "next" the way reliability is named twice; deferred, not rejected, as a good candidate for a future milestone. |
| PHPCS baseline closure / `Plugin` god-class split / doc staleness fixes | N/A (maintenance) | No | N/A | N/A | N/A | N/A | N/A | Explicitly maintenance/cleanup, not a product capability — excluded per the discovery task's own instruction not to turn cleanup into a milestone. |

## PART C — Recommended M11 Scope and Rationale

**Selected: Supplier On-Time Delivery Rate** — a new, read-only statistic answering "of this supplier's completed orders that had a known expected date, what fraction arrived by their deadline?" — surfaced on the same Supplier detail admin panel M9 already built.

Why this is the correct M11:

1. **It is the one piece of "supplier reliability scoring" that is actually well-defined**, resolving the exact ambiguity that stalled the compound candidate twice. Investigation (this planning pass) confirmed the two things needed to define it rigorously already exist and are stable:
   - `expected_date`/`expected_confidence` on a Purchase Order become **permanently frozen** the moment a PO leaves `draft`/`placed` (`PO_Lifecycle::is_editable()`, enforced redundantly at the render, admin-post, and service layers) — so reading them off a completed (`received`) PO is a trustworthy, non-gameable historical signal, not a live value that could later change.
   - `PO_Delay` already owns the exact "deadline = effective_date + grace_days" arithmetic used for live delay-flagging — reusing it (rather than inventing a second, possibly-inconsistent notion of "late") means "on-time" and "not delayed" can never silently disagree.
2. **Directly and only implied by M9+M10** — no other candidate scored as highly against discovery question 9. It completes the natural trio (what happened → what to suggest → how well did we do), using data M9 already assembles in one query.
3. **Overwhelmingly reuses existing architecture.** Primarily extends two already-established owners (`Supplier_Lead_Time_Service` for aggregation, `Purchasing_Page` for presentation) rather than inventing a new service tier. The one new element — a narrow, four-method, pure value/policy class (`Expected_Deadline`, §7) shared by `PO_Delay` and `Supplier_Lead_Time_Service` — is deliberately smaller than a "sole-owner boundary" in the M9/M10 sense (it owns no domain concept from `docs/OWNERSHIP.md`, runs no query, persists nothing); it exists specifically to *avoid* a new cross-service coupling that extending either existing owner directly would have created (see the option comparison in §7).
4. **Smallest complete slice of the compound "reliability scoring" bullet.** Spend analysis and order-history reporting (the other two thirds of that original bullet) are explicitly *not* included — each remains its own undefined, un-scoped future candidate, consistent with "do not combine multiple independent features simply because they are available."
5. **Zero schema, zero mutation, zero new public API, zero storefront/customer-facing change** — the lowest-risk candidate on the table by a wide margin, appropriate after two milestones (M9, M10) already validated this exact "extend a frozen statistics service" pattern successfully.

## PART D — Definitive M11 Implementation Plan

### 1. Executive summary

M11 adds a single new derived statistic — **on-time delivery rate** — to the Supplier detail admin screen, computed from the same qualifying-order dataset `Supplier_Lead_Time_Service` (M9) already assembles, judged against each order's own frozen `expected_date`/`expected_confidence` using the exact grace-day tolerance `PO_Delay` (M2/M8) already applies to live delay-flagging. No schema change, no mutation, no new public API, no storefront change. Plugin version becomes `1.28.0`; `DB_VERSION` stays `10`.

### 2. Problem statement

A merchant can currently see how *fast* a supplier historically delivers (M9's average/fastest/slowest lead time) and get a *forward-looking estimate* pre-filled from that speed when creating a new PO (M10). They cannot see how *reliably* that supplier meets the dates it was judged against — whether a supplier with a fast average lead time is also a supplier that consistently arrives on time, or one whose average masks frequent lateness. This is a real, named gap (`docs/admin-guide-suppliers.md`'s "Not Yet Available" list; M9's and M10's own plans, each independently) that the plugin already has 100% of the underlying data for, and computes none of.

### 3. Why this is the correct M11

See Part C above (not repeated here in full). In one sentence: it is the only backlog candidate that is both fully unblocked by M9+M10 and small/well-defined enough to ship as a single coherent milestone without inventing new architecture or accepting material risk.

### 4. Explicit goals

- Compute, per supplier, an **on-time delivery rate**: the fraction of that supplier's *completed* orders (same qualifying definition M9 already uses: `status = received`, `placed_at` set, at least one posted non-migrated receipt line) that also had a **known** expected date (`expected_confidence != 'unknown'`) and were completed **on or before** `expected_date + grace_days` (the same grace-day tolerance `PO_Delay` already applies to live delay-flagging, read from the same WordPress option).
- Surface this figure on the existing Supplier detail admin screen, in the existing "Observed Lead Time" panel area, gated by a minimum-sample threshold exactly like M9's own average/fastest/slowest figures ("Not enough data yet" below the threshold).
- Keep this a pure, request-scoped, zero-write computation — never persisted, always recomputed live, exactly like every derived statistic in this codebase since M3.

### 5. Explicit non-goals

1. **Spend analysis** and **order-history reporting** — the other two thirds of `docs/admin-guide-suppliers.md`'s compound "Supplier analytics" bullet. Both remain open, undefined future candidates; neither is touched.
2. **Supplier merge tool** — unrelated, not touched.
3. **Any change to `PO_Delay`'s live delay-flagging behavior, output, or callers.** The on-time judgment itself is owned by the new `Expected_Deadline` class (§7), not added to `PO_Delay`; `PO_Delay`'s internals are refactored to consume that same shared primitive, but its `is_line_delayed()`/`is_po_delayed()`/`sql_line_delayed_predicate()`/`sql_po_is_delayed_exists()` public behavior is untouched and regression-tested (§15/§22).
4. **Any change to `Expected_Date_Suggestion_Service` (M10) or its resolution rule.** M10 continues to use only `average_days`/`has_data`/`sample_count`/`is_observed_value_usable()`; none of those change meaning. M10's plan document is not touched.
5. **Per-line reliability.** Like M9 and M10 before it, this stays at PO-header granularity — one judgment per completed PO (using the header's `expected_date`/`expected_confidence`, the same granularity M9's own `DATEDIFF(MAX(gr.posted_at), po.placed_at)` already uses per `po.id`), not per PO line. A per-line override's individual date is never consulted for this figure.
6. **Surfacing on the Suppliers list table.** Single-supplier detail screen only, matching M9's own "kept the first slice minimal" precedent (M9 Non-goal #5). A list-table column/sort is a natural, low-risk future follow-on, not built here.
7. **Any configurability specific to this feature** (e.g., a separate on-time grace-day setting). Reuses the merchant's existing `wc_io_po_delay_grace_days` option unchanged — no new setting is introduced.
8. **Retroactive recomputation or backfill of anything.** There is nothing to backfill — this is a pure read over existing, already-collected data.
9. **Any storefront, REST, AJAX, or capability change.** Admin-only, gated by the same `manage_woocommerce` capability already guarding this page.

### 6. Current architecture relevant to M11

- **`WC_Inventory_Overview_Supplier_Lead_Time_Service`** (`includes/class-wc-inventory-overview-supplier-lead-time-service.php`) — M9's sole owner of observed lead-time statistics. `query_observations()` runs one grouped-aggregate SQL query per batch of supplier IDs: an inner subquery produces one row per qualifying `po.id` (`DATEDIFF(MAX(gr.posted_at), po.placed_at) AS observed_days`, grouped by `po.id, po.supplier_id, po.placed_at`), aggregated in an outer query to `AVG`/`MIN`/`MAX`/`COUNT` per `supplier_id`. Confirmed (this planning pass, direct read) that the inner subquery currently selects only `po.id`, `po.supplier_id`, and the computed `observed_days` — it does **not** currently select `po.expected_date`/`po.expected_confidence`.
- **`WC_Inventory_Overview_PO_Delay`** (`includes/class-wc-inventory-overview-po-delay.php`) — sole owner of the "deadline = effective_date + grace_days" rule and its SQL/PHP forms. `grace_days_from_option()` reads `wc_io_po_delay_grace_days` (default `0`) from a WordPress option; the pure calculator methods (`is_line_delayed()`, `sql_line_delayed_predicate()`) accept `grace_days` as a parameter rather than reading options themselves ("WordPress option lookup belongs to callers" — its own docblock). `add_days()` is a pure `DateTimeImmutable`-based day-adder already usable outside SQL contexts.
- **`WC_Inventory_Overview_PO_Confidence`** — `EXACT`/`ESTIMATED`/`UNKNOWN` vocabulary; `UNKNOWN` means "no usable date," already the value both `PO_Delay` and M10's suggestion service treat as never-actionable.
- **`WC_Inventory_Overview_PO_Lifecycle::is_editable()`** — confirms (this planning pass, direct read) that `expected_date`/`expected_confidence` are editable **only** while a PO is `draft` or `placed`; every later status (`partially_received`, `received`, `cancelled`, `closed_short`) is locked at three redundant layers (render, admin-post handler, service layer). This is the load-bearing fact that makes a completed PO's `expected_date` a stable historical signal.
- **`WC_Inventory_Overview_PO_Expected`** (`includes/class-wc-inventory-overview-po-expected.php`) — already the small, pure, static-method value class `PO_Delay` composes with for line-vs-header inheritance (`effective_date()`/`effective_confidence()`: line override wins, else header, else `unknown`). Confirmed (direct read, full file) this class's entire responsibility is *inheritance resolution* — it knows nothing about grace days, deadlines, or dates-plus-arithmetic. **Considered and rejected as the home for M11's new logic** (see §7): its identity is specifically "line overrides header," a different concern from "date + grace_days → deadline," and M11 never needs line/header coalescing at all (header-level only, §5.5) — bolting deadline arithmetic onto it would conflate two unrelated responsibilities in one class.
- **`WC_Inventory_Overview_Purchasing_Page::render_observed_lead_time()`** (`includes/class-wc-inventory-overview-purchasing-page.php:378-…`) — the existing presentation method rendering M9's "Observed Lead Time" `<table class="form-table">` panel on the Supplier detail screen, called from the supplier-detail render path. Confirmed (direct read) it already fetches `$stats = Supplier_Lead_Time_Service::get_stats_for_supplier( $supplier_id )` and gates display on `$stats['sample_count'] >= ...MINIMUM_SAMPLE_COUNT_FOR_DISPLAY` inline (predating M10's `is_observed_value_usable()` helper — a pre-existing minor inconsistency, not introduced or fixed by this milestone; out of scope per §5.9's spirit, noted here only for the implementer's awareness).

### 7. Ownership model

**Design options considered for the shared "deadline" rule** (per explicit review request):

| Option | Description | Verdict |
|---|---|---|
| **A. Extend `PO_Delay` with a SQL fragment for `Supplier_Lead_Time_Service` to embed** | `PO_Delay` grows a method producing a boolean/CASE SQL fragment shaped for `Supplier_Lead_Time_Service`'s specific aggregate-query structure. | **Rejected.** `PO_Delay`'s existing SQL methods (`sql_line_delayed_predicate()`, `sql_po_is_delayed_exists()`) are shaped for its own EXISTS-over-`pol`-rows pattern, including line/header COALESCE and status/outstanding checks M11 doesn't need at all (header-only, §5.5). Making `PO_Delay` also emit fragments tailored to `Supplier_Lead_Time_Service`'s entirely different GROUP-BY-aggregate shape starts it down the path of becoming a general SQL-expression provider for whichever service asks next — exactly the coupling the review flagged. |
| **B. `Supplier_Lead_Time_Service` writes its own deadline SQL inline, sharing only constants (e.g. the grace-days option, `PO_Confidence::UNKNOWN`)** | No new class; the `DATE_ADD(...)`/eligibility logic is written twice (once inside `PO_Delay`, once inside `Supplier_Lead_Time_Service`), each independently, using the same option/constant values. | **Rejected.** Satisfies "no cross-service SQL coupling" but not "one canonical deadline semantic, no duplicated business-rule policy" — the *formula itself* (not just the option value) would exist in two independently-maintained places, exactly the drift risk INV-M11-2 exists to prevent. |
| **C. A very small, pure, internal deadline-policy helper consumed by both `PO_Delay` and `Supplier_Lead_Time_Service`** | A new leaf-level class owning only "given (date, confidence, grace_days), what's the deadline, and is one knowable at all" — as pure PHP and as minimal SQL micro-fragments (not a full predicate). Both existing services depend on it; it depends on neither. | **Selected.** See justification below. |
| D. Extend `WC_Inventory_Overview_PO_Expected` | Add deadline logic to the existing line/header-inheritance resolver. | **Rejected** — see §6: `PO_Expected`'s identity is inheritance resolution, a different concern, and M11 never needs line/header coalescing (header-only). Conflating the two would make `PO_Expected` harder to reason about for no benefit. |

**Selected: Option C.** New class **`WC_Inventory_Overview_Expected_Deadline`** (`includes/class-wc-inventory-overview-expected-deadline.php`), with a deliberately narrow, closed responsibility — exactly four atomic operations, nothing else, and no ambition to grow into a general SQL toolkit:

- `has_known_date( $expected_date, string $expected_confidence ): bool` — pure PHP eligibility check (mirrors, and becomes the single source for, what `PO_Delay::is_line_delayed()` currently checks inline: confidence isn't `unknown`, date isn't null/empty/`0000-00-00`).
- `deadline( $expected_date, string $expected_confidence, int $grace_days ): ?string` — pure PHP: `expected_date + grace_days` (Y-m-d), or `null` if `has_known_date()` is false. Internally does the day-arithmetic itself (`DateTimeImmutable`, moved here from `PO_Delay::add_days()` — see §15).
- `sql_deadline_expression( string $date_sql_expr, int $grace_days ): string` — the minimal SQL micro-fragment `DATE_ADD(<date_sql_expr>, INTERVAL <grace_days> DAY)`, nothing more (no comparison operator, no eligibility check baked in — callers compose those themselves, in their own query shape).
- `sql_has_known_date_expression( string $date_sql_expr, string $confidence_sql_expr ): string` — the minimal SQL micro-fragment for the eligibility check alone.

Each caller composes these atoms into its *own*, differently-shaped query/predicate — `Expected_Deadline` never knows about `PO_Delay`'s status/outstanding logic or `Supplier_Lead_Time_Service`'s aggregate/GROUP-BY structure, and provides nothing beyond these four methods (an explicit design guard against scope creep, enforced by its architecture guard test — §22).

| Responsibility | Owner |
|---|---|
| Business rule: the deadline formula (`expected_date + grace_days`, inclusive boundary) and whether a deadline is knowable at all | **`WC_Inventory_Overview_Expected_Deadline`** (new, narrow, pure) — the single canonical source, consumed by both services below. |
| Business rule: "is this PO/line currently overdue" (status + outstanding-qty + comparison against today) | **`WC_Inventory_Overview_PO_Delay`** (internally refactored to consume `Expected_Deadline` for the deadline/eligibility sub-expressions it already computes; public methods, signatures, and behavior 100% unchanged — see §15/§26 for the regression-test requirement). |
| Business rule: minimum sample count before displaying a computed rate | **`WC_Inventory_Overview_Supplier_Lead_Time_Service`** (extended) — reuses the exact same `MINIMUM_SAMPLE_COUNT_FOR_DISPLAY` constant and `is_..._usable()` pattern it already established for `average_days` via `is_observed_value_usable()` (M10), applied here to the new, independently-sized `rated_order_count` denominator via a new sibling method `is_on_time_rate_usable()`. |
| Read/aggregation owner: computing and returning `on_time_count`/`rated_order_count` per supplier, in bulk, zero N+1, zero additional query | **`WC_Inventory_Overview_Supplier_Lead_Time_Service`** (extended) — remains the sole owner of "run the one query over PO+Receipt history and return per-supplier statistics"; composes `Expected_Deadline`'s SQL micro-fragments into its own existing query rather than delegating query-shape decisions to anyone else. |
| Mutation owner | **None** — this feature performs zero writes anywhere, at any layer, by construction (matches D16/INV-3: derived, never persisted). `Expected_Deadline` itself never touches `$wpdb`. |
| Presentation owner | **`WC_Inventory_Overview_Purchasing_Page`** (extended) — the same class, same panel (`render_observed_lead_time()`), that already renders M9's lead-time figures; this milestone adds one more row to the same `<table class="form-table">`, no new screen. |

**Why a new (small) owner is justified here, unlike the rest of M11.** Every other piece of M11 extends an existing owner (`Supplier_Lead_Time_Service` for aggregation, `Purchasing_Page` for presentation) — this one atomic sub-rule is the exception, and the justification is precise: **no existing class owns "date + grace_days → deadline" as an isolated, query-shape-agnostic primitive.** `PO_Delay` owns a *larger*, differently-scoped rule (live overdue determination, tied to status/outstanding/today); `PO_Expected` owns a *different* rule (line/header inheritance). Neither can correctly own the narrower shared primitive without also taking on responsibilities it doesn't need (per Options A and D above). The new class is deliberately smaller than either — a value/policy leaf, not a service — matching the size and shape of this repository's existing small value classes (`PO_Confidence`, `PO_Statuses`, `PO_Quantities`, `PO_Expected` itself), not a new "service" tier.

**Class naming is deliberately unchanged.** `Supplier_Lead_Time_Service`'s name no longer perfectly describes everything it computes once it also returns an on-time rate. Renaming it now would touch M10's already-frozen, already-implemented `Expected_Date_Suggestion_Service`, its architecture guard's caller allowlist, and every existing call site, for a naming-purity gain disproportionate to the churn and risk — explicitly against "do not casually redesign these systems." The class docblock is updated to describe its now-slightly-broader scope ("observed lead-time and on-time delivery statistics"); the filename/class name stay exactly as-is, matching this repository's demonstrated preference (see M10 §5.1) for small additive extensions over renames.

### 8. Data flow

1. Merchant opens a Supplier's detail screen (`Purchasing → Suppliers → [supplier]`) — unchanged entry point.
2. `Purchasing_Page::render_observed_lead_time()` calls `Supplier_Lead_Time_Service::get_stats_for_supplier( $supplier_id, $grace_days )`, where `$grace_days = PO_Delay::grace_days_from_option()` (read once, by the caller — consistent with `PO_Delay`'s own "option lookup belongs to callers" discipline).
3. `get_stats_for_supplier()` delegates to `get_stats_bulk( [$supplier_id], $grace_days )` (unchanged single/bulk-consistency discipline).
4. `get_stats_bulk()` runs its existing single grouped query — still exactly one query, not a second one — now also selecting `po.expected_date`/`po.expected_confidence` in the inner per-PO subquery and computing, in the outer aggregate: `on_time_count` (orders meeting the on-time condition) and `rated_order_count` (orders with a known expected date, i.e. the eligible denominator) — using `WC_Inventory_Overview_Expected_Deadline::sql_deadline_expression()`/`sql_has_known_date_expression()` (§7) so the deadline arithmetic is defined in exactly one place, without `Supplier_Lead_Time_Service` depending on `PO_Delay` or vice versa.
5. The returned per-supplier array gains two new keys; existing keys (`has_data`, `average_days`, `fastest_days`, `slowest_days`, `sample_count`) are computed identically to today (regression-tested — see §22).
6. `Purchasing_Page` calls the new `Supplier_Lead_Time_Service::is_on_time_rate_usable( $stats )` to decide whether to show a computed percentage (`round( on_time_count / rated_order_count * 100 )`) or "Not enough data yet," and renders it as one more row in the existing panel.

No AJAX, no new endpoint, no new query beyond the one existing query (still exactly one query per page load, per M9's own zero-N+1 discipline).

### 9. Domain rules

- **Eligibility (which completed orders are judged at all):** a completed order (`status = received`, per M9's existing qualifying-order definition, unchanged) is eligible only if its **header** `expected_confidence != 'unknown'`. An order with unknown confidence contributes to `average_days`/`sample_count` (M9's existing lead-time stats, unaffected) but to neither `on_time_count` nor `rated_order_count` — it is simply invisible to the on-time calculation, never treated as "late by default."
- **On-time condition (inclusive boundary, matching `PO_Delay`'s existing strict-less-than-for-*delayed* convention inverted):** an eligible order counts as on-time when its completion date (the same `MAX(gr.posted_at)` per `po.id` M9's lead-time computation already resolves) is **on or before** `DATE_ADD(po.expected_date, INTERVAL grace_days DAY)`. This is the logical negation of `PO_Delay`'s live delayed condition (`deadline < today` ⇒ delayed), so "on-time" and "not delayed" can never disagree for the same PO at the same point in time.
- **Grace days:** sourced once per request from the same `wc_io_po_delay_grace_days` WordPress option `PO_Delay::grace_days_from_option()` already reads for live delay-flagging — not a new setting, not hardcoded to `0`.
- **Granularity:** per-PO (header-level), one judgment per completed order — matching M9's own per-`po.id` grouping, deliberately not per-line (§5.5).
- **Minimum sample count for display:** reuses `Supplier_Lead_Time_Service::MINIMUM_SAMPLE_COUNT_FOR_DISPLAY` (currently `2`), applied to `rated_order_count` (which can be smaller than `sample_count`, since some completed orders may have unknown confidence) via the new `is_on_time_rate_usable()` method.

### 10. New invariants

> **INV-M11-1 — On-time rate never judges an order without a known deadline.** A completed order with `expected_confidence = 'unknown'` never contributes to either the numerator or denominator of on-time rate — it is excluded entirely, never counted as late by default and never counted as on-time by default. A supplier's on-time rate is unavailable ("not enough data") until at least `MINIMUM_SAMPLE_COUNT_FOR_DISPLAY` such eligible orders exist.

> **INV-M11-2 — On-time and Delayed can never silently disagree.** The deadline arithmetic (`expected_date + grace_days`, inclusive boundary) and the "is a deadline knowable at all" eligibility rule are each defined in exactly one place — `WC_Inventory_Overview_Expected_Deadline` — consumed by both `PO_Delay` (live delay-flagging) and `Supplier_Lead_Time_Service` (historical on-time rate). No second, independently-defined notion of "deadline" or "eligible" is ever introduced, and neither service depends on the other's query shape to enforce this.

Existing invariants unaffected: INV-2 (single stock mutator — untouched), INV-4/INV-5 (computed quantities/delay — untouched, `PO_Delay`'s existing behavior is regression-tested, not modified), D9's confidence vocabulary (reused, not extended), D12/M9/M10's sole-computation-owner discipline (extended again, in the same disclosed, deliberate way M10 already established).

### 11. Schema impact

**None.** No new table, no new column, no new index. Every value consumed (`po.expected_date`, `po.expected_confidence`, `gr.posted_at`, and the existing join path M9 already uses) already exists and is already read by either `PO_Delay` or `Supplier_Lead_Time_Service` today. `DB_VERSION` stays `10`.

### 12. Version impact

Plugin version bumps to `1.28.0` (header + `WC_INVENTORY_OVERVIEW_VERSION` constant + `readme.txt` Stable tag), following the established minor-per-milestone convention. `DB_VERSION` unchanged at `10`.

### 13. Public API impact

**None.** `Supplier_Lead_Time_Service` remains Internal (D16) — no concrete external consumer exists for this figure either. No new hooks, filters, REST routes, or AJAX endpoints.

### 14. Admin/UI behavior

- Purchasing → Suppliers → **[supplier detail]** only. No change to the Suppliers list table, Purchase Orders screens, or any other admin screen.
- One new row ("On-Time Delivery Rate" or equivalent label — exact copy decided at implementation time, following the existing panel's tone) added to the existing "Observed Lead Time" `<table class="form-table">` panel, immediately below or alongside the existing Average/Fastest/Slowest figures.
- Below the minimum-sample threshold: the same "not enough data yet" treatment M9's own average/fastest/slowest figures already use, applied independently (a supplier could have enough *lead-time* samples but not enough *rated* — known-expected-date — orders, and vice versa; each figure's availability is judged independently).
- No new capability required — gated by the same `manage_woocommerce` capability already guarding this page.
- Renders unchanged whether the supplier is active or archived, matching M9's own precedent (archiving never removes historical purchasing evidence).

### 15. Services/classes affected

- **New:** `WC_Inventory_Overview_Expected_Deadline` (§7) — pure, stateless, zero-`$wpdb`, zero-write. Exactly four public methods (`has_known_date()`, `deadline()`, `sql_deadline_expression()`, `sql_has_known_date_expression()`) — deliberately closed, not intended to grow.
- **Modified (internal refactor only — public contract and behavior unchanged, regression-tested):** `WC_Inventory_Overview_PO_Delay` — `is_line_delayed()`'s inline eligibility checks (`UNKNOWN` confidence, null/empty date) and deadline computation (`add_days()` + `<` comparison) are refactored to call `Expected_Deadline::has_known_date()`/`deadline()`; `sql_line_delayed_predicate()`'s inline `DATE_ADD(...)`/eligibility SQL fragments are refactored to call `Expected_Deadline::sql_deadline_expression()`/`sql_has_known_date_expression()`, composed with `PO_Delay`'s own still-owned line/header COALESCE and status/outstanding logic. `add_days()` becomes a one-line delegate to `Expected_Deadline`'s day-arithmetic (kept as a public method for backward compatibility with any existing direct callers/tests). **No method signature changes; no output/behavior changes for any existing input** — proven by re-running `PO_Delay`'s complete existing unit test suite unmodified and green (§21 WP-M11-1, §26).
- **Modified (additive only):** `WC_Inventory_Overview_Supplier_Lead_Time_Service` — `query_observations()`'s SQL extended (new SELECT columns in the inner subquery, new SUM/CASE aggregates in the outer query, composed from `Expected_Deadline`'s SQL micro-fragments) — still exactly one query, not a second one; `get_stats_bulk()`/`get_stats_for_supplier()` signatures gain an optional `int $grace_days = 0` parameter (backward compatible — M10's `Expected_Date_Suggestion_Service` calls these with no `$grace_days` argument and is unaffected, verified not to read the new keys); return shape gains `on_time_count`/`rated_order_count`; new public method `is_on_time_rate_usable( array $stats ): bool`.
- **Modified:** `WC_Inventory_Overview_Purchasing_Page` — `render_observed_lead_time()` extended with one new row; passes `PO_Delay::grace_days_from_option()` into the service call (the grace-days *option value* is still sourced via `PO_Delay`, its established owner for that WordPress-option lookup — only the deadline *formula* moves to `Expected_Deadline`).
- **Unmodified:** `WC_Inventory_Overview_Expected_Date_Suggestion_Service` (M10) — confirmed to consume none of the new fields; its own tests re-run unchanged as a regression check.
- **Unmodified:** `WC_Inventory_Overview_PO_Expected` (considered and rejected as an owner, §6/§7 — left exactly as-is), `WC_Inventory_Overview_PO_Confidence`, `WC_Inventory_Overview_PO_Lifecycle`, `WC_Inventory_Overview_PO_Statuses`, `WC_Inventory_Overview_Purchase_Orders`, `WC_Inventory_Overview_Goods_Receipts` and every other purchasing/receiving class — read from, never modified.

### 16. Hooks/integration points

None added. No new actions, filters, REST routes, or AJAX handlers.

### 17. Performance/query requirements

**Zero additional queries.** `Supplier_Lead_Time_Service::get_stats_bulk()` must continue to execute exactly the same number of queries after M11 as before it — one query per call, regardless of how many supplier IDs are requested. M11 only widens that one existing query (more SELECT columns in the inner subquery, more aggregate expressions in the outer query); it never adds a second query, and `Expected_Deadline` itself issues no queries at all (pure PHP/string building only). The performance test (§22 WP-M11-5) must assert this as an explicit regression check — the same total query count as M9's own pre-M11 baseline at 10/40/200-supplier scale — not merely "still low" or "still one."

### 18. Security/capability considerations

No new capability. No new input surface — this feature has no user input at all (it is pure read/display); no nonces, no form submission, no nothing new to validate. `esc_html()`/`_n()`/`sprintf()` output escaping follows the exact pattern already used by the surrounding panel code.

### 19. Backward compatibility

- `Supplier_Lead_Time_Service::get_stats_bulk()`/`get_stats_for_supplier()`: new optional trailing parameter (default `0`, matching `PO_Delay::DEFAULT_GRACE_DAYS`) — every existing call site (including M10's) continues to compile and behave identically without modification.
- New return-array keys are additive; nothing removes or renames an existing key.
- `PO_Delay`'s existing public methods: zero signature or behavior change — regression-tested explicitly (§22).
- No stored data format changes anywhere; a rollback (§20) requires no data migration.

### 20. Rollback strategy

Code-only, no data or schema written — same clean profile as M6–M10. Reverting this milestone's commits (or simply not merging its branch) leaves the Supplier detail screen exactly as it renders today: Configured/Observed Lead Time only, no On-Time Delivery Rate row. `PO_Delay`'s live delay-flagging behavior is provably unaffected by rollback either way, since M11 never modifies its existing methods.

### 21. Work packages

**WP-M11-1 — `Expected_Deadline` + `PO_Delay` internal refactor.** Introduce `WC_Inventory_Overview_Expected_Deadline` (§7: `has_known_date()`, `deadline()`, `sql_deadline_expression()`, `sql_has_known_date_expression()` — exactly these four methods, nothing else). Refactor `PO_Delay`'s internal implementation (`is_line_delayed()`, `sql_line_delayed_predicate()`, `add_days()`) to consume it for the deadline/eligibility sub-expressions it already computes, with **zero change to any `PO_Delay` public method's signature or behavior** — proven by re-running `PO_Delay`'s complete existing unit test suite unmodified. Ships with `Expected_Deadline`'s own architecture guard test (purity: zero `$wpdb`, zero writes, closed to exactly its four methods). *No dependencies.*

**WP-M11-2 — `Supplier_Lead_Time_Service` extension.** Extend `query_observations()`'s SQL to select `po.expected_date`/`po.expected_confidence` in the inner subquery and compute `on_time_count`/`rated_order_count` in the outer aggregate, composing `Expected_Deadline`'s SQL micro-fragments directly (not via `PO_Delay`). Extend `get_stats_bulk()`/`get_stats_for_supplier()` signatures (`$grace_days` parameter) and return shape. Add `is_on_time_rate_usable()`. Confirm (still exactly one query, per §17). *Depends on WP-M11-1.*

**WP-M11-3 — Regression + unit tests.** Confirm M9's existing `average_days`/`fastest_days`/`slowest_days`/`sample_count` outputs are byte-for-byte unchanged for existing fixtures (explicit regression assertion, same discipline M10 used for M9). New unit tests for WP-M11-1's predicate (boundary at exactly the deadline, grace-days > 0, unknown-confidence exclusion) and WP-M11-2's aggregate shape/bulk-single consistency. *Depends on WP-M11-1, WP-M11-2; validates both before UI work begins.*

**WP-M11-4 — Presentation.** Extend `Purchasing_Page::render_observed_lead_time()` with the new row, sourcing `grace_days` from `PO_Delay::grace_days_from_option()`. *Depends on WP-M11-2.*

**WP-M11-5 — Integration + performance tests.** Real Supplier + PO + Goods Receipt fixtures covering: on-time (before deadline), on-time (exactly at deadline, inclusive-boundary check), late (after deadline), unknown-confidence exclusion (both directions — doesn't count as on-time, doesn't count as late), grace-days > 0 changing the outcome for the same fixture, bulk path with multiple suppliers independently correct, and an explicit regression check that this milestone's queries never change `Expected_Date_Suggestion_Service`'s (M10) or `PO_Delay`'s existing live-delay output. Performance: zero-additional-query regression at 10/40/200-supplier scale (same total query count as M9's pre-M11 baseline, per §17), extending M9's own performance test rather than duplicating its harness. *Depends on WP-M11-2, WP-M11-4.*

**WP-M11-6 — Documentation.** `docs/admin-guide-suppliers.md` (move "reliability scoring" out of "Not Yet Available" into a description of the new figure, explicitly leaving spend analysis/order-history reporting in the still-open list); `docs/ARCHITECTURE_BASELINE_v1.24.0.md` (extend M9's boundary-table row, add INV-M11-1/INV-M11-2, milestone table row); `docs/architecture-audit.md` (new `## Milestone M11` section); `CLAUDE.md` (Implementation Status table row, Platform status line, Release note line — following the exact accuracy discipline established at M10's freeze, i.e. never overstate audit/release status ahead of when it actually happens); `docs/release-runbook.md`, `docs/checklists/validation-checklist.md`, `docs/rollback-plan.md` (M11 subsections). *Depends on all prior WPs (documents what was actually built).*

**Sequence:** WP-M11-1 → WP-M11-2 → WP-M11-3 → WP-M11-4 → WP-M11-5 → WP-M11-6. One logical commit per work package, matching M0–M10 discipline.

### 22. Test plan

- **Unit — new** (`tests/unit/expected-deadline/`, new directory): `Expected_Deadline::has_known_date()`/`deadline()` — before/at/after deadline boundary (inclusive), `grace_days = 0` and `> 0`, unknown confidence excluded, null/empty/`0000-00-00` expected_date excluded; `sql_deadline_expression()`/`sql_has_known_date_expression()` produce the expected SQL text for representative inputs.
- **Unit — architecture guard, new** (`tests/unit/expected-deadline/test-expected-deadline-architecture.php`): confirms `Expected_Deadline` is pure (no `$wpdb` usage, no writes) and closed to exactly its four documented methods — a guard against it accumulating unrelated helper methods over time, directly enforcing the scope boundary the review raised.
- **Unit — `PO_Delay` regression** (existing test file, unmodified assertions re-run): every existing `is_line_delayed()`/`is_po_delayed()`/`sql_line_delayed_predicate()`/`sql_po_is_delayed_exists()`/`add_days()` test case re-run and confirmed to produce byte-identical results after the internal refactor (WP-M11-1) — this is the proof that extracting the shared primitive introduced zero behavior change.
- **Unit** (`tests/unit/supplier-lead-time/test-supplier-lead-time-service.php`, extended): new return-shape fields (`on_time_count`, `rated_order_count`); `is_on_time_rate_usable()` threshold behavior (mirroring the existing `is_observed_value_usable()` test); bulk/single consistency for the new fields; existing `average_days`/`fastest_days`/`slowest_days`/`sample_count`/`has_data`/`is_observed_value_usable()` tests re-run and confirmed unchanged.
- **Architecture guard:** confirm `Supplier_Lead_Time_Service`'s existing sole-owner/zero-N+1 guard tests still pass against the extended (still single) query; confirm no new caller boundary is introduced (`Purchasing_Page` was already the approved presentation caller) — verified, not assumed, during implementation.
- **Integration** (`tests/integration/supplier-lead-time/` or a new `tests/integration/supplier-reliability/` directory — decided at implementation time, following whichever convention keeps the on-time-specific fixtures clearly separated from M9's existing lead-time fixtures): real Supplier + PO + Goods Receipt end-to-end scenarios per WP-M11-5.
- **Performance:** explicit query-count-regression test at 10/40/200-supplier scale — asserts the exact same total query count as M9's own pre-M11 baseline, per the corrected §17 requirement (zero additional queries, not just "still low").
- **Manual/browser acceptance:** open a Supplier detail screen for (a) a supplier below the minimum sample threshold → "not enough data"; (b) a supplier with a mix of on-time and late completed orders and a known grace-days setting → confirm the displayed rate matches manual calculation; (c) a supplier whose only completed orders have unknown confidence → confirm "not enough data," not a fabricated 0%/100%; (d) confirm the existing Configured/Observed Lead Time rows are visually unchanged.
- **CI:** new test file pattern(s) added to `tests/docker/run-phpunit.sh`'s `FILTER_ARGS` from day one (same discipline as every prior milestone), full unit + M1–M11-focused blocking suite + integration suite green before completion.

### 23. Documentation deliverables

See WP-M11-6 above (full list). Additionally, as a small drive-by fix bundled *only* because it is a one-line accuracy correction directly touching the same "Not Yet Available" paragraph WP-M11-6 already edits (not separate cleanup work): the `docs/admin-guide-suppliers.md` edit removing "reliability scoring" from the not-yet-available list is the natural, minimal place this milestone's own change belongs — this is not the README.md/CHANGELOG.md staleness noted in Part A, which remains explicitly out of scope for M11.

### 24. Acceptance criteria

- [ ] WP-M11-1: `Expected_Deadline` implemented (exactly its four documented methods, guard-tested); `PO_Delay` internally refactored to consume it with zero change to any existing `PO_Delay` public method's behavior (regression-tested).
- [ ] WP-M11-2: `Supplier_Lead_Time_Service` extended; single/bulk consistency holds for new fields; existing fields byte-for-byte unchanged for existing fixtures; `is_on_time_rate_usable()` implemented.
- [ ] WP-M11-3: full unit regression + new unit coverage green.
- [ ] WP-M11-4: Supplier detail screen shows the new row, correctly gated by the minimum-sample threshold, independently of the existing lead-time gate.
- [ ] WP-M11-5: full integration + performance coverage per §22, including the inclusive-boundary and unknown-confidence-exclusion cases.
- [ ] WP-M11-6: all listed documentation updated, including moving "reliability scoring" out of `docs/admin-guide-suppliers.md`'s "Not Yet Available" list.
- [ ] `DB_VERSION` confirmed unchanged at `10`; no new public API surface; no new settings/hooks/filters/capabilities; no storefront change.
- [ ] Full CI green (unit + M1–M11-focused blocking suite + integration).
- [ ] Branch fully committed, clean, **not pushed, not merged, not tagged** — per the feature-train model.
- [ ] Per `docs/process/milestone-lifecycle.md`: WP2/WP3 (audit/remediation) or WP4 (lightweight review/freeze) — per Part E's classification below — and the freeze record `docs/checklists/m11-release-readiness.md` all happen as separate, later steps, not part of this plan's own completion.

### 25. Definition of Done

All acceptance criteria in §24 checked; branch `feature/m11-supplier-on-time-rate` (branched from `feature/m10-po-expected-date-suggestion`, per the same feature-train branch topology M10 established) exists, clean, unpushed; `docs/milestones/m11-implementation-plan.md` materialized as a standalone commit before any implementation code.

### 26. Risks and mitigations

| Risk | Mitigation |
|---|---|
| "On-time" and "Delayed" quietly drift apart over time if reimplemented independently | INV-M11-2 + the single-owner `Expected_Deadline` primitive (§7), consumed by both `PO_Delay` and `Supplier_Lead_Time_Service`, neither of which duplicates the formula |
| Refactoring `PO_Delay`'s internals (WP-M11-1) to consume `Expected_Deadline` subtly changes its live delay-flagging behavior | `PO_Delay`'s public contract (method signatures, return values) is explicitly unchanged; its complete existing unit test suite is re-run unmodified and must stay green before WP-M11-1 is considered done (§15/§22) — this is a regression gate, not an assumption |
| `Expected_Deadline` itself grows into an unintended general SQL-expression toolkit over time (the exact concern that motivated this redesign) | Its architecture guard test (§22) closes it to exactly four documented methods; any future consumer wanting more must extend it deliberately, mirroring the sole-caller-allowlist discipline M9/M10 already use for their own guard tests |
| A supplier's on-time rate looks artificially low/high because unknown-confidence orders are miscounted | INV-M11-1 + explicit exclusion tests (WP-M11-3/WP-M11-5) |
| Extending `Supplier_Lead_Time_Service`'s query accidentally changes `average_days`/`sample_count` for existing suppliers, or accidentally adds a second query | Explicit regression assertion for existing fields + explicit zero-additional-query performance assertion (§17/§22) before any new-field work is considered complete |
| M10's `Expected_Date_Suggestion_Service` silently breaks because of the changed method signature | Backward-compatible optional parameter (§19) + explicit regression test confirming M10's existing test suite is unaffected |
| The "reliability" concept still turns out ambiguous once implementation starts (the reason this was deferred twice before) | This plan resolves every ambiguity that stalled it previously — editability (confirmed frozen post-placement), deadline definition (one canonical primitive, not invented per-service), granularity (header-level, matching precedent), unknown-confidence handling (excluded, matching the existing treatment) — nothing is left for the implementer to guess |
| Documentation drift (the exact class of error caught at both M9's audit and M10's freeze) | WP-M11-6 explicitly scoped against the current, verified state of `docs/admin-guide-suppliers.md`'s "Not Yet Available" list, not a generic "add an M11 row" |

**Overall risk profile: low** — comparable to or lower than M10's. M11 introduces one small internal pure value/policy class (`WC_Inventory_Overview_Expected_Deadline`) and zero new query paths (one existing query, extended, per §17).

### 27. Explicit deferred work

Supplier spend analysis; supplier order-history reporting; supplier merge tool; on-time rate surfaced on the Suppliers list table; a dedicated on-time-rate grace-days setting distinct from the existing delay grace-days option; Expected Delivery confidence improvements from observed/on-time data; Inventory Position Coverage/Forecast; Reservations; Inbound Shipment entity; Printable PO; warehouse location hierarchy; PHPCS baseline closure; `Plugin` god-class split; `README.md`/`CHANGELOG.md` staleness (Part A) beyond the one bundled `admin-guide-suppliers.md` line.

### 28. Feature-train recommendation

Per the Release Trigger check (schema change / migration / public API change / ownership-boundary change / storefront behavior change / security fix / breaking change — **none apply**), M11 introduces no release trigger and should **remain unreleased, joining the feature train with M9+M10** — no push, merge, tag, GitHub Release, or deployment as part of M11's implementation. See Part F for a sizing observation about the train itself.

## PART E — Risk Classification and Recommended Validation Lifecycle

**Recommended: A — Lightweight completion review + freeze** (the same WP4-only path M10 used, not a full independent audit).

Justification against the stated audit triggers: M11 has no schema/migration change, no stock/cost mutation, no PO/receipt lifecycle change (it only *reads* PO/receipt data that other milestones already mutate), no public API change (`Expected_Deadline` and the extended `Supplier_Lead_Time_Service` are both Internal, D16), no ownership-*boundary* change in the sense that matters for this trigger (no business concept from `docs/OWNERSHIP.md` is reassigned between plugins or given a new owner; the one new class is a narrow internal value/policy leaf, not a reassignment of an existing domain concept), no destructive/irreversible operation (pure read, zero writes — `Expected_Deadline` never touches `$wpdb`), no security/capability change, no customer-facing behavior (admin-only), no transaction/concurrency behavior (a single `SELECT` query, no writes to coordinate).

The one element with slightly more surface than a pure addition is WP-M11-1's internal refactor of `PO_Delay`'s existing implementation — this does **not** change the classification, because: its public contract is unchanged, its behavior is unchanged (regression-gated by re-running its complete existing test suite unmodified before the work package is considered done, §15/§22/§26), and it does not touch PO/receipt lifecycle, mutation, or any of the enumerated triggers — it is a same-behavior internal extraction, the same class of change M10 already made to M9's service and that was validated at Level A. This is, if anything, a lower-risk milestone than M10 (which at least introduced one new class *and* one new client-side JS behavior); M10 itself was validated at Level A. There is no basis here for requiring the heavier path merely because M9 happened to receive one, or because this revision touches one more existing file than the previous draft did.

## PART F — Feature-Train Recommendation

M11 itself does not create a release boundary (Part D §28). One observation offered for the user's judgment, not a recommendation baked into the plan: after M11 the feature train will be three milestones deep (M9, M10, M11) with none released since `v1.25.0`. Nothing about M11's scope requires closing the train now, but the user may want to consider release timing as a separate decision once M11 is frozen — this is explicitly not part of M11's implementation and is not assumed here.
