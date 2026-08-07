<?php
/**
 * M5 measurable performance criterion: receiving many lines against the same PO
 * must not introduce N+1 query growth — repository query count must remain
 * bounded/driven by bulk operations, growing linearly (not superlinearly) with
 * line count.
 *
 * Extended per the M5 audit remediation (WP3): the plan's own explicit
 * acceptance criterion is "approximately 100 lines," and
 * Goods_Receipt_Service::validate_and_assess_po_linked_lines() now reads via
 * bulk repository methods (Purchase_Order_Lines::list_by_ids(),
 * Purchase_Orders::list_by_ids()) instead of one get() call per line — this
 * file asserts that bulk-ness directly (constant query cost regardless of N),
 * not merely a loose linear-growth ratio at small N.
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
	 * Build a placed PO with $n lines and a matching draft receipt covering all
	 * of them. Shared by every test below so the fixture shape is defined once.
	 *
	 * @param int $n Number of lines.
	 * @return int Draft receipt id.
	 */
	private function build_po_linked_draft_receipt( int $n ): int {
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

		return $draft_id;
	}

	/**
	 * Build an N-line PO-linked draft and post it while counting queries.
	 *
	 * @param int $n Number of lines.
	 * @return int Query count for posting.
	 */
	private function post_n_line_receipt_and_count_queries( int $n ): int {
		$draft_id = $this->build_po_linked_draft_receipt( $n );

		$count   = 0;
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
	 * Reflectively invoke the private validate_and_assess_po_linked_lines()
	 * directly, isolating exactly its own query cost from post()'s other,
	 * separately-expected per-line writes (Restock_Service / Movements /
	 * PO_Receiving_Sync — each unavoidably per-line per INV-1/INV-7). Isolating
	 * this method proves the WP3 fix's specific claim: the validation phase
	 * itself is now bulk, not driven by per-line count.
	 *
	 * @param int $n Number of PO-linked lines to build and validate.
	 * @return int Query count for the validation call alone.
	 */
	private function count_queries_for_po_line_validation( int $n ): int {
		$draft_id = $this->build_po_linked_draft_receipt( $n );
		$lines    = WC_Inventory_Overview_Receipt_Lines::list_for_receipt( $draft_id );

		$method = new ReflectionMethod( 'WC_Inventory_Overview_Goods_Receipt_Service', 'validate_and_assess_po_linked_lines' );
		$method->setAccessible( true );

		$count   = 0;
		$counter = static function ( $query ) use ( &$count ) {
			++$count;
			return $query;
		};
		add_filter( 'query', $counter );
		$result = $method->invoke( null, $lines );
		remove_filter( 'query', $counter );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		return $count;
	}

	/**
	 * Query count grows linearly, not superlinearly (e.g. quadratically), as
	 * line count doubles. Cheap sanity check at small N.
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

	/**
	 * WP3 remediation — validate_and_assess_po_linked_lines()'s own query cost
	 * must be a constant regardless of PO-linked line count, proving it reads
	 * via bulk repository methods (list_by_ids()) rather than one get() call
	 * per line. Tested directly at the plan's named scale (100 lines).
	 */
	public function test_po_line_validation_query_count_is_constant_not_per_line() {
		$q_2   = $this->count_queries_for_po_line_validation( 2 );
		$q_100 = $this->count_queries_for_po_line_validation( 100 );

		$this->assertSame(
			$q_2,
			$q_100,
			"PO-line validation query count must be constant regardless of line count (bulk-fetch, not per-line): 2 lines = {$q_2} queries, 100 lines = {$q_100} queries."
		);
	}

	/**
	 * The M5 plan's own measurable acceptance criterion, tested at the scale it
	 * names: receiving a Purchase Order with approximately 100 lines must not
	 * exhibit N+1 query growth end-to-end through post(). The unavoidable
	 * per-line writes (Restock_Service, Movements, PO_Receiving_Sync — each
	 * bounded and mostly bulk internally per INV-1/INV-7) still scale with N;
	 * this asserts nothing ADDITIONAL scales worse than that fixed per-line
	 * cost as N grows 50x.
	 */
	public function test_100_line_po_receipt_posts_with_linear_not_quadratic_query_growth() {
		$q_2   = $this->post_n_line_receipt_and_count_queries( 2 );
		$q_100 = $this->post_n_line_receipt_and_count_queries( 100 );

		// 50x more lines. A 75x ceiling (rather than a tight 50x) absorbs the
		// fixed one-time overhead (schema/status checks, header operations)
		// that does not scale with N, while still failing hard on genuine
		// quadratic blow-up, which would push this ratio into the thousands.
		$this->assertLessThanOrEqual(
			$q_2 * 75,
			$q_100,
			"Query count grew superlinearly at scale: 2 lines = {$q_2} queries, 100 lines = {$q_100} queries."
		);
	}
}
