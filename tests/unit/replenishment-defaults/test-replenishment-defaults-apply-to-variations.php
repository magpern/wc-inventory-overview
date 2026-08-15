<?php
/**
 * Unit tests for M26 WP-M26-1:
 * WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations().
 *
 * Covers AH–AL idempotent counters, validation atomicity (zero writes),
 * caps, foreign parent membership, ineligible bulk Set, field isolation,
 * and injected mid-write error_data (BR-M26-13/21/24/25).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Defaults_Apply_To_Variations extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		WC_Inventory_Overview_Replenishment_Defaults::reset_test_write_fail();
	}

	public function tearDown(): void {
		WC_Inventory_Overview_Replenishment_Defaults::reset_test_write_fail();
		parent::tearDown();
	}

	/**
	 * @return array{0:WC_Product_Variable,1:int[]}
	 */
	private function make_variable( int $count ): array {
		$variations = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$variations[] = array( 'name' => 'V' . $i );
		}
		$variable = $this->create_variable_product( array(), $variations );
		$variable = wc_get_product( $variable->get_id() );
		$children = array_map( 'intval', $variable->get_children() );
		$this->assertCount( $count, $children );
		return array( $variable, $children );
	}

	private function snapshot_meta( array $variation_ids ): array {
		$out = array();
		foreach ( $variation_ids as $id ) {
			$out[ $id ] = array(
				'supplier' => get_post_meta( $id, WC_Inventory_Overview_Replenishment_Defaults::META_PREFERRED_SUPPLIER, true ),
				'qty'      => get_post_meta( $id, WC_Inventory_Overview_Replenishment_Defaults::META_DEFAULT_QTY, true ),
			);
		}
		return $out;
	}

	// ---------------------------------------------------------------
	// AH–AL: idempotent counters
	// ---------------------------------------------------------------

	/** AH */
	public function test_set_supplier_when_all_already_equal_is_success() {
		list( $variable, $children ) = $this->make_variable( 3 );
		$supplier = $this->create_supplier();
		foreach ( $children as $vid ) {
			WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $vid, (int) $supplier['id'] );
		}

		$result = WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
			$variable->get_id(),
			$children,
			array(
				'update_preferred_supplier' => true,
				'preferred_supplier_id'     => (int) $supplier['id'],
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['variations_updated'] );
		$this->assertSame( 3, $result['supplier_updates'] );
		$this->assertSame( 0, $result['qty_updates'] );
	}

	/** AI */
	public function test_set_qty_when_all_already_equal_is_success() {
		list( $variable, $children ) = $this->make_variable( 2 );
		foreach ( $children as $vid ) {
			WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $vid, '10' );
		}

		$result = WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
			$variable->get_id(),
			$children,
			array(
				'update_default_qty' => true,
				'default_qty'        => '10',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['variations_updated'] );
		$this->assertSame( 0, $result['supplier_updates'] );
		$this->assertSame( 2, $result['qty_updates'] );
	}

	/** AJ */
	public function test_clear_supplier_when_some_already_absent_is_success() {
		list( $variable, $children ) = $this->make_variable( 3 );
		$supplier = $this->create_supplier();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $children[0], (int) $supplier['id'] );
		// children[1] and [2] remain unset.

		$result = WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
			$variable->get_id(),
			$children,
			array(
				'update_preferred_supplier' => true,
				'preferred_supplier_id'     => 0,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['variations_updated'] );
		$this->assertSame( 3, $result['supplier_updates'] );
		foreach ( $children as $vid ) {
			$this->assertSame( 0, WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $vid ) );
		}
	}

	/** AK */
	public function test_clear_qty_when_some_already_absent_is_success() {
		list( $variable, $children ) = $this->make_variable( 2 );
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $children[0], '5' );

		$result = WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
			$variable->get_id(),
			$children,
			array(
				'update_default_qty' => true,
				'default_qty'        => '',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['variations_updated'] );
		$this->assertSame( 2, $result['qty_updates'] );
		foreach ( $children as $vid ) {
			$this->assertSame( 0.0, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $vid ) );
		}
	}

	/** AL */
	public function test_mixed_change_and_already_equal_counts_all_targets() {
		list( $variable, $children ) = $this->make_variable( 3 );
		$supplier = $this->create_supplier();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $children[0], (int) $supplier['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $children[1], (int) $supplier['id'] );
		// children[2] unset — will change.

		$result = WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
			$variable->get_id(),
			$children,
			array(
				'update_preferred_supplier' => true,
				'preferred_supplier_id'     => (int) $supplier['id'],
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['variations_updated'] );
		$this->assertSame( 3, $result['supplier_updates'] );
		$this->assertNotInstanceOf( WP_Error::class, $result );
	}

	// ---------------------------------------------------------------
	// Validation atomicity / caps / identity / eligibility
	// ---------------------------------------------------------------

	public function test_validation_failure_writes_zero_meta() {
		list( $variable, $children ) = $this->make_variable( 2 );
		$before = $this->snapshot_meta( $children );

		$result = WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
			$variable->get_id(),
			$children,
			array(
				'update_default_qty' => true,
				'default_qty'        => '0',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( $before, $this->snapshot_meta( $children ) );
		$this->assertSame( '', get_post_meta( $variable->get_id(), WC_Inventory_Overview_Replenishment_Defaults::META_DEFAULT_QTY, true ) );
	}

	public function test_cap_over_100_rejected_with_zero_writes() {
		list( $variable, $children ) = $this->make_variable( 2 );
		$fake_ids = $children;
		for ( $i = 0; $i < 99; $i++ ) {
			$fake_ids[] = $children[0]; // duplicate ids still count toward the submitted list size.
		}
		$this->assertSame( 101, count( $fake_ids ) );
		$before = $this->snapshot_meta( $children );

		$result = WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
			$variable->get_id(),
			$fake_ids,
			array(
				'update_default_qty' => true,
				'default_qty'        => '3',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_replenishment_bulk_too_many', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot_meta( $children ) );
	}

	public function test_foreign_variation_rejected_before_write() {
		list( $variable_a, $children_a ) = $this->make_variable( 1 );
		list( $variable_b, $children_b ) = $this->make_variable( 1 );
		$supplier = $this->create_supplier();
		$before   = $this->snapshot_meta( array_merge( $children_a, $children_b ) );

		$result = WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
			$variable_a->get_id(),
			array( $children_a[0], $children_b[0] ),
			array(
				'update_preferred_supplier' => true,
				'preferred_supplier_id'     => (int) $supplier['id'],
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_replenishment_bulk_foreign_variation', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot_meta( array_merge( $children_a, $children_b ) ) );
	}

	public function test_simple_parent_rejected() {
		$simple   = $this->create_simple_product();
		$supplier = $this->create_supplier();

		$result = WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
			$simple->get_id(),
			array( $simple->get_id() ),
			array(
				'update_preferred_supplier' => true,
				'preferred_supplier_id'     => (int) $supplier['id'],
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_replenishment_bulk_invalid_parent', $result->get_error_code() );
	}

	public function test_bulk_set_rejects_archived_supplier_even_if_variations_already_hold_it() {
		list( $variable, $children ) = $this->make_variable( 2 );
		$supplier = $this->create_supplier();
		foreach ( $children as $vid ) {
			WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $vid, (int) $supplier['id'] );
		}
		WC_Inventory_Overview_Suppliers::archive( $supplier['id'] );
		$before = $this->snapshot_meta( $children );

		$result = WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
			$variable->get_id(),
			$children,
			array(
				'update_preferred_supplier' => true,
				'preferred_supplier_id'     => (int) $supplier['id'],
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_replenishment_supplier_ineligible', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot_meta( $children ), 'Validation failure must leave zero writes.' );
	}

	public function test_field_isolation_supplier_only_preserves_qty() {
		list( $variable, $children ) = $this->make_variable( 2 );
		$supplier_a = $this->create_supplier();
		$supplier_b = $this->create_supplier();
		foreach ( $children as $vid ) {
			WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $vid, '9' );
			WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $vid, (int) $supplier_a['id'] );
		}

		$result = WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
			$variable->get_id(),
			$children,
			array(
				'update_preferred_supplier' => true,
				'preferred_supplier_id'     => (int) $supplier_b['id'],
			)
		);

		$this->assertIsArray( $result );
		foreach ( $children as $vid ) {
			$this->assertSame( 9.0, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $vid ) );
			$this->assertSame( (int) $supplier_b['id'], WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $vid ) );
		}
	}

	public function test_field_isolation_qty_only_preserves_stale_supplier() {
		list( $variable, $children ) = $this->make_variable( 2 );
		$supplier = $this->create_supplier();
		foreach ( $children as $vid ) {
			WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $vid, (int) $supplier['id'] );
		}
		WC_Inventory_Overview_Suppliers::archive( $supplier['id'] );

		$result = WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
			$variable->get_id(),
			$children,
			array(
				'update_default_qty' => true,
				'default_qty'        => '4',
			)
		);

		$this->assertIsArray( $result );
		foreach ( $children as $vid ) {
			$this->assertSame( (int) $supplier['id'], WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $vid ) );
			$this->assertSame( 4.0, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $vid ) );
		}
	}

	public function test_never_writes_parent_meta() {
		list( $variable, $children ) = $this->make_variable( 1 );
		$supplier = $this->create_supplier();

		WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
			$variable->get_id(),
			$children,
			array(
				'update_preferred_supplier' => true,
				'preferred_supplier_id'     => (int) $supplier['id'],
				'update_default_qty'        => true,
				'default_qty'               => '6',
			)
		);

		$this->assertSame( '', get_post_meta( $variable->get_id(), WC_Inventory_Overview_Replenishment_Defaults::META_PREFERRED_SUPPLIER, true ) );
		$this->assertSame( '', get_post_meta( $variable->get_id(), WC_Inventory_Overview_Replenishment_Defaults::META_DEFAULT_QTY, true ) );
	}

	public function test_injected_mid_write_failure_reports_error_data_without_rollback() {
		list( $variable, $children ) = $this->make_variable( 3 );
		$supplier = $this->create_supplier();

		WC_Inventory_Overview_Replenishment_Defaults::set_test_write_fail(
			array( 'after_success_count' => 1 )
		);

		$result = WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
			$variable->get_id(),
			$children,
			array(
				'update_preferred_supplier' => true,
				'preferred_supplier_id'     => (int) $supplier['id'],
			)
		);

		$this->assertWPError( $result );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 1, $data['variations_updated'] );
		$this->assertSame( 1, $data['supplier_updates'] );
		$this->assertSame( 0, $data['qty_updates'] );
		$this->assertSame( $children[1], $data['failed_variation_id'] );
		$this->assertSame( 'preferred_supplier', $data['failed_field'] );
		$this->assertSame( 'set', $data['failed_operation'] );

		// First variation written; later ones not rolled back (never written).
		$this->assertSame( (int) $supplier['id'], WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $children[0] ) );
		$this->assertSame( 0, WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $children[1] ) );
		$this->assertSame( 0, WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $children[2] ) );
	}

	public function test_save_default_qty_still_uses_shared_normalizer() {
		$product = $this->create_simple_product();
		$this->assertTrue( WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $product->get_id(), '1.23456789' ) );
		$this->assertEqualsWithDelta( 1.2346, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $product->get_id() ), 0.00001 );
		$this->assertWPError( WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $product->get_id(), '0' ) );
	}
}
