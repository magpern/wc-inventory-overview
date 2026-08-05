<?php
/**
 * Purchase Order header repository (M2-C).
 *
 * Persistence only. Lifecycle transitions belong to WC_Inventory_Overview_PO_Service.
 * Never touches WooCommerce stock (INV-2).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Purchase Order header repository.
 */
class WC_Inventory_Overview_Purchase_Orders {

	/**
	 * Prefixed table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_io_purchase_orders';
	}

	/**
	 * Get a PO by ID.
	 *
	 * @param int $id PO id.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get( int $id ) {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return new WP_Error( 'wc_io_po_not_found', sprintf( 'Purchase order %d not found', $id ) );
		}
		return $row;
	}

	/**
	 * Get a PO by number.
	 *
	 * @param string $po_number PO number.
	 * @return array<string,mixed>|null
	 */
	public static function get_by_number( string $po_number ) {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE po_number = %s", $po_number ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * List purchase orders.
	 *
	 * @param array{status?:string,supplier_id?:int,search?:string,delayed?:bool,orderby?:string,order?:string,per_page?:int,offset?:int} $args Args.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list( array $args = array() ): array {
		global $wpdb;
		$table = self::table_name();

		list( $where, $params ) = self::build_where( $args );

		$orderby  = isset( $args['orderby'] ) ? sanitize_key( $args['orderby'] ) : 'created_at';
		$allowed  = array( 'id', 'po_number', 'status', 'expected_date', 'created_at', 'updated_at', 'supplier_name_snapshot', 'order_date', 'currency' );
		$orderby  = in_array( $orderby, $allowed, true ) ? $orderby : 'created_at';
		$order    = isset( $args['order'] ) ? strtoupper( sanitize_key( $args['order'] ) ) : 'DESC';
		$order    = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';
		$per_page = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 20;
		$offset   = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

		$sql      = "SELECT po.* FROM {$table} po WHERE {$where} ORDER BY po.{$orderby} {$order} LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Count purchase orders.
	 *
	 * @param array{status?:string,supplier_id?:int,search?:string,delayed?:bool} $args Args.
	 */
	public static function count( array $args = array() ): int {
		global $wpdb;
		$table                  = self::table_name();
		list( $where, $params ) = self::build_where( $args );
		$sql                    = "SELECT COUNT(*) FROM {$table} po WHERE {$where}";
		if ( empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return absint( $wpdb->get_var( $sql ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return absint( $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) );
	}

	/**
	 * Sum of ordered line totals (qty_ordered × unit_cost) for a PO.
	 *
	 * @param int $po_id PO id.
	 */
	public static function line_total( int $po_id ): float {
		global $wpdb;
		$lines = WC_Inventory_Overview_Purchase_Order_Lines::table_name();
		$sum   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( qty_ordered * unit_cost ), 0 ) FROM {$lines} WHERE po_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$po_id
			)
		);
		return (float) $sum;
	}

	/**
	 * Insert a draft purchase order header.
	 *
	 * Allocates a PO number. Does not write events (service layer responsibility in M2-C).
	 *
	 * @param array<string,mixed> $data Header fields.
	 * @return int|WP_Error New id or error.
	 */
	public static function create_draft( array $data ) {
		global $wpdb;

		$po_number = WC_Inventory_Overview_PO_Numbering::allocate();
		if ( is_wp_error( $po_number ) ) {
			return $po_number;
		}

		$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : WC_Inventory_Overview_PO_Statuses::DRAFT;
		if ( WC_Inventory_Overview_PO_Statuses::DRAFT !== $status ) {
			return new WP_Error( 'wc_io_po_create_status', 'create_draft may only create draft status rows' );
		}
		if ( ! WC_Inventory_Overview_PO_Statuses::is_valid( $status ) ) {
			return new WP_Error( 'wc_io_po_invalid_status', 'Invalid PO status' );
		}

		$now     = current_time( 'mysql', true );
		$user_id = get_current_user_id();

		$row = array(
			'po_number'              => $po_number,
			'supplier_id'            => isset( $data['supplier_id'] ) ? absint( $data['supplier_id'] ) : 0,
			'supplier_name_snapshot' => isset( $data['supplier_name_snapshot'] ) ? sanitize_text_field( (string) $data['supplier_name_snapshot'] ) : '',
			'currency'               => isset( $data['currency'] ) ? strtoupper( sanitize_text_field( (string) $data['currency'] ) ) : 'EUR',
			'status'                 => WC_Inventory_Overview_PO_Statuses::DRAFT,
			'order_date'             => isset( $data['order_date'] ) ? self::nullable_date( $data['order_date'] ) : null,
			'expected_date'          => isset( $data['expected_date'] ) ? self::nullable_date( $data['expected_date'] ) : null,
			'expected_confidence'    => isset( $data['expected_confidence'] ) ? sanitize_key( (string) $data['expected_confidence'] ) : 'unknown',
			'supplier_reference'     => isset( $data['supplier_reference'] ) ? sanitize_text_field( (string) $data['supplier_reference'] ) : null,
			'note'                   => isset( $data['note'] ) ? sanitize_textarea_field( (string) $data['note'] ) : null,
			'placed_at'              => null,
			'closed_at'              => null,
			'created_by'             => $user_id,
			'updated_by'             => $user_id,
			'created_at'             => $now,
			'updated_at'             => $now,
		);

		$formats = array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' );

		$inserted = $wpdb->insert( self::table_name(), $row, $formats );
		if ( false === $inserted ) {
			return new WP_Error( 'wc_io_po_insert_failed', 'Failed to insert purchase order', array( 'db_error' => $wpdb->last_error ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get a PO by ID with a row lock (SELECT ... FOR UPDATE). Must be called inside a transaction.
	 *
	 * @param int $id PO id.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_for_update( int $id ) {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d FOR UPDATE", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( ! $row ) {
			return new WP_Error( 'wc_io_po_not_found', sprintf( 'Purchase order %d not found', $id ) );
		}
		return $row;
	}

	/**
	 * Persist arbitrary allowed columns. No lifecycle policy — caller (PO_Service) enforces rules.
	 *
	 * @param int                 $id   PO id.
	 * @param array<string,mixed> $data Fields to update.
	 * @return true|WP_Error
	 */
	public static function update_fields( int $id, array $data ) {
		global $wpdb;

		$allowed = array(
			'supplier_id',
			'supplier_name_snapshot',
			'currency',
			'status',
			'order_date',
			'expected_date',
			'expected_confidence',
			'supplier_reference',
			'note',
			'placed_at',
			'closed_at',
			'updated_by',
			'updated_at',
		);

		$update  = array();
		$formats = array();
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			switch ( $key ) {
				case 'supplier_id':
				case 'updated_by':
					$update[ $key ] = absint( $data[ $key ] );
					$formats[]      = '%d';
					break;
				case 'order_date':
				case 'expected_date':
				case 'placed_at':
				case 'closed_at':
					$update[ $key ] = self::nullable_date( $data[ $key ] );
					$formats[]      = '%s';
					break;
				case 'note':
					$update[ $key ] = null === $data[ $key ] ? null : sanitize_textarea_field( (string) $data[ $key ] );
					$formats[]      = '%s';
					break;
				case 'currency':
					$update[ $key ] = strtoupper( sanitize_text_field( (string) $data[ $key ] ) );
					$formats[]      = '%s';
					break;
				case 'status':
					$update[ $key ] = sanitize_key( (string) $data[ $key ] );
					$formats[]      = '%s';
					break;
				default:
					$update[ $key ] = null === $data[ $key ] ? null : sanitize_text_field( (string) $data[ $key ] );
					$formats[]      = '%s';
					break;
			}
		}

		if ( empty( $update ) ) {
			return true;
		}

		if ( ! isset( $update['updated_at'] ) ) {
			$update['updated_at'] = current_time( 'mysql', true );
			$formats[]            = '%s';
		}
		if ( ! isset( $update['updated_by'] ) ) {
			$update['updated_by'] = get_current_user_id();
			$formats[]            = '%d';
		}

		$result = $wpdb->update( self::table_name(), $update, array( 'id' => $id ), $formats, array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'wc_io_po_update_failed', 'Failed to update purchase order', array( 'db_error' => $wpdb->last_error ) );
		}

		return true;
	}

	/**
	 * Alias for create_draft — header insert only.
	 *
	 * @param array<string,mixed> $data Header fields.
	 * @return int|WP_Error
	 */
	public static function create( array $data ) {
		return self::create_draft( $data );
	}

	/**
	 * Delete a draft header row only (lines/events must already be removed by the service).
	 *
	 * @param int $id PO id.
	 * @return true|WP_Error
	 */
	public static function delete_draft_record( int $id ) {
		global $wpdb;
		$deleted = $wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );
		if ( false === $deleted ) {
			return new WP_Error( 'wc_io_po_delete_failed', 'Failed to delete purchase order' );
		}
		return true;
	}

	/**
	 * Persist mutable header fields (no lifecycle policy — PO_Service enforces).
	 *
	 * @param int                 $id   PO id.
	 * @param array<string,mixed> $data Fields to update.
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		return self::update_fields( $id, $data );
	}

	/**
	 * Hard-delete a draft header and its lines. Prefer PO_Service::delete_draft() for event cleanup.
	 *
	 * @param int $id PO id.
	 * @return true|WP_Error
	 */
	public static function delete_draft( int $id ) {
		$existing = self::get( $id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( WC_Inventory_Overview_PO_Statuses::DRAFT !== $existing['status'] ) {
			return new WP_Error( 'wc_io_po_delete_not_draft', 'Only draft purchase orders may be hard-deleted' );
		}

		WC_Inventory_Overview_Purchase_Order_Lines::delete_for_po( $id );
		return self::delete_draft_record( $id );
	}

	/**
	 * Alias for get_by_number().
	 *
	 * @param string $po_number PO number.
	 * @return array<string,mixed>|null
	 */
	public static function find_by_number( string $po_number ) {
		return self::get_by_number( $po_number );
	}

	/**
	 * Outstanding quantity helper (M2 form of INV-4: ordered − cancelled).
	 *
	 * @param float|string $qty_ordered   Ordered.
	 * @param float|string $qty_cancelled Cancelled.
	 */
	public static function qty_outstanding( $qty_ordered, $qty_cancelled ): float {
		return WC_Inventory_Overview_PO_Quantities::outstanding( $qty_ordered, $qty_cancelled );
	}

	/**
	 * Build a WHERE clause and prepare params for list/count.
	 *
	 * @param array<string,mixed> $args Args.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private static function build_where( array $args ): array {
		global $wpdb;

		$where  = '1=1';
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND po.status = %s';
			$params[] = sanitize_key( (string) $args['status'] );
		}
		if ( ! empty( $args['supplier_id'] ) ) {
			$where   .= ' AND po.supplier_id = %d';
			$params[] = absint( $args['supplier_id'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( sanitize_text_field( (string) $args['search'] ) ) . '%';
			$where   .= ' AND ( po.po_number LIKE %s OR po.supplier_name_snapshot LIKE %s OR po.supplier_reference LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $args['delayed'] ) ) {
			$grace  = WC_Inventory_Overview_PO_Delay::grace_days_from_option();
			$where .= ' AND ' . WC_Inventory_Overview_PO_Delay::sql_po_is_delayed_exists( 'po', $grace );
		}

		return array( $where, $params );
	}

	/**
	 * Normalize an optional date field to a MySQL date string or null.
	 *
	 * @param mixed $value Date value.
	 * @return string|null
	 */
	private static function nullable_date( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$value = sanitize_text_field( (string) $value );
		return $value;
	}
}
