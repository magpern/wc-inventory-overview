<?php
/**
 * Stock Adjustment header repository (M27).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stock Adjustment header repository.
 */
class WC_Inventory_Overview_Stock_Adjustments {

	/**
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_io_stock_adjustments';
	}

	/**
	 * @param int $id Adjustment id.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get( int $id ) {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return new WP_Error( 'wc_io_sa_not_found', sprintf( 'Stock Adjustment %d not found', $id ) );
		}
		return $row;
	}

	/**
	 * @param array{status?:string,search?:string,orderby?:string,order?:string,per_page?:int,offset?:int} $args Args.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list( array $args = array() ): array {
		global $wpdb;
		$table = self::table_name();

		list( $where, $params ) = self::build_where( $args );

		$orderby  = isset( $args['orderby'] ) ? sanitize_key( $args['orderby'] ) : 'created_at';
		$allowed  = array( 'id', 'adjustment_number', 'status', 'created_at', 'updated_at', 'posted_at' );
		$orderby  = in_array( $orderby, $allowed, true ) ? $orderby : 'created_at';
		$order    = isset( $args['order'] ) ? strtoupper( sanitize_key( $args['order'] ) ) : 'DESC';
		$order    = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';
		$per_page = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 20;
		$offset   = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

		$sql      = "SELECT sa.* FROM {$table} sa WHERE {$where} ORDER BY sa.{$orderby} {$order} LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return is_array( $results ) ? $results : array();
	}

	/**
	 * @param array{status?:string,search?:string} $args Args.
	 */
	public static function count( array $args = array() ): int {
		global $wpdb;
		$table                  = self::table_name();
		list( $where, $params ) = self::build_where( $args );
		$sql                    = "SELECT COUNT(*) FROM {$table} sa WHERE {$where}";
		if ( empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return absint( $wpdb->get_var( $sql ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return absint( $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) );
	}

	/**
	 * @param array<string,mixed> $data Header fields.
	 * @return int|WP_Error
	 */
	public static function create_draft( array $data ) {
		global $wpdb;

		$adjustment_number = WC_Inventory_Overview_Stock_Adjustment_Numbering::allocate();
		if ( is_wp_error( $adjustment_number ) ) {
			return $adjustment_number;
		}

		$now     = current_time( 'mysql', true );
		$user_id = get_current_user_id();

		$kind = isset( $data['adjustment_kind'] ) ? sanitize_key( (string) $data['adjustment_kind'] ) : WC_Inventory_Overview_Stock_Adjustment_Lifecycle::KIND_PERSONAL_USE;
		if ( WC_Inventory_Overview_Stock_Adjustment_Lifecycle::KIND_PERSONAL_USE !== $kind ) {
			$kind = WC_Inventory_Overview_Stock_Adjustment_Lifecycle::KIND_PERSONAL_USE;
		}

		$row = array(
			'adjustment_number' => $adjustment_number,
			'adjustment_kind'   => $kind,
			'status'            => WC_Inventory_Overview_Stock_Adjustment_Lifecycle::STATUS_DRAFT,
			'note'              => isset( $data['note'] ) ? sanitize_textarea_field( (string) $data['note'] ) : '',
			'created_by'        => $user_id,
			'updated_by'        => $user_id,
			'created_at'        => $now,
			'updated_at'        => $now,
		);

		$formats = array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' );

		$inserted = $wpdb->insert( self::table_name(), $row, $formats );
		if ( false === $inserted ) {
			return new WP_Error( 'wc_io_sa_insert_failed', 'Failed to create Stock Adjustment', array( 'db_error' => $wpdb->last_error ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int                 $id   Adjustment id.
	 * @param array<string,mixed> $data Fields.
	 * @return true|WP_Error
	 */
	public static function update_draft( int $id, array $data ) {
		global $wpdb;

		$existing = self::get( $id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$now = current_time( 'mysql', true );

		$update = array(
			'updated_by' => get_current_user_id(),
			'updated_at' => $now,
		);
		$formats = array( '%d', '%s' );

		if ( array_key_exists( 'note', $data ) ) {
			$update['note'] = sanitize_textarea_field( (string) $data['note'] );
			$formats[]      = '%s';
		}

		$result = $wpdb->update( self::table_name(), $update, array( 'id' => $id ), $formats, array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'wc_io_sa_update_failed', 'Failed to update Stock Adjustment', array( 'db_error' => $wpdb->last_error ) );
		}

		return true;
	}

	/**
	 * @param int $id      Adjustment id.
	 * @param int $user_id User id.
	 * @return int Rows affected.
	 */
	public static function compare_and_swap_post( int $id, int $user_id ): int {
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, posted_at = %s, posted_by = %d, updated_at = %s, updated_by = %d WHERE id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				WC_Inventory_Overview_Stock_Adjustment_Lifecycle::STATUS_POSTED,
				$now,
				$user_id,
				$now,
				$user_id,
				$id,
				WC_Inventory_Overview_Stock_Adjustment_Lifecycle::STATUS_DRAFT
			)
		);
		return (int) $wpdb->rows_affected;
	}

	/**
	 * @param int    $id          Adjustment id.
	 * @param int    $user_id     User id.
	 * @param string $void_reason Reason.
	 * @return int Rows affected.
	 */
	public static function compare_and_swap_void( int $id, int $user_id, string $void_reason ): int {
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, voided_at = %s, voided_by = %d, void_reason = %s, updated_at = %s, updated_by = %d WHERE id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				WC_Inventory_Overview_Stock_Adjustment_Lifecycle::STATUS_VOIDED,
				$now,
				$user_id,
				sanitize_textarea_field( $void_reason ),
				$now,
				$user_id,
				$id,
				WC_Inventory_Overview_Stock_Adjustment_Lifecycle::STATUS_POSTED
			)
		);
		return (int) $wpdb->rows_affected;
	}

	/**
	 * @param int $id Adjustment id.
	 * @return true|WP_Error
	 */
	public static function delete_draft_record( int $id ) {
		global $wpdb;
		$deleted = $wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );
		if ( false === $deleted ) {
			return new WP_Error( 'wc_io_sa_delete_failed', 'Failed to delete Stock Adjustment' );
		}
		return true;
	}

	/**
	 * @param array{status?:string,search?:string} $args Args.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private static function build_where( array $args ): array {
		global $wpdb;
		$where  = '1=1';
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND sa.status = %s';
			$params[] = sanitize_key( (string) $args['status'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( sanitize_text_field( (string) $args['search'] ) ) . '%';
			$where   .= ' AND ( sa.adjustment_number LIKE %s OR sa.note LIKE %s )';
			$params[] = $like;
			$params[] = $like;
		}

		return array( $where, $params );
	}
}
