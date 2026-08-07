<?php
/**
 * Characterization test for the M6 landed-cost-type extraction (WP-M6-2):
 * WC_Inventory_Overview_Landed_Cost_Types, Goods_Receipt_Costing, and the
 * now-deprecated Batch_Intake_Service delegation must all agree on the exact
 * same seven cost-type slugs and labels — the extraction must be
 * behavior-preserving, not a redesign.
 *
 * @package WC_Inventory_Overview_Tests
 * @group characterization
 */

class Test_WC_IO_Landed_Cost_Types_Characterization extends WP_UnitTestCase {

	/**
	 * @return array<string,string>
	 */
	private function golden_labels(): array {
		return array(
			'shipping'              => 'Shipping',
			'customs_vat'           => 'Customs / VAT',
			'exchange_fee'          => 'Exchange fee',
			'crypto_transfer_fee'   => 'Crypto transfer fee',
			'payment_processor_fee' => 'Payment processor fee',
			'bank_fee'              => 'Bank fee',
			'miscellaneous'         => 'Miscellaneous',
		);
	}

	public function test_landed_cost_types_matches_golden_vocabulary() {
		$this->assertSame( $this->golden_labels(), WC_Inventory_Overview_Landed_Cost_Types::landed_cost_type_labels() );
		$this->assertSame( array_keys( $this->golden_labels() ), WC_Inventory_Overview_Landed_Cost_Types::allowed_cost_types() );
	}

	public function test_goods_receipt_costing_delegates_to_extracted_class_unchanged() {
		$this->assertSame(
			WC_Inventory_Overview_Landed_Cost_Types::landed_cost_type_labels(),
			WC_Inventory_Overview_Goods_Receipt_Costing::landed_cost_type_labels()
		);
		$this->assertSame(
			WC_Inventory_Overview_Landed_Cost_Types::allowed_cost_types(),
			WC_Inventory_Overview_Goods_Receipt_Costing::allowed_cost_types()
		);
	}

	public function test_deprecated_batch_intake_service_wrapper_still_agrees() {
		$this->assertSame(
			WC_Inventory_Overview_Landed_Cost_Types::landed_cost_type_labels(),
			WC_Inventory_Overview_Batch_Intake_Service::landed_cost_type_labels()
		);
		$this->assertSame(
			WC_Inventory_Overview_Landed_Cost_Types::allowed_cost_types(),
			WC_Inventory_Overview_Batch_Intake_Service::allowed_cost_types()
		);
	}

	/**
	 * Goods_Receipt_Costing must no longer cross-reference Batch_Intake_Service
	 * at all — the hidden-coupling remediation trigger this extraction closes.
	 */
	public function test_goods_receipt_costing_no_longer_references_batch_intake_service() {
		$src = (string) file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-goods-receipt-costing.php' );
		$this->assertStringNotContainsString( 'WC_Inventory_Overview_Batch_Intake_Service::', $src );
	}
}
