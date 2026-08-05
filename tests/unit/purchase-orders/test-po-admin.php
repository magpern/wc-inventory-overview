<?php
/**
 * M2-D: Purchase Order admin UX — handlers, tokens, caps, list filters.
 *
 * @package WC_Inventory_Overview_Tests
 */

// phpcs:disable WordPress.Files.FileName,WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- PHPUnit admin-handler simulations.

/**
 * PO admin integration tests.
 */
class Test_WC_IO_PO_Admin extends WC_Inventory_Overview_Test_Case {

	/**
	 * Admin user id.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Setup.
	 */
	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Capability map defaults to manage_woocommerce.
	 */
	public function test_purchasing_caps_default_to_manage_woocommerce() {
		$this->assertSame( 'manage_woocommerce', WC_Inventory_Overview_Purchasing_Caps::capability( WC_Inventory_Overview_Purchasing_Caps::VIEW_PO ) );
		$this->assertTrue( WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::EDIT_PO ) );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$this->assertFalse( WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::PLACE_PO ) );
	}

	/**
	 * Request tokens are one-shot.
	 */
	public function test_request_token_is_one_shot() {
		$token = WC_Inventory_Overview_PO_Request_Token::issue( 'place' );
		$this->assertTrue( WC_Inventory_Overview_PO_Request_Token::consume( $token, 'place' ) );
		$this->assertFalse( WC_Inventory_Overview_PO_Request_Token::consume( $token, 'place' ) );
		$this->assertFalse( WC_Inventory_Overview_PO_Request_Token::consume( 'bogus', 'place' ) );
	}

	/**
	 * List search, status filter, and sorting.
	 */
	public function test_list_search_filter_and_sort() {
		$supplier = $this->create_supplier( array( 'name' => 'Alpha Supply' ) );
		$product  = $this->create_simple_product();

		$a = WC_Inventory_Overview_PO_Service::create_draft(
			array(
				'supplier_id'        => (int) $supplier['id'],
				'supplier_reference' => 'REF-AAA',
			),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 1,
					'unit_cost'   => 2,
				),
			)
		);
		$b = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 1,
					'unit_cost'   => 2,
				),
			)
		);
		WC_Inventory_Overview_PO_Service::place( $b );

		$drafts = WC_Inventory_Overview_Purchase_Orders::list( array( 'status' => 'draft' ) );
		$this->assertCount( 1, $drafts );
		$this->assertSame( (int) $a, (int) $drafts[0]['id'] );

		$search = WC_Inventory_Overview_Purchase_Orders::list( array( 'search' => 'REF-AAA' ) );
		$this->assertCount( 1, $search );

		$sorted = WC_Inventory_Overview_Purchase_Orders::list(
			array(
				'orderby' => 'po_number',
				'order'   => 'ASC',
			)
		);
		$this->assertCount( 2, $sorted );
		$this->assertTrue( strcmp( $sorted[0]['po_number'], $sorted[1]['po_number'] ) <= 0 );
	}

	/**
	 * Detail URLs and status labels exist for admin rendering.
	 */
	public function test_detail_urls_and_labels() {
		$this->assertStringContainsString( 'tab=orders', WC_Inventory_Overview_PO_Admin::list_url() );
		$this->assertStringContainsString( 'action=new', WC_Inventory_Overview_PO_Admin::detail_url( 0 ) );
		$this->assertSame( 'Draft', WC_Inventory_Overview_PO_Statuses::label( 'draft' ) );
		$this->assertSame( 'Place', WC_Inventory_Overview_PO_Lifecycle::action_label( 'place' ) );
	}

	/**
	 * Save handler creates a draft via PRG (redirect).
	 */
	public function test_save_handler_creates_draft_with_prg() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product();
		$token    = WC_Inventory_Overview_PO_Request_Token::issue( 'save' );

		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new Exception( 'redirect:' . $location );
			}
		);

		$_POST    = array(
			'action'                 => 'wc_io_po_save',
			'po_id'                  => '0',
			'wc_io_po_save_nonce'    => wp_create_nonce( 'wc_io_po_save_0' ),
			'wc_io_po_request_token' => $token,
			'po'                     => array(
				'supplier_id' => (string) $supplier['id'],
				'currency'    => 'EUR',
				'note'        => 'Admin create',
			),
			'lines'                  => array(
				array(
					'product_id'  => (string) $product->get_id(),
					'qty_ordered' => '3',
					'unit_cost'   => '1.5',
				),
			),
		);
		$_REQUEST = $_POST;

		try {
			WC_Inventory_Overview_PO_Admin::handle_save();
			$this->fail( 'Expected redirect' );
		} catch ( Exception $e ) {
			$this->assertStringContainsString( 'redirect:', $e->getMessage() );
			$this->assertStringContainsString( 'wc_io_po=saved', $e->getMessage() );
		}

		$orders = WC_Inventory_Overview_Purchase_Orders::list();
		$this->assertCount( 1, $orders );
		$this->assertSame( 'draft', $orders[0]['status'] );
		$this->assertSame( 'Admin create', $orders[0]['note'] );
	}

	/**
	 * Place / cancel / close_short / duplicate handlers with tokens.
	 */
	public function test_lifecycle_handlers_and_duplicate() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product();
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 2,
					'unit_cost'   => 1,
				),
			)
		);

		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new Exception( 'redirect:' . $location );
			}
		);

		$_POST    = array(
			'po_id'                  => (string) $po_id,
			'wc_io_po_place_nonce'   => wp_create_nonce( 'wc_io_po_place_' . $po_id ),
			'wc_io_po_request_token' => WC_Inventory_Overview_PO_Request_Token::issue( 'place' ),
		);
		$_REQUEST = $_POST;
		try {
			WC_Inventory_Overview_PO_Admin::handle_place();
		} catch ( Exception $e ) {
			$this->assertStringContainsString( 'wc_io_po=placed', $e->getMessage() );
		}
		$this->assertSame( 'placed', WC_Inventory_Overview_PO_Service::get( $po_id )['status'] );

		// Replayed token must die.
		$_POST['wc_io_po_request_token'] = 'already-used';
		$_POST['wc_io_po_place_nonce']   = wp_create_nonce( 'wc_io_po_place_' . $po_id );
		$_REQUEST                        = $_POST;
		try {
			WC_Inventory_Overview_PO_Admin::handle_place();
			$this->fail( 'Expected token failure' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'already been submitted', $e->getMessage() );
		}

		$po2 = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 1,
					'unit_cost'   => 1,
				),
			)
		);
		WC_Inventory_Overview_PO_Service::place( $po2 );
		$_POST    = array(
			'po_id'                      => (string) $po2,
			'wc_io_po_close_short_nonce' => wp_create_nonce( 'wc_io_po_close_short_' . $po2 ),
			'wc_io_po_request_token'     => WC_Inventory_Overview_PO_Request_Token::issue( 'close_short' ),
		);
		$_REQUEST = $_POST;
		try {
			WC_Inventory_Overview_PO_Admin::handle_close_short();
		} catch ( Exception $e ) {
			$this->assertStringContainsString( 'closed_short', $e->getMessage() );
		}
		$this->assertSame( 'closed_short', WC_Inventory_Overview_PO_Service::get( $po2 )['status'] );

		$_POST    = array(
			'po_id'                    => (string) $po_id,
			'wc_io_po_duplicate_nonce' => wp_create_nonce( 'wc_io_po_duplicate_' . $po_id ),
			'wc_io_po_request_token'   => WC_Inventory_Overview_PO_Request_Token::issue( 'duplicate' ),
		);
		$_REQUEST = $_POST;
		try {
			WC_Inventory_Overview_PO_Admin::handle_duplicate();
		} catch ( Exception $e ) {
			$this->assertStringContainsString( 'duplicated', $e->getMessage() );
			$this->assertStringContainsString( 'action=edit', $e->getMessage() );
		}
	}

	/**
	 * Capability failure on save.
	 */
	public function test_save_handler_capability_failure() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$_POST = array(
			'po_id'                  => '0',
			'wc_io_po_save_nonce'    => wp_create_nonce( 'wc_io_po_save_0' ),
			'wc_io_po_request_token' => WC_Inventory_Overview_PO_Request_Token::issue( 'save' ),
			'po'                     => array( 'supplier_id' => '1' ),
		);
		try {
			WC_Inventory_Overview_PO_Admin::handle_save();
			$this->fail( 'Expected capability die' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'Insufficient permissions', $e->getMessage() );
		}
	}

	/**
	 * Nonce failure on place.
	 */
	public function test_place_handler_nonce_failure() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product();
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 1,
					'unit_cost'   => 1,
				),
			)
		);
		$_POST    = array(
			'po_id'                  => (string) $po_id,
			'wc_io_po_place_nonce'   => 'bad',
			'wc_io_po_request_token' => WC_Inventory_Overview_PO_Request_Token::issue( 'place' ),
		);
		try {
			WC_Inventory_Overview_PO_Admin::handle_place();
			$this->fail( 'Expected nonce die' );
		} catch ( WPDieException $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}
	}

	/**
	 * Timeline lists summaries without reason codes.
	 */
	public function test_timeline_events_have_summaries() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product();
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 1,
					'unit_cost'   => 1,
				),
			)
		);
		$events   = WC_Inventory_Overview_PO_Events::list_by_po( $po_id );
		$this->assertNotEmpty( $events[0]['summary'] );
		$this->assertArrayHasKey( 'reason_code', $events[0] ); // Stored but not rendered in the timeline UI.
	}

	/**
	 * Validation error surfaces via transient for admin notice.
	 */
	public function test_validation_error_notice_path() {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new Exception( 'redirect:' . $location );
			}
		);
		$_POST    = array(
			'po_id'                  => '0',
			'wc_io_po_save_nonce'    => wp_create_nonce( 'wc_io_po_save_0' ),
			'wc_io_po_request_token' => WC_Inventory_Overview_PO_Request_Token::issue( 'save' ),
			'po'                     => array(
				'supplier_id' => '0',
				'currency'    => 'EUR',
			),
			'lines'                  => array(),
		);
		$_REQUEST = $_POST;
		try {
			WC_Inventory_Overview_PO_Admin::handle_save();
		} catch ( Exception $e ) {
			$this->assertStringContainsString( 'wc_io_po=err', $e->getMessage() );
		}
		$err = get_transient( 'wc_io_po_err_' . get_current_user_id() );
		$this->assertNotEmpty( $err );
	}

	/**
	 * No receiving actions in admin action map surface.
	 */
	public function test_no_receiving_admin_actions() {
		$actions = WC_Inventory_Overview_PO_Lifecycle::available_actions( 'placed' );
		$this->assertNotContains( 'receive', $actions );
		$this->assertFalse( method_exists( 'WC_Inventory_Overview_PO_Admin', 'handle_receive' ) );
	}
}
