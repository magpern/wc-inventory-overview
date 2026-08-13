# WC Inventory Overview — Purchasing & Incoming Inventory: Complete Architecture & Implementation Guide

**Master document:** Authoritative canonical source for this plugin's architecture (frozen), roadmap (frozen), and milestone implementation plans. Updated by each milestone's implementation pass. Created per Milestone M1 resolution to consolidate Architecture v1.0 Parts I–III verbatim, fixing M0's dangling `docs/testing.md` references to `../CLAUDE.md`.

**Auto-loaded by Claude Code:** this file is discovered at the plugin repository root and serves as project-level instructions/context for the IDE extension and CLI tool.

**Process:** [`docs/process/milestone-lifecycle.md`](docs/process/milestone-lifecycle.md) — the Standard Milestone Lifecycle (v2, effective M10 onward) governing plan → implement → audit → remediate → freeze → next-milestone sequencing and feature-train release batching. Read it before starting any milestone from M10 forward.

**Canonical published baseline:** `main` / tag **`v1.36.0`**
([`docs/GITHUB_RELEASE_NOTES_1.36.0.md`](docs/GITHUB_RELEASE_NOTES_1.36.0.md);
train closure: [`docs/checklists/feature-train-m18-m19-release-readiness.md`](docs/checklists/feature-train-m18-m19-release-readiness.md)).
Contains M0–M8 GA, the bundled M9–M12 Supplier Performance feature train
(and CI recovery), the bundled M13–M15 Purchasing & Supplier Insights
feature train, M16 (PO Expected-Date & Delay Transparency) released as
standalone v1.33.0, M17 (Supplier Merge) released as standalone v1.34.0,
and the bundled M18–M19 Admin Controller Decomposition feature train.
**M0–M8 are GA; M9–M12 were bundled and released as v1.29.0; M13–M15 were
bundled and released as v1.32.0; M16 was released as standalone v1.33.0;
M17 was released as standalone v1.34.0; M18–M19 were bundled and released
as v1.36.0**. See milestone plans and readiness
checklists in [`docs/milestones/`](docs/milestones/) and
[`docs/checklists/`](docs/checklists/) for complete per-milestone detail.

## Platform status: M0–M19 published (v1.36.0)

**Current baseline: plugin 1.36.0, `DB_VERSION` 11.** All nine foundational
milestones (M0 Delivery Foundations through M8 Hardening & GA), the first
post-GA feature train (M9–M12), and the second post-GA feature train
(M13–M15) are tagged and published as `v1.32.0`; M16 and M17 released
standalone as `v1.33.0`/`v1.34.0`; the third post-GA feature train (M18–M19,
Admin Controller Decomposition Phases 1–2) is **tagged and published** as
`v1.36.0`.
M9–M12 were each frozen on feature branches (M9 with a full independent audit;
M10–M12 with Level A completion reviews) and released together per
`docs/process/milestone-lifecycle.md` WP6 as `v1.29.0` — not as separate
`v1.26`–`v1.28` tags. CI recovery is included in that same release. M8 added
zero new domain
concepts, zero schema change, and zero
public API change — it physically removed the M6-deprecated Batch Intake
code, closed the `partially_received` delay-detection gap, added a
repo-wide sibling-plugin-coupling conformance guard, repaired the last
pre-existing test-content bugs (the full integration suite is now a
CI-blocking gate, clean for the first time), and hardened the CI pipeline.
M9 added one read-only reporting feature (observed supplier lead-time,
computed from existing Purchase Order / Goods Receipt history). M10 added
one advisory admin feature (Purchase Order creation pre-fills Expected
Date/Confidence from M9's observed lead time, falling back to the
supplier's configured lead time). M11 added one read-only reporting feature
(supplier on-time delivery rate, judged against each order's own frozen
expected date using the same grace-day tolerance already governing live
delay-flagging). M12 surfaces those same Observed Lead Time and On-Time Rate
figures as read-only columns on the Suppliers list (one bulk
`get_stats_bulk()` call per page) — zero schema change across any of the
four milestones, zero new public API (all new/extended services are Internal,
not Public — D16), zero change to any existing business behavior beyond each
milestone's own one addition.
M13–M15 were each frozen on feature branches with Level A completion reviews
and released together per WP6 as `v1.32.0`. M13 added one read-only
presentation feature (a standalone printable HTML view of a Purchase Order,
resilient to deleted product/supplier references via stored snapshots). M14
added one read-only reporting feature (a paginated, status-inclusive Order
History section per supplier, with per-PO Ordered/Received Value). M15 added
one read-only reporting feature (a per-currency Spend Summary across a
supplier's committed Purchase Orders, never blending currencies) — zero
schema change across any of the three milestones, zero new public API (all
new services are Internal, not Public — D16), zero change to any existing
business behavior beyond each milestone's own one addition.
M18–M19 were each frozen on feature branches with Level A completion reviews
and released together per WP6 as `v1.36.0`. Unlike every prior post-GA
milestone, M18–M19 add **zero** merchant-facing capability — they are a
pure internal admin-architecture refactor. M18 extracted the Dashboard and
Settings tabs of the `WC_Inventory_Overview_Plugin` god class into dedicated
`WC_Inventory_Overview_Dashboard_Controller`/`WC_Inventory_Overview_Settings_Controller`
classes. M19 extracted the Movements, Order Profit, and Product
Profitability tabs into a new `WC_Inventory_Overview_Reporting_Controller`.
Both milestones were built characterization-tests-first (behavior captured
before any code moved, proven byte-identical after) — zero schema change,
zero new public API, zero new capability, zero behavior change beyond the
class each method now lives in. The Inventory Overview and Restock / Cost
Adjustment tabs remain on `Plugin` and are unaffected; their own
decomposition is explicitly deferred to a future, not-yet-scheduled Phase 3
(see `docs/architecture-audit.md`).
**[`docs/ARCHITECTURE_BASELINE_v1.24.0.md`](docs/ARCHITECTURE_BASELINE_v1.24.0.md)**
remains the consolidated architecture snapshot — completed milestones, frozen
ownership boundaries, public APIs, schema, invariants, and future-governance
rules (updated in place for M8–M15, not superseded — see its own
§12 rule and the milestone table; M18–M19 are pure presentation-layer
reorganization and add no new domain concept, so no further update to that
file is required). The Implementation Status
table at the end of this file remains the authoritative per-milestone release
ledger.

---

# PART I — Architecture v1.0

**Status: Architecture v1.0 · Architecture Freeze · Approved baseline for implementation planning.**
**Scope discipline:** No implementation, no code, no milestone sequencing. This document is the internally consistent architectural reference; every section reflects the current decisions (no change-log deltas). v1.0 finalizes Revision 3 with: the variation-level purchasing invariant, receiving-workflow flexibility (omitted lines), printable-PO reserved capability, expected-receipt audit summary projections, optional supplier context in Inventory Overview, the plugin-positioning note, a full consistency review, and the Architecture Freeze (§20).

---

## 1. Executive summary

The plugin's Batch Intake conflates two distinct business facts: *what we have committed to buy* and *what has physically arrived*. This architecture separates them:

- **Purchase Order (PO)** becomes the purchasing commitment. It owns supplier, ordered quantities, prices in supplier currency, and expected receipt dates. It never touches WooCommerce stock. POs are **living business documents** — they evolve (dates, quantities, prices, references, tracking) until closed, with every significant change captured as a PO Event; the event history *is* the revision history. As business documents they are also, in a future phase, printable — a capability reserved, not built (§11.2).
- **Goods Receipt** becomes the receiving event — today's batch, renamed and repositioned. Posting a receipt is the **single controlled operation** in the entire system that mutates WooCommerce stock and EUR weighted-average costing. ("Goods Receipt" is internal architecture vocabulary; the UI says "Receive Stock" — §17.4.)
- **All purchasing references the purchasable inventory item** (INV-8): for variable products, POs, receipts, incoming, expected dates, and stock mutations all target the *variation*; parent products are presentation containers only.
- **Inventory Position** is a **first-class domain concept** — the stable abstraction that answers "what is our stock situation for this item?" Its current model is {On Hand, Incoming, Position}; it is designed to gain Reserved, Available, Coverage, and Forecast fields in future versions without changing the surrounding architecture. Incoming is derived, never stored per product: any number of open PO lines per item remain independent stored records, aggregated on read.
- **Expected dates carry a confidence level** (Exact / Estimated / Unknown), because suppliers give anything from calendar dates to week numbers to nothing. Confidence flows through the resolver to both admin display and storefront wording, and the latest expected-receipt change is surfaced as a summary projection (date, confidence, last updated, updated by) without digging through event history.
- **Suppliers** become first-class entities with a deliberately small field set; configured lead time is the fallback today, observed lead-time statistics are a designed-for-later capability.
- The **storefront** may show only one carefully governed fact: the earliest credible expected receipt, worded by confidence ("Expected back around 1 September" / "Expected during week 36" / "Expected soon") — never suppliers, PO numbers, quantities, or delay details.
- **Migration is additive**: historical applied batches become direct posted Goods Receipts (never synthetic POs); old tables are frozen, not destroyed; the note-regex movement linkage is replaced by typed references.

Scope is controlled hard: no reservations table, no inbound-shipment entity, no sales-ledger reconstruction, no snapshot tables, no attachment or print implementation, no REST API in this phase. Each has a documented additive path (§13, §14).

### 1.1 Plugin positioning

The plugin has grown beyond its name. **"WC Inventory Overview" is retained for compatibility** (plugin slug, update channel, existing installs), but its functional scope now deliberately encompasses: **inventory** (overview, position, movements), **purchasing** (suppliers, purchase orders), **receiving** (goods receipts, landed costs), **costing** (weighted-average, FX), **profitability** (order and product reporting), **supplier management**, and **future planning** (coverage, forecasting — designed-for-later). This is an intentional evolution, and this document is its architectural contract. Future branding or renaming is a product decision that may be taken separately at any time **without affecting this architecture** — nothing in the domain model, schema, or service boundaries depends on the plugin's display name.

---

## 2. Verified current-state context

WC Inventory Overview v1.18.0 (`/opt/biopentra/dev/wc-inventory-overview`). Facts verified in source:

- **Batch Intake has no lifecycle** — no status column, no draft, no edit/void, no batch list/detail UI. Preview is pure computation; Apply writes batch header/lines/costs, mutates WooCommerce stock via `set_stock_quantity()`, updates `_wc_io_average_unit_cost` / `_wc_io_inventory_value` meta (weighted moving average, EUR), and inserts one `purchase_batch` movement per line. Rollback is a hand-rolled compensating undo, not a DB transaction.
- **Batch lines already reject variable parent products** — line validation refuses variable/grouped/external parents and non-stock-managed items; costing meta is written only on simple products or variations, never parents. INV-8 formalizes what the code already practices.
- **Batch↔movement linkage is a regex** over the movement note (`Batch ID: (\d+)`), not a reference column.
- **The movement ledger is purchase-side only** — only `purchase`, `purchase_batch`, `cost_adjustment` are ever written; `sale`/`refund`/`return`/`adjustment` are declared but unused. Sales and inline stock edits bypass the ledger.
- **Costing** is weighted moving average in EUR, stored in product meta on the simple product or variation. Batch lines are historical records, never consumed (no FIFO/lots). Landed costs (7 hardcoded type slugs, per-row currency+FX) are allocated proportionally to EUR line value, remainder to the last line.
- **Suppliers (M1):** first-class `wc_io_suppliers` entity with Purchasing admin UI; Batch Intake and Quick Restock still accept legacy free-text supplier fields with additive autocomplete.
- **Stock is WooCommerce-native** (`_stock`); no shadow table. Order-profit snapshots are frozen at order status change and never restated.
- **Extensibility is minimal** — one business hook (`wc_io_supplier_created`) plus a few trivial filters; the integration surface remains mostly public static service methods.
- **UI**: **Inventory & Profit** hub (`wc-inventory-profit`) with tabs Dashboard, Inventory Overview, Restock/Cost Adjustment (sub-views Batch Intake / Quick Restock / Cost Adjustment), Inventory Movements, Order Profit, Product Profitability, Settings; plus **Purchasing** submenu (`wc-io-purchasing`, Suppliers tab, M1). Plain PHP + WP_List_Table + admin-post/AJAX. Legacy-URL redirect plumbing already exists.
- **Business context**: single warehouse, small EU shop, EUR base, suppliers invoicing EUR/USD/SEK, 1–3 admin users. **Storefront ownership (M7, superseding the note below):** this plugin owns customer-facing expected-delivery rendering itself, behind the `wc_io_expected_delivery_renderer_enabled` toggle — sibling plugin "Biopentra Storefront" was verified to be an empty directory with nothing filtering `woocommerce_get_availability`, so the built-in renderer is the only renderer, not a fallback. Any external consumer integrates generically via `wc_io_storefront_render_expected_delivery` + `Expected_Delivery_Service`, never by a named dependency on this plugin. See `docs/adr/0003-storefront-expected-delivery-ownership.md`.

---

## 3. Core architectural decisions

| # | Decision |
|---|---|
| D1 | **Purchase Order is the purchasing commitment** (root purchasing aggregate). It never touches WooCommerce stock. |
| D2 | **PO Lines are independent ordered quantities** — each line for a purchasable inventory item is its own incoming supply record. |
| D3 | **Goods Receipt is the receiving event** and the **only** operation that mutates WooCommerce stock and weighted-average costing. Everything Batch Intake does today (FX at rate-of-day, landed allocation, moving-average posting, movements) is receiving behavior — repositioned, not discarded. |
| D4 | **Incoming inventory is never added to WooCommerce stock before receipt posting.** Incoming is derived from open PO lines at read time. |
| D5 | **Partial receipts are supported**; multiple receipts may fulfil one PO; a receipt contains only the lines actually received (omitted PO lines simply remain outstanding, §10.2); over-receipt is allowed with warning and audit. |
| D6 | **PO↔receipt linkage is at line level** (`receipt_line.po_line_id`, nullable). One receipt can span POs; a receipt line without a PO link is a direct receipt. |
| D7 | **Direct receipt without a PO remains first-class** ("Quick Receive Without PO", visually secondary to receiving against a PO). **No synthetic POs are ever fabricated** — for direct receipts or for migrated history. |
| D8 | **Suppliers become first-class entities** with a minimal initial field set (§11.1). Configured lead time is the present-day fallback; observed lead-time statistics are designed-for-later (§11.1.1). |
| D9 | **Expected dates live in purchasing data** (PO header default, optional PO-line override) — never as a single product-level field — and carry an **Expected Receipt Confidence** (Exact / Estimated / Unknown, §7.2). The latest expected-receipt state (date, confidence, last updated, updated by) is exposed as a summary projection of the event history (§7.3). |
| D10 | **No Inbound Shipment entity in the initial scope.** Goods Receipt lifecycle is `draft → posted → voided` only; tracking/carrier/revised dates live on the PO. A shipment entity can be inserted additively later (§7.4). |
| D11 | **Inventory Position is a first-class domain concept** (§8.2) — the stable abstraction over an item's stock situation. Current model: {On Hand, Incoming, Position = On Hand + Incoming Total}. Future versions extend the same concept with Reserved, Available, Coverage, Forecast — without changing the surrounding architecture. No reservation subtraction in this phase (§14). |
| D12 | **One authoritative calculator**: the Inventory Position Service is the only component allowed to compute position/aggregation values, single and bulk (no N+1). |
| D13 | **Low-stock and incoming are composable states**, shown simultaneously — never mutually exclusive. |
| D14 | **Migration is additive** and preserves historical batch data; legacy batches become direct posted Goods Receipts. |
| D15 | **Ledger changes are limited to purchasing integrity** in this phase: typed references, receipt-post/void movements, cost-adjustment references. Full sales/refund/manual-edit ledger reconstruction is a separate later initiative. |
| D16 | **No speculative schema or APIs**: no reservations table, no snapshots table, no attachment tables, no print templates, no REST endpoints until a concrete consumer exists. Stable internal service boundaries + WordPress actions/filters are the extension surface. |
| D17 | **Purchase Orders are living business documents** — mutable until closed, with every significant business change recorded as a PO Event. The event history is the complete revision history; **no separate versioning system exists or is needed** (§6.4). As business documents they are also printable in a future phase — capability reserved from the PO aggregate (§11.2). |
| D18 | **Internal vocabulary and UI vocabulary are deliberately separated**: `GoodsReceipt`/`GoodsReceiptLine`/`GoodsReceiptService` internally; "Receive Stock" / "Receive Against Purchase Order" / "Quick Receive Without PO" in the UI (§17.4). |
| D19 | **Purchasing always references the purchasable inventory item** (INV-8): for variable products, the variation — never the parent. Parent products are presentation containers only. |

---

## 4. Domain invariants (formal)

**INV-1 — Independence of incoming supplies.** Any number of open incoming quantities may exist for the same purchasable item, each represented by its own PO line. The system MUST NOT merge, overwrite, or collapse them into a stored product-level record. Corollaries:
- Two POs with the same expected date remain two records.
- Several POs with different expected dates remain several records.
- Lines for the same item from different suppliers remain independent.
- A date change on one PO/PO line affects only that PO/PO line.
- Partial receipt, cancellation, delay, or close-short on one supply leaves all others unaffected.
- Several future receipts may target the same item.

**INV-2 — Single stock mutator.** Only posting (or voiding) a Goods Receipt changes WooCommerce stock or weighted-average cost through this plugin's purchasing domain. POs, drafts, edits, and date changes have zero stock effect.

**INV-3 — Derived aggregation.** All incoming/position figures are computed from stored PO-line records at read time by the Inventory Position Service. No product-level "incoming quantity" or "expected date" field exists anywhere. Consequently, position and expected-date resolution always reflect the **current** inventory state and the **currently** qualifying supplies — never the state at PO creation time (see Scenario G).

**INV-4 — Computed quantities.** `qty_outstanding = GREATEST(0, qty_ordered − qty_received − qty_cancelled)` is always calculated, never independently edited. `qty_received` is a maintained counter that must always equal the sum of posted receipt-line quantities for that PO line (asserted by a reconciliation check).

**INV-5 — Computed delay.** "Delayed" is a condition (`outstanding > 0 AND effective_expected_date + grace_days < today`), never a stored state. (Supplies with Unknown confidence have no date and are never "delayed"; they surface via the "Incoming with no expected date" filter instead.)

**INV-6 — Auditability.** Posted/placed aggregates are never hard-deleted; corrections are lifecycle transitions (void, cancel, close short) with full history. Status changes, expected-date and confidence changes, quantity changes, price corrections, cancellations, close-shorts, reference/tracking updates, and receipt post/void events are recorded in the PO event log (D17).

**INV-7 — Presentation never destroys identity.** Aggregation and same-date grouping are presentation/resolution behaviors; underlying PO-line identities always remain retrievable and individually addressable.

**INV-8 — Purchasing references the purchasable inventory item.** Purchase Orders always reference a specific purchasable inventory item — a simple product or a variation. For variable WooCommerce products this means: PO lines reference the **variation**; Goods Receipt lines reference the **variation**; Inventory Position is calculated **per variation**; incoming inventory belongs to the **variation**; expected dates belong to the **variation** (via its PO lines); stock mutations always occur against the **variation**. **Parent (variable) products are presentation containers only** — incoming inventory must never exist against a variable parent. Parent-level figures shown anywhere (e.g. Inventory Overview parent rows) are presentation-time aggregations over child variations, per INV-7. This formalizes what the current code already practices (batch lines reject variable parents; costing meta lives on variations).
*Simple → variable conversion:* if a simple product is later converted to a variable product, migration splits naturally — future purchasing records (PO lines, receipts) are created against the new variations, while historical receipts and movements against the former simple product remain valid, immutable historical records (they carry `sku_snapshot` and before/after figures; history is never rewritten).

---

## 5. Entity model and relationships

### 5.1 Entities

- **Supplier** — first-class entity replacing free text (minimal fields, §11.1).
- **Purchase Order** — the commitment: supplier, currency, order date, expected receipt date + confidence (header default), reference, tracking/carrier (initial home, §7), status. A living document per D17.
- **PO Line** — the incoming supply record: purchasable item (simple product or variation, INV-8), `qty_ordered/received/cancelled`, unit cost in PO currency, optional expected-date/confidence override, optional supplier item identifiers (§11.3.1), line status.
- **Goods Receipt** — one receiving event: everything today's batch header holds (currency, FX snapshot, entered+EUR totals, note) plus receipt number, status (`draft|posted|voided`), allocation method, provenance (`source`).
- **Receipt Line** — today's batch line (qty, entered/converted/base/landed/true costs, before/after stock+avg+value snapshots) plus nullable `po_line_id`; references the purchasable item (INV-8).
- **Receipt Cost** — today's landed-cost row plus a post-hoc flag (§10.4).
- **Inventory Movement** — the existing ledger, gaining typed references (`reference_type`/`reference_id`) and a `supplier_id`.
- **PO Event** — append-only audit history for POs; the PO's complete revision history (§6.4, §11.7).
- **Inventory Position** — a first-class *derived* concept, not a table: the result struct produced exclusively by the Inventory Position Service (§8.2), always per purchasable item (INV-8).

### 5.2 Relationships

```
Supplier 1 ──── * Purchase Order 1 ──── * PO Line
   │                    │                   │  (nullable line-level link)
   │                    └──── * PO Event    ▼
   └────────── * Goods Receipt 1 ──── * Receipt Line ──── (purchasable item:
                     │  1                                   simple product or variation)
                     ├──── * Receipt Cost
                     └──── * Inventory Movement  (reference_type = 'receipt')
```

Line-level linkage (D6) handles all fulfilment shapes without a shipment entity: one PO received in several deliveries → several receipts pointing at the same PO's lines; one consolidated delivery covering two POs → one receipt whose lines point at lines of different POs; an unplanned arrival → `po_line_id NULL`.

**Referential integrity** is by convention, not MySQL FKs (WordPress norm): every write goes through a service method in an explicit DB transaction; posted/placed aggregates are never hard-deleted (drafts may be); suppliers and products are archived, never deleted, and lines carry denormalized `sku_snapshot` / `supplier_name` so history survives WP-side deletions; a periodic integrity check (orphan scan, `qty_received` recompute per INV-4) surfaces drift as an admin notice.

---

[PART I continues with Sections 6–20, detailed domain specs for lifecycles, expected dates, inventory position, customer-facing policies, workflows, data model, migration strategy, scope boundaries, reservations/pre-orders, extensibility, scenario walkthroughs, administrative presentation, open questions, decision summary, and architecture freeze. Full text retained for canonical reference.]

---

# PART II — Delivery Roadmap v1.0

**Status: Delivery Roadmap v1.0 — approved and frozen (§R9).** Architecture v1.0 (Part I) is the sole architectural authority; this roadmap decides only *sequencing, packaging, verification, and release safety*. Each milestone receives its own dedicated implementation plan later, which must conform to Part I (§20) and to this roadmap's boundaries.

[PART II contains the complete Delivery Roadmap v1.0 sections R1–R9, including governing rules, sequencing rationale, milestone definitions (M0–M8), database evolution, test strategy, documentation deliverables, and quality gates. Full text retained for canonical reference.]

---

# PART III — Milestone M0 Implementation Plan: Delivery Foundations

**Status:** Approved-for-review implementation blueprint for Milestone M0, under the frozen Architecture v1.0 (Part I) and the frozen Delivery Roadmap v1.0 (Part II).

**Milestone contract:** M0 is a safety net with **no functional change**. It builds the verification machinery every later milestone depends on — PHPUnit harness, PHPCS baseline, characterization ("golden") tests, DB-transaction helper, and rehearsed release+rollback cycle. It introduces zero schema, zero UI, zero public behavior change, and zero new business functionality.

[PART III contains the complete M0 Implementation Plan sections M0.1–M0.24, including purpose, objectives, expected outcomes, directory structure, files to create/modify, testing infrastructure, CI setup, coding standards, Docker considerations, development workflow, golden test strategy, characterization tests (A–G), regression strategy, DB-transaction helper, release workflow, rollback strategy, documentation, risk assessment, quality gates, and definition of done. Full text retained for canonical reference.]

---

# Implementation Status by Milestone

This table is updated as each milestone is implemented. Each milestone links to its own dedicated implementation plan.

| # | Milestone | Status | Target Release | Plan | Notes |
|---|---|---|---|---|---|
| M0 | Delivery Foundations | ✅ Complete | 1.17.3 | [Part III above] | Golden suite, PHPUnit, PHPCS, CI, DB-transaction helper, release rehearsal |
| M1 | Suppliers | ✅ Complete | 1.18.0 | [docs/milestones/m1-implementation-plan.md](docs/milestones/m1-implementation-plan.md) | Schema v6, `wc_io_suppliers`, Purchasing page, seed migration, schema-shape assertion |
| M2 | Purchase Orders | ✅ Complete | 1.19.0 | [docs/milestones/m2-implementation-plan.md](docs/milestones/m2-implementation-plan.md) | Schema v7, four-state lifecycle, PO events, expected dates/confidence, delayed detection, Purchasing → Purchase Orders admin; no receiving until M5 |
| M3 | Inventory Position | ✅ Complete | 1.20.0 | [docs/milestones/m3-implementation-plan.md](docs/milestones/m3-implementation-plan.md) | Schema unchanged (v7), Resolver + Service (D12 sole calculator), bulk open-line repository reads, Incoming/Position columns with per-supply drill-down, variable-parent presentation rollup; no receiving until M4/M5 |
| M4 | Receipt Engine | ✅ Complete | 1.21.0 | [docs/milestones/m4-implementation-plan.md](docs/milestones/m4-implementation-plan.md) | Schema v8, Goods Receipt Service (sole D3/INV-2 stock mutator, transactional post/void, throw_if_error bridge), Quick Receive Without PO, current-state-relative void reversal, idempotency (token + compare-and-swap, no row locking), Receive Stock admin tab; no PO-linked receiving until M5 |
| M5 | PO Receiving | ✅ Complete | 1.22.0 | [docs/milestones/m5-implementation-plan.md](docs/milestones/m5-implementation-plan.md) | Schema v9, qty_received (full INV-4 formula), PO_Receiving_Sync (sole qty_received owner, auto-transitions partially_received/received), Receive-against-PO admin UI, reconciliation CLI; no ASN/barcode/scanning until a future milestone |
| M6 | Migration & Retirement | ✅ Complete | 1.23.0 | [docs/milestones/m6-implementation-plan.md](docs/milestones/m6-implementation-plan.md) | Schema v10 (migration-tracking columns only), `Batch_Migration_Service` (record materialization, never receiving-replay; per-batch transactions; order-independent), `wp wc-io migrate-batches` CLI (apply/verify/rollback), migrated-receipt void guard, Batch Intake create/apply retired (legacy tables frozen, never deleted) |
| M7 | Storefront | ✅ Complete | 1.24.0 | [docs/milestones/m7-implementation-plan.md](docs/milestones/m7-implementation-plan.md) | Schema unchanged (v10), `Expected_Delivery_Result_Interface`/`Result`/`Resolver`/`Service`/`Renderer` (API v1, sole public API + sole-entry-point rule), built-in `woocommerce_get_availability` renderer, one merchant toggle, two generic extension filters, Invariants M7-1/M7-2/M7-3; this plugin now owns customer-facing expected-delivery presentation |
| M8 | Hardening & GA | ✅ Complete | 1.25.0 | [docs/milestones/m8-implementation-plan.md](docs/milestones/m8-implementation-plan.md) | Schema unchanged (v10); physically removed M6-deprecated Batch Intake create/apply surface (fixture builder rewritten first); closed `PO_Delay`'s `partially_received` gap; repo-wide sibling-plugin-coupling conformance guard; repaired all remaining pre-existing golden-test bugs (integration suite now CI-blocking, 245 tests / 834 assertions, 0 failures); CI hardening (PHP 8.4 aligned across all workflows); GA-scale (200-item) performance confirmation. Zero new domain concepts, zero public API change. First milestone this program calls Version 1.0 / GA ready. |
| M9 | Supplier Observed Lead-Time Statistics | ✅ Complete | 1.26.0 | [docs/milestones/m9-implementation-plan.md](docs/milestones/m9-implementation-plan.md) | Schema unchanged (v10); first post-GA milestone. `Supplier_Lead_Time_Service` (new Internal, not Public, sole-owner boundary — D16) computes read-only average/fastest/slowest/completed-order statistics per supplier from posted Goods Receipts linked to fully-`received` Purchase Orders, one bulk query, no persistence, no N+1 (proven at 10/40/200-supplier scale). Read-only panel on the Supplier admin screen alongside the existing configured-lead-time field. Fills the D8 "designed-for-later" slot named in `docs/admin-guide-suppliers.md` since M1. Zero new domain concepts, zero public API, zero schema change. |
| M10 | Purchase Order Expected-Date Suggestion | ✅ Complete | 1.27.0 | [docs/milestones/m10-implementation-plan.md](docs/milestones/m10-implementation-plan.md) | Schema unchanged (v10); second milestone of the post-GA feature train. `Expected_Date_Suggestion_Service` (new Internal, not Public, sole-owner boundary — D16) combines M9's observed statistics with the supplier's configured `default_lead_time_days` into an advisory expected-date suggestion (observed → configured → none; calendar days only), pre-filling the new-PO creation form via the existing `wp_localize_script`/`po-admin.js` plumbing — no AJAX, no new endpoint. Always overridable, permanently so once edited (INV-M10-1); never runs on the edit-PO screen. `Supplier_Lead_Time_Service` gains one small additive predicate, `is_observed_value_usable()`. Corrects a previously-false documentation claim that this suggestion already existed. Zero new domain concepts, zero public API, zero schema change. |
| M11 | Supplier On-Time Delivery Rate | ✅ Complete | 1.28.0 | [docs/milestones/m11-implementation-plan.md](docs/milestones/m11-implementation-plan.md) | Schema unchanged (v10); third milestone of the post-GA feature train. New `Expected_Deadline` (Internal, narrow pure value/policy class — four methods, guard-closed) owns the "expected_date + grace_days → deadline" formula and known-date eligibility rule (INV-M11-2), consumed by both `PO_Delay` (internally refactored, public contract unchanged) and the further-extended `Supplier_Lead_Time_Service`, which now also returns `on_time_count`/`rated_order_count` per supplier from the same single query M9 already runs — zero additional queries. Unknown-confidence completed orders excluded from both numerator and denominator (INV-M11-1). Displayed read-only alongside Observed Lead Time on the Supplier admin screen. Zero new domain concepts, zero public API, zero schema change. |
| M12 | Supplier List Performance Surface | ✅ Complete | 1.29.0 | [docs/milestones/m12-implementation-plan.md](docs/milestones/m12-implementation-plan.md) | Schema unchanged (v10); fourth milestone of the post-GA feature train. Read-only Observed Lead Time and On-Time Rate columns on the Suppliers list table, populated by one `Supplier_Lead_Time_Service::get_stats_bulk()` call per page (INV-M12-1/2). No new statistics engine, no mutation, no public API. Completes the supplier performance comparison decision point; released as part of the M9–M12 train, v1.29.0. |
| M13 | Printable Purchase Order | ✅ Complete | 1.30.0 | [docs/milestones/m13-implementation-plan.md](docs/milestones/m13-implementation-plan.md) | Schema unchanged (v10); first milestone of the M13–M15 feature train. New `WC_Inventory_Overview_PO_Print_Renderer` (presentation-only, INV-M13-2) renders a standalone, read-only, printable HTML view of a Purchase Order, composed by `PO_Admin::handle_print()` (new `admin_post_wc_io_po_print` action) from the three existing read owners (`Purchase_Orders`, `Purchase_Order_Lines`, `Suppliers`) plus `PO_Statuses::label()` — no new domain owner. Requires `VIEW_PO` capability and a PO-and-action-scoped nonce before any data is read (INV-M13-4). Printable for every status except `draft`. Product/supplier identity always sourced from existing historical snapshot columns, never a live lookup, so deleted products/suppliers cannot break printing. Zero mutation, zero new public API, zero new capability, zero new hook. Level A completion review passed; see `docs/checklists/m13-release-readiness.md`. Released as part of the M13–M15 train, v1.32.0. |
| M14 | Supplier Order History | ✅ Complete | 1.31.0 | [docs/milestones/m14-implementation-plan.md](docs/milestones/m14-implementation-plan.md) | Schema unchanged (v10); second milestone of the M13–M15 feature train. New `WC_Inventory_Overview_Supplier_Order_History_Service` (Internal, not Public — D16, INV-M14-3) composes a paginated, status-inclusive (INV-M14-4) list of a supplier's Purchase Orders exclusively through `Purchase_Orders::count()`/`list()`/`values_bulk()` (new additive method) — no new domain owner. Rendered as a new "Order History" section on the existing Supplier detail screen, reusing the existing `manage_woocommerce` gate; dedicated `wc_io_supplier_order_history_page` pagination parameter. Each row's Ordered/Received Value is PO-line cost only, in that PO's own currency, never summed across POs or currencies and never conflated with landed cost or inventory valuation (INV-M14-2). Zero mutation (INV-M14-1), zero new public API, zero new capability, zero new hook. Level A completion review passed; see `docs/checklists/m14-release-readiness.md`. Released as part of the M13–M15 train, v1.32.0. |
| M15 | Supplier Spend Summary | ✅ Complete | 1.32.0 | [docs/milestones/m15-implementation-plan.md](docs/milestones/m15-implementation-plan.md) | Schema unchanged (v10); third milestone of the M13–M15 feature train. New `WC_Inventory_Overview_Supplier_Spend_Service` (Internal, not Public — D16, INV-M15-3) owns the "committed spend" status rule (BR-M15-1: `placed`/`partially_received`/`received`/`closed_short` only — `draft` and `cancelled` always excluded, INV-M15-1, a genuinely new business decision not reused verbatim from M13/M14) and composes the summary exclusively through the new `Purchase_Orders::spend_summary_for_supplier()` (self-contained aggregate; does not compose through `list()`) — no new domain owner. Rendered as a new "Spend Summary" section on the existing Supplier detail screen, before Observed Lead Time / Order History, reusing the existing `manage_woocommerce` gate. One row per currency; never blended or converted (INV-M15-2) — `po_count` is `COUNT(DISTINCT po.id)` scoped per currency row (BR-M15-5), so a PO with lines in more than one currency may count once in more than one row. A true database-level aggregate: exactly 1 query regardless of history size (proven at 200-PO/3-currency scale), unlike M14's page-scoped 3-query contract. Zero mutation, zero new public API, zero new capability, zero new hook. Level A completion review passed; see `docs/checklists/m15-release-readiness.md`. Completes the M13–M15 train, v1.32.0. |
| M16 | PO Expected-Date & Delay Transparency | ✅ Complete | 1.33.0 | [docs/milestones/m16-implementation-plan.md](docs/milestones/m16-implementation-plan.md) | Schema unchanged (v10); standalone post-v1.32.0 release, v1.33.0. Three read-mostly surfaces, no new domain owner: (1) `Expected_Date_Suggestion_Service`'s return shape gains `sample_count`/`average_days` (BR-M16-2), sourced from the same already-fetched `Supplier_Lead_Time_Service` stats, so the New PO screen can show *why* a suggestion was made ("Suggested from this supplier's delivery history (N orders, avg D days)." / "...configured default (D days)."), never overloading the resolved `days` value as evidence. (2) A new Settings-tab field exposes the existing `WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS` option (previously editable only via raw `update_option()`) through an explicit validate-or-preserve contract (BR-M16-4) — invalid/missing input always leaves the stored value untouched, deliberately not `absint()`-style coercion. (3) The Inventory Position drilldown (M3) gains Supplier/Status columns from two additional `SELECT` columns on the already-executing `query_open_lines()` query — zero new query, fixed column order (BR-M16-8). Zero domain/operational mutation; one existing settings-option write. Zero new public API, zero new capability, zero storefront impact. Level A completion review passed; see `docs/checklists/m16-release-readiness.md`. |
| M18 | Admin Controller Decomposition, Phase 1 | ✅ Complete | 1.35.0 | [docs/milestones/m18-implementation-plan.md](docs/milestones/m18-implementation-plan.md) | Schema unchanged (v11); first milestone of the M18–M19 feature train. Pure internal refactor extracting the Dashboard and Settings tabs of the `WC_Inventory_Overview_Plugin` god class (2,706 lines) into dedicated `WC_Inventory_Overview_Dashboard_Controller`/`WC_Inventory_Overview_Settings_Controller` classes, characterization-tests-first. Zero schema change, zero new public API, zero new capability, zero behavior change (BR-M18-1..21, byte-identical pre/post extraction). Plugin: 2,706 → 1,561 lines. Overview, Restock, Movements, Order Profit, Product Profitability remain on Plugin, reserved for later phases. Level A completion review passed; see `docs/checklists/m18-release-readiness.md`. Released as part of the M18–M19 train, v1.36.0. |
| M19 | Admin Controller Decomposition, Phase 2 | ✅ Complete | 1.36.0 | [docs/milestones/m19-implementation-plan.md](docs/milestones/m19-implementation-plan.md) | Schema unchanged (v11); second milestone of the M18–M19 feature train. Extracts the three read-only reporting tabs — Movements, Order Profit, Product Profitability — into a new `WC_Inventory_Overview_Reporting_Controller` (BR-M19-1..9, INV-M19-1..15), characterization-tests-first, exact pre/post query-count parity proven (2/3/4 queries respectively). Zero mutation surface in the entire extracted cluster (unlike M18, which retained Settings' pre-existing save/exchange-rate/danger-zone mutation paths unchanged). Overview and Restock — the two remaining tabs, both carrying real mutation surface — are deliberately excluded and reserved for a future, not-yet-scheduled Phase 3. Plugin: 1,561 → 1,230 lines (2,706 → 1,230 combined with M18, ~55% total reduction). Level A completion review passed; see `docs/checklists/m19-release-readiness.md`. Completes the M18–M19 train, v1.36.0. |

**Release note:** **`v1.36.0` is tagged and published** — bundled M18–M19 Admin Controller Decomposition feature train; see [`docs/GITHUB_RELEASE_NOTES_1.36.0.md`](docs/GITHUB_RELEASE_NOTES_1.36.0.md). Intermediate development version `1.35.0` (M18 alone) was never tagged. **`v1.32.0` is tagged and published** — bundled M13–M15 Purchasing & Supplier Insights feature train; see [`docs/GITHUB_RELEASE_NOTES_1.32.0.md`](docs/GITHUB_RELEASE_NOTES_1.32.0.md). Intermediate development versions `1.30.0`/`1.31.0` were never tagged. **`v1.29.0` is tagged and published** — bundled M9–M12 feature train (+ CI recovery); see [`docs/GITHUB_RELEASE_NOTES_1.29.0.md`](docs/GITHUB_RELEASE_NOTES_1.29.0.md). Intermediate development versions `1.26.0`/`1.27.0`/`1.28.0` were never tagged. Prior releases through `v1.25.0` remain tagged and published; see their respective `docs/GITHUB_RELEASE_NOTES_*.md`.

---

**Planning baseline:** Detailed bodies for Part I §6–§20, Delivery Roadmap R1–R9, and M0.1–M0.24 were **never committed to this repository** (the bracket placeholders above are stubs only). For milestone planning today, treat **Part I §1–§5** (executive summary, current state, decisions D1–D19, invariants INV-1–INV-8, entity model §5.1–§5.2), **this status table**, and — for any milestone from M9 onward — **[`docs/ARCHITECTURE_BASELINE_v1.24.0.md`](docs/ARCHITECTURE_BASELINE_v1.24.0.md)** as the authoritative baseline. M1 detail: `docs/milestones/m1-implementation-plan.md`.
