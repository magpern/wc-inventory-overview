<?php
/**
 * Integration tests for the Expected Delivery storefront setting (M7).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Expected_Delivery_Settings extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( WC_Inventory_Overview_Settings::OPTION_EXPECTED_DELIVERY_RENDERER_ENABLED );
	}

	public function tearDown(): void {
		delete_option( WC_Inventory_Overview_Settings::OPTION_EXPECTED_DELIVERY_RENDERER_ENABLED );
		parent::tearDown();
	}

	/**
	 * A feature that ships off by default ships untested in production.
	 */
	public function test_default_is_yes_on_a_fresh_install() {
		$this->assertTrue( WC_Inventory_Overview_Settings::expected_delivery_renderer_enabled() );
	}

	/**
	 * The radio persists both ways through save_from_post().
	 */
	public function test_radio_persists_both_ways_through_save_from_post() {
		$result = WC_Inventory_Overview_Settings::save_from_post( array( 'wc_io_expected_delivery_renderer_enabled' => 'no' ) );
		$this->assertTrue( true === $result );
		$this->assertFalse( WC_Inventory_Overview_Settings::expected_delivery_renderer_enabled() );

		$result = WC_Inventory_Overview_Settings::save_from_post( array( 'wc_io_expected_delivery_renderer_enabled' => 'yes' ) );
		$this->assertTrue( true === $result );
		$this->assertTrue( WC_Inventory_Overview_Settings::expected_delivery_renderer_enabled() );
	}

	/**
	 * An absent POST key normalizes to 'no' (house pattern: unchecked radio
	 * groups are not submitted at all by browsers).
	 */
	public function test_absent_post_key_normalizes_to_no() {
		WC_Inventory_Overview_Settings::save_from_post( array() );

		$this->assertFalse( WC_Inventory_Overview_Settings::expected_delivery_renderer_enabled() );
	}
}
