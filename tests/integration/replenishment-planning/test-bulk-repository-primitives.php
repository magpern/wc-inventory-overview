<?php
/**
 * M24 WP-M24-4: bulk repository primitives.
 *
 * - WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_items_bulk()
 *   -- parity with N individual distinct_supplier_history_for_item() calls,
 *   fixed query count at N=10/50/100, EXPLAIN evidence for both branches
 *   at N=100 (§14.1).
 * - WC_Inventory_Overview_Replenishment_Defaults::get_bulk() -- parity with
 *   N individual get_preferred_supplier_id()/get_default_qty() calls, fixed
 *   query count at N=10/50/100.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Bulk_Repository_Primitives extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function seed_history( int $product_id, int $variation_id = 0, string $order_date = '2026-01-01' ): int {
		$po = $this->create_purchase_order( array( 'order_date' => $order_date ) );
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
			)
		);
		WC_Inventory_Overview_Purchase_Orders::update_fields( $po['id'], array( 'status' => 'placed', 'order_date' => $order_date ) );
		return (int) $po['supplier_id'];
	}

	// -----------------------------------------------------------------
	// distinct_supplier_history_for_items_bulk() parity
	// -----------------------------------------------------------------

	public function test_bulk_history_parity_with_single_item_calls() {
		$p1 = $this->create_simple_product();
		$p2 = $this->create_simple_product();
		$s1a = $this->seed_history( $p1->get_id(), 0, '2026-01-01' );
		$s1b = $this->seed_history( $p1->get_id(), 0, '2026-02-01' );
		$s2  = $this->seed_history( $p2->get_id(), 0, '2026-01-15' );

		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1' ) ) );
		wc_delete_product_transients( $variable->get_id() );
		$fresh    = wc_get_product( $variable->get_id() );
		$children = $fresh->get_children();
		$vid      = (int) $children[0];
		$sv       = $this->seed_history( $variable->get_id(), $vid, '2026-03-01' );

		$bulk = WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_items_bulk(
			array( $p1->get_id(), $p2->get_id() ),
			array( $vid )
		);

		$single_p1 = WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_item( $p1->get_id(), 0 );
		$single_p2 = WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_item( $p2->get_id(), 0 );
		$single_v  = WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_item( $variable->get_id(), $vid );

		$this->assertSame( $single_p1, $bulk[ $p1->get_id() ] ?? array(), 'Bulk result for p1 must match the single-item method exactly, including order.' );
		$this->assertSame( $single_p2, $bulk[ $p2->get_id() ] ?? array() );
		$this->assertSame( $single_v, $bulk[ $vid ] ?? array() );
	}

	public function test_bulk_history_empty_input_returns_empty() {
		$this->assertSame( array(), WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_items_bulk( array(), array() ) );
	}

	public function test_bulk_history_id_with_no_history_absent_from_result() {
		$product = $this->create_simple_product();
		$bulk    = WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_items_bulk( array( $product->get_id() ), array() );
		$this->assertArrayNotHasKey( $product->get_id(), $bulk );
	}

	/**
	 * Query-count parity/flatness at N=10/50/100 items -- at most 2 queries
	 * total (1 product branch + 1 variation branch) regardless of item count.
	 */
	private function count_history_table_queries( array $product_ids, array $variation_ids ): int {
		$lines_table = WC_Inventory_Overview_Purchase_Order_Lines::table_name();
		$hits        = array();
		$counter     = static function ( $query ) use ( $lines_table, &$hits ) {
			if ( false !== strpos( $query, $lines_table ) && false !== stripos( $query, 'SELECT' ) ) {
				$hits[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $counter );
		WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_items_bulk( $product_ids, $variation_ids );
		remove_filter( 'query', $counter );
		return count( $hits );
	}

	public function test_bulk_history_query_count_flat_at_10_50_100() {
		$ids_10 = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$p = $this->create_simple_product();
			$this->seed_history( $p->get_id(), 0 );
			$ids_10[] = $p->get_id();
		}
		$n_at_10 = $this->count_history_table_queries( $ids_10, array() );

		$ids_50 = $ids_10;
		for ( $i = 0; $i < 40; $i++ ) {
			$p = $this->create_simple_product();
			$this->seed_history( $p->get_id(), 0 );
			$ids_50[] = $p->get_id();
		}
		$n_at_50 = $this->count_history_table_queries( $ids_50, array() );

		$ids_100 = $ids_50;
		for ( $i = 0; $i < 50; $i++ ) {
			$p = $this->create_simple_product();
			$this->seed_history( $p->get_id(), 0 );
			$ids_100[] = $p->get_id();
		}
		$n_at_100 = $this->count_history_table_queries( $ids_100, array() );

		$this->assertSame( 1, $n_at_10, 'Product-only branch: exactly 1 query.' );
		$this->assertSame( $n_at_10, $n_at_50 );
		$this->assertSame( $n_at_10, $n_at_100 );
	}

	public function test_bulk_history_query_count_both_branches_at_most_2() {
		$product = $this->create_simple_product();
		$this->seed_history( $product->get_id(), 0 );

		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1' ) ) );
		wc_delete_product_transients( $variable->get_id() );
		$vid = (int) ( wc_get_product( $variable->get_id() ) )->get_children()[0];
		$this->seed_history( $variable->get_id(), $vid );

		$n = $this->count_history_table_queries( array( $product->get_id() ), array( $vid ) );
		$this->assertSame( 2, $n, 'Both branches present: exactly 2 queries (1 per branch), never more.' );
	}

	// -----------------------------------------------------------------
	// EXPLAIN evidence (§14.1, strengthened acceptance criteria)
	// -----------------------------------------------------------------

	/**
	 * Bulk-insert a large, realistic-volume background history table
	 * directly via SQL (bypassing the full PO_Service lifecycle for speed --
	 * this is purely to give MySQL's cost-based optimizer a table large
	 * enough that its index-vs-scan decision is representative of a real
	 * merchant's purchasing history; a synthetic 200-row table is too small
	 * for EXPLAIN evidence to mean anything, since MySQL legitimately
	 * prefers a full scan over an index on a table that small regardless of
	 * query shape). One committed ('placed') PO + one line per background
	 * row, spread across a wide range of distinct product/variation ids so
	 * the target N=100 IN (...) list is a genuinely small, selective subset
	 * of a much larger table.
	 *
	 * @param int $n Number of background PO+line rows to insert.
	 */
	private function seed_large_background_history( int $n ): void {
		global $wpdb;
		$orders_table = WC_Inventory_Overview_Purchase_Orders::table_name();
		$lines_table  = WC_Inventory_Overview_Purchase_Order_Lines::table_name();
		$supplier     = $this->create_supplier();

		$batch = 500;
		for ( $offset = 0; $offset < $n; $offset += $batch ) {
			$count        = min( $batch, $n - $offset );
			$order_values = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$k              = $offset + $i;
				$order_values[] = $wpdb->prepare(
					'(%s,%d,%s,%s,%s,%s,%s)',
					'BG-PO-' . $k . '-' . wp_generate_password( 6, false ),
					$supplier['id'],
					'placed',
					'2026-01-01',
					'EUR',
					current_time( 'mysql', true ),
					current_time( 'mysql', true )
				);
			}
			$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- each value tuple is individually prepared above.
				"INSERT INTO {$orders_table} (po_number, supplier_id, status, order_date, currency, created_at, updated_at) VALUES " . implode( ',', $order_values ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			$first_id = (int) $wpdb->insert_id;

			$line_values = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$k               = $offset + $i;
				$po_id           = $first_id + $i;
				// Wide spread of synthetic product ids -- far outside real
				// post-id range, but the pol table has no FK, so this is a
				// safe, fast way to populate a realistic-cardinality index.
				$fake_product_id = 900000 + $k;
				$line_values[]   = $wpdb->prepare(
					'(%d,%d,%d,%d,%s,%s)',
					$po_id,
					$fake_product_id,
					0,
					1,
					current_time( 'mysql', true ),
					current_time( 'mysql', true )
				);
			}
			$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- each value tuple is individually prepared above.
				"INSERT INTO {$lines_table} (po_id, product_id, variation_id, qty_ordered, created_at, updated_at) VALUES " . implode( ',', $line_values ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}
	}

	/**
	 * Seed N=100 target items with committed history each, on top of a
	 * large (10,000-row) realistic background history table, then run
	 * EXPLAIN against both branches' actual SQL shape and record type/key/
	 * possible_keys/rows/Extra. Asserts the strengthened §14.1 criteria:
	 * key not null, type != ALL for both branches; the product branch's
	 * plan must not be keyed on the low-selectivity variation_id=0 constant
	 * instead of product_id IN (...).
	 */
	/**
	 * @group performance
	 */
	public function test_explain_evidence_both_branches_at_n_100() {
		global $wpdb;

		$this->seed_large_background_history( 10000 );
		// Let MySQL's optimizer statistics reflect the just-inserted volume.
		$wpdb->query( 'ANALYZE TABLE ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'ANALYZE TABLE ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$product_ids   = array();
		$variation_ids = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$p = $this->create_simple_product();
			$this->seed_history( $p->get_id(), 0 );
			$product_ids[] = $p->get_id();
		}
		$variable = $this->create_variable_product(
			array(),
			array_fill( 0, 5, array( 'name' => 'EV' ) )
		);
		wc_delete_product_transients( $variable->get_id() );
		$children = ( wc_get_product( $variable->get_id() ) )->get_children();
		for ( $i = 0; $i < 100; $i++ ) {
			$vid             = $children[ $i % count( $children ) ];
			$this->seed_history( $variable->get_id(), $vid, '2026-0' . ( 1 + ( $i % 9 ) ) . '-01' );
			$variation_ids[] = $vid;
		}
		$variation_ids = array_values( array_unique( $variation_ids ) );

		$lines  = WC_Inventory_Overview_Purchase_Order_Lines::table_name();
		$orders = WC_Inventory_Overview_Purchase_Orders::table_name();

		$product_placeholders   = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
		$variation_placeholders = implode( ',', array_fill( 0, count( $variation_ids ), '%d' ) );

		$variation_sql = "EXPLAIN SELECT pol.variation_id AS item_id, po.supplier_id AS supplier_id, MAX(po.order_date) AS latest_order_date
			FROM {$lines} pol
			INNER JOIN {$orders} po ON po.id = pol.po_id
			WHERE po.status IN ('placed', 'partially_received', 'received', 'closed_short')
				AND pol.variation_id IN ({$variation_placeholders})
			GROUP BY pol.variation_id, po.supplier_id
			ORDER BY pol.variation_id ASC, latest_order_date DESC";

		$product_sql = "EXPLAIN SELECT pol.product_id AS item_id, po.supplier_id AS supplier_id, MAX(po.order_date) AS latest_order_date
			FROM {$lines} pol
			INNER JOIN {$orders} po ON po.id = pol.po_id
			WHERE po.status IN ('placed', 'partially_received', 'received', 'closed_short')
				AND pol.variation_id = 0 AND pol.product_id IN ({$product_placeholders})
			GROUP BY pol.product_id, po.supplier_id
			ORDER BY pol.product_id ASC, latest_order_date DESC";

		$variation_plan = $wpdb->get_results( $wpdb->prepare( $variation_sql, $variation_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$product_plan   = $wpdb->get_results( $wpdb->prepare( $product_sql, $product_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		fwrite( STDERR, "\n[M24 WP-M24-4 EXPLAIN evidence, N=100]\n" );
		fwrite( STDERR, "Variation branch:\n" );
		foreach ( $variation_plan as $row ) {
			fwrite( STDERR, '  ' . wp_json_encode( $row ) . "\n" );
		}
		fwrite( STDERR, "Product branch:\n" );
		foreach ( $product_plan as $row ) {
			fwrite( STDERR, '  ' . wp_json_encode( $row ) . "\n" );
		}

		foreach ( $variation_plan as $row ) {
			if ( isset( $row['table'] ) && 'pol' === $row['table'] ) {
				$this->assertNotSame( 'ALL', $row['type'], 'Variation branch pol access must not be a full table scan.' );
				$this->assertNotNull( $row['key'], 'Variation branch pol access must use an index (key must not be null).' );
			}
		}

		foreach ( $product_plan as $row ) {
			if ( isset( $row['table'] ) && 'pol' === $row['table'] ) {
				$this->assertNotSame( 'ALL', $row['type'], 'Product branch pol access must not be a full table scan.' );
				$this->assertNotNull( $row['key'], 'Product branch pol access must use an index (key must not be null).' );
				// Strengthened criterion (§14.1 Rev.3): must not be keyed on
				// the low-selectivity variation_id column when product_id
				// IN (...) is the actually-selective predicate.
				$this->assertNotSame( 'variation_id', $row['key'], 'Product branch must not key on the low-selectivity variation_id=0 constant instead of product_id IN (...).' );
			}
		}

		$this->assertNotEmpty( $variation_plan );
		$this->assertNotEmpty( $product_plan );
	}

	// -----------------------------------------------------------------
	// Replenishment_Defaults::get_bulk() parity + query count
	// -----------------------------------------------------------------

	public function test_defaults_bulk_parity_with_single_item_calls() {
		$p1 = $this->create_simple_product();
		$p2 = $this->create_simple_product();
		$p3 = $this->create_simple_product();

		$supplier = $this->create_supplier();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $p1->get_id(), $supplier['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $p1->get_id(), '12.5' );
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $p2->get_id(), '3' );
		// $p3 has neither set.

		$bulk = WC_Inventory_Overview_Replenishment_Defaults::get_bulk( array( $p1->get_id(), $p2->get_id(), $p3->get_id() ) );

		foreach ( array( $p1, $p2, $p3 ) as $p ) {
			$this->assertSame(
				array(
					'preferred_supplier_id' => WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $p->get_id() ),
					'default_qty'           => WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $p->get_id() ),
				),
				$bulk[ $p->get_id() ]
			);
		}

		$this->assertSame( (int) $supplier['id'], $bulk[ $p1->get_id() ]['preferred_supplier_id'] );
		$this->assertEqualsWithDelta( 12.5, $bulk[ $p1->get_id() ]['default_qty'], 0.0001 );
		$this->assertSame( 0, $bulk[ $p3->get_id() ]['preferred_supplier_id'] );
		$this->assertEqualsWithDelta( 0.0, $bulk[ $p3->get_id() ]['default_qty'], 0.0001 );
	}

	public function test_defaults_bulk_empty_input_returns_empty() {
		$this->assertSame( array(), WC_Inventory_Overview_Replenishment_Defaults::get_bulk( array() ) );
	}

	private function count_postmeta_queries_for_defaults_bulk( array $ids ): int {
		global $wpdb;
		$hits    = array();
		$counter = static function ( $query ) use ( $wpdb, &$hits ) {
			if ( false !== strpos( $query, $wpdb->postmeta ) && false !== stripos( $query, 'SELECT' ) ) {
				$hits[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $counter );
		WC_Inventory_Overview_Replenishment_Defaults::get_bulk( $ids );
		remove_filter( 'query', $counter );
		return count( $hits );
	}

	public function test_defaults_bulk_query_count_flat_at_10_50_100() {
		$ids_10 = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$ids_10[] = $this->create_simple_product()->get_id();
		}
		wp_cache_flush();
		$n_at_10 = $this->count_postmeta_queries_for_defaults_bulk( $ids_10 );

		$ids_50 = $ids_10;
		for ( $i = 0; $i < 40; $i++ ) {
			$ids_50[] = $this->create_simple_product()->get_id();
		}
		wp_cache_flush();
		$n_at_50 = $this->count_postmeta_queries_for_defaults_bulk( $ids_50 );

		$ids_100 = $ids_50;
		for ( $i = 0; $i < 50; $i++ ) {
			$ids_100[] = $this->create_simple_product()->get_id();
		}
		wp_cache_flush();
		$n_at_100 = $this->count_postmeta_queries_for_defaults_bulk( $ids_100 );

		$this->assertSame( 1, $n_at_10, 'update_meta_cache() must issue exactly 1 query for the whole batch.' );
		$this->assertSame( $n_at_10, $n_at_50 );
		$this->assertSame( $n_at_10, $n_at_100 );
	}

	// -----------------------------------------------------------------
	// INV-M24-8/9: single-item getters remain byte-for-byte unmodified.
	// -----------------------------------------------------------------

	public function test_single_item_getters_unaffected_by_bulk_addition() {
		$product = $this->create_simple_product();
		$this->assertSame( 0, WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $product->get_id() ) );
		$this->assertEqualsWithDelta( 0.0, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $product->get_id() ), 0.0001 );

		$product2 = $this->create_simple_product();
		$this->assertSame( array(), WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_item( $product2->get_id() ) );
	}
}
