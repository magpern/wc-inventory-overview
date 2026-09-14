# WC Inventory Overview 1.44.3 — release notes

## Fixed

- **Quantity spinner step.** Every "Qty"/"Quantity" number input (Purchase
  Order lines, Goods Receipt lines, Stock Adjustment lines, Replenishment
  Planning, and the product/variation default replenishment quantity field)
  used `step="0.0001"`, so the native up/down spinner incremented by 0.0001
  instead of a whole unit. Changed to `step="1"` so the spinner moves by 1.
  Decimal quantities (e.g. 3.5) remain fully enterable and submittable:
  plugin-owned forms gained `novalidate` (they're fully re-validated
  server-side); the product/variation default-qty field, which lives inside
  WooCommerce's own product-edit form, is handled via a scoped
  `invalid`-event listener instead, so no other field on that shared form
  loses native validation.

No PHP validation changed — quantity is parsed as a float server-side in
every affected path regardless of the HTML step attribute. No schema change
(`DB_VERSION` stays 12), no other behavior change.

## Install

Deploy `wc-inventory-overview` **1.44.3** / tag **`v1.44.3`**.

Rollback: **1.44.2** / `v1.44.2`.
