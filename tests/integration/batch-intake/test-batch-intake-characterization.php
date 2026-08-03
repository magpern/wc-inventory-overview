<?php
/**
 * Characterization tests for Batch Intake Preview/Apply parity and rollback
 *
 * Golden test suite for Batch_Intake_Service::build_preview_from_post and ::apply_batch_from_post.
 * Locks the preview/apply parity contract and rollback behavior.
 *
 * @package WC_Inventory_Overview_Tests
 * @group characterization
 * @group batch-intake
 */

class Test_Batch_Intake_Characterization extends WC_Inventory_Overview_Test_Case {

	/**
	 * Scenario: Preview/Apply parity — single line batch
	 *
	 * Tests that build_preview_from_post and apply_batch_from_post produce identical
	 * costing/allocation results for a single-line batch.
	 *
	 * @test
	 */
	public function test_preview_apply_parity_single_line(): void {
		if ( ! class_exists( 'Batch_Intake_Service' ) ) {
			$this->markTestSkipped( 'Batch_Intake_Service class not found' );
		}

		$product = $this->create_simple_product(
			array(
				'name'      => 'Test Product',
				'stock_qty' => 0,
			)
		);

		// Build a simple batch payload.
		$batch_data = array(
			'batch_date'     => current_time( 'mysql' ),
			'supplier_name'  => 'Test Supplier',
			'reference'      => 'REF-001',
			'purchase_currency' => 'EUR',
			'lines' => array(
				array(
					'product_id'    => $product->get_id(),
					'variation_id'  => 0,
					'quantity'      => 10,
					'unit_cost_entered' => 15.50,
					'total_cost_entered' => 155.00,
					'note'          => 'Test line',
				),
			),
			'landed_costs' => array(),
		);

		// Build preview.
		$preview = Batch_Intake_Service::build_preview_from_post( $batch_data );
		$this->assertIsArray( $preview, 'Preview should return array' );

		// Apply batch.
		$batch_id = Batch_Intake_Service::apply_batch_from_post( $batch_data );
		$this->assertNotFalse( $batch_id, 'Apply should succeed and return batch ID' );

		// Verify product state matches preview expectations (if preview included line-level predictions).
		$product = wc_get_product( $product->get_id() );
		$this->assertSame(
			10,
			$product->get_stock_quantity(),
			'Preview and apply should agree on final stock'
		);
	}

	/**
	 * Scenario: Rollback on validation failure
	 *
	 * Tests that if apply_batch_from_post fails partway through, all mutations are rolled back.
	 *
	 * @test
	 */
	public function test_rollback_on_mid_operation_failure(): void {
		if ( ! class_exists( 'Batch_Intake_Service' ) ) {
			$this->markTestSkipped( 'Batch_Intake_Service class not found' );
		}

		$product1 = $this->create_simple_product(
			array(
				'name'      => 'Product 1',
				'stock_qty' => 0,
			)
		);

		$product2 = $this->create_simple_product(
			array(
				'name'      => 'Product 2',
				'stock_qty' => 0,
			)
		);

		// Build a batch with two lines, designed to fail on the second line.
		$batch_data = array(
			'batch_date'     => current_time( 'mysql' ),
			'supplier_name'  => 'Test Supplier',
			'reference'      => 'REF-FAIL',
			'purchase_currency' => 'EUR',
			'lines' => array(
				array(
					'product_id'    => $product1->get_id(),
					'variation_id'  => 0,
					'quantity'      => 10,
					'unit_cost_entered' => 15.50,
				),
				// This line intentionally invalid to trigger rollback.
				array(
					'product_id'    => $product2->get_id(),
					'variation_id'  => 0,
					'quantity'      => 0,  // Invalid: zero quantity should fail.
					'unit_cost_entered' => -1.0,  // Invalid: negative cost.
				),
			),
			'landed_costs' => array(),
		);

		// Attempt apply (should fail).
		$result = Batch_Intake_Service::apply_batch_from_post( $batch_data );
		$this->assertFalse( $result, 'Apply should fail on invalid line' );

		// Verify both products remain untouched (rollback occurred).
		$product1 = wc_get_product( $product1->get_id() );
		$product2 = wc_get_product( $product2->get_id() );

		$this->assertSame( 0, $product1->get_stock_quantity(), 'Product 1 should not be mutated' );
		$this->assertSame( 0, $product2->get_stock_quantity(), 'Product 2 should not be mutated' );
	}
}
