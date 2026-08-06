<?php
/**
 * Integration tests for M4 capability gating (M4-D).
 *
 * WC_Inventory_Overview_Purchasing_Caps' new receipt action keys default to
 * manage_woocommerce; service-level enforcement is tested here directly
 * (UI-level enforcement mirrors WC_Inventory_Overview_PO_Admin's existing
 * guard() pattern and is not re-tested here).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Goods_Receipt_Capability extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Receipt_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_Goods_Receipt_Numbering::OPTION_KEY );
	}

	/**
	 * All five new action keys default to manage_woocommerce, and no new
	 * WordPress capability is registered.
	 */
	public function test_default_capability_map() {
		$actions = array(
			WC_Inventory_Overview_Purchasing_Caps::VIEW_RECEIPT,
			WC_Inventory_Overview_Purchasing_Caps::EDIT_RECEIPT,
			WC_Inventory_Overview_Purchasing_Caps::POST_RECEIPT,
			WC_Inventory_Overview_Purchasing_Caps::VOID_RECEIPT,
			WC_Inventory_Overview_Purchasing_Caps::DELETE_RECEIPT,
		);
		foreach ( $actions as $action ) {
			$this->assertSame( 'manage_woocommerce', WC_Inventory_Overview_Purchasing_Caps::capability( $action ) );
		}
	}

	/**
	 * An administrator (has manage_woocommerce) can create/post/void/delete.
	 */
	public function test_administrator_can_perform_all_actions() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$src = array(
			'wc_io_gr_currency'       => 'EUR',
			'wc_io_gr_line_product'   => array( $product->get_id() ),
			'wc_io_gr_line_qty'       => array( '5' ),
			'wc_io_gr_line_unit_cost' => array( '10' ),
		);

		$id = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post( $src );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );

		$token   = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' );
		$posted  = WC_Inventory_Overview_Goods_Receipt_Service::post( $id, $token );
		$this->assertIsArray( $posted, is_wp_error( $posted ) ? $posted->get_error_message() : '' );

		$void_token = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_void' );
		$voided     = WC_Inventory_Overview_Goods_Receipt_Service::void( $id, 'test', $void_token );
		$this->assertIsArray( $voided, is_wp_error( $voided ) ? $voided->get_error_message() : '' );
	}

	/**
	 * A lesser-privileged user (subscriber) cannot create, post, void, or delete —
	 * every service entry point rejects with wc_io_gr_forbidden, service-level,
	 * independent of any UI-level guard.
	 */
	public function test_subscriber_cannot_perform_any_action() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$src = array(
			'wc_io_gr_currency'       => 'EUR',
			'wc_io_gr_line_product'   => array( $product->get_id() ),
			'wc_io_gr_line_qty'       => array( '5' ),
			'wc_io_gr_line_unit_cost' => array( '10' ),
		);
		$create_result = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post( $src );
		$this->assertWPError( $create_result );
		$this->assertSame( 'wc_io_gr_forbidden', $create_result->get_error_code() );

		// Create a draft as admin, then attempt post/void/delete as subscriber.
		wp_set_current_user( $admin_id );
		$id = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post( $src );
		$this->assertIsInt( $id );
		wp_set_current_user( $subscriber_id );

		$post_token = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' );
		$post_result = WC_Inventory_Overview_Goods_Receipt_Service::post( $id, $post_token );
		$this->assertWPError( $post_result );
		$this->assertSame( 'wc_io_gr_forbidden', $post_result->get_error_code() );

		$delete_result = WC_Inventory_Overview_Goods_Receipt_Service::delete_draft( $id );
		$this->assertWPError( $delete_result );
		$this->assertSame( 'wc_io_gr_forbidden', $delete_result->get_error_code() );

		// Post as admin, then attempt void as subscriber.
		wp_set_current_user( $admin_id );
		$token  = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' );
		$posted = WC_Inventory_Overview_Goods_Receipt_Service::post( $id, $token );
		$this->assertIsArray( $posted );
		wp_set_current_user( $subscriber_id );

		$void_token  = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_void' );
		$void_result = WC_Inventory_Overview_Goods_Receipt_Service::void( $id, 'test', $void_token );
		$this->assertWPError( $void_result );
		$this->assertSame( 'wc_io_gr_forbidden', $void_result->get_error_code() );
	}

	/**
	 * Filtering the capability map (as PO's map already supports) also gates M4 actions.
	 */
	public function test_capability_map_is_filterable() {
		add_filter(
			'wc_io_purchasing_capability_map',
			static function ( $map ) {
				$map[ WC_Inventory_Overview_Purchasing_Caps::POST_RECEIPT ] = 'manage_options';
				return $map;
			}
		);
		$this->assertSame( 'manage_options', WC_Inventory_Overview_Purchasing_Caps::capability( WC_Inventory_Overview_Purchasing_Caps::POST_RECEIPT ) );
		remove_all_filters( 'wc_io_purchasing_capability_map' );
	}
}
