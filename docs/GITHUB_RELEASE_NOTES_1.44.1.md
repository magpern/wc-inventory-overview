# WC Inventory Overview 1.44.1 — release notes

## Fixed

- Purchase Order save request-token TTL raised from 10 to 30 minutes
  (`WC_Inventory_Overview_PO_Request_Token::TTL`). New/edit Purchase Order
  forms with several lines and supplier lookups could legitimately take
  longer than 10 minutes to fill in, causing the one-shot token to expire
  and Save to fail with "This form has already been submitted or expired."

No schema change (`DB_VERSION` stays 12), no other behavior change.

## Install

Deploy `wc-inventory-overview` **1.44.1** / tag **`v1.44.1`**.

Rollback: **1.44.0** / `v1.44.0`.
