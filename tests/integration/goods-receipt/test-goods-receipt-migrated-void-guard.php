<?php
/**
 * M6: Goods_Receipt_Service::void() must reject source='migrated' receipts —
 * voiding reverses stock/cost relative to CURRENT state, which is wrong for
 * a historical replay row (see the M6 plan's Migration model — "Rollback of
 * a migration is not a receipt void"). All other receipt sources must be
 * unaffected by this guard.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Goods_Receipt_Migrated_Void_Guard extends WC_Inventory_Overview_Test_Case {

	private $admin_id;

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_batch_costs' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_batch_lines' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_batches' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_Goods_Receipt_Numbering::OPTION_KEY );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function test_void_rejects_migrated_receipt_with_zero_mutation() {
		$fixture = $this->create_legacy_batch(
			array(
				'qty'       => 4,
				'line_cost' => 40,
			)
		);
		$result  = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$before_stock = get_post_meta( $fixture['product_id'], '_stock', true );

		$void = WC_Inventory_Overview_Goods_Receipt_Service::void(
			$result['receipt_id'],
			'Attempted void of migrated receipt',
			WC_Inventory_Overview_PO_Request_Token::issue( 'gr_void' )
		);
		$this->assertWPError( $void );
		$this->assertSame( 'wc_io_gr_migrated_no_void', $void->get_error_code() );

		$receipt = WC_Inventory_Overview_Goods_Receipts::get( $result['receipt_id'] );
		$this->assertSame( WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_POSTED, $receipt['status'], 'A rejected void must not change status.' );

		wc_delete_product_transients( $fixture['product_id'] );
		clean_post_cache( $fixture['product_id'] );
		$this->assertSame( $before_stock, get_post_meta( $fixture['product_id'], '_stock', true ) );
	}

	public function test_void_of_a_normal_direct_receipt_is_unaffected_by_the_guard() {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );

		$draft_id = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post(
			array(
				'wc_io_gr_currency'       => 'EUR',
				'wc_io_gr_line_product'   => array( $product->get_id() ),
				'wc_io_gr_line_qty'       => array( '5' ),
				'wc_io_gr_line_unit_cost' => array( '10' ),
			)
		);
		$this->assertIsInt( $draft_id, is_wp_error( $draft_id ) ? $draft_id->get_error_message() : '' );

		$posted = WC_Inventory_Overview_Goods_Receipt_Service::post( $draft_id, WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' ) );
		$this->assertIsArray( $posted, is_wp_error( $posted ) ? $posted->get_error_message() : '' );

		$void = WC_Inventory_Overview_Goods_Receipt_Service::void(
			$draft_id,
			'Normal void',
			WC_Inventory_Overview_PO_Request_Token::issue( 'gr_void' )
		);
		$this->assertIsArray( $void, is_wp_error( $void ) ? $void->get_error_message() : '' );
		$this->assertSame( WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_VOIDED, $void['status'] );
	}
}
