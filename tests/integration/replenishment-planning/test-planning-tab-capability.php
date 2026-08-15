<?php
/**
 * M24 WP-M24-6/WP-M24-7: Planning tab capability contract (BR-M24-14).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Planning_Tab_Capability extends WC_Inventory_Overview_Test_Case {

	public function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	private function throw_on_die() {
		$handler = static function ( $message, $title = '', $args = array() ) {
			throw new WPDieException( is_string( $message ) ? $message : 'wp_die' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		};
		add_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
		add_filter( 'wp_die_ajax_handler', $handler, PHP_INT_MAX );
		return $handler;
	}

	/**
	 * BR-M24-14: viewing the Planning tab requires Purchasing_Caps::VIEW_PO
	 * (manage_woocommerce by default). An edit_products-only viewer (the
	 * capability that gates the rest of Inventory Overview) must not be
	 * able to reach it -- confirms M24 does not silently widen access.
	 */
	public function test_edit_products_only_user_cannot_reach_planning_tab() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user_id );

		$_GET['tab'] = WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING;

		$handler = $this->throw_on_die();
		try {
			$this->expectException( WPDieException::class );
			WC_Inventory_Overview_Purchasing_Page::instance()->render_page();
		} finally {
			remove_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
			remove_filter( 'wp_die_ajax_handler', $handler, PHP_INT_MAX );
		}
	}

	/**
	 * A manage_woocommerce administrator (VIEW_PO-eligible by default) can
	 * reach the Planning tab and it renders without dying.
	 */
	public function test_manage_woocommerce_admin_can_reach_planning_tab() {
		WC_Inventory_Overview_Install::create_tables();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$_GET['tab'] = WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING;

		ob_start();
		WC_Inventory_Overview_Purchasing_Page::instance()->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Replenishment Planning', $output );
	}

	/**
	 * A logged-out (anonymous) request cannot reach the Planning tab.
	 */
	public function test_anonymous_user_cannot_reach_planning_tab() {
		wp_set_current_user( 0 );
		$_GET['tab'] = WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING;

		$handler = $this->throw_on_die();
		try {
			$this->expectException( WPDieException::class );
			WC_Inventory_Overview_Purchasing_Page::instance()->render_page();
		} finally {
			remove_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
			remove_filter( 'wp_die_ajax_handler', $handler, PHP_INT_MAX );
		}
	}

	/**
	 * No query-arg bypass: an edit_products-only user cannot reach the
	 * Planning tab even with item_ids present in the query string.
	 */
	public function test_no_query_arg_bypass_with_item_ids_present() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user_id );

		$_GET['tab']      = WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING;
		$_GET['item_ids'] = '1,2,3';

		$handler = $this->throw_on_die();
		try {
			$this->expectException( WPDieException::class );
			WC_Inventory_Overview_Purchasing_Page::instance()->render_page();
		} finally {
			remove_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
			remove_filter( 'wp_die_ajax_handler', $handler, PHP_INT_MAX );
		}
	}
}
