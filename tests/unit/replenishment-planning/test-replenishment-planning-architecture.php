<?php
/**
 * M24 WP-M24-8: architecture guards for INV-M24-1..4/11/12/14/15.
 *
 * Source-scanning + reflection guards, mirroring the established pattern
 * used by every prior milestone's own architecture-guard suite (e.g.
 * Test_WC_IO_Reorder_Signal_Architecture, INV-M21-2).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Planning_Architecture extends WP_UnitTestCase {

	private function includes_dir(): string {
		return WC_INVENTORY_OVERVIEW_PATH . 'includes/';
	}

	private function strip_comments( string $src ): string {
		$src = (string) preg_replace( '#/\*.*?\*/#s', '', $src );
		$src = (string) preg_replace( '#//[^\n]*#', '', $src );
		return $src;
	}

	private function read( string $basename ): string {
		return (string) file_get_contents( $this->includes_dir() . $basename );
	}

	// -----------------------------------------------------------------
	// INV-M24-1: zero mutation anywhere in M24's new code.
	// -----------------------------------------------------------------

	public function test_planning_service_contains_no_write_capable_calls() {
		$src = $this->strip_comments( $this->read( 'class-wc-inventory-overview-replenishment-planning-service.php' ) );

		$forbidden = array(
			'->insert(',
			'->update(',
			'->delete(',
			'->save(',
			'update_post_meta',
			'update_option',
			'wp_insert_post',
			'wp_update_post',
			'set_stock_quantity',
			'PO_Service::create_draft',
			'Purchase_Orders::create_draft',
			'Purchase_Order_Lines::create',
			'PO_Events::add',
		);
		foreach ( $forbidden as $needle ) {
			$this->assertStringNotContainsString( $needle, $src, 'Replenishment_Planning_Service must not contain: ' . $needle );
		}
	}

	public function test_purchasing_page_planning_tab_contains_no_write_capable_calls() {
		$src = $this->strip_comments( $this->read( 'class-wc-inventory-overview-purchasing-page.php' ) );

		$pos = strpos( $src, 'function render_planning_tab' );
		$this->assertNotFalse( $pos, 'render_planning_tab() not found.' );
		$end  = strpos( $src, "\n\tprivate function ", $pos + 10 );
		$body = substr( $src, $pos, ( false !== $end ? $end : strlen( $src ) ) - $pos );

		$forbidden = array( '->save(', 'update_post_meta', 'wp_insert_post', 'PO_Service::create_draft', '<form' );
		foreach ( $forbidden as $needle ) {
			$this->assertStringNotContainsString( $needle, $body, 'render_planning_tab() must not contain: ' . $needle );
		}
	}

	public function test_bulk_action_handler_contains_no_product_mutation() {
		$src = $this->strip_comments( $this->read( 'class-wc-inventory-overview-overview-controller.php' ) );

		$pos = strpos( $src, 'function handle_plan_replenishment_bulk_action' );
		$this->assertNotFalse( $pos, 'handle_plan_replenishment_bulk_action() not found.' );
		$end  = strpos( $src, "\n\tprotected function ", $pos + 10 );
		$end2 = strpos( $src, "\n\tprivate function ", $pos + 10 );
		if ( false === $end || ( false !== $end2 && $end2 < $end ) ) {
			$end = $end2;
		}
		$body = substr( $src, $pos, ( false !== $end ? $end : strlen( $src ) ) - $pos );

		$forbidden = array( '->save(', 'set_stock_quantity', 'wc_get_product(' );
		foreach ( $forbidden as $needle ) {
			$this->assertStringNotContainsString( $needle, $body, 'handle_plan_replenishment_bulk_action() must not contain: ' . $needle );
		}
	}

	// -----------------------------------------------------------------
	// INV-M24-2/3: never reimplements Position/needs_reorder math itself.
	// -----------------------------------------------------------------

	public function test_planning_service_never_calls_position_service_directly() {
		$src = $this->strip_comments( $this->read( 'class-wc-inventory-overview-replenishment-planning-service.php' ) );
		$this->assertStringNotContainsString(
			'Inventory_Position_Service::get_positions_bulk',
			$src,
			'build_plan() must derive incoming from the already-resolved Position captured by Summary, never call get_positions_bulk() itself (avoids a redundant second bulk call and any duplicate Position arithmetic).'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\$position\s*<=\s*\$threshold/',
			$src,
			'build_plan() must never reimplement the position<=threshold comparison inline.'
		);
	}

	// -----------------------------------------------------------------
	// INV-M24-4: supplier precedence exists in exactly one place.
	// -----------------------------------------------------------------

	public function test_only_resolver_and_its_two_callers_implement_precedence() {
		$decide_src = $this->read( 'class-wc-inventory-overview-supplier-preference-resolver.php' );
		$this->assertStringContainsString( 'public static function decide(', $decide_src );

		$prefill_src = $this->strip_comments( $this->read( 'class-wc-inventory-overview-reorder-prefill-service.php' ) );
		$this->assertStringContainsString( 'Supplier_Preference_Resolver::decide', $prefill_src );

		$planning_src = $this->strip_comments( $this->read( 'class-wc-inventory-overview-replenishment-planning-service.php' ) );
		$this->assertStringContainsString( 'Supplier_Preference_Resolver::decide', $planning_src );

		// Neither caller re-derives the preferred-vs-history branching logic
		// inline -- both delegate the actual decision.
		foreach ( array( $prefill_src, $planning_src ) as $src ) {
			$this->assertDoesNotMatchRegularExpression(
				'/history_outcome\s*=\s*[\'"](none|single|ambiguous)[\'"]/',
				$src,
				'Callers must never hardcode a history_outcome value themselves -- only decide() may produce one.'
			);
		}
	}

	// -----------------------------------------------------------------
	// INV-M24-12: no PO_Product_Validator / second wc_get_product() inside
	// build_plan()'s per-item loop.
	// -----------------------------------------------------------------

	public function test_planning_service_never_calls_po_product_validator() {
		$src = $this->strip_comments( $this->read( 'class-wc-inventory-overview-replenishment-planning-service.php' ) );
		$this->assertStringNotContainsString( 'PO_Product_Validator', $src );
		$this->assertStringNotContainsString( 'wc_get_product(', $src, 'build_plan() must never re-fetch a product -- identity is captured once during Summary\'s gather pass.' );
	}

	public function test_summary_gather_never_calls_po_product_validator() {
		$src = $this->strip_comments( $this->read( 'class-wc-inventory-overview-summary.php' ) );
		$this->assertStringNotContainsString( 'PO_Product_Validator', $src );
	}

	// -----------------------------------------------------------------
	// INV-M24-11: Summary remains the sole full-catalog/scoped scanner --
	// no second scan-loop implementation anywhere.
	// -----------------------------------------------------------------

	/**
	 * A generic `while ($page <= $max_page)` page-scan loop shape is a
	 * widely-reused, legitimate idiom throughout this codebase (CSV export,
	 * dashboard metrics, etc.) and is NOT itself evidence of a duplicate
	 * low-stock/needs-reorder scanner -- the actual signature of THIS
	 * specific gather implementation is the combination of a page-scan loop
	 * together with the effective-low-stock-threshold comparison that only
	 * a low-stock candidate gather performs.
	 */
	public function test_no_second_scan_loop_outside_summary() {
		$files = glob( $this->includes_dir() . '*.php' );
		foreach ( (array) $files as $file ) {
			$basename = basename( $file );
			if ( 'class-wc-inventory-overview-summary.php' === $basename ) {
				continue; // Definition site.
			}
			$src = $this->strip_comments( (string) file_get_contents( $file ) );

			$has_page_loop         = (bool) preg_match( '/while\s*\(\s*\$page\s*<=\s*\$max_page\s*\)/', $src );
			$has_low_stock_compare = false !== strpos( $src, 'get_effective_low_stock_amount' );

			$this->assertFalse(
				$has_page_loop && $has_low_stock_compare,
				$basename . ' must not contain a second catalog-wide low-stock-candidate page-scan loop (INV-M24-11).'
			);
		}
	}

	// -----------------------------------------------------------------
	// INV-M24-15/BR-M24-23: no per-selected-id wc_get_product() loop in the
	// bulk-action variable-parent filter.
	// -----------------------------------------------------------------

	public function test_bulk_action_variable_parent_filter_uses_bounded_include_not_loop() {
		$src = $this->strip_comments( $this->read( 'class-wc-inventory-overview-overview-controller.php' ) );

		$pos = strpos( $src, 'function handle_plan_replenishment_bulk_action' );
		$this->assertNotFalse( $pos );
		$end  = strpos( $src, "\n\tprotected function ", $pos + 10 );
		$end2 = strpos( $src, "\n\tprivate function ", $pos + 10 );
		if ( false === $end || ( false !== $end2 && $end2 < $end ) ) {
			$end = $end2;
		}
		$body = substr( $src, $pos, ( false !== $end ? $end : strlen( $src ) ) - $pos );

		$this->assertStringContainsString( "Repository::query_products( array( 'include' => \$ids ) )", $body, 'Must use the bounded include-based Repository lookup.' );
		$this->assertStringNotContainsString( 'wc_get_product(', $body, 'Must never loop wc_get_product() per selected id.' );
		$this->assertDoesNotMatchRegularExpression( '/foreach\s*\(\s*\$ids\s+as/', $body, 'Must never foreach over the raw selected $ids and look each one up individually.' );
	}

	// -----------------------------------------------------------------
	// INV-M24-6: no schema/index/DB_VERSION change.
	// -----------------------------------------------------------------

	public function test_db_version_unchanged_at_11() {
		$this->assertSame( '11', WC_Inventory_Overview_Install::DB_VERSION );
	}

	public function test_install_file_has_no_new_m24_table() {
		$src = $this->read( 'class-wc-inventory-overview-install.php' );
		$this->assertStringNotContainsString( 'wc_io_replenishment_plan', $src, 'M24 introduces no new schema/table (§14.1/INV-M24-6).' );
	}

	// -----------------------------------------------------------------
	// INV-M24-7: no new capability constant; VIEW_PO reused unmodified.
	// -----------------------------------------------------------------

	public function test_no_new_capability_constant_introduced() {
		$src = $this->read( 'class-wc-inventory-overview-purchasing-caps.php' );
		$this->assertStringNotContainsString( 'PLAN_REPLENISHMENT', $src );
		$this->assertStringNotContainsString( 'VIEW_PLANNING', $src );
	}

	// -----------------------------------------------------------------
	// MAX_LINES / MAX_BULK_ACTION_SELECTION constants exist with the
	// approved values (§21).
	// -----------------------------------------------------------------

	public function test_constants_match_approved_values() {
		$this->assertSame( 500, WC_Inventory_Overview_Replenishment_Planning_Service::MAX_LINES );
		$this->assertSame( 100, WC_Inventory_Overview_Replenishment_Planning_Service::MAX_BULK_ACTION_SELECTION );
	}
}
