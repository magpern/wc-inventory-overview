<?php
/**
 * Architecture guard tests for Milestone M6 — Migration & Retirement.
 *
 * Source-scanning guards enforcing the M6 implementation plan's central
 * prohibition (§Migration model): migration is historical record
 * materialization, never receiving-replay. Batch_Migration_Service must
 * never call set_stock_quantity(), never write the cost/value product meta
 * keys, never call Goods_Receipt_Service::post(), and never call any
 * Restock_Service/PO_Receiving_Sync mutation method.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Batch_Migration_Architecture extends WP_UnitTestCase {

	/**
	 * @return string
	 */
	private function includes_dir(): string {
		return WC_INVENTORY_OVERVIEW_PATH . 'includes/';
	}

	/**
	 * @param string $src PHP source.
	 */
	private function strip_comments( string $src ): string {
		$src = (string) preg_replace( '#/\*.*?\*/#s', '', $src );
		$src = (string) preg_replace( '#//[^\n]*#', '', $src );
		return $src;
	}

	/**
	 * @return string
	 */
	private function migration_service_source(): string {
		return $this->strip_comments( (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-batch-migration-service.php' ) );
	}

	/**
	 * The single most important guard in this milestone: migration never
	 * mutates current WooCommerce stock.
	 */
	public function test_never_calls_set_stock_quantity() {
		$this->assertStringNotContainsString( 'set_stock_quantity', $this->migration_service_source() );
	}

	/**
	 * Migration never writes the weighted-average cost or inventory-value
	 * product meta keys directly.
	 */
	public function test_never_writes_cost_meta_keys() {
		$src = $this->migration_service_source();
		$this->assertStringNotContainsString( '_wc_io_average_unit_cost', $src );
		$this->assertStringNotContainsString( '_wc_io_inventory_value', $src );
		$this->assertStringNotContainsString( 'update_post_meta', $src );
		$this->assertStringNotContainsString( '->update_meta_data', $src );
	}

	/**
	 * Migration never goes through the live receiving engine.
	 */
	public function test_never_calls_goods_receipt_service_post() {
		$src = $this->migration_service_source();
		$this->assertStringNotContainsString( 'Goods_Receipt_Service::post(', $src );
		$this->assertStringNotContainsString( 'Goods_Receipt_Service::void(', $src );
	}

	/**
	 * Migration never calls Restock_Service's mutation methods.
	 */
	public function test_never_calls_restock_service_mutators() {
		$src = $this->migration_service_source();
		$this->assertStringNotContainsString( 'Restock_Service::apply_purchase_line_change', $src );
		$this->assertStringNotContainsString( 'Restock_Service::apply_purchase_line_reversal', $src );
	}

	/**
	 * Migration never touches PO receiving/qty_received — a migrated batch
	 * carries no PO linkage by construction (D7, M5's binding note).
	 */
	public function test_never_touches_po_receiving_or_qty_received() {
		$src = $this->migration_service_source();
		$this->assertStringNotContainsString( 'PO_Receiving_Sync', $src );
		$this->assertStringNotContainsString( 'increment_qty_received', $src );
		$this->assertStringNotContainsString( "'qty_received'", $src );
	}

	/**
	 * Migration never fuzzy-matches a batch's free-text supplier name to a
	 * wc_io_suppliers row — supplier_id is always NULL for migrated receipts
	 * (M6 plan §Migration model — "an invented relationship the original
	 * record never had").
	 */
	public function test_never_looks_up_suppliers_by_name() {
		$src = $this->migration_service_source();
		$this->assertStringNotContainsString( 'Suppliers::find_by_name', $src );
		$this->assertStringNotContainsString( 'Suppliers::search', $src );
		$this->assertStringNotContainsString( 'normalized_name', $src );
	}

	/**
	 * map_header() always produces po_line_id => null and supplier_id =>
	 * null (source-level assertion, complementing the behavioral one in the
	 * mapping test).
	 */
	public function test_map_header_hardcodes_null_supplier_id() {
		$src = $this->migration_service_source();
		$this->assertMatchesRegularExpression( "/'supplier_id'\\s*=>\\s*null,/", $src );
	}

	/**
	 * WC_Inventory_Overview_Movements::backfill_reference() — the narrow,
	 * purpose-built movement-reference writer — is called from exactly one
	 * file anywhere in includes/.
	 */
	public function test_backfill_reference_sole_caller() {
		$callers = array();
		foreach ( glob( $this->includes_dir() . '*.php' ) as $file ) {
			$basename = basename( $file );
			if ( 'class-wc-inventory-overview-movements.php' === $basename ) {
				continue; // Definition site.
			}
			$src = $this->strip_comments( (string) file_get_contents( $file ) );
			if ( false !== strpos( $src, '::backfill_reference(' ) ) {
				$callers[] = $basename;
			}
		}
		$this->assertSame(
			array( 'class-wc-inventory-overview-batch-migration-service.php' ),
			$callers,
			'Only Batch_Migration_Service may call Movements::backfill_reference().'
		);
	}

	/**
	 * No row-level locking, distributed locks, or table locks — same
	 * deliberate no-locking posture as M4/M5.
	 */
	public function test_no_row_locking_constructs() {
		$src = $this->migration_service_source();
		$this->assertStringNotContainsString( 'FOR UPDATE', $src );
		$this->assertStringNotContainsString( 'LOCK TABLES', $src );
		$this->assertStringNotContainsString( 'GET_LOCK', $src );
	}

	/**
	 * Invariant M6-1: migrate_batch() and rollback_batch() each open exactly
	 * one transaction (one `new WC_Inventory_Overview_DB_Transaction(`
	 * instantiation per public entry method) — never a shared/outer
	 * transaction spanning multiple batches. Counted at the source level:
	 * exactly two instantiations in the whole file (one per method).
	 */
	public function test_exactly_one_transaction_instantiation_per_public_method() {
		$src   = $this->migration_service_source();
		$count = substr_count( $src, 'new WC_Inventory_Overview_DB_Transaction(' );
		$this->assertSame( 2, $count, 'Expected exactly one DB_Transaction per public entry method (migrate_batch, rollback_batch).' );
	}

	/**
	 * Legacy tables are never dropped or truncated by the migration engine
	 * (D14 — frozen, not destroyed).
	 */
	public function test_never_drops_or_truncates_legacy_tables() {
		$src = $this->migration_service_source();
		$this->assertStringNotContainsString( 'DROP TABLE', $src );
		$this->assertStringNotContainsString( 'TRUNCATE', $src );
	}
}
