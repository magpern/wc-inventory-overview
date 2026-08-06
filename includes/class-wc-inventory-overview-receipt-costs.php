<?php
/**
 * Goods Receipt landed cost row repository (M4).
 *
 * Persistence only. Mirrors wc_io_purchase_batch_costs shape plus a post_hoc flag
 * (always 0 in M4 — no post-hoc landed cost support is built this milestone).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Goods Receipt cost rows repository.
 */
class WC_Inventory_Overview_Receipt_Costs {

	/**
	 * Prefixed table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_io_receipt_costs';
	}

	/**
	 * List cost rows for a receipt.
	 *
	 * @param int $receipt_id Receipt id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_for_receipt( int $receipt_id ): array {
		global $wpdb;
		$table = self::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE receipt_id = %d ORDER BY id ASC", $receipt_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Insert a landed cost row. post_hoc always persists as 0 in M4.
	 *
	 * @param int                 $receipt_id Receipt id.
	 * @param array<string,mixed> $data       Cost row fields.
	 * @return int|WP_Error
	 */
	public static function create( int $receipt_id, array $data ) {
		global $wpdb;

		$row = array(
			'receipt_id'           => $receipt_id,
			'cost_type'            => isset( $data['cost_type'] ) ? sanitize_key( (string) $data['cost_type'] ) : '',
			'entered_currency'     => isset( $data['entered_currency'] ) ? strtoupper( sanitize_text_field( (string) $data['entered_currency'] ) ) : 'EUR',
			'exchange_rate_to_eur' => wc_format_decimal( $data['exchange_rate_to_eur'] ?? 1, 8 ),
			'entered_amount'       => wc_format_decimal( $data['entered_amount'] ?? 0, 4 ),
			'converted_amount_eur' => wc_format_decimal( $data['converted_amount_eur'] ?? 0, 4 ),
			'amount'               => wc_format_decimal( $data['amount'] ?? 0, 4 ),
			'note'                 => isset( $data['note'] ) ? sanitize_text_field( (string) $data['note'] ) : null,
			'post_hoc'             => 0,
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );

		$inserted = $wpdb->insert( self::table_name(), $row, $formats );
		if ( false === $inserted ) {
			return new WP_Error( 'wc_io_gr_cost_insert_failed', 'Failed to insert Goods Receipt landed cost row', array( 'db_error' => $wpdb->last_error ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete all cost rows for a receipt (draft edit replace-all, or draft hard-delete).
	 *
	 * @param int $receipt_id Receipt id.
	 */
	public static function delete_for_receipt( int $receipt_id ): void {
		global $wpdb;
		$wpdb->delete( self::table_name(), array( 'receipt_id' => $receipt_id ), array( '%d' ) );
	}
}
