<?php
/**
 * Architecture guard tests for Milestone M27 — Personal Use Stock Adjustment.
 *
 * Source-scanning guards enforcing ADR-0004 / M27 plan invariants: outbound
 * Restock_Service mutators are callable only from Stock_Adjustment_Service;
 * admin/controller layers never call WC_Product::set_stock_quantity() for this
 * workflow; MAX_LINES is enforced in the service; movement types use named
 * constants; compare-and-swap and transaction patterns mirror Goods Receipt.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Stock_Adjustment_Architecture extends WP_UnitTestCase {

	/**
	 * @return string
	 */
	private function includes_dir(): string {
		return WC_INVENTORY_OVERVIEW_PATH . 'includes/';
	}

	/**
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
	 * @return string
	 */
	private function service_src(): string {
		return (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-stock-adjustment-service.php' );
	}

	/**
	 * Stock_Adjustment_Service is the only caller of Restock_Service outbound mutators.
	 */
	public function test_only_service_calls_outbound_restock_mutators() {
		$mutators = array( 'apply_outbound_line_change', 'apply_outbound_line_reversal' );

		foreach ( $mutators as $method ) {
			$callers = array();
			foreach ( $this->all_include_files() as $file ) {
				$basename = basename( $file );
				if ( 'class-wc-inventory-overview-restock-service.php' === $basename ) {
					continue;
				}
				$src = $this->strip_comments( (string) file_get_contents( $file ) );
				if ( false !== strpos( $src, '::' . $method . '(' ) ) {
					$callers[] = $basename;
				}
			}

			$this->assertSame(
				array( 'class-wc-inventory-overview-stock-adjustment-service.php' ),
				$callers,
				"Only Stock_Adjustment_Service may call {$method}() (sole outbound mutation entry point)."
			);
		}
	}

	/**
	 * SA admin/controller/list-table never call Restock outbound mutators or set_stock_quantity().
	 */
	public function test_no_ui_file_calls_outbound_mutators_or_set_stock_quantity() {
		$ui_files = array(
			'class-wc-inventory-overview-stock-adjustment-admin.php',
			'class-wc-inventory-overview-stock-adjustment-controller.php',
			'class-wc-inventory-overview-stock-adjustments-list-table.php',
		);
		foreach ( $ui_files as $basename ) {
			$src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . $basename ) );
			$this->assertStringNotContainsString( 'apply_outbound_line_change', $src, "{$basename} must not call Restock outbound mutators directly." );
			$this->assertStringNotContainsString( 'apply_outbound_line_reversal', $src, "{$basename} must not call Restock outbound mutators directly." );
			$this->assertStringNotContainsString( 'set_stock_quantity(', $src, "{$basename} must not mutate WooCommerce stock for the SA workflow." );
		}
	}

	/**
	 * Only Stock_Adjustment_Service persists header status via compare-and-swap methods.
	 */
	public function test_only_service_calls_compare_and_swap() {
		$methods = array( 'compare_and_swap_post', 'compare_and_swap_void' );
		foreach ( $methods as $method ) {
			$callers = array();
			foreach ( $this->all_include_files() as $file ) {
				$basename = basename( $file );
				if ( 'class-wc-inventory-overview-stock-adjustments.php' === $basename ) {
					continue;
				}
				$src = $this->strip_comments( (string) file_get_contents( $file ) );
				if ( false !== strpos( $src, 'Stock_Adjustments::' . $method . '(' ) ) {
					$callers[] = $basename;
				}
			}
			$this->assertSame(
				array( 'class-wc-inventory-overview-stock-adjustment-service.php' ),
				$callers,
				"Only Stock_Adjustment_Service may call Stock_Adjustments::{$method}()."
			);
		}
	}

	/**
	 * MAX_LINES is declared on the service and referenced in parse/save/post paths.
	 */
	public function test_max_lines_enforced_in_service() {
		$src = $this->service_src();
		$this->assertStringContainsString( 'const MAX_LINES = 100', $src );
		$this->assertGreaterThanOrEqual( 2, substr_count( $src, 'self::MAX_LINES' ), 'MAX_LINES must gate save and post paths.' );
		$this->assertStringContainsString( 'wc_io_sa_max_lines', $src );
	}

	/**
	 * throw_if_error() bridge exists and wraps fallible calls inside post/void.
	 */
	public function test_throw_if_error_bridge_used() {
		$src   = $this->service_src();
		$count = substr_count( $src, 'throw_if_error(' );
		$this->assertGreaterThan( 6, $count, 'throw_if_error() must wrap fallible calls inside transaction closures.' );
	}

	/**
	 * Movement types for personal use use named constants, never bare string literals.
	 */
	public function test_movement_type_uses_named_constants_not_literals() {
		$src = $this->strip_comments( $this->service_src() );
		$this->assertStringNotContainsString( "'personal_use'", $src, 'Movement type must use Movements::insert_personal_use(), never a bare literal.' );
		$this->assertStringNotContainsString( "'personal_use_void'", $src, 'Void movement type must use Movements::insert_personal_use_void(), never a bare literal.' );
		$this->assertStringContainsString( 'insert_personal_use(', $src );
		$this->assertStringContainsString( 'insert_personal_use_void(', $src );
	}

	/**
	 * Named movement constants are declared on Movements.
	 */
	public function test_movement_constants_declared() {
		$src = (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-movements.php' );
		$this->assertStringContainsString( "TYPE_PERSONAL_USE      = 'personal_use'", $src );
		$this->assertStringContainsString( "TYPE_PERSONAL_USE_VOID = 'personal_use_void'", $src );
		$this->assertStringContainsString( "REFERENCE_TYPE_STOCK_ADJUSTMENT = 'stock_adjustment'", $src );
	}

	/**
	 * Lifecycle has exactly three states and no reopen action (GR parity).
	 */
	public function test_lifecycle_is_exactly_three_states_no_reopen() {
		$this->assertSame(
			array( 'draft', 'posted', 'voided' ),
			WC_Inventory_Overview_Stock_Adjustment_Lifecycle::all()
		);
		$this->assertFalse( WC_Inventory_Overview_Stock_Adjustment_Lifecycle::can_transition(
			WC_Inventory_Overview_Stock_Adjustment_Lifecycle::STATUS_VOIDED,
			WC_Inventory_Overview_Stock_Adjustment_Lifecycle::STATUS_DRAFT
		) );
		$this->assertSame(
			array(),
			WC_Inventory_Overview_Stock_Adjustment_Lifecycle::transitions()[ WC_Inventory_Overview_Stock_Adjustment_Lifecycle::STATUS_VOIDED ]
		);
	}
}
