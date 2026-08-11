<?php
/**
 * Architecture guard tests for Milestone M14 — Supplier Order History.
 *
 * Source-scanning guards enforcing docs/milestones/m14-implementation-plan.md
 * Part E: WC_Inventory_Overview_Supplier_Order_History_Service reads
 * exclusively through WC_Inventory_Overview_Purchase_Orders's own read
 * methods (INV-M14-3) -- never $wpdb, never Purchase_Order_Lines or
 * Goods_Receipts directly -- performs zero writes (INV-M14-1), and only the
 * Suppliers admin detail screen may call into it (sole-consumer discipline,
 * same mechanism used for Supplier_Lead_Time_Service / PO_Print_Renderer).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Order_History_Architecture extends WP_UnitTestCase {

	private const SERVICE_FILE         = 'class-wc-inventory-overview-supplier-order-history-service.php';
	private const PURCHASE_ORDERS_FILE = 'class-wc-inventory-overview-purchase-orders.php';

	/**
	 * Tokens that would indicate the service bypasses its one approved read
	 * owner and reads something else directly.
	 *
	 * @return string[]
	 */
	private function forbidden_read_tokens(): array {
		return array(
			'$wpdb',
			'Purchase_Order_Lines::',
			'Goods_Receipts::',
			'Receipt_Lines::',
			'Receipt_Costs::',
			'Suppliers::',
		);
	}

	/**
	 * Tokens that would indicate the service (or values_bulk() itself)
	 * mutates something -- both must be read-only by construction.
	 *
	 * @return string[]
	 */
	private function forbidden_write_tokens(): array {
		return array(
			'set_stock_quantity',
			'update_post_meta',
			'update_option',
			'->insert(',
			'->update(',
			'->delete(',
			'INSERT INTO',
			'UPDATE ',
			'DELETE FROM',
		);
	}

	/**
	 * @return string
	 */
	private function includes_dir(): string {
		return WC_INVENTORY_OVERVIEW_PATH . 'includes/';
	}

	/**
	 * @param string $file Absolute path.
	 * @return string
	 */
	private function src( string $file ): string {
		return (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin source file, not a remote URL.
	}

	/**
	 * Strip PHP comments so source-scanning assertions only see executable
	 * code.
	 *
	 * @param string $src PHP source.
	 * @return string
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
	 * INV-M14-3: the service must never bypass Purchase_Orders and read
	 * PO lines, receipts, or suppliers directly.
	 */
	public function test_service_has_zero_unapproved_read_access() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . self::SERVICE_FILE ) );

		foreach ( $this->forbidden_read_tokens() as $token ) {
			$this->assertStringNotContainsString( $token, $src, 'Supplier_Order_History_Service must not contain unapproved read token: ' . $token );
		}
	}

	/**
	 * INV-M14-3: the service sources data only through
	 * Purchase_Orders::count()/list()/values_bulk().
	 */
	public function test_service_uses_only_approved_read_owner_methods() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . self::SERVICE_FILE ) );

		$this->assertStringContainsString( 'WC_Inventory_Overview_Purchase_Orders::count(', $src );
		$this->assertStringContainsString( 'WC_Inventory_Overview_Purchase_Orders::list(', $src );
		$this->assertStringContainsString( 'WC_Inventory_Overview_Purchase_Orders::values_bulk(', $src );
	}

	/**
	 * INV-M14-1: the service performs zero writes.
	 */
	public function test_service_has_zero_write_tokens() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . self::SERVICE_FILE ) );

		foreach ( $this->forbidden_write_tokens() as $token ) {
			$this->assertStringNotContainsString( $token, $src, 'Supplier_Order_History_Service must not contain write token: ' . $token );
		}
	}

	/**
	 * The values_bulk() method itself, on Purchase_Orders (which otherwise
	 * legitimately writes elsewhere in the same file), must contain no
	 * write statement.
	 */
	public function test_values_bulk_method_has_zero_write_tokens() {
		$src = $this->src( $this->includes_dir() . self::PURCHASE_ORDERS_FILE );

		$start = strpos( $src, 'function values_bulk(' );
		$this->assertNotFalse( $start, 'values_bulk() must exist.' );

		$after = substr( $src, $start );
		$next  = preg_match( '/\n\t(?:public|private|protected) static function (?!values_bulk)/', $after, $m, PREG_OFFSET_CAPTURE );
		$body  = $this->strip_comments( $next ? substr( $after, 0, (int) $m[0][1] ) : $after );

		foreach ( array( '->insert(', '->update(', '->delete(', 'INSERT INTO', 'UPDATE ', 'DELETE FROM' ) as $token ) {
			$this->assertStringNotContainsString( $token, $body, 'values_bulk() must not contain write token: ' . $token );
		}
	}

	/**
	 * Sole-consumer discipline: only the Suppliers admin detail screen may
	 * call Supplier_Order_History_Service:: -- a future consumer must extend
	 * this allowlist deliberately, not silently.
	 */
	public function test_only_purchasing_page_calls_the_service() {
		$callers = array();

		foreach ( $this->all_include_files() as $file ) {
			$basename = basename( $file );
			if ( self::SERVICE_FILE === $basename ) {
				continue; // Definition site, not a caller.
			}

			$src = $this->strip_comments( $this->src( $file ) );
			if ( false !== strpos( $src, 'Supplier_Order_History_Service::' ) ) {
				$callers[] = $basename;
			}
		}

		$this->assertSame(
			array( 'class-wc-inventory-overview-purchasing-page.php' ),
			$callers,
			'Only the Suppliers admin detail screen may call Supplier_Order_History_Service:: (sole-consumer discipline).'
		);
	}
}
