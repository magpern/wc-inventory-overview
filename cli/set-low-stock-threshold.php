<?php
/**
 * WP-CLI eval-file: set per-variation low stock threshold meta to a fixed value.
 *
 * Run from WooCommerce Docker project (bind-mounted wp-content):
 *
 *   cd /path/to/woocommerce
 *   docker compose run --rm --no-deps --entrypoint="" \
 *     -v /path/to/wc-inventory-overview-plugin/cli/set-low-stock-threshold.php:/var/www/html/wp-content/set-low-stock-threshold.php:ro \
 *     wpcli wp eval-file wp-content/set-low-stock-threshold.php
 *
 * Or copy this file into wp-content/ then:
 *   ./wp eval-file wp-content/set-low-stock-threshold.php
 *
 * Updates only product_variation posts via WC_Product APIs.
 * Does not change stock quantity or stock status.
 *
 * @package WC_CLI_Scripts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce is not active.' );
}

$target = 3;

$scanned = 0;
$updated = 0;
$skipped = 0;
$failed  = 0;

$parents_for_transients    = array();
$variations_for_transients = array();

$paged    = 1;
$per_page = 200;

WP_CLI::log( sprintf( 'Setting variation _low_stock_amount to %d (skip trash; simple/parent products untouched).', $target ) );

do {
	$ids = get_posts(
		array(
			'post_type'              => 'product_variation',
			'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page'         => $per_page,
			'paged'                  => $paged,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	if ( empty( $ids ) ) {
		break;
	}

	foreach ( $ids as $variation_id ) {
		++$scanned;

		try {
			$product = wc_get_product( $variation_id );

			if ( ! $product instanceof WC_Product || ! $product->is_type( 'variation' ) ) {
				++$skipped;
				WP_CLI::log( sprintf( 'SKIP variation %d — not a valid variation object.', (int) $variation_id ) );
				continue;
			}

			$prev = $product->get_low_stock_amount( 'edit' );

			// Skip only when variation already stores this exact threshold (non-empty meta equals target).
			if ( '' !== $prev && absint( $prev ) === $target ) {
				++$skipped;
				WP_CLI::log(
					sprintf(
						'SKIP variation %d — already _low_stock_amount=%d.',
						(int) $variation_id,
						$target
					)
				);
				continue;
			}

			$parent_id = $product->get_parent_id();
			$parent    = $parent_id ? wc_get_product( $parent_id ) : null;
			$parent_label = $parent
				? sprintf( '%d (%s)', (int) $parent_id, $parent->get_name() )
				: sprintf( '%d (missing parent)', (int) $parent_id );

			$shop_default = get_option( 'woocommerce_notify_low_stock_amount', 2 );
			$prev_display = ( '' === $prev || null === $prev )
				? sprintf( "'' (inherit shop default: %s)", $shop_default )
				: (string) $prev;

			$product->set_low_stock_amount( $target );
			$product->save();

			$parents_for_transients[ (int) $parent_id ]       = true;
			$variations_for_transients[ (int) $variation_id ] = true;

			++$updated;

			WP_CLI::log(
				sprintf(
					'OK variation %d | parent %s | previous threshold %s | new threshold %d',
					(int) $variation_id,
					$parent_label,
					$prev_display,
					$target
				)
			);
		} catch ( Throwable $e ) {
			++$failed;
			WP_CLI::warning(
				sprintf(
					'FAIL variation %d — %s',
					(int) $variation_id,
					$e->getMessage()
				)
			);
		}
	}

	++$paged;
} while ( count( $ids ) === $per_page );

foreach ( array_keys( $variations_for_transients ) as $vid ) {
	wc_delete_product_transients( $vid );
}
foreach ( array_keys( $parents_for_transients ) as $pid ) {
	if ( $pid > 0 ) {
		wc_delete_product_transients( $pid );
	}
}

if ( class_exists( 'WC_Cache_Helper' ) ) {
	WC_Cache_Helper::get_transient_version( 'product', true );
}

WP_CLI::success(
	sprintf(
		'Summary — scanned: %d | updated: %d | skipped: %d | failed: %d',
		$scanned,
		$updated,
		$skipped,
		$failed
	)
);
