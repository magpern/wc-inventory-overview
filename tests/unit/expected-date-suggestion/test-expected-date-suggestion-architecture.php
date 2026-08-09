<?php
/**
 * Architecture guard tests for Milestone M10 — Purchase Order Expected-Date
 * Suggestion.
 *
 * Source-scanning guards: WC_Inventory_Overview_Expected_Date_Suggestion_Service
 * is the sole owner of the observed/configured/none suggestion (recommendation)
 * policy (no duplicate resolution logic anywhere else in includes/); it never
 * duplicates M9's own observed-lead-time computation; it performs zero writes
 * (read-only by construction, per docs/milestones/m10-implementation-plan.md
 * §5 -- no suggestion is ever persisted); it never queries the database
 * directly (it only ever delegates to Supplier_Lead_Time_Service); only the
 * PO admin screen calls into it (no unexpected caller); and it carries no
 * public API/versioning surface.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Expected_Date_Suggestion_Architecture extends WP_UnitTestCase {

	private const SERVICE_FILE = 'class-wc-inventory-overview-expected-date-suggestion-service.php';

	/**
	 * Token unique to this service's resolution policy (plan §5.2) -- the
	 * "is the observed average usable" decision call. A second,
	 * independently-written caller of this same M9 predicate anywhere else
	 * in includes/ would mean a second component is re-implementing this
	 * milestone's fallback policy, a sole-owner violation.
	 *
	 * @return string[]
	 */
	private function computation_signature_tokens(): array {
		return array(
			'is_observed_value_usable(',
		);
	}

	/**
	 * M9's own computation signature (docs/milestones/m9-implementation-plan.md
	 * §6) -- this service must never duplicate the observed-lead-time
	 * aggregation itself, only consume Supplier_Lead_Time_Service's already-
	 * computed result.
	 *
	 * @return string[]
	 */
	private function m9_computation_tokens(): array {
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
		'wp_insert_post',
		'wp_update_post',
		'qty_received',
	);

	/**
	 * Expected exact set of includes/ files calling
	 * WC_Inventory_Overview_Expected_Date_Suggestion_Service:: -- the PO
	 * admin screen (WP-C), and nothing else. A future caller (e.g. Quick
	 * Restock, per docs/milestones/m10-implementation-plan.md §4 non-goal
	 * #6) must be added here deliberately, in the same review, never
	 * silently.
	 *
	 * @return string[]
	 */
	private function approved_callers(): array {
		return array(
			'class-wc-inventory-overview-po-admin.php',
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
	 * calls the "is this observed average usable" predicate -- the
	 * resolution policy signature.
	 */
	public function test_only_service_owns_the_suggestion_policy() {
		$offenders = array();

		foreach ( $this->all_include_files() as $file ) {
			$basename = basename( $file );
			if ( self::SERVICE_FILE === $basename ) {
				continue; // Definition site (the caller), not a duplicate.
			}
			// Supplier_Lead_Time_Service itself legitimately defines the
			// method it doesn't call it.
			if ( 'class-wc-inventory-overview-supplier-lead-time-service.php' === $basename ) {
				continue;
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
			'Only WC_Inventory_Overview_Expected_Date_Suggestion_Service may own the observed/configured/none suggestion policy (sole-owner rule).'
		);
	}

	/**
	 * This service must never duplicate M9's own observed-lead-time
	 * aggregation -- it may only ever consume
	 * Supplier_Lead_Time_Service::get_stats_bulk()'s already-computed
	 * result, never recompute an average/min/max itself.
	 */
	public function test_never_duplicates_supplier_lead_time_computation() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . self::SERVICE_FILE ) );

		foreach ( $this->m9_computation_tokens() as $token ) {
			$this->assertStringNotContainsString( $token, $src, 'Service must never duplicate Supplier_Lead_Time_Service\'s own computation token: ' . $token );
		}
	}

	/**
	 * Zero mutation: the service never touches WooCommerce stock, PO state,
	 * or Goods Receipt state -- read-only by construction.
	 */
	public function test_service_performs_zero_writes() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . self::SERVICE_FILE ) );

		foreach ( self::FORBIDDEN_WRITE_TOKENS as $needle ) {
			$this->assertStringNotContainsString( $needle, $src, 'Service must not contain forbidden write token: ' . $needle );
		}
	}

	/**
	 * The service never queries the database directly at all -- it only
	 * ever delegates to Supplier_Lead_Time_Service::get_stats_bulk() and
	 * reads already-loaded supplier rows passed in by its caller. Stronger
	 * than "read-only": zero direct $wpdb usage of any kind.
	 */
	public function test_service_never_queries_the_database_directly() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . self::SERVICE_FILE ) );

		$this->assertStringNotContainsString( '$wpdb', $src, 'Service must never touch $wpdb directly -- it only ever delegates to Supplier_Lead_Time_Service.' );
	}

	/**
	 * No public API/versioning surface: this service is Internal (D16) --
	 * no API_VERSION constant, no interface declaration.
	 */
	public function test_service_has_no_public_api_surface() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . self::SERVICE_FILE ) );

		$this->assertStringNotContainsString( 'API_VERSION', $src, 'Service must not carry a versioned public API surface (D16 -- Internal only).' );
		$this->assertStringNotContainsString( 'interface ', $src, 'Service must not declare or implement a public interface (D16 -- Internal only).' );
	}

	/**
	 * Sole caller: only the PO admin screen may call into the service. A
	 * future consumer (plan §4 non-goal #6) must extend this allowlist
	 * deliberately, not silently.
	 */
	public function test_only_approved_files_call_the_service() {
		$callers = array();

		foreach ( $this->all_include_files() as $file ) {
			$basename = basename( $file );
			if ( self::SERVICE_FILE === $basename ) {
				continue; // Definition site, not a caller.
			}

			$src = $this->strip_comments( $this->src( $file ) );
			if ( false !== strpos( $src, 'Expected_Date_Suggestion_Service::' ) ) {
				$callers[] = $basename;
			}
		}

		sort( $callers );
		$expected = $this->approved_callers();
		sort( $expected );

		$this->assertSame(
			$expected,
			$callers,
			'Only the approved caller set may reference Expected_Date_Suggestion_Service:: (sole-consumer discipline until a future milestone deliberately extends it).'
		);
	}

	/**
	 * Bulk-first shape: get_suggestion_for_supplier() must delegate to
	 * get_suggestions_bulk() (same discipline as every prior derived-value
	 * service in this codebase), never re-implement single-item logic
	 * independently.
	 */
	public function test_single_method_delegates_to_bulk() {
		$service = new ReflectionClass( WC_Inventory_Overview_Expected_Date_Suggestion_Service::class );

		$this->assertTrue( $service->hasMethod( 'get_suggestion_for_supplier' ) );
		$this->assertTrue( $service->hasMethod( 'get_suggestions_bulk' ) );

		$src  = $this->src( $this->includes_dir() . self::SERVICE_FILE );
		$body = substr(
			$src,
			(int) strpos( $src, 'function get_suggestion_for_supplier' ),
			300
		);

		$this->assertStringContainsString( 'get_suggestions_bulk', $body, 'get_suggestion_for_supplier() must delegate to get_suggestions_bulk() (single/bulk consistency by construction).' );
	}
}
