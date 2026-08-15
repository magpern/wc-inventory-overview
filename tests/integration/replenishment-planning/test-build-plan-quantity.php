<?php
/**
 * M24 WP-M24-5: quantity resolution contract (§13, BR-M24-9).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Build_Plan_Quantity extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function line_for( array $plan, int $item_post_id ): ?array {
		foreach ( $plan['groups'] as $group ) {
			foreach ( $group['lines'] as $line ) {
				$id = $line['variation_id'] > 0 ? $line['variation_id'] : $line['product_id'];
				if ( $id === $item_post_id ) {
					return $line;
				}
			}
		}
		return null;
	}

	public function test_configured_default_qty_used() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$supplier = $this->create_supplier();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $supplier['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $product->get_id(), '17.5' );

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();
		$line = $this->line_for( $plan, $product->get_id() );

		$this->assertNotNull( $line );
		$this->assertEqualsWithDelta( 17.5, $line['qty_suggested'], 0.0001 );
	}

	public function test_unset_qty_stays_zero_never_defaults_to_one() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$supplier = $this->create_supplier();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $supplier['id'] );

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();
		$line = $this->line_for( $plan, $product->get_id() );

		$this->assertNotNull( $line );
		$this->assertEqualsWithDelta( 0.0, $line['qty_suggested'], 0.0001, 'An unset default_qty must stay 0.0, never guessed or defaulted to 1.' );
	}
}
