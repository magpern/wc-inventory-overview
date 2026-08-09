<?php
/**
 * Unit tests for Milestone M12 — Supplier List Performance Surface.
 *
 * Column registration, non-sortability, and presentation formatting against
 * injected Supplier_Lead_Time_Service stats shapes (no second business-rule
 * implementation — only the same threshold helpers the production UI uses).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Suppliers_List_Performance extends WP_UnitTestCase {

	/**
	 * @return WC_Inventory_Overview_Suppliers_List_Table
	 */
	private function table(): WC_Inventory_Overview_Suppliers_List_Table {
		return new WC_Inventory_Overview_Suppliers_List_Table();
	}

	/**
	 * @param WC_Inventory_Overview_Suppliers_List_Table $table Table.
	 * @param array                                      $stats Stats keyed by supplier ID.
	 */
	private function inject_stats( WC_Inventory_Overview_Suppliers_List_Table $table, array $stats ): void {
		$prop = new ReflectionProperty( WC_Inventory_Overview_Suppliers_List_Table::class, 'performance_stats' );
		$prop->setAccessible( true );
		$prop->setValue( $table, $stats );
	}

	public function test_columns_registered_in_approved_order() {
		$columns = array_keys( $this->table()->get_columns() );
		$this->assertSame(
			array(
				'name',
				'default_currency',
				'default_lead_time',
				'observed_lead_time',
				'on_time_rate',
			),
			$columns
		);
	}

	public function test_performance_columns_are_not_sortable() {
		$sortable = array_keys( $this->table()->get_sortable_columns() );
		$this->assertContains( 'name', $sortable );
		$this->assertNotContains( 'observed_lead_time', $sortable );
		$this->assertNotContains( 'on_time_rate', $sortable );
		$this->assertNotContains( 'default_lead_time', $sortable );
	}

	public function test_configured_lead_time_column_unchanged() {
		$table = $this->table();
		$this->assertSame( '—', $table->column_default_lead_time( array( 'default_lead_time_days' => null ) ) );
		$this->assertSame( '12 days', $table->column_default_lead_time( array( 'default_lead_time_days' => 12 ) ) );
	}

	public function test_observed_column_em_dash_when_no_data() {
		$table = $this->table();
		$this->inject_stats(
			$table,
			array(
				1 => array(
					'has_data'          => false,
					'average_days'      => null,
					'fastest_days'      => null,
					'slowest_days'      => null,
					'sample_count'      => 0,
					'on_time_count'     => 0,
					'rated_order_count' => 0,
				),
			)
		);
		$html = $table->column_observed_lead_time( array( 'id' => 1 ) );
		$this->assertStringContainsString( '—', $html );
		$this->assertFalse( WC_Inventory_Overview_Supplier_Lead_Time_Service::is_observed_value_usable( array(
			'has_data' => false, 'average_days' => null, 'fastest_days' => null, 'slowest_days' => null,
			'sample_count' => 0, 'on_time_count' => 0, 'rated_order_count' => 0,
		) ) );
	}

	public function test_observed_column_em_dash_below_minimum_sample() {
		$table = $this->table();
		$stats = array(
			'has_data'          => true,
			'average_days'      => 10.0,
			'fastest_days'      => 10,
			'slowest_days'      => 10,
			'sample_count'      => 1,
			'on_time_count'     => 0,
			'rated_order_count' => 0,
		);
		$this->assertFalse( WC_Inventory_Overview_Supplier_Lead_Time_Service::is_observed_value_usable( $stats ) );
		$this->inject_stats( $table, array( 2 => $stats ) );
		$this->assertStringContainsString( '—', $table->column_observed_lead_time( array( 'id' => 2 ) ) );
	}

	public function test_observed_column_shows_rounded_average_at_threshold() {
		$table = $this->table();
		$stats = array(
			'has_data'          => true,
			'average_days'      => 10.6,
			'fastest_days'      => 8,
			'slowest_days'      => 12,
			'sample_count'      => WC_Inventory_Overview_Supplier_Lead_Time_Service::MINIMUM_SAMPLE_COUNT_FOR_DISPLAY,
			'on_time_count'     => 0,
			'rated_order_count' => 0,
		);
		$this->assertTrue( WC_Inventory_Overview_Supplier_Lead_Time_Service::is_observed_value_usable( $stats ) );
		$this->inject_stats( $table, array( 3 => $stats ) );
		$html = $table->column_observed_lead_time( array( 'id' => 3 ) );
		$this->assertStringContainsString( '11 days', $html );
		$this->assertStringNotContainsString( '—', wp_strip_all_tags( $html ) );
	}

	public function test_on_time_column_em_dash_when_unusable() {
		$table = $this->table();
		$stats = array(
			'has_data'          => true,
			'average_days'      => 10.0,
			'fastest_days'      => 10,
			'slowest_days'      => 10,
			'sample_count'      => 5,
			'on_time_count'     => 1,
			'rated_order_count' => 1,
		);
		$this->assertFalse( WC_Inventory_Overview_Supplier_Lead_Time_Service::is_on_time_rate_usable( $stats ) );
		$this->inject_stats( $table, array( 4 => $stats ) );
		$this->assertStringContainsString( '—', $table->column_on_time_rate( array( 'id' => 4 ) ) );
	}

	public function test_on_time_column_shows_rounded_percentage_when_usable() {
		$table = $this->table();
		$stats = array(
			'has_data'          => true,
			'average_days'      => 10.0,
			'fastest_days'      => 10,
			'slowest_days'      => 10,
			'sample_count'      => 6,
			'on_time_count'     => 5,
			'rated_order_count' => 6,
		);
		$this->assertTrue( WC_Inventory_Overview_Supplier_Lead_Time_Service::is_on_time_rate_usable( $stats ) );
		$this->inject_stats( $table, array( 5 => $stats ) );
		$html = $table->column_on_time_rate( array( 'id' => 5 ) );
		$this->assertStringContainsString( '83%', $html );
	}
}
