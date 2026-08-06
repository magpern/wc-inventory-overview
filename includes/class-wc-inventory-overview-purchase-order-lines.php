<?php
/**
 * Purchase Order line repository (M2-C, extended M5).
 *
 * Persistence only — no lifecycle policy.
 * Outstanding = ordered − received − cancelled (full INV-4 formula, M5).
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
	 * Get a line by ID with a row lock. Must be called inside a transaction.
	 *
	 * @param int $id Line id.
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
	 * Alias for list_for_po().
	 *
	 * @param int $po_id PO id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_by_po( int $po_id ): array {
		return self::list_for_po( $po_id );
	}

	/**
	 * Insert a line. Caller (PO_Service) enforces lifecycle and validation.
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
	 * Update a line. No lifecycle policy — PO_Service enforces.
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
			'supplier_sku',
			'qty_ordered',
			'qty_cancelled',
			'unit_cost',
			'currency',
			'expected_date',
			'expected_confidence',
			'status',
			'note',
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
				case 'qty_ordered':
				case 'qty_cancelled':
				case 'unit_cost':
					$update[ $key ] = (float) $data[ $key ];
					$formats[]      = '%f';
					break;
				case 'expected_date':
					$update[ $key ] = ( null === $data[ $key ] || '' === $data[ $key ] ) ? null : sanitize_text_field( (string) $data[ $key ] );
					$formats[]      = '%s';
					break;
				case 'expected_confidence':
					$update[ $key ] = ( null === $data[ $key ] || '' === $data[ $key ] ) ? null : sanitize_key( (string) $data[ $key ] );
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
				default:
					$update[ $key ] = null === $data[ $key ] ? null : sanitize_text_field( (string) $data[ $key ] );
					$formats[]      = '%s';
					break;
			}
		}

		if ( empty( $update ) ) {
			return true;
		}

		$update['updated_at'] = current_time( 'mysql', true );
		$formats[]            = '%s';

		$result = $wpdb->update( self::table_name(), $update, array( 'id' => $id ), $formats, array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'wc_io_po_line_update_failed', 'Failed to update purchase order line', array( 'db_error' => $wpdb->last_error ) );
		}

		return true;
	}

	/**
	 * Delete a single line by id.
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
			return new WP_Error( 'wc_io_po_line_delete_failed', 'Failed to delete purchase order line' );
		}
		return true;
	}

	/**
	 * Resequence line_index to 0..n-1 in current id order for a PO.
	 *
	 * @param int $po_id PO id.
	 * @return true|WP_Error
	 */
	public static function resequence( int $po_id ) {
		$lines = self::list_for_po( $po_id );
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
	 * Delete all lines for a PO (used by draft hard-delete).
	 *
	 * @param int $po_id PO id.
	 */
	public static function delete_for_po( int $po_id ): void {
		global $wpdb;
		$wpdb->delete( self::table_name(), array( 'po_id' => $po_id ), array( '%d' ) );
	}

	/**
	 * Outstanding for a line row (full INV-4 formula, M5).
	 *
	 * @param array<string,mixed> $line Line row.
	 */
	public static function outstanding( array $line ): float {
		return WC_Inventory_Overview_Purchase_Orders::qty_outstanding(
			$line['qty_ordered'] ?? 0,
			$line['qty_received'] ?? 0,
			$line['qty_cancelled'] ?? 0
		);
	}

	/**
	 * Increment (or, with a negative delta, decrement) qty_received by an exact amount.
	 *
	 * Sole physical writer of this column anywhere in the codebase (architecture-guard
	 * enforced, M5) — called only by WC_Inventory_Overview_PO_Receiving_Sync. A plain
	 * atomic `SET qty_received = qty_received + %f`, not a read-then-write in PHP, so it
	 * is safe under concurrent transactions without row locking (M5 plan §Idempotency).
	 *
	 * @param int   $line_id Line id.
	 * @param float $delta   Signed delta; positive on receipt post, negative on void.
	 * @return int Rows affected (0 or 1).
	 */
	public static function increment_qty_received( int $line_id, float $delta ): int {
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is prepared immediately below via $wpdb->prepare().
			$wpdb->prepare(
				"UPDATE {$table} SET qty_received = qty_received + %f, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$delta,
				$now,
				$line_id
			)
		);
		return (int) $wpdb->rows_affected;
	}

	/**
	 * Next line_index for a PO (max existing + 1, or 0).
	 *
	 * @param int $po_id PO id.
	 */
	public static function next_line_index( int $po_id ): int {
		global $wpdb;
		$table = self::table_name();
		$max   = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(line_index) FROM {$table} WHERE po_id = %d", $po_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null === $max ? 0 : ( (int) $max + 1 );
	}

	/**
	 * List open PO lines for simple products (M3 — Inventory Position).
	 *
	 * "Open" qualification is PO header status IN (placed, partially_received,
	 * received) — the PO-line status column is not consulted (M3 plan §6). A
	 * `received` PO is included for completeness though its lines' outstanding
	 * is always 0 and the HAVING clause below excludes them regardless.
	 * Outstanding uses the full INV-4 formula, GREATEST(0, qty_ordered -
	 * qty_received - qty_cancelled) as of M5 (schema v9). Lines are never aggregated in
	 * SQL: each row is an independent line so the caller (the Inventory
	 * Position Service) can retain per-line identity for drill-down
	 * (INV-1, INV-7).
	 *
	 * Deliberately a separate query from list_open_lines_for_variation_ids()
	 * rather than one OR-based query (M3 plan §6).
	 *
	 * @param int[] $product_ids Product IDs (variation_id = 0 rows only).
	 * @return array<int,array<string,mixed>> Line rows: line_id, po_id, po_number,
	 *         product_id, variation_id, outstanding, expected_date,
	 *         expected_confidence, is_delayed.
	 */
	public static function list_open_lines_for_product_ids( array $product_ids ): array {
		$product_ids = self::normalize_ids( $product_ids );
		if ( empty( $product_ids ) ) {
			return array();
		}
		return self::query_open_lines(
			'pol.variation_id = 0 AND pol.product_id IN (' . self::placeholders( $product_ids ) . ')',
			$product_ids
		);
	}

	/**
	 * List open PO lines for variations (M3 — Inventory Position).
	 *
	 * See list_open_lines_for_product_ids() for qualification rules; this
	 * is its variation-scoped counterpart and shares the same query shape.
	 *
	 * @param int[] $variation_ids Variation IDs.
	 * @return array<int,array<string,mixed>> Line rows, same shape as
	 *         list_open_lines_for_product_ids().
	 */
	public static function list_open_lines_for_variation_ids( array $variation_ids ): array {
		$variation_ids = self::normalize_ids( $variation_ids );
		if ( empty( $variation_ids ) ) {
			return array();
		}
		return self::query_open_lines(
			'pol.variation_id IN (' . self::placeholders( $variation_ids ) . ')',
			$variation_ids
		);
	}

	/**
	 * Shared open-line SELECT for the two M3 bulk methods above.
	 *
	 * @param string $qualifier_sql Additional WHERE fragment (product_id/variation_id IN (...)) with %d placeholders.
	 * @param int[]  $ids           IDs to bind into the qualifier's placeholders, in order.
	 * @return array<int,array<string,mixed>>
	 */
	private static function query_open_lines( string $qualifier_sql, array $ids ): array {
		global $wpdb;

		$lines  = self::table_name();
		$orders = WC_Inventory_Overview_Purchase_Orders::table_name();

		$delayed_predicate = WC_Inventory_Overview_PO_Delay::sql_line_delayed_predicate(
			WC_Inventory_Overview_PO_Delay::grace_days_from_option()
		);

		$outstanding_expr   = 'GREATEST(0, pol.qty_ordered - pol.qty_received - pol.qty_cancelled)';
		$expected_date_expr = "COALESCE(NULLIF(pol.expected_date, '0000-00-00'), NULLIF(po.expected_date, '0000-00-00'))";
		$expected_conf_expr = "COALESCE(NULLIF(pol.expected_confidence, ''), NULLIF(po.expected_confidence, ''), 'unknown')";

		$sql = "SELECT
				pol.id AS line_id,
				pol.po_id AS po_id,
				po.po_number AS po_number,
				pol.product_id AS product_id,
				pol.variation_id AS variation_id,
				{$outstanding_expr} AS outstanding,
				{$expected_date_expr} AS expected_date,
				{$expected_conf_expr} AS expected_confidence,
				({$delayed_predicate}) AS is_delayed
			FROM {$lines} pol
			INNER JOIN {$orders} po ON po.id = pol.po_id
			WHERE po.status IN ('placed', 'partially_received', 'received')
				AND {$qualifier_sql}
			HAVING outstanding > 0
			ORDER BY pol.po_id ASC, pol.line_index ASC, pol.id ASC";

		$prepared = $wpdb->prepare( $sql, ...$ids ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built entirely from %d placeholders, no interpolated values.
		$rows     = $wpdb->get_results( $prepared, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $prepared is the $wpdb->prepare() result from the line above.

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Sanitize a list of IDs to positive unique integers.
	 *
	 * @param array $ids Raw IDs.
	 * @return int[]
	 */
	private static function normalize_ids( array $ids ): array {
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Build a comma-separated list of %d placeholders, one per ID.
	 *
	 * @param array $ids IDs (only the count is used).
	 */
	private static function placeholders( array $ids ): string {
		return implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	}
}
