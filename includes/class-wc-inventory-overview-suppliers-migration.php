<?php
/**
 * Idempotent seed migration: historical supplier strings → supplier entity rows.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Supplier seed migration runner.
 */
class WC_Inventory_Overview_Suppliers_Migration {

	/**
	 * Run the idempotent seed migration.
	 * Returns the migration report, persisted to option.
	 */
	public static function run(): array {
		global $wpdb;

		$report = array(
			'ran_at'                      => current_time( 'mysql', true ),
			'db_version_before'           => get_option( 'wc_io_db_version', '' ),
			'db_version_after'            => '6',
			'source_rows_scanned'         => 0,
			'distinct_normalized_groups'  => 0,
			'suppliers_created'           => 0,
			'suppliers_skipped_existing'  => 0,
			'created_supplier_ids'        => array(),
			'run_count'                   => absint( get_option( 'wc_io_migration_run_count', 0 ) ) + 1,
			'errors'                      => array(),
		);

		// Step 1: Collect source rows from batches and movements.
		$batches_table   = $wpdb->prefix . 'wc_io_purchase_batches';
		$movements_table = $wpdb->prefix . 'wc_io_inventory_movements';

		$supplier_rows = array();

		// From batches.
		$batch_rows = $wpdb->get_results( "SELECT supplier_name, created_at FROM {$batches_table} WHERE supplier_name IS NOT NULL AND supplier_name != '' ORDER BY created_at ASC" );
		foreach ( $batch_rows as $row ) {
			$supplier_rows[] = array(
				'original_string' => $row->supplier_name,
				'created_at'      => $row->created_at,
				'source'          => 'batch',
			);
		}

		// From movements.
		$movement_rows = $wpdb->get_results( "SELECT supplier_name, created_at FROM {$movements_table} WHERE supplier_name IS NOT NULL AND supplier_name != '' ORDER BY created_at ASC" );
		foreach ( $movement_rows as $row ) {
			$supplier_rows[] = array(
				'original_string' => $row->supplier_name,
				'created_at'      => $row->created_at,
				'source'          => 'movement',
			);
		}

		$report['source_rows_scanned'] = count( $supplier_rows );

		// Step 2: Group by normalized name.
		$groups = array();
		foreach ( $supplier_rows as $row ) {
			$normalized = WC_Inventory_Overview_Suppliers::normalize_name( $row['original_string'] );
			if ( ! isset( $groups[ $normalized ] ) ) {
				$groups[ $normalized ] = array();
			}
			$groups[ $normalized ][] = $row;
		}

		$report['distinct_normalized_groups'] = count( $groups );

		// Step 3: For each group, apply tie-break to choose display name; insert if not already existing.
		foreach ( $groups as $normalized => $rows ) {
			$chosen_name = self::apply_tie_break( $rows );

			// Check if already exists (idempotent check).
			$existing = WC_Inventory_Overview_Suppliers::get_by_normalized_name( $normalized );
			if ( $existing ) {
				$report['suppliers_skipped_existing']++;
				continue;
			}

			// Insert.
			$result = WC_Inventory_Overview_Suppliers::create( array(
				'name'                   => $chosen_name,
				'default_currency'       => 'EUR',
				'default_lead_time_days' => null,
			) );

			if ( is_wp_error( $result ) ) {
				$report['errors'][] = array(
					'normalized'  => $normalized,
					'chosen_name' => $chosen_name,
					'error'       => $result->get_error_message(),
				);
			} else {
				$report['suppliers_created']++;
				$report['created_supplier_ids'][] = $result;
			}
		}

		// Step 4: Persist report.
		update_option( 'wc_io_supplier_seed_migration_report', $report, false );
		update_option( 'wc_io_migration_run_count', $report['run_count'] );

		return $report;
	}

	/**
	 * Deterministic 3-step tie-break to choose the display name for a group of supplier strings.
	 * Step 1: Most-frequent original string.
	 * Step 2: Earliest created_at.
	 * Step 3: Alphabetical (strcmp).
	 */
	private static function apply_tie_break( array $rows ): string {
		// Count occurrences of each original string.
		$frequency = array();
		foreach ( $rows as $row ) {
			$str = $row['original_string'];
			if ( ! isset( $frequency[ $str ] ) ) {
				$frequency[ $str ] = 0;
			}
			$frequency[ $str ]++;
		}

		// Sort by frequency (highest first), then by earliest created_at, then alphabetically.
		usort( $rows, function ( $a, $b ) use ( $frequency ) {
			$freq_a = $frequency[ $a['original_string'] ];
			$freq_b = $frequency[ $b['original_string'] ];

			if ( $freq_a !== $freq_b ) {
				return $freq_b - $freq_a;
			}

			$time_a = strtotime( $a['created_at'] );
			$time_b = strtotime( $b['created_at'] );
			if ( $time_a !== $time_b ) {
				return $time_a - $time_b;
			}

			return strcmp( $a['original_string'], $b['original_string'] );
		} );

		return $rows[0]['original_string'];
	}
}
