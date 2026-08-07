# WC Inventory Overview — Purchasing & Incoming Inventory: Complete Architecture & Implementation Guide

**Master document:** Authoritative canonical source for this plugin's architecture (frozen), roadmap (frozen), and milestone implementation plans. Updated by each milestone's implementation pass. Created per Milestone M1 resolution to consolidate Architecture v1.0 Parts I–III verbatim, fixing M0's dangling `docs/testing.md` references to `../CLAUDE.md`.

**Auto-loaded by Claude Code:** this file is discovered at the plugin repository root and serves as project-level instructions/context for the IDE extension and CLI tool.

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
- **Business context**: single warehouse, small EU shop, EUR base, suppliers invoicing EUR/USD/SEK, 1–3 admin users. Sibling plugin Biopentra Storefront renders front-end stock text via a `woocommerce_get_availability` filter — the natural consumer for customer-facing expected dates.

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
| M7 | Storefront | ⏳ Planned | 1.24.0 | docs/milestones/m7-implementation-plan.md *(not yet written)* | Expected date exposure, confidence-driven wording |
| M8 | Hardening & GA | ⏳ Planned | 2.0.0 | docs/milestones/m8-implementation-plan.md *(not yet written)* | Integrity checks, conformance audit, GA readiness |

**Release note:** v1.18.0 (M1) is on `main`. GitHub tag **`v1.18.0` is pending** — see `docs/GITHUB_RELEASE_NOTES_1.18.0.md` before tagging.

---

**Planning baseline:** Detailed bodies for Part I §6–§20, Delivery Roadmap R1–R9, and M0.1–M0.24 were **never committed to this repository** (the bracket placeholders above are stubs only). For milestone planning today, treat **Part I §1–§5** (executive summary, current state, decisions D1–D19, invariants INV-1–INV-8, entity model §5.1–§5.2) and **this status table** as the authoritative baseline. M1 detail: `docs/milestones/m1-implementation-plan.md`.
