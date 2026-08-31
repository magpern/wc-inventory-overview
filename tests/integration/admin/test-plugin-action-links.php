<?php
/**
 * Integration tests for Plugins screen action links.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Plugin_Action_Links extends WP_UnitTestCase {

	public function test_links_for_shop_manager() {
		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$links = WC_Inventory_Overview_Plugin_Action_Links::links(
			array( 'deactivate' => '<a href="#">Deactivate</a>' )
		);

		$this->assertArrayHasKey( 'overview', $links );
		$this->assertArrayHasKey( 'settings', $links );
		$this->assertArrayHasKey( 'deactivate', $links );
		$this->assertStringContainsString( 'Overview', $links['overview'] );
		$this->assertStringContainsString( 'Settings', $links['settings'] );
		$this->assertStringContainsString(
			'page=' . WC_Inventory_Overview_Plugin::PAGE_SLUG,
			$links['overview']
		);
		$this->assertStringContainsString(
			'tab=' . WC_Inventory_Overview_Plugin::TAB_OVERVIEW,
			$links['overview']
		);
		$this->assertStringContainsString(
			'tab=' . WC_Inventory_Overview_Plugin::TAB_SETTINGS,
			$links['settings']
		);
	}

	public function test_links_hidden_without_product_edit_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$original = array( 'deactivate' => '<a href="#">Deactivate</a>' );
		$links    = WC_Inventory_Overview_Plugin_Action_Links::links( $original );

		$this->assertSame( $original, $links );
	}
}
