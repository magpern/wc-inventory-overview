<?php
/**
 * Product profitability: aggregate snapshotted line items on orders matching snapshot status settings.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * SQL aggregates for the Product Profitability tab (no live product cost).
 */
class WC_Inventory_Overview_Product_Profitability_Query {

	/**
	 * @return bool
	 */
	protected static function is_hpos_orders() {
		return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			&& class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class );
	}

	/**
	 * @return array{date_from:string,date_to:string}
	 */
	public static function get_filters_from_request() {
		$date_from = isset( $_REQUEST['wc_io_pp_date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wc_io_pp_date_from'] ) ) : '';
		$date_to   = isset( $_REQUEST['wc_io_pp_date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wc_io_pp_date_to'] ) ) : '';

		if ( $date_from && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$date_from = '';
		}
		if ( $date_to && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$date_to = '';
		}

		$had_date_in_request = isset( $_REQUEST['wc_io_pp_date_from'] ) || isset( $_REQUEST['wc_io_pp_date_to'] );
		if ( ! $had_date_in_request && '' === $date_from && '' === $date_to ) {
			$defaults  = WC_Inventory_Overview_Settings::get_default_report_date_bounds_local();
			$date_from = $defaults['date_from'];
			$date_to   = $defaults['date_to'];
		}

		return array(
			'date_from' => $date_from,
			'date_to'   => $date_to,
		);
	}

	/**
	 * @param string $date_from Y-m-d or empty.
	 * @param string $date_to   Y-m-d or empty.
	 * @return array{from:?string,to:?string}
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
	 * Group key: variation ID when set, else product ID.
	 *
	 * @return string SQL expression (no user input).
	 */
	protected static function sql_product_key_expr( $vid_alias, $pid_alias ) {
		return "CASE WHEN NULLIF(TRIM({$vid_alias}.meta_value), '') IS NOT NULL AND CAST({$vid_alias}.meta_value AS UNSIGNED) > 0 THEN CAST({$vid_alias}.meta_value AS UNSIGNED) ELSE CAST({$pid_alias}.meta_value AS UNSIGNED) END";
	}

	/**
	 * @param array{date_from:string,date_to:string} $filters Filters.
	 * @param array{from:?string,to:?string}         $bounds  GMT bounds.
	 * @return array{from_sql:string,args:array<int|float|string>,date_col:string,hpos:bool}
	 */
	protected static function build_aggregation_from( array $filters, array $bounds ) {
		unset( $filters );
		global $wpdb;

		$hpos       = self::is_hpos_orders();
		$oi_table   = $wpdb->prefix . 'woocommerce_order_items';
		$oim_table  = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$snap_key   = WC_Inventory_Overview_Order_Item_Snapshots::META_SALE_UNIT_SNAPSHOT;

		$statuses = WC_Inventory_Overview_Settings::get_profitability_sql_order_statuses();
		$st_in    = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$args = $statuses;

		if ( $hpos ) {
			$orders_table = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name();
			$from_sql     = " FROM {$oi_table} AS oi
				INNER JOIN {$orders_table} AS ord ON ord.id = oi.order_id AND ord.type = 'shop_order' AND ord.status IN ({$st_in})";
			$date_col = 'ord.date_created_gmt';
		} else {
			$from_sql = " FROM {$oi_table} AS oi
				INNER JOIN {$wpdb->posts} AS ord ON ord.ID = oi.order_id AND ord.post_type = 'shop_order' AND ord.post_status IN ({$st_in})";
			$date_col = 'ord.post_date_gmt';
		}

		$from_sql .= "
			INNER JOIN {$oim_table} AS sale ON sale.order_item_id = oi.order_item_id AND sale.meta_key = %s
			INNER JOIN {$oim_table} AS qty ON qty.order_item_id = oi.order_item_id AND qty.meta_key = %s
			INNER JOIN {$oim_table} AS cost ON cost.order_item_id = oi.order_item_id AND cost.meta_key = %s
			LEFT JOIN {$oim_table} AS lt ON lt.order_item_id = oi.order_item_id AND lt.meta_key = %s
			LEFT JOIN {$oim_table} AS disc ON disc.order_item_id = oi.order_item_id AND disc.meta_key = %s
			INNER JOIN {$oim_table} AS pid ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id'
			LEFT JOIN {$oim_table} AS vid ON vid.order_item_id = oi.order_item_id AND vid.meta_key = '_variation_id'
			WHERE oi.order_item_type = 'line_item'";

		$args[] = $snap_key;
		$args[] = WC_Inventory_Overview_Order_Item_Snapshots::META_QTY_SNAPSHOT;
		$args[] = WC_Inventory_Overview_Order_Item_Snapshots::META_PRODUCT_COST_TOTAL;
		$args[] = WC_Inventory_Overview_Order_Item_Snapshots::META_LINE_TOTAL_SNAPSHOT;
		$args[] = WC_Inventory_Overview_Order_Item_Snapshots::META_DISCOUNT_SNAPSHOT;

		if ( null !== $bounds['from'] ) {
			$from_sql .= " AND {$date_col} >= %s";
			$args[]    = $bounds['from'];
		}
		if ( null !== $bounds['to'] ) {
			$from_sql .= " AND {$date_col} <= %s";
			$args[]    = $bounds['to'];
		}

		return array(
			'from_sql' => $from_sql,
			'args'     => $args,
			'date_col' => $date_col,
			'hpos'     => $hpos,
		);
	}

	/**
	 * @param array{date_from:string,date_to:string} $filters Filters.
	 * @return int Number of distinct products/variations in the aggregate.
	 */
	public static function count_products( array $filters ) {
		global $wpdb;

		$bounds = self::get_gmt_bounds( $filters['date_from'], $filters['date_to'] );
		$parts  = self::build_aggregation_from( $filters, $bounds );

		$key_expr = self::sql_product_key_expr( 'vid', 'pid' );

		$sql = "SELECT COUNT(1) FROM (
			SELECT {$key_expr} AS agg_product_id
			{$parts['from_sql']}
			GROUP BY {$key_expr}
		) AS agg";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared = $wpdb->prepare( $sql, $parts['args'] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $prepared );
	}

	/**
	 * @param array{date_from:string,date_to:string} $filters Filters.
	 * @param int                                   $limit   Page size.
	 * @param int                                   $offset  Offset.
	 * @return object[] Rows with agg_product_id, units_sold, revenue, line_discount, product_cost (strings from DB).
	 */
	public static function get_rows( array $filters, $limit, $offset ) {
		global $wpdb;

		$limit  = max( 1, min( 500, (int) $limit ) );
		$offset = max( 0, (int) $offset );

		$bounds = self::get_gmt_bounds( $filters['date_from'], $filters['date_to'] );
		$parts  = self::build_aggregation_from( $filters, $bounds );

		$key_expr = self::sql_product_key_expr( 'vid', 'pid' );

		$sql = "SELECT
			{$key_expr} AS agg_product_id,
			SUM(CAST(qty.meta_value AS DECIMAL(24,8))) AS units_sold,
			SUM(
				CASE
					WHEN lt.meta_value IS NOT NULL AND TRIM(CAST(lt.meta_value AS CHAR)) <> ''
					THEN CAST(lt.meta_value AS DECIMAL(24,8))
					ELSE CAST(sale.meta_value AS DECIMAL(24,8)) * CAST(qty.meta_value AS DECIMAL(24,8))
				END
			) AS revenue,
			SUM(
				CASE
					WHEN disc.meta_value IS NOT NULL AND TRIM(CAST(disc.meta_value AS CHAR)) <> ''
					THEN CAST(disc.meta_value AS DECIMAL(24,8))
					ELSE 0
				END
			) AS line_discount,
			SUM(CAST(cost.meta_value AS DECIMAL(24,8))) AS product_cost
			{$parts['from_sql']}
			GROUP BY {$key_expr}
			ORDER BY revenue DESC, {$key_expr} ASC
			LIMIT %d OFFSET %d";

		$args   = array_merge( $parts['args'], array( $limit, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared = $wpdb->prepare( $sql, $args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $prepared, OBJECT );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array{date_from:string,date_to:string} $filters Filters.
	 * @param int                                   $chunk_size Rows per query.
	 * @return \Generator<int, object>
	 */
	public static function iterate_rows( array $filters, $chunk_size = 300 ) {
		$chunk_size = max( 50, min( 500, (int) $chunk_size ) );
		$total      = self::count_products( $filters );
		$offset     = 0;
		while ( $offset < $total ) {
			$batch = self::get_rows( $filters, $chunk_size, $offset );
			foreach ( $batch as $row ) {
				yield $row;
			}
			$offset += $chunk_size;
		}
	}
}
