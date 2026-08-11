<?php
/**
 * Architecture guard tests for M17 Supplier Merge.
 *
 * Grep-based structural guards proving:
 * - INV-M17-2: Supplier_Merge_Service is the sole class permitted to write
 *   wc_io_suppliers.merged_into_supplier_id or bulk-update supplier_id
 *   across more than one row of purchase_orders/goods_receipts at once.
 * - INV-M17-7: the admin page (Purchasing_Page) contains no direct SQL for
 *   the merge.
 * - INV-M17-10: M17 introduces no new WordPress action, no new WordPress
 *   filter, and no new public API of any kind in the new files.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Merge_Architecture extends WP_UnitTestCase {

	/**
	 * INV-M17-2: only Suppliers::mark_merged() writes merged_into_supplier_id.
	 * No other file in includes/ may reference this column name for writing.
	 */
	public function test_merged_into_supplier_id_written_only_by_suppliers_mark_merged() {
		$includes_dir = WC_INVENTORY_OVERVIEW_PATH . 'includes/';
		$files        = glob( $includes_dir . '*.php' );

		$writers = array();
		foreach ( $files as $file ) {
			$basename = basename( $file );
			// Skip the one file allowed to write this column.
			if ( 'class-wc-inventory-overview-suppliers.php' === $basename ) {
				continue;
			}
			$source = file_get_contents( $file );
			// Look for an actual SQL write referencing the column (UPDATE-style
			// context), not merely a read/reference (e.g. array key access).
			if ( preg_match( '/merged_into_supplier_id[\'"]?\s*=>/', $source ) && false !== strpos( $source, '$wpdb->update' ) ) {
				$writers[] = $basename;
			}
		}

		$this->assertSame( array(), $writers, 'Only class-wc-inventory-overview-suppliers.php may write merged_into_supplier_id via $wpdb->update.' );
	}

	/**
	 * INV-M17-2: only Purchase_Orders::reassign_supplier_bulk() and
	 * Goods_Receipts::reassign_supplier_bulk() bulk-UPDATE supplier_id
	 * across more than one row.
	 */
	public function test_bulk_supplier_id_reassignment_confined_to_dedicated_methods() {
		$includes_dir = WC_INVENTORY_OVERVIEW_PATH . 'includes/';
		$files        = glob( $includes_dir . '*.php' );

		$allowed = array(
			'class-wc-inventory-overview-purchase-orders.php',
			'class-wc-inventory-overview-goods-receipts.php',
		);

		$violators = array();
		foreach ( $files as $file ) {
			$basename = basename( $file );
			if ( in_array( $basename, $allowed, true ) ) {
				continue;
			}
			$source = file_get_contents( $file );
			// Bulk reassignment pattern: UPDATE ... SET supplier_id = ... WHERE supplier_id = ...
			if ( preg_match( '/UPDATE\s+\{?\$?table\}?\s+SET\s+supplier_id\s*=\s*%d\s+WHERE\s+supplier_id\s*=\s*%d/i', $source ) ) {
				$violators[] = $basename;
			}
		}

		$this->assertSame( array(), $violators, 'Only Purchase_Orders/Goods_Receipts::reassign_supplier_bulk() may bulk-UPDATE supplier_id.' );
	}

	/**
	 * INV-M17-2: Supplier_Merge_Service is confirmed to be the orchestrator
	 * calling all the mutation primitives -- structural confirmation via
	 * source inspection.
	 */
	public function test_merge_service_is_sole_orchestrator_of_mutation_primitives() {
		$file   = WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-supplier-merge-service.php';
		$source = file_get_contents( $file );

		$this->assertStringContainsString( 'Purchase_Orders::reassign_supplier_bulk', $source );
		$this->assertStringContainsString( 'Goods_Receipts::reassign_supplier_bulk', $source );
		$this->assertStringContainsString( 'Suppliers::mark_merged', $source );
		$this->assertStringContainsString( 'Supplier_Merges::add', $source );
	}

	/**
	 * INV-M17-7: the admin page (Purchasing_Page) contains no direct SQL
	 * for the merge -- it only calls the service layer.
	 */
	public function test_purchasing_page_contains_no_direct_sql_for_merge() {
		$file   = WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-purchasing-page.php';
		$source = file_get_contents( $file );

		// Extract just the merge-related methods for a targeted check.
		$this->assertMatchesRegularExpression( '/function handle_supplier_merge\(\)/', $source );
		$this->assertMatchesRegularExpression( '/function ajax_search_merge_targets\(\)/', $source );

		// Get the substring from handle_supplier_merge to the next method.
		$start = strpos( $source, 'function handle_supplier_merge()' );
		$end   = strpos( $source, 'function ajax_search_merge_targets()' );
		$merge_handler_source = substr( $source, $start, $end - $start );

		$this->assertStringNotContainsString( 'global $wpdb', $merge_handler_source );
		$this->assertStringNotContainsString( '$wpdb->query', $merge_handler_source );
		$this->assertStringNotContainsString( '$wpdb->update', $merge_handler_source );
		$this->assertStringContainsString( 'WC_Inventory_Overview_Supplier_Merge_Service::merge', $merge_handler_source );
	}

	/**
	 * INV-M17-10: no new do_action()/apply_filters() introduced anywhere in
	 * the new M17 files.
	 */
	public function test_no_new_wordpress_hooks_introduced() {
		$m17_files = array(
			'class-wc-inventory-overview-supplier-merges.php',
			'class-wc-inventory-overview-supplier-merge-service.php',
		);

		foreach ( $m17_files as $basename ) {
			$file   = WC_INVENTORY_OVERVIEW_PATH . 'includes/' . $basename;
			$source = file_get_contents( $file );

			$this->assertSame( 0, substr_count( $source, 'do_action(' ), "do_action() found in {$basename} -- M17 introduces no new WordPress action (INV-M17-10)." );
			$this->assertSame( 0, substr_count( $source, 'apply_filters(' ), "apply_filters() found in {$basename} -- M17 introduces no new WordPress filter (INV-M17-10)." );
		}
	}
}
