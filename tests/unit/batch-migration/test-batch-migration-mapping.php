<?php
/**
 * Pure field-mapping tests for WC_Inventory_Overview_Batch_Migration_Service.
 *
 * map_header()/map_line()/map_cost() are pure functions of their input row
 * (map_line() also does a wc_get_product() lookup for the best-effort
 * sku/name snapshot, requiring the WP/WC test harness, but performs no
 * writes). Tested here against fixture arrays shaped exactly like real
 * wc_io_purchase_batches(_lines|_costs) rows, independent of the
 * transactional migrate_batch() write path (covered separately under
 * tests/integration).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Batch_Migration_Mapping extends WC_Inventory_Overview_Test_Case {

	/**
	 * @return array<string,mixed>
	 */
	private function fixture_batch_row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                       => 7,
				'supplier_name'            => 'Acme Supplies',
				'reference'                => 'INV-2025-001',
				'purchase_currency'        => 'USD',
				'exchange_rate_to_eur'     => '0.92000000',
				'exchange_rate_date'       => '2025-03-01',
				'product_subtotal_entered' => '200.0000',
				'landed_total_entered'     => '20.0000',
				'batch_total_entered'      => '220.0000',
				'product_subtotal'         => '184.0000',
				'landed_total'             => '18.4000',
				'batch_total'              => '202.4000',
				'note'                     => 'Spring restock',
				'user_id'                  => 5,
				'created_at'               => '2025-03-01 09:00:00',
			),
			$overrides
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function fixture_batch_line( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                      => 11,
				'batch_id'                => 7,
				'product_id'              => 123,
				'variation_id'            => 0,
				'quantity'                => '10.0000',
				'entered_currency'        => 'USD',
				'exchange_rate_to_eur'    => '0.92000000',
				'entered_line_cost'       => '100.0000',
				'converted_line_cost_eur' => '92.0000',
				'base_line_cost'          => '92.0000',
				'allocated_landed_cost'   => '9.2000',
				'true_line_cost'          => '101.2000',
				'true_unit_cost'          => '10.120000',
				'old_stock'               => '5.0000',
				'new_stock'               => '15.0000',
				'old_average_unit_cost'   => '8.000000',
				'new_average_unit_cost'   => '8.746667',
				'old_inventory_value'     => '40.0000',
				'new_inventory_value'     => '131.2000',
			),
			$overrides
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function fixture_batch_cost( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                   => 3,
				'batch_id'             => 7,
				'cost_type'            => 'shipping',
				'entered_currency'     => 'USD',
				'exchange_rate_to_eur' => '0.92000000',
				'entered_amount'       => '21.7391',
				'converted_amount_eur' => '20.0000',
				'amount'               => '20.0000',
				'note'                 => 'DHL',
			),
			$overrides
		);
	}

	public function test_map_header_status_source_and_supplier() {
		$header = WC_Inventory_Overview_Batch_Migration_Service::map_header( $this->fixture_batch_row() );

		$this->assertSame( WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_POSTED, $header['status'] );
		$this->assertSame( WC_Inventory_Overview_Goods_Receipts::SOURCE_MIGRATED, $header['source'] );
		$this->assertNull( $header['supplier_id'] );
		$this->assertSame( 'Acme Supplies', $header['supplier_name_snapshot'] );
	}

	public function test_map_header_totals_and_currency_carried_verbatim() {
		$header = WC_Inventory_Overview_Batch_Migration_Service::map_header( $this->fixture_batch_row() );

		$this->assertSame( 'USD', $header['currency'] );
		$this->assertDecimalEqual( 0.92, (float) $header['exchange_rate_to_eur'], 8 );
		$this->assertSame( '2025-03-01', $header['exchange_rate_date'] );
		$this->assertDecimalEqual( 200.0, (float) $header['product_subtotal_entered'], 4 );
		$this->assertDecimalEqual( 20.0, (float) $header['landed_total_entered'], 4 );
		$this->assertDecimalEqual( 220.0, (float) $header['receipt_total_entered'], 4, 'batch_total_entered must map to receipt_total_entered.' );
		$this->assertDecimalEqual( 184.0, (float) $header['product_subtotal'], 4 );
		$this->assertDecimalEqual( 18.4, (float) $header['landed_total'], 4 );
		$this->assertDecimalEqual( 202.4, (float) $header['receipt_total'], 4, 'batch_total must map to receipt_total.' );
	}

	public function test_map_header_provenance_reference_string() {
		$header = WC_Inventory_Overview_Batch_Migration_Service::map_header( $this->fixture_batch_row( array( 'id' => 42 ) ) );
		$this->assertSame( 'Migrated from legacy Batch #42', $header['reference'] );
	}

	public function test_map_header_timestamps_all_equal_batch_created_at() {
		$header = WC_Inventory_Overview_Batch_Migration_Service::map_header( $this->fixture_batch_row() );

		$this->assertSame( '2025-03-01 09:00:00', $header['posted_at'] );
		$this->assertSame( '2025-03-01 09:00:00', $header['created_at'] );
		$this->assertSame( '2025-03-01 09:00:00', $header['updated_at'] );
	}

	public function test_map_header_user_ids_from_batch_user_id() {
		$header = WC_Inventory_Overview_Batch_Migration_Service::map_header( $this->fixture_batch_row( array( 'user_id' => 9 ) ) );

		$this->assertSame( 9, $header['posted_by'] );
		$this->assertSame( 9, $header['created_by'] );
		$this->assertSame( 9, $header['updated_by'] );
	}

	public function test_map_header_zero_user_id_yields_null_posted_by() {
		$header = WC_Inventory_Overview_Batch_Migration_Service::map_header( $this->fixture_batch_row( array( 'user_id' => 0 ) ) );

		$this->assertNull( $header['posted_by'] );
		$this->assertSame( 0, $header['created_by'], 'created_by/updated_by stay NOT NULL (schema default 0), unlike posted_by.' );
	}

	public function test_map_line_unit_cost_derived_by_dividing_line_totals_by_quantity() {
		$line = WC_Inventory_Overview_Batch_Migration_Service::map_line( $this->fixture_batch_line(), 0 );

		// entered_line_cost 100 / qty 10 = 10; converted_line_cost_eur 92 / qty 10 = 9.2.
		$this->assertDecimalEqual( 10.0, (float) $line['entered_unit_cost'], 6 );
		$this->assertDecimalEqual( 9.2, (float) $line['converted_unit_cost_eur'], 6 );
	}

	public function test_map_line_po_line_id_always_null() {
		$line = WC_Inventory_Overview_Batch_Migration_Service::map_line( $this->fixture_batch_line(), 0 );
		$this->assertNull( $line['po_line_id'], 'Migrated lines must never carry a PO linkage (D7 / M5 binding note).' );
	}

	public function test_map_line_index_uses_supplied_position() {
		$line = WC_Inventory_Overview_Batch_Migration_Service::map_line( $this->fixture_batch_line(), 3 );
		$this->assertSame( 3, $line['line_index'] );
	}

	public function test_map_line_snapshot_and_derived_totals_carried_verbatim() {
		$line = WC_Inventory_Overview_Batch_Migration_Service::map_line( $this->fixture_batch_line(), 0 );

		$this->assertDecimalEqual( 10.0, (float) $line['qty'], 4 );
		$this->assertDecimalEqual( 92.0, (float) $line['base_line_cost'], 4 );
		$this->assertDecimalEqual( 9.2, (float) $line['allocated_landed_cost'], 4 );
		$this->assertDecimalEqual( 101.2, (float) $line['true_line_cost'], 4 );
		$this->assertDecimalEqual( 10.12, (float) $line['true_unit_cost'], 6 );
		$this->assertDecimalEqual( 5.0, (float) $line['old_stock'], 4 );
		$this->assertDecimalEqual( 15.0, (float) $line['new_stock'], 4 );
		$this->assertDecimalEqual( 8.0, (float) $line['old_average_unit_cost'], 6 );
		$this->assertDecimalEqual( 8.746667, (float) $line['new_average_unit_cost'], 6 );
		$this->assertDecimalEqual( 40.0, (float) $line['old_inventory_value'], 4 );
		$this->assertDecimalEqual( 131.2, (float) $line['new_inventory_value'], 4 );
	}

	public function test_map_line_null_old_average_unit_cost_preserved_as_null() {
		$line = WC_Inventory_Overview_Batch_Migration_Service::map_line(
			$this->fixture_batch_line( array( 'old_average_unit_cost' => null ) ),
			0
		);
		$this->assertNull( $line['old_average_unit_cost'], 'Fresh-stock-from-zero batches (no prior average) must not synthesize a fake 0 average.' );
	}

	public function test_map_line_sku_and_name_snapshot_best_effort_from_current_product() {
		$product = $this->create_simple_product( array( 'name' => 'Widget' ) );
		$product->set_sku( 'WIDGET-1' );
		$product->save();

		$line = WC_Inventory_Overview_Batch_Migration_Service::map_line(
			$this->fixture_batch_line(
				array(
					'product_id'   => $product->get_id(),
					'variation_id' => 0,
				)
			),
			0
		);

		$this->assertSame( 'WIDGET-1', $line['sku_snapshot'] );
		$this->assertSame( 'Widget', $line['name_snapshot'] );
	}

	public function test_map_line_snapshot_null_when_product_no_longer_exists() {
		$line = WC_Inventory_Overview_Batch_Migration_Service::map_line(
			$this->fixture_batch_line(
				array(
					'product_id'   => 999999999,
					'variation_id' => 0,
				)
			),
			0
		);

		$this->assertNull( $line['sku_snapshot'] );
		$this->assertNull( $line['name_snapshot'] );
	}

	public function test_map_cost_carries_fields_verbatim_and_post_hoc_always_zero() {
		$cost = WC_Inventory_Overview_Batch_Migration_Service::map_cost( $this->fixture_batch_cost() );

		$this->assertSame( 'shipping', $cost['cost_type'] );
		$this->assertSame( 'USD', $cost['entered_currency'] );
		$this->assertDecimalEqual( 21.7391, (float) $cost['entered_amount'], 4 );
		$this->assertDecimalEqual( 20.0, (float) $cost['converted_amount_eur'], 4 );
		$this->assertDecimalEqual( 20.0, (float) $cost['amount'], 4 );
		$this->assertSame( 'DHL', $cost['note'] );
		$this->assertSame( 0, $cost['post_hoc'] );
	}
}
