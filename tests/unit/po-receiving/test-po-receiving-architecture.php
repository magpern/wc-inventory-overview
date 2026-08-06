<?php
/**
 * Architecture guard tests for Milestone M5 — Purchase Order Receiving.
 *
 * Source-scanning guards enforcing the M5 implementation plan's binding
 * invariants (§Receiving-status ownership / Formal invariant): qty_received has
 * exactly one physical writer (increment_qty_received()), called only by
 * PO_Receiving_Sync; PO_Receiving_Sync's receiving-path method (apply_line_delta)
 * is called only by Goods_Receipt_Service; its reconciliation-path method
 * (reconcile_line) is called only by the reconciliation CLI; PO_Receiving_Sync
 * never opens its own transaction; Restock_Service's caller set gained zero new
 * entries; the per-line wc_io_purchase_order_lines.status column is never
 * assigned a new value by any M5 file.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_PO_Receiving_Architecture extends WP_UnitTestCase {

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
	 * Guard 1 (sole physical writer): increment_qty_received() is called from
	 * exactly one file anywhere in includes/.
	 */
	public function test_guard1_increment_qty_received_sole_caller() {
		$callers = array();
		foreach ( $this->all_include_files() as $file ) {
			$basename = basename( $file );
			if ( 'class-wc-inventory-overview-purchase-order-lines.php' === $basename ) {
				continue; // Definition site.
			}
			$src = $this->strip_comments( (string) file_get_contents( $file ) );
			if ( false !== strpos( $src, '::increment_qty_received(' ) ) {
				$callers[] = $basename;
			}
		}
		$this->assertSame(
			array( 'class-wc-inventory-overview-po-receiving-sync.php' ),
			$callers,
			'Only PO_Receiving_Sync may call increment_qty_received().'
		);
	}

	/**
	 * Guard 2 (sole orchestrator of the receiving path): PO_Receiving_Sync::
	 * apply_line_delta() is called from exactly one file.
	 */
	public function test_guard2_apply_line_delta_sole_caller() {
		$callers = array();
		foreach ( $this->all_include_files() as $file ) {
			$basename = basename( $file );
			if ( 'class-wc-inventory-overview-po-receiving-sync.php' === $basename ) {
				continue; // Definition site.
			}
			$src = $this->strip_comments( (string) file_get_contents( $file ) );
			if ( false !== strpos( $src, '::apply_line_delta(' ) ) {
				$callers[] = $basename;
			}
		}
		$this->assertSame(
			array( 'class-wc-inventory-overview-goods-receipt-service.php' ),
			$callers,
			'Only Goods_Receipt_Service may call PO_Receiving_Sync::apply_line_delta().'
		);
	}

	/**
	 * Guard 2b (sole caller of the reconciliation path): PO_Receiving_Sync::
	 * reconcile_line() is called from exactly one file (the reconciliation CLI),
	 * never from Goods_Receipt_Service or anywhere else.
	 */
	public function test_guard2b_reconcile_line_sole_caller() {
		$callers = array();
		foreach ( $this->all_include_files() as $file ) {
			$basename = basename( $file );
			if ( 'class-wc-inventory-overview-po-receiving-sync.php' === $basename ) {
				continue; // Definition site.
			}
			$src = $this->strip_comments( (string) file_get_contents( $file ) );
			if ( false !== strpos( $src, '::reconcile_line(' ) ) {
				$callers[] = $basename;
			}
		}
		$this->assertSame(
			array( 'class-wc-inventory-overview-reconcile-cli-command.php' ),
			$callers,
			'Only the reconciliation CLI command may call PO_Receiving_Sync::reconcile_line().'
		);

		$service_src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-goods-receipt-service.php' ) );
		$this->assertStringNotContainsString( 'reconcile_line', $service_src, 'Goods_Receipt_Service must never call reconcile_line() — apply_line_delta() only.' );
	}

	/**
	 * Restock_Service's mutator caller set gained zero new entries under M5 —
	 * PO_Receiving_Sync must never appear in it (proves M5 didn't accidentally
	 * create a second stock-mutation path).
	 */
	public function test_restock_service_callers_unchanged_by_m5() {
		$mutators = array( 'apply_purchase_line_change', 'apply_purchase_line_reversal' );
		$allowed  = array(
			'class-wc-inventory-overview-goods-receipt-service.php',
			'class-wc-inventory-overview-batch-intake-service.php',
			'class-wc-inventory-overview-restock-service.php',
		);
		foreach ( $mutators as $method ) {
			foreach ( $this->all_include_files() as $file ) {
				$basename = basename( $file );
				if ( 'class-wc-inventory-overview-restock-service.php' === $basename ) {
					continue;
				}
				$src = $this->strip_comments( (string) file_get_contents( $file ) );
				if ( false !== strpos( $src, '::' . $method . '(' ) ) {
					$this->assertContains( $basename, $allowed, "Unexpected caller of {$method}(): {$basename} — PO_Receiving_Sync must never call Restock_Service directly." );
				}
			}
		}
	}

	/**
	 * PO_Receiving_Sync never opens its own transaction — it must run inside
	 * whatever transaction its caller (Goods_Receipt_Service, or the
	 * reconciliation CLI for reconcile_line()) already has open.
	 */
	public function test_po_receiving_sync_never_opens_own_transaction() {
		$src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-po-receiving-sync.php' ) );
		$this->assertStringNotContainsString( '->begin(', $src );
		$this->assertStringNotContainsString( '->commit(', $src );
		$this->assertStringNotContainsString( '->rollback(', $src );
		$this->assertStringNotContainsString( 'new WC_Inventory_Overview_DB_Transaction', $src );
	}

	/**
	 * The per-line wc_io_purchase_order_lines.status bookkeeping column is never
	 * assigned a new value by any M5 file — "line completion" is derived from
	 * qty_outstanding == 0, never stored as a line-level enum (M5 plan
	 * §Scope-boundary ruling).
	 */
	public function test_no_new_per_line_status_value_assigned() {
		$m5_files = array(
			'class-wc-inventory-overview-po-receiving-sync.php',
			'class-wc-inventory-overview-goods-receipt-service.php',
			'class-wc-inventory-overview-purchase-order-lines.php',
			'class-wc-inventory-overview-reconcile-cli-command.php',
		);
		foreach ( $m5_files as $basename ) {
			$src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . $basename ) );
			$this->assertStringNotContainsString( "'status' => 'partially_received'", $src, "{$basename} must never write a per-line status value." );
			$this->assertStringNotContainsString( "'status' => 'received'", $src, "{$basename} must never write a per-line status value." );
		}
	}

	/**
	 * No M5 file uses row-level locking, distributed locks, or table locks —
	 * the same deliberate no-locking posture M4 established (token +
	 * compare-and-swap / atomic UPDATE only).
	 */
	public function test_no_row_locking_constructs_in_m5_files() {
		$m5_files = array(
			'class-wc-inventory-overview-po-receiving-sync.php',
			'class-wc-inventory-overview-reconcile-cli-command.php',
		);
		foreach ( $m5_files as $basename ) {
			$src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . $basename ) );
			$this->assertStringNotContainsString( 'FOR UPDATE', $src, "{$basename} must not use SELECT ... FOR UPDATE." );
			$this->assertStringNotContainsString( 'LOCK TABLES', $src, "{$basename} must not use LOCK TABLES." );
			$this->assertStringNotContainsString( 'GET_LOCK', $src, "{$basename} must not use a distributed lock." );
		}
	}

	/**
	 * PO_Receiving_Sync's event-writing helpers only ever pass named
	 * WC_Inventory_Overview_PO_Events::TYPE_* constants, never inlined string
	 * literals, matching the movement-constant convention M4 established.
	 */
	public function test_po_receiving_sync_uses_named_event_constants() {
		$src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-po-receiving-sync.php' ) );
		$this->assertStringContainsString( 'PO_Events::TYPE_LINE_RECEIVED', $src );
		$this->assertStringContainsString( 'PO_Events::TYPE_LINE_RECEIPT_VOIDED', $src );
		$this->assertStringContainsString( 'PO_Events::TYPE_PARTIALLY_RECEIVED', $src );
		$this->assertStringContainsString( 'PO_Events::TYPE_RECEIVED', $src );
		$this->assertStringContainsString( 'PO_Events::TYPE_QTY_RECEIVED_RECONCILED', $src );
		$this->assertStringNotContainsString( "'event_type' => 'po_line_received'", $src, 'Must use the named constant, never the bare string literal.' );
	}
}
