# Milestone M7 Implementation Plan — Storefront Expected Delivery

**Materialization target:** `docs/milestones/m7-implementation-plan.md`. Target release: plugin **v1.24.0**. **`DB_VERSION` unchanged at 10** — M7 adds no table, no column, no index, and no `expected_schema_v11()`. Roadmap ordering unchanged; M7 follows M6 exactly as `CLAUDE.md`'s frozen status table states.

---

## Context

Every milestone so far has been internal. M1–M2 built the purchasing record, M3 turned it into a read-only aggregation (Inventory Position), M4–M5 made receiving real, and M6 consolidated the plugin onto one receiving history. Nothing shipped in M0–M6 is visible to a customer: `docs/architecture-audit.md:141` still reads "No public REST routes or storefront-facing hooks," and a repository-wide grep confirms it — zero `woocommerce_get_availability` filters, zero REST routes, zero shortcodes, zero frontend enqueues.

M7 is the first milestone that a customer sees. It exposes exactly **one** governed fact, fixed by `CLAUDE.md:26`:

> The **storefront** may show only one carefully governed fact: the earliest credible expected receipt, worded by confidence ("Expected back around 1 September" / "Expected during week 36" / "Expected soon") — never suppliers, PO numbers, quantities, or delay details.

The raw material already exists and is authoritative. `Inventory_Position_Service::get_positions_bulk()` already returns, per item, the full set of open PO lines with `outstanding`, `expected_date`, `expected_confidence` and `is_delayed` already resolved (line-overrides-header inheritance applied in SQL). M7 adds no new purchasing concept — it adds a **selection rule** (which of those lines is the one credible answer) and a **presentation rule** (how to word it), behind a stable public API and one merchant toggle.

Two facts discovered during planning materially shape this plan and are recorded here rather than assumed:

1. **The intended external consumer does not exist.** `CLAUDE.md:50` names sibling plugin "Biopentra Storefront" as "the natural consumer for customer-facing expected dates," rendering stock text via `woocommerce_get_availability`. Verified: `wp-content/plugins/biopentra-storefront/` is an **empty directory** — zero files — and nothing outside WooCommerce itself filters `woocommerce_get_availability`, `_text`, or `_class` anywhere in the install. The built-in renderer designed here is therefore not a standalone fallback; **it is the only renderer**, and this plugin now owns customer-facing expected-delivery presentation. M7 records that ownership shift (see §Documentation).
2. **`is_delayed` alone is not a sufficient credibility test.** This is the single most important correction in this plan and is treated as a blocker in §Architecture — Invariant M7-1.

M7 is deliberately small in code and disproportionately careful in contract. Four classes, no schema change, one setting, two filters. What makes it worth planning at this length is that it is the first thing this plugin says to someone who is not an administrator, and the first API it must keep stable for consumers it cannot see.

---

## Objectives

1. Expose the earliest **credible** expected receipt for an unavailable purchasable item, and nothing else, through a stable public API (`API_VERSION = 1`) that is versioned independently of the plugin version.
2. Introduce a dedicated Expected Delivery domain boundary (Result interface + Result / Resolver / Service / Renderer) in which the **Service is the sole public API**, the **returned contract is an interface**, and everything else is internal implementation detail.
3. Consume Inventory Position (M3) as the authoritative source — never re-query Purchase Orders, receipt tables, or receiving repositories to recompute the same information (D12).
4. Ship a generic built-in renderer that makes the plugin complete and production-ready **with no external consumer plugin installed**, while remaining free of any named sibling-plugin coupling.
5. Provide exactly two generic extension points — one merchant setting, one per-render opt-out filter — sufficient for any consumer plugin to suppress the built-in output and render its own.
6. Guarantee bounded query growth on storefront catalog pages: one Inventory Position round-trip per page, not one per product.
7. Mutate nothing. No stock, no PO, no Goods Receipt, no `qty_received`, no schema, no option except the one new setting the merchant sets deliberately.

---

## Required analysis — findings

Verified in source before drafting the rest of this plan. Each is load-bearing for a decision below.

1. **The Position Service contract.** `get_positions_bulk( array $product_on_hand, array $variation_on_hand ): array` returns, per item ID: `on_hand` (float, echoed back verbatim), `incoming` (float), `position` (float), `incoming_delayed` (bool), `incoming_lines` (list). Cost: exactly one SQL query per **non-empty** map, so 0–2 per call regardless of item count. Every requested ID gets an entry even with no open lines.
2. **The incoming-line shape.** Each line is a raw `$wpdb` `ARRAY_A` row — **every value is a string**, except `expected_date` which is `Y-m-d` **or `null`**: `line_id`, `po_id`, `po_number`, `product_id`, `variation_id`, `outstanding`, `expected_date`, `expected_confidence` (`'exact'|'estimated'|'unknown'`, never empty — SQL `COALESCE`s to `'unknown'`), `is_delayed` (`'1'|'0'`). **There is no supplier field** — the supplier lives on the PO header and is never joined. Line-overrides-header inheritance for date and confidence is already applied in SQL (`purchase-order-lines.php:419-420`).
3. **`is_delayed` is `'0'` in two states where the date is nonetheless in the past.** `PO_Delay::sql_line_delayed_predicate()` (`po-delay.php:126`) opens with `po.status = 'placed'`, while the open-lines query accepts `IN ('placed','partially_received','received')` (`purchase-order-lines.php:434`) — so a partially-received PO's remaining outstanding is **never** flagged delayed (documented gap, `docs/architecture-audit.md:298`). Separately, `wc_io_po_delay_grace_days` (default 0) shifts the threshold, so with grace 7 a date a week past is not delayed. In M3 this was an admin cosmetic gap. On a storefront it becomes a customer-facing false statement. **Blocker — resolved by Invariant M7-1.**
4. **Position/Resolver deliberately never cache.** Service docblock: "never caches." M3 lists caching under Hard prohibitions and its DoD requires "no caching." Any memoization M7 needs belongs to M7's own layer.
5. **The plugin is already loaded on frontend requests.** `wc-inventory-overview.php:87` registers `plugins_loaded` priority 11 gated **only** on `class_exists('WooCommerce')` — no `is_admin()` gate — and requires the resolver and service at lines 118–119. No loader change is needed to call the Position Service from a storefront filter.
6. **WooCommerce 10.9.4 integration points, verified in source.** `abstract-wc-product.php:2353` `get_availability()` returns `apply_filters( 'woocommerce_get_availability', array( 'availability' => text, 'class' => class ), $this )`. `:2389` `get_availability_class()` returns `'out-of-stock'` iff `! is_in_stock()`, `'available-on-backorder'` when backordering, else `'in-stock'` — and ends with its own `woocommerce_get_availability_class` filter, so the class is a *derived string*, not a fact. `wc-template-functions.php:4311-4313` `wc_get_stock_html()` renders **nothing** when the availability text is empty. `class-wc-product-variable.php:466` `get_available_variation()` sets `availability_html => wc_get_stock_html( $variation )`. `wc-template-functions.php:2227` gates inline variation JSON on `count( get_children() ) <= apply_filters( 'woocommerce_ajax_variation_threshold', 30, $product )`.
7. **`woocommerce_get_variation` runs on `admin-ajax.php`.** Registered on `wp_ajax_`/`wp_ajax_nopriv_` (`class-wc-ajax.php:155-157`) as well as the `wc_ajax_` frontend route (`:159`). `is_admin()` is `true` on the former. A bare `! is_admin()` gate would silently break variation availability for products with more than 30 children.
8. **Variation stock methods leak parent values.** `WC_Product_Variation::get_manage_stock()` returns the **string `'parent'`** (truthy) when the variation does not manage its own stock (`class-wc-product-variation.php:323-331`), and `get_stock_quantity()` then returns the **parent's** quantity (`:339-347`). A naive `managing_stock() ? get_stock_quantity() : 0` attributes parent stock to every variation.
9. **The active theme renders availability on catalog cards.** `themes/blocksy/inc/components/woocommerce/archive/product-card.php:482` calls `wc_get_stock_html( $product )` per card. Stock WooCommerce's `templates/content-product.php` does **not**. So the catalog N+1 risk is real on this deployment but theme-conditional — the plan states this rather than asserting a generic need.
10. **Existing business hooks available for memo invalidation.** Exactly four `do_action` calls exist in the plugin: `wc_io_purchase_order_created` (`po-service.php:165`, `:1000`), `wc_io_purchase_order_placed` (`:626`), `wc_io_purchase_order_cancelled` (`:707`), `wc_io_purchase_order_closed_short` (`:818`). There is no Goods Receipt post/void hook; adding one is out of scope.
11. **Structured data has no consumer and three defects.** `class-wc-structured-data.php:232` assigns `$markup['offers']` only inside `if ( '' !== $product->get_price() )`, while the early-return at `:483` lets a zero-priced product with a review reach the `woocommerce_structured_data_product` filter (`:490`) with **no `offers` key at all**. `@type` is `'Offer'` for simple/grouped/external *and* for variable products whose min and max price are equal (`:240-252`), so `! is_type('variable')` is the wrong discriminator. And Rank Math already emits its own competing Product JSON-LD on this install. **Resolved: out of scope, §Milestone boundaries.**
12. **CI blocking filter is a hardcoded alternation.** `tests/docker/run-phpunit.sh:107` — a new prefix must be added there or the M7 suite never blocks CI.
13. **M7 needs nothing from M6.** Confirmed by re-verification of M6's own honest statement: Inventory Position aggregates open PO lines only and queries no receipt or batch table. `Inventory_Position_Service`/`Resolver` are byte-identical to their M3 form. Migrated Goods Receipts (`source = 'migrated'`) are never read by M7, and no migration-tracking column reaches the public API. See §M6 consistency review.

---

## Milestone boundaries

**Hard prohibitions — must not be implemented in M7:**

- Any mutation whatsoever: no stock, no cost meta, no Purchase Order, no PO line, no Goods Receipt, no `qty_received`, no PO status, no PO event. M7 is read-only end to end.
- Any schema change. `DB_VERSION` stays `10`; no `expected_schema_v11()`; no `forbidden_columns` entry; no `ALTER TABLE`.
- Any direct query of `wc_io_purchase_orders`, `wc_io_purchase_order_lines`, `wc_io_goods_receipts`, `wc_io_receipt_lines`, `wc_io_receipt_costs`, or `wc_io_purchase_batches*` from any M7 class. Inventory Position is the only source (D12).
- Recomputation of anything Inventory Position already computes: outstanding quantity, delay, expected-date inheritance, confidence inheritance. Those remain upstream responsibilities.
- **Structured data** (`woocommerce_structured_data_product`). Cut deliberately — see finding 11. `availabilityStarts` is legal schema.org but undocumented by Google for Product/merchant listings (i.e. ignored), the `offers` key is not guaranteed to exist when the filter fires, `@type` is not reliably discriminated by product type, and Rank Math already emits a competing Product entity on this install. Shipping it would be a D16 violation ("no speculative APIs until a concrete consumer exists") for zero measurable benefit. It is purely additive later, under its own plan, if a consumer ever appears.
- New REST endpoints, Store API endpoints, GraphQL, shortcodes, or template overrides (D16, unchanged discipline). Any future REST/Store API/GraphQL/Blocks/headless consumer **must** delegate to `Expected_Delivery_Service`, never calling `Expected_Delivery_Resolver` directly and never re-deriving the answer from Inventory Position or PO data — guard-enforced, not merely documented (§Architecture — sole-entry-point rule).
- Any named dependency on a sibling plugin: no `class_exists()`/`function_exists()` check for a named consumer, no plugin slug, no site-specific coexistence heuristic, no `remove_filter()` against another plugin's hooks.
- A general stock-label system. M7 owns exactly one case — an item WooCommerce reports as not in stock — and leaves every other availability string untouched.
- Exposure of supplier, PO number, PO count, quantity, cost, the raw delayed flag, or any raw Inventory Position figure, in any accessor, filter argument, markup attribute, or CSS class.
- Persistent cross-request caching (transients, object cache, options). See §Architecture — Caching.
- M8 scope: broad cleanup, general caching optimization, unrelated technical debt, Batch Intake physical deletion, GA conformance audit, general storefront redesign.

**Hard prohibitions — must not be modified:**

- `Inventory_Position_Service`, `Inventory_Position_Resolver` — consumed as-is. M7 adds a caller, not a change. Their "never caches" contract stays intact.
- `PO_Delay`, `PO_Expected`, `PO_Confidence`, `Purchase_Order_Lines::query_open_lines()` — M7 does not fix the `partially_received` delay gap (finding 3). That gap is a PO-domain concern with admin-visible consequences; M7 defends against it in its own Resolver (Invariant M7-1) rather than reaching into a frozen milestone's calculator. Fixing it properly belongs to M8 and is recorded as such.
- The M3 architecture guard `test_only_service_calls_bulk_repository_methods` (`tests/unit/inventory-position/test-inventory-position-architecture.php:46-67`) — M7 goes **through** the Service, so this guard is untouched and must **not** be "revised." Stated explicitly given this repository's history of documented guard revisions.
- `Goods_Receipt_Service`, `PO_Receiving_Sync`, `Restock_Service`, `Batch_Migration_Service` — not referenced by M7 at all.

---

## Data ownership

| Milestone | Owns |
|---|---|
| M1–M6 | Suppliers, Purchase Orders, Inventory Position, Goods Receipts, PO Receiving, Migration — all frozen, none modified. |
| **M7 (this plan)** | **Presentation only.** Owns exactly two things: (1) the *selection rule* that picks one credible expected receipt out of an item's open PO lines, and (2) the *customer-facing wording* of that one fact. Owns **zero** stored data — no table, no column, no post meta, no transient. Its only persistent footprint is one boolean `wp_options` row the merchant sets deliberately. Every fact it presents was already decided by whoever created the PO line; M7 chooses which one to say out loud and how to phrase it. |

---

## Architecture

### The public API contract

`WC_Inventory_Overview_Expected_Delivery_Service` is the **sole public API**. The **interface** `WC_Inventory_Overview_Expected_Delivery_Result_Interface` is the returned contract. `Resolver` and `Renderer` are internal implementation detail and carry an explicit `@internal Not public API.` docblock tag.

```php
const API_VERSION = 1;   // owned by the Service; independent of plugin version

public static function get_for_product( $product ): WC_Inventory_Overview_Expected_Delivery_Result_Interface;
public static function get_for_products_bulk( array $products ): array;   // int product ID => …Result_Interface
```

**The public contract is the interface, not the concrete class.** `final class WC_Inventory_Overview_Expected_Delivery_Result implements WC_Inventory_Overview_Expected_Delivery_Result_Interface`, and every public signature — both Service methods, the `wc_io_expected_delivery_text` filter argument, and all documentation — is typed against the **interface**. Promising a concrete `final` class forever is a much heavier commitment than promising a method set: it freezes the constructor strategy, the inheritance decision, and the file itself. An interface freezes only what consumers actually depend on, and leaves room to introduce a second implementation (a null object, a test double, a future decorator) without an `API_VERSION` bump. Naming follows house convention (`WC_Inventory_Overview_*`, no namespaces), so the file is `interface-wc-inventory-overview-expected-delivery-result.php`, mirroring the existing `trait-wc-inventory-overview-hub-list-table-column-info.php`.

Both methods accept `WC_Product` instances or integer IDs (normalized internally via `wc_get_product()`). `get_for_product()` is defined as exactly `get_for_products_bulk( array( $product ) )` returning the single element — one code path, so single and bulk can never disagree (asserted by test).

**API v1 stability contract, stated as a promise:** within `API_VERSION = 1`, the interface's method set never shrinks, state constants are never removed or given new meanings, and `expected_date()` never changes format. New states or accessors would require `API_VERSION = 2`. This is why the plugin version and the API version are separate integers — v1.25.0 must be able to ship without implying an API change.

**Result objects are immutable and may safely be held by callers for the duration of a request.** A consumer that resolves once and reuses the object across a template, a widget, and a shortcode gets the same answer every time, with no defensive copying and no further queries. Across requests the guarantee lapses by design — see §Caching for why nothing about expected delivery may be persisted.

**Sole-entry-point rule for any future consumer.** Any future REST route, Store API extension, GraphQL resolver, Blocks integration, or headless endpoint **must** delegate to `Expected_Delivery_Service`. `Expected_Delivery_Resolver` must never be called directly from outside the Service, and the Service must never be bypassed by re-deriving the answer from Inventory Position or PO data. This is the same sole-owner discipline D3/INV-2 established for stock and D12 for position calculation, applied to presentation, and it is guard-enforced (§Testing) rather than left as prose — the failure mode it prevents is a second, subtly-different expected-delivery answer appearing on a channel nobody re-audited.

### `Expected_Delivery_Result_Interface` / `Expected_Delivery_Result`

The interface declares exactly five methods, and no others. The implementation is `final`, immutable, with private properties, a private constructor, a static `create()` factory, no setters, and snake_case accessors:

| Accessor | Returns |
|---|---|
| `api_version()` | `int` — **informational only**; see below |
| `available_now()` | `bool` — WooCommerce's own `is_in_stock()` answer, echoed |
| `state()` | `string` — one of the four `STATE_*` constants |
| `expected_date()` | `string|null` — raw `Y-m-d`, never localized; non-null **only** in `STATE_EXPECTED_DATE` |
| `confidence()` | `string|null` — `'exact'` or `'estimated'`; non-null **only** in `STATE_EXPECTED_DATE`. Never `'unknown'` — an unknown-confidence line is by definition not customer-safe, so it can never win. |

**`api_version()` is informational only. Consumers must never branch on it.** It reports the `API_VERSION` of the Service that produced this Result (passed in at `create()` time, so the single source of truth stays on the Service and a Result always self-describes the contract it was built under). Its purpose is diagnostics, logging, and bug reports — not runtime compatibility switching. **The Service owns compatibility**: if the contract ever changes incompatibly, the Service is what changes, and consumers migrate deliberately rather than discovering it through a runtime `if`. Consumer code containing `if ( 2 === $result->api_version() )` is a symptom that the contract was broken without a migration path, not a supported pattern. This is stated in the plan, the interface docblock, and `docs/api-expected-delivery.md`.

Deliberately absent, and guarded against: `on_hand()`, `incoming()`, `position()`, anything supplier-, PO-, quantity-, cost- or delay-related. If raw Inventory Position data is ever needed publicly, that belongs to a separate Position-owned API — not to this Result. Widening this Result is how a narrow presentation contract turns into an accidental data-export surface, and the guard in §Testing exists to make that impossible to do by accident.

**States** (constants declared on the **interface**, so consumers reference the contract rather than the implementation; business-facing names, not implementation-branch names):

| Constant | Meaning | Renderer behavior |
|---|---|---|
| `STATE_IN_STOCK` | WooCommerce reports the item available | Leave WooCommerce/theme output completely unchanged |
| `STATE_UNAVAILABLE` | Not available, and no open incoming supply at all | Leave WooCommerce/theme output completely unchanged |
| `STATE_EXPECTED_DATE` | Not available; a customer-safe earliest expected receipt exists | Replace text, worded by confidence |
| `STATE_EXPECTED_SOON` | Not available; incoming supply exists, but no date is safe enough to publish | Replace text with "Expected soon" |

Four states, no more. `available_now()` is redundant with `STATE_IN_STOCK` by construction — kept anyway because it is the one question a consumer asks most often, and answering it without a string comparison against a constant is worth one method.

**`STATE_EXPECTED_SOON` deserves its own explanation, because it is the only state that deliberately withholds information.** It is emphatically **not** "a date we are less sure about" — that case is already covered by `STATE_EXPECTED_DATE` with `confidence() === 'estimated'`, which *does* publish a date, worded by week. `STATE_EXPECTED_SOON` means something categorically different:

> **Incoming inventory exists, but no date is safe enough to expose.**

The stock is genuinely coming. The plugin knows it, and says so. What it refuses to do is attach a number to it, because every candidate date failed the customer-safe test — every line is delayed, or carries `'unknown'` confidence, or has no date at all, or has a date already in the past (Invariant M7-1). Publishing any of them would be a statement the merchant cannot stand behind.

The distinction matters in three concrete ways. It is why the state exists at all rather than collapsing into `STATE_UNAVAILABLE` (which would hide real incoming stock from a customer willing to wait). It is why `expected_date()` and `confidence()` are both `null` here rather than carrying a "best guess" a consumer might render anyway. And it is why a variable parent may only ever reach *this* state (Invariant M7-2) — "expected soon" is the strongest claim that remains true no matter which variation the customer eventually chooses.

### A note on terminology: "credible" vs. `customer_safe`

`CLAUDE.md:26` — frozen architecture text this plan quotes verbatim — uses the phrase "the earliest **credible** expected receipt." That business phrasing is retained wherever the frozen document is quoted.

The **code-level predicate is named `customer_safe`**, not `credible`. "Credible" reads as a statistical or probabilistic judgment about whether a date is *likely* — which is not what is being computed. The test is a governance one: *is this date safe to state publicly, in the shop's own voice, to a customer who may hold us to it?* A line fails not because it is improbable but because it is delayed, undated, unknown-confidence, or already in the past. `customer_safe( $line )` names that, and keeps a reader from looking for a confidence score that does not exist. Both terms refer to the same predicate; the plan uses the code name from here on.

### The expected-delivery algorithm

`Expected_Delivery_Resolver::resolve( bool $available_now, array $incoming_lines, string $today ): array` — pure, deterministic, total.

```
if available_now:
    return [ state = STATE_IN_STOCK, expected_date = null, confidence = null ]

customer_safe(line) :=
        (float) line.outstanding > 0
    AND empty( line.is_delayed )
    AND line.expected_confidence !== 'unknown'
    AND line.expected_date is a valid 'Y-m-d' string
    AND line.expected_date >= today          // Invariant M7-1

if no customer_safe lines:
    if any line has (float) outstanding > 0:  return [ STATE_EXPECTED_SOON, null, null ]
    return [ STATE_UNAVAILABLE, null, null ]

sort customer_safe by ( expected_date ASC, confidence_rank ASC, (int) line_id ASC )
    where confidence_rank: 'exact' => 0, 'estimated' => 1, anything else => 2
return [ STATE_EXPECTED_DATE, winner.expected_date, winner.expected_confidence ]
```

Notes that matter:

- **`$today` is a parameter, never a lookup.** This is exactly the pattern `PO_Delay` already established ("Pure calculator accepts grace days as an argument; WordPress option lookup belongs to callers"). The Service supplies `current_time( 'Y-m-d' )`. It keeps the Resolver clock-free and unit-testable at any simulated date, and it makes the storefront decision site-timezone-consistent — worth noting because the upstream SQL flag uses MySQL `CURDATE()` while `PO_Delay::today()` uses `current_time()`, a discrepancy that is cosmetic in admin and visible on a storefront.
- **`Y-m-d` compares correctly as a plain string**, so no date library is needed and none is permitted. Date *validity* is checked with `preg_match( '/^\d{4}-\d{2}-\d{2}$/' )` + `checkdate()` — both pure PHP, and precisely the technique `PO_Validation::validate_date()` already uses (`po-validation.php:164-170`). The Resolver must **not** call `PO_Validation::validate_date()` itself: it returns `WP_Error`, a WordPress class.
- **`outstanding > 0` is input validation, not recomputation.** The repository's `HAVING outstanding > 0` already guarantees it; the Resolver re-checks because it accepts an arbitrary array and must be total for any input.
- **The sort is fully deterministic**, including the `line_id` tiebreak, so two lines with identical date and confidence always produce the same winner and tests are reproducible.
- **Delay is consumed, never computed.** The Resolver reads `is_delayed`; it never evaluates the delay rule.

### Formal invariants

**Invariant M7-1 — a customer-safe line's date is never in the past.** A line whose `expected_date` is earlier than today is never customer-safe, regardless of `is_delayed`.

This is not defensive padding; it closes a concrete customer-facing defect. `is_delayed` is provably `'0'` for a past-dated line in two ordinary situations (finding 3): the PO has been partially received (the delay predicate only matches `po.status = 'placed'`), or `wc_io_po_delay_grace_days` is non-zero. Concretely: a PO placed 2026-02-01 expecting 2026-03-12 receives one partial receipt, auto-transitions to `partially_received`, and its remaining outstanding is thereafter never flagged delayed — so without this invariant the storefront would render "Expected back around 12 March 2026" on 7 August 2026, **indefinitely**. M3 tolerated this gap because it only dulled an admin badge. A storefront cannot. M7 defends in its own Resolver rather than editing a frozen milestone's calculator; the upstream fix is recorded as M8 work.

**Invariant M7-2 — a variable parent never carries a date.** A variable parent may present `STATE_EXPECTED_SOON`, never `STATE_EXPECTED_DATE`, and its `expected_date()` is always `null`.

INV-8 is explicit that incoming inventory must never exist *against* a variable parent, and structurally that already holds for free: PO lines for a variation store `product_id = parent_id, variation_id = <variation>`, while `list_open_lines_for_product_ids()` filters `pol.variation_id = 0 AND pol.product_id IN (...)`, so routing a parent through the product map yields zero lines with no extra query and no special case. The question M7 must answer is whether a parent may *present* an aggregation of its children, and INV-7 answers it: "Aggregation and same-date grouping are presentation/resolution behaviors" — the M3 list table already rolls child variations up into a parent row exactly this way (`list-table.php:452-478`).

The rule: when a variable parent is not in stock and at least one child variation resolves to `STATE_EXPECTED_DATE` or `STATE_EXPECTED_SOON`, the parent presents `STATE_EXPECTED_SOON`. Never a date, never a per-variation claim. "Expected soon" asserts nothing falsifiable about which variation arrives when, so nothing is fabricated — which is precisely what distinguishes it from inheriting the earliest child date, the fabrication INV-8 exists to prevent. It is also exactly the claim `STATE_EXPECTED_SOON` was defined to carry: incoming stock exists, no date is safe to publish. Without this rule the active theme would show a bare "Out of stock" on a catalog card whose every variation has a confirmed inbound date (finding 9), while the product page itself says "Expected back around 1 September".

Concretely, for a parent whose three variations resolve to 1 Sept, 6 Sept and 12 Sept:

```
Parent  → "Expected soon"          (no date — which variation would it even name?)
  ├─ Variation A → "Expected back around 1 September"
  ├─ Variation B → "Expected back around 6 September"
  └─ Variation C → "Expected back around 12 September"
```

**Invariant M7-3 — one Inventory Position round-trip per storefront page.** A rendered catalog or product page performs at most **two** `wc_io_purchase_order_lines` SELECTs (one product-scoped, one variation-scoped) for expected-delivery purposes, regardless of how many products it renders. Preload warms; memoization holds; a miss degrades to one bounded lookup, never to N lookups for one item.

### Renderer integration

One filter, `woocommerce_get_availability`, priority 10, 2 args. That single hook covers every path that matters, verified in source: simple products on the product page, every variation via `wc_get_stock_html( $variation )` inside `get_available_variation()` (both the inline-JSON path and the `woocommerce_get_variation` AJAX path), catalog cards in themes that render availability, and WooCommerce Blocks / Store API which reach the same product method.

The callback bails, in this order, cheapest first — an opt-out must never cost a query:

1. `wp_doing_cron()` → bail.
2. `is_admin() && ! wp_doing_ajax()` → bail. **Not** a bare `! is_admin()`: `woocommerce_get_variation` runs on `admin-ajax.php` where `is_admin()` is `true` (finding 7), and a bare gate would silently break variation availability for products with more than 30 children.
3. Setting `wc_io_expected_delivery_renderer_enabled` is not `'yes'` → bail.
4. `$product->is_in_stock()` → bail. **Gate on the fact, not the derived class string.** `get_availability_class()` ends with its own `woocommerce_get_availability_class` filter, so a third party could rewrite the class and silently disable the feature. As a deliberate-override escape hatch, also bail when `'in-stock' === $availability['class']` — if someone forced the class to in-stock, respect it. `! is_in_stock()` also excludes backorder items automatically (a backordered product *is* in stock by WooCommerce's definition), which is exactly how M7 honors WooCommerce's own backorder notification semantics with no special-casing.
5. `'' === trim( $availability['availability'] )` → bail. `wc_get_stock_html()` renders nothing when the text is empty, so a site using the common one-line "hide out of stock text" snippet has deliberately suppressed that line — M7 must not resurrect it.
6. `false === apply_filters( 'wc_io_storefront_render_expected_delivery', true, $product )` → bail. Positioned here so a consumer opting out pays zero queries.
7. Look up the Result. States `STATE_IN_STOCK` / `STATE_UNAVAILABLE` → return `$availability` untouched.
8. Build text, pass through `apply_filters( 'wc_io_expected_delivery_text', $text, $result, $product )`, assign to `$availability['availability']`. **`$availability['class']` is never modified** — the theme's styling hook stays exactly as WooCommerce set it.

The callback returns; it never echoes (`phpunit.xml.dist` sets `beStrictAboutOutputDuringTests` with `failOnRisky`).

**Copy**, matching `CLAUDE.md:26` verbatim:

| Condition | String |
|---|---|
| `STATE_EXPECTED_DATE`, `confidence() === 'exact'` | `Expected back around %s` — `%s` = `date_i18n( get_option( 'date_format' ), $ts )` |
| `STATE_EXPECTED_DATE`, `confidence() === 'estimated'` | `Expected during week %s` — `%s` = ISO-8601 week, `date_i18n( 'W', $ts )` |
| `STATE_EXPECTED_SOON` | `Expected soon` |

All three are `esc_html__( …, 'wc-inventory-overview' )` with `sprintf`. The ISO week deliberately carries no year: on 2026-12-30 it correctly reads week 01. That is ISO-8601 behavior, not a defect, and it is covered by a year-boundary test rather than "fixed."

**Preload.** Two warm hooks, both pure optimization — correctness never depends on either firing:

- `woocommerce_before_single_product` — warms the product, plus its children **only when** `count( get_children() ) <= apply_filters( 'woocommerce_ajax_variation_threshold', 30, $product )`. Above that threshold WooCommerce sets `available_variations => false` and renders no inline variation data, so warming hundreds of child IDs would be pure waste; those pages resolve per-variation over AJAX in separate requests where no preload applies.
- `woocommerce_before_shop_loop` — warms from `$GLOBALS['wp_query']->posts`. Correct for classic shop/category/tag/search archives (`templates/archive-product.php:49` fires it after the main query is populated) and used by the active theme.

Explicitly accepted as fallback paths, documented rather than papered over: related/up-sell/cross-sell loops and the non-paginated `[products]` shortcode do not fire `woocommerce_before_shop_loop`, and block-theme archives (Product Collection) never fire it either. Those degrade to one memoized, indexed lookup per rendered item — bounded and small (related products default to 4), never an N+1 *within* an item. `woocommerce_before_template_part` is named here as the additive extension point if measurement ever shows it matters; it is not implemented in M7 because a hot hook firing dozens of times per page is not worth adding for three small loops on speculation (D16). Also worth recording: when `woocommerce_hide_out_of_stock_items` is `'yes'`, out-of-stock items are absent from the catalog entirely and the archive warm is moot.

### Caching

**Request-scoped memoization only. Persistent cross-request caching is prohibited for API v1.**

The write-side invalidation surface is already large — Goods Receipt post and void, PO Receiving `qty_received` changes, PO status transitions, expected-date edits, confidence edits, line quantity edits, cancel, close-short, over-receipt, and the `wc_io_po_delay_grace_days` option — and this plugin exposes `do_action` hooks for only four of those events (finding 10).

But the decisive argument is not the write surface, it is that **the answer changes with no write at all**. Both `is_delayed` (INV-5) and Invariant M7-1 compare a stored date against *today*. A line that is customer-safe at 23:59 is not customer-safe at 00:01, and **no code runs at that moment**. A persistent cache would therefore go stale with literally zero invalidation trigger — not a hard invalidation problem, an impossible one, short of a TTL short enough to defeat the purpose of caching. Rebuilding the answer costs one indexed SELECT against a small table.

Memoization is a `static array` on the Service keyed by item ID. Product and variation IDs share the WordPress post-ID space, which the Position Service already documents and relies on, so a flat map is unambiguous. As defense against a same-request write (WP-CLI, bulk edit, a write followed by a read in one request), the memo is flushed on the four existing `wc_io_purchase_order_*` hooks. This is memo invalidation, not caching, and it is characterized honestly as belt-and-braces: storefront requests do not post receipts, and the memo dies at end of request regardless.

### Extension points

Exactly **two** filters ship in M7. Three candidates from earlier design direction are dropped:

| Hook | Status | Reasoning |
|---|---|---|
| `wc_io_storefront_render_expected_delivery` ( `bool $render`, `WC_Product $product` ) | **Ship** | The generic per-render opt-out. A consumer plugin returns `false`, calls `Expected_Delivery_Service` itself, and renders its own styling and copy. This plugin never needs to know who is doing it. |
| `wc_io_expected_delivery_text` ( `string $text`, `Result $result`, `WC_Product $product` ) | **Ship** | Copy customization without opting out of the whole feature — the reason no wording-template setting is needed. |
| `wc_io_availability_html` | **Drop** | Redundant. A consumer wanting different markup opts out via filter 1 and renders its own; that is the designed path. Two ways to do one thing is how extension surfaces rot. |
| `wc_io_expected_delivery_rendered` | **Drop** | A notification hook with no consumer. Textbook D16 ("no speculative APIs until a concrete consumer exists"). |
| `wc_io_structured_data_*` | **Drop** | Structured data is out of scope entirely (finding 11). |

Together with `Expected_Delivery_Service`'s two public methods, this is the complete integration surface — consistent with `CLAUDE.md:48` ("the integration surface remains mostly public static service methods") and D16 ("Stable internal service boundaries + WordPress actions/filters are the extension surface").

### Admin

**Exactly one new setting, on the existing Settings tab. No new admin page.**

`wc_io_expected_delivery_renderer_enabled`, default `'yes'`, label "Enable Expected Delivery display", in a new **Storefront** section on `admin.php?page=wc-inventory-profit&tab=settings`. It follows the house pattern exactly: `OPTION_*` constant on `WC_Inventory_Overview_Settings`, static getter `self::is_yes( get_option( CONST, 'yes' ) )`, persisted in `save_from_post()` via `update_option( CONST, self::normalize_yes_no( $post, 'wc_io_expected_delivery_renderer_enabled' ), false )`, rendered as a radio Yes/No pair (never a checkbox — all four existing booleans use radios) in `render_settings_panel()`.

Why nothing more, stated because "just one more setting" is how admin screens die:

- **No wording-template editor.** `wc_io_expected_delivery_text` covers customization for developers, and translation covers it for everyone else. A stored template string would be a permanently supported input format with escaping obligations.
- **No thresholds** (e.g. "only show dates within N days"). That would be a *second* delay concept competing with INV-5's `wc_io_po_delay_grace_days`, with no rule for which wins.
- **No colors or CSS options.** `$availability['class']` is left untouched precisely so the theme keeps styling it.
- **No per-product override.** That is product-level expected-date data, which D9 forbids ("never as a single product-level field").
- **No new admin page.** One boolean does not justify a menu entry, and M3–M6 all extended existing screens.

---

## Domain model

**No schema change.** `DB_VERSION` remains `10`. No table, no column, no index, no `expected_schema_v11()`, no `forbidden_columns` entry, no `ALTER TABLE`, no activation/upgrade routine change. M7 is the second milestone after M3 to touch no schema at all, and for the same reason: it derives, it does not store.

**No new stored data of any kind** — no post meta, no term meta, no transient, no custom table. The single persistent artifact is one `wp_options` row (`autoload = false`) holding the merchant's toggle.

Five new files in `includes/`, added **in this order** to the main runtime `require_once` block in `wc-inventory-overview.php` (after the Inventory Position requires at lines 118–119, since the Service depends on them) and **not** to the activation block — none is needed at install or activation time. Ordering matters: there is no autoloader, and the interface must be declared before the class that implements it.

```
interface-wc-inventory-overview-expected-delivery-result.php   ← public contract
class-wc-inventory-overview-expected-delivery-result.php
class-wc-inventory-overview-expected-delivery-resolver.php
class-wc-inventory-overview-expected-delivery-service.php
class-wc-inventory-overview-expected-delivery-renderer.php
```

The Renderer registers its hooks from `WC_Inventory_Overview_Plugin::init()`, alongside the existing unconditional registrations — no `is_admin()` gate at registration time, because the context decision belongs in the callback where tests can exercise it.

---

## Implementation work packages

- **WP-M7-1 — `Expected_Delivery_Result_Interface` + `Expected_Delivery_Result`.** The public contract (interface: four `STATE_*` constants, five method declarations, `@internal`-free docblocks written for external consumers) and its single `final` implementation (private constructor, static `create()`, immutable). No dependencies; independently reviewable, and the piece most worth reviewing carefully since it is the only thing M7 promises to keep stable.
- **WP-M7-2 — `Expected_Delivery_Resolver`.** The pure algorithm, including Invariant M7-1's `$today` comparison and the deterministic sort. Depends on WP-M7-1 only for the state constants.
- **WP-M7-3 — `Expected_Delivery_Service`.** `API_VERSION`, `get_for_product()`, `get_for_products_bulk()`, item-type routing, the variable-parent rule (Invariant M7-2), request-scoped memoization, memo flush on the four PO hooks. Supplies `on_hand = 0.0` for every item, with a docblock stating why: the Result exposes no quantity, the Resolver reads only `incoming_lines`, and `available_now` comes from `is_in_stock()` — so `on_hand` is genuinely unused, and passing `0.0` sidesteps the `get_manage_stock() === 'parent'` trap (finding 8) rather than inventing a third on-hand formula.
- **WP-M7-4 — Architecture guards.** Written against WP-M7-1..3 before the Renderer exists, so the boundary is enforced before anything is tempted to cross it.
- **WP-M7-5 — `Expected_Delivery_Renderer`.** The `woocommerce_get_availability` callback with its seven-step bail ladder, the copy, the two filters, and both preload hooks.
- **WP-M7-6 — Setting.** `WC_Inventory_Overview_Settings` constant + getter + save, and the Storefront section in `render_settings_panel()`.
- **WP-M7-7 — Bulk preload and performance.** Query-scaling tests and the measured acceptance criteria in §Testing. Split from WP-M7-5 because it is verification of a claim, not new behavior.
- **WP-M7-8 — Tests, documentation, release preparation.**

Sequencing: WP-M7-1 → WP-M7-2 → WP-M7-3 are a strict chain. WP-M7-4 must land before WP-M7-5 (guards first, then the class most likely to violate them). WP-M7-6 is independent of everything except WP-M7-5's read of the setting and may be built in parallel. WP-M7-7 requires WP-M7-5. WP-M7-8 is last.

Note the deliberate absence of the structured-data package that earlier design direction anticipated as WP8 — cut per finding 11, with the reasoning recorded in §Milestone boundaries so nobody has to re-derive it from the diff.

---

## Testing

New directories `tests/unit/expected-delivery/` and `tests/integration/expected-delivery/`, matching the M3–M6 convention. Classes named `Test_WC_IO_Expected_Delivery_*`. **`tests/docker/run-phpunit.sh:107`'s blocking filter alternation gains `Test_WC_IO_Expected_Delivery_`** — without this the entire suite runs but never blocks CI.

**Unit — `Result` / `Result_Interface`:** the concrete class `implements` the interface (`in_array( …_Result_Interface::class, class_implements( …_Result::class ), true )`); the interface declares exactly the five approved methods and the four `STATE_*` constants; immutability (no setter, no public property); `create()` + accessor round-trip; `api_version()` returns what the Service passed; `expected_date()`/`confidence()` are `null` in all three non-date states; `confidence()` is never `'unknown'`; both Service methods are type-declared against the **interface**, not the class.

**Unit — `Resolver`** (the bulk of the coverage; pure, no DB, table-driven):
- in stock → `STATE_IN_STOCK`, regardless of incoming lines
- no lines → `STATE_UNAVAILABLE`
- lines all `is_delayed = '1'` → `STATE_EXPECTED_SOON`
- lines all `expected_confidence = 'unknown'` → `STATE_EXPECTED_SOON`
- lines all `expected_date = null` → `STATE_EXPECTED_SOON`
- one customer-safe exact line → `STATE_EXPECTED_DATE` with that date and `'exact'`
- one customer-safe estimated line → `STATE_EXPECTED_DATE` with `'estimated'`
- multiple customer-safe lines, different dates → earliest wins
- **same-date tie, exact vs estimated → exact wins**
- same-date same-confidence tie → lowest `line_id` wins (determinism)
- mixed customer-safe and unsafe → an unsafe line never wins even when earlier
- **Invariant M7-1: past-dated line with `is_delayed = '0'` → not customer-safe.** Three scenarios: partially-received PO simulation; `grace_days = 7` with a 3-day-past date; and `expected_date === $today` which **must remain customer-safe** (boundary).
- malformed `expected_date` (`'0000-00-00'`, `''`, `'2026-13-45'`, `'not-a-date'`) → not customer-safe, no error
- `outstanding = '0'` / negative → not customer-safe
- unexpected confidence string → ranks last, never crashes
- string-typed inputs throughout (every field arrives as a string from `$wpdb`)

**Unit — architecture guards** (`test-expected-delivery-architecture.php`, source-scanning in the established `file_get_contents` + `strip_comments()` style, no Reflection):
- the set of files in `includes/` referencing `Inventory_Position_Service::` is **exactly** `{ class-wc-inventory-overview-list-table.php, class-wc-inventory-overview-expected-delivery-service.php }`
- `Resolver` source contains none of: `$wpdb`, `wc_get_product`, `WC_Product`, `get_option`, `update_option`, `apply_filters`, `add_filter`, `do_action`, `current_time`, `date_i18n`, `date(`, `gmdate(`, `time()`, `mktime`, `strtotime`, `DateTime`, `DateTimeImmutable`, `WP_Error`, `is_wp_error`, `__(`, `esc_html`
- `Renderer` contains no `$wpdb`, no `WC_Inventory_Overview_Purchase_Order`, no `Inventory_Position_Service` — it must go through the Service
- `Result` is declared `final` and `implements` the interface; has no `set_*`/`with_*` method; `sort( get_class_methods() )` equals the sorted approved set exactly (sorted on both sides — declaration order is not a contract), so the implementation cannot quietly add a public method the interface does not promise
- `sort( get_class_methods( …_Result_Interface::class ) )` equals the same sorted set — the interface is the thing that must not drift
- `Result` and the interface source contain none of the forbidden accessor names: `on_hand`, `incoming`, `position`, `supplier`, `po_number`, `po_id`, `outstanding`, `is_delayed`
- **the only file in `includes/` referencing `Expected_Delivery_Resolver::` is `class-wc-inventory-overview-expected-delivery-service.php`** — the sole-entry-point rule, enforced now so that a future REST/Blocks/GraphQL consumer physically cannot bypass the Service (the established "sole caller" guard idiom, matching M5's `increment_qty_received` guard)
- the Renderer is the **only** file in `includes/` containing `woocommerce_get_availability`, and the only one containing `add_filter( 'woocommerce_` — so a later milestone cannot bolt a second storefront hook elsewhere
- no M7 file contains `remove_filter(`, and none contains a `class_exists(`/`function_exists(` check against a non-WooCommerce third-party symbol (replaces an earlier proposed "no sibling-plugin name in `includes/`" grep, which was codebase hygiene belonging to M8's conformance audit rather than an Expected Delivery concern)
- no M7 file writes: none of `set_stock_quantity`, `update_post_meta`, `->insert(`, `->update(`, `->delete(`, `qty_received`

**Integration — `Service`:** simple product with real PO lines end-to-end; variation; **variable parent yields `STATE_EXPECTED_SOON` and `expected_date() === null`** when out of stock with customer-safe children (Invariant M7-2); variable parent in stock → `STATE_IN_STOCK`; single and bulk return identical Results for the same input; memoization returns the same instance within a request; memo flush on `wc_io_purchase_order_placed`; negative stock; unmanaged stock; deleted/missing product ID handled without fatal.

**Integration — `Renderer`:** in-stock product untouched; backorder product untouched (`'available-on-backorder'`); out-of-stock with no incoming untouched; out-of-stock with customer-safe exact date → "Expected back around …"; estimated → "Expected during week …"; no customer-safe date → "Expected soon"; `$availability['class']` unchanged in every case; empty availability text → untouched; setting disabled → untouched; `wc_io_storefront_render_expected_delivery` returning `false` → untouched **and zero Inventory Position queries issued**; `wc_io_expected_delivery_text` overrides the string; a custom `woocommerce_get_availability_class` value does not break rendering; the filter is **active** under `wp_doing_ajax()` and **inactive** under admin-non-AJAX; ISO-week year boundary (a date in the 2026-12-28..2027-01-03 week).

**Integration — settings:** default is `'yes'` on a fresh install; the radio persists both ways through `save_from_post()`; a disabled toggle disables rendering.

**Performance — Invariant M7-3, measured, not asserted in prose.** Using `$wpdb->num_queries` deltas across a rendered archive of **20 mixed simple + variable products** (at least 5 variable parents with 3 variations each):
- one `get_for_products_bulk()` call issues **≤ 2** `wc_io_purchase_order_lines` SELECTs, independent of item count
- the same page rendered with **40** products issues the **same** number of such SELECTs as with 20 — the acceptance criterion is *equality*, which is what distinguishes bounded from merely-small
- after preload, every `woocommerce_get_availability` call during the loop issues **zero** additional queries
- a cache miss (item absent from the warm set) costs exactly **1** additional SELECT and is then memoized — a second call for the same item costs **0**

**Regression:** Inventory Overview list table, Quick Restock, Cost Adjustment, Goods Receipts (M4), PO Receiving (M5), batch migration CLI (M6), and Supplier admin all behave exactly as in v1.23.0. The M3 Position architecture guard passes **unmodified**.

---

## Quality gates

Executed and individually classified (PASS / FAIL / PASS WITH KNOWN PRE-EXISTING FAILURES / CONFIGURED — NOT EXECUTED / NOT APPLICABLE):

- PHP syntax lint; Composer validation; Docker Compose config
- Unit suite; M1–M7 focused blocking suite (with the new prefix registered); cumulative integration suite — pre-existing M0-era failures individually classified against the byte-for-byte-stable baseline carried through M4/M5/M6, never hidden
- Expected Delivery tests in isolation
- PHPCS; actionlint if workflow files change
- **Schema verification confirming `DB_VERSION` is still `10`** and `wc_io_schema_assertion` reports `ok: true` at `version: "10"` — the negative check that M7 changed no schema
- **Invariant M7-1 guard** (past-dated line never customer-safe) — a release blocker in its own right, distinct from the general suite, because it is the difference between a correct storefront and one that lies
- **Invariant M7-3 query-scaling test** (20 vs 40 products issue equal query counts) — a release blocker in its own right
- Architecture guards, including the unmodified M3 Position guard
- Zero-write verification: the full M7 diff contains no `set_stock_quantity`, no `update_post_meta`, no `->insert(`/`->update(`/`->delete(`, no `qty_received`
- Release ZIP build and inspection; git diff review against `v1.23.0`; working-tree verification

Any new test failure introduced by this milestone is a release blocker.

---

## Documentation

1. `docs/milestones/m7-implementation-plan.md` (this document).
2. **`CLAUDE.md`** — two edits. The milestone status table's M7 row → ✅ Complete, 1.24.0, plan linked (updated only after implementation is complete). And **§2 line 50 amended**: the statement that sibling plugin Biopentra Storefront "renders front-end stock text via a `woocommerce_get_availability` filter — the natural consumer for customer-facing expected dates" is factually stale (the plugin directory is empty; nothing outside WooCommerce filters that hook). Replace it with: this plugin now owns customer-facing expected-delivery rendering behind the `wc_io_expected_delivery_renderer_enabled` toggle, and any external consumer integrates generically via `wc_io_storefront_render_expected_delivery` + `Expected_Delivery_Service`. Also add M7's invariants to §4 if the numbering convention permits, or reference them from the plan.
3. **`docs/adr/0003-storefront-expected-delivery-ownership.md`** (new) — **mandatory, not optional.** A future contributor opening this repository will reasonably ask *"why is storefront rendering living inside an inventory plugin?"*, and without a durable answer someone will eventually "clean it up." The ADR answers it permanently: the intended external consumer was verified vacant, presentation of an inventory-derived fact belongs to the domain that owns the fact, and the two-filter surface exists precisely so an external consumer can take rendering back without a code change here. It also records why the built-in renderer is generic rather than sibling-coupled, why the public contract is an interface rather than a class, why `api_version()` is informational, and why persistent caching is prohibited for API v1. Follows `docs/adr/0001-inbound-domain-ownership.md` §7 ("Future integration uses narrow documented WordPress hooks or versioned read contracts on the publisher side only … each with its own ADR").
4. **`docs/admin-guide-storefront-availability.md`** (new) — merchant-facing: what customers see in each state, the one setting and its effect, what is deliberately never shown (supplier, PO, quantity, delay), why a date can disappear without anyone editing anything (a past date stops being customer-safe; Invariant M7-1), why "Expected soon" is not a vaguer date but a deliberate refusal to publish an unsafe one, and how to customize copy via translation or `wc_io_expected_delivery_text`.
5. **`docs/api-expected-delivery.md`** (new) — the public API v1 contract for consumer-plugin developers: both Service methods, the **interface** and its five accessors, the four states (including why `STATE_EXPECTED_SOON` is a refusal to publish a date rather than a vaguer date), `API_VERSION` semantics and the explicit "informational only — never branch on it; the Service owns compatibility" rule, the within-request immutability/reuse guarantee, the sole-entry-point rule for future REST/Blocks/GraphQL consumers, both filters with signatures, and an explicit list of what is *not* public API (the concrete `Result` class, Resolver, Renderer, internal methods).
6. `docs/checklists/validation-checklist.md` — new `### For M7 (Storefront, v1.24.0)` subsection, first item the schema check in M3's negative phrasing ("**No schema change**: `DB_VERSION` is unchanged at `10`"), last item the standard no-regression sweep naming every prior feature area against v1.23.0.
7. **`docs/release-runbook.md`** — new `### M7: Storefront` section. Note this is not optional: line 81 already promises "storefront-toggle validation for M7" as a milestone-specific runbook addition. Must cover the toggle on/off walkthrough, the four customer-visible states verified on a real product, and confirmation that in-stock and backorder products are untouched.
8. `docs/testing.md` — the two new test directories in the structure tree, the updated focused-suite test/assertion counts, and the new filter prefix.
9. `docs/architecture-audit.md` — new `## Milestone M7 — Storefront Expected Delivery` section inserted before `## Known risks / tech debt`, in the established shape: `**Status:**` line ("Schema unchanged (v10), zero new tables"), `**Scope:**` paragraph relating to D9/D12/D16/INV-7/INV-8, one bolded run-in paragraph per new class, an `**Architecture guards:**` paragraph naming the guard file and each asserted property, and a `**Testing:**` paragraph. Must state the sole-entry-point rule explicitly (only the Service may call the Resolver; any future REST/Blocks/GraphQL consumer delegates to the Service) alongside the existing D3/INV-2 and D12 sole-owner entries, since that is where a future contributor will look for it. The `## Known risks / tech debt` item 6 test enumeration gains "+ M7 expected-delivery". **Line 141's "No public REST routes or storefront-facing hooks" must be corrected** — it becomes false the moment M7 ships. Record the upstream `partially_received` delay gap as an M8 follow-up.
10. `CHANGELOG.md` — v1.24.0 entry leading with the one governed fact and the stability of the public API.
11. `readme.txt` and all repository version references, consistently `1.24.0`.
12. `docs/GITHUB_RELEASE_NOTES_1.24.0.md` — created **before tagging** (the Release workflow requires it; its absence was M6's sole release blocker and must not repeat).
13. `docs/rollback-plan.md` — new M7 section. Short, because the answer is short: see §Rollback.

---

## Deployment

1. Deploy v1.24.0 normally. **No schema step** — `DB_VERSION` stays `10`, no `ALTER` runs, no upgrade routine fires.
2. The renderer is active on deploy (`wc_io_expected_delivery_renderer_enabled` defaults to `'yes'`). This is deliberate: a feature that ships off by default ships untested in production. It is also why the toggle exists and why the runbook walkthrough (§Documentation item 7) is mandatory before tagging.
3. Verify on a real out-of-stock product with a placed PO carrying an exact expected date — the storefront should read "Expected back around …".
4. Toggle the setting off; confirm WooCommerce's stock text returns to "Out of stock" exactly. Toggle back on.
5. Confirm an in-stock product and a backordered product are visually unchanged.
6. Confirm no admin screen changed apart from the new Storefront section on the Settings tab.

---

## Rollback

**M7 has the cleanest rollback story of any milestone in this program — cleaner even than M6's.**

- **Instant, no deploy:** set `wc_io_expected_delivery_renderer_enabled` to `'no'`. Storefront output returns to stock WooCommerce immediately. Nothing else in the plugin changes behavior.
- **Code rollback 1.24.0 → 1.23.0:** unconditionally safe. M7 wrote no data, changed no schema, and mutated nothing. The only persistent artifact is one `wp_options` row that 1.23.0 simply never reads. There is no data-safety reason to remove it, and no schema to reverse.
- Unlike M4/M5 (stock/`qty_received` effects survive a code rollback) and unlike M6 (additive migrated rows survive), **M7 leaves nothing behind at all**.

---

## M6 consistency review

Explicitly verified, because M7 is being planned after M6 and inherited assumptions are how stale plans happen:

- **`Inventory_Position_Service` behavior is unchanged by M6.** The Service and Resolver files are byte-identical to their M3 form. M6 touched neither. (The one real drift is M5's, not M6's: `outstanding` now subtracts `qty_received` and the header status filter widened to `IN ('placed','partially_received','received')` — both correct, and the second is the root of Invariant M7-1's necessity.)
- **Batch Intake retirement has no effect on M7.** Inventory Position aggregates open PO lines only; it never queried `wc_io_purchase_batches*`, before or after M6.
- **Migrated Goods Receipts are never read by M7.** M7 reads no receipt table at all. `source = 'migrated'` is invisible to it.
- **No M6 migration metadata leaks into the public API.** `migrated_receipt_id` and `migrated_at` live on `wc_io_purchase_batches`, which M7 never touches; the Result's five accessors expose nothing from that table or any other.
- **Baselines confirmed:** plugin 1.23.0, `DB_VERSION` 10. M7 targets 1.24.0 and leaves `DB_VERSION` at 10.
- **M7 needs no schema change** — confirmed by field-level analysis: every input already exists on `wc_io_purchase_order_lines`/`wc_io_purchase_orders` and is already exposed through `incoming_lines`.
- **No technical dependency on M6 is invented.** M6's own plan stated plainly that "M7 would function identically today, before M6, if the version numbering allowed it." Re-verified and still true. M7 follows M6 for roadmap sequencing and baseline hygiene, not because it needs anything M6 produced.

---

## Critical review

Issues actively searched for and resolved **inside** this plan rather than deferred to implementation:

| Risk | Resolution |
|---|---|
| **Duplicate business logic** | The Resolver recomputes nothing — outstanding, delay, and date/confidence inheritance all stay upstream. The one genuinely new rule (credibility + earliest-wins) exists in exactly one place. The single-vs-bulk consistency risk is removed structurally: `get_for_product()` *is* `get_for_products_bulk()`. |
| **Stale-date defect** (severe, customer-facing) | Invariant M7-1, with three dedicated tests and a release-blocking quality gate. |
| **Public API overreach** | Five accessors, guarded by exact `get_class_methods()` assertions on *both* the interface and the implementation, plus a forbidden-name scan. Resolver and Renderer marked `@internal`. |
| **Over-committing the public contract** | The promise is an interface, not a `final` class — so a second implementation, a null object, or a decorator can appear without an `API_VERSION` bump. `api_version()` is explicitly informational, so consumers cannot build runtime version branching on it and turn a diagnostic into a compatibility mechanism. |
| **A second expected-delivery answer appearing on a new channel** | Sole-entry-point rule, guard-enforced now rather than when the first REST consumer arrives: only the Service may call the Resolver. |
| **Presentation leaking internal data** | No supplier/PO/quantity/cost/delay reaches any accessor, filter argument, or markup. `$availability['class']` is never modified, so no new CSS hook encodes internal state either. |
| **N+1 risk** | Invariant M7-3 with an equality-based acceptance criterion (20 vs 40 products → equal query counts). Fallback paths named and bounded rather than hand-waved. |
| **Plugin coupling** | The sibling plugin does not exist (verified empty directory). The renderer is generic; a guard forbids `remove_filter` and third-party `class_exists` checks; the ownership shift is recorded in CLAUDE.md and a new ADR. |
| **Excessive settings** | One boolean, with five specific rejected alternatives and reasons. |
| **Unnecessary filters** | Two shipped, three dropped with reasons (D16). |
| **Theme-specific assumptions** | Gate on `! is_in_stock()` (a fact) rather than the filterable class string; bail on empty availability text so "hide out of stock" snippets are respected; leave `class` untouched so theme CSS keeps working. The Blocksy dependency is stated as a deployment observation, never assumed. |
| **Stale pre-M6 assumptions** | §M6 consistency review, field-level. |
| **M8 scope leakage** | Structured data cut; the "no sibling-plugin name in `includes/`" grep guard cut (M8 conformance work) and replaced with a load-bearing coupling guard; the upstream `partially_received` delay gap defended against locally and recorded as an M8 follow-up rather than fixed inside a frozen milestone. |
| **`is_admin()` trap** | `woocommerce_get_variation` runs on `admin-ajax.php`; the gate is `! ( is_admin() && ! wp_doing_ajax() )`, with a test asserting the filter is live under `wp_doing_ajax()`. |
| **Variation parent-stock leak** | `on_hand` is always `0.0` and provably unused, sidestepping `get_manage_stock() === 'parent'` rather than inventing a third on-hand formula. |
| **Resurrecting suppressed output** | Bail on empty availability text, with a test. |

---

## Definition of Done

- [ ] `DB_VERSION` is still `10`; `wc_io_schema_assertion` reports `ok: true` at `version: "10"`; the diff contains no `ALTER TABLE`, no new table, and no `expected_schema_v11()`.
- [ ] `Expected_Delivery_Service` exposes exactly `get_for_product()`, `get_for_products_bulk()`, and `API_VERSION = 1`; `API_VERSION` is independent of the plugin version and documented as stable for v1.
- [ ] **The public contract is `WC_Inventory_Overview_Expected_Delivery_Result_Interface`, not the concrete class** — both Service methods are type-declared against the interface, the interface declares exactly the five accessors and the four `STATE_*` constants, and `final class …_Result implements` it. Guard-enforced on both sides (sorted `get_class_methods()` equality for interface *and* class, plus a forbidden-name scan).
- [ ] `Expected_Delivery_Result` is `final` and immutable (private constructor, private properties, no setters); Result objects are documented as safely reusable by callers for the duration of a request.
- [ ] **`api_version()` is documented — in the interface docblock, `docs/api-expected-delivery.md`, and ADR-0003 — as informational only**, with the explicit rule that consumers must never branch on it and that the Service owns compatibility.
- [ ] **`STATE_EXPECTED_SOON` is documented as a refusal to publish an unsafe date, not as a lower-confidence date** — in the plan, the API reference, and the merchant guide — and its `expected_date()`/`confidence()` are both `null` by test.
- [ ] **Sole-entry-point rule enforced:** the only file in `includes/` referencing `Expected_Delivery_Resolver::` is the Service; the rule that any future REST/Store API/GraphQL/Blocks/headless consumer must delegate to `Expected_Delivery_Service` is recorded in the plan, the API reference, and the architecture audit.
- [ ] `Expected_Delivery_Resolver` is pure: guard-enforced absence of `$wpdb`, `WC_Product`, `wc_get_product`, WordPress functions, `WP_Error`, all date/clock functions and classes, and all I/O. `$today` is a parameter.
- [ ] **Invariant M7-1** holds: a line whose `expected_date` is before `$today` is never customer-safe regardless of `is_delayed` — verified for the partially-received case, the non-zero-`grace_days` case, and the `expected_date === $today` boundary (which must remain customer-safe).
- [ ] **Invariant M7-2** holds: a variable parent never returns `STATE_EXPECTED_DATE` and its `expected_date()` is always `null`; it returns `STATE_EXPECTED_SOON` when out of stock with ≥1 customer-safe child.
- [ ] **Invariant M7-3** holds, measured: an archive of 20 mixed products issues ≤ 2 `wc_io_purchase_order_lines` SELECTs for expected-delivery purposes, and 40 products issues the **same** count as 20; a preloaded item costs 0 further queries; a miss costs exactly 1 and is then memoized.
- [ ] `Expected_Delivery_Service` is the only new caller of `Inventory_Position_Service`; the set of `includes/` files calling it is exactly `{list-table, expected-delivery-service}`; the M3 Position architecture guard passes **unmodified**.
- [ ] The renderer acts only when the product is not in stock, the availability text is non-empty, the setting is enabled, and the opt-out filter returns true — and never modifies `$availability['class']`. In-stock and backordered products are byte-identical to stock WooCommerce.
- [ ] The renderer is live under `wp_doing_ajax()` (so `woocommerce_get_variation` works for >30-child products) and inert on admin-non-AJAX and cron requests.
- [ ] Customer-facing copy matches `CLAUDE.md:26` verbatim: "Expected back around {date}" / "Expected during week {ISO week}" / "Expected soon"; all strings translatable under `wc-inventory-overview`; the ISO-week year boundary is covered by test.
- [ ] Exactly one new setting (`wc_io_expected_delivery_renderer_enabled`, default `'yes'`), on the existing Settings tab, following the house radio Yes/No pattern; no new admin page.
- [ ] Exactly two filters ship (`wc_io_storefront_render_expected_delivery`, `wc_io_expected_delivery_text`); `wc_io_availability_html`, `wc_io_expected_delivery_rendered`, and all structured-data hooks are absent from the codebase.
- [ ] No structured-data integration ships; the decision and its reasoning are recorded in the plan and the architecture audit.
- [ ] No named sibling-plugin coupling anywhere: no `class_exists`/`function_exists` check against a third-party symbol, no `remove_filter()`, no plugin slug — guard-enforced. The plugin is fully functional and production-ready with no external consumer installed.
- [ ] Zero mutation: the diff contains no `set_stock_quantity`, no `update_post_meta`, no `->insert(`/`->update(`/`->delete(`, no `qty_received`, no PO/receipt/batch write of any kind — guard-enforced.
- [ ] `Test_WC_IO_Expected_Delivery_` is registered in `tests/docker/run-phpunit.sh`'s blocking filter and the suite is verified to actually block CI.
- [ ] All unit, integration, architecture-guard, and performance tests exist and pass; the M0 golden suite and existing characterization fixtures are unchanged; the pre-existing integration-suite failure baseline is byte-for-byte identical to v1.23.0's.
- [ ] Quick Restock, Cost Adjustment, Inventory Overview list table, Goods Receipts (M4), PO Receiving (M5), batch migration CLI (M6), and Supplier admin verified unaffected.
- [ ] All 13 documentation deliverables complete, including `CLAUDE.md` §2's corrected storefront-ownership statement, ADR 0003, the merchant guide, the public API reference, the `docs/release-runbook.md` M7 section promised at line 81, and the correction of `docs/architecture-audit.md:141`.
- [ ] `docs/GITHUB_RELEASE_NOTES_1.24.0.md` exists **before** tagging.
- [ ] Version prepared as `1.24.0` consistently across plugin header, `WC_INVENTORY_OVERVIEW_VERSION`, `readme.txt`, and `CHANGELOG.md`; not tagged, not released, as part of plan authorship.
- [ ] All quality gates executed and individually classified; every gate PASS or PASS WITH KNOWN PRE-EXISTING FAILURES; no new failure introduced.
- [ ] Implementation branch left committed, clean, unpushed/unmerged, ready for independent audit.

---

READY FOR REVIEW
