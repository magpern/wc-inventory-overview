<?php
/**
 * Integration tests for supplier admin PRG / list-table fixes (v1.18.1).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Suppliers_Admin_PRG extends WP_UnitTestCase {

	/**
	 * Captured redirect location from wp_redirect filter.
	 *
	 * @var string|null
	 */
	private $redirect_to = null;

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->redirect_to = null;

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );

		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10, 1 );
	}

	public function tearDown(): void {
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10 );
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		parent::tearDown();
	}

	/**
	 * Capture redirect target and abort the exit path.
	 *
	 * @param string $location Redirect URL.
	 * @return string
	 */
	public function capture_redirect( $location ) {
		$this->redirect_to = $location;
		throw new Exception( 'wp_redirect:' . $location );
	}

	/**
	 * @param callable $callback Handler invocation.
	 * @return string Redirect location.
	 */
	private function run_expecting_redirect( callable $callback ) {
		try {
			$callback();
			$this->fail( 'Expected wp_safe_redirect/exit' );
		} catch ( Exception $e ) {
			$this->assertStringStartsWith( 'wp_redirect:', $e->getMessage() );
		}
		$this->assertNotEmpty( $this->redirect_to );
		return (string) $this->redirect_to;
	}

	public function test_save_create_redirects_with_saved_notice() {
		$page = WC_Inventory_Overview_Purchasing_Page::instance();

		$nonce = wp_create_nonce( 'wc_io_supplier_save' );
		$_POST = array(
			'supplier_id'              => 0,
			'wc_io_supplier_save_nonce' => $nonce,
			'_wp_http_referer'         => '/wp-admin/',
			'supplier'                 => array(
				'name' => 'PRG Create Supplier',
			),
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $page ) {
				$page->handle_supplier_save();
			}
		);

		$this->assertStringContainsString( 'wc_io_supplier=saved', $location );
		$this->assertStringContainsString( 'tab=suppliers', $location );
		$this->assertStringContainsString( 'action=edit', $location );
		$this->assertStringContainsString( 'supplier_id=', $location );
		$this->assertStringNotContainsString( 'admin-post.php', $location );
	}

	public function test_archive_via_get_row_action_with_nonce() {
		$id = WC_Inventory_Overview_Suppliers::create(
			array(
				'name' => 'PRG Archive Supplier',
			)
		);
		$this->assertIsInt( $id );

		$page  = WC_Inventory_Overview_Purchasing_Page::instance();
		$nonce = wp_create_nonce( 'wc_io_supplier_archive_' . $id );

		$_REQUEST = array(
			'supplier_id'                  => $id,
			'wc_io_supplier_archive_nonce' => $nonce,
		);
		$_GET     = $_REQUEST;
		$_POST    = array();

		$location = $this->run_expecting_redirect(
			static function () use ( $page ) {
				$page->handle_supplier_archive();
			}
		);

		$this->assertStringContainsString( 'wc_io_supplier=archived', $location );
		$supplier = WC_Inventory_Overview_Suppliers::get( $id );
		$this->assertSame( 'archived', $supplier['status'] );
	}

	public function test_reactivate_via_get_row_action_with_nonce() {
		$id = WC_Inventory_Overview_Suppliers::create(
			array(
				'name' => 'PRG Reactivate Supplier',
			)
		);
		$this->assertIsInt( $id );
		WC_Inventory_Overview_Suppliers::archive( $id );

		$page  = WC_Inventory_Overview_Purchasing_Page::instance();
		$nonce = wp_create_nonce( 'wc_io_supplier_reactivate_' . $id );

		$_REQUEST = array(
			'supplier_id'                     => $id,
			'wc_io_supplier_reactivate_nonce' => $nonce,
		);
		$_GET     = $_REQUEST;
		$_POST    = array();

		$location = $this->run_expecting_redirect(
			static function () use ( $page ) {
				$page->handle_supplier_reactivate();
			}
		);

		$this->assertStringContainsString( 'wc_io_supplier=reactivated', $location );
		$supplier = WC_Inventory_Overview_Suppliers::get( $id );
		$this->assertSame( 'active', $supplier['status'] );
	}

	public function test_list_table_display_renders_status_views() {
		WC_Inventory_Overview_Suppliers::create(
			array(
				'name'             => 'View Active Supplier',
				'default_currency' => 'EUR',
			)
		);

		set_current_screen( 'woocommerce_page_wc-io-purchasing' );
		$_REQUEST['page'] = 'wc-io-purchasing';
		$_REQUEST['tab']  = 'suppliers';

		$table = new WC_Inventory_Overview_Suppliers_List_Table();
		$table->prepare_items();

		ob_start();
		$table->display();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Active', $html );
		$this->assertStringContainsString( 'Archived', $html );
		$this->assertStringContainsString( 'subsubsub', $html );
	}

	public function test_handlers_do_not_call_wp_safe_remote_post() {
		$source = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-purchasing-page.php' );
		$this->assertIsString( $source );
		$this->assertSame( 0, substr_count( $source, 'wp_safe_remote_post' ) );
		$this->assertGreaterThanOrEqual( 4, substr_count( $source, 'wp_safe_redirect' ) );
	}
}
