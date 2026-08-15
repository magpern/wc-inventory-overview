<?php
/**
 * M24 WP-M24-6/WP-M24-7: bulk-action visibility gating (§10).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Planning_Tab_Visibility extends WC_Inventory_Overview_Test_Case {

	/**
	 * Access get_bulk_actions() via reflection -- it's a protected
	 * WP_List_Table method, same access pattern any other test in this
	 * suite touching list-table internals would need.
	 */
	private function get_bulk_actions( WC_Inventory_Overview_List_Table $table ): array {
		$method = new ReflectionMethod( $table, 'get_bulk_actions' );
		$method->setAccessible( true );
		return $method->invoke( $table );
	}

	/**
	 * A manage_woocommerce admin (VIEW_PO-eligible) sees the action.
	 */
	public function test_bulk_action_visible_to_view_po_capable_user() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$table   = new WC_Inventory_Overview_List_Table();
		$actions = $this->get_bulk_actions( $table );

		$this->assertArrayHasKey( 'wc_io_plan_replenishment', $actions );
	}

	/**
	 * §10 MEDIUM fix: an edit_products-only viewer who can never reach the
	 * Planning tab must never see the action offered.
	 */
	public function test_bulk_action_hidden_from_edit_products_only_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user_id );

		$table   = new WC_Inventory_Overview_List_Table();
		$actions = $this->get_bulk_actions( $table );

		$this->assertArrayNotHasKey( 'wc_io_plan_replenishment', $actions );

		// The four pre-existing mutating actions remain visible/unaffected
		// (INV-M24-1's UI counterpart: this milestone must not touch
		// existing bulk-action visibility for edit_products viewers).
		$this->assertArrayHasKey( 'wc_io_set_draft', $actions );
		$this->assertArrayHasKey( 'wc_io_hide_catalog', $actions );
		$this->assertArrayHasKey( 'wc_io_mark_instock', $actions );
		$this->assertArrayHasKey( 'wc_io_mark_outofstock', $actions );
	}
}
