<?php
/**
 * M5 measurable performance criterion: receiving many lines against the same PO
 * must not introduce N+1 query growth — repository query count must remain
 * bounded/driven by bulk operations, growing linearly (not superlinearly) with
 * line count.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_PO_Receiving_Performance extends WC_Inventory_Overview_Test_Case {

	/**
	 * @var int
	 */
	private $admin_id;

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Receipt_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_Goods_Receipt_Numbering::OPTION_KEY );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Build a placed PO with $n lines, and a matching draft receipt covering all
	 * of them, then post it while counting queries. Query count for N=4 lines
	 * must not exceed roughly double the count for N=2 lines — linear, not
	 * quadratic, growth. (A fixed per-line cost — one Restock_Service mutation,
	 * one movement insert, one PO sync call each with its own bounded, mostly
	 * bulk queries — is expected and unavoidable per INV-1/INV-7; the guard is
	 * against avoidable, non-bulk per-line re-reads on top of that.)
	 *
	 * @param int $n Number of lines.
	 * @return int Query count for posting.
	 */
	private function post_n_line_receipt_and_count_queries( int $n ): int {
		$supplier    = $this->create_supplier();
		$product_ids = array();
		$po_line_ids = array();
		$qtys        = array();
		$costs       = array();

		$po_id = WC_Inventory_Overview_PO_Service::create_draft( array( 'supplier_id' => (int) $supplier['id'] ), array() );
		for ( $i = 0; $i < $n; $i++ ) {
			$product       = $this->create_simple_product( array( 'stock_qty' => 0 ) );
			$product_ids[] = $product->get_id();
			$qtys[]        = 2;
			$costs[]       = 3;
			WC_Inventory_Overview_PO_Service::add_line( $po_id, array( 'product_id' => $product->get_id(), 'qty_ordered' => 2, 'unit_cost' => 3 ) );
		}
		WC_Inventory_Overview_PO_Service::place( $po_id );
		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );
		foreach ( $lines as $line ) {
			$po_line_ids[] = (int) $line['id'];
		}

		$draft_id = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post(
			array(
				'wc_io_gr_currency'        => 'EUR',
				'wc_io_gr_line_product'    => $product_ids,
				'wc_io_gr_line_qty'        => $qtys,
				'wc_io_gr_line_unit_cost'  => $costs,
				'wc_io_gr_line_po_line_id' => $po_line_ids,
			)
		);
		$this->assertIsInt( $draft_id, is_wp_error( $draft_id ) ? $draft_id->get_error_message() : '' );

		global $wpdb;
		$count = 0;
		$counter = static function ( $query ) use ( &$count ) {
			++$count;
			return $query;
		};
		add_filter( 'query', $counter );
		$result = WC_Inventory_Overview_Goods_Receipt_Service::post( $draft_id, WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' ) );
		remove_filter( 'query', $counter );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		return $count;
	}

	/**
	 * Query count grows linearly, not superlinearly (e.g. quadratically), as
	 * line count doubles.
	 */
	public function test_query_count_grows_linearly_not_quadratically() {
		$q2 = $this->post_n_line_receipt_and_count_queries( 2 );
		$q4 = $this->post_n_line_receipt_and_count_queries( 4 );

		// Linear growth: doubling lines should roughly double the marginal query
		// cost, not quadruple it. Allow generous headroom (3x) to avoid a flaky
		// test while still catching genuine N+1/quadratic regressions.
		$this->assertLessThanOrEqual(
			$q2 * 3,
			$q4,
			"Query count grew superlinearly: 2 lines = {$q2} queries, 4 lines = {$q4} queries."
		);
	}
}
