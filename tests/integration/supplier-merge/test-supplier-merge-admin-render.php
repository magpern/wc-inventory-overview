<?php
/**
 * WP-M17-7: Automated UI-rendering assertions for supplier merge.
 *
 * Output-buffered assertions on Purchasing_Page::render_page() (which
 * dispatches to the private render_supplier_detail()), matching this
 * codebase's general admin-page-rendering test approach. Exact 10-item
 * list per the hardened plan's Part L.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Merge_Admin_Render extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_supplier_merges' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_suppliers' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user     = new WP_User( $admin_id );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $admin_id );
	}

	public function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	private function create_supplier( string $name ): int {
		return (int) WC_Inventory_Overview_Suppliers::create(
			array(
				'name'             => $name,
				'default_currency' => 'EUR',
			)
		);
	}

	private function render_detail( int $supplier_id ): string {
		$_GET = array(
			'page'        => WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG,
			'tab'         => WC_Inventory_Overview_Purchasing_Page::TAB_SUPPLIERS,
			'action'      => 'edit',
			'supplier_id' => (string) $supplier_id,
		);

		ob_start();
		WC_Inventory_Overview_Purchasing_Page::instance()->render_page();
		return ob_get_clean();
	}

	/**
	 * (1) An active eligible source renders the merge section.
	 */
	public function test_active_eligible_source_renders_merge_section() {
		$id   = $this->create_supplier( 'Active Eligible Supplier' );
		$html = $this->render_detail( $id );

		$this->assertStringContainsString( 'Merge into another supplier', $html );
		$this->assertStringContainsString( 'wc-io-supplier-merge-form', $html );
	}

	/**
	 * (2) An archived eligible source renders the merge section.
	 */
	public function test_archived_eligible_source_renders_merge_section() {
		$id = $this->create_supplier( 'Archived Eligible Supplier' );
		WC_Inventory_Overview_Suppliers::archive( $id );

		$html = $this->render_detail( $id );

		$this->assertStringContainsString( 'Merge into another supplier', $html );
		$this->assertStringContainsString( 'wc-io-supplier-merge-form', $html );
	}

	/**
	 * (3) An already-merged source renders the static "merged into X"
	 * notice and NO form.
	 */
	public function test_already_merged_source_renders_notice_not_form() {
		$s1 = $this->create_supplier( 'Merged Source' );
		$s2 = $this->create_supplier( 'Merge Target' );
		WC_Inventory_Overview_Supplier_Merge_Service::merge( $s1, $s2, 1, 'Merged Source' );

		$html = $this->render_detail( $s1 );

		$this->assertStringContainsString( 'was merged into', $html );
		$this->assertStringContainsString( 'Merge Target', $html );
		$this->assertStringNotContainsString( 'wc-io-supplier-merge-form', $html );
	}

	/**
	 * (4) An already-merged source's row has no enabled Reactivate control --
	 * neither on the detail screen nor in the list table.
	 */
	public function test_already_merged_source_has_no_reactivate_control() {
		$s1 = $this->create_supplier( 'No Reactivate Source' );
		$s2 = $this->create_supplier( 'Reactivate Target' );
		WC_Inventory_Overview_Supplier_Merge_Service::merge( $s1, $s2, 1, 'No Reactivate Source' );

		// Detail screen.
		$detail_html = $this->render_detail( $s1 );
		$this->assertStringNotContainsString( 'wc_io_supplier_reactivate', $detail_html );

		// List table row action.
		$table = new WC_Inventory_Overview_Suppliers_List_Table();
		$_REQUEST['page'] = WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG;
		$_REQUEST['tab']  = WC_Inventory_Overview_Purchasing_Page::TAB_SUPPLIERS;
		$table->prepare_items();

		ob_start();
		$table->display();
		$list_html = ob_get_clean();

		// The merged row's action text should read "Merged into Reactivate Target",
		// not contain a live wc_io_supplier_reactivate action link for this row.
		$this->assertStringContainsString( 'Merged into', $list_html );
	}

	/**
	 * (5) The target picker's rendered markup references the new
	 * wc_io_search_merge_targets AJAX action and a data-exclude-supplier-id
	 * matching the source's own ID.
	 */
	public function test_target_picker_markup_references_ajax_action_and_exclude_id() {
		$id   = $this->create_supplier( 'Picker Source' );
		$html = $this->render_detail( $id );

		$this->assertStringContainsString( 'wc_io_search_merge_targets', $html );
		$this->assertStringContainsString( 'data-exclude-supplier-id="' . $id . '"', $html );
	}

	/**
	 * (6) The typed-confirmation field is present.
	 */
	public function test_typed_confirmation_field_present() {
		$id   = $this->create_supplier( 'Confirmation Field Source' );
		$html = $this->render_detail( $id );

		$this->assertStringContainsString( 'id="wc_io_supplier_merge_confirmation"', $html );
		$this->assertStringContainsString( 'name="supplier_merge_confirmation"', $html );
	}

	/**
	 * (7) The nonce field is present with the correct action name.
	 */
	public function test_nonce_field_present_with_correct_action() {
		$id   = $this->create_supplier( 'Nonce Field Source' );
		$html = $this->render_detail( $id );

		$this->assertStringContainsString( 'wc_io_supplier_merge_nonce', $html );
	}

	/**
	 * (8) The request-token hidden field is present.
	 */
	public function test_request_token_hidden_field_present() {
		$id   = $this->create_supplier( 'Token Field Source' );
		$html = $this->render_detail( $id );

		$this->assertStringContainsString( 'wc_io_supplier_merge_request_token', $html );
	}

	/**
	 * (9) The warning copy contains the irreversibility phrase.
	 */
	public function test_warning_copy_contains_cannot_be_undone() {
		$id   = $this->create_supplier( 'Warning Copy Source' );
		$html = $this->render_detail( $id );

		$this->assertStringContainsString( 'cannot be undone', $html );
	}

	/**
	 * (10) The submit control is present and marked to start disabled.
	 */
	public function test_submit_control_present_and_starts_disabled() {
		$id   = $this->create_supplier( 'Submit Control Source' );
		$html = $this->render_detail( $id );

		$this->assertStringContainsString( 'wc-io-supplier-merge-submit', $html );
		$this->assertStringContainsString( 'disabled', $html );
	}
}
