<?php
/**
 * M24 WP-M24-2: WC_Inventory_Overview_Repository::query_products()'s
 * additive `include` passthrough (§9.2).
 *
 * INV-M24-13: existing pagination-based callers are unaffected -- proven by
 * a plain unscoped call still returning paginated results.
 * BR-M24-20: the `include`-scoped discovery path costs a flat, N-independent,
 * cold-cache-measured SQL count, never a per-id lookup and never the
 * catalog-wide page loop -- measured (not pre-declared) at N=10/50/100.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Repository_Include_Passthrough extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * INV-M24-13: the additive `include` param must not change behavior for
	 * existing pagination-based callers that never pass it.
	 */
	public function test_unscoped_pagination_unaffected() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->create_simple_product( array( 'stock_qty' => 1 ) );
		}

		$r = WC_Inventory_Overview_Repository::query_products( array( 'per_page' => 3, 'paged' => 1 ) );

		$this->assertArrayHasKey( 'products', $r );
		$this->assertArrayHasKey( 'total', $r );
		$this->assertArrayHasKey( 'max_num_pages', $r );
		$this->assertCount( 3, $r['products'], 'Unscoped pagination must be unaffected by the additive include param.' );
		$this->assertGreaterThanOrEqual( 5, $r['total'] );
	}

	/**
	 * The `include` passthrough returns exactly the requested ids (bounded,
	 * no pagination math), one call regardless of how many ids.
	 */
	public function test_include_returns_exactly_requested_ids() {
		$ids = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$ids[] = $this->create_simple_product( array( 'stock_qty' => 1 ) )->get_id();
		}
		// A distractor product NOT in the include list.
		$this->create_simple_product( array( 'stock_qty' => 1 ) );

		$r = WC_Inventory_Overview_Repository::query_products(
			array(
				'include'                    => $ids,
				'sellable_stock_lines_only'  => true,
			)
		);

		$returned_ids = array_map(
			static function ( $p ) {
				return $p->get_id();
			},
			$r['products']
		);

		sort( $ids );
		sort( $returned_ids );
		$this->assertSame( $ids, $returned_ids, 'include must return exactly the requested ids, nothing more, nothing less.' );
	}

	/**
	 * BR-M24-20: cold-cache SQL count for the include-scoped path stays
	 * flat across N=10/50/100 -- never a per-id query.
	 */
	private function count_queries_for_include( array $ids ): int {
		wp_cache_flush();
		$hits    = array();
		$counter = static function ( $query ) use ( &$hits ) {
			if ( false !== stripos( $query, 'SELECT' ) ) {
				$hits[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $counter );
		WC_Inventory_Overview_Repository::query_products(
			array(
				'include'                   => $ids,
				'sellable_stock_lines_only' => true,
			)
		);
		remove_filter( 'query', $counter );

		return count( $hits );
	}

	public function test_include_query_count_flat_across_n_10_50_100() {
		$ids_10 = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$ids_10[] = $this->create_simple_product( array( 'stock_qty' => 1 ) )->get_id();
		}
		$n_at_10 = $this->count_queries_for_include( $ids_10 );

		$ids_50 = $ids_10;
		for ( $i = 0; $i < 40; $i++ ) {
			$ids_50[] = $this->create_simple_product( array( 'stock_qty' => 1 ) )->get_id();
		}
		$n_at_50 = $this->count_queries_for_include( $ids_50 );

		$ids_100 = $ids_50;
		for ( $i = 0; $i < 50; $i++ ) {
			$ids_100[] = $this->create_simple_product( array( 'stock_qty' => 1 ) )->get_id();
		}
		$n_at_100 = $this->count_queries_for_include( $ids_100 );

		// Not pre-declared as exactly 1 (Rev. 3 correction, §9.2) -- the
		// requirement is flatness (does not scale with N), never a per-id
		// query pattern (asserted as: the query count at N=100 does not
		// exceed some small constant multiple of the N=10 count, and is
		// never within an order of magnitude of N itself, which a per-id
		// loop would produce).
		$this->assertLessThanOrEqual( $n_at_10 + 3, $n_at_50, 'Query count must not grow materially between N=10 and N=50 (BR-M24-20).' );
		$this->assertLessThanOrEqual( $n_at_10 + 3, $n_at_100, 'Query count must not grow materially between N=10 and N=100 (BR-M24-20).' );
		$this->assertLessThan( 100, $n_at_100, 'Query count at N=100 must be nowhere near N -- proves no per-id query pattern.' );
	}
}
