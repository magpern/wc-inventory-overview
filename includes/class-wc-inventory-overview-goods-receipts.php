<?php
/**
 * Goods Receipt header repository (M4, extended M5).
 *
 * Persistence only. Lifecycle transitions and inventory mutation belong to
 * WC_Inventory_Overview_Goods_Receipt_Service. Never touches WooCommerce stock (INV-2).
 * `source` ('direct'|'po'|'mixed') is derived by Goods_Receipt_Service from a
 * draft's line composition (M5) — never operator-chosen.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Goods Receipt header repository.
 */
class WC_Inventory_Overview_Goods_Receipts {

	const SOURCE_DIRECT = 'direct';
	const SOURCE_PO     = 'po';
	const SOURCE_MIXED  = 'mixed';

	/**
	 * Historical record materialization from a legacy Batch Intake row (M6) —
	 * never a live receiving event. See the M6 implementation plan's Migration
	 * model section: a receipt with this source was never posted through
	 * Goods_Receipt_Service::post() and must never be voided through
	 * Goods_Receipt_Service::void() (that path assumes current-state-relative
	 * reversal, which does not apply to historical replay rows).
	 */
	const SOURCE_MIGRATED = 'migrated';

	/**
	 * Prefixed table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_io_goods_receipts';
	}

	/**
	 * Get a receipt by ID.
	 *
	 * @param int $id Receipt id.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get( int $id ) {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return new WP_Error( 'wc_io_gr_not_found', sprintf( 'Goods Receipt %d not found', $id ) );
		}
		return $row;
	}

	/**
	 * List goods receipts.
	 *
	 * @param array{status?:string,supplier_id?:int,search?:string,orderby?:string,order?:string,per_page?:int,offset?:int} $args Args.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list( array $args = array() ): array {
		global $wpdb;
		$table = self::table_name();

		list( $where, $params ) = self::build_where( $args );

		$orderby  = isset( $args['orderby'] ) ? sanitize_key( $args['orderby'] ) : 'created_at';
		$allowed  = array( 'id', 'receipt_number', 'status', 'created_at', 'updated_at', 'supplier_name_snapshot', 'posted_at' );
		$orderby  = in_array( $orderby, $allowed, true ) ? $orderby : 'created_at';
		$order    = isset( $args['order'] ) ? strtoupper( sanitize_key( $args['order'] ) ) : 'DESC';
		$order    = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';
		$per_page = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 20;
		$offset   = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

		$sql      = "SELECT gr.* FROM {$table} gr WHERE {$where} ORDER BY gr.{$orderby} {$order} LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Count goods receipts.
	 *
	 * @param array{status?:string,supplier_id?:int,search?:string} $args Args.
	 */
	public static function count( array $args = array() ): int {
		global $wpdb;
		$table                  = self::table_name();
		list( $where, $params ) = self::build_where( $args );
		$sql                    = "SELECT COUNT(*) FROM {$table} gr WHERE {$where}";
		if ( empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return absint( $wpdb->get_var( $sql ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return absint( $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) );
	}

	/**
	 * Insert a draft Goods Receipt header. Allocates a receipt number.
	 *
	 * @param array<string,mixed> $data Header fields.
	 * @return int|WP_Error New id or error.
	 */
	public static function create_draft( array $data ) {
		global $wpdb;

		$receipt_number = WC_Inventory_Overview_Goods_Receipt_Numbering::allocate();
		if ( is_wp_error( $receipt_number ) ) {
			return $receipt_number;
		}

		$now     = current_time( 'mysql', true );
		$user_id = get_current_user_id();

		$source = isset( $data['source'] ) ? sanitize_key( (string) $data['source'] ) : self::SOURCE_DIRECT;
		if ( ! in_array( $source, array( self::SOURCE_DIRECT, self::SOURCE_PO, self::SOURCE_MIXED ), true ) ) {
			$source = self::SOURCE_DIRECT;
		}

		$row = array(
			'receipt_number'           => $receipt_number,
			'status'                   => WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_DRAFT,
			'source'                   => $source,
			'supplier_id'              => isset( $data['supplier_id'] ) && (int) $data['supplier_id'] > 0 ? absint( $data['supplier_id'] ) : null,
			'supplier_name_snapshot'   => isset( $data['supplier_name_snapshot'] ) ? sanitize_text_field( (string) $data['supplier_name_snapshot'] ) : null,
			'currency'                 => isset( $data['currency'] ) ? strtoupper( sanitize_text_field( (string) $data['currency'] ) ) : 'EUR',
			'exchange_rate_to_eur'     => isset( $data['exchange_rate_to_eur'] ) ? wc_format_decimal( $data['exchange_rate_to_eur'], 8 ) : '1',
			'exchange_rate_date'       => isset( $data['exchange_rate_date'] ) ? self::nullable_date( $data['exchange_rate_date'] ) : null,
			'product_subtotal_entered' => wc_format_decimal( $data['product_subtotal_entered'] ?? 0, 4 ),
			'landed_total_entered'     => wc_format_decimal( $data['landed_total_entered'] ?? 0, 4 ),
			'receipt_total_entered'    => wc_format_decimal( $data['receipt_total_entered'] ?? 0, 4 ),
			'product_subtotal'         => wc_format_decimal( $data['product_subtotal'] ?? 0, 4 ),
			'landed_total'             => wc_format_decimal( $data['landed_total'] ?? 0, 4 ),
			'receipt_total'            => wc_format_decimal( $data['receipt_total'] ?? 0, 4 ),
			'reference'                => isset( $data['reference'] ) ? sanitize_text_field( (string) $data['reference'] ) : null,
			'note'                     => isset( $data['note'] ) ? sanitize_textarea_field( (string) $data['note'] ) : null,
			'created_by'               => $user_id,
			'updated_by'               => $user_id,
			'created_at'               => $now,
			'updated_at'               => $now,
		);

		$formats = array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' );

		$inserted = $wpdb->insert( self::table_name(), $row, $formats );
		if ( false === $inserted ) {
			return new WP_Error( 'wc_io_gr_insert_failed', 'Failed to insert Goods Receipt', array( 'db_error' => $wpdb->last_error ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Persist mutable header fields. Draft-editability is enforced by the caller
	 * (Goods_Receipt_Service), never here.
	 *
	 * @param int                 $id   Receipt id.
	 * @param array<string,mixed> $data Fields to update.
	 * @return true|WP_Error
	 */
	public static function update_fields( int $id, array $data ) {
		global $wpdb;

		$allowed = array(
			'source',
			'supplier_id',
			'supplier_name_snapshot',
			'currency',
			'exchange_rate_to_eur',
			'exchange_rate_date',
			'product_subtotal_entered',
			'landed_total_entered',
			'receipt_total_entered',
			'product_subtotal',
			'landed_total',
			'receipt_total',
			'reference',
			'note',
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
					$update[ $key ] = null === $data[ $key ] || 0 === (int) $data[ $key ] ? null : absint( $data[ $key ] );
					$formats[]      = '%d';
					break;
				case 'exchange_rate_date':
					$update[ $key ] = self::nullable_date( $data[ $key ] );
					$formats[]      = '%s';
					break;
				case 'exchange_rate_to_eur':
					$update[ $key ] = wc_format_decimal( $data[ $key ], 8 );
					$formats[]      = '%s';
					break;
				case 'product_subtotal_entered':
				case 'landed_total_entered':
				case 'receipt_total_entered':
				case 'product_subtotal':
				case 'landed_total':
				case 'receipt_total':
					$update[ $key ] = wc_format_decimal( $data[ $key ], 4 );
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
				case 'source':
					$source         = sanitize_key( (string) $data[ $key ] );
					$update[ $key ] = in_array( $source, array( self::SOURCE_DIRECT, self::SOURCE_PO, self::SOURCE_MIXED ), true ) ? $source : self::SOURCE_DIRECT;
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
			return new WP_Error( 'wc_io_gr_update_failed', 'Failed to update Goods Receipt', array( 'db_error' => $wpdb->last_error ) );
		}

		return true;
	}

	/**
	 * Compare-and-swap the header status from draft to posted (first write of post()).
	 *
	 * @param int $id      Receipt id.
	 * @param int $user_id Posting user id.
	 * @return int Rows affected (0 or 1).
	 */
	public static function compare_and_swap_post( int $id, int $user_id ): int {
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, posted_at = %s, posted_by = %d, updated_at = %s, updated_by = %d WHERE id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_POSTED,
				$now,
				$user_id,
				$now,
				$user_id,
				$id,
				WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_DRAFT
			)
		);
		return (int) $wpdb->rows_affected;
	}

	/**
	 * Compare-and-swap the header status from posted to voided (first write of void()).
	 *
	 * @param int    $id          Receipt id.
	 * @param int    $user_id     Voiding user id.
	 * @param string $void_reason Free-text reason.
	 * @return int Rows affected (0 or 1).
	 */
	public static function compare_and_swap_void( int $id, int $user_id, string $void_reason ): int {
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, voided_at = %s, voided_by = %d, void_reason = %s, updated_at = %s, updated_by = %d WHERE id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_VOIDED,
				$now,
				$user_id,
				sanitize_textarea_field( $void_reason ),
				$now,
				$user_id,
				$id,
				WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_POSTED
			)
		);
		return (int) $wpdb->rows_affected;
	}

	/**
	 * Hard-delete a draft header row only (lines must already be removed by the caller).
	 *
	 * @param int $id Receipt id.
	 * @return true|WP_Error
	 */
	public static function delete_draft_record( int $id ) {
		global $wpdb;
		$deleted = $wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );
		if ( false === $deleted ) {
			return new WP_Error( 'wc_io_gr_delete_failed', 'Failed to delete Goods Receipt' );
		}
		return true;
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
			$where   .= ' AND gr.status = %s';
			$params[] = sanitize_key( (string) $args['status'] );
		}
		if ( ! empty( $args['supplier_id'] ) ) {
			$where   .= ' AND gr.supplier_id = %d';
			$params[] = absint( $args['supplier_id'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( sanitize_text_field( (string) $args['search'] ) ) . '%';
			$where   .= ' AND ( gr.receipt_number LIKE %s OR gr.supplier_name_snapshot LIKE %s OR gr.reference LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
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
		return sanitize_text_field( (string) $value );
	}
}
