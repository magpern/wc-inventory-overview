<?php
/**
 * Architecture guard tests for M23 — Replenishment Defaults.
 *
 * Source-scanning guards: sole meta-key ownership (INV-M23-12), no SQL in
 * Replenishment_Defaults or Product_Replenishment_Admin (INV-M23-13), no
 * new admin_post/wp_ajax registration and no new public hook/filter
 * (INV-M23-15), Reorder_Prefill_Service remains read-only and gains no
 * new meta-writing token (INV-M23-3, extending M22's own
 * test_zero_mutation_shaped_tokens assertion without weakening it),
 * capability checks route through WooCommerce's own product-edit gate,
 * never Purchasing_Caps (INV-M23-20), and DB_VERSION is unchanged
 * (INV-M23-19).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Defaults_Architecture extends WP_UnitTestCase {

	private function includes_dir(): string {
		return WC_INVENTORY_OVERVIEW_PATH . 'includes/';
	}

	private function strip_comments( string $src ): string {
		$src = (string) preg_replace( '#/\*.*?\*/#s', '', $src );
		$src = (string) preg_replace( '#//[^\n]*#', '', $src );
		return $src;
	}

	private function all_include_files(): array {
		return glob( $this->includes_dir() . '*.php' );
	}

	/**
	 * INV-M23-12: no class other than Replenishment_Defaults references
	 * either meta-key literal.
	 */
	public function test_sole_meta_key_ownership() {
		$keys = array( '_wc_io_preferred_supplier_id', '_wc_io_default_replenishment_qty' );

		foreach ( $this->all_include_files() as $file ) {
			$basename = basename( $file );
			if ( 'class-wc-inventory-overview-replenishment-defaults.php' === $basename ) {
				continue;
			}
			$src = (string) file_get_contents( $file );
			foreach ( $keys as $key ) {
				$this->assertStringNotContainsString( $key, $src, "{$basename} must not reference the meta key literal \"{$key}\" -- only Replenishment_Defaults may." );
			}
		}
	}

	/**
	 * INV-M23-13: no direct SQL in either new M23 class.
	 */
	public function test_no_sql_in_new_m23_files() {
		foreach (
			array(
				'class-wc-inventory-overview-replenishment-defaults.php',
				'class-wc-inventory-overview-product-replenishment-admin.php',
			) as $basename
		) {
			$src = (string) file_get_contents( $this->includes_dir() . $basename );
			$this->assertStringNotContainsString( '$wpdb', $src, "{$basename} must contain zero direct SQL." );
		}
	}

	/**
	 * INV-M23-15 / no new admin-post or AJAX mutation endpoint: neither
	 * new file registers its own handler.
	 */
	public function test_no_new_admin_post_or_ajax_handler() {
		foreach (
			array(
				'class-wc-inventory-overview-replenishment-defaults.php',
				'class-wc-inventory-overview-product-replenishment-admin.php',
			) as $basename
		) {
			$src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . $basename ) );
			$this->assertDoesNotMatchRegularExpression( '/add_action\(\s*[\'"]admin_post_/', $src, "{$basename} must not register a new admin_post_* action." );
			$this->assertDoesNotMatchRegularExpression( '/add_action\(\s*[\'"]wp_ajax_/', $src, "{$basename} must not register a new wp_ajax_* action." );
		}
	}

	/**
	 * INV-M23-15: no new public hook/filter is introduced -- neither new
	 * file fires its own do_action()/apply_filters() (they only hook INTO
	 * WooCommerce's existing hooks via add_action, which is expected).
	 */
	public function test_no_new_public_hook_introduced() {
		foreach (
			array(
				'class-wc-inventory-overview-replenishment-defaults.php',
				'class-wc-inventory-overview-product-replenishment-admin.php',
			) as $basename
		) {
			$src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . $basename ) );
			$this->assertStringNotContainsString( 'do_action(', $src, "{$basename} must not introduce a new action hook." );
			$this->assertStringNotContainsString( 'apply_filters(', $src, "{$basename} must not introduce a new filter hook." );
		}
	}

	/**
	 * INV-M23-3: Reorder_Prefill_Service remains strictly read-only.
	 * Extends (does not replace) M22's own
	 * test_zero_mutation_shaped_tokens assertion in
	 * tests/unit/reorder-prefill/test-reorder-prefill-architecture.php,
	 * which already forbids update_post_meta( in this file.
	 */
	public function test_prefill_service_still_never_writes_meta() {
		$src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-reorder-prefill-service.php' ) );

		$this->assertStringNotContainsString( 'update_post_meta(', $src );
		$this->assertStringNotContainsString( 'delete_post_meta(', $src );
		$this->assertStringNotContainsString( 'save_preferred_supplier(', $src, 'Reorder_Prefill_Service must only read defaults, never save them.' );
		$this->assertStringNotContainsString( 'save_default_qty(', $src, 'Reorder_Prefill_Service must only read defaults, never save them.' );
	}

	/**
	 * INV-M23-12: only Replenishment_Defaults calls update_post_meta()/
	 * delete_post_meta() for these two keys -- Product_Replenishment_Admin
	 * must delegate, never write directly.
	 */
	public function test_admin_orchestrator_never_writes_meta_directly() {
		$src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-product-replenishment-admin.php' ) );

		$this->assertStringNotContainsString( 'update_post_meta(', $src );
		$this->assertStringNotContainsString( 'delete_post_meta(', $src );
	}

	/**
	 * INV-M23-20: capability checks in the new admin class use
	 * WooCommerce's own product-edit gate, never Purchasing_Caps.
	 */
	public function test_capability_checks_use_wc_product_gate_not_purchasing_caps() {
		$src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-product-replenishment-admin.php' ) );

		$this->assertStringContainsString( "current_user_can( 'edit_product',", $src );
		$this->assertStringNotContainsString( 'Purchasing_Caps', $src, 'Product_Replenishment_Admin must not depend on Purchasing_Caps (§16 of the M23 plan).' );
	}

	/**
	 * INV-M23-19: DB_VERSION is unchanged by M23.
	 */
	public function test_db_version_unchanged() {
		$this->assertSame( '11', WC_Inventory_Overview_Install::DB_VERSION );
	}
}
