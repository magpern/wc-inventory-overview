<?php
/**
 * Architecture guard tests for Milestone M3 — Inventory Position (D12).
 *
 * Source-scanning guards: only the Inventory Position Service may call the
 * new bulk repository reads; the Resolver and Service must never contain
 * write, stock-mutation, cost-mutation, or receiving-domain code; no
 * qty_received reference anywhere in the M3 surface.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Inventory_Position_Architecture extends WP_UnitTestCase {

	/**
	 * @return string
	 */
	private function includes_dir(): string {
		return WC_INVENTORY_OVERVIEW_PATH . 'includes/';
	}

	/**
	 * Strip PHP comments (block and line) so source-scanning assertions only
	 * see executable code -- explanatory prose ("no $wpdb", "No qty_received
	 * column") legitimately documents an absence and must not itself trip
	 * the guard it is describing.
	 *
	 * @param string $src PHP source.
	 */
	private function strip_comments( string $src ): string {
		$src = (string) preg_replace( '#/\*.*?\*/#s', '', $src );
		$src = (string) preg_replace( '#//[^\n]*#', '', $src );
		return $src;
	}

	/**
	 * @return string[]
	 */
	private function all_include_files(): array {
		$files = glob( $this->includes_dir() . '*.php' );
		return is_array( $files ) ? $files : array();
	}

	/**
	 * D12: only the Position Service calls the two new bulk repository methods.
	 */
	public function test_only_service_calls_bulk_repository_methods() {
		$callers = array();

		foreach ( $this->all_include_files() as $file ) {
			$basename = basename( $file );
			if ( 'class-wc-inventory-overview-purchase-order-lines.php' === $basename ) {
				continue; // Definition site, not a caller.
			}

			$src = (string) file_get_contents( $file );
			if ( false !== strpos( $src, 'list_open_lines_for_product_ids' )
				|| false !== strpos( $src, 'list_open_lines_for_variation_ids' ) ) {
				$callers[] = $basename;
			}
		}

		$this->assertSame(
			array( 'class-wc-inventory-overview-inventory-position-service.php' ),
			$callers,
			'Only WC_Inventory_Overview_Inventory_Position_Service may call the bulk open-PO-line repository methods (D12: sole authoritative calculator).'
		);
	}

	/**
	 * Repository gains exactly two separate query methods, not one OR-based method.
	 */
	public function test_repository_exposes_two_separate_bulk_methods() {
		$src = (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-purchase-order-lines.php' );

		$this->assertStringContainsString( 'function list_open_lines_for_product_ids', $src );
		$this->assertStringContainsString( 'function list_open_lines_for_variation_ids', $src );
	}

	/**
	 * Resolver is stateless: no $wpdb, no product loading, no PO repository coupling.
	 */
	public function test_resolver_is_stateless_and_isolated() {
		$src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-inventory-position-resolver.php' ) );

		$this->assertStringNotContainsString( '$wpdb', $src, 'Resolver must be independent of $wpdb' );
		$this->assertStringNotContainsString( 'wc_get_product', $src, 'Resolver must be independent of WooCommerce product loading' );
		$this->assertStringNotContainsString( 'WC_Inventory_Overview_Purchase_Order_Lines', $src, 'Resolver must be independent of PO repositories' );
		$this->assertStringNotContainsString( 'WC_Inventory_Overview_Purchase_Orders', $src, 'Resolver must be independent of PO repositories' );
	}

	/**
	 * Resolver and Service contain no write, stock-mutation, cost-mutation, or receiving code.
	 */
	public function test_no_write_stock_or_receiving_operations_in_position_classes() {
		$forbidden = array(
			'set_stock_quantity',
			'wc_update_product_stock',
			'update_post_meta',
			'qty_received',
			'goods_receipt',
			'Goods_Receipt',
			'GoodsReceipt',
			'->insert(',
			'->update(',
			'->delete(',
		);

		$files = array(
			$this->includes_dir() . 'class-wc-inventory-overview-inventory-position-resolver.php',
			$this->includes_dir() . 'class-wc-inventory-overview-inventory-position-service.php',
		);

		foreach ( $files as $file ) {
			$src = (string) file_get_contents( $file );
			foreach ( $forbidden as $needle ) {
				$this->assertStringNotContainsString( $needle, $src, basename( $file ) . ' must not contain forbidden token: ' . $needle );
			}
		}
	}

	/**
	 * No qty_received reference in the Resolver/Service themselves — the two
	 * classes D12 names as the sole calculator surface. Revised for M5 (not
	 * silently broken — M5 implementation plan §Testing "M4 guard-revision
	 * audit"): the pre-M5 version of this test also scanned
	 * class-wc-inventory-overview-purchase-order-lines.php, on the premise that
	 * the entire M3 surface (Resolver + Service + the repository it reads through)
	 * was qty_received-free. That repository is not M3-exclusive — it is the same
	 * shared Purchase Order line repository M5 extends with qty_received as its
	 * sole physical writer (WC_Inventory_Overview_Purchase_Order_Lines::
	 * increment_qty_received()), so it legitimately references qty_received now.
	 * What actually matters for D12 (one authoritative calculator, no N+1) is
	 * untouched by this: the Resolver and Service still never reference
	 * qty_received directly — they only ever see the already-computed
	 * `outstanding` figure query_open_lines() returns, unchanged in shape.
	 */
	public function test_no_qty_received_in_resolver_or_service() {
		$files = array(
			$this->includes_dir() . 'class-wc-inventory-overview-inventory-position-resolver.php',
			$this->includes_dir() . 'class-wc-inventory-overview-inventory-position-service.php',
		);

		foreach ( $files as $file ) {
			$src = $this->strip_comments( (string) file_get_contents( $file ) );
			$this->assertStringNotContainsString( 'qty_received', $src, basename( $file ) . ' must not reference qty_received in executable code' );
		}
	}

	/**
	 * Service never refetches WooCommerce products (caller-supplied On Hand only).
	 */
	public function test_service_never_refetches_products() {
		$src = (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-inventory-position-service.php' );

		$this->assertStringNotContainsString( 'wc_get_product', $src );
		$this->assertStringNotContainsString( 'wc_get_products', $src );
	}

	/**
	 * Service reuses the existing line-level delay predicate rather than inventing new delay logic.
	 */
	public function test_delay_logic_is_reused_not_reinvented() {
		$repo_src = (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-purchase-order-lines.php' );
		$this->assertStringContainsString( 'WC_Inventory_Overview_PO_Delay::sql_line_delayed_predicate', $repo_src );

		$service_src = (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-inventory-position-service.php' );
		$this->assertStringNotContainsString( 'DATE_ADD', $service_src, 'Service must not reimplement delay SQL' );
	}
}
