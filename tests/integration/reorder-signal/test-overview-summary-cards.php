<?php
/**
 * Integration tests for M21 Overview summary-card Reorder Signal surface
 * (WP-M21-5, BR-M21-6): "Needs Reorder" card, capability-gated
 * (INV-M21-5), additive to the six pre-existing cards (INV-M21-6).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Overview_Summary_Cards_Reorder extends WP_UnitTestCase {

	/**
	 * @return array<string, int>
	 */
	private function sample_stats(): array {
		return array(
			'total'         => 10,
			'in_stock'      => 8,
			'out_of_stock'  => 1,
			'on_backorder'  => 1,
			'low_stock'     => 3,
			'needs_reorder' => 2,
			'draft'         => 0,
			'hidden'        => 0,
		);
	}

	/**
	 * manage_woocommerce viewers see the "Needs Reorder" card, alongside the
	 * six pre-existing cards, unchanged.
	 */
	public function test_manage_woocommerce_viewer_sees_needs_reorder_card() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		ob_start();
		WC_Inventory_Overview_Overview_Controller::render_summary_cards( $this->sample_stats() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-wc-io-metric="needs_reorder"', $html );
		$this->assertStringContainsString( 'data-wc-io-metric="low_stock"', $html, 'Pre-existing Low stock card must remain.' );
		$this->assertStringContainsString( 'data-wc-io-metric="total"', $html );
		$this->assertStringContainsString( 'data-wc-io-metric="draft"', $html );
		$this->assertStringContainsString( 'data-wc-io-metric="hidden"', $html );
	}

	/**
	 * edit_products-only viewers see exactly today's unchanged six cards --
	 * no "Needs Reorder" card (BR-M21-6, INV-M21-5).
	 */
	public function test_edit_products_only_viewer_does_not_see_needs_reorder_card() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user_id );

		ob_start();
		WC_Inventory_Overview_Overview_Controller::render_summary_cards( $this->sample_stats() );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'data-wc-io-metric="needs_reorder"', $html );
		$this->assertStringContainsString( 'data-wc-io-metric="low_stock"', $html, 'Pre-existing Low stock card must remain.' );
		$this->assertStringContainsString( 'data-wc-io-metric="total"', $html );
	}

	/**
	 * Zero needs_reorder does not mark the card as an alert.
	 */
	public function test_zero_needs_reorder_not_alert() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$stats                   = $this->sample_stats();
		$stats['needs_reorder']  = 0;

		ob_start();
		WC_Inventory_Overview_Overview_Controller::render_summary_cards( $stats );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/wc-io-summary-card"[^>]*data-wc-io-metric="needs_reorder"/',
			$html,
			'Zero needs_reorder must not carry the is-alert class.'
		);
	}
}
