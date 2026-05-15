<?php
/**
 * Admin: actual shipping cost on the order edit screen (order meta only; does not alter totals).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores optional actual shipping cost for reporting (not part of WooCommerce order totals).
 */
class WC_Inventory_Overview_Order_Shipping_Admin {

	public const META_KEY = '_wc_io_actual_shipping_cost';

	public const NONCE_ACTION = 'wc_io_actual_shipping_save';

	public const INPUT_NAME = 'wc_io_actual_shipping_cost_input';

	public const INPUT_ID = 'wc_io_actual_shipping_cost';

	public static function register() {
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'render_field' ), 20, 1 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save' ), 45, 2 );
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public static function render_field( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$order_id = $order->get_id();
		if ( ! $order_id || ! current_user_can( 'edit_shop_orders', $order_id ) ) {
			return;
		}

		$raw        = $order->get_meta( self::META_KEY, true );
		$display_dp = max( 2, (int) wc_get_price_decimals() );
		$display    = ( '' !== $raw && null !== $raw ) ? wc_format_decimal( (string) $raw, $display_dp ) : '';

		echo '<div class="address wc-io-actual-shipping-cost-field">';
		echo '<p><strong>' . esc_html__( 'Actual shipping cost', 'wc-inventory-overview' ) . '</strong></p>';
		echo '<p class="description">' . esc_html__( 'Recorded for your records only. This does not change order totals or shipping lines.', 'wc-inventory-overview' ) . '</p>';
		wp_nonce_field( self::NONCE_ACTION, '_wc_io_actual_shipping_nonce', false, true );
		printf(
			'<p class="form-field form-field-wide"><label for="%1$s">%2$s</label><input type="text" class="short wc_input_price" name="%3$s" id="%1$s" value="%4$s" placeholder="0" autocomplete="off" /></p>',
			esc_attr( self::INPUT_ID ),
			esc_html__( 'Amount', 'wc-inventory-overview' ),
			esc_attr( self::INPUT_NAME ),
			esc_attr( $display )
		);
		echo '</div>';
	}

	/**
	 * Persist meta when the order is saved from the admin order screen (CPT and HPOS).
	 *
	 * @param int                $order_id Order ID.
	 * @param WC_Order|WP_Post|null $post_or_order Post (legacy) or order object (HPOS).
	 */
	public static function save( $order_id, $post_or_order = null ) {
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_verify_nonce expects raw nonce.
		if ( empty( $_POST['_wc_io_actual_shipping_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_wc_io_actual_shipping_nonce'] ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_shop_orders', $order_id ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::INPUT_NAME ] ) ) {
			return;
		}

		$order = null;
		if ( $post_or_order instanceof WC_Order ) {
			$order = $post_or_order;
		} else {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$raw = trim( wp_unslash( $_POST[ self::INPUT_NAME ] ) );
		$raw = wc_clean( $raw );

		if ( '' === $raw ) {
			$order->delete_meta_data( self::META_KEY );
			$order->save();
			return;
		}

		if ( ! preg_match( '/\d/', $raw ) ) {
			return;
		}

		$decimals = wc_get_price_decimals() + 4;
		$decimals = max( 4, min( 8, (int) $decimals ) );
		$formatted = wc_format_decimal( $raw, $decimals );

		if ( ! is_numeric( $formatted ) || (float) $formatted < 0 ) {
			return;
		}

		$order->update_meta_data( self::META_KEY, $formatted );
		$order->save();
	}
}
