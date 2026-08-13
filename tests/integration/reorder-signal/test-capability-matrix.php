<?php
/**
 * Consolidated M21 capability-matrix regression pass (WP-M21-6): fills the
 * two Reorder Signal surfaces not yet directly covered by WP-M21-2..5's own
 * test files -- the inline-stock AJAX badge refresh, and the
 * edit_products-only negative case for the variable-parent rollup badge --
 * proving every Reorder Signal surface obeys the single rule (BR-M21-4):
 * present for manage_woocommerce, absent for edit_products-only.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Reorder_Signal_Capability_Matrix extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );

		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}
	}

	public function tearDown(): void {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
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
	 * A user with edit_products AND edit_product (own products) but
	 * explicitly NOT manage_woocommerce -- the precise boundary BR-M21-4
	 * governs. Administrator/shop_manager roles carry manage_woocommerce by
	 * default in a WooCommerce install, so this must be built from a bare
	 * subscriber role, matching the same pattern already used in
	 * test-list-table-reorder-badges.php and
	 * test-dashboard-reorder-surfaces.php.
	 *
	 * @return int User ID.
	 */
	private function create_edit_products_only_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		// WordPress's own map_meta_cap() for a *published* post requires
		// edit_published_products in addition to edit_products/
		// edit_others_products (a pre-existing, non-M21 requirement of
		// ajax_save_inline_stock()'s current_user_can( 'edit_product', $id )
		// check) -- none of these is manage_woocommerce, the one
		// capability BR-M21-4 actually governs.
		$user->add_cap( 'edit_products' );
		$user->add_cap( 'edit_others_products' );
		$user->add_cap( 'edit_published_products' );
		return $user_id;
	}

	/**
	 * @param callable $callback AJAX handler invocation.
	 * @return array<string, mixed>
	 */
	private function run_ajax( callable $callback ): array {
		$force_throwing_die_handler = static function () {
			return static function ( $message, $title = '', $args = array() ) {
				throw new WPDieException( is_string( $message ) ? $message : 'wp_die' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- re-thrown wp_die() message, not output.
			};
		};
		add_filter( 'wp_die_ajax_handler', $force_throwing_die_handler, PHP_INT_MAX );

		try {
			ob_start();
			$callback();
			$json = ob_get_clean();
			$this->fail( 'Expected wp_send_json_*() to call wp_die().' );
		} catch ( WPDieException $e ) {
			$json = ob_get_clean();
		} finally {
			remove_filter( 'wp_die_ajax_handler', $force_throwing_die_handler, PHP_INT_MAX );
		}

		$response = json_decode( $json, true );
		$this->assertIsArray( $response, 'Raw JSON output: ' . var_export( $json, true ) );
		return $response;
	}

	/**
	 * manage_woocommerce viewer: the inline-stock AJAX success response's
	 * badgesHtml includes the Reorder Signal badge for an already-low-stock
	 * product (BR-M21-4, applied to the AJAX badge-refresh call site added
	 * in WP-M21-2).
	 */
	public function test_ajax_badge_refresh_includes_reorder_signal_for_manage_woocommerce() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_POST      = array(
			'nonce'      => wp_create_nonce( 'wc_io_inventory' ),
			'product_id' => $product->get_id(),
			'stock_qty'  => 1, // Now low stock, no incoming -> needs_reorder.
		);
		$_REQUEST = $_POST;

		$response = $this->run_ajax(
			static function () use ( $controller ) {
				$controller->ajax_save_inline_stock();
			}
		);

		$this->assertTrue( $response['success'] );
		$this->assertStringContainsString( 'wc-io-badge-needs-reorder', $response['data']['badgesHtml'] );
	}

	/**
	 * edit_products-only viewer: the same AJAX response's badgesHtml never
	 * includes a Reorder Signal badge -- byte-identical to the pre-M21
	 * "Low stock" badge only (BR-M21-4, INV-M21-5).
	 */
	public function test_ajax_badge_refresh_excludes_reorder_signal_for_edit_products_only() {
		// Created while logged in as this user, so current_user_can(
		// 'edit_product', $id )'s pre-existing (non-M21) ownership-based
		// meta-cap mapping resolves via the plain edit_products capability
		// already granted, without needing edit_others_products too.
		$user_id = $this->create_edit_products_only_user();
		wp_set_current_user( $user_id );

		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_POST      = array(
			'nonce'      => wp_create_nonce( 'wc_io_inventory' ),
			'product_id' => $product->get_id(),
			'stock_qty'  => 1,
		);
		$_REQUEST = $_POST;

		$response = $this->run_ajax(
			static function () use ( $controller ) {
				$controller->ajax_save_inline_stock();
			}
		);

		$this->assertTrue( $response['success'], 'AJAX response: ' . var_export( $response, true ) );
		$this->assertStringContainsString( 'wc-io-badge-low', $response['data']['badgesHtml'], 'Pre-M21 Low stock badge must still render.' );
		$this->assertStringNotContainsString( 'wc-io-badge-needs-reorder', $response['data']['badgesHtml'] );
		$this->assertStringNotContainsString( 'wc-io-badge-covered-incoming', $response['data']['badgesHtml'] );
	}

	/**
	 * edit_products-only viewer: the variable-parent rollup "Needs reorder"
	 * child-badge never renders (extends WP-M21-3's manage_woocommerce-only
	 * coverage with the negative case, BR-M21-4/BR-M21-5).
	 */
	public function test_variable_parent_rollup_badge_absent_for_edit_products_only() {
		$variable = $this->create_variable_product(
			array(),
			array( array( 'name' => 'A', 'stock_qty' => 1 ) )
		);
		wc_delete_product_transients( $variable->get_id() );
		$fresh    = wc_get_product( $variable->get_id() );
		$children = $fresh->get_children();
		$v        = wc_get_product( $children[0] );
		$v->set_low_stock_amount( 5 );
		$v->save();

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $variable->get_id(), 'variation_id' => (int) $children[0], 'qty_ordered' => 1 ) );
		$this->place_po( $po['id'] );

		$user_id = $this->create_edit_products_only_user();
		wp_set_current_user( $user_id );

		$table = new WC_Inventory_Overview_List_Table();
		$table->prepare_items();
		ob_start();
		$table->display_rows();
		$html = (string) ob_get_clean();

		$this->assertSame( array(), $table->position_map );
		$this->assertStringNotContainsString( 'wc-io-badge-needs-reorder', $html );
	}
}
