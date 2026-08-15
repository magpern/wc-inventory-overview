<?php
/**
 * M25 WP-M25-6: full crafted-POST security/validation matrix for
 * WC_Inventory_Overview_Replenishment_Commit_Admin::handle_commit(), plus
 * capability/nonce/token isolation.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Commit_Admin_Security extends WC_Inventory_Overview_Test_Case {

	/**
	 * @var string|null
	 */
	private $redirect_to = null;

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		$this->redirect_to = null;

		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10, 1 );
	}

	public function tearDown(): void {
		remove_all_filters( 'wc_io_purchasing_capability_map' );
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10 );
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
		WC_Inventory_Overview_Replenishment_Commit_Service::reset_test_seams();
		parent::tearDown();
	}

	public function capture_redirect( $location ) {
		$this->redirect_to = $location;
		throw new Exception( 'wp_redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function admin_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	private function make_needs_reorder_product( array $props = array() ): WC_Product_Simple {
		$product = $this->create_simple_product( array_merge( array( 'stock_qty' => 1 ), $props ) );
		$product->set_low_stock_amount( 5 );
		$product->save();
		return $product;
	}

	private function valid_post( array $items ): array {
		return array(
			'action'                                   => 'wc_io_replenishment_commit',
			'wc_io_replenishment_commit_nonce'         => wp_create_nonce( 'wc_io_replenishment_commit' ),
			'wc_io_replenishment_commit_request_token' => WC_Inventory_Overview_PO_Request_Token::issue( 'replenishment_commit' ),
			'items'                                    => $items,
		);
	}

	/**
	 * Set $_POST AND keep $_REQUEST in sync -- WP core's check_admin_referer()/
	 * wp_verify_nonce() read $_REQUEST, which PHP only auto-populates from
	 * $_POST once at the start of a real HTTP request; a test that mutates
	 * $_POST directly after bootstrap must resync $_REQUEST itself.
	 *
	 * @param array<string,mixed> $post Full $_POST payload (e.g. from valid_post()).
	 */
	private function set_post( array $post ): void {
		$_POST    = $post;
		$_REQUEST = $post;
	}

	private function throw_on_die() {
		$handler = static function ( $message, $title = '', $args = array() ) {
			throw new WPDieException( is_string( $message ) ? $message : 'wp_die' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		};
		add_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
		add_filter( 'wp_die_ajax_handler', $handler, PHP_INT_MAX );
		return $handler;
	}

	private function remove_die_handler( $handler ) {
		remove_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
		remove_filter( 'wp_die_ajax_handler', $handler, PHP_INT_MAX );
	}

	private function run_expecting_redirect( callable $callback ): string {
		try {
			$callback();
			$this->fail( 'Expected wp_safe_redirect/exit.' );
		} catch ( Exception $e ) {
			$this->assertStringStartsWith( 'wp_redirect:', $e->getMessage() );
		}
		$this->assertNotEmpty( $this->redirect_to );
		return (string) $this->redirect_to;
	}

	// -----------------------------------------------------------------
	// Capability / nonce / token isolation.
	// -----------------------------------------------------------------

	public function test_capability_denied_403() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );
		$this->set_post( $this->valid_post( array() ) );

		$handler = $this->throw_on_die();
		try {
			$this->expectException( WPDieException::class );
			WC_Inventory_Overview_Replenishment_Commit_Admin::handle_commit();
		} finally {
			$this->remove_die_handler( $handler );
		}
	}

	/**
	 * Split VIEW_PO / EDIT_PO onto distinct WP caps so a VIEW_PO-only user
	 * can be constructed without inventing new Purchasing_Caps constants
	 * (WP2 OBS-02).
	 */
	private function split_view_and_edit_caps(): void {
		add_filter(
			'wc_io_purchasing_capability_map',
			static function ( array $map ) {
				$map[ WC_Inventory_Overview_Purchasing_Caps::VIEW_PO ] = 'wc_io_test_view_po';
				$map[ WC_Inventory_Overview_Purchasing_Caps::EDIT_PO ] = 'wc_io_test_edit_po';
				return $map;
			}
		);
	}

	public function test_view_po_only_user_cannot_commit() {
		$this->split_view_and_edit_caps();
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'wc_io_test_view_po' );
		wp_set_current_user( $user_id );
		$this->set_post( $this->valid_post( array() ) );

		$handler = $this->throw_on_die();
		try {
			$this->expectException( WPDieException::class );
			WC_Inventory_Overview_Replenishment_Commit_Admin::handle_commit();
		} finally {
			$this->remove_die_handler( $handler );
		}
	}

	public function test_edit_products_only_user_cannot_commit() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user_id );
		$this->set_post( $this->valid_post( array() ) );

		$handler = $this->throw_on_die();
		try {
			$this->expectException( WPDieException::class );
			WC_Inventory_Overview_Replenishment_Commit_Admin::handle_commit();
		} finally {
			$this->remove_die_handler( $handler );
		}
	}

	public function test_missing_nonce_dies() {
		$this->admin_user();
		$post = $this->valid_post( array() );
		unset( $post['wc_io_replenishment_commit_nonce'] );
		$this->set_post( $post );

		$handler = $this->throw_on_die();
		try {
			$this->expectException( WPDieException::class );
			WC_Inventory_Overview_Replenishment_Commit_Admin::handle_commit();
		} finally {
			$this->remove_die_handler( $handler );
		}
	}

	/**
	 * A token issued for a different context ('save', reused from PO_Admin's
	 * own vocabulary) must never validate for 'replenishment_commit' --
	 * mirrors WP-M25-1's PO_Request_Token context-isolation characterization.
	 */
	public function test_token_from_wrong_context_rejected() {
		$this->admin_user();
		$product = $this->make_needs_reorder_product();
		$post    = $this->valid_post(
			array( array( 'selected' => '1', 'product_id' => $product->get_id(), 'variation_id' => 0, 'qty' => '1' ) )
		);
		$post['wc_io_replenishment_commit_request_token'] = WC_Inventory_Overview_PO_Request_Token::issue( 'save' );
		$this->set_post( $post );

		$handler = $this->throw_on_die();
		try {
			$this->expectException( WPDieException::class );
			WC_Inventory_Overview_Replenishment_Commit_Admin::handle_commit();
		} finally {
			$this->remove_die_handler( $handler );
		}
	}

	public function test_token_replay_rejected_on_second_submission() {
		$this->admin_user();
		$supplier = $this->create_supplier();
		$product  = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $supplier['id'] );

		$this->set_post(
			$this->valid_post(
				array( array( 'selected' => '1', 'product_id' => $product->get_id(), 'variation_id' => 0, 'qty' => '2' ) )
			)
		);

		$this->run_expecting_redirect(
			static function () {
				WC_Inventory_Overview_Replenishment_Commit_Admin::handle_commit();
			}
		);

		// Replay the exact same POST (same token, same nonce -- nonce alone
		// is still valid within its window, only the token is one-shot).
		$this->redirect_to = null;
		$replay = $_POST;
		$replay['wc_io_replenishment_commit_nonce'] = wp_create_nonce( 'wc_io_replenishment_commit' );
		$this->set_post( $replay );

		$handler = $this->throw_on_die();
		try {
			$this->expectException( WPDieException::class );
			WC_Inventory_Overview_Replenishment_Commit_Admin::handle_commit();
		} finally {
			$this->remove_die_handler( $handler );
		}
	}

	// -----------------------------------------------------------------
	// Crafted-POST matrix.
	// -----------------------------------------------------------------

	public function test_forged_top_level_fields_have_zero_effect() {
		$this->admin_user();
		$real_supplier = $this->create_supplier( array( 'default_currency' => 'EUR' ) );
		$product       = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $real_supplier['id'] );

		$this->set_post(
			$this->valid_post(
				array(
					array(
						'selected'     => '1',
						'product_id'   => $product->get_id(),
						'variation_id' => 0,
						'qty'          => '3',
						'supplier_id'  => 999999,
						'currency'     => 'SEK',
					),
				)
			)
		);

		$location = $this->run_expecting_redirect(
			static function () {
				WC_Inventory_Overview_Replenishment_Commit_Admin::handle_commit();
			}
		);

		$this->assertStringContainsString( 'wc_io_commit_result=', $location );
		$table = WC_Inventory_Overview_Purchase_Orders::table_name();
		global $wpdb;
		$po = $wpdb->get_row( "SELECT * FROM {$table} LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( (int) $real_supplier['id'], (int) $po['supplier_id'] );
		$this->assertSame( 'EUR', $po['currency'] );
	}

	public function test_variable_parent_selection_skipped_not_fatal() {
		$this->admin_user();
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1', 'stock_qty' => 100 ) ) );
		$this->set_post(
			$this->valid_post(
				array( array( 'selected' => '1', 'product_id' => $variable->get_id(), 'variation_id' => 0, 'qty' => '1' ) )
			)
		);

		$location = $this->run_expecting_redirect(
			static function () {
				WC_Inventory_Overview_Replenishment_Commit_Admin::handle_commit();
			}
		);

		$this->assertStringContainsString( 'wc_io_commit_result=', $location, 'A variable-parent selection is skipped, not a request failure.' );
	}

	public function test_negative_qty_selected_row_gets_prg_notice_not_wp_die() {
		$this->admin_user();
		$product = $this->make_needs_reorder_product();
		$this->set_post(
			$this->valid_post(
				array( array( 'selected' => '1', 'product_id' => $product->get_id(), 'variation_id' => 0, 'qty' => '-5' ) )
			)
		);

		$location = $this->run_expecting_redirect(
			static function () {
				WC_Inventory_Overview_Replenishment_Commit_Admin::handle_commit();
			}
		);

		$this->assertStringContainsString( 'wc_io_commit_validation_err=1', $location );
	}

	public function test_zero_qty_selected_row_gets_prg_notice() {
		$this->admin_user();
		$product = $this->make_needs_reorder_product();
		$this->set_post(
			$this->valid_post(
				array( array( 'selected' => '1', 'product_id' => $product->get_id(), 'variation_id' => 0, 'qty' => '0' ) )
			)
		);

		$location = $this->run_expecting_redirect(
			static function () {
				WC_Inventory_Overview_Replenishment_Commit_Admin::handle_commit();
			}
		);

		$this->assertStringContainsString( 'wc_io_commit_validation_err=1', $location );
	}

	public function test_non_numeric_qty_selected_row_gets_prg_notice() {
		$this->admin_user();
		$product = $this->make_needs_reorder_product();
		$this->set_post(
			$this->valid_post(
				array( array( 'selected' => '1', 'product_id' => $product->get_id(), 'variation_id' => 0, 'qty' => 'abc' ) )
			)
		);

		$location = $this->run_expecting_redirect(
			static function () {
				WC_Inventory_Overview_Replenishment_Commit_Admin::handle_commit();
			}
		);

		$this->assertStringContainsString( 'wc_io_commit_validation_err=1', $location );
	}

	public function test_over_cap_selection_dies_400() {
		$this->admin_user();
		$rows = array();
		for ( $i = 1; $i <= WC_Inventory_Overview_Replenishment_Commit_Service::MAX_COMMIT_LINES + 1; $i++ ) {
			$rows[] = array( 'selected' => '1', 'product_id' => $i, 'variation_id' => 0, 'qty' => '1' );
		}
		$this->set_post( $this->valid_post( $rows ) );

		$handler = $this->throw_on_die();
		try {
			$this->expectException( WPDieException::class );
			WC_Inventory_Overview_Replenishment_Commit_Admin::handle_commit();
		} finally {
			$this->remove_die_handler( $handler );
		}
	}

	/**
	 * §48 Amendment C: duplicate submitted identities (same product/variation
	 * appearing more than once via array-index tricks) must never crash, never
	 * double-create.
	 */
	public function test_duplicate_submitted_identities_collapse_gracefully() {
		$this->admin_user();
		$supplier = $this->create_supplier();
		$product  = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $supplier['id'] );

		$this->set_post(
			$this->valid_post(
				array(
					array( 'selected' => '1', 'product_id' => $product->get_id(), 'variation_id' => 0, 'qty' => '5' ),
					array( 'selected' => '1', 'product_id' => $product->get_id(), 'variation_id' => 0, 'qty' => '9' ),
				)
			)
		);

		$this->run_expecting_redirect(
			static function () {
				WC_Inventory_Overview_Replenishment_Commit_Admin::handle_commit();
			}
		);

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() . ' WHERE product_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$product->get_id()
			)
		);
		$this->assertSame( 1, $count, 'Duplicate submitted identities must collapse to exactly one PO line, never double-create.' );
	}

	/**
	 * §7: an unchecked row's sibling fields (garbage product_id/qty) are
	 * discarded, never validated -- must not cause a wp_die even though the
	 * data would be invalid if it HAD been selected.
	 */
	public function test_unchecked_row_sibling_fields_discarded_never_validated() {
		$this->admin_user();
		$supplier = $this->create_supplier();
		$product  = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $supplier['id'] );

		$this->set_post(
			$this->valid_post(
				array(
					// Unselected row with deliberately garbage data.
					array( 'product_id' => 'not-a-number', 'variation_id' => -1, 'qty' => 'also-garbage' ),
					array( 'selected' => '1', 'product_id' => $product->get_id(), 'variation_id' => 0, 'qty' => '3' ),
				)
			)
		);

		$location = $this->run_expecting_redirect(
			static function () {
				WC_Inventory_Overview_Replenishment_Commit_Admin::handle_commit();
			}
		);

		$this->assertStringContainsString( 'wc_io_commit_result=', $location, 'The garbage unselected row must never cause a validation failure.' );
	}

	/**
	 * §48 Amendment D: quantity is mapped by canonical identity key, never
	 * array position -- reorder the submitted rows relative to a
	 * multi-supplier plan and confirm each item's own quantity lands
	 * correctly, not swapped.
	 */
	public function test_reordered_rows_map_quantity_by_identity_not_position() {
		$this->admin_user();
		$supplier_a = $this->create_supplier();
		$supplier_b = $this->create_supplier();
		$product_a  = $this->make_needs_reorder_product();
		$product_b  = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product_a->get_id(), $supplier_a['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product_b->get_id(), $supplier_b['id'] );

		// Deliberately reversed order relative to ascending supplier_id/id.
		$this->set_post(
			$this->valid_post(
				array(
					array( 'selected' => '1', 'product_id' => $product_b->get_id(), 'variation_id' => 0, 'qty' => '77' ),
					array( 'selected' => '1', 'product_id' => $product_a->get_id(), 'variation_id' => 0, 'qty' => '33' ),
				)
			)
		);

		$this->run_expecting_redirect(
			static function () {
				WC_Inventory_Overview_Replenishment_Commit_Admin::handle_commit();
			}
		);

		global $wpdb;
		$qty_a = (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT qty_ordered FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() . ' WHERE product_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$product_a->get_id()
			)
		);
		$qty_b = (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT qty_ordered FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() . ' WHERE product_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$product_b->get_id()
			)
		);

		$this->assertEqualsWithDelta( 33.0, $qty_a, 0.0001, 'product_a must receive its own submitted quantity (33), not product_b\'s.' );
		$this->assertEqualsWithDelta( 77.0, $qty_b, 0.0001, 'product_b must receive its own submitted quantity (77), not product_a\'s.' );
	}

	public function test_no_lines_selected_redirects_with_validation_notice() {
		$this->admin_user();
		$product = $this->make_needs_reorder_product();
		$this->set_post(
			$this->valid_post(
				array( array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'qty' => '1' ) ) // Never selected.
			)
		);

		$location = $this->run_expecting_redirect(
			static function () {
				WC_Inventory_Overview_Replenishment_Commit_Admin::handle_commit();
			}
		);

		$this->assertStringContainsString( 'wc_io_commit_validation_err=1', $location );
	}

	public function test_missing_items_key_entirely_dies_400() {
		$this->admin_user();
		$post = $this->valid_post( array() );
		unset( $post['items'] );
		$this->set_post( $post );

		$handler = $this->throw_on_die();
		try {
			$this->expectException( WPDieException::class );
			WC_Inventory_Overview_Replenishment_Commit_Admin::handle_commit();
		} finally {
			$this->remove_die_handler( $handler );
		}
	}

	public function test_selected_row_missing_required_field_dies_400() {
		$this->admin_user();
		$this->set_post(
			$this->valid_post(
				array( array( 'selected' => '1', 'product_id' => 5 ) ) // Missing variation_id/qty.
			)
		);

		$handler = $this->throw_on_die();
		try {
			$this->expectException( WPDieException::class );
			WC_Inventory_Overview_Replenishment_Commit_Admin::handle_commit();
		} finally {
			$this->remove_die_handler( $handler );
		}
	}
}
