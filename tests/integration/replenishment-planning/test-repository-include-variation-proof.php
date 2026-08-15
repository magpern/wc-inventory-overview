<?php
/**
 * M24 WP-M24-1 §9.4/§24 item 3 (BR-M24-22): characterization proof of
 * WooCommerce's own `wc_get_products()` behavior for a mixed
 * simple/variable-parent/variation `include` fixture, under the exact same
 * $args shape `WC_Inventory_Overview_Repository::query_products()` will use
 * once WP-M24-2 wires the additive `include` passthrough (§9.2): `type` =>
 * [SIMPLE, VARIATION] (the same array `sellable_stock_lines_only` already
 * produces), `include` => the fixture ids, `paginate` => false.
 *
 * Written and run BEFORE any production file is touched (WP-M24-1) — calls
 * WooCommerce core's `wc_get_products()` directly, not any new M24 code, so
 * this is a fact about WooCommerce's own query semantics under this
 * repository's existing `sellable_stock_lines_only` args shape, not a claim
 * about plugin code. Its outcome decides which of the two approved bounded
 * discovery designs (§9.4) WP-M24-3 implements:
 *   A. one mixed `include` call (type => [SIMPLE, VARIATION])
 *   B. two `include` calls, split by type (SIMPLE / VARIATION)
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Repository_Include_Variation_Proof extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * The exact $args shape Repository::query_products() builds for the
	 * non-children, sellable_stock_lines_only branch, plus the additive
	 * `include` passthrough (§9.2's diff) applied by hand here, since this
	 * test must run before that diff exists in production code.
	 *
	 * @param int[] $include Ids to pass as WooCommerce's native `include`.
	 * @return array<int,WC_Product>|object
	 */
	private function query_with_include( array $include ) {
		$args = array(
			'status'   => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'return'   => 'objects',
			'paginate' => false,
			'type'     => array(
				\Automattic\WooCommerce\Enums\ProductType::SIMPLE,
				\Automattic\WooCommerce\Enums\ProductType::VARIATION,
			),
			'include'  => array_map( 'absint', $include ),
			'limit'    => count( $include ),
		);

		return wc_get_products( $args );
	}

	/**
	 * BR-M24-22 / §9.4: mixed fixture — one simple product, one variable
	 * parent (2 variations of its own, not included), and 2 concrete
	 * variations of a THIRD, separate variable product. Records exactly
	 * what wc_get_products() returns: types, ids, counts.
	 */
	public function test_mixed_include_fixture_return_semantics() {
		$simple = $this->create_simple_product( array( 'stock_qty' => 5 ) );

		// Variable parent whose OWN id is included -- must never be treated
		// as a purchasable candidate, per BR-M24-16/INV-8.
		$variable_parent = $this->create_variable_product(
			array(),
			array( array( 'name' => 'Unincluded child', 'stock_qty' => 5 ) )
		);

		// A separate variable product whose two variations ARE included
		// (parent id itself is NOT included).
		$variable_with_included_children = $this->create_variable_product(
			array(),
			array(
				array( 'name' => 'Included A', 'stock_qty' => 3 ),
				array( 'name' => 'Included B', 'stock_qty' => 4 ),
			)
		);
		wc_delete_product_transients( $variable_with_included_children->get_id() );
		$fresh_parent = wc_get_product( $variable_with_included_children->get_id() );
		$children     = $fresh_parent->get_children();
		$this->assertCount( 2, $children, 'Fixture setup: expected exactly 2 variations.' );
		list( $variation_a_id, $variation_b_id ) = $children;

		$include = array(
			$simple->get_id(),
			$variable_parent->get_id(),
			$variation_a_id,
			$variation_b_id,
		);

		$result = $this->query_with_include( $include );

		$products = is_object( $result ) && isset( $result->products ) ? $result->products : $result;
		$this->assertIsArray( $products, 'wc_get_products() with paginate=>false must still return an array of products.' );

		$by_id = array();
		foreach ( $products as $p ) {
			$this->assertInstanceOf( WC_Product::class, $p );
			$by_id[ $p->get_id() ] = $p;
		}

		// Required end semantic (§9.4):
		// 1. the simple product id -> returned, as a simple WC_Product.
		$this->assertArrayHasKey( $simple->get_id(), $by_id, 'Simple product must be discoverable via the mixed include call.' );
		$this->assertTrue( $by_id[ $simple->get_id() ]->is_type( 'simple' ) );

		// 2. each variation id -> returned, as that exact WC_Product_Variation.
		$this->assertArrayHasKey( $variation_a_id, $by_id, 'Variation A must be discoverable via the mixed include call, not silently dropped.' );
		$this->assertArrayHasKey( $variation_b_id, $by_id, 'Variation B must be discoverable via the mixed include call, not silently dropped.' );
		$this->assertTrue( $by_id[ $variation_a_id ]->is_type( 'variation' ), 'Variation A must be returned as an actual WC_Product_Variation, not collapsed to its parent.' );
		$this->assertTrue( $by_id[ $variation_b_id ]->is_type( 'variation' ), 'Variation B must be returned as an actual WC_Product_Variation, not collapsed to its parent.' );
		$this->assertSame( $variable_with_included_children->get_id(), $by_id[ $variation_a_id ]->get_parent_id() );

		// 3. the variable parent id -> excluded from what a purchasable-
		// candidate consumer would treat as eligible: either never
		// returned, or returned but is_type('variable') so downstream
		// managing_stock()/eligibility filtering excludes it. Record which.
		if ( isset( $by_id[ $variable_parent->get_id() ] ) ) {
			$this->assertTrue(
				$by_id[ $variable_parent->get_id() ]->is_type( 'variable' ),
				'If the variable parent IS returned, it must still be identifiable and filterable as a variable product (never silently masquerading as purchasable).'
			);
			// A variable parent never passes managing_stock() in this
			// plugin's existing model -- confirms the downstream filter
			// (already relied on elsewhere in this codebase) still excludes
			// it even if wc_get_products() returns the row.
			$this->assertFalse( $by_id[ $variable_parent->get_id() ]->managing_stock(), 'A variable parent must never independently pass managing_stock().' );
		} else {
			// Acceptable alternative outcome: never returned at all.
			$this->assertTrue( true, 'Variable parent was not returned by the mixed include call -- acceptable per §9.4.' );
		}

		// Unincluded sibling variation of the variable parent must never
		// leak into the result merely because its parent's id was included.
		$parent_children = ( wc_get_product( $variable_parent->get_id() ) )->get_children();
		foreach ( $parent_children as $uninc_child_id ) {
			$this->assertArrayNotHasKey( (int) $uninc_child_id, $by_id, 'A variation whose id was never in the include list must not appear merely because its parent id was included.' );
		}

		// DECISION RECORD (read by WP-M24-3): if this assertion block above
		// passes in full (simple discoverable, both variations discoverable
		// AS variations, variable parent excluded-or-filterable), Design A
		// (one mixed include call) is proven sufficient and is the design
		// WP-M24-3 implements. This comment is the citable evidence trail;
		// the implementation plan's outcome is recorded again in
		// docs/checklists/m24-release-readiness.md at freeze.
		$this->assertArrayHasKey( $variation_a_id, $by_id );
		$this->assertArrayHasKey( $variation_b_id, $by_id );
		$this->assertArrayHasKey( $simple->get_id(), $by_id );
	}

	/**
	 * Sanity check: an include list containing ONLY variation ids (no
	 * simple products) still returns them as variations under the same
	 * mixed `type` array -- proves the `type` array itself doesn't
	 * accidentally require at least one simple-product id to "unlock"
	 * variation-type results.
	 */
	public function test_variation_only_include_list() {
		$variable = $this->create_variable_product(
			array(),
			array(
				array( 'name' => 'Solo A', 'stock_qty' => 1 ),
				array( 'name' => 'Solo B', 'stock_qty' => 1 ),
			)
		);
		wc_delete_product_transients( $variable->get_id() );
		$fresh    = wc_get_product( $variable->get_id() );
		$children = $fresh->get_children();

		$result   = $this->query_with_include( $children );
		$products = is_object( $result ) && isset( $result->products ) ? $result->products : $result;

		$ids = array_map( static function ( $p ) {
			return $p->get_id();
		}, $products );

		foreach ( $children as $vid ) {
			$this->assertContains( (int) $vid, $ids, 'Every variation id in a variation-only include list must be returned.' );
		}
	}
}
