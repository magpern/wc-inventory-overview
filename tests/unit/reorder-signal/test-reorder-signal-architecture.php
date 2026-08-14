<?php
/**
 * Architecture guard tests for Milestone M21 — Position-Aware Reorder Signal.
 *
 * Source-scanning guards: WC_Inventory_Overview_Reorder_Signal_Resolver is
 * the sole component permitted to compute the needs_reorder/
 * covered_by_incoming classification (INV-M21-2); the Resolver itself must
 * remain stateless and read-only (mirrors the M3 Inventory Position
 * Resolver's own guard); every M21 surface that reads a viewer capability
 * must use manage_woocommerce, never a new capability constant
 * (INV-M21-5); DB_VERSION stays unchanged (INV-M21-8).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Reorder_Signal_Architecture extends WP_UnitTestCase {

	/**
	 * @return string
	 */
	private function includes_dir(): string {
		return WC_INVENTORY_OVERVIEW_PATH . 'includes/';
	}

	/**
	 * Strip PHP comments so source-scanning assertions only see executable code.
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
	 * INV-M21-2: only the Resolver's own definition file and files that call
	 * through it (directly or via Summary::classify_needs_reorder_bulk()) may
	 * reference needs_reorder/covered_by_incoming — no independent
	 * reimplementation of the comparison exists anywhere else.
	 */
	public function test_only_resolver_and_its_callers_reference_the_classification_keys() {
		$allowed = array(
			'class-wc-inventory-overview-reorder-signal-resolver.php',
			'class-wc-inventory-overview-summary.php',
			'class-wc-inventory-overview-list-table.php',
			'class-wc-inventory-overview-overview-controller.php',
			'class-wc-inventory-overview-dashboard-controller.php',
			// M22: reads Reorder_Signal_Resolver::resolve()'s own return
			// keys to decide the reorder-prefill 'stale' vs 'prefilled'
			// outcome -- calls through the Resolver, never reimplements it.
			'class-wc-inventory-overview-reorder-prefill-service.php',
			// M24: calls Summary::get_needs_reorder_items() (itself a
			// caller-through-Summary of classify_needs_reorder_bulk() /
			// the Resolver, already allowed above) to discover/resolve the
			// bulk replenishment worklist -- never reimplements the
			// position<=threshold comparison itself (INV-M24-2/3).
			'class-wc-inventory-overview-replenishment-planning-service.php',
		);

		$offenders = array();

		foreach ( $this->all_include_files() as $file ) {
			$basename = basename( $file );
			if ( in_array( $basename, $allowed, true ) ) {
				continue;
			}
			$src = $this->strip_comments( (string) file_get_contents( $file ) );
			if ( false !== strpos( $src, 'needs_reorder' ) || false !== strpos( $src, 'covered_by_incoming' ) ) {
				$offenders[] = $basename;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'Only the Reorder Signal Resolver and its known callers may reference needs_reorder/covered_by_incoming (INV-M21-2).'
		);
	}

	/**
	 * INV-M21-2: no file outside the Resolver reimplements the
	 * position<=threshold-shaped comparison for reorder classification (i.e.
	 * every caller goes through resolve(), never re-deriving the boolean
	 * itself from a raw comparison).
	 */
	public function test_no_file_reimplements_position_threshold_comparison() {
		$forbidden_patterns = array(
			'/\$position\s*<=\s*\$threshold/',
			'/\$position\s*<=\s*\$low/',
		);

		foreach ( $this->all_include_files() as $file ) {
			$basename = basename( $file );
			if ( 'class-wc-inventory-overview-reorder-signal-resolver.php' === $basename ) {
				continue; // Definition site.
			}
			$src = $this->strip_comments( (string) file_get_contents( $file ) );
			foreach ( $forbidden_patterns as $pattern ) {
				$this->assertDoesNotMatchRegularExpression(
					$pattern,
					$src,
					$basename . ' must not reimplement the position<=threshold reorder comparison inline (INV-M21-2).'
				);
			}
		}
	}

	/**
	 * Resolver is stateless: no $wpdb, no product loading, no repository coupling.
	 */
	public function test_resolver_is_stateless_and_isolated() {
		$src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-reorder-signal-resolver.php' ) );

		$this->assertStringNotContainsString( '$wpdb', $src, 'Resolver must be independent of $wpdb' );
		$this->assertStringNotContainsString( 'wc_get_product', $src, 'Resolver must be independent of WooCommerce product loading' );
		$this->assertStringNotContainsString( 'WC_Inventory_Overview_Inventory_Position_Service', $src, 'Resolver must not call the Position Service itself — callers supply Position' );
	}

	/**
	 * Resolver contains no write, stock-mutation, cost-mutation, or option operations (INV-M21-1).
	 */
	public function test_resolver_has_no_mutation() {
		$forbidden = array(
			'set_stock_quantity',
			'wc_update_product_stock',
			'update_post_meta',
			'update_option',
			'->insert(',
			'->update(',
			'->delete(',
			'->save(',
		);

		$src = (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-reorder-signal-resolver.php' );
		foreach ( $forbidden as $needle ) {
			$this->assertStringNotContainsString( $needle, $src, 'Resolver must not contain forbidden token: ' . $needle );
		}
	}

	/**
	 * INV-M21-8: DB_VERSION is unchanged by this milestone.
	 */
	public function test_db_version_unchanged() {
		$this->assertSame( '11', WC_Inventory_Overview_Install::DB_VERSION );
	}

	// -----------------------------------------------------------------
	// WP-M21-6: consolidated capability-matrix static checks (BR-M21-4,
	// INV-M21-5) -- every new capability-gated M21 surface uses
	// manage_woocommerce, never a new capability constant. The behavioral
	// proof (present/absent per viewer) lives in the integration test
	// suites; these are a static defense-in-depth layer only.
	// -----------------------------------------------------------------

	/**
	 * Overview_Controller::render_summary_cards()'s new capability check
	 * (BR-M21-6) uses manage_woocommerce.
	 */
	public function test_overview_summary_cards_gate_uses_manage_woocommerce() {
		$src = (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-overview-controller.php' );
		$this->assertStringContainsString(
			"if ( current_user_can( 'manage_woocommerce' ) ) {\n\t\t\tarray_splice(",
			$src,
			'render_summary_cards() must gate the Needs Reorder card with manage_woocommerce.'
		);
	}

	/**
	 * Overview_Controller's inline-stock AJAX badge-refresh position_map
	 * build (WP-M21-2) uses manage_woocommerce.
	 */
	public function test_ajax_badge_refresh_position_map_gate_uses_manage_woocommerce() {
		$src = (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-overview-controller.php' );
		$this->assertStringContainsString(
			"if ( current_user_can( 'manage_woocommerce' ) && \$product->managing_stock() ) {",
			$src
		);
	}

	/**
	 * Dashboard_Controller's new KPI/table-column gates (BR-M21-9,
	 * BR-M21-10) both check manage_woocommerce -- the table columns reuse
	 * the pre-existing $can_shop variable (render_dashboard_operational_panels()),
	 * and the KPI card checks the same capability directly (a separate
	 * method, render(), so it cannot share that local variable) -- no new
	 * capability constant is introduced anywhere in this file.
	 */
	public function test_dashboard_new_surfaces_use_manage_woocommerce_only() {
		$src = (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-dashboard-controller.php' );
		$this->assertStringContainsString( "\$can_shop = current_user_can( 'manage_woocommerce' );", $src );
		$this->assertStringContainsString( 'if ( $can_shop ) {', $src );
		// The new KPI-card gate (render(), a separate method from $can_shop's
		// scope) checks the same capability directly.
		$this->assertStringContainsString(
			"if ( current_user_can( 'manage_woocommerce' ) ) {\n\t\t\t\$kpis[] = array(",
			$src
		);
	}
}
