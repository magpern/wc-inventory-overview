<?php
/**
 * Repo-wide conformance guard: no file under includes/ may reference a
 * sibling plugin by name.
 *
 * M8/WP3. Cut from M7's own guard test as "codebase hygiene belonging to
 * M8's conformance audit" (docs/milestones/m7-implementation-plan.md) --
 * distinct from M7's own narrower, load-bearing coupling guard
 * (tests/unit/expected-delivery/test-expected-delivery-architecture.php),
 * which only checks the M7 surface. Every milestone's per-feature guard
 * already asserts "no sibling-plugin coupling" within its own files; this
 * is the one guard that scans the entire includes/ tree at once, closing
 * the gap ADR-0003 audited by hand but never enforced mechanically:
 * docs/adr/0003-storefront-expected-delivery-ownership.md's central claim
 * is that this plugin owns storefront presentation without any named
 * dependency on (or coordination with) the sibling "Biopentra Storefront"
 * plugin, and docs/OWNERSHIP.md separately names "MP Commerce Fulfillment"
 * (MPCF) as the outbound sibling this plugin must never reach into either.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_No_Sibling_Plugin_Coupling extends WP_UnitTestCase {

	/**
	 * class_exists()/function_exists() symbols legitimately checked anywhere
	 * in includes/ today -- WordPress core, WooCommerce core, or a PHP
	 * extension function, never a sibling plugin. Closed set, not a prefix
	 * heuristic (matches this repository's established guard-test idiom,
	 * e.g. the M7 architecture guard's exact-set assertions): adding a new
	 * class_exists()/function_exists() check anywhere in includes/ must
	 * extend this allowlist deliberately, in the same review, rather than
	 * silently passing.
	 *
	 * @return string[]
	 */
	private function allowed_existence_check_symbols(): array {
		return array(
			'WP_List_Table',
			'WP_CLI',
			'WC_Cache_Helper',
			'wp_get_environment_type',
			'wp_cache_flush_group',
			'mb_strtolower',
			'Automattic\\WooCommerce\\Utilities\\OrderUtil',
			'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStore',
			'WC_Admin_Meta_Boxes',
		);
	}

	/**
	 * Sibling plugins this plugin must never name, per docs/OWNERSHIP.md
	 * (MP Commerce Fulfillment, the outbound sibling) and
	 * docs/adr/0003-storefront-expected-delivery-ownership.md ("Biopentra
	 * Storefront", the storefront-presentation sibling verified empty in M7).
	 *
	 * @return string[]
	 */
	private function forbidden_sibling_identifiers(): array {
		return array(
			'biopentra-storefront',
			'biopentra_storefront',
			'mp-commerce-fulfillment',
			'mp_commerce_fulfillment',
		);
	}

	/**
	 * @return string
	 */
	private function includes_dir(): string {
		return WC_INVENTORY_OVERVIEW_PATH . 'includes/';
	}

	/**
	 * @return string[]
	 */
	private function all_include_files(): array {
		$files = glob( $this->includes_dir() . '*.php' );
		return is_array( $files ) ? $files : array();
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
	 * Every class_exists()/function_exists() symbol referenced anywhere in
	 * includes/ (string-literal or ::class syntax) is on the closed
	 * allowlist above.
	 */
	public function test_only_wordpress_or_woocommerce_existence_checks() {
		$found = array();

		foreach ( $this->all_include_files() as $file ) {
			$src = $this->strip_comments( (string) file_get_contents( $file ) );

			// class_exists( 'Symbol' ) / function_exists( 'symbol' ) -- single or double quoted.
			if ( preg_match_all( '/\b(?:class|function)_exists\(\s*[\'"]([^\'"]+)[\'"]/', $src, $m ) ) {
				foreach ( $m[1] as $symbol ) {
					$found[ basename( $file ) ][] = ltrim( $symbol, '\\' );
				}
			}

			// class_exists( \Fully\Qualified\Name::class ).
			if ( preg_match_all( '/\bclass_exists\(\s*\\\\?([A-Za-z0-9_\\\\]+)::class/', $src, $m ) ) {
				foreach ( $m[1] as $symbol ) {
					$found[ basename( $file ) ][] = ltrim( $symbol, '\\' );
				}
			}
		}

		$allowed = $this->allowed_existence_check_symbols();

		foreach ( $found as $file => $symbols ) {
			foreach ( $symbols as $symbol ) {
				$this->assertContains(
					$symbol,
					$allowed,
					"{$file} checks class_exists()/function_exists() against '{$symbol}', which is not on the allowlist -- verify it's a WordPress/WooCommerce/PHP-core symbol (not a sibling plugin) and add it deliberately if so."
				);
			}
		}

		// Sanity check: the scan itself must have found something (guards
		// against a refactor silently making this test vacuously pass).
		$this->assertNotEmpty( $found, 'Expected at least one class_exists()/function_exists() check in includes/ -- the scan itself may be broken.' );
	}

	/**
	 * No file under includes/ calls remove_filter()/remove_action() --
	 * this plugin never unregisters another plugin's (or its own) hooks.
	 */
	public function test_no_remove_filter_or_remove_action() {
		foreach ( $this->all_include_files() as $file ) {
			$src = $this->strip_comments( (string) file_get_contents( $file ) );
			$this->assertStringNotContainsString( 'remove_filter(', $src, basename( $file ) . ' calls remove_filter() -- this plugin must never unregister a hook it does not own.' );
			$this->assertStringNotContainsString( 'remove_action(', $src, basename( $file ) . ' calls remove_action() -- this plugin must never unregister a hook it does not own.' );
		}
	}

	/**
	 * No file under includes/ contains a hardcoded sibling-plugin slug or
	 * identifier, in any casing.
	 */
	public function test_no_hardcoded_sibling_plugin_identifiers() {
		$forbidden = $this->forbidden_sibling_identifiers();

		foreach ( $this->all_include_files() as $file ) {
			$src_lower = strtolower( (string) file_get_contents( $file ) );
			foreach ( $forbidden as $identifier ) {
				$this->assertStringNotContainsString(
					strtolower( $identifier ),
					$src_lower,
					basename( $file ) . " references the sibling-plugin identifier '{$identifier}' -- integration must go through a generic hook/filter, never a named dependency (ADR-0003)."
				);
			}
		}
	}
}
