# Milestone M2 Implementation Plan — Purchase Orders (schema v7)

**Status: Complete — target release v1.19.0 on `feature/m2-a-schema-foundation`.**

**Prerequisite:** v1.18.1 (M1 Purchasing PRG hotfix) on schema v6.

**Architecture context:** [`CLAUDE.md`](../../CLAUDE.md) Part I §1–§5 (decisions D1–D19, invariants INV-1–INV-8). **Numbering concurrency:** [`docs/adr/0002-po-number-allocation-concurrency.md`](../adr/0002-po-number-allocation-concurrency.md).

## Summary

Milestone M2 introduces the Purchase Order aggregate as a living business document: schema v7 tables, four-state lifecycle, append-only PO events, expected-receipt dates with confidence, delayed detection, never-reuse numbering, and a full Purchasing admin surface — **without receiving, stock mutation, or costing changes**.

## Domain model (M2 scope)

### Four-state lifecycle

| Status | Meaning | Outgoing transitions |
|--------|---------|----------------------|
| `draft` | Editable work-in-progress | → `placed`, `cancelled` |
| `placed` | Committed purchasing order | → `cancelled`, `closed_short` |
| `cancelled` | Terminal — order voided | *(none — absorbing)* |
| `closed_short` | Terminal — outstanding qty written off | *(none — absorbing)* |

Receiving states (`partially_received`, `received`) and `qty_received` counters arrive in **M5**. M2 schema assertion **forbids** a `qty_received` column on PO lines.

### Numbering

- Format: `PO-{YYYY}-{NNNN}` (minimum 4 digits; grows past 9999).
- Numbers are **never reused**; gaps after failed creates, draft deletes, or exhausted retries are expected.
- Allocation uses a WordPress option map; uniqueness is enforced by the `UNIQUE KEY po_number` index. See ADR-0002 for the documented concurrency limitation.

### Reason codes

Optional structured classification on PO events: `supplier_change`, `price_change`, `quantity_change`, `schedule_change`, `manual`, `other`. Stored for future reporting; not rendered in the admin timeline.

### Duplicate

`duplicate` creates a new **draft** PO from any non-draft source, copying header fields and lines with fresh numbering and a provenance event. Source PO is unchanged.

### Explicitly out of scope (M5+)

- Goods Receipt / Receive Stock UI
- `qty_received` maintenance or outstanding-qty reconciliation against receipts
- WooCommerce stock or weighted-average cost mutation via PO actions
- Printable PO templates or public print hooks

## Schema (DB_VERSION 7)

New tables via `dbDelta()` in `WC_Inventory_Overview_Install`:

| Table | Purpose |
|-------|---------|
| `wc_io_purchase_orders` | PO header: number, supplier, currency, status, dates, confidence, snapshots |
| `wc_io_purchase_order_lines` | Line items: product/variation, qty_ordered, qty_cancelled, unit cost, optional line-level expected date/confidence |
| `wc_io_po_events` | Append-only audit log with optional `reason_code` |

`assert_schema_shape()` writes canonical option `wc_io_schema_assertion` (mirrored to `wc_io_schema_v7_assertion`). Purchasing menu remains gated on `ok: true`.

## Admin surfaces delivered

**WooCommerce → Purchasing → Purchase Orders** (default tab):

- **List:** paginated `WP_List_Table`, status views, delayed filter, search; row actions per lifecycle.
- **Detail:** create/edit draft, view placed/terminal read-only; header fields (supplier, currency, dates, confidence, reference, note); dynamic line editor (product search, qty, cost, line-level expected overrides).
- **Timeline:** append-only PO event history on detail screen.
- **Lifecycle actions** (PRG `admin-post` + nonces + request tokens): save, place, cancel, close short, delete draft, duplicate.
- **Assets:** `assets/purchasing.css`, `assets/po-admin.js`.

Mutations flow exclusively through `WC_Inventory_Overview_PO_Service` inside DB transactions.

## Implementation map

| Layer | Primary classes |
|-------|-----------------|
| Lifecycle / vocabulary | `PO_Statuses`, `PO_Lifecycle`, `PO_Confidence`, `PO_Reason_Codes`, `PO_Quantities`, `PO_Expected`, `PO_Delay` |
| Persistence | `Purchase_Orders`, `Purchase_Order_Lines`, `PO_Events` |
| Domain service | `PO_Service` (create, update, place, cancel, close short, duplicate, line CRUD) |
| Numbering / validation | `PO_Numbering`, `PO_Validation`, `PO_Product_Validator` |
| Admin | `PO_Admin`, `Purchase_Orders_List_Table`, `Purchasing_Page`, `Purchasing_Caps`, `PO_Request_Token` |
| Install | `Install::DB_VERSION = '7'`, `expected_schema_v7()`, forbidden-column guard |

## Tests

PHPUnit suites under `tests/unit/purchase-orders/` (lifecycle, numbering, service, validation, delay, admin, architecture) plus extended schema-shape assertion in `tests/integration/install/`. Run via Docker harness: `tests/docker/docker-compose.phpunit.yml`.

## Quality gates

- [ ] Schema v7 upgrade on production-data copy; `wc_io_schema_assertion` reports `ok: true`.
- [ ] **No stock change:** place/cancel/close-short/duplicate on a PO leaves WooCommerce `_stock` and costing meta unchanged.
- [ ] M0 golden suite still green (zero fixture edits).
- [ ] M2 unit/integration tests pass in Docker PHPUnit harness.
- [ ] CI lint + release ZIP build green.

## Definition of done

- [ ] `DB_VERSION` is `'7'`; three PO tables exist with expected columns and `po_number` unique index.
- [ ] Four-state lifecycle enforced; terminal statuses are absorbing.
- [ ] PO numbers allocated as `PO-{YYYY}-{NNNN}`; never reused.
- [ ] Purchasing → Purchase Orders tab functional end-to-end for `manage_woocommerce` users.
- [ ] No receiving columns, no stock mutations, no print hooks.
- [ ] ADR-0002 referenced in release/docs for numbering concurrency.
- [ ] CHANGELOG, readme, release notes, and runbook updated for v1.19.0.
