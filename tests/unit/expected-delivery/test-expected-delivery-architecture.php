<?php
/**
 * Architecture guard tests for Milestone M7 — Storefront Expected Delivery.
 *
 * Source-scanning guards: the public contract is the interface, not the
 * concrete class; only the Service may call the Resolver (sole-entry-point
 * rule) or the Position Service; the Renderer is the only file touching
 * `woocommerce_get_availability`; no sibling-plugin coupling; zero mutation
 * anywhere in the M7 surface.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Expected_Delivery_Architecture extends WP_UnitTestCase {

	/**
	 * Interface's five approved accessors (STATE_* constants are not methods
	 * and are checked separately). This set must never drift without an
	 * API_VERSION bump.
	 */
	private const APPROVED_INTERFACE_METHODS = array(
		'api_version',
		'available_now',
		'confidence',
		'expected_date',
		'state',
	);

	/**
	 * Result's public surface: the five interface accessors plus its own
	 * static create() factory (not part of the interface contract).
	 */
	private const APPROVED_RESULT_METHODS = array(
		'api_version',
		'available_now',
		'confidence',
		'create',
		'expected_date',
		'state',
	);

	private const FORBIDDEN_RESOLVER_TOKENS = array(
		'$wpdb',
		'wc_get_product',
		'WC_Product',
		'get_option',
		'update_option',
		'apply_filters',
		'add_filter',
		'do_action',
		'current_time',
		'date_i18n',
		'date(',
		'gmdate(',
		'time()',
		'mktime',
		'strtotime',
		'DateTime',
		'DateTimeImmutable',
		'WP_Error',
		'is_wp_error',
		'__(',
		'esc_html',
	);

	private const FORBIDDEN_ACCESSOR_NAMES = array(
		'on_hand',
		'incoming',
		'position',
		'supplier',
		'po_number',
		'po_id',
		'outstanding',
		'is_delayed',
	);

	private const FORBIDDEN_WRITE_TOKENS = array(
		'set_stock_quantity',
		'update_post_meta',
		'->insert(',
		'->update(',
		'->delete(',
		'qty_received',
	);

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

	private function m7_files(): array {
		return array(
			'interface-wc-inventory-overview-expected-delivery-result.php',
			'class-wc-inventory-overview-expected-delivery-result.php',
			'class-wc-inventory-overview-expected-delivery-resolver.php',
			'class-wc-inventory-overview-expected-delivery-service.php',
			'class-wc-inventory-overview-expected-delivery-renderer.php',
		);
	}

	/**
	 * D12 extended to M7: the set of includes/ files referencing
	 * Inventory_Position_Service:: is exactly the list table and the
	 * Expected Delivery Service. M7 consumes Inventory Position (D12);
	 * it never becomes a second calculator.
	 *
	 * Extended by M21: Reorder Signal classification needs the same
	 * Position figures, so two more legitimate callers are added --
	 * neither becomes a second calculator either, both consume
	 * get_positions_bulk()'s result exactly as List_Table already did.
	 * - class-wc-inventory-overview-summary.php: classify_needs_reorder_bulk()
	 *   is the sole caller of get_positions_bulk() on Summary's own
	 *   behalf (INV-M21-2), for the store-wide needs_reorder scan and the
	 *   chart-row extension.
	 * - class-wc-inventory-overview-overview-controller.php:
	 *   ajax_save_inline_stock()'s badge-refresh response builds a single-item,
	 *   manage_woocommerce-gated Position lookup so the AJAX response can
	 *   include the same Reorder Signal badge the list table shows.
	 */
	public function test_only_list_table_and_expected_delivery_service_call_position_service() {
		$callers = array();

		foreach ( $this->all_include_files() as $file ) {
			$basename = basename( $file );
			if ( 'class-wc-inventory-overview-inventory-position-service.php' === $basename ) {
				continue; // Definition site, not a caller.
			}

			$src = $this->strip_comments( $this->src( $file ) );
			if ( false !== strpos( $src, 'Inventory_Position_Service::' ) ) {
				$callers[] = $basename;
			}
		}

		sort( $callers );

		$this->assertSame(
			array(
				'class-wc-inventory-overview-expected-delivery-service.php',
				'class-wc-inventory-overview-list-table.php',
				'class-wc-inventory-overview-overview-controller.php',
				'class-wc-inventory-overview-summary.php',
			),
			$callers,
			'Only the list table, the Expected Delivery Service, Summary (M21), and Overview_Controller (M21) may call Inventory_Position_Service:: (D12 sole-calculator discipline, extended to M7/M21).'
		);
	}

	/**
	 * Sole-entry-point rule: only the Service may call the Resolver. Any
	 * future REST/Store API/GraphQL/Blocks consumer must delegate to the
	 * Service and can never bypass it to call the Resolver directly.
	 */
	public function test_only_service_calls_resolver() {
		$callers = array();

		foreach ( $this->all_include_files() as $file ) {
			$basename = basename( $file );
			if ( 'class-wc-inventory-overview-expected-delivery-resolver.php' === $basename ) {
				continue; // Definition site, not a caller.
			}

			$src = $this->strip_comments( $this->src( $file ) );
			if ( false !== strpos( $src, 'Expected_Delivery_Resolver::' ) ) {
				$callers[] = $basename;
			}
		}

		$this->assertSame(
			array( 'class-wc-inventory-overview-expected-delivery-service.php' ),
			$callers,
			'Only WC_Inventory_Overview_Expected_Delivery_Service may call the Resolver (sole-entry-point rule).'
		);
	}

	/**
	 * Resolver is pure: no WordPress functions, no product loading, no
	 * clock/date library, no error-handling classes, no I/O of any kind.
	 * $today is always a caller-supplied parameter.
	 */
	public function test_resolver_is_pure() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . 'class-wc-inventory-overview-expected-delivery-resolver.php' ) );

		// 'date(' / 'gmdate(' mean "the PHP date()/gmdate() call", not "any
		// identifier ending in those letters" -- the Resolver legitimately
		// calls checkdate() (required by the plan for Y-m-d validity, the
		// same technique PO_Validation::validate_date() uses) and declares
		// is_valid_date(), both of which contain the literal substring
		// 'date(' without being a call to date()/gmdate().
		$function_call_tokens = array( 'date(', 'gmdate(' );

		foreach ( self::FORBIDDEN_RESOLVER_TOKENS as $needle ) {
			if ( in_array( $needle, $function_call_tokens, true ) ) {
				$this->assertSame(
					0,
					preg_match( '/(?<![A-Za-z0-9_])' . preg_quote( $needle, '/' ) . '/', $src ),
					'Resolver must not call forbidden function: ' . $needle
				);
				continue;
			}

			$this->assertStringNotContainsString( $needle, $src, 'Resolver must not contain forbidden token: ' . $needle );
		}
	}

	/**
	 * Renderer must go through the Service — never touch $wpdb, PO domain
	 * classes, or the Position Service directly.
	 */
	public function test_renderer_goes_through_service_only() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . 'class-wc-inventory-overview-expected-delivery-renderer.php' ) );

		$this->assertStringNotContainsString( '$wpdb', $src, 'Renderer must not touch $wpdb directly' );
		$this->assertStringNotContainsString( 'WC_Inventory_Overview_Purchase_Order', $src, 'Renderer must not touch PO domain classes directly' );
		$this->assertStringNotContainsString( 'Inventory_Position_Service', $src, 'Renderer must go through the Expected Delivery Service, never the Position Service directly' );
	}

	/**
	 * Result is final, implements the interface, has no setter/wither, and
	 * its public method set is exactly the approved five accessors plus
	 * create() -- nothing quietly added.
	 */
	public function test_result_is_final_immutable_and_matches_approved_surface() {
		$this->assertTrue(
			( new ReflectionClass( WC_Inventory_Overview_Expected_Delivery_Result::class ) )->isFinal(),
			'Result must be declared final'
		);

		$this->assertContains(
			WC_Inventory_Overview_Expected_Delivery_Result_Interface::class,
			class_implements( WC_Inventory_Overview_Expected_Delivery_Result::class ),
			'Result must implement the Result interface'
		);

		$methods = get_class_methods( WC_Inventory_Overview_Expected_Delivery_Result::class );
		sort( $methods );

		$this->assertSame( self::APPROVED_RESULT_METHODS, $methods, 'Result must expose exactly the approved public method set' );

		foreach ( $methods as $method ) {
			$this->assertStringStartsNotWith( 'set_', $method, 'Result must have no setter' );
			$this->assertStringStartsNotWith( 'with_', $method, 'Result must have no wither' );
		}
	}

	/**
	 * The interface -- the actual public contract -- declares exactly the
	 * approved five accessors. This is the thing that must not drift.
	 */
	public function test_interface_declares_exactly_the_approved_accessors() {
		$methods = get_class_methods( WC_Inventory_Overview_Expected_Delivery_Result_Interface::class );
		sort( $methods );

		$this->assertSame( self::APPROVED_INTERFACE_METHODS, $methods, 'Interface must declare exactly the approved five accessors' );
	}

	/**
	 * Both Service methods are type-declared against the interface, not the
	 * concrete class -- the promise is the contract, not the implementation.
	 */
	public function test_service_methods_are_typed_against_the_interface() {
		$service = new ReflectionClass( WC_Inventory_Overview_Expected_Delivery_Service::class );

		$single_return = $service->getMethod( 'get_for_product' )->getReturnType();
		$this->assertNotNull( $single_return );
		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::class, $single_return->getName() );

		$bulk_doc = (string) $service->getMethod( 'get_for_products_bulk' )->getDocComment();
		$this->assertStringContainsString( 'Result_Interface', $bulk_doc, 'Bulk method must document the interface as its element type' );
	}

	/**
	 * Neither the Result class nor the interface leaks raw Inventory Position
	 * data (supplier, PO, quantity, cost, or the raw delayed flag).
	 */
	public function test_result_and_interface_expose_no_forbidden_accessor_names() {
		$files = array(
			$this->includes_dir() . 'interface-wc-inventory-overview-expected-delivery-result.php',
			$this->includes_dir() . 'class-wc-inventory-overview-expected-delivery-result.php',
		);

		foreach ( $files as $file ) {
			$src = $this->strip_comments( $this->src( $file ) );
			foreach ( self::FORBIDDEN_ACCESSOR_NAMES as $needle ) {
				$this->assertStringNotContainsString( $needle, $src, basename( $file ) . ' must not expose forbidden accessor name: ' . $needle );
			}
		}
	}

	/**
	 * The Renderer is the only file in includes/ touching
	 * woocommerce_get_availability or registering a woocommerce_* filter, so
	 * a later milestone cannot bolt a second storefront hook elsewhere.
	 */
	public function test_renderer_is_the_sole_storefront_hook_site() {
		$availability_callers = array();
		$filter_registrars    = array();

		foreach ( $this->all_include_files() as $file ) {
			$src = $this->strip_comments( $this->src( $file ) );

			if ( false !== strpos( $src, 'woocommerce_get_availability' ) ) {
				$availability_callers[] = basename( $file );
			}

			if ( false !== strpos( $src, "add_filter( 'woocommerce_" ) ) {
				$filter_registrars[] = basename( $file );
			}
		}

		$this->assertSame( array( 'class-wc-inventory-overview-expected-delivery-renderer.php' ), $availability_callers );
		$this->assertSame( array( 'class-wc-inventory-overview-expected-delivery-renderer.php' ), $filter_registrars );
	}

	/**
	 * No M7 file removes another actor's filters, and none checks for a
	 * named third-party (non-WooCommerce) symbol -- the built-in renderer is
	 * generic, not sibling-plugin coupled.
	 */
	public function test_no_sibling_plugin_coupling() {
		foreach ( $this->m7_files() as $basename ) {
			$src = $this->strip_comments( $this->src( $this->includes_dir() . $basename ) );

			$this->assertStringNotContainsString( 'remove_filter(', $src, $basename . ' must not remove another actor\'s filters' );

			$this->assertSame(
				0,
				preg_match( '/\b(?:class_exists|function_exists)\(\s*[\'"](?!WooCommerce|WC_)/', $src ),
				$basename . ' must not gate on a named third-party symbol'
			);
		}
	}

	/**
	 * Zero mutation across the whole M7 surface: no stock write, no meta
	 * write, no direct DB write, no receiving-domain token.
	 */
	public function test_zero_mutation_across_m7_surface() {
		foreach ( $this->m7_files() as $basename ) {
			$src = $this->strip_comments( $this->src( $this->includes_dir() . $basename ) );

			foreach ( self::FORBIDDEN_WRITE_TOKENS as $needle ) {
				$this->assertStringNotContainsString( $needle, $src, $basename . ' must not contain forbidden write token: ' . $needle );
			}
		}
	}
}
