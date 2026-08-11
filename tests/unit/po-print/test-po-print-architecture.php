<?php
/**
 * Architecture guard tests for Milestone M13 — Printable Purchase Order.
 *
 * Source-scanning guards enforcing docs/milestones/m13-implementation-plan.md
 * Part F: WC_Inventory_Overview_PO_Print_Renderer is presentation-only
 * (INV-M13-2) -- zero $wpdb access, zero calls into the established read
 * owners, zero mutation tokens, zero authorization/lifecycle logic. Print
 * data is obtained only through the three approved read owners plus
 * PO_Statuses::label() (INV-M13-3), and access requires capability + nonce
 * before any of those reads occur (INV-M13-4).
 *
 * @package WC_Inventory_Overview_Tests
 */

/**
 * M13 architecture guard tests.
 */
class Test_WC_IO_PO_Print_Architecture extends WP_UnitTestCase {

	private const RENDERER_FILE = 'class-wc-inventory-overview-po-print-renderer.php';
	private const ADMIN_FILE    = 'class-wc-inventory-overview-po-admin.php';

	/**
	 * Tokens that would indicate the renderer duplicates a repository read
	 * owner's job instead of only formatting an already-composed model.
	 *
	 * @return string[]
	 */
	private function forbidden_read_owner_tokens(): array {
		return array(
			'Purchase_Orders::',
			'Purchase_Order_Lines::',
			'Suppliers::',
			'$wpdb',
			'wc_get_product',
			'wc_get_product_object',
		);
	}

	/**
	 * Tokens that would indicate the renderer mutates something.
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
			'wp_update_post',
			'wp_insert_post',
		);
	}

	/**
	 * Tokens that would indicate the renderer performs authorization or
	 * lifecycle checks of its own -- both are the handler's job.
	 *
	 * @return string[]
	 */
	private function forbidden_authorization_tokens(): array {
		return array(
			'current_user_can',
			'check_admin_referer',
			'wp_verify_nonce',
			'Purchasing_Caps::',
			'PO_Lifecycle::',
			'$_GET',
			'$_POST',
			'$_REQUEST',
		);
	}

	/**
	 * Absolute path to the plugin's includes/ directory.
	 *
	 * @return string
	 */
	private function includes_dir(): string {
		return WC_INVENTORY_OVERVIEW_PATH . 'includes/';
	}

	/**
	 * Read a local plugin source file for static scanning (not a remote URL).
	 *
	 * @param string $file Absolute path.
	 * @return string
	 */
	private function src( string $file ): string {
		return (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin source file, not a remote URL.
	}

	/**
	 * Strip PHP comments so source-scanning assertions only see executable
	 * code -- explanatory prose legitimately documenting an absence must not
	 * itself trip the guard it is describing.
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
	 * INV-M13-2: renderer performs zero database access and zero calls into
	 * any established read owner.
	 */
	public function test_renderer_has_zero_repository_access() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . self::RENDERER_FILE ) );

		foreach ( $this->forbidden_read_owner_tokens() as $token ) {
			$this->assertStringNotContainsString( $token, $src, 'PO_Print_Renderer must not contain repository/product-lookup token: ' . $token );
		}
	}

	/**
	 * INV-M13-1 / INV-M13-2: renderer performs zero writes.
	 */
	public function test_renderer_has_zero_write_tokens() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . self::RENDERER_FILE ) );

		foreach ( $this->forbidden_write_tokens() as $token ) {
			$this->assertStringNotContainsString( $token, $src, 'PO_Print_Renderer must not contain write token: ' . $token );
		}
	}

	/**
	 * INV-M13-2: renderer performs zero authorization or lifecycle checks --
	 * that is the handler's responsibility, not the renderer's.
	 */
	public function test_renderer_has_zero_authorization_or_lifecycle_logic() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . self::RENDERER_FILE ) );

		foreach ( $this->forbidden_authorization_tokens() as $token ) {
			$this->assertStringNotContainsString( $token, $src, 'PO_Print_Renderer must not contain authorization/lifecycle token: ' . $token );
		}
	}

	/**
	 * Sole ownership: no file in includes/ other than PO_Admin (the
	 * composer) calls WC_Inventory_Overview_PO_Print_Renderer:: -- a future
	 * caller must extend this allowlist deliberately, not silently.
	 */
	public function test_only_po_admin_calls_the_renderer() {
		$callers = array();
		$files   = glob( $this->includes_dir() . '*.php' );
		$files   = is_array( $files ) ? $files : array();

		foreach ( $files as $file ) {
			$basename = basename( $file );
			if ( self::RENDERER_FILE === $basename ) {
				continue; // Definition site, not a caller.
			}
			$src = $this->strip_comments( $this->src( $file ) );
			if ( false !== strpos( $src, 'PO_Print_Renderer::' ) ) {
				$callers[] = $basename;
			}
		}

		$this->assertSame(
			array( self::ADMIN_FILE ),
			$callers,
			'Only PO_Admin may call PO_Print_Renderer:: (sole-consumer discipline).'
		);
	}

	/**
	 * INV-M13-3: the print handler in PO_Admin sources PO/line/supplier data
	 * only through the three approved read owners (plus PO_Statuses::label())
	 * -- no duplicated aggregation SQL, no direct $wpdb query in the print
	 * code path.
	 */
	public function test_handler_uses_only_approved_read_owners() {
		$body = $this->handle_print_body();

		$this->assertStringNotContainsString( '$wpdb', $body, 'handle_print() must not access $wpdb directly.' );
		$this->assertStringContainsString( 'WC_Inventory_Overview_Purchase_Orders::get(', $body );
		$this->assertStringContainsString( 'WC_Inventory_Overview_Purchase_Order_Lines::list_for_po(', $body );
		$this->assertStringContainsString( 'WC_Inventory_Overview_Suppliers::get(', $body );
		$this->assertStringContainsString( 'WC_Inventory_Overview_PO_Statuses::label(', $body );
	}

	/**
	 * INV-M13-4: capability and nonce checks must both occur, textually,
	 * before the first repository read in handle_print()'s body. The method
	 * is written as plain top-to-bottom procedural code (no branching around
	 * the guard/nonce calls), so a source-position check is not misleading
	 * here -- it mechanically proves the required ordering rather than only
	 * asserting presence.
	 */
	public function test_handler_checks_capability_and_nonce_before_any_repository_read() {
		$body = $this->handle_print_body();

		$guard_pos = strpos( $body, 'self::guard( WC_Inventory_Overview_Purchasing_Caps::VIEW_PO )' );
		$nonce_pos = strpos( $body, 'check_admin_referer(' );
		$read_pos  = strpos( $body, 'WC_Inventory_Overview_Purchase_Orders::get(' );

		$this->assertNotFalse( $guard_pos, 'handle_print() must call the VIEW_PO capability guard.' );
		$this->assertNotFalse( $nonce_pos, 'handle_print() must call check_admin_referer().' );
		$this->assertNotFalse( $read_pos, 'handle_print() must read the PO.' );

		$this->assertLessThan( $nonce_pos, $guard_pos, 'Capability check must precede nonce verification.' );
		$this->assertLessThan( $read_pos, $nonce_pos, 'Nonce verification must precede any repository read.' );
	}

	/**
	 * Behavioral companion to the ordering guard above: draft is excluded
	 * from the printable-status allowlist, and no other status is silently
	 * added without updating this guard.
	 */
	public function test_printable_statuses_exclude_draft_only() {
		$printable = WC_Inventory_Overview_PO_Admin::printable_statuses();

		$this->assertNotContains( WC_Inventory_Overview_PO_Statuses::DRAFT, $printable );

		$expected = array(
			WC_Inventory_Overview_PO_Statuses::PLACED,
			WC_Inventory_Overview_PO_Statuses::PARTIALLY_RECEIVED,
			WC_Inventory_Overview_PO_Statuses::RECEIVED,
			WC_Inventory_Overview_PO_Statuses::CANCELLED,
			WC_Inventory_Overview_PO_Statuses::CLOSED_SHORT,
		);
		sort( $expected );
		$actual = $printable;
		sort( $actual );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Isolate handle_print()'s method body via source slicing (same
	 * technique as the M12 architecture guard's prepare_items() slice).
	 *
	 * @return string
	 */
	private function handle_print_body(): string {
		$src = $this->src( $this->includes_dir() . self::ADMIN_FILE );

		$start = strpos( $src, 'function handle_print()' );
		$this->assertNotFalse( $start, 'handle_print() must exist.' );

		$after = substr( $src, $start );
		$next  = preg_match( '/\n\t(?:public|private|protected) static function (?!handle_print)/', $after, $m, PREG_OFFSET_CAPTURE );
		$body  = $next ? substr( $after, 0, (int) $m[0][1] ) : $after;

		return $this->strip_comments( $body );
	}
}
