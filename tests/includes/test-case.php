<?php
/**
 * Base test case for WC Inventory Overview
 *
 * Extends WordPress PHPUnit with plugin-specific helpers.
 *
 * @package WC_Inventory_Overview_Tests
 */

/**
 * WC_Inventory_Overview_Test_Case
 *
 * Base class for integration tests. Ensures proper isolation and cleanup.
 */
abstract class WC_Inventory_Overview_Test_Case extends WP_UnitTestCase {

	/**
	 * Set up test fixtures (run before each test method).
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure database is fresh for each test.
		self::flush_cache();
	}

	/**
	 * Tear down after each test (run after each test method).
	 */
	public function tearDown(): void {
		parent::tearDown();

		// Flush any remaining caches.
		self::flush_cache();
	}

	/**
	 * Flush all WordPress caches (object cache, transients, etc.).
	 *
	 * Static to match WP_UnitTestCase_Base::flush_cache()'s signature in
	 * current WordPress core -- PHP does not allow narrowing a static parent
	 * method to non-static in a subclass. $this->flush_cache() calls below
	 * remain valid (PHP permits calling a static method via $this->).
	 */
	public static function flush_cache(): void {
		wp_cache_flush();

		// Also flush product-specific transients if WooCommerce is active.
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			// WooCommerce transient cleanup would be called per-product in real tests.
		}
	}

	/**
	 * Create a simple WooCommerce product for testing.
	 *
	 * @param array $props Product properties to override defaults.
	 * @return WC_Product_Simple The created product.
	 */
	protected function create_simple_product( $props = array() ): WC_Product_Simple {
		$defaults = array(
			'name'         => 'Test Product',
			'type'         => 'simple',
			'status'       => 'publish',
			'stock_qty'    => 0,
			'manage_stock' => true,
		);

		$props   = array_merge( $defaults, $props );
		$product = new WC_Product_Simple();
		$product->set_name( $props['name'] );
		$product->set_status( $props['status'] );
		$product->set_manage_stock( $props['manage_stock'] );
		$product->set_stock_quantity( $props['stock_qty'] );

		$product->save();

		return $product;
	}

	/**
	 * Create a variable WooCommerce product with variations.
	 *
	 * @param array $props Product properties.
	 * @param array $variations Array of variation data.
	 * @return WC_Product_Variable The created variable product.
	 */
	protected function create_variable_product( $props = array(), $variations = array() ): WC_Product_Variable {
		$defaults = array(
			'name'   => 'Test Variable Product',
			'type'   => 'variable',
			'status' => 'publish',
		);

		$props   = array_merge( $defaults, $props );
		$product = new WC_Product_Variable();
		$product->set_name( $props['name'] );
		$product->set_status( $props['status'] );

		$product->save();

		// Add variations.
		foreach ( $variations as $var_data ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_name( $var_data['name'] ?? 'Variation' );
			$variation->set_status( 'publish' );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $var_data['stock_qty'] ?? 0 );

			// Set attributes.
			if ( isset( $var_data['attributes'] ) ) {
				foreach ( $var_data['attributes'] as $attr_name => $attr_value ) {
					$variation->set_attributes( array( $attr_name => $attr_value ) );
				}
			}

			$variation->save();
		}

		return $product;
	}

	/**
	 * Set a product's average cost meta (used in golden fixture setup).
	 *
	 * @param WC_Product $product The product to update.
	 * @param float      $avg_cost The average cost value.
	 * @return void
	 */
	protected function set_product_average_cost( WC_Product $product, float $avg_cost ): void {
		$product->update_meta_data( '_wc_io_average_unit_cost', $avg_cost );
		$product->save();
	}

	/**
	 * Set a product's inventory value meta (used in golden fixture setup).
	 *
	 * @param WC_Product $product The product to update.
	 * @param float      $inventory_value The inventory value.
	 * @return void
	 */
	protected function set_product_inventory_value( WC_Product $product, float $inventory_value ): void {
		$product->update_meta_data( '_wc_io_inventory_value', $inventory_value );
		$product->save();
	}

	/**
	 * Get a product's average cost (as persisted in meta).
	 *
	 * @param WC_Product $product The product.
	 * @return float|null The average cost or null if not set.
	 */
	protected function get_product_average_cost( WC_Product $product ): ?float {
		$cost = $product->get_meta( '_wc_io_average_unit_cost' );
		return ( '' === $cost || null === $cost ) ? null : (float) $cost;
	}

	/**
	 * Get a product's inventory value (as persisted in meta).
	 *
	 * @param WC_Product $product The product.
	 * @return float The inventory value.
	 */
	protected function get_product_inventory_value( WC_Product $product ): float {
		return (float) $product->get_meta( '_wc_io_inventory_value', true );
	}

	/**
	 * Assert that two decimal values are equal to a given precision.
	 *
	 * @param float  $expected The expected value.
	 * @param float  $actual The actual value.
	 * @param int    $decimals Number of decimal places to compare (default 4).
	 * @param string $message Optional failure message.
	 * @return void
	 */
	protected function assertDecimalEqual( float $expected, float $actual, int $decimals = 4, string $message = '' ): void {
		$expected_str = number_format( $expected, $decimals, '.', '' );
		$actual_str   = number_format( $actual, $decimals, '.', '' );

		$this->assertEquals( $expected_str, $actual_str, $message ?: "Decimal values do not match to {$decimals} places" );
	}

	/**
	 * Create a supplier for testing.
	 *
	 * @param array $props Override properties (default: reasonable test values).
	 * @return array The created supplier row.
	 */
	protected function create_supplier( array $props = array() ): array {
		$defaults = array(
			'name'             => 'Test Supplier ' . uniqid(),
			'default_currency' => 'EUR',
		);
		$data     = wp_parse_args( $props, $defaults );

		$id = WC_Inventory_Overview_Suppliers::create( $data );
		if ( is_wp_error( $id ) ) {
			$this->fail( 'Failed to create supplier: ' . $id->get_error_message() );
		}

		return WC_Inventory_Overview_Suppliers::get( $id );
	}

	/**
	 * Create a draft purchase order for testing. Creates a supplier first if
	 * none given, following create_supplier()'s fail-fast pattern.
	 *
	 * @param array $props Override properties (default: reasonable test values).
	 * @return array The created PO row.
	 */
	protected function create_purchase_order( array $props = array() ): array {
		if ( ! isset( $props['supplier_id'] ) ) {
			$supplier             = $this->create_supplier();
			$props['supplier_id'] = $supplier['id'];
		}

		$defaults = array(
			'currency' => 'EUR',
		);
		$data     = wp_parse_args( $props, $defaults );

		$id = WC_Inventory_Overview_Purchase_Orders::create_draft( $data );
		if ( is_wp_error( $id ) ) {
			$this->fail( 'Failed to create purchase order: ' . $id->get_error_message() );
		}

		return WC_Inventory_Overview_Purchase_Orders::get( $id );
	}

	/**
	 * Add a line to a purchase order for testing. Creates a fresh simple,
	 * stock-managed product if none given.
	 *
	 * @param int   $po_id PO ID.
	 * @param array $props Override properties (default: reasonable test values).
	 * @return array The created line row.
	 */
	protected function add_po_line( int $po_id, array $props = array() ): array {
		if ( ! isset( $props['product_id'] ) ) {
			$product             = $this->create_simple_product();
			$props['product_id'] = $product->get_id();
		}

		$defaults = array(
			'qty_ordered' => 1,
			'unit_cost'   => 1,
		);
		$data     = wp_parse_args( $props, $defaults );

		$id = WC_Inventory_Overview_Purchase_Order_Lines::create( $po_id, $data );
		if ( is_wp_error( $id ) ) {
			$this->fail( 'Failed to add purchase order line: ' . $id->get_error_message() );
		}

		return WC_Inventory_Overview_Purchase_Order_Lines::get( $id );
	}

	/**
	 * Create a legacy Batch Intake batch via the real (M6-deprecated, but
	 * still directly callable) apply path --
	 * WC_Inventory_Overview_Batch_Intake_Service::apply_batch_from_post().
	 * Only that class's admin-post/AJAX entry points were retired in M6; the
	 * method itself still works exactly as it always did, so a batch built
	 * this way is a realistic historical fixture -- it has already mutated
	 * current stock/cost precisely like a real pre-M6 batch would have,
	 * before any M6 migration test runs against it.
	 *
	 * @param array<string,mixed> $props Overrides: product (WC_Product), supplier_name,
	 *                                    reference, note, currency, qty, line_cost,
	 *                                    landed_type, landed_amount.
	 * @return array{batch_id:int, product_id:int}
	 */
	protected function create_legacy_batch( array $props = array() ): array {
		$product = $props['product'] ?? $this->create_simple_product( array( 'stock_qty' => 0 ) );

		$post = array(
			'wc_io_batch_supplier'             => $props['supplier_name'] ?? 'Legacy Supplier',
			'wc_io_batch_reference'            => $props['reference'] ?? 'INV-0001',
			'wc_io_batch_note'                 => $props['note'] ?? '',
			'wc_io_batch_purchase_currency'    => $props['currency'] ?? 'EUR',
			'wc_io_batch_exchange_rate_date'   => $props['fx_date'] ?? wp_date( 'Y-m-d', null, wp_timezone() ),
			'wc_io_batch_exchange_rate_to_eur' => $props['fx_rate'] ?? '1',
			'wc_io_batch_line_product'         => array( $product->get_id() ),
			'wc_io_batch_line_qty'             => array( (string) ( $props['qty'] ?? 10 ) ),
			'wc_io_batch_line_cost'            => array( (string) ( $props['line_cost'] ?? 100 ) ),
			'wc_io_batch_confirm'              => '1',
		);

		if ( isset( $props['landed_type'] ) ) {
			$post['wc_io_batch_cost_type']          = array( $props['landed_type'] );
			$post['wc_io_batch_cost_amount']        = array( (string) ( $props['landed_amount'] ?? 10 ) );
			$post['wc_io_batch_cost_note']          = array( '' );
			$post['wc_io_batch_cost_currency']      = array( $props['currency'] ?? 'EUR' );
			$post['wc_io_batch_cost_exchange_rate'] = array( (string) ( $props['fx_rate'] ?? '1' ) );
		}

		$batch_id = WC_Inventory_Overview_Batch_Intake_Service::apply_batch_from_post( $post );
		if ( is_wp_error( $batch_id ) ) {
			$this->fail( 'Failed to create legacy batch: ' . $batch_id->get_error_message() );
		}

		return array(
			'batch_id'   => (int) $batch_id,
			'product_id' => $product->get_id(),
		);
	}

	/**
	 * Backdate a legacy batch's created_at (direct SQL — simulates a batch
	 * genuinely applied in a prior year, for receipt-numbering-year tests).
	 *
	 * @param int    $batch_id Legacy batch id.
	 * @param string $datetime MySQL datetime string, e.g. '2023-05-01 10:00:00'.
	 */
	protected function backdate_legacy_batch( int $batch_id, string $datetime ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'wc_io_purchase_batches',
			array( 'created_at' => $datetime ),
			array( 'id' => $batch_id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
