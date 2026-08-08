<?php
/**
 * Characterization tests for FX (exchange rate) resolution
 *
 * Golden test suite for Exchange_Rates::get_exchange_rate_to_eur.
 * These tests lock the FX rate lookup behavior before any future changes.
 *
 * @package WC_Inventory_Overview_Tests
 * @group characterization
 * @group exchange-rates
 */

class Test_FX_Characterization extends WC_Inventory_Overview_Test_Case {

	/**
	 * Scenario: EUR→EUR passthrough always returns 1.0
	 *
	 * @test
	 */
	public function test_eur_to_eur_passthrough(): void {
		$fixture = WC_Inventory_Overview_Fixtures::load( 'exchange-rates/fixture-eur-passthrough.php' );

		if ( ! class_exists( 'WC_Inventory_Overview_Exchange_Rates' ) ) {
			$this->markTestSkipped( 'Exchange_Rates class not found' );
		}

		$result = WC_Inventory_Overview_Exchange_Rates::get_exchange_rate_to_eur(
			$fixture['operation']['from_currency'],
			$fixture['operation']['rate_date']
		);

		// get_exchange_rate_to_eur() returns {rate, source, rate_date}, not a bare float
		// (production contract, includes/class-wc-inventory-overview-exchange-rates.php).
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame(
			$fixture['expected']['rate'],
			$result['rate'],
			"EUR→EUR must always return 1.0"
		);
	}

	/**
	 * Scenario: Latest rate before requested date is used (no interpolation)
	 *
	 * @test
	 */
	public function test_latest_before_date_no_interpolation(): void {
		$fixture = WC_Inventory_Overview_Fixtures::load( 'exchange-rates/fixture-latest-before-date.php' );

		if ( ! class_exists( 'WC_Inventory_Overview_Exchange_Rates' ) ) {
			$this->markTestSkipped( 'Exchange_Rates class not found' );
		}

		// Seed the rate into the database (if the test table exists).
		global $wpdb;
		$table = $wpdb->prefix . 'wc_io_exchange_rates';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			$this->markTestSkipped( 'Exchange rates table not found (may not exist until later milestones)' );
		}

		// Insert seed rate. Column is "exchange_rate" (see expected_schema_v5()
		// in class-wc-inventory-overview-install.php) -- the fixture's own key
		// is "rate_value", a fixture-authoring name mismatch, not a schema fact.
		foreach ( $fixture['setup']['seeded_rates'] as $seed ) {
			$wpdb->insert(
				$table,
				array(
					'from_currency' => $seed['from_currency'],
					'to_currency'   => $seed['to_currency'],
					'rate_date'     => $seed['rate_date'],
					'exchange_rate' => $seed['rate_value'],
					'created_at'    => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%f', '%s' )
			);
		}

		// Request rate after seeded date.
		$result = WC_Inventory_Overview_Exchange_Rates::get_exchange_rate_to_eur(
			$fixture['operation']['from_currency'],
			$fixture['operation']['rate_date']
		);

		// get_exchange_rate_to_eur() returns {rate, source, rate_date}, not a bare float.
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		// Should get fallback rate (the one before requested date).
		$this->assertSame(
			$fixture['expected']['rate'],
			$result['rate'],
			"Should use latest rate before requested date (no interpolation)"
		);
	}
}
