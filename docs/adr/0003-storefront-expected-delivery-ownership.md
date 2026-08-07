# ADR-0003 — Storefront expected-delivery ownership

## Status

Accepted. Implemented in M7 (v1.24.0).

## Context

`CLAUDE.md`'s Architecture v1.0 (§2, pre-M7 text) named sibling plugin
"Biopentra Storefront" as "the natural consumer for customer-facing expected
dates," rendering front-end stock text via a `woocommerce_get_availability`
filter. M7 planning verified this in source before drafting the milestone:

- `wp-content/plugins/biopentra-storefront/` is an **empty directory** — zero
  files.
- A repository-wide grep of the WordPress install found **no code outside
  WooCommerce core** filtering `woocommerce_get_availability`,
  `woocommerce_get_availability_class`, or `woocommerce_get_availability_text`.
- `docs/architecture-audit.md` (pre-M7) stated plainly: "No public REST routes
  or storefront-facing hooks."

The intended external consumer does not exist. Without a decision, M7's
built-in renderer would read as a temporary stopgap that a future contributor
might reasonably try to "clean up" once Biopentra Storefront eventually ships
— removing customer-facing functionality that has no other home.

## Decision

This plugin owns customer-facing expected-delivery **presentation**, not as a
stopgap but as a permanent architectural position, for as long as no external
consumer exists. Concretely:

1. `WC_Inventory_Overview_Expected_Delivery_Renderer` is **the** storefront
   renderer, not a fallback alongside another one. It is the only file in
   `includes/` that touches `woocommerce_get_availability`, guard-enforced
   (`tests/unit/expected-delivery/test-expected-delivery-architecture.php`).
2. Ownership is gated by one merchant setting
   (`wc_io_expected_delivery_renderer_enabled`), not by detecting or
   coordinating with any other plugin. The renderer contains no
   `class_exists()`/`function_exists()` check against a named third-party
   symbol and no `remove_filter()` against another plugin's hooks —
   guard-enforced.
3. A future external consumer (Biopentra Storefront or otherwise) integrates
   through exactly two generic extension points:
   `wc_io_storefront_render_expected_delivery` (a per-render opt-out) and
   `wc_io_expected_delivery_text` (copy override). It never needs this
   plugin to know its name, and this plugin never needs to know the consumer
   exists.
4. The public contract is `WC_Inventory_Overview_Expected_Delivery_Result_Interface`,
   not the concrete `Result` class, and `Expected_Delivery_Service` is the
   sole public API (`API_VERSION = 1`, independent of the plugin version).
   `Expected_Delivery_Resolver` may never be called directly from outside
   the Service — the sole-entry-point rule, guard-enforced now so a future
   REST/Store API/GraphQL/Blocks consumer physically cannot bypass the
   Service to re-derive the answer from Inventory Position or PO data.
5. `api_version()` on a Result is **informational only**. Consumers must
   never branch on it; the Service owns compatibility, and a
   backward-incompatible contract change is expressed as a new
   `API_VERSION`, never as a runtime `if` in consumer code.
6. Persistent cross-request caching is prohibited for API v1 (see
   `docs/milestones/m7-implementation-plan.md` §Caching): both `is_delayed`
   and Invariant M7-1 compare a stored date against *today*, so the answer
   can go stale with zero write-side trigger. Only request-scoped
   memoization is permitted.

## Consequences

- If Biopentra Storefront (or any other consumer) ships later, it opts the
  built-in renderer out per-product via
  `wc_io_storefront_render_expected_delivery` and calls
  `Expected_Delivery_Service::get_for_product()` / `get_for_products_bulk()`
  itself. No code change is required in this plugin.
- Removing or disabling the built-in renderer without providing a
  replacement is a **product regression**, not a cleanup — it deletes the
  only implementation of `CLAUDE.md`'s customer-facing policy ("the earliest
  credible expected receipt, worded by confidence").
- `docs/architecture-audit.md`'s "No public REST routes or storefront-facing
  hooks" statement is corrected as of M7; the storefront-facing hook
  (`woocommerce_get_availability`) and the sole-entry-point rule are
  documented in the M7 section there instead.
- Any future REST, Store API, GraphQL, or Blocks integration must delegate to
  `Expected_Delivery_Service`; this ADR does not authorize a second,
  independently-derived expected-delivery answer on any channel.

## Related

`docs/milestones/m7-implementation-plan.md` (full rationale, findings 1 and
11, Milestone boundaries, §Architecture); `docs/api-expected-delivery.md`
(consumer-facing contract); `docs/admin-guide-storefront-availability.md`
(merchant-facing behavior);
`tests/unit/expected-delivery/test-expected-delivery-architecture.php`
(guard enforcement).
