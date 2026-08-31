<?php
/**
 * Stock Adjustment line repository (M27).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stock Adjustment lines repository.
 */
class WC_Inventory_Overview_Stock_Adjustment_Lines {

	/**
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_io_stock_adjustment_lines';
	}

	/**
	 * @param int $id Line id.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get( int $id ) {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return new WP_Error( 'wc_io_sa_line_not_found', sprintf( 'Stock Adjustment line %d not found', $id ) );
		}
		return $row;
	}

	/**
	 * @param int $adjustment_id Adjustment id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_for_adjustment( int $adjustment_id ): array {
		global $wpdb;
		$table = self::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE adjustment_id = %d ORDER BY line_index ASC, id ASC", $adjustment_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param int $adjustment_id Adjustment id.
	 */
	public static function count_for_adjustment( int $adjustment_id ): int {
		global $wpdb;
		$table = self::table_name();
		return absint(
			$wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE adjustment_id = %d", $adjustment_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);
	}

	/**
	 * @param int                 $adjustment_id Adjustment id.
	 * @param array<string,mixed> $data          Line fields.
	 * @return int|WP_Error
	 */
	public static function create( int $adjustment_id, array $data ) {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$row = array(
			'adjustment_id'  => $adjustment_id,
			'line_index'     => isset( $data['line_index'] ) ? absint( $data['line_index'] ) : self::next_line_index( $adjustment_id ),
			'product_id'     => isset( $data['product_id'] ) ? absint( $data['product_id'] ) : 0,
			'variation_id'   => isset( $data['variation_id'] ) ? absint( $data['variation_id'] ) : 0,
			'sku_snapshot'   => isset( $data['sku_snapshot'] ) ? sanitize_text_field( (string) $data['sku_snapshot'] ) : null,
			'name_snapshot'  => isset( $data['name_snapshot'] ) ? sanitize_text_field( (string) $data['name_snapshot'] ) : null,
			'qty'            => wc_format_decimal( $data['qty'] ?? 0, 4 ),
			'created_at'     => $now,
		);

		$formats = array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' );

		$inserted = $wpdb->insert( self::table_name(), $row, $formats );
		if ( false === $inserted ) {
			return new WP_Error( 'wc_io_sa_line_insert_failed', 'Failed to insert Stock Adjustment line', array( 'db_error' => $wpdb->last_error ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int                 $id   Line id.
	 * @param array<string,mixed> $data Fields.
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$existing = self::get( $id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$allowed = array( 'qty', 'sku_snapshot', 'name_snapshot', 'product_id', 'variation_id', 'line_index' );
		$update  = array();
		$formats = array();

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			if ( 'qty' === $key ) {
				$update[ $key ] = wc_format_decimal( $data[ $key ], 4 );
				$formats[]    = '%s';
			} elseif ( in_array( $key, array( 'product_id', 'variation_id', 'line_index' ), true ) ) {
				$update[ $key ] = absint( $data[ $key ] );
				$formats[]    = '%d';
			} else {
				$update[ $key ] = sanitize_text_field( (string) $data[ $key ] );
				$formats[]    = '%s';
			}
		}

		if ( empty( $update ) ) {
			return true;
		}

		$result = $wpdb->update( self::table_name(), $update, array( 'id' => $id ), $formats, array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'wc_io_sa_line_update_failed', 'Failed to update Stock Adjustment line', array( 'db_error' => $wpdb->last_error ) );
		}

		return true;
	}

	/**
	 * @param int                 $id     Line id.
	 * @param array<string,mixed> $snap   Posting snapshot fields.
	 * @return true|WP_Error
	 */
	public static function persist_posting_snapshot( int $id, array $snap ) {
		global $wpdb;

		$update = array(
			'unit_cost_at_post'       => wc_format_decimal( $snap['unit_cost_at_post'] ?? 0, 6 ),
			'value_removed'           => wc_format_decimal( $snap['value_removed'] ?? 0, 4 ),
			'old_stock'               => wc_format_decimal( $snap['old_stock'] ?? 0, 4 ),
			'new_stock'               => wc_format_decimal( $snap['new_stock'] ?? 0, 4 ),
			'old_average_unit_cost'   => isset( $snap['old_average_unit_cost'] ) && null !== $snap['old_average_unit_cost'] && '' !== $snap['old_average_unit_cost']
				? wc_format_decimal( $snap['old_average_unit_cost'], 6 )
				: null,
			'new_average_unit_cost'   => wc_format_decimal( $snap['new_average_unit_cost'] ?? 0, 6 ),
			'old_inventory_value'     => wc_format_decimal( $snap['old_inventory_value'] ?? 0, 4 ),
			'new_inventory_value'     => wc_format_decimal( $snap['new_inventory_value'] ?? 0, 4 ),
		);

		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		$result = $wpdb->update( self::table_name(), $update, array( 'id' => $id ), $formats, array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'wc_io_sa_line_snapshot_failed', 'Failed to persist Stock Adjustment line snapshot', array( 'db_error' => $wpdb->last_error ) );
		}

		return true;
	}

	/**
	 * @param int $adjustment_id Adjustment id.
	 * @return true|WP_Error
	 */
	public static function delete_all_for_adjustment( int $adjustment_id ) {
		global $wpdb;
		$deleted = $wpdb->delete( self::table_name(), array( 'adjustment_id' => $adjustment_id ), array( '%d' ) );
		if ( false === $deleted ) {
			return new WP_Error( 'wc_io_sa_lines_delete_failed', 'Failed to delete Stock Adjustment lines' );
		}
		return true;
	}

	/**
	 * @param int $adjustment_id Adjustment id.
	 */
	private static function next_line_index( int $adjustment_id ): int {
		global $wpdb;
		$table = self::table_name();
		$max   = $wpdb->get_var(
			$wpdb->prepare( "SELECT MAX(line_index) FROM {$table} WHERE adjustment_id = %d", $adjustment_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return null === $max ? 0 : ( (int) $max + 1 );
	}

	/**
	 * Purchasable item post id for a line row.
	 *
	 * @param array<string,mixed> $line Line row.
	 */
	public static function item_post_id( array $line ): int {
		return (int) $line['variation_id'] > 0 ? (int) $line['variation_id'] : (int) $line['product_id'];
	}
}
