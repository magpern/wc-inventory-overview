<?php
/**
 * M6 retirement regression: the two Batch Intake admin entry points no
 * longer register, the Batch Intake subview is gone from the Restock/Cost
 * Adjustment nav, and Quick Restock / Cost Adjustment remain fully
 * functional and unmodified.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Batch_Migration_Retirement_Regression extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	public function test_batch_apply_admin_post_hook_no_longer_registered() {
		WC_Inventory_Overview_Plugin::instance()->init();
		$this->assertFalse( has_action( 'admin_post_wc_io_batch_apply' ), 'admin_post_wc_io_batch_apply must not be registered after M6.' );
	}

	public function test_batch_preview_ajax_hook_no_longer_registered() {
		WC_Inventory_Overview_Plugin::instance()->init();
		$this->assertFalse( has_action( 'wp_ajax_wc_io_batch_preview' ), 'wp_ajax_wc_io_batch_preview must not be registered after M6.' );
	}

	public function test_other_restock_and_settings_hooks_remain_registered() {
		WC_Inventory_Overview_Plugin::instance()->init();
		$this->assertNotFalse( has_action( 'admin_post_wc_io_restock' ), 'Quick Restock hook must remain registered.' );
		$this->assertNotFalse( has_action( 'admin_post_wc_io_cost_adjustment' ), 'Cost Adjustment hook must remain registered.' );
		$this->assertNotFalse( has_action( 'wp_ajax_wc_io_get_cost_adjustment_preview' ), 'Cost Adjustment preview AJAX hook must remain registered.' );
	}

	public function test_restock_subview_no_longer_includes_batch() {
		$plugin  = WC_Inventory_Overview_Plugin::instance();
		$method  = new ReflectionMethod( $plugin, 'get_restock_subview' );
		$method->setAccessible( true );

		$_GET['tab']           = WC_Inventory_Overview_Plugin::TAB_RESTOCK;
		$_GET['restock_view'] = 'batch';
		$result                = $method->invoke( $plugin );
		unset( $_GET['restock_view'], $_GET['tab'] );

		$this->assertSame( WC_Inventory_Overview_Plugin::RESTOCK_VIEW_QUICK, $result, 'A stale restock_view=batch request must fall back to Quick Restock, not error.' );
	}

	public function test_restock_subview_still_accepts_quick_and_adjust() {
		$plugin = WC_Inventory_Overview_Plugin::instance();
		$method = new ReflectionMethod( $plugin, 'get_restock_subview' );
		$method->setAccessible( true );

		$_GET['tab'] = WC_Inventory_Overview_Plugin::TAB_RESTOCK;
		foreach ( array( WC_Inventory_Overview_Plugin::RESTOCK_VIEW_QUICK, WC_Inventory_Overview_Plugin::RESTOCK_VIEW_ADJUST ) as $view ) {
			$_GET['restock_view'] = $view;
			$this->assertSame( $view, $method->invoke( $plugin ) );
		}
		unset( $_GET['restock_view'], $_GET['tab'] );
	}

	/**
	 * Quick Restock remains fully functional — unaffected by Batch Intake's
	 * retirement (verified independent of Batch_Intake_Service by source scan
	 * in the architecture guard suite; this is the behavioral confirmation).
	 */
	public function test_quick_restock_still_functions() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Movements::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );

		$applied = WC_Inventory_Overview_Restock_Service::apply_purchase_line_change( $product->get_id(), 5.0, 2.0 );
		$this->assertIsArray( $applied, is_wp_error( $applied ) ? $applied->get_error_message() : '' );

		$fresh = wc_get_product( $product->get_id() );
		$this->assertEqualsWithDelta( 5.0, (float) $fresh->get_stock_quantity(), 0.0001 );
	}

	/**
	 * Cost Adjustment remains fully functional — unaffected by Batch Intake's
	 * retirement.
	 */
	public function test_cost_adjustment_still_functions() {
		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );
		$this->set_product_average_cost( $product, 4.0 );
		$this->set_product_inventory_value( $product, 40.0 );

		$result = WC_Inventory_Overview_Cost_Adjustment_Service::process( $product->get_id(), 6.0, 'Regression check' );
		$this->assertTrue( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$fresh = wc_get_product( $product->get_id() );
		$this->assertEqualsWithDelta( 6.0, (float) $fresh->get_meta( '_wc_io_average_unit_cost' ), 0.0001 );
	}
}
