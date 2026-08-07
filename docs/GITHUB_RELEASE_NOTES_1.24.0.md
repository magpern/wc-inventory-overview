# WC Inventory Overview 1.24.0

**Canonical standalone release** from [magpern/wc-inventory-overview](https://github.com/magpern/wc-inventory-overview).

## Prerequisite

Upgrade from **1.23.0** (M6 Migration & Retirement, schema v10).

## What changed

**Milestone M7 — Storefront Expected Delivery** — the first milestone that a customer sees. Exposes exactly **one** governed fact for an out-of-stock item: the earliest credible expected receipt, worded by confidence ("Expected back around 1 September" / "Expected during week 36" / "Expected soon") — never suppliers, PO numbers, quantities, or delay details. Ships behind a stable public API (`API_VERSION = 1`, versioned independently of the plugin version). **Schema unchanged (v10)** — zero new tables, columns, or indexes; M7 derives, it does not store.

- **`WC_Inventory_Overview_Expected_Delivery_Service`** — the sole public API (`get_for_product()`/`get_for_products_bulk()`), consuming Inventory Position (D12) exclusively. The public contract is the **interface**, `WC_Inventory_Overview_Expected_Delivery_Result_Interface`, not the concrete class — `api_version()` is informational only, never a runtime branch point. Any future REST/Store API/GraphQL/Blocks integration must delegate to this Service (the sole-entry-point rule, guard-enforced now, before a second consumer exists).
- **`WC_Inventory_Overview_Expected_Delivery_Resolver`** — pure, deterministic selection algorithm. **Invariant M7-1**: a customer-safe line's date is never in the past, regardless of the upstream `is_delayed` flag — closes a concrete customer-facing defect (a partially-received PO's remaining outstanding, or a non-zero delay grace period, could otherwise leave a stale date on the storefront indefinitely).
- **`WC_Inventory_Overview_Expected_Delivery_Renderer`** — the built-in, generic storefront renderer (filters `woocommerce_get_availability`). Not a fallback: the intended external consumer plugin ("Biopentra Storefront") was verified to be an empty directory with nothing filtering that hook, so this is the only renderer — see [`docs/adr/0003-storefront-expected-delivery-ownership.md`](../docs/adr/0003-storefront-expected-delivery-ownership.md). **Invariant M7-2**: an out-of-stock variable parent presents "Expected soon," never a specific date, regardless of how confident an individual variation's date is. **Invariant M7-3**: at most one product-scoped and one variation-scoped SQL query per rendered page, regardless of item count — measured, not asserted in prose (20 vs 40 mixed products issue the *same* query count).
- **One new setting**, `wc_io_expected_delivery_renderer_enabled` (default `Yes`), on the existing Settings tab's new **Storefront** section.
- **Two extension filters**: `wc_io_storefront_render_expected_delivery` (generic per-render opt-out, checked before any query runs) and `wc_io_expected_delivery_text` (copy override).
- **71 new dedicated tests / 218 assertions** (Result/Resolver contract and pure algorithm, architecture guards including the sole-entry-point rule and D12 extended, Service integration, Renderer integration including the ISO-week year boundary, settings, and Invariant M7-3's equality-based performance test) — **442 tests / 1,768 assertions in the M1–M7 focused suite, all passing** (0 failures; 7 pre-existing, documented risky `Test_DB_Transaction` tests unrelated to M7).

**Explicitly not in this release:**

- No schema change of any kind — `DB_VERSION` stays `10`.
- No new REST endpoints, Store API endpoints, GraphQL, or template overrides.
- No structured data (`woocommerce_structured_data_product`) — cut deliberately; see the M7 implementation plan's findings and this release's ADR.
- No named dependency on any sibling plugin — no `class_exists()`/`function_exists()` check against a third-party symbol, no `remove_filter()` against another plugin's hooks, guard-enforced.
- No persistent cross-request caching — request-scoped memoization only, because whether a date is still customer-safe depends on *today* with zero write-side invalidation trigger.
- No mutation of any kind — no stock, no PO, no Goods Receipt, no schema, no option except the one new setting.
- No changes to Inventory Position (M3), Goods Receipt (M4), PO Receiving (M5), or Migration (M6) architecture — all consumed as-is, none modified.

## Install / upgrade

1. Download **`wc-inventory-overview-1.24.0.zip`** from this release.
2. Upload via **Plugins → Add New → Upload**, or use **Dashboard → Updates** on production.
3. No schema step — `DB_VERSION` stays `10`, no `ALTER` runs, no upgrade routine fires.
4. The renderer is active immediately on upgrade (`wc_io_expected_delivery_renderer_enabled` defaults to `Yes`). Verify on a real out-of-stock product with a placed PO carrying an exact expected date — the storefront should read "Expected back around …". If you need to disable it, go to **Inventory & Profit → Settings → Storefront** and set "Enable Expected Delivery display" to **No** — takes effect immediately, no deploy needed.

## Before tagging

Per [docs/release-runbook.md](release-runbook.md#m7-storefront): confirm CI (unit, M1–M7 blocking, and cumulative integration suites) passes with zero new failures, confirm `DB_VERSION` is still `10` and the schema assertion is `ok: true`, walk through the storefront-toggle validation (setting on → real customer-safe date renders correctly → toggle off restores stock WooCommerce text → toggle back on), verify all four customer-visible states on a real product, verify a variable parent with a customer-safe child shows "Expected soon" (never a date) while the specific variation shows its own precise wording, and confirm Quick Restock / Cost Adjustment / Inventory Overview / Goods Receipts / PO Receiving / batch migration CLI / Supplier admin remain fully functional.

## Rollback

**M7 has the cleanest rollback story of any milestone in this program — cleaner even than M6's.**

- **Instant, no deploy:** set `wc_io_expected_delivery_renderer_enabled` to `No`. Storefront output returns to stock WooCommerce immediately. Nothing else in the plugin changes behavior.
- **Code rollback 1.24.0 → 1.23.0:** unconditionally safe. M7 wrote no data, changed no schema, and mutated nothing. The only persistent artifact is one `wp_options` row that 1.23.0 simply never reads. There is no data-safety reason to remove it, and no schema to reverse.
- Unlike M4/M5 (stock/`qty_received` effects survive a code rollback) and unlike M6 (additive migrated rows survive), **M7 leaves nothing behind at all**.

See [docs/rollback-plan.md](rollback-plan.md) for the full explanation.

Changelog: [CHANGELOG.md](../CHANGELOG.md)
