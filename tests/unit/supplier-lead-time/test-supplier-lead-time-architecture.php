<?php
/**
 * Architecture guard tests for Milestone M9 — Supplier Observed Lead-Time
 * Statistics.
 *
 * Source-scanning guards: WC_Inventory_Overview_Supplier_Lead_Time_Service
 * is the sole owner of the observed-lead-time computation (no duplicate
 * derivation anywhere else in includes/); it performs zero writes (read-only
 * by construction, per docs/milestones/m9-implementation-plan.md §6.1
 * "Source of Truth" -- no statistic is ever persisted); and only the
 * Suppliers admin screen calls into it (no unexpected caller).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Lead_Time_Architecture extends WP_UnitTestCase {

	private const SERVICE_FILE = 'class-wc-inventory-overview-supplier-lead-time-service.php';

	/**
	 * Tokens unique to the observed-lead-time *computation itself* (plan
	 * §6) -- deliberately not the returned array keys (average_days,
	 * fastest_days, slowest_days, sample_count), which the approved UI
	 * caller legitimately reads without duplicating any calculation. A
	 * second, independently-derived implementation of this SQL calculation
	 * anywhere else in includes/ would be a sole-owner violation, not a new
	 * feature.
	 *
	 * @return string[]
	 */
	private function computation_signature_tokens(): array {
		return array(
			'DATEDIFF(',
			'observed_days',
		);
	}

	private const FORBIDDEN_WRITE_TOKENS = array(
		'set_stock_quantity',
		'update_post_meta',
		'->insert(',
		'->update(',
		'->delete(',
		'qty_received',
	);

	/**
	 * Expected exact set of includes/ files calling
	 * WC_Inventory_Overview_Supplier_Lead_Time_Service:: -- the Suppliers
	 * admin screen (WP3), plus M10's Expected_Date_Suggestion_Service
	 * (docs/milestones/m10-implementation-plan.md §5.1: a deliberate,
	 * anticipated extension of this allowlist, not a silent one), and
	 * nothing else. A future caller must be added here deliberately, in
	 * the same review, never silently.
	 *
	 * @return string[]
	 */
	private function approved_callers(): array {
		return array(
			'class-wc-inventory-overview-purchasing-page.php',
			'class-wc-inventory-overview-expected-date-suggestion-service.php',
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
		return (string) file_get_contents( $file );
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
	 * @return string[]
	 */
	private function all_include_files(): array {
		$files = glob( $this->includes_dir() . '*.php' );
		return is_array( $files ) ? $files : array();
	}

	/**
	 * Sole ownership: no file in includes/ other than the service itself
	 * contains the observed-lead-time computation's distinctive tokens.
	 */
	public function test_only_service_computes_observed_lead_time() {
		$offenders = array();

		foreach ( $this->all_include_files() as $file ) {
			$basename = basename( $file );
			if ( self::SERVICE_FILE === $basename ) {
				continue; // Definition site, not a duplicate.
			}

			$src = $this->strip_comments( $this->src( $file ) );
			foreach ( $this->computation_signature_tokens() as $token ) {
				if ( false !== strpos( $src, $token ) ) {
					$offenders[] = $basename;
					break;
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'Only WC_Inventory_Overview_Supplier_Lead_Time_Service may compute observed lead-time statistics (sole-owner rule).'
		);
	}

	/**
	 * Zero mutation: the service never touches WooCommerce stock, PO state,
	 * or Goods Receipt state -- read-only by construction (§6.1).
	 */
	public function test_service_performs_zero_writes() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . self::SERVICE_FILE ) );

		foreach ( self::FORBIDDEN_WRITE_TOKENS as $needle ) {
			$this->assertStringNotContainsString( $needle, $src, 'Service must not contain forbidden write token: ' . $needle );
		}
	}

	/**
	 * The service reads $wpdb->get_results() only -- never ->query() with a
	 * write verb, confirming "read-only by construction" mechanically
	 * rather than only by the token blocklist above.
	 */
	public function test_service_only_ever_calls_get_results() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . self::SERVICE_FILE ) );

		$this->assertStringNotContainsString( '$wpdb->query(', $src, 'Service must use get_results(), never a raw query() call that could carry a write statement.' );
		$this->assertStringContainsString( '$wpdb->get_results(', $src, 'Service must read via get_results().' );
	}

	/**
	 * Sole caller: only the Suppliers admin screen may call into the
	 * service. A future consumer (§8.1 "Future Consumers (Not Part of M9)")
	 * must extend this allowlist deliberately, not silently.
	 */
	public function test_only_approved_files_call_the_service() {
		$callers = array();

		foreach ( $this->all_include_files() as $file ) {
			$basename = basename( $file );
			if ( self::SERVICE_FILE === $basename ) {
				continue; // Definition site, not a caller.
			}

			$src = $this->strip_comments( $this->src( $file ) );
			if ( false !== strpos( $src, 'Supplier_Lead_Time_Service::' ) ) {
				$callers[] = $basename;
			}
		}

		sort( $callers );
		$expected = $this->approved_callers();
		sort( $expected );

		$this->assertSame(
			$expected,
			$callers,
			'Only the approved caller set may reference Supplier_Lead_Time_Service:: (sole-consumer discipline until a future milestone deliberately extends it).'
		);
	}

	/**
	 * Bulk-first shape: get_stats_for_supplier() must delegate to
	 * get_stats_bulk() (same discipline as Inventory Position / Expected
	 * Delivery), never re-implement single-item logic independently.
	 */
	public function test_single_method_delegates_to_bulk() {
		$service = new ReflectionClass( WC_Inventory_Overview_Supplier_Lead_Time_Service::class );

		$this->assertTrue( $service->hasMethod( 'get_stats_for_supplier' ) );
		$this->assertTrue( $service->hasMethod( 'get_stats_bulk' ) );

		$src  = $this->src( $this->includes_dir() . self::SERVICE_FILE );
		$body = substr(
			$src,
			(int) strpos( $src, 'function get_stats_for_supplier' ),
			200
		);

		$this->assertStringContainsString( 'get_stats_bulk', $body, 'get_stats_for_supplier() must delegate to get_stats_bulk() (single/bulk consistency by construction).' );
	}
}
