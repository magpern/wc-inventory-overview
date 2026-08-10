<?php
/**
 * Architecture guard tests for Milestone M11 —
 * WC_Inventory_Overview_Expected_Deadline.
 *
 * Source-scanning guards: the class is pure (no $wpdb, no writes, no
 * WordPress option access), closed to exactly its four documented methods
 * (deliberate scope-creep guard, per docs/milestones/m11-implementation-plan.md
 * §7 -- it must never grow into a general SQL-expression provider), and
 * called only by its two approved consumers.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Expected_Deadline_Architecture extends WP_UnitTestCase {

	private const CLASS_FILE = 'class-wc-inventory-overview-expected-deadline.php';

	private const APPROVED_METHODS = array(
		'has_known_date',
		'deadline',
		'sql_deadline_expression',
		'sql_has_known_date_expression',
	);

	/**
	 * @return string[]
	 */
	private function approved_callers(): array {
		return array(
			'class-wc-inventory-overview-po-delay.php',
			'class-wc-inventory-overview-supplier-lead-time-service.php',
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
	 * Purity: zero $wpdb usage anywhere in the class -- it never queries the
	 * database.
	 */
	public function test_class_never_touches_wpdb() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . self::CLASS_FILE ) );

		$this->assertStringNotContainsString( '$wpdb', $src, 'Expected_Deadline must never touch $wpdb -- it is pure PHP/SQL-string building only.' );
	}

	/**
	 * Purity: zero write tokens -- the class performs no mutation of any
	 * kind.
	 */
	public function test_class_performs_zero_writes() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . self::CLASS_FILE ) );

		$forbidden = array( '->insert(', '->update(', '->delete(', 'update_option', 'add_option', 'delete_option', 'wp_insert_post', 'wp_update_post', 'set_stock_quantity' );
		foreach ( $forbidden as $needle ) {
			$this->assertStringNotContainsString( $needle, $src, 'Expected_Deadline must not contain forbidden write/option-write token: ' . $needle );
		}
	}

	/**
	 * Purity: zero WordPress option access -- grace-days option lookup
	 * remains PO_Delay::grace_days_from_option()'s responsibility; callers
	 * pass grace_days in, Expected_Deadline never reads it itself.
	 */
	public function test_class_never_reads_wordpress_options() {
		$src = $this->strip_comments( $this->src( $this->includes_dir() . self::CLASS_FILE ) );

		$this->assertStringNotContainsString( 'get_option(', $src, 'Expected_Deadline must never call get_option() -- option lookup belongs to callers (PO_Delay::grace_days_from_option()).' );
	}

	/**
	 * Closed surface: exactly the four approved public static methods, no
	 * more -- a deliberate guard against this class accumulating unrelated
	 * helper methods (i.e. becoming a general SQL-expression provider) over
	 * time.
	 */
	public function test_class_exposes_exactly_the_approved_methods() {
		$class = new ReflectionClass( WC_Inventory_Overview_Expected_Deadline::class );

		$public_static_methods = array();
		foreach ( $class->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( $method->isStatic() && $method->getDeclaringClass()->getName() === $class->getName() ) {
				$public_static_methods[] = $method->getName();
			}
		}

		sort( $public_static_methods );
		$expected = self::APPROVED_METHODS;
		sort( $expected );

		$this->assertSame(
			$expected,
			$public_static_methods,
			'Expected_Deadline must expose exactly its four approved methods -- any addition must be a deliberate, reviewed extension of the plan.'
		);
	}

	/**
	 * Sole callers: only PO_Delay and Supplier_Lead_Time_Service may
	 * reference Expected_Deadline:: -- a future consumer must extend this
	 * allowlist deliberately, not silently (same discipline M9/M10 already
	 * use for their own guard tests).
	 */
	public function test_only_approved_files_call_the_class() {
		$callers = array();

		foreach ( $this->all_include_files() as $file ) {
			$basename = basename( $file );
			if ( self::CLASS_FILE === $basename ) {
				continue; // Definition site, not a caller.
			}

			$src = $this->strip_comments( $this->src( $file ) );
			if ( false !== strpos( $src, 'Expected_Deadline::' ) ) {
				$callers[] = $basename;
			}
		}

		sort( $callers );
		$expected = $this->approved_callers();
		sort( $expected );

		$this->assertSame(
			$expected,
			$callers,
			'Only the approved caller set may reference Expected_Deadline:: (sole-consumer discipline until a future milestone deliberately extends it).'
		);
	}
}
