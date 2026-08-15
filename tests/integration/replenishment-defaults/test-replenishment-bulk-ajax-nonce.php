<?php
/**
 * M26 layered security: WC AJAX entry nonce boundary for bulk-edit-variations.
 *
 * Proves check_ajax_referer( 'bulk-edit-variations', 'security' ) protects the
 * operation. Do not confuse with do_action( 'woocommerce_bulk_edit_variations' )
 * alone, which does not prove nonce rejection (Amendment 6).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_M26_Replenishment_Bulk_Ajax_Nonce extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Production register() is gated on is_admin(); wire the handler for
		// the WC AJAX entry path under PHPUnit.
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
	}

	public function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
		parent::tearDown();
	}

	/**
	 * @return array{0:WC_Product_Variable,1:int[],2:array}
	 */
	private function fixture(): array {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'Red' ) ) );
		$variable = wc_get_product( $variable->get_id() );
		$children = array_map( 'intval', $variable->get_children() );
		$supplier = $this->create_supplier();
		return array( $variable, $children, $supplier );
	}

	private function force_ajax_die_handler(): callable {
		$handler = static function () {
			return static function ( $message, $title = '', $args = array() ) {
				throw new WPDieException( is_string( $message ) ? $message : 'wp_die' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			};
		};
		add_filter( 'wp_die_ajax_handler', $handler, PHP_INT_MAX );
		add_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
		return $handler;
	}

	/**
	 * Invoke WC_AJAX::bulk_edit_variations() and restore the output-buffer
	 * level WC opens with ob_start() before check_ajax_referer/wp_die.
	 *
	 * @return bool True if wp_die was thrown.
	 */
	private function run_bulk_edit_ajax(): bool {
		$handler  = $this->force_ajax_die_handler();
		$ob_level = ob_get_level();
		$died     = false;

		try {
			WC_AJAX::bulk_edit_variations();
		} catch ( WPDieException $e ) {
			$died = true;
		} finally {
			remove_filter( 'wp_die_ajax_handler', $handler, PHP_INT_MAX );
			remove_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
		}

		return $died;
	}

	public function test_missing_nonce_dies_without_writes() {
		list( $variable, $children, $supplier ) = $this->fixture();

		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		$_POST    = array(
			'action'      => 'woocommerce_bulk_edit_variations',
			'product_id'  => $variable->get_id(),
			'bulk_action' => 'wc_io_variable_preferred_supplier',
			'data'        => array( 'preferred_supplier_id' => (int) $supplier['id'] ),
		);
		$_REQUEST = $_POST;

		$this->assertTrue( $this->run_bulk_edit_ajax(), 'Missing nonce must cause wp_die via check_ajax_referer.' );
		$this->assertSame( 0, WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $children[0] ) );
	}

	public function test_bad_nonce_dies_without_writes() {
		list( $variable, $children, $supplier ) = $this->fixture();

		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		$_POST    = array(
			'action'      => 'woocommerce_bulk_edit_variations',
			'security'    => 'not-a-valid-nonce',
			'product_id'  => $variable->get_id(),
			'bulk_action' => 'wc_io_variable_preferred_supplier',
			'data'        => array( 'preferred_supplier_id' => (int) $supplier['id'] ),
		);
		$_REQUEST = $_POST;

		$this->assertTrue( $this->run_bulk_edit_ajax(), 'Bad nonce must cause wp_die via check_ajax_referer.' );
		$this->assertSame( 0, WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $children[0] ) );
	}

	public function test_valid_nonce_applies_supplier() {
		list( $variable, $children, $supplier ) = $this->fixture();

		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		$_POST    = array(
			'action'      => 'woocommerce_bulk_edit_variations',
			'security'    => wp_create_nonce( 'bulk-edit-variations' ),
			'product_id'  => $variable->get_id(),
			'bulk_action' => 'wc_io_variable_preferred_supplier',
			'data'        => array( 'preferred_supplier_id' => (string) $supplier['id'] ),
		);
		$_REQUEST = $_POST;

		$this->assertTrue( $this->run_bulk_edit_ajax(), 'WC bulk_edit_variations always terminates with wp_die().' );
		$this->assertSame(
			(int) $supplier['id'],
			WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $children[0] )
		);
	}
}
