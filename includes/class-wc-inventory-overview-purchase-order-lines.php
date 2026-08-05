<?php
/**
 * Purchase Order line repository (M2-A architecture skeleton).
 *
 * No qty_received column. Outstanding = ordered − cancelled (M2 form of INV-4).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Purchase Order lines repository.
 */
class WC_Inventory_Overview_Purchase_Order_Lines {

	/**
	 * Prefixed table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_io_purchase_order_lines';
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
			return new WP_Error( 'wc_io_po_line_not_found', sprintf( 'Purchase order line %d not found', $id ) );
		}
		return $row;
	}

	/**
	 * List lines for a PO, ordered by line_index.
	 *
	 * @param int $po_id PO id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_for_po( int $po_id ): array {
		global $wpdb;
		$table = self::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE po_id = %d ORDER BY line_index ASC, id ASC", $po_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Insert a line.
	 *
	 * @param int                 $po_id PO id.
	 * @param array<string,mixed> $data  Line fields.
	 * @return int|WP_Error
	 */
	public static function create( int $po_id, array $data ) {
		global $wpdb;

		$po = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		if ( is_wp_error( $po ) ) {
			return $po;
		}
		if ( WC_Inventory_Overview_PO_Statuses::is_terminal( $po['status'] ) ) {
			return new WP_Error( 'wc_io_po_terminal', 'Cannot add lines to a terminal purchase order' );
		}
		$editable = WC_Inventory_Overview_PO_Lifecycle::assert_editable( $po['status'] );
		if ( is_wp_error( $editable ) ) {
			return $editable;
		}

		$now = current_time( 'mysql', true );
		$row = array(
			'po_id'               => $po_id,
			'line_index'          => isset( $data['line_index'] ) ? absint( $data['line_index'] ) : self::next_line_index( $po_id ),
			'product_id'          => isset( $data['product_id'] ) ? absint( $data['product_id'] ) : 0,
			'variation_id'        => isset( $data['variation_id'] ) ? absint( $data['variation_id'] ) : 0,
			'sku_snapshot'        => isset( $data['sku_snapshot'] ) ? sanitize_text_field( (string) $data['sku_snapshot'] ) : null,
			'name_snapshot'       => isset( $data['name_snapshot'] ) ? sanitize_text_field( (string) $data['name_snapshot'] ) : null,
			'supplier_sku'        => isset( $data['supplier_sku'] ) ? sanitize_text_field( (string) $data['supplier_sku'] ) : null,
			'qty_ordered'         => isset( $data['qty_ordered'] ) ? (float) $data['qty_ordered'] : 0,
			'qty_cancelled'       => isset( $data['qty_cancelled'] ) ? (float) $data['qty_cancelled'] : 0,
			'unit_cost'           => isset( $data['unit_cost'] ) ? (float) $data['unit_cost'] : 0,
			'currency'            => isset( $data['currency'] ) ? strtoupper( sanitize_text_field( (string) $data['currency'] ) ) : (string) $po['currency'],
			'expected_date'       => isset( $data['expected_date'] ) && '' !== $data['expected_date'] ? sanitize_text_field( (string) $data['expected_date'] ) : null,
			'expected_confidence' => isset( $data['expected_confidence'] ) && '' !== $data['expected_confidence'] ? sanitize_key( (string) $data['expected_confidence'] ) : null,
			'status'              => isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : 'open',
			'note'                => isset( $data['note'] ) ? sanitize_textarea_field( (string) $data['note'] ) : null,
			'created_at'          => $now,
			'updated_at'          => $now,
		);

		$formats = array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		$inserted = $wpdb->insert( self::table_name(), $row, $formats );
		if ( false === $inserted ) {
			return new WP_Error( 'wc_io_po_line_insert_failed', 'Failed to insert purchase order line', array( 'db_error' => $wpdb->last_error ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete all lines for a PO (used by draft hard-delete).
	 *
	 * @param int $po_id PO id.
	 */
	public static function delete_for_po( int $po_id ): void {
		global $wpdb;
		$wpdb->delete( self::table_name(), array( 'po_id' => $po_id ), array( '%d' ) );
	}

	/**
	 * Outstanding for a line row (M2 INV-4 reduction).
	 *
	 * @param array<string,mixed> $line Line row.
	 */
	public static function outstanding( array $line ): float {
		return WC_Inventory_Overview_Purchase_Orders::qty_outstanding(
			$line['qty_ordered'] ?? 0,
			$line['qty_cancelled'] ?? 0
		);
	}

	/**
	 * Next line_index for a PO (max existing + 1, or 0).
	 *
	 * @param int $po_id PO id.
	 */
	private static function next_line_index( int $po_id ): int {
		global $wpdb;
		$table = self::table_name();
		$max   = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(line_index) FROM {$table} WHERE po_id = %d", $po_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null === $max ? 0 : ( (int) $max + 1 );
	}
}
