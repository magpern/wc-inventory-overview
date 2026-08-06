<?php
/**
 * Goods Receipt line repository (M4).
 *
 * Persistence only — no lifecycle policy, no stock/cost mutation. po_line_id is
 * always NULL in M4: this repository's create()/update() signatures accept no
 * po_line_id parameter at all (structural guarantee, not a runtime check).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Goods Receipt lines repository.
 */
class WC_Inventory_Overview_Receipt_Lines {

	/**
	 * Prefixed table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_io_receipt_lines';
	}

	/**
	 * Get a line by ID.
	 *
	 * @param int $id Line id.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get( int $id ) {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return new WP_Error( 'wc_io_gr_line_not_found', sprintf( 'Goods Receipt line %d not found', $id ) );
		}
		return $row;
	}

	/**
	 * List lines for a receipt, ordered by line_index (posting/voiding order).
	 *
	 * @param int $receipt_id Receipt id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_for_receipt( int $receipt_id ): array {
		global $wpdb;
		$table = self::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE receipt_id = %d ORDER BY line_index ASC, id ASC", $receipt_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Insert a draft line. po_line_id is always persisted as NULL in M4 — no
	 * parameter accepts one. Caller (Goods_Receipt_Service) enforces draft-only
	 * editability.
	 *
	 * @param int                 $receipt_id Receipt id.
	 * @param array<string,mixed> $data       Line fields.
	 * @return int|WP_Error
	 */
	public static function create( int $receipt_id, array $data ) {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$row = array(
			'receipt_id'              => $receipt_id,
			'line_index'              => isset( $data['line_index'] ) ? absint( $data['line_index'] ) : self::next_line_index( $receipt_id ),
			'po_line_id'              => null,
			'product_id'              => isset( $data['product_id'] ) ? absint( $data['product_id'] ) : 0,
			'variation_id'            => isset( $data['variation_id'] ) ? absint( $data['variation_id'] ) : 0,
			'sku_snapshot'            => isset( $data['sku_snapshot'] ) ? sanitize_text_field( (string) $data['sku_snapshot'] ) : null,
			'name_snapshot'           => isset( $data['name_snapshot'] ) ? sanitize_text_field( (string) $data['name_snapshot'] ) : null,
			'qty'                     => wc_format_decimal( $data['qty'] ?? 0, 4 ),
			'entered_currency'        => isset( $data['entered_currency'] ) ? strtoupper( sanitize_text_field( (string) $data['entered_currency'] ) ) : 'EUR',
			'exchange_rate_to_eur'    => wc_format_decimal( $data['exchange_rate_to_eur'] ?? 1, 8 ),
			'entered_unit_cost'       => wc_format_decimal( $data['entered_unit_cost'] ?? 0, 6 ),
			'converted_unit_cost_eur' => wc_format_decimal( $data['converted_unit_cost_eur'] ?? 0, 6 ),
			'base_line_cost'          => wc_format_decimal( $data['base_line_cost'] ?? 0, 4 ),
			'allocated_landed_cost'   => wc_format_decimal( $data['allocated_landed_cost'] ?? 0, 4 ),
			'true_line_cost'          => wc_format_decimal( $data['true_line_cost'] ?? 0, 4 ),
			'true_unit_cost'          => wc_format_decimal( $data['true_unit_cost'] ?? 0, 6 ),
			'created_at'              => $now,
		);

		$formats = array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		$inserted = $wpdb->insert( self::table_name(), $row, $formats );
		if ( false === $inserted ) {
			return new WP_Error( 'wc_io_gr_line_insert_failed', 'Failed to insert Goods Receipt line', array( 'db_error' => $wpdb->last_error ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a draft line's editable fields. No lifecycle policy — caller
	 * (Goods_Receipt_Service) enforces draft-only editability. po_line_id is never
	 * accepted here.
	 *
	 * @param int                 $id   Line id.
	 * @param array<string,mixed> $data Fields to update.
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$existing = self::get( $id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$allowed = array(
			'line_index',
			'product_id',
			'variation_id',
			'sku_snapshot',
			'name_snapshot',
			'qty',
			'entered_currency',
			'exchange_rate_to_eur',
			'entered_unit_cost',
			'converted_unit_cost_eur',
			'base_line_cost',
			'allocated_landed_cost',
			'true_line_cost',
			'true_unit_cost',
		);

		$update  = array();
		$formats = array();
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			switch ( $key ) {
				case 'line_index':
				case 'product_id':
				case 'variation_id':
					$update[ $key ] = absint( $data[ $key ] );
					$formats[]      = '%d';
					break;
				case 'qty':
					$update[ $key ] = wc_format_decimal( $data[ $key ], 4 );
					$formats[]      = '%s';
					break;
				case 'exchange_rate_to_eur':
					$update[ $key ] = wc_format_decimal( $data[ $key ], 8 );
					$formats[]      = '%s';
					break;
				case 'entered_unit_cost':
				case 'converted_unit_cost_eur':
				case 'true_unit_cost':
					$update[ $key ] = wc_format_decimal( $data[ $key ], 6 );
					$formats[]      = '%s';
					break;
				case 'base_line_cost':
				case 'allocated_landed_cost':
				case 'true_line_cost':
					$update[ $key ] = wc_format_decimal( $data[ $key ], 4 );
					$formats[]      = '%s';
					break;
				case 'entered_currency':
					$update[ $key ] = strtoupper( sanitize_text_field( (string) $data[ $key ] ) );
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

		$result = $wpdb->update( self::table_name(), $update, array( 'id' => $id ), $formats, array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'wc_io_gr_line_update_failed', 'Failed to update Goods Receipt line', array( 'db_error' => $wpdb->last_error ) );
		}

		return true;
	}

	/**
	 * Persist the posting-time stock/cost snapshot for a line. Only ever called
	 * from Goods_Receipt_Service::post() inside its transaction — never from a
	 * draft edit path.
	 *
	 * @param int                 $id     Line id.
	 * @param array<string,mixed> $result Keys: old_stock,new_stock,old_average_unit_cost,new_average_unit_cost,old_inventory_value,new_inventory_value.
	 * @return true|WP_Error
	 */
	public static function persist_posting_snapshot( int $id, array $result ) {
		global $wpdb;

		$old_avg = ( null === $result['old_average_unit_cost'] || '' === $result['old_average_unit_cost'] )
			? null
			: wc_format_decimal( $result['old_average_unit_cost'], 6 );

		$update  = array(
			'old_stock'             => wc_format_decimal( $result['old_stock'], 4 ),
			'new_stock'             => wc_format_decimal( $result['new_stock'], 4 ),
			'new_average_unit_cost' => wc_format_decimal( $result['new_average_unit_cost'], 6 ),
			'old_inventory_value'   => wc_format_decimal( $result['old_inventory_value'], 4 ),
			'new_inventory_value'   => wc_format_decimal( $result['new_inventory_value'], 4 ),
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s' );

		if ( null !== $old_avg ) {
			$update['old_average_unit_cost'] = $old_avg;
			$formats[]                       = '%s';
		}

		$result_db = $wpdb->update( self::table_name(), $update, array( 'id' => $id ), $formats, array( '%d' ) );
		if ( false === $result_db ) {
			return new WP_Error( 'wc_io_gr_line_snapshot_failed', 'Failed to persist Goods Receipt line snapshot', array( 'db_error' => $wpdb->last_error ) );
		}

		return true;
	}

	/**
	 * Delete a single line by id (draft-only; caller enforces).
	 *
	 * @param int $id Line id.
	 * @return true|WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;
		$existing = self::get( $id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		$deleted = $wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );
		if ( false === $deleted ) {
			return new WP_Error( 'wc_io_gr_line_delete_failed', 'Failed to delete Goods Receipt line' );
		}
		return true;
	}

	/**
	 * Delete all lines for a receipt (used by draft hard-delete).
	 *
	 * @param int $receipt_id Receipt id.
	 */
	public static function delete_for_receipt( int $receipt_id ): void {
		global $wpdb;
		$wpdb->delete( self::table_name(), array( 'receipt_id' => $receipt_id ), array( '%d' ) );
	}

	/**
	 * Resequence line_index to 0..n-1 in current id order for a receipt.
	 *
	 * @param int $receipt_id Receipt id.
	 * @return true|WP_Error
	 */
	public static function resequence( int $receipt_id ) {
		$lines = self::list_for_receipt( $receipt_id );
		$index = 0;
		foreach ( $lines as $line ) {
			if ( (int) $line['line_index'] !== $index ) {
				$updated = self::update( (int) $line['id'], array( 'line_index' => $index ) );
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
			}
			++$index;
		}
		return true;
	}

	/**
	 * Next line_index for a receipt (max existing + 1, or 0).
	 *
	 * @param int $receipt_id Receipt id.
	 */
	public static function next_line_index( int $receipt_id ): int {
		global $wpdb;
		$table = self::table_name();
		$max   = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(line_index) FROM {$table} WHERE receipt_id = %d", $receipt_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null === $max ? 0 : ( (int) $max + 1 );
	}
}
