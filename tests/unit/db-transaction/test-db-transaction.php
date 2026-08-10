<?php
/**
 * Unit tests for WC_Inventory_Overview_DB_Transaction
 *
 * Tests the database transaction helper in isolation against a scratch table.
 * Verifies commit, rollback, savepoint, and exception-driven rollback behavior
 * against the project's pinned MariaDB version.
 *
 * @package WC_Inventory_Overview_Tests
 */

/**
 * Test_DB_Transaction
 *
 * @group db-transaction
 */
class Test_DB_Transaction extends PHPUnit_Framework_TestCase {

	/**
	 * The transaction helper instance.
	 *
	 * @var WC_Inventory_Overview_DB_Transaction
	 */
	private $txn;

	/**
	 * The WordPress database object.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * The name of the scratch table used for testing.
	 *
	 * @var string
	 */
	private $scratch_table = 'wp_test_txn_scratch';

	/**
	 * Set up before each test.
	 *
	 * Creates a fresh scratch TEMPORARY table. TEMPORARY tables live for the
	 * whole MySQL connection (one PHPUnit process), so each method must drop
	 * any leftover table before CREATE — otherwise WordPress prints a
	 * "table already exists" HTML error and PHPUnit marks the test risky
	 * under beStrictAboutOutputDuringTests / failOnRisky.
	 */
	public function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->wpdb = $wpdb;

		$this->drop_scratch_table();

		$wpdb->query(
			"CREATE TEMPORARY TABLE {$this->scratch_table} (
				id BIGINT AUTO_INCREMENT PRIMARY KEY,
				value VARCHAR(100),
				created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
			)"
		);

		$this->txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );
	}

	/**
	 * Tear down after each test.
	 *
	 * Force-close any dangling transaction and drop the scratch table so the
	 * next method (or a repeated suite run on the same connection) starts clean.
	 */
	public function tearDown(): void {
		if ( isset( $this->txn ) ) {
			while ( $this->txn->is_active() ) {
				$this->txn->rollback();
			}
		}

		$this->drop_scratch_table();

		parent::tearDown();
	}

	/**
	 * Drop the scratch TEMPORARY table if it exists.
	 */
	private function drop_scratch_table(): void {
		if ( ! isset( $this->wpdb ) ) {
			return;
		}

		// DROP TEMPORARY TABLE IF EXISTS is silent when the table is absent.
		$this->wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$this->scratch_table}" );
	}

	/**
	 * Test: begin() and commit() persist data.
	 *
	 * @test
	 */
	public function test_begin_commit_persists_data(): void {
		$this->txn->begin();

		$this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO {$this->scratch_table} (value) VALUES (%s)",
				'test_value_1'
			)
		);

		$this->assertTrue( $this->txn->commit(), 'Commit should succeed' );
		$this->assertFalse( $this->txn->is_active(), 'Transaction should not be active after commit' );

		// Verify data persists after commit.
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->scratch_table} WHERE value = %s",
				'test_value_1'
			)
		);

		$this->assertNotNull( $row, 'Data should persist after commit' );
		$this->assertSame( 'test_value_1', $row->value );
	}

	/**
	 * Test: begin() and rollback() undo all changes.
	 *
	 * @test
	 */
	public function test_begin_rollback_undoes_changes(): void {
		$this->txn->begin();

		$this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO {$this->scratch_table} (value) VALUES (%s)",
				'test_value_2'
			)
		);

		$this->assertTrue( $this->txn->rollback(), 'Rollback should succeed' );
		$this->assertFalse( $this->txn->is_active(), 'Transaction should not be active after rollback' );

		// Verify data is not persisted after rollback.
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->scratch_table} WHERE value = %s",
				'test_value_2'
			)
		);

		$this->assertNull( $row, 'Data should not persist after rollback' );
	}

	/**
	 * Test: nested transactions use savepoints correctly.
	 *
	 * @test
	 */
	public function test_nested_transaction_savepoint(): void {
		// Outer transaction.
		$this->txn->begin();

		$this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO {$this->scratch_table} (value) VALUES (%s)",
				'outer_value'
			)
		);

		// Inner transaction (savepoint).
		$this->txn->begin();

		$this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO {$this->scratch_table} (value) VALUES (%s)",
				'inner_value'
			)
		);

		$this->txn->commit(); // Commits the inner savepoint.
		$this->txn->commit(); // Commits the outer transaction.

		$this->assertFalse( $this->txn->is_active(), 'Transaction should not be active after nested commits' );

		// Both inserts should persist.
		$outer = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->scratch_table} WHERE value = %s",
				'outer_value'
			)
		);
		$inner = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->scratch_table} WHERE value = %s",
				'inner_value'
			)
		);

		$this->assertNotNull( $outer, 'Outer insert should persist' );
		$this->assertNotNull( $inner, 'Inner insert should persist' );
	}

	/**
	 * Test: inner savepoint rollback does not undo outer transaction.
	 *
	 * @test
	 */
	public function test_nested_rollback_does_not_undo_outer(): void {
		// Outer transaction.
		$this->txn->begin();

		$this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO {$this->scratch_table} (value) VALUES (%s)",
				'outer_persists'
			)
		);

		// Inner transaction (savepoint).
		$this->txn->begin();

		$this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO {$this->scratch_table} (value) VALUES (%s)",
				'inner_rolled_back'
			)
		);

		$this->txn->rollback(); // Rollback only the inner savepoint.
		$this->txn->commit();   // Commit outer.

		$this->assertFalse( $this->txn->is_active(), 'Transaction should not be active after outer commit' );

		// Outer insert should persist; inner should be gone.
		$outer = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->scratch_table} WHERE value = %s",
				'outer_persists'
			)
		);
		$inner = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->scratch_table} WHERE value = %s",
				'inner_rolled_back'
			)
		);

		$this->assertNotNull( $outer, 'Outer insert should persist' );
		$this->assertNull( $inner, 'Inner insert should be rolled back' );
	}

	/**
	 * Test: run() executes work and commits on success.
	 *
	 * @test
	 */
	public function test_run_commits_on_success(): void {
		$result = $this->txn->run(
			function () {
				$this->wpdb->query(
					$this->wpdb->prepare(
						"INSERT INTO {$this->scratch_table} (value) VALUES (%s)",
						'run_success'
					)
				);
				return 'success';
			}
		);

		$this->assertSame( 'success', $result, 'run() should return the callable result' );
		$this->assertFalse( $this->txn->is_active(), 'Transaction should not be active after run()' );

		// Data should persist.
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->scratch_table} WHERE value = %s",
				'run_success'
			)
		);
		$this->assertNotNull( $row, 'Data should persist after successful run()' );
	}

	/**
	 * Test: run() rolls back and rethrows on exception.
	 *
	 * @test
	 */
	public function test_run_rollbacks_on_exception(): void {
		try {
			$this->txn->run(
				function () {
					$this->wpdb->query(
						$this->wpdb->prepare(
							"INSERT INTO {$this->scratch_table} (value) VALUES (%s)",
							'run_rollback'
						)
					);
					throw new RuntimeException( 'Simulated error' );
				}
			);
			$this->fail( 'Expected exception to be rethrown' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Simulated error', $e->getMessage() );
		}

		$this->assertFalse( $this->txn->is_active(), 'Transaction should be rolled back' );

		// Data should not persist.
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->scratch_table} WHERE value = %s",
				'run_rollback'
			)
		);
		$this->assertNull( $row, 'Data should not persist after rollback on exception' );
	}

	/**
	 * Test: run() with mid-transaction error rolls back all preceding work.
	 *
	 * Simulates a failure partway through a multi-step operation.
	 *
	 * @test
	 */
	public function test_run_partial_failure_rolls_back_all(): void {
		try {
			$this->txn->run(
				function () {
					// Step 1: Insert data.
					$this->wpdb->query(
						$this->wpdb->prepare(
							"INSERT INTO {$this->scratch_table} (value) VALUES (%s)",
							'step_1'
						)
					);

					// Step 2: Insert more data.
					$this->wpdb->query(
						$this->wpdb->prepare(
							"INSERT INTO {$this->scratch_table} (value) VALUES (%s)",
							'step_2'
						)
					);

					// Step 3: Simulate a failure (e.g., stock mutation error).
					throw new RuntimeException( 'Stock mutation failed' );
				}
			);
			$this->fail( 'Expected exception to be rethrown' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Stock mutation failed', $e->getMessage() );
		}

		$this->assertFalse( $this->txn->is_active(), 'Transaction should be rolled back' );

		// Verify both inserts are rolled back (transaction atomicity).
		$count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->scratch_table} WHERE value IN (%s, %s)",
				'step_1',
				'step_2'
			)
		);

		$this->assertSame( 0, (int) $count, 'All inserts should be rolled back after mid-transaction failure' );
	}

	/**
	 * Test: is_active() reports transaction state correctly.
	 *
	 * @test
	 */
	public function test_is_active_reports_state(): void {
		$this->assertFalse( $this->txn->is_active(), 'Transaction should not be active initially' );

		$this->txn->begin();
		$this->assertTrue( $this->txn->is_active(), 'Transaction should be active after begin()' );

		$this->txn->commit();
		$this->assertFalse( $this->txn->is_active(), 'Transaction should not be active after commit()' );
	}
}
