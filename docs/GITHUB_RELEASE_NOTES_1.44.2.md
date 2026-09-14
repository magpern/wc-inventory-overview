# WC Inventory Overview 1.44.2 — release notes

## Fixed

- **Goods Receipt line editor "Add line" / "Remove" buttons.** `assets/po-admin.js`
  (shared by the Purchase Order and Goods Receipt admin screens) only wired up
  the PO-namespaced line-repeater selectors. The Goods Receipt line editor
  renders its own, separately-namespaced markup that nothing ever handled —
  Add line and Remove were dead buttons on every Goods Receipt screen
  (New/Quick Receive Without PO and Receive Against PO alike).

No schema change (`DB_VERSION` stays 12), no other behavior change.

## Install

Deploy `wc-inventory-overview` **1.44.2** / tag **`v1.44.2`**.

Rollback: **1.44.1** / `v1.44.1`.
