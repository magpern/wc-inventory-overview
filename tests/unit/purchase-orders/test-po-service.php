<?php
/**
 * M2-C: transactional PO service — persistence, events, idempotency, no-stock.
 *
 * @package WC_Inventory_Overview_Tests
 */

// phpcs:disable WordPress.Files.FileName -- PHPUnit test class naming convention.

/**
 * PO service integration tests.
 */
class Test_WC_IO_PO_Service extends WC_Inventory_Overview_Test_Case {

	/**
	 * Reset schema/sequence and clear PO + supplier rows for isolation.
	 */
	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );
	}

	/**
	 * Truncate PO aggregate tables and suppliers so numbering/sequence resets stay unique.
	 */
	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Create draft with several lines, snapshots, and one po_created event.
	 */
	public function test_create_draft_with_several_lines() {
		$supplier = $this->create_supplier(
			array(
				'name'             => 'Acme Labs',
				'default_currency' => 'EUR',
			)
		);
		$p1       = $this->create_simple_product(
			array(
				'name'      => 'Alpha',
				'stock_qty' => 10,
			)
		);
		$p1->set_sku( 'SKU-A' );
		$p1->save();
		$p2 = $this->create_simple_product(
			array(
				'name'      => 'Beta',
				'stock_qty' => 5,
			)
		);
		$p2->set_sku( 'SKU-B' );
		$p2->save();

		$po_id = WC_Inventory_Overview_PO_Service::create_draft(
			array(
				'supplier_id' => (int) $supplier['id'],
				'currency'    => 'EUR',
				'note'        => 'First draft',
			),
			array(
				array(
					'product_id'  => $p1->get_id(),
					'qty_ordered' => 3,
					'unit_cost'   => 2.5,
				),
				array(
					'product_id'   => $p2->get_id(),
					'qty_ordered'  => 4,
					'unit_cost'    => 1.25,
					'supplier_sku' => 'SUP-B',
				),
			)
		);
		$this->assertIsInt( $po_id );

		$po = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		$this->assertSame( 'draft', $po['status'] );
		$this->assertSame( 'Acme Labs', $po['supplier_name_snapshot'] );
		$this->assertMatchesRegularExpression( '/^PO-\d{4}-\d{4,}$/', $po['po_number'] );

		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_by_po( $po_id );
		$this->assertCount( 2, $lines );
		$this->assertSame( 'SKU-A', $lines[0]['sku_snapshot'] );
		$this->assertSame( 'Alpha', $lines[0]['name_snapshot'] );
		$this->assertSame( 'SKU-B', $lines[1]['sku_snapshot'] );
		$this->assertSame( 'EUR', $lines[0]['currency'] );

		$events = WC_Inventory_Overview_PO_Events::list_by_po( $po_id );
		$this->assertCount( 1, $events );
		$this->assertSame( 'po_created', $events[0]['event_type'] );
		$this->assertSame( 1, WC_Inventory_Overview_PO_Events::count_by_po( $po_id ) );
	}

	/**
	 * Failed line rolls back header, lines, and events (sequence may remain consumed).
	 */
	public function test_create_draft_rolls_back_when_line_fails() {
		$supplier     = $this->create_supplier();
		$good         = $this->create_simple_product();
		$count_before = WC_Inventory_Overview_Purchase_Orders::count();

		$result = WC_Inventory_Overview_PO_Service::create_draft(
			array(
				'supplier_id' => (int) $supplier['id'],
				'currency'    => 'EUR',
			),
			array(
				array(
					'product_id'  => $good->get_id(),
					'qty_ordered' => 1,
					'unit_cost'   => 1,
				),
				array(
					'product_id'  => 0,
					'qty_ordered' => 1,
					'unit_cost'   => 1,
				),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( $count_before, WC_Inventory_Overview_Purchase_Orders::count() );
		$this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_PO_Events::table_name() ) );
		$this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ) );
	}

	/**
	 * Update_draft emits a single deterministic event and reason code.
	 */
	public function test_update_draft_header_events_and_reason_codes() {
		$supplier = $this->create_supplier();
		$other    = $this->create_supplier( array( 'name' => 'Other Co' ) );
		$product  = $this->create_simple_product();
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array(
				'supplier_id' => (int) $supplier['id'],
				'currency'    => 'EUR',
			),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 2,
					'unit_cost'   => 1,
				),
			)
		);

		WC_Inventory_Overview_PO_Service::update_draft(
			$po_id,
			array( 'expected_date' => '2026-09-01' )
		);
		$latest = WC_Inventory_Overview_PO_Events::get_latest( $po_id );
		$this->assertSame( 'po_expected_date_changed', $latest['event_type'] );
		$this->assertSame( 'schedule_change', $latest['reason_code'] );

		WC_Inventory_Overview_PO_Service::update_draft(
			$po_id,
			array( 'expected_confidence' => 'exact' )
		);
		$latest = WC_Inventory_Overview_PO_Events::get_latest( $po_id );
		$this->assertSame( 'po_confidence_changed', $latest['event_type'] );

		WC_Inventory_Overview_PO_Service::update_draft(
			$po_id,
			array(
				'supplier_id'   => (int) $other['id'],
				'expected_date' => '2026-10-01',
			)
		);
		$latest = WC_Inventory_Overview_PO_Events::get_latest( $po_id );
		$this->assertSame( 'po_edited', $latest['event_type'] );
		$this->assertSame( 'other', $latest['reason_code'] );

		$bad = WC_Inventory_Overview_PO_Service::update_draft(
			$po_id,
			array( 'note' => 'x' ),
			array( 'reason_code' => 'not_real' )
		);
		$this->assertWPError( $bad );
		$this->assertSame( 'wc_io_po_invalid_reason_code', $bad->get_error_code() );
	}

	/**
	 * Place, cancel, close_short idempotency and terminal write rejection.
	 */
	public function test_place_cancel_close_short_idempotency_and_terminal_rejection() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product( array( 'stock_qty' => 7 ) );
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 5,
					'unit_cost'   => 3,
				),
			)
		);

		$placed = WC_Inventory_Overview_PO_Service::place( $po_id );
		$this->assertSame( 'placed', $placed['status'] );
		$this->assertNotEmpty( $placed['placed_at'] );
		$count_after_place = WC_Inventory_Overview_PO_Events::count_by_po( $po_id );

		$again = WC_Inventory_Overview_PO_Service::place( $po_id );
		$this->assertSame( 'placed', $again['status'] );
		$this->assertSame( $count_after_place, WC_Inventory_Overview_PO_Events::count_by_po( $po_id ) );

		$updated = WC_Inventory_Overview_PO_Service::update_placed(
			$po_id,
			array( 'note' => 'after place' )
		);
		$this->assertSame( 'after place', $updated['note'] );

		$line = WC_Inventory_Overview_Purchase_Order_Lines::list_by_po( $po_id )[0];
		WC_Inventory_Overview_PO_Service::update_line(
			(int) $line['id'],
			array( 'unit_cost' => 4 )
		);
		$latest = WC_Inventory_Overview_PO_Events::get_latest( $po_id );
		$this->assertSame( 'po_line_changed', $latest['event_type'] );
		$this->assertSame( 'price_change', $latest['reason_code'] );

		$closed = WC_Inventory_Overview_PO_Service::close_short( $po_id );
		$this->assertSame( 'closed_short', $closed['status'] );
		$line = WC_Inventory_Overview_Purchase_Order_Lines::get( (int) $line['id'] );
		$this->assertEquals( 5.0, (float) $line['qty_cancelled'] );
		$this->assertEquals( 5.0, (float) $line['qty_ordered'] );
		$count_after_close = WC_Inventory_Overview_PO_Events::count_by_po( $po_id );

		$closed_again = WC_Inventory_Overview_PO_Service::close_short( $po_id );
		$this->assertSame( 'closed_short', $closed_again['status'] );
		$this->assertSame( $count_after_close, WC_Inventory_Overview_PO_Events::count_by_po( $po_id ) );

		$this->assertWPError( WC_Inventory_Overview_PO_Service::update_placed( $po_id, array( 'note' => 'nope' ) ) );
		$this->assertWPError(
			WC_Inventory_Overview_PO_Service::add_line(
				$po_id,
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 1,
					'unit_cost'   => 1,
				)
			)
		);
		$this->assertWPError( WC_Inventory_Overview_PO_Service::cancel( $po_id ) );

		// Separate cancel flow + idempotency.
		$po2       = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 1,
					'unit_cost'   => 1,
				),
			)
		);
		$cancelled = WC_Inventory_Overview_PO_Service::cancel( $po2 );
		$this->assertSame( 'cancelled', $cancelled['status'] );
		$count_cancel = WC_Inventory_Overview_PO_Events::count_by_po( $po2 );
		$cancelled2   = WC_Inventory_Overview_PO_Service::cancel( $po2 );
		$this->assertSame( 'cancelled', $cancelled2['status'] );
		$this->assertSame( $count_cancel, WC_Inventory_Overview_PO_Events::count_by_po( $po2 ) );
		$this->assertWPError( WC_Inventory_Overview_PO_Service::close_short( $po2 ) );
	}

	/**
	 * Line add / update / remove / cancel and mixed reason-code policy.
	 */
	public function test_line_mutations_and_mixed_reason_policy() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product();
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] )
		);

		$line_id = WC_Inventory_Overview_PO_Service::add_line(
			$po_id,
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 10,
				'unit_cost'   => 2,
			)
		);
		$this->assertIsInt( $line_id );
		$this->assertSame( 'po_line_added', WC_Inventory_Overview_PO_Events::get_latest( $po_id )['event_type'] );

		WC_Inventory_Overview_PO_Service::update_line(
			$line_id,
			array(
				'qty_ordered' => 8,
				'unit_cost'   => 2.5,
			)
		);
		$latest = WC_Inventory_Overview_PO_Events::get_latest( $po_id );
		$this->assertSame( 'other', $latest['reason_code'], 'Mixed qty+price → other' );

		WC_Inventory_Overview_PO_Service::cancel_line( $line_id, 3 );
		$line = WC_Inventory_Overview_Purchase_Order_Lines::get( $line_id );
		$this->assertEquals( 3.0, (float) $line['qty_cancelled'] );
		$this->assertSame( 'po_line_cancelled', WC_Inventory_Overview_PO_Events::get_latest( $po_id )['event_type'] );

		$this->assertTrue( WC_Inventory_Overview_PO_Service::remove_line( $line_id ) );
		$this->assertSame( 'po_line_removed', WC_Inventory_Overview_PO_Events::get_latest( $po_id )['event_type'] );
		$this->assertCount( 0, WC_Inventory_Overview_Purchase_Order_Lines::list_by_po( $po_id ) );
	}

	/**
	 * Hooks fire only after commit; never on rollback.
	 */
	public function test_hooks_after_commit_only() {
		$supplier  = $this->create_supplier();
		$product   = $this->create_simple_product();
		$created   = 0;
		$placed    = 0;
		$cancelled = 0;
		$closed    = 0;

		add_action(
			'wc_io_purchase_order_created',
			function () use ( &$created ) {
				++$created;
			}
		);
		add_action(
			'wc_io_purchase_order_placed',
			function () use ( &$placed ) {
				++$placed;
			}
		);
		add_action(
			'wc_io_purchase_order_cancelled',
			function () use ( &$cancelled ) {
				++$cancelled;
			}
		);
		add_action(
			'wc_io_purchase_order_closed_short',
			function () use ( &$closed ) {
				++$closed;
			}
		);

		$fail = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => 0,
					'qty_ordered' => 1,
					'unit_cost'   => 1,
				),
			)
		);
		$this->assertWPError( $fail );
		$this->assertSame( 0, $created );

		$po_id = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 2,
					'unit_cost'   => 1,
				),
			)
		);
		$this->assertSame( 1, $created );

		WC_Inventory_Overview_PO_Service::place( $po_id );
		$this->assertSame( 1, $placed );
		WC_Inventory_Overview_PO_Service::place( $po_id );
		$this->assertSame( 1, $placed, 'Idempotent place must not re-fire hook' );

		$po2 = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 1,
					'unit_cost'   => 1,
				),
			)
		);
		WC_Inventory_Overview_PO_Service::cancel( $po2 );
		$this->assertSame( 1, $cancelled );

		$po3 = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 1,
					'unit_cost'   => 1,
				),
			)
		);
		WC_Inventory_Overview_PO_Service::place( $po3 );
		WC_Inventory_Overview_PO_Service::close_short( $po3 );
		$this->assertSame( 1, $closed );
	}

	/**
	 * Duplicate from each source state; source unchanged; fires created hook.
	 */
	public function test_duplicate_from_each_source_state() {
		$supplier = $this->create_supplier( array( 'name' => 'Dup Supplier' ) );
		$product  = $this->create_simple_product( array( 'name' => 'Dup Product' ) );
		$product->set_sku( 'DUP-SKU' );
		$product->save();

		$statuses = array();

		$draft_id          = WC_Inventory_Overview_PO_Service::create_draft(
			array(
				'supplier_id'        => (int) $supplier['id'],
				'supplier_reference' => 'REF-1',
				'note'               => 'notes',
				'expected_date'      => '2026-08-20',
			),
			array(
				array(
					'product_id'    => $product->get_id(),
					'qty_ordered'   => 6,
					'qty_cancelled' => 1,
					'unit_cost'     => 9.5,
					'supplier_sku'  => 'S-1',
					'note'          => 'line note',
				),
			)
		);
		$statuses['draft'] = $draft_id;

		$placed_id = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 2,
					'unit_cost'   => 1,
				),
			)
		);
		WC_Inventory_Overview_PO_Service::place( $placed_id );
		$statuses['placed'] = $placed_id;

		$cancelled_id = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 2,
					'unit_cost'   => 1,
				),
			)
		);
		WC_Inventory_Overview_PO_Service::cancel( $cancelled_id );
		$statuses['cancelled'] = $cancelled_id;

		$closed_id = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 2,
					'unit_cost'   => 1,
				),
			)
		);
		WC_Inventory_Overview_PO_Service::place( $closed_id );
		WC_Inventory_Overview_PO_Service::close_short( $closed_id );
		$statuses['closed_short'] = $closed_id;

		$created_hooks = 0;
		add_action(
			'wc_io_purchase_order_created',
			function () use ( &$created_hooks ) {
				++$created_hooks;
			}
		);

		foreach ( $statuses as $status => $source_id ) {
			$source_before = WC_Inventory_Overview_Purchase_Orders::get( $source_id );
			$source_events = WC_Inventory_Overview_PO_Events::count_by_po( $source_id );
			$source_lines  = WC_Inventory_Overview_Purchase_Order_Lines::list_by_po( $source_id );

			$new_id = WC_Inventory_Overview_PO_Service::duplicate( $source_id );
			$this->assertIsInt( $new_id, "duplicate from {$status}" );

			$new = WC_Inventory_Overview_Purchase_Orders::get( $new_id );
			$this->assertSame( 'draft', $new['status'] );
			$this->assertNotSame( $source_before['po_number'], $new['po_number'] );
			$this->assertNull( $new['placed_at'] );
			$this->assertNull( $new['closed_at'] );
			$this->assertSame( (string) $source_before['supplier_reference'], (string) $new['supplier_reference'] );
			$this->assertSame( (string) $source_before['note'], (string) $new['note'] );

			$new_lines = WC_Inventory_Overview_Purchase_Order_Lines::list_by_po( $new_id );
			$this->assertCount( count( $source_lines ), $new_lines );
			$this->assertEquals( 0.0, (float) $new_lines[0]['qty_cancelled'] );
			$this->assertEquals( (float) $source_lines[0]['qty_ordered'], (float) $new_lines[0]['qty_ordered'] );
			$this->assertSame( 'DUP-SKU', $new_lines[0]['sku_snapshot'] );

			$events = WC_Inventory_Overview_PO_Events::list_by_po( $new_id );
			$this->assertCount( 1, $events );
			$this->assertSame( 'po_duplicated', $events[0]['event_type'] );
			$data = json_decode( (string) $events[0]['data'], true );
			$this->assertSame( (int) $source_id, (int) $data['source_po_id'] );
			$this->assertSame( $source_before['po_number'], $data['source_po_number'] );

			$source_after = WC_Inventory_Overview_Purchase_Orders::get( $source_id );
			$this->assertSame( $source_before, $source_after );
			$this->assertSame( $source_events, WC_Inventory_Overview_PO_Events::count_by_po( $source_id ) );
		}

		$this->assertSame( 4, $created_hooks );
	}

	/**
	 * Duplicate rolls back when INV-8 fails after source product becomes invalid.
	 */
	public function test_duplicate_rollback_on_invalid_product() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product();
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 1,
					'unit_cost'   => 1,
				),
			)
		);

		$product->set_manage_stock( false );
		$product->save();

		$before = WC_Inventory_Overview_Purchase_Orders::count();
		$result = WC_Inventory_Overview_PO_Service::duplicate( $po_id );
		$this->assertWPError( $result );
		$this->assertSame( $before, WC_Inventory_Overview_Purchase_Orders::count() );
	}

	/**
	 * Draft delete removes aggregate events via narrow cleanup; generic delete impossible.
	 */
	public function test_delete_draft_cleans_events_without_generic_delete() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product();
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 1,
					'unit_cost'   => 1,
				),
			)
		);
		$this->assertGreaterThan( 0, WC_Inventory_Overview_PO_Events::count_by_po( $po_id ) );

		$this->assertTrue( WC_Inventory_Overview_PO_Service::delete_draft( $po_id ) );
		$this->assertWPError( WC_Inventory_Overview_Purchase_Orders::get( $po_id ) );
		$this->assertSame( 0, WC_Inventory_Overview_PO_Events::count_by_po( $po_id ) );
		$this->assertFalse( method_exists( 'WC_Inventory_Overview_PO_Events', 'delete' ) );

		$placed = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 1,
					'unit_cost'   => 1,
				),
			)
		);
		WC_Inventory_Overview_PO_Service::place( $placed );
		$this->assertWPError( WC_Inventory_Overview_PO_Service::delete_draft( $placed ) );
	}

	/**
	 * Headline safety: PO lifecycle must not mutate stock, cost meta, or movements.
	 */
	public function test_no_stock_mutation_invariant() {
		global $wpdb;

		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product( array( 'stock_qty' => 42 ) );
		$this->set_product_average_cost( $product, 3.5 );
		$this->set_product_inventory_value( $product, 147.0 );

		$movements_table  = $wpdb->prefix . 'wc_io_inventory_movements';
		$movements_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$movements_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$snap = array(
			'stock' => (float) $product->get_stock_quantity(),
			'avg'   => $this->get_product_average_cost( $product ),
			'val'   => $this->get_product_inventory_value( $product ),
			'moves' => $movements_before,
		);

		$po_id = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 10,
					'unit_cost'   => 3.5,
				),
			)
		);
		WC_Inventory_Overview_PO_Service::update_draft( $po_id, array( 'note' => 'edit' ) );
		WC_Inventory_Overview_PO_Service::place( $po_id );
		WC_Inventory_Overview_PO_Service::duplicate( $po_id );

		$po_cancel = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 1,
					'unit_cost'   => 1,
				),
			)
		);
		WC_Inventory_Overview_PO_Service::cancel( $po_cancel );

		$po_close = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 4,
					'unit_cost'   => 1,
				),
			)
		);
		WC_Inventory_Overview_PO_Service::place( $po_close );
		WC_Inventory_Overview_PO_Service::close_short( $po_close );

		$product = wc_get_product( $product->get_id() );
		$this->assertSame( $snap['stock'], (float) $product->get_stock_quantity() );
		$this->assertSame( $snap['avg'], $this->get_product_average_cost( $product ) );
		$this->assertSame( $snap['val'], $this->get_product_inventory_value( $product ) );
		$this->assertSame(
			$snap['moves'],
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$movements_table}" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * M2-C's own write surface (PO_Service's manual header/line updates) never
	 * writes qty_received or the two M5 receiving statuses — those are exclusively
	 * written by WC_Inventory_Overview_PO_Receiving_Sync (M5 plan §Receiving-status
	 * ownership). Superseded from the pre-M5 "no receiving columns exist at all"
	 * assertion (qty_received is now a real column, schema v9) to this narrower,
	 * still-true claim: PO_Service itself never produces these values.
	 *
	 * Revised again for the M5 audit remediation (WP4): close_short() now READS
	 * qty_received (already-fetched line data, not a new query) to correctly cancel
	 * only a line's unreceived remainder instead of its full ordered quantity —
	 * this is not a violation of the sole-writer invariant, which governs the
	 * physical column WRITE, not reads of an already-fetched value. The blanket
	 * substring guard is narrowed accordingly to specifically catch a WRITE attempt
	 * (a repository update() call passing 'qty_received', or raw SQL setting it),
	 * which remains, and must remain, absent from this file.
	 */
	public function test_po_service_never_writes_receiving_status_or_qty_received() {
		$src = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-po-service.php' );
		$src = preg_replace( '#/\*.*?\*/#s', '', $src );
		$src = preg_replace( '#//[^\n]*#', '', $src );

		$this->assertStringNotContainsString( "'qty_received' =>", $src, 'PO_Service must never pass qty_received as a repository update() field — only PO_Receiving_Sync writes it (reading an already-fetched line\'s qty_received, e.g. to compute qty_cancelled correctly, is legitimate).' );
		$this->assertStringNotContainsString( 'qty_received =', $src, 'PO_Service must never write qty_received via raw SQL.' );
		$this->assertStringNotContainsString( "'partially_received'", $src, 'PO_Service must never write the partially_received status — it is auto-transitioned only.' );
		$this->assertStringNotContainsString( 'PO_Statuses::RECEIVED', $src, 'PO_Service must never write the received status — it is auto-transitioned only.' );
		$this->assertStringNotContainsString( 'PO_Receiving_Sync', $src, 'PO_Service must never call PO_Receiving_Sync — Goods_Receipt_Service is its sole caller.' );
	}

	/**
	 * The M5 receiving statuses are now real, valid vocabulary (schema v9) — this
	 * replaces the pre-M5 assertion that they didn't exist.
	 */
	public function test_receiving_statuses_are_valid_vocabulary() {
		$this->assertTrue( WC_Inventory_Overview_PO_Statuses::is_valid( 'partially_received' ) );
		$this->assertTrue( WC_Inventory_Overview_PO_Statuses::is_valid( 'received' ) );
	}

	/**
	 * Event payloads are minimal JSON with before/after for edits.
	 */
	public function test_event_payloads_are_structured() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product();
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 2,
					'unit_cost'   => 1,
				),
			)
		);
		WC_Inventory_Overview_PO_Service::update_draft( $po_id, array( 'expected_date' => '2026-12-01' ) );
		$event = WC_Inventory_Overview_PO_Events::get_latest( $po_id );
		$data  = json_decode( (string) $event['data'], true );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'changed', $data );
		$this->assertArrayHasKey( 'expected_date', $data['changed'] );
		$this->assertArrayHasKey( 'from', $data['changed']['expected_date'] );
		$this->assertArrayHasKey( 'to', $data['changed']['expected_date'] );
	}
}
