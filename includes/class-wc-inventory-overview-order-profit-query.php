<?php
/**
 * Order profit report: discover orders with line-item snapshots + compute snapshot-only metrics.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Filters and aggregates for the Order Profit tab.
 */
class WC_Inventory_Overview_Order_Profit_Query {

	/**
	 * Parse and validate list/export filter parameters from the current request.
	 *
	 * @return array{date_from:string,date_to:string,statuses:string[]}
	 */
	public static function get_filters_from_request() {
		$date_from = isset( $_REQUEST['wc_io_op_date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wc_io_op_date_from'] ) ) : '';
		$date_to   = isset( $_REQUEST['wc_io_op_date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wc_io_op_date_to'] ) ) : '';

		if ( $date_from && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$date_from = '';
		}
		if ( $date_to && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$date_to = '';
		}

		$had_date_in_request = isset( $_REQUEST['wc_io_op_date_from'] ) || isset( $_REQUEST['wc_io_op_date_to'] );
		if ( ! $had_date_in_request && '' === $date_from && '' === $date_to ) {
			$defaults  = WC_Inventory_Overview_Settings::get_default_report_date_bounds_local();
			$date_from = $defaults['date_from'];
			$date_to   = $defaults['date_to'];
		}

		$valid_statuses = array_keys( wc_get_order_statuses() );
		$statuses       = $valid_statuses;

		if ( isset( $_REQUEST['wc_io_op_status'] ) ) {
			$raw = sanitize_key( wp_unslash( (string) $_REQUEST['wc_io_op_status'] ) );
			if ( $raw && 'all' !== $raw && in_array( $raw, $valid_statuses, true ) ) {
				$statuses = array( $raw );
			}
		}

		return array(
			'date_from' => $date_from,
			'date_to'   => $date_to,
			'statuses'  => $statuses,
		);
	}

	/**
	 * Convert local calendar dates to UTC bounds for SQL (inclusive).
	 *
	 * @param string $date_from Y-m-d or empty.
	 * @param string $date_to   Y-m-d or empty.
	 * @return array{from:?string,to:?string} MySQL datetime GMT or null when open-ended.
	 */
	public static function get_gmt_bounds( $date_from, $date_to ) {
		$tz = wp_timezone();

		$from_gmt = null;
		$to_gmt   = null;

		if ( $date_from ) {
			try {
				$from_dt  = new DateTimeImmutable( $date_from . ' 00:00:00', $tz );
				$from_gmt = $from_dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			} catch ( Exception $e ) {
				unset( $e );
				$from_gmt = null;
			}
		}

		if ( $date_to ) {
			try {
				$to_dt  = new DateTimeImmutable( $date_to . ' 23:59:59', $tz );
				$to_gmt = $to_dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			} catch ( Exception $e ) {
				unset( $e );
				$to_gmt = null;
			}
		}

		return array(
			'from' => $from_gmt,
			'to'   => $to_gmt,
		);
	}

	/**
	 * Snapshot-only product revenue, line discounts, and product cost for an order (ignores lines without snapshots).
	 *
	 * Product revenue prefers {@see WC_Inventory_Overview_Order_Item_Snapshots::META_LINE_TOTAL_SNAPSHOT}
	 * (WooCommerce line total after discount at snapshot time); otherwise falls back to
	 * unit snapshot × quantity for older snapshots.
	 *
	 * @param WC_Order $order Order.
	 * @return array{product_revenue: float, line_discount: float, product_cost: float}
	 */
	public static function sum_snapshot_product_amounts( WC_Order $order ) {
		$product_revenue = 0.0;
		$line_discount   = 0.0;
		$product_cost    = 0.0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$item_id = (int) $item->get_id();
			if ( ! metadata_exists( 'order_item', $item_id, WC_Inventory_Overview_Order_Item_Snapshots::META_SALE_UNIT_SNAPSHOT ) ) {
				continue;
			}

			$sale_raw = $item->get_meta( WC_Inventory_Overview_Order_Item_Snapshots::META_SALE_UNIT_SNAPSHOT, true );
			$qty_raw  = $item->get_meta( WC_Inventory_Overview_Order_Item_Snapshots::META_QTY_SNAPSHOT, true );
			$cost_raw = $item->get_meta( WC_Inventory_Overview_Order_Item_Snapshots::META_PRODUCT_COST_TOTAL, true );
			$lt_raw   = $item->get_meta( WC_Inventory_Overview_Order_Item_Snapshots::META_LINE_TOTAL_SNAPSHOT, true );
			$disc_raw = $item->get_meta( WC_Inventory_Overview_Order_Item_Snapshots::META_DISCOUNT_SNAPSHOT, true );

			$sale = (float) wc_format_decimal( (string) $sale_raw, 6 );
			$qty  = (float) wc_format_decimal( (string) $qty_raw, 6 );
			$cost = (float) wc_format_decimal( (string) $cost_raw, 4 );

			if ( null !== $lt_raw && '' !== trim( (string) $lt_raw ) ) {
				$product_revenue += (float) wc_format_decimal( (string) $lt_raw, 8 );
			} else {
				$product_revenue += $sale * $qty;
			}

			if ( null !== $disc_raw && '' !== trim( (string) $disc_raw ) ) {
				$line_discount += (float) wc_format_decimal( (string) $disc_raw, 8 );
			}

			$product_cost += $cost;
		}

		return array(
			'product_revenue' => $product_revenue,
			'line_discount'   => $line_discount,
			'product_cost'    => $product_cost,
		);
	}

	/**
	 * Shipping paid by customer (not from line snapshots).
	 *
	 * @param WC_Order $order Order.
	 * @return float
	 */
	public static function get_shipping_paid( WC_Order $order ) {
		$shipping = (float) wc_format_decimal( (string) $order->get_shipping_total(), wc_get_price_decimals() );
		if ( WC_Inventory_Overview_Settings::include_shipping_tax() ) {
			$shipping += (float) wc_format_decimal( (string) $order->get_shipping_tax(), wc_get_price_decimals() );
		}
		return $shipping;
	}

	/**
	 * Actual shipping cost from order meta (admin field).
	 *
	 * @param WC_Order $order Order.
	 * @return float
	 */
	public static function get_actual_shipping_cost( WC_Order $order ) {
		$raw = $order->get_meta( WC_Inventory_Overview_Order_Shipping_Admin::META_KEY, true );
		if ( '' === $raw || null === $raw ) {
			return WC_Inventory_Overview_Settings::get_default_actual_shipping_cost();
		}
		return (float) wc_format_decimal( (string) $raw, 6 );
	}

	/**
	 * Full metrics row for display or CSV.
	 *
	 * @param WC_Order $order Order.
	 * @return array{product_revenue:float,line_discount:float,shipping_paid:float,product_cost:float,actual_shipping_cost:float,gross_profit:float,margin_percent:?float}
	 */
	public static function get_metrics_for_order( WC_Order $order ) {
		$sums                 = self::sum_snapshot_product_amounts( $order );
		$product_revenue      = $sums['product_revenue'];
		$line_discount        = $sums['line_discount'];
		$product_cost         = $sums['product_cost'];
		$shipping_paid        = self::get_shipping_paid( $order );
		$actual_shipping_cost = self::get_actual_shipping_cost( $order );

		$gross_profit = $product_revenue + $shipping_paid - $product_cost - $actual_shipping_cost;

		$denom = $product_revenue + $shipping_paid;
		$margin_percent = null;
		if ( $denom > 0.0000001 ) {
			$margin_percent = ( $gross_profit / $denom ) * 100.0;
		}

		return array(
			'product_revenue'      => $product_revenue,
			'line_discount'        => $line_discount,
			'shipping_paid'        => $shipping_paid,
			'product_cost'         => $product_cost,
			'actual_shipping_cost' => $actual_shipping_cost,
			'gross_profit'         => $gross_profit,
			'margin_percent'       => $margin_percent,
		);
	}

	/**
	 * @return bool
	 */
	protected static function is_hpos_orders() {
		return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			&& class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class );
	}

	/**
	 * @param array{date_from:string,date_to:string,statuses:string[]} $filters Filters.
	 * @param array{from:?string,to:?string}                           $bounds  GMT bounds.
	 * @return array{sql:string,args:(int|float|string)[]}
	 */
	protected static function build_sql_parts( array $filters, array $bounds ) {
		global $wpdb;

		$hpos        = self::is_hpos_orders();
		$snapshot_key = WC_Inventory_Overview_Order_Item_Snapshots::META_SALE_UNIT_SNAPSHOT;
		$oi_table    = $wpdb->prefix . 'woocommerce_order_items';
		$oim_table   = $wpdb->prefix . 'woocommerce_order_itemmeta';

		$statuses = $filters['statuses'];
		$in_st    = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$args = array( $snapshot_key );
		foreach ( $statuses as $st ) {
			$args[] = $st;
		}

		if ( $hpos ) {
			$orders_table = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name();
			$sql          = "SELECT COUNT(DISTINCT w.id) FROM {$orders_table} AS w
				INNER JOIN {$oi_table} AS oi ON oi.order_id = w.id AND oi.order_item_type = 'line_item'
				INNER JOIN {$oim_table} AS io_snap ON io_snap.order_item_id = oi.order_item_id AND io_snap.meta_key = %s
				WHERE w.type = 'shop_order' AND w.status IN ({$in_st})";
			$date_col = 'w.date_created_gmt';
			$id_col   = 'w.id';
		} else {
			$sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} AS p
				INNER JOIN {$oi_table} AS oi ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
				INNER JOIN {$oim_table} AS io_snap ON io_snap.order_item_id = oi.order_item_id AND io_snap.meta_key = %s
				WHERE p.post_type = 'shop_order' AND p.post_status IN ({$in_st})";
			$date_col = 'p.post_date_gmt';
			$id_col   = 'p.ID';
		}

		if ( null !== $bounds['from'] ) {
			$sql     .= " AND {$date_col} >= %s";
			$args[] = $bounds['from'];
		}
		if ( null !== $bounds['to'] ) {
			$sql     .= " AND {$date_col} <= %s";
			$args[] = $bounds['to'];
		}

		return array(
			'sql'     => $sql,
			'args'    => $args,
			'date_col' => $date_col,
			'id_col'   => $id_col,
			'hpos'     => $hpos,
			'oi_table' => $oi_table,
			'oim_table' => $oim_table,
			'snapshot_key' => $snapshot_key,
			'in_st'    => $in_st,
		);
	}

	/**
	 * @param array{date_from:string,date_to:string,statuses:string[]} $filters Filters.
	 * @return int Distinct order count.
	 */
	public static function count_orders( array $filters ) {
		global $wpdb;

		if ( empty( $filters['statuses'] ) ) {
			return 0;
		}

		$bounds = self::get_gmt_bounds( $filters['date_from'], $filters['date_to'] );
		$parts  = self::build_sql_parts( $filters, $bounds );
		$sql    = $parts['sql'];
		$args   = $parts['args'];

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared = $wpdb->prepare( $sql, $args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $prepared );
	}

	/**
	 * @param array{date_from:string,date_to:string,statuses:string[]} $filters Filters.
	 * @param int                                                       $limit  Page size.
	 * @param int                                                       $offset Offset.
	 * @return int[] Order IDs newest first.
	 */
	public static function get_order_ids( array $filters, $limit, $offset ) {
		global $wpdb;

		if ( empty( $filters['statuses'] ) ) {
			return array();
		}

		$limit  = max( 1, min( 500, (int) $limit ) );
		$offset = max( 0, (int) $offset );

		$bounds = self::get_gmt_bounds( $filters['date_from'], $filters['date_to'] );
		$parts  = self::build_sql_parts( $filters, $bounds );

		$hpos        = $parts['hpos'];
		$orders_table = $hpos
			? \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name()
			: $wpdb->posts;

		if ( $hpos ) {
			$from_sql = "SELECT DISTINCT w.id FROM {$orders_table} AS w
				INNER JOIN {$parts['oi_table']} AS oi ON oi.order_id = w.id AND oi.order_item_type = 'line_item'
				INNER JOIN {$parts['oim_table']} AS io_snap ON io_snap.order_item_id = oi.order_item_id AND io_snap.meta_key = %s
				WHERE w.type = 'shop_order' AND w.status IN ({$parts['in_st']})";
		} else {
			$from_sql = "SELECT DISTINCT p.ID FROM {$orders_table} AS p
				INNER JOIN {$parts['oi_table']} AS oi ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
				INNER JOIN {$parts['oim_table']} AS io_snap ON io_snap.order_item_id = oi.order_item_id AND io_snap.meta_key = %s
				WHERE p.post_type = 'shop_order' AND p.post_status IN ({$parts['in_st']})";
		}

		$args = array( $parts['snapshot_key'] );
		foreach ( $filters['statuses'] as $st ) {
			$args[] = $st;
		}

		if ( null !== $bounds['from'] ) {
			$from_sql .= " AND {$parts['date_col']} >= %s";
			$args[]    = $bounds['from'];
		}
		if ( null !== $bounds['to'] ) {
			$from_sql .= " AND {$parts['date_col']} <= %s";
			$args[]    = $bounds['to'];
		}

		$from_sql .= " ORDER BY {$parts['date_col']} DESC, {$parts['id_col']} DESC LIMIT %d OFFSET %d";
		$args[]    = $limit;
		$args[]    = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared = $wpdb->prepare( $from_sql, $args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $prepared );

		return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
	}

	/**
	 * Single pass: dashboard totals plus per–local-day snapshot revenue and gross profit (order creation date).
	 *
	 * @param array{date_from:string,date_to:string,statuses:string[]} $filters Filters.
	 * @return array{totals: array{order_count:int,product_revenue:float,shipping_paid:float,product_cost:float,actual_shipping_cost:float,gross_profit:float,margin_percent:?float}, daily_buckets: array<string, array{r: float, g: float}>}
	 */
	public static function get_dashboard_order_rollup( array $filters ) {
		$empty_totals = array(
			'order_count'          => 0,
			'product_revenue'      => 0.0,
			'shipping_paid'        => 0.0,
			'product_cost'         => 0.0,
			'actual_shipping_cost' => 0.0,
			'gross_profit'         => 0.0,
			'margin_percent'       => null,
		);

		if ( empty( $filters['statuses'] ) ) {
			return array(
				'totals'         => $empty_totals,
				'daily_buckets'  => array(),
			);
		}

		$order_count = self::count_orders( $filters );

		$tot_r = 0.0;
		$tot_s = 0.0;
		$tot_c = 0.0;
		$tot_a = 0.0;
		$daily = array();

		foreach ( self::iterate_order_ids( $filters, 200 ) as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$m = self::get_metrics_for_order( $order );
			$tot_r += $m['product_revenue'];
			$tot_s += $m['shipping_paid'];
			$tot_c += $m['product_cost'];
			$tot_a += $m['actual_shipping_cost'];

			$day_key = self::order_created_local_date_key( $order );
			if ( '' === $day_key ) {
				continue;
			}
			if ( ! isset( $daily[ $day_key ] ) ) {
				$daily[ $day_key ] = array(
					'r' => 0.0,
					'g' => 0.0,
				);
			}
			$daily[ $day_key ]['r'] += $m['product_revenue'];
			$daily[ $day_key ]['g'] += $m['gross_profit'];
		}

		$tot_gp = $tot_r + $tot_s - $tot_c - $tot_a;

		$denom = $tot_r + $tot_s;
		$margin_percent = null;
		if ( $denom > 0.0000001 ) {
			$margin_percent = ( $tot_gp / $denom ) * 100.0;
		}

		$totals = array(
			'order_count'          => $order_count,
			'product_revenue'      => $tot_r,
			'shipping_paid'        => $tot_s,
			'product_cost'         => $tot_c,
			'actual_shipping_cost' => $tot_a,
			'gross_profit'         => $tot_gp,
			'margin_percent'       => $margin_percent,
		);

		return array(
			'totals'        => $totals,
			'daily_buckets' => $daily,
		);
	}

	/**
	 * Local calendar date (Y-m-d) for the order’s creation timestamp.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	protected static function order_created_local_date_key( WC_Order $order ) {
		$dt = $order->get_date_created();
		if ( ! $dt ) {
			return '';
		}

		return wp_date( 'Y-m-d', $dt->getTimestamp() );
	}

	/**
	 * Build labels and series for Revenue vs Gross Profit chart.
	 *
	 * @param array{date_from:string,date_to:string,statuses:string[]} $filters Filters.
	 * @param array<string, array{r: float, g: float}>                    $daily_buckets From {@see get_dashboard_order_rollup()}.
	 * @return array{available:bool,message:string,granularity:string,labels:string[],revenue:float[],gross_profit:float[]}
	 */
	public static function format_dashboard_revenue_profit_series( array $filters, array $daily_buckets ) {
		$from = $filters['date_from'];
		$to   = $filters['date_to'];
		$both = ( '' !== $from && '' !== $to );

		if ( ! $both ) {
			ksort( $daily_buckets );
			$labels  = array_keys( $daily_buckets );
			$revenue = array();
			$gp      = array();
			foreach ( $daily_buckets as $b ) {
				$revenue[] = $b['r'];
				$gp[]      = $b['g'];
			}
			if ( empty( $labels ) ) {
				return array(
					'available'    => false,
					'message'      => __( 'No sales data yet', 'wc-inventory-overview' ),
					'hint'         => __( 'Sales and profit charts will populate automatically after orders are completed.', 'wc-inventory-overview' ),
					'detail'       => __( 'No orders with snapshot lines matched the current filters. Adjust the date range or order status settings.', 'wc-inventory-overview' ),
					'granularity'  => 'day',
					'labels'       => array(),
					'revenue'      => array(),
					'gross_profit' => array(),
				);
			}
			return array(
				'available'    => true,
				'message'      => '',
				'granularity'  => 'day',
				'labels'       => $labels,
				'revenue'      => $revenue,
				'gross_profit' => $gp,
			);
		}

		try {
			$d0 = new DateTimeImmutable( $from . ' 00:00:00', wp_timezone() );
			$d1 = new DateTimeImmutable( $to . ' 00:00:00', wp_timezone() );
		} catch ( Exception $e ) {
			unset( $e );
			return array(
				'available'    => false,
				'message'      => __( 'Invalid date range', 'wc-inventory-overview' ),
				'detail'       => __( 'Check the from and to dates and try again.', 'wc-inventory-overview' ),
				'granularity'  => 'day',
				'labels'       => array(),
				'revenue'      => array(),
				'gross_profit' => array(),
			);
		}

		if ( $d0 > $d1 ) {
			return array(
				'available'    => false,
				'message'      => __( 'Date range error', 'wc-inventory-overview' ),
				'detail'       => __( 'Date from must be on or before date to.', 'wc-inventory-overview' ),
				'granularity'  => 'day',
				'labels'       => array(),
				'revenue'      => array(),
				'gross_profit' => array(),
			);
		}

		$days_inclusive = (int) $d0->diff( $d1 )->format( '%a' ) + 1;

		if ( $days_inclusive > 120 ) {
			$months = array();
			foreach ( $daily_buckets as $day => $b ) {
				$ym = substr( $day, 0, 7 );
				if ( ! isset( $months[ $ym ] ) ) {
					$months[ $ym ] = array(
						'r' => 0.0,
						'g' => 0.0,
					);
				}
				$months[ $ym ]['r'] += $b['r'];
				$months[ $ym ]['g'] += $b['g'];
			}
			ksort( $months );
			$labels  = array_keys( $months );
			$revenue = array();
			$gp      = array();
			foreach ( $months as $b ) {
				$revenue[] = $b['r'];
				$gp[]      = $b['g'];
			}
			if ( empty( $labels ) ) {
				return array(
					'available'    => false,
					'message'      => __( 'No sales data yet', 'wc-inventory-overview' ),
					'hint'         => __( 'Sales and profit charts will populate automatically after orders are completed.', 'wc-inventory-overview' ),
					'detail'       => __( 'No orders with snapshot lines in this date range.', 'wc-inventory-overview' ),
					'granularity'  => 'month',
					'labels'       => array(),
					'revenue'      => array(),
					'gross_profit' => array(),
				);
			}
			return array(
				'available'    => true,
				'message'      => '',
				'granularity'  => 'month',
				'labels'       => $labels,
				'revenue'      => $revenue,
				'gross_profit' => $gp,
			);
		}

		$labels  = array();
		$revenue = array();
		$gp      = array();
		$cur     = $d0;
		while ( $cur <= $d1 ) {
			$k         = $cur->format( 'Y-m-d' );
			$labels[]  = $k;
			$revenue[] = isset( $daily_buckets[ $k ] ) ? $daily_buckets[ $k ]['r'] : 0.0;
			$gp[]      = isset( $daily_buckets[ $k ] ) ? $daily_buckets[ $k ]['g'] : 0.0;
			$cur       = $cur->modify( '+1 day' );
		}

		return array(
			'available'    => true,
			'message'      => '',
			'granularity'  => 'day',
			'labels'       => $labels,
			'revenue'      => $revenue,
			'gross_profit' => $gp,
		);
	}

	/**
	 * Sum snapshot revenue, shipping, costs, and gross profit for dashboard KPIs.
	 *
	 * Uses {@see get_metrics_for_order()} per order (respects shipping tax and default actual shipping settings).
	 * Only orders with at least one snapshot line and statuses in `$filters['statuses']` are included.
	 *
	 * @param array{date_from:string,date_to:string,statuses:string[]} $filters Filters.
	 * @return array{order_count:int,product_revenue:float,shipping_paid:float,product_cost:float,actual_shipping_cost:float,gross_profit:float,margin_percent:?float}
	 */
	public static function aggregate_dashboard_totals( array $filters ) {
		$rollup = self::get_dashboard_order_rollup( $filters );
		return $rollup['totals'];
	}

	/**
	 * @param array{date_from:string,date_to:string,statuses:string[]} $filters Filters.
	 * @param int                                                       $chunk_size Batch size.
	 * @return \Generator<int, int>
	 */
	public static function iterate_order_ids( array $filters, $chunk_size = 200 ) {
		$chunk_size = max( 50, min( 500, (int) $chunk_size ) );
		$total      = self::count_orders( $filters );
		$offset     = 0;
		while ( $offset < $total ) {
			$batch = self::get_order_ids( $filters, $chunk_size, $offset );
			foreach ( $batch as $id ) {
				yield (int) $id;
			}
			$offset += $chunk_size;
		}
	}
}
