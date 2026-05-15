<?php
/**
 * Danger Zone: preview and bulk-delete plugin analytics / test data (no WC stock or orders deleted).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parses reset requests, previews row/meta counts, and performs bulk DELETEs.
 */
class WC_Inventory_Overview_Data_Reset {

	public const SCOPE_24H = '24h';

	public const SCOPE_7D = '7d';

	public const SCOPE_CUSTOM = 'custom';

	public const SCOPE_ALL = 'all';

	public const TRANSIENT_PREVIEW_PREFIX = 'wc_io_dgr_pv_';

	public const TRANSIENT_RESULT_PREFIX = 'wc_io_dgr_rs_';

	public const PREVIEW_TTL = 1200;

	/**
	 * Order line item meta keys removed for order/product profitability snapshots.
	 *
	 * @return string[]
	 */
	public static function line_snapshot_meta_keys() {
		return array(
			WC_Inventory_Overview_Order_Item_Snapshots::META_LINE_SUBTOTAL_SNAPSHOT,
			WC_Inventory_Overview_Order_Item_Snapshots::META_LINE_TOTAL_SNAPSHOT,
			WC_Inventory_Overview_Order_Item_Snapshots::META_DISCOUNT_SNAPSHOT,
			WC_Inventory_Overview_Order_Item_Snapshots::META_QTY_SNAPSHOT,
			WC_Inventory_Overview_Order_Item_Snapshots::META_UNIT_REVENUE_SNAPSHOT,
			WC_Inventory_Overview_Order_Item_Snapshots::META_AVG_COST_SNAPSHOT,
			WC_Inventory_Overview_Order_Item_Snapshots::META_PRODUCT_COST_TOTAL,
			WC_Inventory_Overview_Order_Item_Snapshots::META_LINE_GROSS_PROFIT_SNAPSHOT,
			WC_Inventory_Overview_Order_Item_Snapshots::META_SALE_UNIT_SNAPSHOT,
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
	 * @param string $scope One of self::SCOPE_*.
	 * @param string $custom_from Y-m-d.
	 * @param string $custom_to   Y-m-d.
	 * @return array{from:?string,to:?string} MySQL datetime strings (UTC) for comparisons, or null bounds for all time.
	 */
	public static function bounds_for_scope( $scope, $custom_from, $custom_to ) {
		$scope = sanitize_key( (string) $scope );
		if ( self::SCOPE_ALL === $scope ) {
			return array(
				'from' => null,
				'to'   => null,
			);
		}

		if ( self::SCOPE_CUSTOM === $scope ) {
			return WC_Inventory_Overview_Order_Profit_Query::get_gmt_bounds( $custom_from, $custom_to );
		}

		$tz  = new DateTimeZone( 'UTC' );
		$now = new DateTimeImmutable( 'now', $tz );
		if ( self::SCOPE_24H === $scope ) {
			$from = $now->modify( '-24 hours' );
		} elseif ( self::SCOPE_7D === $scope ) {
			$from = $now->modify( '-7 days' );
		} else {
			return array(
				'from' => null,
				'to'   => null,
			);
		}

		return array(
			'from' => $from->format( 'Y-m-d H:i:s' ),
			'to'   => $now->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * @param array<string, mixed> $post Raw POST (unslashed by caller).
	 * @return array<string, mixed>|WP_Error Normalized payload.
	 */
	public static function parse_request_payload( array $post ) {
		$scope = isset( $post['wc_io_reset_date_scope'] ) ? sanitize_key( (string) $post['wc_io_reset_date_scope'] ) : self::SCOPE_ALL;
		if ( ! in_array( $scope, array( self::SCOPE_24H, self::SCOPE_7D, self::SCOPE_CUSTOM, self::SCOPE_ALL ), true ) ) {
			$scope = self::SCOPE_ALL;
		}

		$custom_from = isset( $post['wc_io_reset_date_from'] ) ? sanitize_text_field( (string) $post['wc_io_reset_date_from'] ) : '';
		$custom_to   = isset( $post['wc_io_reset_date_to'] ) ? sanitize_text_field( (string) $post['wc_io_reset_date_to'] ) : '';
		if ( $custom_from && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $custom_from ) ) {
			$custom_from = '';
		}
		if ( $custom_to && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $custom_to ) ) {
			$custom_to = '';
		}

		if ( self::SCOPE_CUSTOM === $scope && ( '' === $custom_from || '' === $custom_to ) ) {
			return new WP_Error(
				'wc_io_reset_dates',
				__( 'Custom date range requires both a start and end date.', 'wc-inventory-overview' )
			);
		}

		$reset_all = ! empty( $post['wc_io_reset_all'] );

		$targets = array(
			'movements'          => $reset_all || ! empty( $post['wc_io_reset_movements'] ),
			'batches'            => $reset_all || ! empty( $post['wc_io_reset_batches'] ),
			'line_snapshots'     => $reset_all || ! empty( $post['wc_io_reset_order_snapshots'] ) || ! empty( $post['wc_io_reset_pp_snapshots'] ),
			'shipping_meta'      => ! empty( $post['wc_io_reset_shipping_meta'] ),
			'product_cost_meta'  => ! empty( $post['wc_io_reset_product_cost_meta'] ),
		);

		if ( ! $targets['movements'] && ! $targets['batches'] && ! $targets['line_snapshots'] && ! $targets['shipping_meta'] && ! $targets['product_cost_meta'] ) {
			return new WP_Error(
				'wc_io_reset_none',
				__( 'Select at least one reset option.', 'wc-inventory-overview' )
			);
		}

		$bounds = self::bounds_for_scope( $scope, $custom_from, $custom_to );

		return array(
			'scope'               => $scope,
			'custom_from'         => $custom_from,
			'custom_to'           => $custom_to,
			'bounds'              => $bounds,
			'targets'             => $targets,
			'reset_all_checkbox'  => $reset_all,
		);
	}

	/**
	 * @param array<string, mixed> $payload From parse_request_payload.
	 * @return array<string, int>
	 */
	public static function preview_counts( array $payload ) {
		global $wpdb;

		$bounds  = $payload['bounds'];
		$targets = $payload['targets'];
		$counts  = array(
			'movements'                 => 0,
			'purchase_batches'          => 0,
			'purchase_batch_lines'      => 0,
			'purchase_batch_costs'      => 0,
			'order_line_snapshot_meta'  => 0,
			'order_shipping_meta'       => 0,
			'product_cost_meta_rows'    => 0,
		);

		$mov = WC_Inventory_Overview_Movements::table_name();
		if ( $targets['movements'] ) {
			$counts['movements'] = self::count_table_by_created_at( $mov, $bounds );
		}

		if ( $targets['batches'] ) {
			$batches = $wpdb->prefix . 'wc_io_purchase_batches';
			$lines   = $wpdb->prefix . 'wc_io_purchase_batch_lines';
			$costs   = $wpdb->prefix . 'wc_io_purchase_batch_costs';

			$batch_ids_sql = self::sql_batch_ids_in_date_range( $batches, $bounds );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- bounds embedded via helper.
			$ids = $wpdb->get_col( $batch_ids_sql['sql'] );
			if ( ! is_array( $ids ) ) {
				$ids = array();
			}
			$ids = array_filter( array_map( 'absint', $ids ) );
			$counts['purchase_batches'] = count( $ids );

			if ( ! empty( $ids ) ) {
				$in_list                    = implode( ',', $ids );
				$counts['purchase_batch_lines'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$lines} WHERE batch_id IN ({$in_list})" );
				$counts['purchase_batch_costs'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$costs} WHERE batch_id IN ({$in_list})" );
			}
		}

		if ( $targets['line_snapshots'] ) {
			$counts['order_line_snapshot_meta'] = self::count_order_item_meta_snapshots( $bounds );
		}

		if ( $targets['shipping_meta'] ) {
			$counts['order_shipping_meta'] = self::count_order_meta_shipping( $bounds );
		}

		if ( $targets['product_cost_meta'] ) {
			$counts['product_cost_meta_rows'] = self::count_product_cost_meta();
		}

		return $counts;
	}

	/**
	 * @param array<string, mixed> $payload Payload.
	 * @param array<string, int>   $preview_counts Counts from preview (must match after re-count).
	 * @return array<string, int>|WP_Error Deleted counts per category.
	 */
	public static function apply_reset( array $payload, array $preview_counts ) {
		global $wpdb;

		$fresh = self::preview_counts( $payload );
		foreach ( $preview_counts as $k => $v ) {
			if ( ! isset( $fresh[ $k ] ) || (int) $fresh[ $k ] !== (int) $v ) {
				return new WP_Error(
					'wc_io_reset_stale',
					__( 'The dataset changed since preview. Run Preview Reset again, then apply.', 'wc-inventory-overview' )
				);
			}
		}

		$bounds  = $payload['bounds'];
		$targets = $payload['targets'];
		$deleted = array(
			'movements'                 => 0,
			'purchase_batch_costs'      => 0,
			'purchase_batch_lines'      => 0,
			'purchase_batches'          => 0,
			'order_line_snapshot_meta'  => 0,
			'order_shipping_meta'       => 0,
			'product_cost_meta_rows'    => 0,
		);

		if ( $targets['movements'] ) {
			$mov                   = WC_Inventory_Overview_Movements::table_name();
			$deleted['movements'] = self::delete_table_by_created_at( $mov, $bounds );
		}

		if ( $targets['batches'] ) {
			$batches = $wpdb->prefix . 'wc_io_purchase_batches';
			$lines   = $wpdb->prefix . 'wc_io_purchase_batch_lines';
			$costs   = $wpdb->prefix . 'wc_io_purchase_batch_costs';

			$batch_ids_sql = self::sql_batch_ids_in_date_range( $batches, $bounds );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$ids = $wpdb->get_col( $batch_ids_sql['sql'] );
			if ( is_array( $ids ) && ! empty( $ids ) ) {
				$ids     = array_filter( array_map( 'absint', $ids ) );
				$in_list = implode( ',', $ids );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
				$deleted['purchase_batch_costs'] = (int) $wpdb->query( "DELETE FROM {$costs} WHERE batch_id IN ({$in_list})" );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$deleted['purchase_batch_lines'] = (int) $wpdb->query( "DELETE FROM {$lines} WHERE batch_id IN ({$in_list})" );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$deleted['purchase_batches'] = (int) $wpdb->query( "DELETE FROM {$batches} WHERE id IN ({$in_list})" );
			}
		}

		if ( $targets['line_snapshots'] ) {
			$deleted['order_line_snapshot_meta'] = self::delete_order_item_meta_snapshots( $bounds );
		}

		if ( $targets['shipping_meta'] ) {
			$deleted['order_shipping_meta'] = self::delete_order_meta_shipping( $bounds );
		}

		if ( $targets['product_cost_meta'] ) {
			$deleted['product_cost_meta_rows'] = self::delete_product_cost_meta();
		}

		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'product', true );
		}
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'orders' );
		}
		delete_expired_transients();

		return $deleted;
	}

	/**
	 * @param string               $table Prefixed table name.
	 * @param array{from:?string,to:?string} $bounds Datetime UTC.
	 */
	protected static function count_table_by_created_at( $table, array $bounds ) {
		global $wpdb;

		$sql  = "SELECT COUNT(*) FROM {$table} WHERE 1=1";
		$args = array();
		if ( null !== $bounds['from'] ) {
			$sql .= ' AND created_at >= %s';
			$args[] = $bounds['from'];
		}
		if ( null !== $bounds['to'] ) {
			$sql .= ' AND created_at <= %s';
			$args[] = $bounds['to'];
		}
		if ( $args ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $args );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * @param string               $table Prefixed table name.
	 * @param array{from:?string,to:?string} $bounds Datetime UTC.
	 * @return int Rows deleted.
	 */
	protected static function delete_table_by_created_at( $table, array $bounds ) {
		global $wpdb;

		$sql  = "DELETE FROM {$table} WHERE 1=1";
		$args = array();
		if ( null !== $bounds['from'] ) {
			$sql .= ' AND created_at >= %s';
			$args[] = $bounds['from'];
		}
		if ( null !== $bounds['to'] ) {
			$sql .= ' AND created_at <= %s';
			$args[] = $bounds['to'];
		}
		if ( $args ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $args );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query( $sql );
	}

	/**
	 * @param string $batches_table Prefixed batches table.
	 * @param array{from:?string,to:?string} $bounds Bounds.
	 * @return array{sql:string}
	 */
	protected static function sql_batch_ids_in_date_range( $batches_table, array $bounds ) {
		global $wpdb;

		$sql  = "SELECT id FROM {$batches_table} WHERE 1=1";
		$args = array();
		if ( null !== $bounds['from'] ) {
			$sql .= ' AND created_at >= %s';
			$args[] = $bounds['from'];
		}
		if ( null !== $bounds['to'] ) {
			$sql .= ' AND created_at <= %s';
			$args[] = $bounds['to'];
		}
		if ( $args ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $args );
		}
		return array( 'sql' => $sql );
	}

	/**
	 * @param array{from:?string,to:?string} $bounds Order date (GMT).
	 */
	protected static function order_join_sql_and_date_col() {
		global $wpdb;

		if ( self::is_hpos_orders() ) {
			$orders_table = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name();
			$from_sql     = " FROM {$wpdb->prefix}woocommerce_order_items AS oi
				INNER JOIN {$orders_table} AS ord ON ord.id = oi.order_id AND ord.type = 'shop_order' ";
			$date_col     = 'ord.date_created_gmt';
		} else {
			$from_sql = " FROM {$wpdb->prefix}woocommerce_order_items AS oi
				INNER JOIN {$wpdb->posts} AS ord ON ord.ID = oi.order_id AND ord.post_type = 'shop_order' ";
			$date_col = 'ord.post_date_gmt';
		}

		return array(
			'from_sql' => $from_sql,
			'date_col' => $date_col,
		);
	}

	/**
	 * @param array{from:?string,to:?string} $bounds Order date (GMT).
	 */
	protected static function count_order_item_meta_snapshots( array $bounds ) {
		global $wpdb;

		$oim_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$keys      = self::line_snapshot_meta_keys();
		$in_keys   = "'" . implode( "','", array_map( 'esc_sql', $keys ) ) . "'";

		$join = self::order_join_sql_and_date_col();
		$sql  = "SELECT COUNT(*) {$join['from_sql']}
			INNER JOIN {$oim_table} AS oim ON oim.order_item_id = oi.order_item_id
			WHERE oi.order_item_type = 'line_item' AND oim.meta_key IN ({$in_keys})";

		$sql .= self::sql_date_bounds_clause( $join['date_col'], $bounds );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * @param array{from:?string,to:?string} $bounds Order date (GMT).
	 * @return int Rows deleted.
	 */
	protected static function delete_order_item_meta_snapshots( array $bounds ) {
		global $wpdb;

		$oim_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$keys      = self::line_snapshot_meta_keys();
		$in_keys   = "'" . implode( "','", array_map( 'esc_sql', $keys ) ) . "'";

		$join = self::order_join_sql_and_date_col();
		$sql  = "DELETE oim FROM {$oim_table} AS oim
			INNER JOIN {$wpdb->prefix}woocommerce_order_items AS oi ON oi.order_item_id = oim.order_item_id AND oi.order_item_type = 'line_item'";
		if ( self::is_hpos_orders() ) {
			$sql .= self::hpos_order_join_fragment();
		} else {
			$sql .= self::legacy_order_join_fragment();
		}
		$sql .= " WHERE oim.meta_key IN ({$in_keys})";
		$sql .= self::sql_date_bounds_clause( $join['date_col'], $bounds );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query( $sql );
	}

	/**
	 * INNER JOIN fragment for HPOS (ord alias).
	 */
	protected static function hpos_order_join_fragment() {
		global $wpdb;
		$orders_table = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name();
		return " INNER JOIN {$orders_table} AS ord ON ord.id = oi.order_id AND ord.type = 'shop_order' ";
	}

	/**
	 * INNER JOIN fragment for posts (ord alias).
	 */
	protected static function legacy_order_join_fragment() {
		global $wpdb;
		return " INNER JOIN {$wpdb->posts} AS ord ON ord.ID = oi.order_id AND ord.post_type = 'shop_order' ";
	}

	/**
	 * @param string $date_col SQL expression with ord alias.
	 * @param array{from:?string,to:?string} $bounds Bounds.
	 * @return string Empty or " AND ..." fragment.
	 */
	protected static function sql_date_bounds_clause( $date_col, array $bounds ) {
		$parts = array();
		if ( null !== $bounds['from'] && '' !== $bounds['from'] ) {
			$parts[] = "{$date_col} >= '" . esc_sql( $bounds['from'] ) . "'";
		}
		if ( null !== $bounds['to'] && '' !== $bounds['to'] ) {
			$parts[] = "{$date_col} <= '" . esc_sql( $bounds['to'] ) . "'";
		}
		if ( empty( $parts ) ) {
			return '';
		}
		return ' AND ' . implode( ' AND ', $parts );
	}

	/**
	 * @param array{from:?string,to:?string} $bounds Order date (GMT).
	 */
	protected static function count_order_meta_shipping( array $bounds ) {
		global $wpdb;

		$key = esc_sql( WC_Inventory_Overview_Order_Shipping_Admin::META_KEY );

		if ( self::is_hpos_orders() ) {
			$orders_table = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name();
			$meta_table   = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_meta_table_name();
			$sql          = "SELECT COUNT(*) FROM {$meta_table} AS om
				INNER JOIN {$orders_table} AS ord ON ord.id = om.order_id AND ord.type = 'shop_order'
				WHERE om.meta_key = '{$key}'";
			$sql .= self::sql_date_bounds_clause( 'ord.date_created_gmt', $bounds );
		} else {
			$sql = "SELECT COUNT(*) FROM {$wpdb->postmeta} AS pm
				INNER JOIN {$wpdb->posts} AS ord ON ord.ID = pm.post_id AND ord.post_type = 'shop_order'
				WHERE pm.meta_key = '{$key}'";
			$sql .= self::sql_date_bounds_clause( 'ord.post_date_gmt', $bounds );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * @param array{from:?string,to:?string} $bounds Order date (GMT).
	 * @return int Rows deleted.
	 */
	protected static function delete_order_meta_shipping( array $bounds ) {
		global $wpdb;

		$key = esc_sql( WC_Inventory_Overview_Order_Shipping_Admin::META_KEY );

		if ( self::is_hpos_orders() ) {
			$orders_table = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name();
			$meta_table   = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_meta_table_name();
			$sql          = "DELETE om FROM {$meta_table} AS om
				INNER JOIN {$orders_table} AS ord ON ord.id = om.order_id AND ord.type = 'shop_order'
				WHERE om.meta_key = '{$key}'";
			$sql .= self::sql_date_bounds_clause( 'ord.date_created_gmt', $bounds );
		} else {
			$sql = "DELETE pm FROM {$wpdb->postmeta} AS pm
				INNER JOIN {$wpdb->posts} AS ord ON ord.ID = pm.post_id AND ord.post_type = 'shop_order'
				WHERE pm.meta_key = '{$key}'";
			$sql .= self::sql_date_bounds_clause( 'ord.post_date_gmt', $bounds );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query( $sql );
	}

	/**
	 * All simple/variation product rows with costing meta (date scope not applied).
	 */
	protected static function count_product_cost_meta() {
		global $wpdb;

		$keys    = array( WC_Inventory_Overview_Costing::META_AVG, WC_Inventory_Overview_Costing::META_VAL );
		$in_keys = "'" . implode( "','", array_map( 'esc_sql', $keys ) ) . "'";

		$sql = "SELECT COUNT(*) FROM {$wpdb->postmeta} AS pm
			INNER JOIN {$wpdb->posts} AS p ON p.ID = pm.post_id
			WHERE p.post_type IN ('product','product_variation')
			AND p.post_status NOT IN ('trash','auto-draft')
			AND pm.meta_key IN ({$in_keys})";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * @return int Rows deleted.
	 */
	protected static function delete_product_cost_meta() {
		global $wpdb;

		$keys    = array( WC_Inventory_Overview_Costing::META_AVG, WC_Inventory_Overview_Costing::META_VAL );
		$in_keys = "'" . implode( "','", array_map( 'esc_sql', $keys ) ) . "'";

		$sql = "DELETE pm FROM {$wpdb->postmeta} AS pm
			INNER JOIN {$wpdb->posts} AS p ON p.ID = pm.post_id
			WHERE p.post_type IN ('product','product_variation')
			AND p.post_status NOT IN ('trash','auto-draft')
			AND pm.meta_key IN ({$in_keys})";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query( $sql );
	}

	/**
	 * @param string $token Preview token.
	 * @param array<string, mixed> $payload Payload.
	 * @param array<string, int> $counts Counts.
	 */
	public static function store_preview( $token, array $payload, array $counts ) {
		$token = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $token );
		if ( strlen( $token ) < 20 ) {
			return false;
		}
		return set_transient(
			self::TRANSIENT_PREVIEW_PREFIX . $token,
			array(
				'user_id' => get_current_user_id(),
				'payload' => $payload,
				'counts'  => $counts,
				'ts'      => time(),
			),
			self::PREVIEW_TTL
		);
	}

	/**
	 * @param string $token Token.
	 * @return array{payload: array<string, mixed>, counts: array<string, int>}|null
	 */
	public static function get_preview( $token ) {
		$token = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $token );
		if ( strlen( $token ) < 20 ) {
			return null;
		}
		$data = get_transient( self::TRANSIENT_PREVIEW_PREFIX . $token );
		if ( ! is_array( $data ) || empty( $data['payload'] ) || ! isset( $data['counts'] ) ) {
			return null;
		}
		if ( (int) ( $data['user_id'] ?? 0 ) !== (int) get_current_user_id() ) {
			return null;
		}
		return array(
			'payload' => $data['payload'],
			'counts'  => $data['counts'],
		);
	}

	/**
	 * @param string $token Token.
	 */
	public static function delete_preview( $token ) {
		$token = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $token );
		if ( strlen( $token ) >= 20 ) {
			delete_transient( self::TRANSIENT_PREVIEW_PREFIX . $token );
		}
	}

	/**
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $summary Summary for notice.
	 */
	public static function store_result_notice( $user_id, array $summary ) {
		set_transient(
			self::TRANSIENT_RESULT_PREFIX . (int) $user_id,
			$summary,
			120
		);
	}

	/**
	 * @param int $user_id User ID.
	 * @return array<string, mixed>|null
	 */
	public static function consume_result_notice( $user_id ) {
		$key  = self::TRANSIENT_RESULT_PREFIX . (int) $user_id;
		$data = get_transient( $key );
		delete_transient( $key );
		return is_array( $data ) ? $data : null;
	}
}
