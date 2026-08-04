# ADR-0001 — Inbound domain ownership

## Status

Accepted (documentation-only boundary ratification, pre-M2).

## Context

The Biopentra stack runs two first-party warehouse plugins:

- **WC Inventory Overview** (this plugin) — inventory, purchasing, receiving, costing.
- **MP Commerce Fulfillment (MPCF)** — outbound warehouse execution for customer orders.

An ownership review ratified that the **complete inbound inventory domain** belongs
in this plugin. MPCF ADR-0007 records the same boundary from the outbound side.
Duplicate ownership caused a mis-planned receiving milestone inside MPCF and
conflicting location-topology claims in both roadmaps.

## Decision

1. **wc-inventory-overview owns the complete inbound inventory domain:**
   suppliers, purchase orders, goods receipts, receiving, inventory position,
   inventory movements, stock ledger, landed/actual cost, inventory
   reconciliation, warehouse location master data (hierarchy, bins, shelves,
   aisles), and **all inbound stock mutation**.

2. **MPCF owns outbound warehouse execution only:** fulfillment workflow,
   picking, packing, shipments, packages, tracking, fulfillment documents,
   operator UX, and the fulfillment audit trail.

3. **WooCommerce owns:** product catalog, stock-on-hand as the commerce record,
   customer orders, checkout, and the payment/refund lifecycle.

4. **Exactly one canonical owner per business concept.** See
   [`docs/OWNERSHIP.md`](../OWNERSHIP.md). Duplicate ownership is prohibited.

5. **Ownership changes** require an Accepted ADR in **every affected repository**
   before code or schema changes.

6. **No direct cross-plugin table access.** Neither plugin may read or write
   the other's database tables.

7. **Future integration** uses narrow documented WordPress hooks or versioned
   read contracts on the publisher side only — introduced when a concrete need
   exists, each with its own ADR.

## Rejected alternatives

- Purchase orders or receiving inside MPCF.
- A second inbound stock writer or parallel inventory ledger in MPCF.
- Cross-plugin database coupling instead of hooks/contracts.

## Consequences

- M2 Purchase Orders and later receiving milestones are planned and implemented
  **only in this repository**.
- MPCF may consume read-only signals (e.g. pick-path hints) via a future contract;
  it does not own location master data or inbound mutations.
- This ADR is the wc-inventory-overview mirror of MPCF ADR-0007; both must stay
  aligned on future boundary changes.

## Related

[`docs/OWNERSHIP.md`](../OWNERSHIP.md), [`CLAUDE.md`](../../CLAUDE.md) Part I §1–§5,
MPCF `docs/adr/0007-inbound-domain-belongs-to-inventory-plugin.md`.
