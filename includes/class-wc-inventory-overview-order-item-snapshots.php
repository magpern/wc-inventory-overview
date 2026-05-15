<?php
/**
 * Persist per–order line item cost and price snapshots when an order is paid / fulfilled.
 *
 * Snapshots are written once per line item when the order first reaches a status allowed in plugin settings (processing, completed, or both).
 * A future admin “force resnapshot” flow may delete snapshot metas on line items and re-run; that is not implemented here.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order item meta: WooCommerce line money at snapshot time (after discounts), qty, cost, and derived fields.
 */
class WC_Inventory_Overview_Order_Item_Snapshots {

	/** Unit revenue after discount on the line (mirrors _wc_io_unit_revenue_snapshot); not live catalog price. */
	public const META_SALE_UNIT_SNAPSHOT = '_wc_io_sale_price_snapshot';

	public const META_QTY_SNAPSHOT = '_wc_io_quantity_snapshot';

	public const META_AVG_COST_SNAPSHOT = '_wc_io_average_unit_cost_snapshot';

	public const META_PRODUCT_COST_TOTAL = '_wc_io_product_cost_total';

	public const META_LINE_SUBTOTAL_SNAPSHOT = '_wc_io_line_subtotal_snapshot';

	public const META_LINE_TOTAL_SNAPSHOT = '_wc_io_line_total_snapshot';

	public const META_DISCOUNT_SNAPSHOT = '_wc_io_discount_snapshot';

	public const META_UNIT_REVENUE_SNAPSHOT = '_wc_io_unit_revenue_snapshot';

	public const META_LINE_GROSS_PROFIT_SNAPSHOT = '_wc_io_line_gross_profit_snapshot';

	public static function register() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 10, 4 );
	}

	/**
	 * @param int              $order_id Order ID.
	 * @param string           $from     Previous status.
	 * @param string           $to       New status.
	 * @param WC_Order|false   $order    Order object.
	 */
	public static function on_order_status_changed( $order_id, $from, $to, $order ) {
		unset( $from );
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! WC_Inventory_Overview_Settings::should_snapshot_on_transition_to( $to ) ) {
			return;
		}
		self::snapshot_line_items( $order );
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public static function snapshot_line_items( WC_Order $order ) {
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$item_id = (int) $item_id;
			if ( metadata_exists( 'order_item', $item_id, self::META_SALE_UNIT_SNAPSHOT ) ) {
				continue;
			}

			$qty = (float) wc_stock_amount( (float) $item->get_quantity() );
			if ( $qty <= 0 ) {
				$qty = 0.0;
			}

			$line_subtotal = (float) wc_format_decimal( (string) $item->get_subtotal(), 8 );
			$line_total    = (float) wc_format_decimal( (string) $item->get_total(), 8 );
			$line_discount = $line_subtotal - $line_total;

			// Unit revenue after discount (line total / qty); kept on _wc_io_sale_price_snapshot for backward compatibility.
			$sale_unit = $qty > 0 ? (float) wc_format_decimal( $line_total / $qty, 6 ) : 0.0;

			$product = self::get_product_for_line_item( $item );
			$avg_raw = null;
			if ( $product instanceof WC_Product && ( $product->is_type( 'variation' ) || $product->is_type( 'simple' ) ) ) {
				$avg_raw = WC_Inventory_Overview_Costing::get_average_raw( $product );
			}

			$avg_float = null;
			if ( null !== $avg_raw && '' !== $avg_raw ) {
				$avg_float = (float) wc_format_decimal( $avg_raw, 6 );
			}

			$avg_snapshot = null === $avg_float ? '' : wc_format_decimal( $avg_float, 6 );
			$cost_total   = ( null === $avg_float || $qty <= 0 )
				? wc_format_decimal( 0, 4 )
				: wc_format_decimal( $qty * $avg_float, 4 );

			$cost_total_float = (float) wc_format_decimal( $cost_total, 8 );
			$line_gross       = wc_format_decimal( $line_total - $cost_total_float, 4 );

			$item->update_meta_data( self::META_LINE_SUBTOTAL_SNAPSHOT, wc_format_decimal( $line_subtotal, 6 ) );
			$item->update_meta_data( self::META_LINE_TOTAL_SNAPSHOT, wc_format_decimal( $line_total, 6 ) );
			$item->update_meta_data( self::META_DISCOUNT_SNAPSHOT, wc_format_decimal( $line_discount, 6 ) );
			$item->update_meta_data( self::META_SALE_UNIT_SNAPSHOT, wc_format_decimal( $sale_unit, 6 ) );
			$item->update_meta_data( self::META_UNIT_REVENUE_SNAPSHOT, wc_format_decimal( $sale_unit, 6 ) );
			$item->update_meta_data( self::META_QTY_SNAPSHOT, wc_format_decimal( $qty, 4 ) );
			$item->update_meta_data( self::META_AVG_COST_SNAPSHOT, $avg_snapshot );
			$item->update_meta_data( self::META_PRODUCT_COST_TOTAL, $cost_total );
			$item->update_meta_data( self::META_LINE_GROSS_PROFIT_SNAPSHOT, $line_gross );

			$item->save();
		}
	}

	/**
	 * @param WC_Order_Item_Product $item Line item.
	 * @return WC_Product|null
	 */
	protected static function get_product_for_line_item( WC_Order_Item_Product $item ) {
		$vid = (int) $item->get_variation_id();
		$pid = (int) $item->get_product_id();
		$id  = $vid > 0 ? $vid : $pid;
		if ( ! $id ) {
			return null;
		}
		$product = wc_get_product( $id );
		return $product instanceof WC_Product ? $product : null;
	}
}
