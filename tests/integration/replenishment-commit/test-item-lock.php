<?php
/**
 * M25 §16/WP-M25-2: WC_Inventory_Overview_Replenishment_Item_Lock.
 *
 * Includes a genuine dual-connection empirical test (via a second, real
 * mysqli connection independent of $wpdb) proving GET_LOCK()'s blocking/
 * timeout behavior -- not merely a sequential simulation, since a single
 * connection can re-acquire its own already-held named lock without
 * blocking (session-reentrant since MySQL 5.7/MariaDB).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Commit_Item_Lock extends WC_Inventory_Overview_Test_Case {

	public function test_acquire_returns_all_ids_when_uncontended() {
		$ids = array( 500001, 500002, 500003 );

		$acquired = WC_Inventory_Overview_Replenishment_Item_Lock::acquire( $ids );

		$this->assertSame( array( 500001, 500002, 500003 ), $acquired );

		WC_Inventory_Overview_Replenishment_Item_Lock::release( $acquired );
	}

	/**
	 * INV-M25-20: locks are always acquired in ascending numeric order,
	 * regardless of the order ids were submitted in.
	 */
	public function test_acquire_sorts_ascending_regardless_of_input_order() {
		$ids = array( 500103, 500101, 500102 );

		$acquired = WC_Inventory_Overview_Replenishment_Item_Lock::acquire( $ids );

		$this->assertSame( array( 500101, 500102, 500103 ), $acquired );

		WC_Inventory_Overview_Replenishment_Item_Lock::release( $acquired );
	}

	public function test_release_is_idempotent_and_harmless_for_unheld_locks() {
		// Never acquired by this session -- must not error or warn.
		WC_Inventory_Overview_Replenishment_Item_Lock::release( array( 500999 ) );
		$this->assertTrue( true );
	}

	public function test_acquire_release_round_trip_allows_immediate_reacquisition() {
		$ids = array( 500201 );

		$first = WC_Inventory_Overview_Replenishment_Item_Lock::acquire( $ids );
		$this->assertSame( $ids, $first );

		WC_Inventory_Overview_Replenishment_Item_Lock::release( $first );

		$second = WC_Inventory_Overview_Replenishment_Item_Lock::acquire( $ids );
		$this->assertSame( $ids, $second, 'A released lock must be immediately re-acquirable.' );

		WC_Inventory_Overview_Replenishment_Item_Lock::release( $second );
	}

	/**
	 * Genuine dual-connection test: a second, independent MySQL session
	 * holds the lock; this process's own acquire() call, with a short
	 * timeout, must time out and return the item absent -- proving real
	 * GET_LOCK()/RELEASE_LOCK() blocking semantics, not merely a sequential
	 * simulation of "the lock is held."
	 */
	public function test_dual_connection_lock_contention_times_out() {
		$second = $this->open_second_db_connection();
		if ( null === $second ) {
			$this->markTestSkipped( 'Test harness does not support opening a genuine second DB connection.' );
			return;
		}

		$lock_name = 'wc_io_replen_item_500301';
		$held      = $second->query( "SELECT GET_LOCK('" . $second->real_escape_string( $lock_name ) . "', 5)" );
		$row       = $held ? $held->fetch_row() : null;
		$this->assertNotNull( $row, 'Second connection failed to acquire the lock at all -- test setup is broken.' );
		$this->assertSame( '1', (string) $row[0] );

		$start    = microtime( true );
		$acquired = WC_Inventory_Overview_Replenishment_Item_Lock::acquire( array( 500301 ), 1 );
		$elapsed  = microtime( true ) - $start;

		$this->assertSame( array(), $acquired, 'Lock held by a genuinely different connection must not be acquired.' );
		$this->assertGreaterThanOrEqual( 0.9, $elapsed, 'acquire() with timeout=1 must actually wait, not return instantly.' );

		// Release from the second connection, then confirm this process can
		// now acquire it.
		$second->query( "SELECT RELEASE_LOCK('" . $second->real_escape_string( $lock_name ) . "')" );
		$second->close();

		$reacquired = WC_Inventory_Overview_Replenishment_Item_Lock::acquire( array( 500301 ), 5 );
		$this->assertSame( array( 500301 ), $reacquired );
		WC_Inventory_Overview_Replenishment_Item_Lock::release( $reacquired );
	}

	/**
	 * Other, uncontended items in the same acquire() call are unaffected by
	 * one contended item elsewhere (item-scoped skip, §48 Amendment E is the
	 * caller's job -- but the primitive itself must still return the
	 * available subset correctly).
	 */
	public function test_one_contended_item_does_not_block_others_in_the_same_call() {
		$second = $this->open_second_db_connection();
		if ( null === $second ) {
			$this->markTestSkipped( 'Test harness does not support opening a genuine second DB connection.' );
			return;
		}

		$second->query( "SELECT GET_LOCK('wc_io_replen_item_500401', 5)" );

		$acquired = WC_Inventory_Overview_Replenishment_Item_Lock::acquire( array( 500401, 500402 ), 1 );

		$this->assertNotContains( 500401, $acquired );
		$this->assertContains( 500402, $acquired );

		WC_Inventory_Overview_Replenishment_Item_Lock::release( $acquired );
		$second->query( "SELECT RELEASE_LOCK('wc_io_replen_item_500401')" );
		$second->close();
	}
}
