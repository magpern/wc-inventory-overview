<?php
/**
 * Built-in storefront renderer for Expected Delivery (M7).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * The only file in this plugin that touches `woocommerce_get_availability`.
 *
 * Always goes through WC_Inventory_Overview_Expected_Delivery_Service —
 * never the Resolver, never Inventory Position directly. Generic: no named
 * dependency on any sibling plugin. Two preload hooks are pure optimization;
 * correctness never depends on either firing.
 */
final class WC_Inventory_Overview_Expected_Delivery_Renderer {

	/**
	 * Registers the storefront filter and both (optional) preload hooks.
	 */
	public static function register() {
		add_filter( 'woocommerce_get_availability', array( __CLASS__, 'filter_availability' ), 10, 2 );
		add_action( 'woocommerce_before_single_product', array( __CLASS__, 'preload_single_product' ) );
		add_action( 'woocommerce_before_shop_loop', array( __CLASS__, 'preload_shop_loop' ) );
	}

	/**
	 * `woocommerce_get_availability` callback. Bails cheapest-first so an
	 * opt-out never costs a query.
	 *
	 * @param array      $availability { 'availability' => string, 'class' => string }.
	 * @param WC_Product $product      The product WooCommerce is rendering availability for.
	 * @return array
	 */
	public static function filter_availability( $availability, $product ) {
		if ( wp_doing_cron() ) {
			return $availability;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return $availability;
		}

		if ( ! WC_Inventory_Overview_Settings::expected_delivery_renderer_enabled() ) {
			return $availability;
		}

		if ( ! $product instanceof WC_Product ) {
			return $availability;
		}

		// Gate on the fact, not the derived (separately filterable) class
		// string -- except as a deliberate-override escape hatch: if a
		// third party already forced the class to in-stock, respect it.
		$class = isset( $availability['class'] ) ? (string) $availability['class'] : '';
		if ( $product->is_in_stock() || 'in-stock' === $class ) {
			return $availability;
		}

		$text = isset( $availability['availability'] ) ? (string) $availability['availability'] : '';
		if ( '' === trim( $text ) ) {
			return $availability;
		}

		if ( false === apply_filters( 'wc_io_storefront_render_expected_delivery', true, $product ) ) {
			return $availability;
		}

		$result = WC_Inventory_Overview_Expected_Delivery_Service::get_for_product( $product );
		$state  = $result->state();

		if ( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_IN_STOCK === $state
			|| WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_UNAVAILABLE === $state ) {
			return $availability;
		}

		$new_text = self::build_text( $result );

		/**
		 * Filters the rendered expected-delivery text.
		 *
		 * @param string                                                    $new_text Rendered text.
		 * @param WC_Inventory_Overview_Expected_Delivery_Result_Interface $result   The resolved Result.
		 * @param WC_Product                                                $product  The product.
		 */
		$availability['availability'] = (string) apply_filters( 'wc_io_expected_delivery_text', $new_text, $result, $product );

		return $availability;
	}

	/**
	 * Copy, matching CLAUDE.md:26 verbatim.
	 *
	 * @param WC_Inventory_Overview_Expected_Delivery_Result_Interface $result STATE_EXPECTED_DATE or STATE_EXPECTED_SOON.
	 * @return string
	 */
	private static function build_text( WC_Inventory_Overview_Expected_Delivery_Result_Interface $result ): string {
		if ( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_SOON === $result->state() ) {
			return esc_html__( 'Expected soon', 'wc-inventory-overview' );
		}

		$timestamp = strtotime( (string) $result->expected_date() );

		if ( 'exact' === $result->confidence() ) {
			return sprintf(
				/* translators: %s: expected delivery date, formatted per site date format setting. */
				esc_html__( 'Expected back around %s', 'wc-inventory-overview' ),
				date_i18n( get_option( 'date_format' ), $timestamp )
			);
		}

		return sprintf(
			/* translators: %s: ISO-8601 week number (01-53), no year. */
			esc_html__( 'Expected during week %s', 'wc-inventory-overview' ),
			date_i18n( 'W', $timestamp )
		);
	}

	/**
	 * Warms the single product being viewed, plus its variations when there
	 * are few enough that WooCommerce itself inlines their data (finding 6).
	 */
	public static function preload_single_product() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$to_preload = array( $product );

		if ( $product->is_type( 'variable' ) ) {
			$children  = $product->get_children();
			$threshold = apply_filters( 'woocommerce_ajax_variation_threshold', 30, $product );

			if ( count( $children ) <= (int) $threshold ) {
				foreach ( $children as $child_id ) {
					$to_preload[] = $child_id;
				}
			}
		}

		WC_Inventory_Overview_Expected_Delivery_Service::get_for_products_bulk( $to_preload );
	}

	/**
	 * Warms every product on the current classic shop/category/tag/search
	 * archive loop (finding: block-theme archives and shortcode/related
	 * loops do not fire this hook and fall back to one memoized lookup per
	 * item, bounded and small).
	 */
	public static function preload_shop_loop() {
		if ( empty( $GLOBALS['wp_query']->posts ) || ! is_array( $GLOBALS['wp_query']->posts ) ) {
			return;
		}

		$ids = array();
		foreach ( $GLOBALS['wp_query']->posts as $post ) {
			$ids[] = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : (int) $post;
		}

		WC_Inventory_Overview_Expected_Delivery_Service::get_for_products_bulk( $ids );
	}
}
