<?php
/**
 * Supplier_Merges: append-only audit repository for supplier merge operations (M17).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Supplier merge audit repository. Mirrors the pattern of PO_Events.
 *
 * Public mutation surface: `add()` only. All writes are append-only.
 */
class WC_Inventory_Overview_Supplier_Merges {

	/**
	 * Get table name (prefixed), matching every other repository class in
	 * this codebase (Suppliers::table_name(), Purchase_Orders::table_name(),
	 * Goods_Receipts::table_name() all return the prefixed name).
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_io_supplier_merges';
	}

	/**
	 * Add a new merge audit record.
	 *
	 * Must be called inside an active database transaction (caller responsibility).
	 *
	 * @param array $data {
	 *     Merge audit record data.
	 *     @type int    $source_supplier_id           Source supplier ID.
	 *     @type string $source_supplier_name_snapshot Source supplier name snapshot.
	 *     @type int    $target_supplier_id           Target supplier ID.
	 *     @type string $target_supplier_name_snapshot Target supplier name snapshot.
	 *     @type int    $purchase_orders_reassigned   Count of reassigned purchase orders.
	 *     @type int    $goods_receipts_reassigned    Count of reassigned goods receipts.
	 *     @type int    $performed_by                 User ID who performed the merge.
	 * }
	 *
	 * @return int|WP_Error new row ID, or WP_Error on failure.
	 */
	public static function add( array $data ) {
		global $wpdb;

		$required = array(
			'source_supplier_id',
			'source_supplier_name_snapshot',
			'target_supplier_id',
			'target_supplier_name_snapshot',
			'purchase_orders_reassigned',
			'goods_receipts_reassigned',
			'performed_by',
		);

		foreach ( $required as $key ) {
			if ( ! isset( $data[ $key ] ) ) {
				return new WP_Error(
					'wc_io_supplier_merge_missing_field',
					"Missing required field: $key"
				);
			}
		}

		$result = $wpdb->insert(
			self::table_name(),
			array(
				'source_supplier_id'            => (int) $data['source_supplier_id'],
				'source_supplier_name_snapshot' => (string) $data['source_supplier_name_snapshot'],
				'target_supplier_id'            => (int) $data['target_supplier_id'],
				'target_supplier_name_snapshot' => (string) $data['target_supplier_name_snapshot'],
				'purchase_orders_reassigned'    => (int) $data['purchase_orders_reassigned'],
				'goods_receipts_reassigned'     => (int) $data['goods_receipts_reassigned'],
				'performed_by'                  => (int) $data['performed_by'],
			),
			array( '%d', '%s', '%d', '%s', '%d', '%d', '%d' )
		);

		if ( false === $result ) {
			return new WP_Error(
				'wc_io_supplier_merge_audit_insert_failed',
				'Failed to record supplier merge audit entry',
				array( 'db_error' => $wpdb->last_error )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Retrieve a single merge audit record by ID.
	 *
	 * @param int $id Merge audit record ID.
	 *
	 * @return array|null Record array, or null if not found.
	 */
	public static function get( int $id ) {
		global $wpdb;
		$table = self::table_name();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Retrieve all merge audit records for a given source supplier ID.
	 *
	 * @param int $source_id Source supplier ID.
	 *
	 * @return array List of merge audit records (may be empty).
	 */
	public static function get_for_source( int $source_id ) {
		global $wpdb;
		$table = self::table_name();

		$results = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE source_supplier_id = %d ORDER BY created_at DESC", $source_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}
}
