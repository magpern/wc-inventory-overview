<?php
/**
 * M25 WP-M25-5/BR-M25-18: the Planning tab's commit form is gated on
 * EDIT_PO independently of the tab's own VIEW_PO gate, and the result
 * summary transient is scoped to the current user's own id.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Commit_Form_Capability_Gating extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
	}

	public function tearDown(): void {
		$_GET = array();
		remove_all_filters( 'wc_io_purchasing_capability_map' );
		parent::tearDown();
	}

	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function make_plan_line(): array {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $supplier['id'] );
		return array( $supplier, $product );
	}

	private function render(): string {
		ob_start();
		WC_Inventory_Overview_Purchasing_Page::instance()->render_page();
		return ob_get_clean();
	}

	/**
	 * Split VIEW_PO and EDIT_PO into two different WordPress capabilities so
	 * a VIEW_PO-only user can genuinely be constructed for this test.
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

	public function test_edit_po_user_sees_commit_form() {
		list( $supplier, $product ) = $this->make_plan_line();
		unset( $supplier, $product );

		$this->split_view_and_edit_caps();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'wc_io_test_view_po' );
		$user->add_cap( 'wc_io_test_edit_po' );
		wp_set_current_user( $user_id );

		$_GET['tab'] = WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING;
		$output      = $this->render();

		$this->assertStringContainsString( '<form', $output );
		$this->assertStringContainsString( 'Create Draft Purchase Orders', $output );
		// WP2 F-04: select-all must clamp to remaining global capacity, never
		// blindly check every non-disabled box in the group.
		$this->assertStringContainsString( 'MAX - outsideChecked', $output );
		$this->assertStringContainsString( 'selectedInGroup < remaining', $output );
	}

	public function test_view_po_only_user_does_not_see_commit_form() {
		list( $supplier, $product ) = $this->make_plan_line();
		unset( $supplier, $product );

		$this->split_view_and_edit_caps();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'wc_io_test_view_po' );
		// Deliberately no wc_io_test_edit_po.
		wp_set_current_user( $user_id );

		$_GET['tab'] = WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING;
		$output      = $this->render();

		$this->assertStringNotContainsString( 'Create Draft Purchase Orders', $output );
		$this->assertStringNotContainsString( 'wc_io_replenishment_commit', $output, 'A VIEW_PO-only user must see zero trace of the commit form.' );
	}

	/**
	 * §21: the result-transient key is always built from the CURRENT user's
	 * own id -- a user cannot view another user's result even by guessing
	 * a result_id.
	 */
	public function test_result_summary_scoped_to_current_user_only() {
		$owner_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$result_id = str_repeat( 'a', 12 );

		set_transient(
			WC_Inventory_Overview_Replenishment_Commit_Admin::RESULT_TRANSIENT_PREFIX . $owner_id . '_' . $result_id,
			array( 'created' => array(), 'failed' => array(), 'skipped' => array() ),
			120
		);

		wp_set_current_user( $other_id );
		$_GET['tab'] = WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING;
		$_GET[ WC_Inventory_Overview_Replenishment_Commit_Admin::RESULT_QUERY_ARG ] = $result_id;

		$output = $this->render();

		$this->assertStringNotContainsString( 'Replenishment commit finished', $output, 'A different user must never see another user\'s commit result.' );

		// The owner, however, can.
		wp_set_current_user( $owner_id );
		$output_owner = $this->render();
		$this->assertStringContainsString( 'Replenishment commit finished', $output_owner );
	}

	public function test_malformed_result_id_shape_silently_ignored() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$_GET['tab'] = WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING;
		$_GET[ WC_Inventory_Overview_Replenishment_Commit_Admin::RESULT_QUERY_ARG ] = '<script>alert(1)</script>';

		$output = $this->render();

		$this->assertStringNotContainsString( 'Replenishment commit finished', $output );
		$this->assertStringNotContainsString( '<script>alert', $output );
	}
}
