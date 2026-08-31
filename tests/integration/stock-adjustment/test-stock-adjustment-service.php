<?php
/**
 * Integration tests for WC_Inventory_Overview_Stock_Adjustment_Service (M27).
 *
 * Happy path, insufficient stock, failure-mode matrix (§15.1), idempotency,
 * void-after-intervening-receipt (§17.1), double void.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Stock_Adjustment_Service extends WC_Inventory_Overview_Test_Case {

	/**
	 * @var int
	 */
	private $admin_id;

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->truncate_sa_tables();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function tearDown(): void {
		remove_all_filters( 'query' );
		parent::tearDown();
	}

	private function truncate_sa_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Stock_Adjustment_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Stock_Adjustments::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Movements::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Receipt_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_Stock_Adjustment_Numbering::OPTION_KEY );
		delete_option( WC_Inventory_Overview_Goods_Receipt_Numbering::OPTION_KEY );
	}

	/**
	 * @param array<int,array{product_id:int,qty:float}> $lines Lines.
	 * @param string                                     $note  Note.
	 * @return int Draft adjustment id.
	 */
	private function create_draft( array $lines, string $note = 'Owner samples' ): int {
		$id = WC_Inventory_Overview_Stock_Adjustment_Service::create_draft();
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );

		$parsed_lines = array();
		foreach ( $lines as $line ) {
			$parsed_lines[] = array(
				'product_id'   => (int) $line['product_id'],
				'variation_id' => 0,
				'qty'          => (float) $line['qty'],
			);
		}

		$saved = WC_Inventory_Overview_Stock_Adjustment_Service::save_draft_from_post(
			$id,
			array(
				'note'  => $note,
				'lines' => $parsed_lines,
			)
		);
		$this->assertIsInt( $saved, is_wp_error( $saved ) ? $saved->get_error_message() : '' );

		return $id;
	}

	/**
	 * @param int $id Adjustment id.
	 * @return array<string,mixed>|WP_Error
	 */
	private function post_adjustment( int $id ) {
		$token = WC_Inventory_Overview_PO_Request_Token::issue( 'sa_post' );
		return WC_Inventory_Overview_Stock_Adjustment_Service::post( $id, $token );
	}

	/**
	 * @param int    $id     Adjustment id.
	 * @param string $reason Void reason.
	 * @return array<string,mixed>|WP_Error
	 */
	private function void_adjustment( int $id, string $reason = 'posted in error' ) {
		$token = WC_Inventory_Overview_PO_Request_Token::issue( 'sa_void' );
		return WC_Inventory_Overview_Stock_Adjustment_Service::void( $id, $token, $reason );
	}

	/**
	 * Seed stock + WAC on a simple product.
	 *
	 * @param float $stock Stock.
	 * @param float $avg   Average cost.
	 */
	private function product_with_cost( float $stock, float $avg ): WC_Product_Simple {
		$product = $this->create_simple_product( array( 'stock_qty' => $stock ) );
		$this->set_product_average_cost( $product, $avg );
		$this->set_product_inventory_value( $product, (float) wc_format_decimal( $stock * $avg, 4 ) );
		$product->save();
		return $product;
	}

	/**
	 * Post happy path: stock, cost, movement, line snapshots, header posted.
	 */
	public function test_post_happy_path_single_line() {
		$product = $this->product_with_cost( 20.0, 8.0 );
		$id      = $this->create_draft( array( array( 'product_id' => $product->get_id(), 'qty' => 5 ) ) );

		$result = $this->post_adjustment( $id );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'posted', $result['status'] );

		$fresh = wc_get_product( $product->get_id() );
		$this->assertDecimalEqual( 15.0, (float) $fresh->get_stock_quantity(), 4 );
		$this->assertDecimalEqual( 8.0, $this->get_product_average_cost( $fresh ), 6 );
		$this->assertDecimalEqual( 120.0, $this->get_product_inventory_value( $fresh ), 4 );

		global $wpdb;
		$movements = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . WC_Inventory_Overview_Movements::table_name() . ' WHERE reference_type = %s AND reference_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				WC_Inventory_Overview_Movements::REFERENCE_TYPE_STOCK_ADJUSTMENT,
				$id
			),
			ARRAY_A
		);
		$this->assertCount( 1, $movements );
		$this->assertSame( WC_Inventory_Overview_Movements::TYPE_PERSONAL_USE, $movements[0]['movement_type'] );
		$this->assertDecimalEqual( -5.0, (float) $movements[0]['quantity_change'], 4 );
	}

	/**
	 * Insufficient stock: fail closed, no partial state.
	 */
	public function test_post_rejects_insufficient_stock() {
		$product = $this->product_with_cost( 3.0, 10.0 );
		$id      = $this->create_draft( array( array( 'product_id' => $product->get_id(), 'qty' => 5 ) ) );

		$result = $this->post_adjustment( $id );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_sa_insufficient_stock', $result->get_error_code() );

		$fresh = wc_get_product( $product->get_id() );
		$this->assertDecimalEqual( 3.0, (float) $fresh->get_stock_quantity(), 4 );

		$header = WC_Inventory_Overview_Stock_Adjustments::get( $id );
		$this->assertSame( 'draft', $header['status'] );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Movements::table_name() . ' WHERE reference_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);
		$this->assertSame( 0, $count );
	}

	/**
	 * Movement insert failure: WC meta restored, header stays draft, zero movement rows.
	 */
	public function test_movement_insert_failure_restores_wc_meta() {
		$product = $this->product_with_cost( 10.0, 6.0 );
		$id      = $this->create_draft( array( array( 'product_id' => $product->get_id(), 'qty' => 4 ) ) );

		global $wpdb;
		$table     = WC_Inventory_Overview_Movements::table_name();
		$temp_name = $table . '_m27_hide';

		$wpdb->suppress_errors( true );
		try {
			$wpdb->query( "RENAME TABLE {$table} TO {$temp_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$result = $this->post_adjustment( $id );
		} finally {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $temp_name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $temp_name === $exists ) {
				$wpdb->query( "RENAME TABLE {$temp_name} TO {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
			$wpdb->suppress_errors( false );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_sa_movement', $result->get_error_code() );

		$fresh = wc_get_product( $product->get_id() );
		$this->assertDecimalEqual( 10.0, (float) $fresh->get_stock_quantity(), 4 );
		$this->assertDecimalEqual( 60.0, $this->get_product_inventory_value( $fresh ), 4 );

		$header = WC_Inventory_Overview_Stock_Adjustments::get( $id );
		$this->assertSame( 'draft', $header['status'] );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Movements::table_name() . ' WHERE reference_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);
		$this->assertSame( 0, $count );
	}

	/**
	 * Line 1 succeeds, line 2 WC save fails — line 1 restored, header stays draft.
	 */
	public function test_line_one_success_line_two_wc_save_fails_restores_line_one() {
		$p1 = $this->product_with_cost( 10.0, 8.0 );
		$p2 = $this->product_with_cost( 10.0, 8.0 );
		$id = $this->create_draft(
			array(
				array( 'product_id' => $p1->get_id(), 'qty' => 2 ),
				array( 'product_id' => $p2->get_id(), 'qty' => 2 ),
			)
		);

		$sabotage = wc_get_product( $p2->get_id() );
		$sabotage->set_manage_stock( false );
		$sabotage->save();

		$result = $this->post_adjustment( $id );
		$this->assertWPError( $result );

		$fresh1 = wc_get_product( $p1->get_id() );
		$this->assertDecimalEqual( 10.0, (float) $fresh1->get_stock_quantity(), 4, 'Line 1 must be fully restored.' );

		$header = WC_Inventory_Overview_Stock_Adjustments::get( $id );
		$this->assertSame( 'draft', $header['status'] );
	}

	/**
	 * Lines applied, header CAS loses race — all lines restored, header stays draft.
	 */
	public function test_cas_fail_restores_all_touched_lines() {
		$p1 = $this->product_with_cost( 10.0, 5.0 );
		$p2 = $this->product_with_cost( 8.0, 5.0 );
		$id = $this->create_draft(
			array(
				array( 'product_id' => $p1->get_id(), 'qty' => 1 ),
				array( 'product_id' => $p2->get_id(), 'qty' => 1 ),
			)
		);

		$table = WC_Inventory_Overview_Stock_Adjustments::table_name();
		add_filter(
			'query',
			static function ( $query ) use ( $id, $table ) {
				if (
					is_string( $query )
					&& false !== strpos( $query, $table )
					&& false !== strpos( $query, 'posted_at' )
					&& false !== strpos( $query, 'draft' )
				) {
					global $wpdb;
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$table} SET status = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							WC_Inventory_Overview_Stock_Adjustment_Lifecycle::STATUS_POSTED,
							$id
						)
					);
				}
				return $query;
			}
		);

		$result = $this->post_adjustment( $id );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_sa_post_race', $result->get_error_code() );

		$this->assertDecimalEqual( 10.0, (float) wc_get_product( $p1->get_id() )->get_stock_quantity(), 4 );
		$this->assertDecimalEqual( 8.0, (float) wc_get_product( $p2->get_id() )->get_stock_quantity(), 4 );

		$header = WC_Inventory_Overview_Stock_Adjustments::get( $id );
		$this->assertSame( 'draft', $header['status'], 'Failed CAS must roll back header status to draft.' );
	}

	/**
	 * Compensation restore failure returns wc_io_compensation_failed.
	 */
	public function test_compensation_failure_surfaces_error_code() {
		$product = $this->product_with_cost( 6.0, 4.0 );
		$id      = $this->create_draft( array( array( 'product_id' => $product->get_id(), 'qty' => 2 ) ) );
		$pid     = $product->get_id();

		global $wpdb;
		$mv_table = WC_Inventory_Overview_Movements::table_name();

		$wpdb->suppress_errors( true );
		add_filter(
			'query',
			static function ( $query ) use ( $mv_table, $pid ) {
				if ( is_string( $query ) && false !== strpos( $query, $mv_table ) && false !== stripos( $query, 'INSERT' ) ) {
					wp_delete_post( $pid, true );
					return str_replace( 'INSERT INTO', 'INSERT INTO definitely_not_a_table', $query );
				}
				return $query;
			}
		);

		$result = $this->post_adjustment( $id );
		remove_all_filters( 'query' );
		$wpdb->suppress_errors( false );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_compensation_failed', $result->get_error_code() );
	}

	/**
	 * Reused sa_post token rejected; second draft untouched.
	 */
	public function test_idempotent_post_token_single_use() {
		$product = $this->product_with_cost( 10.0, 5.0 );
		$id1     = $this->create_draft( array( array( 'product_id' => $product->get_id(), 'qty' => 1 ) ) );
		$token   = WC_Inventory_Overview_PO_Request_Token::issue( 'sa_post' );

		$first = WC_Inventory_Overview_Stock_Adjustment_Service::post( $id1, $token );
		$this->assertIsArray( $first );

		$id2 = $this->create_draft( array( array( 'product_id' => $product->get_id(), 'qty' => 1 ) ) );
		$second = WC_Inventory_Overview_Stock_Adjustment_Service::post( $id2, $token );
		$this->assertWPError( $second );
		$this->assertSame( 'wc_io_sa_token', $second->get_error_code() );

		$header2 = WC_Inventory_Overview_Stock_Adjustments::get( $id2 );
		$this->assertSame( 'draft', $header2['status'] );
	}

	/**
	 * §17.1 three-step: personal use → goods receipt at different cost → void SA.
	 */
	public function test_void_after_intervening_receipt_recalculates_wac() {
		$product = $this->product_with_cost( 20.0, 10.0 );
		$sa_id   = $this->create_draft( array( array( 'product_id' => $product->get_id(), 'qty' => 5 ) ) );

		$posted = $this->post_adjustment( $sa_id );
		$this->assertIsArray( $posted );

		$mid = wc_get_product( $product->get_id() );
		$this->assertDecimalEqual( 15.0, (float) $mid->get_stock_quantity(), 4 );
		$this->assertDecimalEqual( 10.0, $this->get_product_average_cost( $mid ), 6 );

		$gr_src = array(
			'wc_io_gr_currency'       => 'EUR',
			'wc_io_gr_line_product'   => array( $product->get_id() ),
			'wc_io_gr_line_qty'       => array( '10' ),
			'wc_io_gr_line_unit_cost' => array( '20' ),
		);
		$gr_id = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post( $gr_src );
		$this->assertIsInt( $gr_id );
		$gr_token = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' );
		$gr_post  = WC_Inventory_Overview_Goods_Receipt_Service::post( $gr_id, $gr_token );
		$this->assertIsArray( $gr_post );

		$before_void = wc_get_product( $product->get_id() );
		$this->assertDecimalEqual( 25.0, (float) $before_void->get_stock_quantity(), 4 );

		$lines = WC_Inventory_Overview_Stock_Adjustment_Lines::list_for_adjustment( $sa_id );
		$this->assertCount( 1, $lines );
		$value_removed = (float) $lines[0]['value_removed'];
		$qty_removed   = (float) $lines[0]['qty'];

		$stock_before = (float) wc_stock_amount( $before_void->get_stock_quantity() );
		$avg_before   = WC_Inventory_Overview_Costing::get_average_float( $before_void );
		$value_before = WC_Inventory_Overview_Restock_Service::read_inventory_value_at_post( $before_void, $stock_before, $avg_before );

		$voided = $this->void_adjustment( $sa_id, 'wrong qty' );
		$this->assertIsArray( $voided );
		$this->assertSame( 'voided', $voided['status'] );

		$expected_stock = (float) wc_format_decimal( $stock_before + $qty_removed, 4 );
		$expected_value = (float) wc_format_decimal( $value_before + $value_removed, 4 );
		$expected_avg   = $expected_stock > 0
			? (float) wc_format_decimal( $expected_value / $expected_stock, 6 )
			: 0.0;

		$after = wc_get_product( $product->get_id() );
		$this->assertDecimalEqual( $expected_stock, (float) $after->get_stock_quantity(), 4 );
		$this->assertDecimalEqual( $expected_value, WC_Inventory_Overview_Restock_Service::read_inventory_value_at_post(
			$after,
			(float) wc_stock_amount( $after->get_stock_quantity() ),
			WC_Inventory_Overview_Costing::get_average_float( $after )
		), 4 );
		$this->assertDecimalEqual( $expected_avg, $this->get_product_average_cost( $after ), 6 );

		$this->assertDecimalEqual( 30.0, (float) $after->get_stock_quantity(), 4, 'Must not naive-undo to pre-SA snapshot (20).' );
	}

	/**
	 * Second void on voided header rejected; no double restoration.
	 */
	public function test_double_void_rejected() {
		$product = $this->product_with_cost( 10.0, 5.0 );
		$id      = $this->create_draft( array( array( 'product_id' => $product->get_id(), 'qty' => 2 ) ) );

		$this->assertIsArray( $this->post_adjustment( $id ) );
		$this->assertIsArray( $this->void_adjustment( $id ) );

		$stock_after_first_void = (float) wc_get_product( $product->get_id() )->get_stock_quantity();

		$second = $this->void_adjustment( $id );
		$this->assertWPError( $second );
		$this->assertSame( 'wc_io_sa_not_posted', $second->get_error_code() );

		$this->assertDecimalEqual(
			$stock_after_first_void,
			(float) wc_get_product( $product->get_id() )->get_stock_quantity(),
			4,
			'Second void must not mutate stock again.'
		);
	}
}
