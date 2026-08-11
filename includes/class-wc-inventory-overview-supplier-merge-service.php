<?php
/**
 * Supplier merge domain service (M17-D).
 *
 * Orchestrates supplier merge operations: atomicity, exception-safety, business rules,
 * server-enforced confirmation, transaction management.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Supplier merge orchestration service.
 *
 * Sole entity permitted to write merged_into_supplier_id or bulk-reassign supplier_id
 * across multiple records (INV-M17-2).
 */
class WC_Inventory_Overview_Supplier_Merge_Service {

	/**
	 * Test-only failure-injection seam. Private, gated by WC_IO_PHPUNIT_RUNNING.
	 *
	 * @var string|null
	 */
	private static $test_fail_after_step = null;

	/**
	 * Perform a supplier merge.
	 *
	 * All-or-nothing transaction: merges source's purchase orders and goods receipts
	 * into target, archives source, records audit entry. Exception-safe rollback on
	 * any failure path.
	 *
	 * @param int    $source_supplier_id Source supplier ID (being dissolved).
	 * @param int    $target_supplier_id Target supplier ID (receiving).
	 * @param int    $performed_by       User ID performing the merge.
	 * @param string $confirmation       Server-side confirmation text (must match source.name exactly).
	 *
	 * @return array|WP_Error {
	 *     On success, array with counts:
	 *     @type int $source_supplier_id
	 *     @type int $target_supplier_id
	 *     @type int $purchase_orders_reassigned
	 *     @type int $goods_receipts_reassigned
	 * } On failure, WP_Error with code from BR-M17-1..18.
	 */
	public static function merge( int $source_supplier_id, int $target_supplier_id, int $performed_by, string $confirmation ) {
		global $wpdb;

		// BR-M17-1/2: cheap, pre-transaction checks.
		if ( $source_supplier_id === $target_supplier_id ) {
			return new WP_Error( 'wc_io_supplier_merge_same_supplier', __( 'A supplier cannot be merged into itself.', 'wc-inventory-overview' ) );
		}
		if ( is_wp_error( WC_Inventory_Overview_Suppliers::get( $source_supplier_id ) ) ) {
			return new WP_Error( 'wc_io_supplier_merge_source_not_found', __( 'Source supplier not found.', 'wc-inventory-overview' ) );
		}
		if ( is_wp_error( WC_Inventory_Overview_Suppliers::get( $target_supplier_id ) ) ) {
			return new WP_Error( 'wc_io_supplier_merge_target_not_found', __( 'Target supplier not found.', 'wc-inventory-overview' ) );
		}

		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );
		if ( ! $txn->begin() ) {
			return new WP_Error( 'wc_io_supplier_merge_txn_failed', __( 'Failed to begin merge transaction.', 'wc-inventory-overview' ) );
		}

		try {
			// Fixed lock order: prevent deadlock on concurrent reverse-direction merges.
			$first_id  = min( $source_supplier_id, $target_supplier_id );
			$second_id = max( $source_supplier_id, $target_supplier_id );

			$first = WC_Inventory_Overview_Suppliers::get_for_update( $first_id );
			if ( is_wp_error( $first ) ) {
				$txn->rollback();
				return $first;
			}
			$second = WC_Inventory_Overview_Suppliers::get_for_update( $second_id );
			if ( is_wp_error( $second ) ) {
				$txn->rollback();
				return $second;
			}
			$source = ( (int) $first['id'] === $source_supplier_id ) ? $first : $second;
			$target = ( (int) $first['id'] === $target_supplier_id ) ? $first : $second;

			// BR-M17-16: server-side confirmation.
			if ( $confirmation !== $source['name'] ) {
				$txn->rollback();
				return new WP_Error( 'wc_io_supplier_merge_confirmation_mismatch', __( 'Confirmation text did not match the source supplier name exactly.', 'wc-inventory-overview' ) );
			}

			// BR-M17-3/4: re-validate inside locked transaction.
			if ( ! empty( $source['merged_into_supplier_id'] ) ) {
				$txn->rollback();
				$existing_target = WC_Inventory_Overview_Suppliers::get( (int) $source['merged_into_supplier_id'] );
				$target_name     = is_wp_error( $existing_target ) ? '' : $existing_target['name'];
				return new WP_Error( 'wc_io_supplier_merge_source_already_merged', sprintf( __( 'This supplier was already merged into %s.', 'wc-inventory-overview' ), $target_name ) );
			}
			if ( WC_Inventory_Overview_Suppliers::STATUS_ACTIVE !== $target['status'] ) {
				$txn->rollback();
				return new WP_Error( 'wc_io_supplier_merge_target_not_active', __( 'Target supplier must be active.', 'wc-inventory-overview' ) );
			}
			if ( ! empty( $target['merged_into_supplier_id'] ) ) {
				$txn->rollback();
				return new WP_Error( 'wc_io_supplier_merge_target_already_merged', __( 'Target supplier has itself already been merged into another supplier.', 'wc-inventory-overview' ) );
			}

			// BR-M17-6: bulk reassign purchase orders (all statuses).
			$po_count = WC_Inventory_Overview_Purchase_Orders::reassign_supplier_bulk( $source_supplier_id, $target_supplier_id );
			if ( is_wp_error( $po_count ) ) {
				$txn->rollback();
				return $po_count;
			}
			self::maybe_inject_test_failure( 'po_reassign' );

			// BR-M17-6: bulk reassign goods receipts (all statuses).
			$gr_count = WC_Inventory_Overview_Goods_Receipts::reassign_supplier_bulk( $source_supplier_id, $target_supplier_id );
			if ( is_wp_error( $gr_count ) ) {
				$txn->rollback();
				return $gr_count;
			}
			self::maybe_inject_test_failure( 'gr_reassign' );

			// BR-M17-11: record audit entry.
			$audit_id = WC_Inventory_Overview_Supplier_Merges::add(
				array(
					'source_supplier_id'            => $source_supplier_id,
					'source_supplier_name_snapshot' => $source['name'],
					'target_supplier_id'            => $target_supplier_id,
					'target_supplier_name_snapshot' => $target['name'],
					'purchase_orders_reassigned'    => $po_count,
					'goods_receipts_reassigned'     => $gr_count,
					'performed_by'                  => $performed_by,
				)
			);
			if ( is_wp_error( $audit_id ) ) {
				$txn->rollback();
				return $audit_id;
			}
			self::maybe_inject_test_failure( 'audit_insert' );

			// BR-M17-10: mark source as merged.
			$marked = WC_Inventory_Overview_Suppliers::mark_merged( $source_supplier_id, $target_supplier_id );
			if ( is_wp_error( $marked ) ) {
				$txn->rollback();
				return $marked;
			}

			if ( ! $txn->commit() ) {
				$txn->rollback();
				return new WP_Error( 'wc_io_supplier_merge_txn_failed', __( 'Failed to commit merge transaction.', 'wc-inventory-overview' ) );
			}
		} catch ( \Throwable $e ) {
			if ( $txn->is_active() ) {
				$txn->rollback();
			}
			return new WP_Error(
				'wc_io_supplier_merge_failed',
				__( 'Supplier merge failed unexpectedly and was rolled back.', 'wc-inventory-overview' ),
				array( 'internal' => $e->getMessage() )
			);
		}

		return array(
			'source_supplier_id'         => $source_supplier_id,
			'target_supplier_id'         => $target_supplier_id,
			'purchase_orders_reassigned' => $po_count,
			'goods_receipts_reassigned'  => $gr_count,
		);
	}

	/**
	 * Test-only: arm failure injection after named step.
	 * No-op unless WC_IO_PHPUNIT_RUNNING is defined (tests/bootstrap.php only).
	 *
	 * @param string|null $step Step name ('po_reassign'|'gr_reassign'|'audit_insert'), or null to disarm.
	 */
	public static function set_test_fail_after_step( ?string $step ): void {
		if ( ! defined( 'WC_IO_PHPUNIT_RUNNING' ) ) {
			return;
		}
		self::$test_fail_after_step = $step;
	}

	/**
	 * Test-only: inject failure if armed for this step.
	 * No-op in production.
	 *
	 * @param string $step Current step.
	 * @throws RuntimeException if failure is armed.
	 */
	private static function maybe_inject_test_failure( string $step ): void {
		if ( defined( 'WC_IO_PHPUNIT_RUNNING' ) && self::$test_fail_after_step === $step ) {
			throw new RuntimeException( 'wc_io_test_injected_failure:' . $step );
		}
	}
}
