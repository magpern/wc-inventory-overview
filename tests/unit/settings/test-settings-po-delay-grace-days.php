<?php
/**
 * Unit tests for Milestone M16 — PO delay grace-days Settings field.
 *
 * Covers BR-M16-4's exact validate-or-preserve contract on
 * WC_Inventory_Overview_Settings::save_from_post(): missing/non-numeric/
 * non-clean-integer/out-of-range submissions must all leave the previously
 * stored WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS option
 * completely untouched (never absint()-style coerced into range), while a
 * clean integer 0-365 (including the boundary values and 0 itself) saves
 * exactly as submitted.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Settings_PO_Delay_Grace_Days extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS );
	}

	public function tearDown(): void {
		delete_option( WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS );
		parent::tearDown();
	}

	/**
	 * Fresh-install default is 0, matching PO_Delay::grace_days_from_option()'s
	 * own documented default -- unchanged by this milestone.
	 */
	public function test_default_is_zero_on_a_fresh_install() {
		$this->assertSame( 0, WC_Inventory_Overview_Settings::get_po_delay_grace_days() );
	}

	/**
	 * A valid mid-range integer saves and is immediately visible through
	 * both the new getter and the pre-existing PO_Delay reader (same
	 * option, per BR-M16-5 -- no duplicate option/constant).
	 */
	public function test_valid_integer_saves_and_is_visible_immediately() {
		$result = WC_Inventory_Overview_Settings::save_from_post( array( 'wc_io_po_delay_grace_days' => '15' ) );
		$this->assertTrue( true === $result );

		$this->assertSame( 15, WC_Inventory_Overview_Settings::get_po_delay_grace_days() );
		$this->assertSame( 15, WC_Inventory_Overview_PO_Delay::grace_days_from_option() );
	}

	/**
	 * 0 is a valid, saveable value -- never treated as empty/falsy and
	 * skipped (BR-M16-4).
	 */
	public function test_zero_is_valid_and_saves() {
		update_option( WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS, 9 );

		WC_Inventory_Overview_Settings::save_from_post( array( 'wc_io_po_delay_grace_days' => '0' ) );

		$this->assertSame( 0, WC_Inventory_Overview_Settings::get_po_delay_grace_days() );
	}

	/**
	 * The upper boundary (365) is valid and saves.
	 */
	public function test_upper_boundary_365_saves() {
		WC_Inventory_Overview_Settings::save_from_post( array( 'wc_io_po_delay_grace_days' => '365' ) );

		$this->assertSame( 365, WC_Inventory_Overview_Settings::get_po_delay_grace_days() );
	}

	/**
	 * A missing field must preserve the previously stored value -- no
	 * update_option() call for this field at all.
	 */
	public function test_missing_field_preserves_previous_value() {
		update_option( WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS, 42 );

		WC_Inventory_Overview_Settings::save_from_post( array() );

		$this->assertSame( 42, WC_Inventory_Overview_Settings::get_po_delay_grace_days() );
	}

	/**
	 * Negative input must preserve the previous value and must NEVER be
	 * coerced into range (e.g. -5 must never become 5 or 0) -- the
	 * defining behavior this milestone deliberately deviates from
	 * absint()-style handling for.
	 */
	public function test_negative_value_preserves_previous_value_never_coerced() {
		update_option( WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS, 7 );

		WC_Inventory_Overview_Settings::save_from_post( array( 'wc_io_po_delay_grace_days' => '-5' ) );

		$this->assertSame( 7, WC_Inventory_Overview_Settings::get_po_delay_grace_days(), '-5 must preserve the prior value, never become 5 or 0.' );
	}

	/**
	 * A value above the 365 ceiling must preserve the previous value.
	 */
	public function test_value_above_365_preserves_previous_value() {
		update_option( WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS, 30 );

		WC_Inventory_Overview_Settings::save_from_post( array( 'wc_io_po_delay_grace_days' => '366' ) );

		$this->assertSame( 30, WC_Inventory_Overview_Settings::get_po_delay_grace_days() );
	}

	/**
	 * A non-numeric string must preserve the previous value.
	 */
	public function test_non_numeric_value_preserves_previous_value() {
		update_option( WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS, 5 );

		WC_Inventory_Overview_Settings::save_from_post( array( 'wc_io_po_delay_grace_days' => 'abc' ) );

		$this->assertSame( 5, WC_Inventory_Overview_Settings::get_po_delay_grace_days() );
	}

	/**
	 * A decimal (non-clean-integer) numeric value must preserve the
	 * previous value -- is_numeric('1.5') is true, so the clean-integer
	 * round-trip check is what must reject it.
	 */
	public function test_decimal_value_preserves_previous_value() {
		update_option( WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS, 5 );

		WC_Inventory_Overview_Settings::save_from_post( array( 'wc_io_po_delay_grace_days' => '1.5' ) );

		$this->assertSame( 5, WC_Inventory_Overview_Settings::get_po_delay_grace_days() );
	}

	/**
	 * Scientific-notation numeric strings (is_numeric() considers them
	 * valid) must also be rejected by the clean-integer round-trip check.
	 */
	public function test_scientific_notation_value_preserves_previous_value() {
		update_option( WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS, 5 );

		WC_Inventory_Overview_Settings::save_from_post( array( 'wc_io_po_delay_grace_days' => '1e2' ) );

		$this->assertSame( 5, WC_Inventory_Overview_Settings::get_po_delay_grace_days() );
	}

	/**
	 * A numeric-with-trailing-garbage string ('12abc' is not is_numeric())
	 * must preserve the previous value.
	 */
	public function test_numeric_with_trailing_characters_preserves_previous_value() {
		update_option( WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS, 5 );

		WC_Inventory_Overview_Settings::save_from_post( array( 'wc_io_po_delay_grace_days' => '12abc' ) );

		$this->assertSame( 5, WC_Inventory_Overview_Settings::get_po_delay_grace_days() );
	}

	/**
	 * save_from_post() must not introduce a duplicate option -- the M16
	 * getter and the pre-existing PO_Delay reader must always agree,
	 * proving BR-M16-5 (single option, no duplicate constant).
	 */
	public function test_getter_and_po_delay_reader_always_agree() {
		WC_Inventory_Overview_Settings::save_from_post( array( 'wc_io_po_delay_grace_days' => '21' ) );

		$this->assertSame(
			WC_Inventory_Overview_PO_Delay::grace_days_from_option(),
			WC_Inventory_Overview_Settings::get_po_delay_grace_days()
		);
	}

	// -----------------------------------------------------------------
	// Architecture guard (INV-M16-2)
	// -----------------------------------------------------------------

	/**
	 * Sole-mutator rule: only WC_Inventory_Overview_Settings may write
	 * WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS. No other includes/
	 * file may call update_option() for it -- mirrors the sole-mutator
	 * discipline already mechanically enforced for every other domain in
	 * this codebase (INV-M16-2).
	 */
	public function test_only_settings_writes_the_grace_days_option() {
		$includes_dir = WC_INVENTORY_OVERVIEW_PATH . 'includes/';
		$needle       = 'update_option( WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS';
		$offenders    = array();

		foreach ( glob( $includes_dir . '*.php' ) as $file ) {
			$basename = basename( $file );
			if ( 'class-wc-inventory-overview-settings.php' === $basename ) {
				continue; // The one approved mutator.
			}

			$src = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $file ) );
			$src = (string) preg_replace( '#//[^\n]*#', '', $src );

			if ( false !== strpos( $src, $needle ) ) {
				$offenders[] = $basename;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'Only WC_Inventory_Overview_Settings may write WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS (INV-M16-2 sole-mutator rule).'
		);
	}
}
