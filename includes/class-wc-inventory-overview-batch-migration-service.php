<?php
/**
 * Legacy Batch Intake -> Goods Receipt migration engine (M6).
 *
 * Central decision (see the M6 implementation plan's Migration model):
 * migration is historical record MATERIALIZATION, not receiving. A migrated
 * Goods Receipt is a historical fact being written down in today's schema —
 * the stock/cost mutation it describes already happened, once, when the
 * original batch was applied, and is already fully reflected in current
 * WooCommerce stock and cost. This class therefore NEVER calls
 * set_stock_quantity(), never writes _wc_io_average_unit_cost or
 * _wc_io_inventory_value product meta, and never goes through
 * WC_Inventory_Overview_Goods_Receipt_Service::post() or any
 * WC_Inventory_Overview_Restock_Service mutation method — doing so would
 * apply the same historical stock/cost change a second time. Every write in
 * this class is a direct, transactional INSERT/UPDATE using the source
 * batch's own already-stored before/after snapshot values.
 *
 * Invariant M6-1 (one transaction per batch, never one for a whole run):
 * migrate_batch()/rollback_batch() each open exactly one
 * WC_Inventory_Overview_DB_Transaction::run() call for exactly one batch.
 * Callers (the CLI command) that process many batches must call these
 * methods once per batch in a loop — never wrap multiple batches in a
 * shared transaction.
 *
 * Invariant M6-2 (order independence): migrate_batch() never reads current
 * stock, current cost, or any other batch's rows — each batch's migration is
 * a pure function of that one batch's own already-stored rows. Batches may
 * therefore be migrated in any order with an identical result.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Batch -> Goods Receipt migration engine.
 */
class WC_Inventory_Overview_Batch_Migration_Service {

	/**
	 * Migrate one legacy batch into a posted, source='migrated' Goods Receipt.
	 * Idempotent: refuses a batch that already carries a migrated_receipt_id.
	 *
	 * @param int $batch_id Legacy batch id.
	 * @return array{batch_id:int,receipt_id:int,receipt_number:string,lines_migrated:int,costs_migrated:int,movements_backfilled:int}|WP_Error
	 */
	public static function migrate_batch( int $batch_id ) {
		$batch = self::get_batch_row( $batch_id );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}

		if ( self::already_migrated( $batch ) ) {
			return new WP_Error(
				'wc_io_batch_migration_already_migrated',
				sprintf( 'Batch #%d was already migrated to Goods Receipt #%d.', $batch_id, (int) $batch['migrated_receipt_id'] )
			);
		}

		$lines = self::get_batch_lines( $batch_id );
		if ( empty( $lines ) ) {
			return new WP_Error( 'wc_io_batch_migration_no_lines', sprintf( 'Batch #%d has no lines to migrate.', $batch_id ) );
		}
		$costs = self::get_batch_costs( $batch_id );

		global $wpdb;
		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );

		try {
			$result = $txn->run(
				function () use ( $batch, $lines, $costs ) {
					return self::materialize_batch( $batch, $lines, $costs );
				}
			);
		} catch ( WC_Inventory_Overview_Batch_Migration_Exception $e ) {
			return $e->get_wp_error();
		}

		return $result;
	}

	/**
	 * Read-only drift check between a migrated batch's source rows and the
	 * Goods Receipt it was materialized into. Never repairs — the permanent
	 * reconciliation tool for this data (M6 plan §Required analysis, point 8).
	 *
	 * @param int $batch_id Legacy batch id.
	 * @return array{batch_id:int,receipt_id:int,ok:bool,drift:array<int,string>}|WP_Error
	 */
	public static function verify_batch( int $batch_id ) {
		$batch = self::get_batch_row( $batch_id );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}

		if ( ! self::already_migrated( $batch ) ) {
			return new WP_Error( 'wc_io_batch_migration_not_migrated', sprintf( 'Batch #%d has not been migrated.', $batch_id ) );
		}

		$receipt_id = (int) $batch['migrated_receipt_id'];
		$drift      = array();

		$receipt = WC_Inventory_Overview_Goods_Receipts::get( $receipt_id );
		if ( is_wp_error( $receipt ) ) {
			return array(
				'batch_id'   => $batch_id,
				'receipt_id' => $receipt_id,
				'ok'         => false,
				'drift'      => array( sprintf( 'Migrated Goods Receipt #%d no longer exists.', $receipt_id ) ),
			);
		}

		if ( WC_Inventory_Overview_Goods_Receipts::SOURCE_MIGRATED !== $receipt['source'] ) {
			$drift[] = sprintf( 'source: expected "migrated", actual "%s"', (string) $receipt['source'] );
		}
		if ( WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_POSTED !== $receipt['status'] ) {
			$drift[] = sprintf( 'status: expected "posted", actual "%s"', (string) $receipt['status'] );
		}

		$expected_header   = self::map_header( $batch );
		$comparable_fields = array(
			'supplier_name_snapshot',
			'currency',
			'product_subtotal_entered',
			'landed_total_entered',
			'receipt_total_entered',
			'product_subtotal',
			'landed_total',
			'receipt_total',
			'reference',
		);
		foreach ( $comparable_fields as $field ) {
			$expected = (string) ( $expected_header[ $field ] ?? '' );
			$actual   = (string) ( $receipt[ $field ] ?? '' );
			if ( $expected !== $actual ) {
				$drift[] = sprintf( '%s: expected "%s", actual "%s"', $field, $expected, $actual );
			}
		}

		$batch_lines   = self::get_batch_lines( $batch_id );
		$receipt_lines = WC_Inventory_Overview_Receipt_Lines::list_for_receipt( $receipt_id );
		if ( count( $batch_lines ) !== count( $receipt_lines ) ) {
			$drift[] = sprintf( 'line count: expected %d, actual %d', count( $batch_lines ), count( $receipt_lines ) );
		}

		$expected_true_total = 0.0;
		foreach ( $batch_lines as $line ) {
			$expected_true_total += (float) $line['true_line_cost'];
		}
		$actual_true_total = 0.0;
		foreach ( $receipt_lines as $line ) {
			$actual_true_total += (float) $line['true_line_cost'];
		}
		if ( 0.0 !== round( $expected_true_total - $actual_true_total, 4 ) ) {
			$drift[] = sprintf(
				'line true_line_cost total: expected %s, actual %s',
				wc_format_decimal( $expected_true_total, 4 ),
				wc_format_decimal( $actual_true_total, 4 )
			);
		}

		$movement_ids = self::find_batch_movement_ids( $batch_id );
		if ( ! empty( $movement_ids ) ) {
			global $wpdb;
			$table = WC_Inventory_Overview_Movements::table_name();
			$in    = implode( ',', array_fill( 0, count( $movement_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is built entirely from %d placeholders (one per $movement_ids element); $table is a fixed, non-user-supplied table name.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, reference_id FROM {$table} WHERE id IN ({$in})", $movement_ids ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				if ( (int) $row['reference_id'] !== $receipt_id ) {
					$drift[] = sprintf( 'movement #%d reference_id: expected %d, actual %d', (int) $row['id'], $receipt_id, (int) $row['reference_id'] );
				}
			}
		}

		return array(
			'batch_id'   => $batch_id,
			'receipt_id' => $receipt_id,
			'ok'         => empty( $drift ),
			'drift'      => $drift,
		);
	}

	/**
	 * Undo one batch's migration: deletes the migrated receipt/lines/costs,
	 * reverts its movement rows' reference_type/reference_id to NULL, and
	 * clears the batch's tracking columns. Never touches current stock/cost —
	 * symmetric with forward migration, not a Goods Receipt void (see the M6
	 * plan's Migration model — "Rollback of a migration is not a receipt void").
	 *
	 * @param int $batch_id Legacy batch id.
	 * @return true|WP_Error
	 */
	public static function rollback_batch( int $batch_id ) {
		$batch = self::get_batch_row( $batch_id );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}

		if ( ! self::already_migrated( $batch ) ) {
			return new WP_Error( 'wc_io_batch_migration_not_migrated', sprintf( 'Batch #%d was not migrated; nothing to roll back.', $batch_id ) );
		}

		$receipt_id = (int) $batch['migrated_receipt_id'];

		global $wpdb;
		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );

		try {
			$txn->run(
				function () use ( $batch_id, $receipt_id ) {
					global $wpdb;

					$receipt = WC_Inventory_Overview_Goods_Receipts::get( $receipt_id );
					if ( ! is_wp_error( $receipt ) ) {
						if ( WC_Inventory_Overview_Goods_Receipts::SOURCE_MIGRATED !== $receipt['source'] ) {
							self::throw_if_error(
								new WP_Error(
									'wc_io_batch_migration_rollback_not_migrated_receipt',
									sprintf( 'Goods Receipt #%d is not a migrated receipt; refusing to delete it.', $receipt_id )
								)
							);
						}

						WC_Inventory_Overview_Receipt_Costs::delete_for_receipt( $receipt_id );
						WC_Inventory_Overview_Receipt_Lines::delete_for_receipt( $receipt_id );

						$deleted = $wpdb->delete( WC_Inventory_Overview_Goods_Receipts::table_name(), array( 'id' => $receipt_id ), array( '%d' ) );
						if ( false === $deleted ) {
							self::throw_if_error(
								new WP_Error( 'wc_io_batch_migration_rollback_receipt_delete', sprintf( 'Failed to delete migrated Goods Receipt #%d.', $receipt_id ) )
							);
						}
					}

					foreach ( self::find_batch_movement_ids( $batch_id ) as $movement_id ) {
						$ok = WC_Inventory_Overview_Movements::backfill_reference( $movement_id, null, null );
						if ( ! $ok ) {
							self::throw_if_error(
								new WP_Error( 'wc_io_batch_migration_rollback_movement_clear', sprintf( 'Failed to clear movement reference for batch #%d, movement #%d.', $batch_id, $movement_id ) )
							);
						}
					}

					$cleared = $wpdb->update(
						$wpdb->prefix . 'wc_io_purchase_batches',
						array(
							'migrated_receipt_id' => null,
							'migrated_at'         => null,
						),
						array( 'id' => $batch_id ),
						array( '%d', '%s' ),
						array( '%d' )
					);
					if ( false === $cleared ) {
						self::throw_if_error(
							new WP_Error( 'wc_io_batch_migration_rollback_tracking_clear', sprintf( 'Failed to clear migration tracking columns for batch #%d.', $batch_id ) )
						);
					}
				}
			);
		} catch ( WC_Inventory_Overview_Batch_Migration_Exception $e ) {
			return $e->get_wp_error();
		}

		return true;
	}

	/**
	 * Batch ids with no migrated_receipt_id yet, ascending — both "what's left
	 * to migrate" and the idempotency guard (M6 plan §Migration model).
	 *
	 * @param int $limit          Cap the result count (0 = no cap).
	 * @param int $only_batch_id Restrict to exactly one batch id (0 = no restriction).
	 * @return int[]
	 */
	public static function list_eligible_batch_ids( int $limit = 0, int $only_batch_id = 0 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_io_purchase_batches';

		if ( $only_batch_id > 0 ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND migrated_receipt_id IS NULL", $only_batch_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return array_map( 'intval', (array) $ids );
		}

		$sql = "SELECT id FROM {$table} WHERE migrated_receipt_id IS NULL ORDER BY id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a fixed, non-user-supplied table name.
		if ( $limit > 0 ) {
			$sql = $wpdb->prepare( $sql . ' LIMIT %d', $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the fixed string built immediately above.
		}
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is either the fixed string above or its $wpdb->prepare() result.
	}

	/**
	 * Batch ids already carrying a migrated_receipt_id, ascending.
	 *
	 * @param int $only_batch_id Restrict to exactly one batch id (0 = no restriction).
	 * @return int[]
	 */
	public static function list_migrated_batch_ids( int $only_batch_id = 0 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_io_purchase_batches';

		if ( $only_batch_id > 0 ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND migrated_receipt_id IS NOT NULL", $only_batch_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return array_map( 'intval', (array) $ids );
		}

		$ids = $wpdb->get_col( "SELECT id FROM {$table} WHERE migrated_receipt_id IS NOT NULL ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a fixed, non-user-supplied table name.
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Total legacy batch row count (eligible + already-migrated).
	 */
	public static function count_all_batches(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_io_purchase_batches';
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a fixed, non-user-supplied table name.
	}

	/**
	 * Pure mapping: legacy batch header row -> Goods Receipt header fields
	 * (excluding receipt_number, which requires allocation). No DB access.
	 *
	 * @param array<string,mixed> $batch Legacy wc_io_purchase_batches row.
	 * @return array<string,mixed>
	 */
	public static function map_header( array $batch ): array {
		$batch_id = (int) $batch['id'];
		$user_id  = (int) $batch['user_id'];
		$created  = (string) $batch['created_at'];

		return array(
			'status'                   => WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_POSTED,
			'source'                   => WC_Inventory_Overview_Goods_Receipts::SOURCE_MIGRATED,
			'supplier_id'              => null,
			'supplier_name_snapshot'   => isset( $batch['supplier_name'] ) && '' !== $batch['supplier_name'] ? (string) $batch['supplier_name'] : null,
			'currency'                 => (string) $batch['purchase_currency'],
			'exchange_rate_to_eur'     => wc_format_decimal( $batch['exchange_rate_to_eur'], 8 ),
			'exchange_rate_date'       => ( isset( $batch['exchange_rate_date'] ) && '' !== $batch['exchange_rate_date'] ) ? (string) $batch['exchange_rate_date'] : null,
			'product_subtotal_entered' => wc_format_decimal( $batch['product_subtotal_entered'], 4 ),
			'landed_total_entered'     => wc_format_decimal( $batch['landed_total_entered'], 4 ),
			'receipt_total_entered'    => wc_format_decimal( $batch['batch_total_entered'], 4 ),
			'product_subtotal'         => wc_format_decimal( $batch['product_subtotal'], 4 ),
			'landed_total'             => wc_format_decimal( $batch['landed_total'], 4 ),
			'receipt_total'            => wc_format_decimal( $batch['batch_total'], 4 ),
			'reference'                => sprintf( 'Migrated from legacy Batch #%d', $batch_id ),
			'note'                     => isset( $batch['note'] ) && '' !== $batch['note'] ? (string) $batch['note'] : null,
			'posted_at'                => $created,
			'posted_by'                => $user_id > 0 ? $user_id : null,
			'created_by'               => $user_id,
			'updated_by'               => $user_id,
			'created_at'               => $created,
			'updated_at'               => $created,
		);
	}

	/**
	 * Pure mapping: one legacy batch line -> Goods Receipt line fields.
	 * sku_snapshot/name_snapshot are best-effort, derived from the product's
	 * CURRENT state (wc_get_product()) — never historically captured, so
	 * never authoritative (M6 plan §Migration model).
	 *
	 * @param array<string,mixed> $batch_line Legacy wc_io_purchase_batch_lines row.
	 * @param int                 $line_index Zero-based position for this receipt's line_index.
	 * @return array<string,mixed>
	 */
	public static function map_line( array $batch_line, int $line_index ): array {
		$qty          = (float) $batch_line['quantity'];
		$product_id   = (int) $batch_line['product_id'];
		$variation_id = (int) $batch_line['variation_id'];
		$lookup_id    = $variation_id > 0 ? $variation_id : $product_id;

		$sku_snapshot  = null;
		$name_snapshot = null;
		$product       = $lookup_id > 0 ? wc_get_product( $lookup_id ) : false;
		if ( $product instanceof WC_Product ) {
			$sku_snapshot  = (string) $product->get_sku();
			$name_snapshot = (string) $product->get_name();
		}

		$entered_unit_cost       = $qty > 0 ? ( (float) $batch_line['entered_line_cost'] / $qty ) : 0.0;
		$converted_unit_cost_eur = $qty > 0 ? ( (float) $batch_line['converted_line_cost_eur'] / $qty ) : 0.0;

		$old_avg = ( ! isset( $batch_line['old_average_unit_cost'] ) || null === $batch_line['old_average_unit_cost'] || '' === $batch_line['old_average_unit_cost'] )
			? null
			: wc_format_decimal( $batch_line['old_average_unit_cost'], 6 );

		return array(
			'line_index'              => $line_index,
			'po_line_id'              => null,
			'product_id'              => $product_id,
			'variation_id'            => $variation_id,
			'sku_snapshot'            => $sku_snapshot,
			'name_snapshot'           => $name_snapshot,
			'qty'                     => wc_format_decimal( $qty, 4 ),
			'entered_currency'        => (string) $batch_line['entered_currency'],
			'exchange_rate_to_eur'    => wc_format_decimal( $batch_line['exchange_rate_to_eur'], 8 ),
			'entered_unit_cost'       => wc_format_decimal( $entered_unit_cost, 6 ),
			'converted_unit_cost_eur' => wc_format_decimal( $converted_unit_cost_eur, 6 ),
			'base_line_cost'          => wc_format_decimal( $batch_line['base_line_cost'], 4 ),
			'allocated_landed_cost'   => wc_format_decimal( $batch_line['allocated_landed_cost'], 4 ),
			'true_line_cost'          => wc_format_decimal( $batch_line['true_line_cost'], 4 ),
			'true_unit_cost'          => wc_format_decimal( $batch_line['true_unit_cost'], 6 ),
			'old_stock'               => wc_format_decimal( $batch_line['old_stock'], 4 ),
			'new_stock'               => wc_format_decimal( $batch_line['new_stock'], 4 ),
			'old_average_unit_cost'   => $old_avg,
			'new_average_unit_cost'   => wc_format_decimal( $batch_line['new_average_unit_cost'], 6 ),
			'old_inventory_value'     => wc_format_decimal( $batch_line['old_inventory_value'], 4 ),
			'new_inventory_value'     => wc_format_decimal( $batch_line['new_inventory_value'], 4 ),
		);
	}

	/**
	 * Pure mapping: one legacy batch landed-cost row -> Goods Receipt cost
	 * row fields. post_hoc is always 0 — these were entered at the batch's
	 * own creation time, not added afterward.
	 *
	 * @param array<string,mixed> $batch_cost Legacy wc_io_purchase_batch_costs row.
	 * @return array<string,mixed>
	 */
	public static function map_cost( array $batch_cost ): array {
		return array(
			'cost_type'            => (string) $batch_cost['cost_type'],
			'entered_currency'     => (string) $batch_cost['entered_currency'],
			'exchange_rate_to_eur' => wc_format_decimal( $batch_cost['exchange_rate_to_eur'], 8 ),
			'entered_amount'       => wc_format_decimal( $batch_cost['entered_amount'], 4 ),
			'converted_amount_eur' => wc_format_decimal( $batch_cost['converted_amount_eur'], 4 ),
			'amount'               => wc_format_decimal( $batch_cost['amount'], 4 ),
			'note'                 => isset( $batch_cost['note'] ) && '' !== $batch_cost['note'] ? (string) $batch_cost['note'] : null,
			'post_hoc'             => 0,
		);
	}

	/**
	 * The single write path for one batch's migration — receipt header, lines,
	 * costs, movement backfill, and tracking columns, all inside the caller's
	 * already-open transaction (Invariant M6-1). Never calls
	 * set_stock_quantity(), never writes cost meta, never calls
	 * Goods_Receipt_Service or Restock_Service.
	 *
	 * @param array<string,mixed>            $batch Legacy batch row.
	 * @param array<int,array<string,mixed>> $lines Legacy batch lines, id ASC.
	 * @param array<int,array<string,mixed>> $costs Legacy batch landed-cost rows, id ASC.
	 * @return array{batch_id:int,receipt_id:int,receipt_number:string,lines_migrated:int,costs_migrated:int,movements_backfilled:int}
	 * @throws WC_Inventory_Overview_Batch_Migration_Exception On any failure (bridged from throw_if_error()).
	 */
	private static function materialize_batch( array $batch, array $lines, array $costs ): array {
		global $wpdb;

		$batch_id = (int) $batch['id'];
		$year     = (int) substr( (string) $batch['created_at'], 0, 4 );

		$receipt_number = self::throw_if_error( WC_Inventory_Overview_Goods_Receipt_Numbering::allocate( $year ) );

		$header                   = self::map_header( $batch );
		$header['receipt_number'] = $receipt_number;

		$inserted = $wpdb->insert( WC_Inventory_Overview_Goods_Receipts::table_name(), $header, self::formats_for( $header, self::header_format_map() ) );
		if ( false === $inserted ) {
			self::throw_if_error(
				new WP_Error( 'wc_io_batch_migration_receipt_insert', sprintf( 'Failed to insert migrated Goods Receipt for batch #%d: %s', $batch_id, $wpdb->last_error ) )
			);
		}
		$receipt_id = (int) $wpdb->insert_id;

		$receipt_lines_table = WC_Inventory_Overview_Receipt_Lines::table_name();
		foreach ( $lines as $index => $batch_line ) {
			$line_row               = self::map_line( $batch_line, $index );
			$line_row['receipt_id'] = $receipt_id;
			$line_row['created_at'] = (string) $batch['created_at'];

			$ins = $wpdb->insert( $receipt_lines_table, $line_row, self::formats_for( $line_row, self::line_format_map() ) );
			if ( false === $ins ) {
				self::throw_if_error(
					new WP_Error( 'wc_io_batch_migration_line_insert', sprintf( 'Failed to insert migrated Goods Receipt line for batch #%d, line %d: %s', $batch_id, $index, $wpdb->last_error ) )
				);
			}
		}

		$receipt_costs_table = WC_Inventory_Overview_Receipt_Costs::table_name();
		foreach ( $costs as $batch_cost ) {
			$cost_row               = self::map_cost( $batch_cost );
			$cost_row['receipt_id'] = $receipt_id;

			$ins = $wpdb->insert( $receipt_costs_table, $cost_row, self::formats_for( $cost_row, self::cost_format_map() ) );
			if ( false === $ins ) {
				self::throw_if_error(
					new WP_Error( 'wc_io_batch_migration_cost_insert', sprintf( 'Failed to insert migrated Goods Receipt landed cost for batch #%d: %s', $batch_id, $wpdb->last_error ) )
				);
			}
		}

		$movement_ids = self::find_batch_movement_ids( $batch_id );
		foreach ( $movement_ids as $movement_id ) {
			$ok = WC_Inventory_Overview_Movements::backfill_reference( $movement_id, WC_Inventory_Overview_Movements::REFERENCE_TYPE_GOODS_RECEIPT, $receipt_id );
			if ( ! $ok ) {
				self::throw_if_error(
					new WP_Error( 'wc_io_batch_migration_movement_backfill', sprintf( 'Failed to backfill movement reference for batch #%d, movement #%d.', $batch_id, $movement_id ) )
				);
			}
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'wc_io_purchase_batches',
			array(
				'migrated_receipt_id' => $receipt_id,
				'migrated_at'         => current_time( 'mysql', true ),
			),
			array( 'id' => $batch_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			self::throw_if_error(
				new WP_Error( 'wc_io_batch_migration_tracking_update', sprintf( 'Failed to update migration tracking columns for batch #%d.', $batch_id ) )
			);
		}

		return array(
			'batch_id'             => $batch_id,
			'receipt_id'           => $receipt_id,
			'receipt_number'       => $receipt_number,
			'lines_migrated'       => count( $lines ),
			'costs_migrated'       => count( $costs ),
			'movements_backfilled' => count( $movement_ids ),
		);
	}

	/**
	 * Movement rows for this batch — identified by the same "Batch ID: {id}"
	 * first-note-line convention build_movement_note_for_line() writes and
	 * movements-list-table.php's regex reads, scoped to movement_type =
	 * purchase_batch and matched with an exact-integer-then-newline prefix
	 * (never a partial match, e.g. batch #1 vs batch #12).
	 *
	 * @param int $batch_id Legacy batch id.
	 * @return int[] Movement row ids.
	 */
	private static function find_batch_movement_ids( int $batch_id ): array {
		global $wpdb;
		$table  = WC_Inventory_Overview_Movements::table_name();
		$prefix = 'Batch ID: ' . $batch_id . "\n";
		$like   = $wpdb->esc_like( $prefix ) . '%';

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE movement_type = %s AND note LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				WC_Inventory_Overview_Movements::TYPE_PURCHASE_BATCH,
				$like
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Whether a batch row already carries a valid migrated_receipt_id.
	 *
	 * @param array<string,mixed> $batch Legacy batch row.
	 */
	private static function already_migrated( array $batch ): bool {
		return isset( $batch['migrated_receipt_id'] ) && null !== $batch['migrated_receipt_id'] && '' !== $batch['migrated_receipt_id'] && (int) $batch['migrated_receipt_id'] > 0;
	}

	/**
	 * Fetch one legacy batch header row.
	 *
	 * @param int $batch_id Legacy batch id.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function get_batch_row( int $batch_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_io_purchase_batches';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $batch_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return new WP_Error( 'wc_io_batch_migration_not_found', sprintf( 'Batch #%d not found.', $batch_id ) );
		}
		return $row;
	}

	/**
	 * Fetch a legacy batch's lines, id ASC (original apply-time order).
	 *
	 * @param int $batch_id Legacy batch id.
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_batch_lines( int $batch_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_io_purchase_batch_lines';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE batch_id = %d ORDER BY id ASC", $batch_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch a legacy batch's landed-cost rows, id ASC.
	 *
	 * @param int $batch_id Legacy batch id.
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_batch_costs( int $batch_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_io_purchase_batch_costs';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE batch_id = %d ORDER BY id ASC", $batch_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Build a $wpdb->insert()/update() $format array positionally aligned to
	 * $row's own key order, looked up from $format_map by column name —
	 * avoids the fragile "two hand-written arrays must stay in the same
	 * order" failure mode.
	 *
	 * @param array<string,mixed>  $row        Row about to be inserted/updated.
	 * @param array<string,string> $format_map Column name => sprintf-style format.
	 * @return array<int,string>
	 */
	private static function formats_for( array $row, array $format_map ): array {
		$formats = array();
		foreach ( $row as $key => $value ) {
			$formats[] = $format_map[ $key ] ?? '%s';
		}
		return $formats;
	}

	/**
	 * Column => $wpdb format for the Goods Receipt header insert.
	 *
	 * @return array<string,string>
	 */
	private static function header_format_map(): array {
		return array(
			'receipt_number'           => '%s',
			'status'                   => '%s',
			'source'                   => '%s',
			'supplier_id'              => '%d',
			'supplier_name_snapshot'   => '%s',
			'currency'                 => '%s',
			'exchange_rate_to_eur'     => '%s',
			'exchange_rate_date'       => '%s',
			'product_subtotal_entered' => '%s',
			'landed_total_entered'     => '%s',
			'receipt_total_entered'    => '%s',
			'product_subtotal'         => '%s',
			'landed_total'             => '%s',
			'receipt_total'            => '%s',
			'reference'                => '%s',
			'note'                     => '%s',
			'posted_at'                => '%s',
			'posted_by'                => '%d',
			'created_by'               => '%d',
			'updated_by'               => '%d',
			'created_at'               => '%s',
			'updated_at'               => '%s',
		);
	}

	/**
	 * Column => $wpdb format for each Goods Receipt line insert.
	 *
	 * @return array<string,string>
	 */
	private static function line_format_map(): array {
		return array(
			'receipt_id'              => '%d',
			'line_index'              => '%d',
			'po_line_id'              => '%d',
			'product_id'              => '%d',
			'variation_id'            => '%d',
			'sku_snapshot'            => '%s',
			'name_snapshot'           => '%s',
			'qty'                     => '%s',
			'entered_currency'        => '%s',
			'exchange_rate_to_eur'    => '%s',
			'entered_unit_cost'       => '%s',
			'converted_unit_cost_eur' => '%s',
			'base_line_cost'          => '%s',
			'allocated_landed_cost'   => '%s',
			'true_line_cost'          => '%s',
			'true_unit_cost'          => '%s',
			'old_stock'               => '%s',
			'new_stock'               => '%s',
			'old_average_unit_cost'   => '%s',
			'new_average_unit_cost'   => '%s',
			'old_inventory_value'     => '%s',
			'new_inventory_value'     => '%s',
			'created_at'              => '%s',
		);
	}

	/**
	 * Column => $wpdb format for each Goods Receipt landed-cost row insert.
	 *
	 * @return array<string,string>
	 */
	private static function cost_format_map(): array {
		return array(
			'receipt_id'           => '%d',
			'cost_type'            => '%s',
			'entered_currency'     => '%s',
			'exchange_rate_to_eur' => '%s',
			'entered_amount'       => '%s',
			'converted_amount_eur' => '%s',
			'amount'               => '%s',
			'note'                 => '%s',
			'post_hoc'             => '%d',
		);
	}

	/**
	 * Bridge a fallible call's WP_Error return into a thrown Exception so it
	 * can never silently escape an open DB_Transaction::run() closure. Mirrors
	 * WC_Inventory_Overview_Goods_Receipt_Service::throw_if_error() (M4).
	 *
	 * @param mixed $value Return value of a fallible call.
	 * @return mixed $value, when not a WP_Error.
	 * @throws WC_Inventory_Overview_Batch_Migration_Exception When $value is a WP_Error.
	 */
	private static function throw_if_error( $value ) {
		if ( is_wp_error( $value ) ) {
			throw new WC_Inventory_Overview_Batch_Migration_Exception( $value ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $value is a WP_Error object being wrapped, not output.
		}
		return $value;
	}
}
