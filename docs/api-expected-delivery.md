# Expected Delivery — Public API v1 (M7)

The public contract for consumer-plugin developers who want the same
governed expected-delivery answer WC Inventory Overview shows on the
storefront — for a custom renderer, a REST endpoint, a Store API extension,
or any other integration.

**Not public API:** the concrete `WC_Inventory_Overview_Expected_Delivery_Result`
class, `WC_Inventory_Overview_Expected_Delivery_Resolver`, and
`WC_Inventory_Overview_Expected_Delivery_Renderer`. Only what's documented
below is covered by the API v1 stability contract.

## The Service

`WC_Inventory_Overview_Expected_Delivery_Service` is the **sole public API**.
Any future REST route, Store API extension, GraphQL resolver, Blocks
integration, or headless endpoint **must** delegate to this Service —
`Expected_Delivery_Resolver` must never be called directly from outside it,
and the Service must never be bypassed by re-deriving the answer from
Inventory Position or Purchase Order data (the sole-entry-point rule).

```php
const API_VERSION = 1;

/**
 * @param WC_Product|int $product Product/variation instance or ID.
 * @return WC_Inventory_Overview_Expected_Delivery_Result_Interface
 */
public static function get_for_product( $product ): WC_Inventory_Overview_Expected_Delivery_Result_Interface;

/**
 * @param array<int,WC_Product|int> $products Product/variation instances or IDs.
 * @return array<int,WC_Inventory_Overview_Expected_Delivery_Result_Interface> Keyed by item ID.
 */
public static function get_for_products_bulk( array $products ): array;
```

Both methods accept `WC_Product` instances or integer IDs. `get_for_product()`
is exactly `get_for_products_bulk( array( $product ) )` returning the single
element — single and bulk can never disagree.

**Reuse the Result within a request.** Results are immutable and safely held
by a caller for the duration of a request — resolve once, reuse across a
template, a widget, and a shortcode with no defensive copying and no further
queries. This guarantee is per-request only: never cache a Result across
requests (see §Caching below).

**Bulk-load on catalog pages.** `get_for_products_bulk()` issues at most one
product-scoped and one variation-scoped SQL query, regardless of how many
items are passed — call it once per page with every product you're about to
render, not once per product.

## The Result contract

The **interface**, `WC_Inventory_Overview_Expected_Delivery_Result_Interface`,
is the returned contract — not the concrete class. Both Service methods, the
`wc_io_expected_delivery_text` filter argument, and your own type hints
should reference the interface.

```php
interface WC_Inventory_Overview_Expected_Delivery_Result_Interface {
    const STATE_IN_STOCK      = 'in_stock';
    const STATE_UNAVAILABLE   = 'unavailable';
    const STATE_EXPECTED_DATE = 'expected_date';
    const STATE_EXPECTED_SOON = 'expected_soon';

    public function api_version(): int;
    public function available_now(): bool;
    public function state(): string;
    public function expected_date(): ?string;
    public function confidence(): ?string;
}
```

| Accessor | Returns |
|---|---|
| `api_version()` | `int` — see §Versioning below. **Informational only.** |
| `available_now()` | `bool` — WooCommerce's own `is_in_stock()` answer, echoed. |
| `state()` | `string` — one of the four `STATE_*` constants. |
| `expected_date()` | `string\|null` — raw `Y-m-d`, never localized. Non-null **only** in `STATE_EXPECTED_DATE`. |
| `confidence()` | `string\|null` — `'exact'` or `'estimated'`. Non-null **only** in `STATE_EXPECTED_DATE`. Never `'unknown'`. |

### States

| Constant | Meaning | Typical rendering |
|---|---|---|
| `STATE_IN_STOCK` | WooCommerce reports the item available. | Show normal in-stock UI; don't call this API's output at all. |
| `STATE_UNAVAILABLE` | Not available, and no open incoming supply at all. | Plain "Out of stock" — nothing to say. |
| `STATE_EXPECTED_DATE` | Not available; a customer-safe earliest expected receipt exists. | "Expected back around {date}" (exact) or "Expected during week {W}" (estimated), per `confidence()`. |
| `STATE_EXPECTED_SOON` | Not available; incoming supply exists, but no date is safe enough to publish. | "Expected soon" — **not** a vaguer date. `expected_date()` and `confidence()` are both `null`; do not invent a fallback date. |

Nothing else is ever exposed: no supplier, no PO number, no quantity, no
cost, no raw delayed flag, no raw Inventory Position figures. If you need
that data, it belongs to a separate, Position-owned API — not this Result.

### Versioning

`api_version()` reports the `API_VERSION` of the Service that produced the
Result. **It is informational only — never branch on it.** The Service owns
compatibility: if the contract changes incompatibly, `API_VERSION` is bumped
and the Service is what changes; consumers migrate deliberately rather than
discovering a change through a runtime `if ( 2 === $result->api_version() )`.

Within `API_VERSION = 1`: the interface's method set never shrinks, the
`STATE_*` constants are never removed or given new meanings, and
`expected_date()` never changes format.

## Extension points

Exactly two filters ship with the built-in renderer. Together with the
Service's two public methods, this is the complete integration surface.

### `wc_io_storefront_render_expected_delivery`

```php
apply_filters( 'wc_io_storefront_render_expected_delivery', true, $product );
```

Return `false` to suppress the built-in renderer's output for a given
product and render your own. Checked before any Position lookup runs, so an
opt-out never costs a query.

```php
add_filter( 'wc_io_storefront_render_expected_delivery', function ( $render, $product ) {
    // Suppress the built-in renderer for a specific category, then render
    // your own markup elsewhere using Expected_Delivery_Service directly.
    return has_term( 'preorder', 'product_cat', $product->get_id() ) ? false : $render;
}, 10, 2 );
```

### `wc_io_expected_delivery_text`

```php
apply_filters( 'wc_io_expected_delivery_text', $text, $result, $product );
```

Customize the copy without opting out of the whole feature.

```php
add_filter( 'wc_io_expected_delivery_text', function ( $text, $result, $product ) {
    if ( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_SOON === $result->state() ) {
        return __( 'Back in stock soon', 'my-theme' );
    }
    return $text;
}, 10, 3 );
```

## Caching

**Do not persist a Result across requests** (transient, object cache,
custom table, etc.). Both `is_delayed` and Invariant M7-1 (a customer-safe
date is never in the past) compare a stored date against *today* — the
answer can go stale with **zero write-side trigger**, since no code runs at
midnight. Request-scoped memoization (a static variable, a single
page-load's worth of reuse) is fine and is exactly what the Service itself
does internally.

## Example: a custom REST endpoint

```php
register_rest_route( 'my-plugin/v1', '/expected-delivery/(?P<id>\d+)', array(
    'methods'  => 'GET',
    'callback' => function ( $request ) {
        $result = WC_Inventory_Overview_Expected_Delivery_Service::get_for_product( (int) $request['id'] );

        return array(
            'state'         => $result->state(),
            'expected_date' => $result->expected_date(),
            'confidence'    => $result->confidence(),
        );
    },
) );
```

This delegates entirely to the Service — it never touches the Resolver,
Inventory Position, or Purchase Order data directly, per the sole-entry-point
rule.
