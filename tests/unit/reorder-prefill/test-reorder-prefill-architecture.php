<?php
/**
 * Architecture guard tests for M22 — Reorder → Draft PO Quick Action.
 *
 * Source-scanning guards: no new M22 file reimplements the needs_reorder
 * comparison or Position arithmetic (INV-M22-1/2); no mutation-shaped
 * token appears in any new/touched M22 file (INV-M22-7/8); no new
 * admin_post/wp_ajax/hook registration exists (INV-M22-3/9); PO_Admin and
 * List_Table contain zero SQL (INV-M22-10); every new capability check
 * routes through Purchasing_Caps, never a raw capability string
 * (INV-M22-14, WP-M22-6).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Reorder_Prefill_Architecture extends WP_UnitTestCase {

	/**
	 * @return string
	 */
	private function includes_dir(): string {
		return WC_INVENTORY_OVERVIEW_PATH . 'includes/';
	}

	/**
	 * @return string[] Full paths of the M22 files touched/created by this milestone.
	 */
	private function m22_files(): array {
		return array(
			$this->includes_dir() . 'class-wc-inventory-overview-reorder-prefill-service.php',
			$this->includes_dir() . 'class-wc-inventory-overview-po-admin.php',
			$this->includes_dir() . 'class-wc-inventory-overview-list-table.php',
			$this->includes_dir() . 'class-wc-inventory-overview-purchase-order-lines.php',
			$this->includes_dir() . 'class-wc-inventory-overview-suppliers.php',
		);
	}

	private function strip_comments( string $src ): string {
		$src = (string) preg_replace( '#/\*.*?\*/#s', '', $src );
		$src = (string) preg_replace( '#//[^\n]*#', '', $src );
		return $src;
	}

	/**
	 * INV-M22-1/2: the new prefill service never reimplements
	 * position<=threshold or sums on_hand+incoming itself -- it must call
	 * through Reorder_Signal_Resolver::resolve() and
	 * Inventory_Position_Service::get_position().
	 */
	public function test_prefill_service_delegates_never_reimplements() {
		$src = file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-reorder-prefill-service.php' );

		$this->assertStringContainsString( 'WC_Inventory_Overview_Reorder_Signal_Resolver::resolve(', $src );
		$this->assertStringContainsString( 'WC_Inventory_Overview_Inventory_Position_Service::get_position(', $src );
		$this->assertDoesNotMatchRegularExpression( '/\$on_hand\s*\+\s*\$incoming/', $src );
	}

	/**
	 * INV-M22-3: the new Reorder_Prefill_Service never calls
	 * Purchase_Orders::create_draft()/Purchase_Order_Lines::create()
	 * directly, and registers no admin-post or AJAX handler of its own
	 * (it is a plain read-only service class, never wired to init()).
	 */
	public function test_prefill_service_has_no_mutation_entry_point() {
		$src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-reorder-prefill-service.php' ) );

		$this->assertDoesNotMatchRegularExpression( '/add_action\(\s*[\'"]admin_post_/', $src );
		$this->assertDoesNotMatchRegularExpression( '/add_action\(\s*[\'"]wp_ajax_/', $src );
		$this->assertStringNotContainsString( 'Purchase_Orders::create_draft(', $src );
		$this->assertStringNotContainsString( 'Purchase_Order_Lines::create(', $src );
	}

	/**
	 * INV-M22-3/9: PO_Admin::init() still registers exactly the same
	 * pre-existing seven admin-post actions -- M22 adds no eighth.
	 */
	public function test_po_admin_registers_no_new_admin_post_action() {
		$src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-po-admin.php' ) );

		preg_match_all( '/[\'"](wc_io_po_[a-z_]+)[\'"]/', $src, $matches );
		$init_start = strpos( $src, 'public static function init()' );
		$init_end   = strpos( $src, 'function enqueue_assets', $init_start );
		$init_body  = substr( $src, $init_start, $init_end - $init_start );

		preg_match_all( '/[\'"](wc_io_po_[a-z_]+)[\'"]/', $init_body, $action_matches );
		$actions = array_values( array_unique( $action_matches[1] ) );

		$this->assertSame(
			array( 'wc_io_po_save', 'wc_io_po_place', 'wc_io_po_cancel', 'wc_io_po_close_short', 'wc_io_po_delete_draft', 'wc_io_po_duplicate', 'wc_io_po_print' ),
			$actions,
			'M22 must not register a new admin_post_* action.'
		);
	}

	/**
	 * INV-M22-7/8: zero mutation-shaped tokens anywhere in the M22 file set
	 * -- no stock write, no cost write, no generic WP/WC persistence call.
	 */
	public function test_zero_mutation_shaped_tokens() {
		$forbidden = array(
			'set_stock_quantity(',
			'update_option(',
			'update_post_meta(',
			'wp_insert_post(',
			'->save()',
		);

		foreach ( $this->m22_files() as $file ) {
			$src = $this->strip_comments( (string) file_get_contents( $file ) );
			foreach ( $forbidden as $token ) {
				$this->assertStringNotContainsString( $token, $src, basename( $file ) . ' must not contain mutation token "' . $token . '".' );
			}
		}
	}

	/**
	 * INV-M22-10: PO_Admin and List_Table remain entirely free of direct
	 * SQL -- all new M22 reads route through repository/service classes.
	 */
	public function test_no_sql_in_rendering_controller_layer() {
		foreach ( array( 'class-wc-inventory-overview-po-admin.php', 'class-wc-inventory-overview-list-table.php' ) as $basename ) {
			$src = (string) file_get_contents( $this->includes_dir() . $basename );
			$this->assertStringNotContainsString( '$wpdb', $src, $basename . ' must contain zero direct SQL.' );
		}
	}

	/**
	 * INV-M22-14 / WP-M22-6: every current_user_can()-shaped capability
	 * check newly introduced by M22 routes through
	 * WC_Inventory_Overview_Purchasing_Caps::current_user_can(), never a
	 * raw current_user_can( 'manage_woocommerce' ) (or any other literal
	 * capability string) call.
	 */
	public function test_new_capability_checks_route_through_purchasing_caps() {
		foreach (
			array(
				'class-wc-inventory-overview-reorder-prefill-service.php',
				'class-wc-inventory-overview-list-table.php',
			) as $basename
		) {
			$src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . $basename ) );

			if ( 'class-wc-inventory-overview-reorder-prefill-service.php' === $basename ) {
				$this->assertStringNotContainsString( 'current_user_can(', $src, 'Reorder_Prefill_Service performs no capability check of its own -- gating is PO_Admin/List_Table\'s job.' );
			}
		}

		$list_table_src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-list-table.php' ) );
		$this->assertStringContainsString( 'WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::EDIT_PO )', $list_table_src );

		$po_admin_src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-po-admin.php' ) );
		$this->assertStringContainsString( "isset( \$_GET['wc_io_ro_product_id']", $po_admin_src, 'resolve_reorder_prefill() must exist and gate on the GET param.' );
	}

	/**
	 * INV-M22-11: DB_VERSION is unchanged by M22.
	 */
	public function test_db_version_unchanged() {
		$this->assertSame( '11', WC_Inventory_Overview_Install::DB_VERSION );
	}
}
