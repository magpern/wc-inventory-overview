<?php
/**
 * Architecture guard tests for Milestone M4 — Receive Stock (Goods Receipt engine).
 *
 * Source-scanning guards enforcing the M4 implementation plan's binding
 * invariants: Goods_Receipt_Service is the sole caller of Restock_Service's
 * mutation methods; no direct DB_Transaction rollback call; every fallible
 * transaction call is wrapped in the throw_if_error() bridge; no irreversible
 * side effect inside a transaction closure; named movement constants only;
 * no row-locking constructs; po_line_id is never populated; no qty_received
 * reference; no PO table writes; no M5+ functionality.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Goods_Receipt_Architecture extends WP_UnitTestCase {

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
	 * @return string
	 */
	private function service_src(): string {
		return (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-goods-receipt-service.php' );
	}

	/**
	 * Goods_Receipt_Service is the only M4 caller of Restock_Service's two
	 * mutation methods (§Transaction model — single inventory mutation entry point).
	 */
	public function test_only_service_calls_restock_mutation_methods() {
		$mutators = array( 'apply_purchase_line_change', 'apply_purchase_line_reversal' );

		foreach ( $mutators as $method ) {
			$callers = array();
			foreach ( $this->all_include_files() as $file ) {
				$basename = basename( $file );
				if ( 'class-wc-inventory-overview-restock-service.php' === $basename ) {
					continue; // Definition site, not a caller.
				}
				$src = $this->strip_comments( (string) file_get_contents( $file ) );
				if ( false !== strpos( $src, '::' . $method . '(' ) ) {
					$callers[] = $basename;
				}
			}

			if ( 'apply_purchase_line_change' === $method ) {
				// Also called by Batch_Intake_Service (pre-existing, M4 must not remove it).
				$this->assertContains( 'class-wc-inventory-overview-goods-receipt-service.php', $callers );
				$this->assertContains( 'class-wc-inventory-overview-batch-intake-service.php', $callers );
				$allowed = array(
					'class-wc-inventory-overview-goods-receipt-service.php',
					'class-wc-inventory-overview-batch-intake-service.php',
					'class-wc-inventory-overview-restock-service.php',
				);
				foreach ( $callers as $c ) {
					$this->assertContains( $c, $allowed, "Unexpected caller of {$method}(): {$c}" );
				}
			} else {
				$this->assertSame(
					array( 'class-wc-inventory-overview-goods-receipt-service.php' ),
					$callers,
					"Only Goods_Receipt_Service may call {$method}() (sole M4 inventory mutation entry point)."
				);
			}
		}
	}

	/**
	 * No admin/UI/controller file calls the Restock_Service mutators directly.
	 */
	public function test_no_ui_file_calls_restock_mutators_directly() {
		$ui_files = array(
			'class-wc-inventory-overview-goods-receipt-admin.php',
			'class-wc-inventory-overview-goods-receipts-list-table.php',
			'class-wc-inventory-overview-purchasing-page.php',
		);
		foreach ( $ui_files as $basename ) {
			$src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . $basename ) );
			$this->assertStringNotContainsString( 'apply_purchase_line_change', $src, "{$basename} must not call Restock_Service mutators directly" );
			$this->assertStringNotContainsString( 'apply_purchase_line_reversal', $src, "{$basename} must not call Restock_Service mutators directly" );
		}
	}

	/**
	 * Only Goods_Receipt_Service persists receipt status via the compare-and-swap methods.
	 */
	public function test_only_service_calls_compare_and_swap() {
		$methods = array( 'compare_and_swap_post', 'compare_and_swap_void' );
		foreach ( $methods as $method ) {
			$callers = array();
			foreach ( $this->all_include_files() as $file ) {
				$basename = basename( $file );
				if ( 'class-wc-inventory-overview-goods-receipts.php' === $basename ) {
					continue; // Definition site.
				}
				$src = $this->strip_comments( (string) file_get_contents( $file ) );
				if ( false !== strpos( $src, $method . '(' ) ) {
					$callers[] = $basename;
				}
			}
			$this->assertSame(
				array( 'class-wc-inventory-overview-goods-receipt-service.php' ),
				$callers,
				"Only Goods_Receipt_Service may call {$method}()."
			);
		}
	}

	/**
	 * No direct DB_Transaction begin()/commit()/rollback() calls from
	 * Goods_Receipt_Service — only $txn->run() closures.
	 */
	public function test_service_never_calls_transaction_methods_directly() {
		$src = $this->service_src();
		$this->assertStringNotContainsString( '->rollback(', $src, 'Goods_Receipt_Service must never call rollback() directly — only DB_Transaction::run() may roll back.' );
		$this->assertStringNotContainsString( '->begin(', $src, 'Goods_Receipt_Service must never call begin() directly — only via $txn->run().' );
		$this->assertStringNotContainsString( '->commit(', $src, 'Goods_Receipt_Service must never call commit() directly — only via $txn->run().' );
		$this->assertStringContainsString( '->run(', $src, 'Goods_Receipt_Service must use DB_Transaction::run().' );
	}

	/**
	 * throw_if_error() bridge exists and is used substantially inside the service
	 * (every fallible call inside a run() closure must be wrapped).
	 */
	public function test_throw_if_error_bridge_used() {
		$src = $this->service_src();
		$this->assertStringContainsString( 'function throw_if_error(', $src );
		$count = substr_count( $src, 'throw_if_error(' );
		// One definition + at least one call per fallible operation across create/update/delete/post/void.
		$this->assertGreaterThan( 8, $count, 'throw_if_error() must wrap most fallible calls inside transaction closures.' );
	}

	/**
	 * No bare WP_Error is left unthrown inside a run() closure. Every
	 * `$txn->run( function () use (...) { ... } );` closure body in
	 * Goods_Receipt_Service is extracted by brace-matching; inside each one,
	 * every fallible call (anything returning WP_Error, and every direct
	 * `new WP_Error(`) must be wrapped in throw_if_error() — a bare
	 * `return new WP_Error(` or an un-wrapped repository/service call would
	 * silently commit a partial mutation on failure.
	 */
	public function test_no_bare_wp_error_inside_transaction_closures() {
		$src      = $this->service_src();
		$closures = $this->extract_run_closure_bodies( $src );

		$this->assertGreaterThanOrEqual( 5, count( $closures ), 'Expected at least 5 $txn->run() closures (create/update draft, delete, post, void).' );

		foreach ( $closures as $i => $body ) {
			$stripped = $this->strip_comments( $body );
			$this->assertStringNotContainsString( 'return new WP_Error(', $stripped, "Closure #{$i}: a WP_Error must never be returned bare from inside a transaction closure — wrap it in throw_if_error()." );
			$this->assertStringNotContainsString( '->rollback(', $stripped, "Closure #{$i}: no direct rollback() call is permitted inside a closure." );

			// Every direct `new WP_Error(` construction must be the argument of throw_if_error(.
			preg_match_all( '/new WP_Error\(/', $stripped, $raw_matches, PREG_OFFSET_CAPTURE );
			foreach ( $raw_matches[0] as $match ) {
				$offset = $match[1];
				$before = substr( $stripped, max( 0, $offset - 30 ), 30 );
				$this->assertStringContainsString(
					'throw_if_error(',
					$before,
					"Closure #{$i}: found `new WP_Error(` not immediately wrapped by throw_if_error() at offset {$offset}: …" . trim( $before ) . '…'
				);
			}
		}
	}

	/**
	 * Extract a named method's body via brace-matching (handles nested braces,
	 * unlike a non-greedy regex which stops at the first inner closing brace).
	 *
	 * @param string $src        Full source.
	 * @param string $method_name Method name (no parens).
	 * @return string|null Body between the outermost { and }, or null if not found.
	 */
	private function extract_method_body( string $src, string $method_name ): ?string {
		if ( ! preg_match( '/function\s+' . preg_quote( $method_name, '/' ) . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$search_from = $m[0][1];
		$brace_start = strpos( $src, '{', $search_from );
		if ( false === $brace_start ) {
			return null;
		}
		$depth = 0;
		for ( $i = $brace_start; $i < strlen( $src ); $i++ ) {
			if ( '{' === $src[ $i ] ) {
				++$depth;
			} elseif ( '}' === $src[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $src, $brace_start + 1, $i - $brace_start - 1 );
				}
			}
		}
		return null;
	}

	/**
	 * Extract the bodies of every `$txn->run( function () use (...) { ... } );`
	 * closure in $src via brace-matching (regex cannot reliably match nested braces).
	 *
	 * @param string $src Full source.
	 * @return string[] Closure bodies (contents between the outermost { and }).
	 */
	private function extract_run_closure_bodies( string $src ): array {
		$bodies = array();
		$offset = 0;
		while ( false !== ( $pos = strpos( $src, '$txn->run(', $offset ) ) ) {
			$brace_start = strpos( $src, '{', $pos );
			if ( false === $brace_start ) {
				break;
			}
			$depth = 0;
			$end   = null;
			for ( $i = $brace_start; $i < strlen( $src ); $i++ ) {
				if ( '{' === $src[ $i ] ) {
					++$depth;
				} elseif ( '}' === $src[ $i ] ) {
					--$depth;
					if ( 0 === $depth ) {
						$end = $i;
						break;
					}
				}
			}
			if ( null === $end ) {
				break;
			}
			$bodies[] = substr( $src, $brace_start + 1, $end - $brace_start - 1 );
			$offset   = $end + 1;
		}
		return $bodies;
	}

	/**
	 * Cache/transient invalidation (an irreversible side effect) only happens inside
	 * invalidate_product_caches(), which is called strictly after $txn->run() has
	 * returned or thrown — never inside a transaction closure. It is called on both
	 * the success path (post-commit, so the fresh state is visible) and the failure
	 * path (post-rollback, so any product WooCommerce's own update_post_meta()
	 * already wrote through to the object cache before the SQL ROLLBACK is
	 * invalidated too — the SQL rollback cannot reach a non-transactional cache
	 * backend such as this deployment's Redis object cache).
	 */
	public function test_cache_invalidation_isolated_to_transaction_boundary() {
		$src = $this->strip_comments( $this->service_src() );

		$helper_body = $this->extract_method_body( $src, 'invalidate_product_caches' );
		if ( null === $helper_body ) {
			$this->fail( 'invalidate_product_caches() helper not found.' );
		}

		$this->assertStringContainsString( 'wc_delete_product_transients', $helper_body );

		$total_occurrences  = substr_count( $src, 'wc_delete_product_transients' );
		$helper_occurrences = substr_count( $helper_body, 'wc_delete_product_transients' );
		$this->assertSame(
			$helper_occurrences,
			$total_occurrences,
			'wc_delete_product_transients() must only be called from inside invalidate_product_caches(), never inside a transaction closure.'
		);

		// Called exactly 4 times: post()'s success + catch, void()'s success + catch.
		$this->assertSame( 4, substr_count( $src, 'self::invalidate_product_caches(' ) );

		// None of those call sites may appear inside a $txn->run() closure body.
		foreach ( $this->extract_run_closure_bodies( $src ) as $i => $body ) {
			$this->assertStringNotContainsString( 'invalidate_product_caches', $body, "Closure #{$i}: cache invalidation must never run inside the transaction closure." );
		}
	}

	/**
	 * Movement type is always referenced via named class constants, never inlined
	 * as a bare string literal, matching the existing TYPE_PURCHASE/TYPE_PURCHASE_BATCH
	 * convention.
	 */
	public function test_movement_type_uses_named_constants_not_literals() {
		$src = $this->strip_comments( $this->service_src() );
		$this->assertStringNotContainsString( "'goods_receipt'", $src, 'Movement type must use WC_Inventory_Overview_Movements::TYPE_GOODS_RECEIPT, never the bare string literal.' );
		$this->assertStringNotContainsString( "'goods_receipt_void'", $src, 'Movement type must use WC_Inventory_Overview_Movements::TYPE_GOODS_RECEIPT_VOID, never the bare string literal.' );
		$this->assertStringContainsString( 'insert_goods_receipt(', $src );
		$this->assertStringContainsString( 'insert_goods_receipt_void(', $src );
	}

	/**
	 * Named movement constants are declared on Movements.
	 */
	public function test_movement_constants_declared() {
		$src = (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-movements.php' );
		$this->assertStringContainsString( "TYPE_GOODS_RECEIPT      = 'goods_receipt'", $src );
		$this->assertStringContainsString( "TYPE_GOODS_RECEIPT_VOID = 'goods_receipt_void'", $src );
	}

	/**
	 * No row-locking constructs anywhere in the M4 surface (deliberate concurrency
	 * posture: idempotency token + compare-and-swap only, §Idempotency & concurrency).
	 */
	public function test_no_row_locking_constructs_in_m4_files() {
		$m4_files = array(
			'class-wc-inventory-overview-goods-receipt-numbering.php',
			'class-wc-inventory-overview-goods-receipt-lifecycle.php',
			'class-wc-inventory-overview-goods-receipts.php',
			'class-wc-inventory-overview-receipt-lines.php',
			'class-wc-inventory-overview-receipt-costs.php',
			'class-wc-inventory-overview-goods-receipt-costing.php',
			'class-wc-inventory-overview-goods-receipt-service.php',
			'class-wc-inventory-overview-goods-receipt-admin.php',
			'class-wc-inventory-overview-goods-receipts-list-table.php',
		);
		foreach ( $m4_files as $basename ) {
			$src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . $basename ) );
			$this->assertStringNotContainsString( 'FOR UPDATE', $src, "{$basename} must not use SELECT ... FOR UPDATE." );
			$this->assertStringNotContainsString( 'LOCK TABLES', $src, "{$basename} must not use LOCK TABLES." );
			$this->assertStringNotContainsString( 'GET_LOCK', $src, "{$basename} must not use a distributed lock." );
		}
	}

	/**
	 * po_line_id is never set to anything but NULL by any M4 code path — no
	 * repository method accepts it as a settable field, and the service never
	 * references it as a parameter.
	 */
	public function test_po_line_id_never_populated() {
		$repo_src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-receipt-lines.php' ) );
		$this->assertStringNotContainsString( "\$data['po_line_id']", $repo_src, 'Receipt_Lines must not read po_line_id from caller-supplied $data — it is always NULL in M4.' );
		$this->assertMatchesRegularExpression( "/'po_line_id'\\s*=>\\s*null,/", $repo_src, 'Receipt_Lines::create() must persist po_line_id as a hardcoded NULL, not a caller-supplied value.' );

		$service_src = $this->strip_comments( $this->service_src() );
		$this->assertStringNotContainsString( 'po_line_id', $service_src, 'Goods_Receipt_Service must never reference po_line_id — M4 has no PO linkage.' );
	}

	/**
	 * No qty_received reference in executable M4 code.
	 */
	public function test_no_qty_received_in_m4_surface() {
		$m4_files = array(
			'class-wc-inventory-overview-goods-receipt-numbering.php',
			'class-wc-inventory-overview-goods-receipt-lifecycle.php',
			'class-wc-inventory-overview-goods-receipts.php',
			'class-wc-inventory-overview-receipt-lines.php',
			'class-wc-inventory-overview-receipt-costs.php',
			'class-wc-inventory-overview-goods-receipt-costing.php',
			'class-wc-inventory-overview-goods-receipt-service.php',
			'class-wc-inventory-overview-goods-receipt-admin.php',
			'class-wc-inventory-overview-goods-receipts-list-table.php',
		);
		foreach ( $m4_files as $basename ) {
			$src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . $basename ) );
			$this->assertStringNotContainsString( 'qty_received', $src, "{$basename} must not reference qty_received." );
		}
	}

	/**
	 * No M4 file writes to any Purchase Order table (wc_io_purchase_orders,
	 * wc_io_purchase_order_lines, wc_io_po_events).
	 */
	public function test_no_po_table_writes_in_m4_files() {
		$m4_files = array(
			'class-wc-inventory-overview-goods-receipt-service.php',
			'class-wc-inventory-overview-goods-receipt-admin.php',
			'class-wc-inventory-overview-receipt-lines.php',
			'class-wc-inventory-overview-goods-receipts.php',
		);
		$forbidden = array(
			'WC_Inventory_Overview_Purchase_Orders::',
			'WC_Inventory_Overview_Purchase_Order_Lines::',
			'WC_Inventory_Overview_PO_Events::',
			'WC_Inventory_Overview_PO_Service::',
		);
		foreach ( $m4_files as $basename ) {
			$src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . $basename ) );
			foreach ( $forbidden as $needle ) {
				$this->assertStringNotContainsString( $needle, $src, "{$basename} must not reference {$needle} — M4 never touches PO tables." );
			}
		}
	}

	/**
	 * No M5+ functionality: no "Receive Against PO" / PO-linked receiving UI or
	 * service surface anywhere in the M4 files.
	 */
	public function test_no_m5_receive_against_po_functionality() {
		$m4_files = array(
			'class-wc-inventory-overview-goods-receipt-service.php',
			'class-wc-inventory-overview-goods-receipt-admin.php',
		);
		$forbidden = array( 'receive_against_po', 'Receive Against PO', 'po_line_completion' );
		foreach ( $m4_files as $basename ) {
			$src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . $basename ) );
			foreach ( $forbidden as $needle ) {
				$this->assertStringNotContainsString( $needle, $src, "{$basename} must not contain M5 functionality: {$needle}." );
			}
		}
	}

	/**
	 * Lifecycle has exactly three states and no reopen action.
	 */
	public function test_lifecycle_is_exactly_three_states_no_reopen() {
		$this->assertSame(
			array( 'draft', 'posted', 'voided' ),
			WC_Inventory_Overview_Goods_Receipt_Lifecycle::all()
		);
		$this->assertFalse( WC_Inventory_Overview_Goods_Receipt_Lifecycle::has_reopen() );
		$this->assertSame(
			array(),
			WC_Inventory_Overview_Goods_Receipt_Lifecycle::transitions()[ WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_VOIDED ]
		);
	}

	/**
	 * Schema v8 does not add qty_received anywhere and does not add a header-level
	 * po_id column on wc_io_goods_receipts.
	 */
	public function test_schema_v8_has_no_po_linkage_header_column() {
		$install_src = $this->strip_comments( (string) file_get_contents( $this->includes_dir() . 'class-wc-inventory-overview-install.php' ) );
		$this->assertMatchesRegularExpression(
			'/\$goods_receipts\s*=.*?CREATE TABLE.*?\{\$goods_receipts\}\s*\((.*?)\)\s*\{\$collate\}/s',
			$install_src,
			'Could not locate the wc_io_goods_receipts CREATE TABLE block.'
		);
		preg_match( '/\$goods_receipts\s*=.*?CREATE TABLE.*?\{\$goods_receipts\}\s*\((.*?)\)\s*\{\$collate\}/s', $install_src, $m );
		$table_body = $m[1];
		$this->assertStringNotContainsString( 'po_id', $table_body, 'wc_io_goods_receipts must have no header-level po_id column (D6: line-level linkage only).' );
	}
}
