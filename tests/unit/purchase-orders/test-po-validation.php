<?php
/**
 * M2-B: validation, quantities, expected inheritance, INV-8.
 *
 * @package WC_Inventory_Overview_Tests
 */

// phpcs:disable WordPress.Files.FileName -- Matches established tests/unit/purchase-orders/test-*.php naming.

/**
 * Validation and INV-8 tests.
 */
class Test_WC_IO_PO_Validation extends WC_Inventory_Overview_Test_Case {

	/**
	 * Outstanding floors at zero (full INV-4 formula, M5: ordered − received − cancelled).
	 */
	public function test_outstanding_quantity() {
		$this->assertSame( 3.0, WC_Inventory_Overview_PO_Quantities::outstanding( 5, 0, 2 ) );
		$this->assertSame( 0.0, WC_Inventory_Overview_PO_Quantities::outstanding( 2, 0, 5 ) );
		$this->assertSame( 0.0, WC_Inventory_Overview_PO_Quantities::outstanding( 0, 0, 0 ) );
		// M5: received also reduces outstanding, same as cancelled.
		$this->assertSame( 4.0, WC_Inventory_Overview_PO_Quantities::outstanding( 10, 6, 0 ) );
		$this->assertSame( 0.0, WC_Inventory_Overview_PO_Quantities::outstanding( 10, 6, 4 ) );
		$this->assertSame( 0.0, WC_Inventory_Overview_PO_Quantities::outstanding( 10, 15, 0 ), 'Over-receipt floors at zero, never negative (D5).' );
	}

	/**
	 * Line quantity and cost rules.
	 */
	public function test_line_quantity_and_cost_validation() {
		$this->assertTrue( WC_Inventory_Overview_PO_Quantities::validate_quantities( 1, 0 ) );
		$this->assertWPError( WC_Inventory_Overview_PO_Quantities::validate_quantities( 0, 0 ) );
		$this->assertWPError( WC_Inventory_Overview_PO_Quantities::validate_quantities( 1, -1 ) );
		$this->assertWPError( WC_Inventory_Overview_PO_Quantities::validate_quantities( 1, 2 ) );
		$this->assertTrue( WC_Inventory_Overview_PO_Quantities::validate_unit_cost( 0 ) );
		$this->assertWPError( WC_Inventory_Overview_PO_Quantities::validate_unit_cost( -0.01 ) );
	}

	/**
	 * Header validation rejects unknown status/currency/confidence.
	 */
	public function test_header_validation() {
		$this->assertTrue(
			WC_Inventory_Overview_PO_Validation::validate_header(
				array(
					'status'              => 'draft',
					'currency'            => 'EUR',
					'expected_confidence' => 'estimated',
					'expected_date'       => '2026-09-01',
				)
			)
		);
		// M5 revision: 'received' is now a legitimate status value (schema v9) and
		// no longer triggers this rejection — a genuinely unknown status is used
		// instead (M5 implementation plan §Testing "M4 guard-revision audit").
		$this->assertWPError(
			WC_Inventory_Overview_PO_Validation::validate_header( array( 'status' => 'bogus_status' ) )
		);
		$this->assertTrue(
			WC_Inventory_Overview_PO_Validation::validate_header( array( 'status' => 'received' ) ),
			'received is now valid header-validation vocabulary (M5).'
		);
		$this->assertWPError(
			WC_Inventory_Overview_PO_Validation::validate_header( array( 'currency' => 'GBP' ) )
		);
		$this->assertWPError(
			WC_Inventory_Overview_PO_Validation::validate_header( array( 'expected_confidence' => 'maybe' ) )
		);
	}

	/**
	 * Expected date and confidence inheritance.
	 */
	public function test_expected_inheritance() {
		$this->assertSame(
			'2026-09-10',
			WC_Inventory_Overview_PO_Expected::effective_date( '2026-09-10', '2026-09-01' )
		);
		$this->assertSame(
			'2026-09-01',
			WC_Inventory_Overview_PO_Expected::effective_date( null, '2026-09-01' )
		);
		$this->assertNull( WC_Inventory_Overview_PO_Expected::effective_date( '', null ) );

		$this->assertSame(
			'exact',
			WC_Inventory_Overview_PO_Expected::effective_confidence( 'exact', 'estimated' )
		);
		$this->assertSame(
			'estimated',
			WC_Inventory_Overview_PO_Expected::effective_confidence( null, 'estimated' )
		);
		$this->assertSame(
			'unknown',
			WC_Inventory_Overview_PO_Expected::effective_confidence( '', '' )
		);
	}

	/**
	 * Confidence vocabulary.
	 */
	public function test_confidence_vocabulary() {
		$this->assertSame( array( 'exact', 'estimated', 'unknown' ), WC_Inventory_Overview_PO_Confidence::all() );
		$this->assertWPError( WC_Inventory_Overview_PO_Confidence::validate( 'fuzzy' ) );
	}

	/**
	 * INV-8 accepts simple stock-managed products.
	 */
	public function test_inv8_accepts_simple_product() {
		$product = $this->create_simple_product( array( 'name' => 'PO Simple' ) );
		$result  = WC_Inventory_Overview_PO_Product_Validator::validate( $product->get_id(), 0 );
		$this->assertIsArray( $result );
		$this->assertSame( $product->get_id(), $result['product_id'] );
		$this->assertSame( 0, $result['variation_id'] );
	}

	/**
	 * INV-8 rejects variable parents.
	 */
	public function test_inv8_rejects_variable_parent() {
		$parent = $this->create_variable_product(
			array( 'name' => 'PO Variable' ),
			array(
				array(
					'name'      => 'Size M',
					'stock_qty' => 3,
				),
			)
		);
		$result = WC_Inventory_Overview_PO_Product_Validator::validate( $parent->get_id(), 0 );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_po_product_type', $result->get_error_code() );
	}

	/**
	 * INV-8 accepts variations and normalizes parent/variation ids.
	 */
	public function test_inv8_accepts_variation() {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'PO Variable 2' );
		$parent->set_status( 'publish' );
		$parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_status( 'publish' );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( 5 );
		$variation->save();

		$result = WC_Inventory_Overview_PO_Product_Validator::validate( $parent->get_id(), $variation->get_id() );
		$this->assertIsArray( $result );
		$this->assertSame( $parent->get_id(), $result['product_id'] );
		$this->assertSame( $variation->get_id(), $result['variation_id'] );
	}

	/**
	 * INV-8 rejects parent/variation mismatches.
	 */
	public function test_inv8_rejects_mismatch() {
		$parent_a = new WC_Product_Variable();
		$parent_a->set_name( 'Parent A' );
		$parent_a->set_status( 'publish' );
		$parent_a->save();

		$parent_b = new WC_Product_Variable();
		$parent_b->set_name( 'Parent B' );
		$parent_b->set_status( 'publish' );
		$parent_b->save();

		$var_b = new WC_Product_Variation();
		$var_b->set_parent_id( $parent_b->get_id() );
		$var_b->set_status( 'publish' );
		$var_b->set_manage_stock( true );
		$var_b->set_stock_quantity( 1 );
		$var_b->save();

		$result = WC_Inventory_Overview_PO_Product_Validator::validate( $parent_a->get_id(), $var_b->get_id() );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_po_product_mismatch', $result->get_error_code() );
	}

	/**
	 * INV-8 rejects products without stock management.
	 */
	public function test_inv8_rejects_unmanaged_stock() {
		$product = $this->create_simple_product(
			array(
				'name'         => 'No Stock Mgmt',
				'manage_stock' => false,
			)
		);
		$result  = WC_Inventory_Overview_PO_Product_Validator::validate( $product->get_id(), 0 );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_po_product_stock', $result->get_error_code() );
	}

	/**
	 * Place validation requires lines, active supplier, and valid currency.
	 */
	public function test_validate_for_place() {
		$product  = $this->create_simple_product();
		$supplier = $this->create_supplier(
			array(
				'name'             => 'Place Supplier',
				'default_currency' => 'EUR',
			)
		);

		$header = array(
			'status'   => 'draft',
			'currency' => 'EUR',
		);
		$lines  = array(
			array(
				'product_id'    => $product->get_id(),
				'variation_id'  => 0,
				'qty_ordered'   => 2,
				'qty_cancelled' => 0,
				'unit_cost'     => 1.25,
			),
		);

		$this->assertTrue( WC_Inventory_Overview_PO_Validation::validate_for_place( $header, $lines, $supplier ) );

		$this->assertWPError( WC_Inventory_Overview_PO_Validation::validate_for_place( $header, array(), $supplier ) );
		$this->assertWPError( WC_Inventory_Overview_PO_Validation::validate_for_place( $header, $lines, null ) );

		$archived           = $supplier;
		$archived['status'] = WC_Inventory_Overview_Suppliers::STATUS_ARCHIVED;
		$this->assertWPError( WC_Inventory_Overview_PO_Validation::validate_for_place( $header, $lines, $archived ) );

		$placed_header           = $header;
		$placed_header['status'] = 'placed';
		$this->assertWPError( WC_Inventory_Overview_PO_Validation::validate_for_place( $placed_header, $lines, $supplier ) );
	}

	/**
	 * No receiving terminology in quantity helpers.
	 */
	public function test_no_receiving_in_quantity_api() {
		$methods = get_class_methods( 'WC_Inventory_Overview_PO_Quantities' );
		$this->assertNotContains( 'qty_received', $methods );
		$this->assertFalse( method_exists( 'WC_Inventory_Overview_PO_Quantities', 'received' ) );
	}
}
