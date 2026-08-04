# Domain ownership

Every business concept has **exactly one canonical owner**. Duplicate ownership
is prohibited. Reassigning a concept requires an Accepted ADR in **every**
affected repository before implementation.

Authoritative decision: [ADR-0001](adr/0001-inbound-domain-ownership.md). Outbound
mirror: MP Commerce Fulfillment
[ADR-0007](https://github.com/magpern/mp-commerce-fulfillment/blob/main/docs/adr/0007-inbound-domain-belongs-to-inventory-plugin.md).

## Registry

| Business concept | Owner |
|---|---|
| Product catalog | WooCommerce |
| Stock quantity (on hand) | WooCommerce |
| Product prices | WooCommerce |
| Customer | WooCommerce |
| Customer order | WooCommerce |
| Checkout and payment | WooCommerce |
| Refunds and cancellations (initiation) | WooCommerce |
| Supplier | wc-inventory-overview |
| Purchase order | wc-inventory-overview |
| PO line / incoming supply | wc-inventory-overview |
| Goods receipt | wc-inventory-overview |
| Receiving (supplier delivery) | wc-inventory-overview |
| Receiving discrepancy | wc-inventory-overview |
| Inventory movement | wc-inventory-overview |
| Stock ledger | wc-inventory-overview |
| Inventory position / incoming | wc-inventory-overview |
| Warehouse location (hierarchy) | wc-inventory-overview |
| Bin / shelf / aisle | wc-inventory-overview |
| Item-to-location assignment | wc-inventory-overview |
| Inventory cost / weighted average | wc-inventory-overview |
| Landed cost | wc-inventory-overview |
| Inbound stock mutation | wc-inventory-overview |
| Inventory reconciliation | wc-inventory-overview |
| Fulfillment (outbound) | MPCF |
| Warehouse workflow (outbound states) | MPCF |
| Picking progress | MPCF |
| Packing progress | MPCF |
| Shipment | MPCF |
| Package | MPCF |
| Tracking (outbound consignment) | MPCF |
| Packing slip | MPCF |
| Picking list (fulfillment document) | MPCF |
| Fulfillment audit trail | MPCF |
| Operator workflow UX (outbound) | MPCF |
| Per-warehouse queue partition | MPCF |
| Pick-path hint at intake (`location_snapshot`) | MPCF (immutable snapshot; not inventory authority) |

## Integration rule

Cross-plugin integration uses **documented hooks or versioned read contracts
only**. No direct reads or writes of another plugin's tables.

## Planning baseline

Inbound milestone planning uses [`CLAUDE.md`](../CLAUDE.md) Part I §1–§5 and the
milestone status table in that file.
