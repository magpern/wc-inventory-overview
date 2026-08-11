<?php
/**
 * Integration tests for WC_Inventory_Overview_Expected_Delivery_Renderer (M7).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Expected_Delivery_Renderer extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );
		delete_option( WC_Inventory_Overview_Settings::OPTION_EXPECTED_DELIVERY_RENDERER_ENABLED );
		WC_Inventory_Overview_Expected_Delivery_Service::flush_memo();
	}

	public function tearDown(): void {
		WC_Inventory_Overview_Expected_Delivery_Service::flush_memo();
		delete_option( WC_Inventory_Overview_Settings::OPTION_EXPECTED_DELIVERY_RENDERER_ENABLED );
		set_current_screen( 'front' );
		parent::tearDown();
	}

	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param int $po_id PO id.
	 * @return array<string,mixed>
	 */
	private function place_po( int $po_id ): array {
		$result = WC_Inventory_Overview_PO_Service::place( $po_id );
		if ( is_wp_error( $result ) ) {
			$this->fail( 'Failed to place PO: ' . $result->get_error_message() );
		}
		return $result;
	}

	/**
	 * Out-of-stock product with one open, customer-safe PO line.
	 *
	 * @param string $expected_date '2026-09-01' style.
	 * @param string $confidence    'exact'|'estimated'.
	 * @return WC_Product_Simple
	 */
	private function create_out_of_stock_product_with_customer_safe_line( string $expected_date, string $confidence ): WC_Product_Simple {
		$product = $this->create_simple_product( array( 'stock_qty' => 0, 'manage_stock' => true ) );

		$po = $this->create_purchase_order(
			array(
				'expected_date'       => $expected_date,
				'expected_confidence' => $confidence,
			)
		);
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 1,
			)
		);
		$this->place_po( $po['id'] );

		return $product;
	}

	public function test_in_stock_product_is_untouched() {
		$product = $this->create_simple_product( array( 'stock_qty' => 5, 'manage_stock' => true ) );
		$availability = array(
			'availability' => 'In stock',
			'class'        => 'in-stock',
		);

		$out = WC_Inventory_Overview_Expected_Delivery_Renderer::filter_availability( $availability, $product );

		$this->assertSame( $availability, $out );
	}

	public function test_backorder_product_is_untouched() {
		$product = $this->create_simple_product( array( 'stock_qty' => 0, 'manage_stock' => true ) );
		$product->set_backorders( 'yes' );
		$product->save();

		$this->assertTrue( $product->is_in_stock(), 'A backorder-permitted product must be in stock by WooCommerce definition' );

		$availability = array(
			'availability' => 'Available on backorder',
			'class'        => 'available-on-backorder',
		);

		$out = WC_Inventory_Overview_Expected_Delivery_Renderer::filter_availability( $availability, $product );

		$this->assertSame( $availability, $out );
	}

	public function test_out_of_stock_with_no_incoming_is_untouched() {
		$product = $this->create_simple_product( array( 'stock_qty' => 0, 'manage_stock' => true ) );
		$availability = array(
			'availability' => 'Out of stock',
			'class'        => 'out-of-stock',
		);

		$out = WC_Inventory_Overview_Expected_Delivery_Renderer::filter_availability( $availability, $product );

		$this->assertSame( $availability, $out );
	}

	public function test_customer_safe_exact_date_replaces_text() {
		$product = $this->create_out_of_stock_product_with_customer_safe_line( '2026-09-01', 'exact' );
		$availability = array(
			'availability' => 'Out of stock',
			'class'        => 'out-of-stock',
		);

		$out = WC_Inventory_Overview_Expected_Delivery_Renderer::filter_availability( $availability, $product );

		$expected_text = sprintf( 'Expected back around %s', date_i18n( get_option( 'date_format' ), strtotime( '2026-09-01' ) ) );
		$this->assertSame( $expected_text, $out['availability'] );
		$this->assertSame( 'out-of-stock', $out['class'] );
	}

	public function test_customer_safe_estimated_date_replaces_text_with_week() {
		$product = $this->create_out_of_stock_product_with_customer_safe_line( '2026-09-01', 'estimated' );
		$availability = array(
			'availability' => 'Out of stock',
			'class'        => 'out-of-stock',
		);

		$out = WC_Inventory_Overview_Expected_Delivery_Renderer::filter_availability( $availability, $product );

		$expected_text = sprintf( 'Expected during week %s', date_i18n( 'W', strtotime( '2026-09-01' ) ) );
		$this->assertSame( $expected_text, $out['availability'] );
	}

	public function test_no_customer_safe_date_yields_expected_soon() {
		$product = $this->create_simple_product( array( 'stock_qty' => 0, 'manage_stock' => true ) );

		// A placed PO with a past expected date: the upstream SQL predicate
		// flags it delayed (status = 'placed' AND date < today), so it is
		// real, open, incoming supply that is nonetheless not customer-safe.
		$po_past = $this->create_purchase_order(
			array(
				'expected_date'       => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
				'expected_confidence' => 'exact',
			)
		);
		$this->add_po_line(
			$po_past['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 1,
			)
		);
		$this->place_po( $po_past['id'] );

		$availability = array(
			'availability' => 'Out of stock',
			'class'        => 'out-of-stock',
		);

		$out = WC_Inventory_Overview_Expected_Delivery_Renderer::filter_availability( $availability, $product );

		$this->assertSame( 'Expected soon', $out['availability'] );
	}

	public function test_empty_availability_text_is_untouched() {
		$product = $this->create_out_of_stock_product_with_customer_safe_line( '2026-09-01', 'exact' );
		$availability = array(
			'availability' => '',
			'class'        => 'out-of-stock',
		);

		$out = WC_Inventory_Overview_Expected_Delivery_Renderer::filter_availability( $availability, $product );

		$this->assertSame( '', $out['availability'] );
	}

	public function test_setting_disabled_leaves_output_untouched() {
		update_option( WC_Inventory_Overview_Settings::OPTION_EXPECTED_DELIVERY_RENDERER_ENABLED, 'no' );

		$product = $this->create_out_of_stock_product_with_customer_safe_line( '2026-09-01', 'exact' );
		$availability = array(
			'availability' => 'Out of stock',
			'class'        => 'out-of-stock',
		);

		$out = WC_Inventory_Overview_Expected_Delivery_Renderer::filter_availability( $availability, $product );

		$this->assertSame( $availability, $out );
	}

	public function test_opt_out_filter_leaves_output_untouched_and_issues_zero_queries() {
		$product = $this->create_out_of_stock_product_with_customer_safe_line( '2026-09-01', 'exact' );
		$availability = array(
			'availability' => 'Out of stock',
			'class'        => 'out-of-stock',
		);

		add_filter( 'wc_io_storefront_render_expected_delivery', '__return_false' );

		$lines_table = WC_Inventory_Overview_Purchase_Order_Lines::table_name();
		$queries     = array();
		$counter     = static function ( $query ) use ( $lines_table, &$queries ) {
			if ( false !== strpos( $query, $lines_table ) && false !== stripos( $query, 'SELECT' ) ) {
				$queries[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $counter );
		$out = WC_Inventory_Overview_Expected_Delivery_Renderer::filter_availability( $availability, $product );
		remove_filter( 'query', $counter );
		remove_filter( 'wc_io_storefront_render_expected_delivery', '__return_false' );

		$this->assertSame( $availability, $out );
		$this->assertCount( 0, $queries, 'An opt-out must never cost a query' );
	}

	public function test_expected_delivery_text_filter_overrides_the_string() {
		$product = $this->create_out_of_stock_product_with_customer_safe_line( '2026-09-01', 'exact' );
		$availability = array(
			'availability' => 'Out of stock',
			'class'        => 'out-of-stock',
		);

		$override = static function ( $text, $result, $filtered_product ) {
			return 'Custom expected delivery copy';
		};
		add_filter( 'wc_io_expected_delivery_text', $override, 10, 3 );

		$out = WC_Inventory_Overview_Expected_Delivery_Renderer::filter_availability( $availability, $product );

		remove_filter( 'wc_io_expected_delivery_text', $override, 10 );

		$this->assertSame( 'Custom expected delivery copy', $out['availability'] );
	}

	public function test_custom_availability_class_does_not_break_rendering() {
		$product = $this->create_out_of_stock_product_with_customer_safe_line( '2026-09-01', 'exact' );
		$availability = array(
			'availability' => 'Out of stock',
			'class'        => 'my-theme-custom-class',
		);

		$out = WC_Inventory_Overview_Expected_Delivery_Renderer::filter_availability( $availability, $product );

		$this->assertStringContainsString( 'Expected back around', $out['availability'] );
		$this->assertSame( 'my-theme-custom-class', $out['class'] );
	}

	public function test_forced_in_stock_class_is_respected_as_override_escape_hatch() {
		$product = $this->create_out_of_stock_product_with_customer_safe_line( '2026-09-01', 'exact' );
		$availability = array(
			'availability' => 'Out of stock',
			'class'        => 'in-stock', // Third party forced the class.
		);

		$out = WC_Inventory_Overview_Expected_Delivery_Renderer::filter_availability( $availability, $product );

		$this->assertSame( $availability, $out );
	}

	/**
	 * Simulates AJAX context via the `wp_doing_ajax` filter rather than
	 * `define( 'DOING_AJAX', true )`. PHP constants can never be undefined,
	 * so a real define() here would permanently leak wp_doing_ajax() === true
	 * to every test that runs later in the same PHPUnit process, regardless
	 * of suite ordering (see M14's test_capability_gate_denies_unauthorized_user
	 * hardening, which had to defend against exactly that leak). The
	 * production code under test (WC_Inventory_Overview_Expected_Delivery_Renderer)
	 * only ever calls wp_doing_ajax(), never the raw constant, so the filter
	 * is a behaviorally equivalent and fully reversible stand-in.
	 */
	public function test_filter_is_inactive_on_admin_non_ajax_and_active_under_ajax() {
		$product = $this->create_out_of_stock_product_with_customer_safe_line( '2026-09-01', 'exact' );
		$availability = array(
			'availability' => 'Out of stock',
			'class'        => 'out-of-stock',
		);

		set_current_screen( 'edit-post' );
		$this->assertTrue( is_admin() );

		$inactive = WC_Inventory_Overview_Expected_Delivery_Renderer::filter_availability( $availability, $product );
		$this->assertSame( $availability, $inactive, 'Admin, non-AJAX must be inert' );

		add_filter( 'wp_doing_ajax', '__return_true' );
		WC_Inventory_Overview_Expected_Delivery_Service::flush_memo();

		try {
			$active = WC_Inventory_Overview_Expected_Delivery_Renderer::filter_availability( $availability, $product );
			$this->assertNotSame( $availability, $active, 'woocommerce_get_variation runs on admin-ajax.php where is_admin() is true; the filter must stay live under wp_doing_ajax()' );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
			set_current_screen( 'front' );
		}
	}

	/**
	 * ISO-8601 week deliberately carries no year: 2027-01-01, a January date,
	 * correctly reads week 53 -- the ISO week containing Jan 1, 2027 belongs
	 * to the *previous* ISO year. Deliberate behavior, covered by test
	 * rather than "fixed".
	 */
	public function test_iso_week_year_boundary() {
		$boundary_date = '2027-01-01';
		$this->assertSame( '53', date_i18n( 'W', strtotime( $boundary_date ) ), 'Precondition: PHP/WP date() must report week 53 for this date, confirming the deliberate year-boundary behavior' );

		$product = $this->create_out_of_stock_product_with_customer_safe_line( $boundary_date, 'estimated' );
		$availability = array(
			'availability' => 'Out of stock',
			'class'        => 'out-of-stock',
		);

		$out = WC_Inventory_Overview_Expected_Delivery_Renderer::filter_availability( $availability, $product );

		$this->assertSame( 'Expected during week 53', $out['availability'] );
	}
}
