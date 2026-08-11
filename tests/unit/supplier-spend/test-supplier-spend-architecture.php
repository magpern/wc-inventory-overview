<?php
/**
 * Architecture guard tests for Milestone M15 — Supplier Spend Summary.
 *
 * Source-scanning guards enforcing docs/milestones/m15-implementation-plan.md:
 * WC_Inventory_Overview_Supplier_Spend_Service reads exclusively through
 * WC_Inventory_Overview_Purchase_Orders::spend_summary_for_supplier()
 * (INV-M15-3) -- never $wpdb, never Purchase_Order_Lines, Goods_Receipts,
 * Receipt_Lines, Receipt_Costs, or Suppliers directly -- performs zero
 * writes (INV-M15-3), never blends currencies or converts FX (INV-M15-2),
 * and only the Suppliers admin detail screen may call into it
 * (sole-consumer discipline, INV-M15-4, same mechanism used for
 * Supplier_Order_History_Service / Supplier_Lead_Time_Service).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Spend_Architecture extends WP_UnitTestCase {

	private const SERVICE_FILE         = 'class-wc-inventory-overview-supplier-spend-service.php';
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
	 * Tokens that would indicate the service (or spend_summary_for_supplier()
	 * itself) mutates something or performs FX conversion -- both are
	 * forbidden by construction (INV-M15-2, INV-M15-3).
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
	 * Tokens that would indicate FX/currency-blending logic has crept in
	 * (INV-M15-2 -- never blend currencies, never convert).
	 *
	 * @return string[]
	 */
	private function forbidden_fx_tokens(): array {
		return array(
			'Exchange_Rates::',
			'exchange_rate',
			'fx_rate',
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
	 * INV-M15-3: the service must never bypass Purchase_Orders and read
	 * PO lines, receipts, or suppliers directly.
	 */
	public function test_service_has_zero_unapproved_read_access() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . self::SERVICE_FILE ) );

		foreach ( $this->forbidden_read_tokens() as $token ) {
			$this->assertStringNotContainsString( $token, $src, 'Supplier_Spend_Service must not contain unapproved read token: ' . $token );
		}
	}

	/**
	 * INV-M15-3: the service sources data only through
	 * Purchase_Orders::spend_summary_for_supplier().
	 */
	public function test_service_uses_only_approved_read_owner_method() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . self::SERVICE_FILE ) );

		$this->assertStringContainsString( 'WC_Inventory_Overview_Purchase_Orders::spend_summary_for_supplier(', $src );
	}

	/**
	 * INV-M15-3: the service performs zero writes.
	 */
	public function test_service_has_zero_write_tokens() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . self::SERVICE_FILE ) );

		foreach ( $this->forbidden_write_tokens() as $token ) {
			$this->assertStringNotContainsString( $token, $src, 'Supplier_Spend_Service must not contain write token: ' . $token );
		}
	}

	/**
	 * INV-M15-2: the service never blends currencies or converts FX.
	 */
	public function test_service_has_zero_fx_tokens() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . self::SERVICE_FILE ) );

		foreach ( $this->forbidden_fx_tokens() as $token ) {
			$this->assertStringNotContainsString( $token, $src, 'Supplier_Spend_Service must not contain FX/currency-blending token: ' . $token );
		}
	}

	/**
	 * The spend_summary_for_supplier() method itself, on Purchase_Orders
	 * (which otherwise legitimately writes elsewhere in the same file),
	 * must contain no write statement and no FX token.
	 */
	public function test_spend_summary_method_has_zero_write_and_fx_tokens() {
		$src = $this->src( $this->includes_dir() . self::PURCHASE_ORDERS_FILE );

		$start = strpos( $src, 'function spend_summary_for_supplier(' );
		$this->assertNotFalse( $start, 'spend_summary_for_supplier() must exist.' );

		$after = substr( $src, $start );
		$next  = preg_match( '/\n\t(?:public|private|protected) static function (?!spend_summary_for_supplier)/', $after, $m, PREG_OFFSET_CAPTURE );
		$body  = $this->strip_comments( $next ? substr( $after, 0, (int) $m[0][1] ) : $after );

		foreach ( array( '->insert(', '->update(', '->delete(', 'INSERT INTO', 'UPDATE ', 'DELETE FROM' ) as $token ) {
			$this->assertStringNotContainsString( $token, $body, 'spend_summary_for_supplier() must not contain write token: ' . $token );
		}
		foreach ( $this->forbidden_fx_tokens() as $token ) {
			$this->assertStringNotContainsString( $token, $body, 'spend_summary_for_supplier() must not contain FX/currency-blending token: ' . $token );
		}
	}

	/**
	 * Sole-consumer discipline (INV-M15-4): only the Suppliers admin detail
	 * screen may call Supplier_Spend_Service:: -- a future consumer must
	 * extend this allowlist deliberately, not silently.
	 */
	public function test_only_purchasing_page_calls_the_service() {
		$callers = array();

		foreach ( $this->all_include_files() as $file ) {
			$basename = basename( $file );
			if ( self::SERVICE_FILE === $basename ) {
				continue; // Definition site, not a caller.
			}

			$src = $this->strip_comments( $this->src( $file ) );
			if ( false !== strpos( $src, 'Supplier_Spend_Service::' ) ) {
				$callers[] = $basename;
			}
		}

		$this->assertSame(
			array( 'class-wc-inventory-overview-purchasing-page.php' ),
			$callers,
			'Only the Suppliers admin detail screen may call Supplier_Spend_Service:: (sole-consumer discipline).'
		);
	}
}
