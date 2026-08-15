<?php
/**
 * M25 WP-M25-2/WP-M25-6: Replenishment_Commit_Service::commit()'s
 * service-boundary shape validation (§7/§28). These paths all return before
 * any lock acquisition or DB read, so they are genuinely DB-free unit tests.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Commit_Shape_Validation extends WP_UnitTestCase {

	private function line( $product_id = 1, $variation_id = 0, $qty = 1 ): array {
		return array(
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'qty'          => $qty,
		);
	}

	public function test_too_many_lines_returns_wp_error() {
		$items = array();
		for ( $i = 1; $i <= 101; $i++ ) {
			$items[] = $this->line( $i );
		}

		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit( $items );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_io_replen_commit_too_many', $result->get_error_code() );
	}

	public function test_exactly_max_lines_does_not_trigger_too_many() {
		$items = array();
		for ( $i = 1; $i <= WC_Inventory_Overview_Replenishment_Commit_Service::MAX_COMMIT_LINES; $i++ ) {
			$items[] = $this->line( $i );
		}

		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit( $items );

		$this->assertNotInstanceOf( WP_Error::class, $result, 'Exactly MAX_COMMIT_LINES must not be rejected as too_many.' );
	}

	public function test_empty_items_returns_empty_result_shape_not_error() {
		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['created'] );
		$this->assertSame( array(), $result['failed'] );
		$this->assertSame( array(), $result['skipped'] );
	}

	public function test_missing_keys_returns_malformed() {
		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit( array( array( 'product_id' => 1 ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_io_replen_commit_malformed', $result->get_error_code() );
	}

	public function test_non_array_row_returns_malformed() {
		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit( array( 'not-an-array' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_io_replen_commit_malformed', $result->get_error_code() );
	}

	public function test_non_numeric_product_id_returns_malformed() {
		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit( array( $this->line( 'abc', 0, 1 ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_io_replen_commit_malformed', $result->get_error_code() );
	}

	public function test_negative_product_id_returns_malformed() {
		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit( array( $this->line( -5, 0, 1 ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_io_replen_commit_malformed', $result->get_error_code() );
	}

	public function test_both_ids_zero_returns_malformed() {
		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit( array( $this->line( 0, 0, 1 ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_io_replen_commit_malformed', $result->get_error_code() );
	}

	public function test_zero_qty_returns_malformed() {
		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit( array( $this->line( 1, 0, 0 ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_io_replen_commit_malformed', $result->get_error_code() );
	}

	public function test_negative_qty_returns_malformed() {
		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit( array( $this->line( 1, 0, -3 ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_io_replen_commit_malformed', $result->get_error_code() );
	}

	public function test_non_numeric_qty_returns_malformed() {
		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit( array( $this->line( 1, 0, 'abc' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_io_replen_commit_malformed', $result->get_error_code() );
	}

	public function test_qty_over_one_million_returns_malformed() {
		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit( array( $this->line( 1, 0, 1000001 ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_io_replen_commit_malformed', $result->get_error_code() );
	}

	/**
	 * §48 Amendment C: duplicate submitted identities collapse to one
	 * canonical entry and must never inflate/evade MAX_COMMIT_LINES. Here,
	 * MAX_COMMIT_LINES + 1 raw rows that are ALL the same identity must
	 * still be rejected as too_many -- the cap applies to the raw submitted
	 * count, before dedup.
	 */
	public function test_duplicate_identities_do_not_evade_the_raw_line_cap() {
		$items = array();
		for ( $i = 0; $i < WC_Inventory_Overview_Replenishment_Commit_Service::MAX_COMMIT_LINES + 1; $i++ ) {
			$items[] = $this->line( 42, 0, 1 );
		}

		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit( $items );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_io_replen_commit_too_many', $result->get_error_code() );
	}
}
