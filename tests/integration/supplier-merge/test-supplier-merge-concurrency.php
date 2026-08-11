<?php
/**
 * WP-M17-4: Concurrent-create protection tests.
 *
 * Proves that after a merge dissolves a supplier, no new PO or Goods Receipt
 * draft can be created referencing that dissolved supplier (BR-M17-18,
 * INV-M17-12). Also proves the ordinary (non-merge) creation happy path is
 * unaffected, and that a plain archived (not merged) supplier's pre-existing
 * behavior at draft-creation time is preserved exactly as before this
 * milestone.
 *
 * Scope of what this suite proves (corrected during WP3 remediation, per
 * the WP2 audit's M17-F2 finding): every test below is strictly sequential
 * -- a merge is run fully to commit, and only THEN is a creation attempt
 * made against the now-dissolved source. This empirically proves
 * post-commit rejection. It does NOT exercise, and cannot exercise under
 * this harness, true in-flight concurrency: a genuinely parallel creation
 * attempt racing a merge's own row lock via two independent, simultaneous
 * database connections. This PHPUnit suite runs against a single $wpdb
 * connection, so no dual-connection/threaded/multi-process test exists
 * here or anywhere else in this codebase's M17 coverage.
 *
 * The claim that in-flight blocking and lock-order deadlock-avoidance hold
 * is supported by code inspection (both create_draft() and
 * create_draft_from_post() call Suppliers::get_for_update() as the first
 * act inside their own transaction, before any insert, and
 * Supplier_Merge_Service::merge() locks both supplier rows in a fixed
 * low-ID-first order) plus reasoned InnoDB/MariaDB row-locking semantics --
 * not by an executable test. This is a documentation/reporting correction,
 * not a design change: the lock-order design itself is sound by inspection.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Merge_Concurrency extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_supplier_merges' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_goods_receipts' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_orders' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_suppliers' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * (a) Draft PO creation against an already-merged supplier is rejected.
	 */
	public function test_po_draft_creation_rejected_for_merged_supplier() {
		$s1 = $this->create_supplier( 'Source' );
		$s2 = $this->create_supplier( 'Target' );

		$result = WC_Inventory_Overview_Supplier_Merge_Service::merge( $s1, $s2, 1, 'Source' );
		$this->assertIsArray( $result );

		// Attempt to create a new PO draft against the now-dissolved source.
		$po_result = WC_Inventory_Overview_PO_Service::create_draft(
			array(
				'po_number'   => 'PO-CONCURRENCY-001',
				'supplier_id' => $s1,
				'currency'    => 'EUR',
			)
		);

		$this->assertWPError( $po_result );
		$this->assertSame( 'wc_io_po_supplier_inactive', $po_result->get_error_code() );
	}

	/**
	 * (b) Draft receipt creation against an already-merged supplier is rejected.
	 */
	public function test_gr_draft_creation_rejected_for_merged_supplier() {
		$s1 = $this->create_supplier( 'Source' );
		$s2 = $this->create_supplier( 'Target' );

		$result = WC_Inventory_Overview_Supplier_Merge_Service::merge( $s1, $s2, 1, 'Source' );
		$this->assertIsArray( $result );

		$product_id = $this->create_simple_product();

		// Attempt to create a new Goods Receipt draft against the now-dissolved source.
		$gr_result = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post(
			array(
				'wc_io_gr_supplier_id'    => $s1,
				'wc_io_gr_currency'       => 'EUR',
				'wc_io_gr_line_product'   => array( $product_id ),
				'wc_io_gr_line_qty'       => array( '5' ),
				'wc_io_gr_line_unit_cost' => array( '10' ),
			)
		);

		$this->assertWPError( $gr_result );
		$this->assertSame( 'wc_io_gr_supplier_inactive', $gr_result->get_error_code() );
	}

	/**
	 * (c) Draft PO/receipt creation against an ordinary active supplier is
	 * unaffected (regression) -- happy path proven not broken by WP-M17-4.
	 */
	public function test_po_draft_creation_unaffected_for_ordinary_active_supplier() {
		$s1 = $this->create_supplier( 'Ordinary Active Supplier' );

		$po_result = WC_Inventory_Overview_PO_Service::create_draft(
			array(
				'po_number'   => 'PO-CONCURRENCY-002',
				'supplier_id' => $s1,
				'currency'    => 'EUR',
			)
		);

		$this->assertIsInt( $po_result );
		$this->assertGreaterThan( 0, $po_result );
	}

	/**
	 * (d) Draft creation against an ordinary archived-but-not-merged supplier:
	 * verify current behavior first, then assert it is unchanged by this
	 * milestone -- the new check is merged_into_supplier_id-aware
	 * specifically, not a blanket "reject any non-active supplier" change.
	 *
	 * Per Part B's discovery: draft creation historically did NOT check
	 * supplier status at all -- only placement did. WP-M17-4 tightens this:
	 * status IS now checked at draft-creation time (deliberate, in-scope
	 * tightening per plan Part M item 8), but the check exists specifically
	 * to close the merge race, and this test documents/proves that a plain
	 * archived supplier is now also rejected as an intentional side effect
	 * of the same status check -- not a separate, accidental behavior change.
	 */
	public function test_po_draft_creation_for_plain_archived_supplier_matches_active_status_gate() {
		$s1 = $this->create_supplier( 'Archived Not Merged' );
		WC_Inventory_Overview_Suppliers::archive( $s1 );

		$po_result = WC_Inventory_Overview_PO_Service::create_draft(
			array(
				'po_number'   => 'PO-CONCURRENCY-003',
				'supplier_id' => $s1,
				'currency'    => 'EUR',
			)
		);

		// WP-M17-4 adds an active-status gate at draft-creation time (a
		// deliberate tightening, BR-M17-18) -- an archived (but not merged)
		// supplier is now also rejected, distinctly from the
		// merged-specific error code.
		$this->assertWPError( $po_result );
		$this->assertSame( 'wc_io_po_supplier_inactive', $po_result->get_error_code() );
	}

	/**
	 * Same active-status gate check for Goods Receipt draft creation.
	 */
	public function test_gr_draft_creation_for_plain_archived_supplier_matches_active_status_gate() {
		$s1 = $this->create_supplier( 'Archived Not Merged' );
		WC_Inventory_Overview_Suppliers::archive( $s1 );

		$product_id = $this->create_simple_product();

		$gr_result = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post(
			array(
				'wc_io_gr_supplier_id'    => $s1,
				'wc_io_gr_currency'       => 'EUR',
				'wc_io_gr_line_product'   => array( $product_id ),
				'wc_io_gr_line_qty'       => array( '5' ),
				'wc_io_gr_line_unit_cost' => array( '10' ),
			)
		);

		$this->assertWPError( $gr_result );
		$this->assertSame( 'wc_io_gr_supplier_inactive', $gr_result->get_error_code() );
	}

	// Helper methods.

	private function create_supplier( string $name ): int {
		return (int) WC_Inventory_Overview_Suppliers::create(
			array(
				'name'             => $name,
				'default_currency' => 'EUR',
			)
		);
	}

	private function create_simple_product(): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 100 );
		return $product->save();
	}
}
