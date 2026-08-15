<?php
/**
 * Integration tests for M26 Product_Replenishment_Admin bulk-edit wiring.
 *
 * Hook registration, action→changes mapping, and wp_send_json error exit
 * (nonce is intentionally out of scope here — see AJAX boundary suite).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_M26_Product_Replenishment_Bulk_Admin extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		WC_Inventory_Overview_Replenishment_Defaults::reset_test_write_fail();

		// Production register() is gated on is_admin(); PHPUnit is not admin by
		// default, so wire the M26 hooks explicitly for these integration tests.
		$this->ensure_bulk_hooks_registered();
	}

	private function ensure_bulk_hooks_registered(): void {
		if ( false === has_action(
			'woocommerce_variable_product_bulk_edit_actions',
			array( 'WC_Inventory_Overview_Product_Replenishment_Admin', 'render_bulk_edit_actions' )
		) ) {
			add_action(
				'woocommerce_variable_product_bulk_edit_actions',
				array( 'WC_Inventory_Overview_Product_Replenishment_Admin', 'render_bulk_edit_actions' )
			);
		}
		if ( false === has_action(
			'woocommerce_bulk_edit_variations',
			array( 'WC_Inventory_Overview_Product_Replenishment_Admin', 'handle_bulk_edit_variations' )
		) ) {
			add_action(
				'woocommerce_bulk_edit_variations',
				array( 'WC_Inventory_Overview_Product_Replenishment_Admin', 'handle_bulk_edit_variations' ),
				10,
				4
			);
		}
		if ( false === has_action(
			'admin_enqueue_scripts',
			array( 'WC_Inventory_Overview_Product_Replenishment_Admin', 'enqueue_bulk_assets' )
		) ) {
			add_action(
				'admin_enqueue_scripts',
				array( 'WC_Inventory_Overview_Product_Replenishment_Admin', 'enqueue_bulk_assets' )
			);
		}
	}

	public function tearDown(): void {
		WC_Inventory_Overview_Replenishment_Defaults::reset_test_write_fail();
		$_POST    = array();
		$_REQUEST = array();
		parent::tearDown();
	}

	public function test_bulk_hooks_are_registered() {
		// register() is called at plugins_loaded; assert the callbacks are wired.
		$this->assertNotFalse(
			has_action(
				'woocommerce_variable_product_bulk_edit_actions',
				array( 'WC_Inventory_Overview_Product_Replenishment_Admin', 'render_bulk_edit_actions' )
			)
		);
		$this->assertNotFalse(
			has_action(
				'woocommerce_bulk_edit_variations',
				array( 'WC_Inventory_Overview_Product_Replenishment_Admin', 'handle_bulk_edit_variations' )
			)
		);
		$this->assertNotFalse(
			has_action(
				'admin_enqueue_scripts',
				array( 'WC_Inventory_Overview_Product_Replenishment_Admin', 'enqueue_bulk_assets' )
			)
		);
	}

	public function test_bulk_action_options_render() {
		ob_start();
		WC_Inventory_Overview_Product_Replenishment_Admin::render_bulk_edit_actions();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'wc_io_variable_preferred_supplier', $html );
		$this->assertStringContainsString( 'wc_io_variable_preferred_supplier_clear', $html );
		$this->assertStringContainsString( 'wc_io_variable_default_qty', $html );
		$this->assertStringContainsString( 'wc_io_variable_default_qty_clear', $html );
		$this->assertStringContainsString( 'Replenishment', $html );
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
		return array( $variable, array_map( 'intval', $variable->get_children() ) );
	}

	public function test_handler_maps_set_supplier_and_writes() {
		list( $variable, $children ) = $this->make_variable( 2 );
		$supplier = $this->create_supplier();

		WC_Inventory_Overview_Product_Replenishment_Admin::handle_bulk_edit_variations(
			'wc_io_variable_preferred_supplier',
			array( 'preferred_supplier_id' => (int) $supplier['id'] ),
			$variable->get_id(),
			$children
		);

		foreach ( $children as $vid ) {
			$this->assertSame(
				(int) $supplier['id'],
				WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $vid )
			);
		}
	}

	public function test_handler_maps_clear_qty() {
		list( $variable, $children ) = $this->make_variable( 1 );
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $children[0], '8' );

		WC_Inventory_Overview_Product_Replenishment_Admin::handle_bulk_edit_variations(
			'wc_io_variable_default_qty_clear',
			array(),
			$variable->get_id(),
			$children
		);

		$this->assertSame( 0.0, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $children[0] ) );
	}

	public function test_handler_ignores_unrelated_bulk_action() {
		list( $variable, $children ) = $this->make_variable( 1 );
		$before = WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $children[0] );

		WC_Inventory_Overview_Product_Replenishment_Admin::handle_bulk_edit_variations(
			'variable_regular_price',
			array( 'value' => '9' ),
			$variable->get_id(),
			$children
		);

		$this->assertSame( $before, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $children[0] ) );
	}

	/**
	 * On M26 failure the handler must wp_send_json({error}) and exit — never
	 * fall through. DOING_AJAX must be true so wp_send_json routes via wp_die.
	 */
	public function test_handler_wp_send_json_error_on_validation_failure() {
		list( $variable, $children ) = $this->make_variable( 1 );

		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		$force_throwing_die_handler = static function () {
			return static function ( $message, $title = '', $args = array() ) {
				throw new WPDieException( is_string( $message ) ? $message : 'wp_die' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			};
		};
		add_filter( 'wp_die_ajax_handler', $force_throwing_die_handler, PHP_INT_MAX );

		$json = '';
		try {
			ob_start();
			WC_Inventory_Overview_Product_Replenishment_Admin::handle_bulk_edit_variations(
				'wc_io_variable_default_qty',
				array( 'default_qty' => '0' ),
				$variable->get_id(),
				$children
			);
			$json = ob_get_clean();
			$this->fail( 'Expected wp_send_json() to call wp_die().' );
		} catch ( WPDieException $e ) {
			$json = ob_get_clean();
		} finally {
			remove_filter( 'wp_die_ajax_handler', $force_throwing_die_handler, PHP_INT_MAX );
		}

		$response = json_decode( $json, true );
		$this->assertIsArray( $response, 'Raw JSON: ' . var_export( $json, true ) );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertNotSame( '', $response['error'] );
		$this->assertSame( 0.0, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $children[0] ) );
	}
}
