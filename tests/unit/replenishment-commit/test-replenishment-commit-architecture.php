<?php
/**
 * M25 WP-M25-7: architecture guards for the bulk-commit orchestrator.
 *
 * Source-scanning guards, mirroring the established pattern used by
 * Test_WC_IO_Replenishment_Planning_Architecture (M24) and every prior
 * milestone's own architecture-guard suite.
 *
 * Byte-identity of the M24-frozen files (Replenishment_Planning_Service,
 * Supplier_Preference_Resolver, PO_Service, Purchase_Orders, PO_Events,
 * PO_Product_Validator, DB_Transaction) against the M24 tip
 * (3c21a69e6402d631575ded4653435dbaa6dbe435) is a `git diff` check --
 * deliberately NOT automated here, since the PHPUnit docker container
 * (wordpress:cli-2.12.0-php8.4) has no `git` binary and this repository is
 * not itself a git worktree the container has access to as one. That
 * invariant is verified directly on the host as part of this milestone's
 * own validation pass (recorded in the release-readiness checklist), the
 * same way it would be verified for any milestone's frozen-file guard.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Commit_Architecture extends WP_UnitTestCase {

	private function includes_dir(): string {
		return WC_INVENTORY_OVERVIEW_PATH . 'includes/';
	}

	private function strip_comments( string $src ): string {
		$src = (string) preg_replace( '#/\*.*?\*/#s', '', $src );
		$src = (string) preg_replace( '#//[^\n]*#', '', $src );
		return $src;
	}

	private function read( string $basename ): string {
		return (string) file_get_contents( $this->includes_dir() . $basename );
	}

	// -----------------------------------------------------------------
	// Pure-orchestrator contract: no $wpdb, no PO_Product_Validator, no
	// Replenishment_Defaults writes, no duplicated domain logic.
	// -----------------------------------------------------------------

	public function test_commit_service_never_touches_wpdb_directly() {
		$src = $this->strip_comments( $this->read( 'class-wc-inventory-overview-replenishment-commit-service.php' ) );

		$this->assertStringNotContainsString( 'global $wpdb', $src, 'Replenishment_Commit_Service must never touch $wpdb directly.' );
		$this->assertStringNotContainsString( '$wpdb->', $src, 'Replenishment_Commit_Service must never touch $wpdb directly.' );
	}

	public function test_commit_service_never_calls_po_product_validator() {
		$src = $this->strip_comments( $this->read( 'class-wc-inventory-overview-replenishment-commit-service.php' ) );

		$this->assertStringNotContainsString( 'PO_Product_Validator', $src );
	}

	public function test_commit_service_never_writes_replenishment_defaults() {
		$src = $this->strip_comments( $this->read( 'class-wc-inventory-overview-replenishment-commit-service.php' ) );

		$this->assertStringNotContainsString( 'Replenishment_Defaults::save_', $src );
	}

	public function test_commit_service_calls_build_plan_at_most_once_textually() {
		$src = $this->strip_comments( $this->read( 'class-wc-inventory-overview-replenishment-commit-service.php' ) );

		$count = substr_count( $src, 'Replenishment_Planning_Service::build_plan(' );
		$this->assertSame( 1, $count, 'Exactly one build_plan() call site (INV-M25-13) -- never per-line, never per-group.' );
	}

	public function test_commit_service_releases_locks_in_finally() {
		$src = $this->read( 'class-wc-inventory-overview-replenishment-commit-service.php' );

		$pos = strpos( $src, 'try {' );
		$this->assertNotFalse( $pos, 'commit() must wrap its resolution/creation steps in try/finally.' );
		$finally_pos = strpos( $src, 'finally {', $pos );
		$this->assertNotFalse( $finally_pos, 'commit() must have a finally block.' );
		$release_pos = strpos( $src, 'Replenishment_Item_Lock::release(', $finally_pos );
		$this->assertNotFalse( $release_pos, 'Lock release must occur inside the finally block (INV-M25-21).' );
	}

	// -----------------------------------------------------------------
	// No new capability, no new public hook, no schema/DB_VERSION change --
	// all self-contained (no git dependency).
	// -----------------------------------------------------------------

	/**
	 * Whitelist-based, git-free equivalent of "Purchasing_Caps unchanged":
	 * the exact set of capability action-key constants must match the M24
	 * set precisely -- neither a new constant added nor an existing one
	 * removed/renamed.
	 */
	public function test_purchasing_caps_constant_set_unchanged() {
		$src = $this->read( 'class-wc-inventory-overview-purchasing-caps.php' );

		preg_match_all( '/const\s+([A-Z_]+)\s*=/', $src, $matches );
		$actual = $matches[1];
		sort( $actual );

		$expected = array(
			'CANCEL_PO',
			'CLOSE_PO',
			'DELETE_PO',
			'DELETE_RECEIPT',
			'DUPLICATE_PO',
			'EDIT_PO',
			'EDIT_RECEIPT',
			'MANAGE_SUPPLIERS',
			'MERGE_SUPPLIER',
			'PLACE_PO',
			'POST_RECEIPT',
			'RECEIVE_PO',
			'VIEW_PO',
			'VIEW_RECEIPT',
			'VOID_RECEIPT',
		);
		sort( $expected );

		$this->assertSame( $expected, $actual, 'M25 must reuse EDIT_PO verbatim -- no new Purchasing_Caps constant.' );
	}

	public function test_no_new_do_action_or_add_action_hook_registration_in_new_files() {
		foreach ( array(
			'class-wc-inventory-overview-replenishment-commit-service.php',
			'class-wc-inventory-overview-replenishment-item-lock.php',
		) as $file ) {
			$src = $this->strip_comments( $this->read( $file ) );
			$this->assertStringNotContainsString( 'do_action(', $src, $file . ' must not register any new public hook.' );
			$this->assertStringNotContainsString( 'apply_filters(', $src, $file . ' must not register any new public filter.' );
		}
	}

	public function test_db_version_unchanged() {
		$src = $this->read( 'class-wc-inventory-overview-install.php' );
		$this->assertMatchesRegularExpression( "/const DB_VERSION = '11'/", $src, 'DB_VERSION must stay 11 -- M25 introduces no schema change.' );
	}

	public function test_conflict_status_set_is_exact() {
		$src = $this->read( 'class-wc-inventory-overview-purchase-order-lines.php' );
		$pos = strpos( $src, 'function query_conflicting_item_ids' );
		$this->assertNotFalse( $pos, 'query_conflicting_item_ids() not found.' );
		$end  = strpos( $src, "\n\t/**", $pos );
		$body = substr( $src, $pos, ( false !== $end ? $end : strlen( $src ) ) - $pos );

		$this->assertStringContainsString( 'WC_Inventory_Overview_PO_Statuses::DRAFT', $body );
		$this->assertStringContainsString( 'WC_Inventory_Overview_PO_Statuses::PLACED', $body );
		$this->assertStringContainsString( 'WC_Inventory_Overview_PO_Statuses::PARTIALLY_RECEIVED', $body );
		$this->assertStringNotContainsString( 'PO_Statuses::RECEIVED,', $body );
		$this->assertStringNotContainsString( 'PO_Statuses::CANCELLED', $body );
		$this->assertStringNotContainsString( 'PO_Statuses::CLOSED_SHORT', $body );
	}

	/**
	 * Purchase_Order_Lines gained one additive method
	 * (list_open_or_draft_item_ids_bulk()); its pre-existing public methods
	 * must still all be present with an unchanged signature line. A
	 * whitelist check (not a git diff) -- each of the method names/signature
	 * lines below is copied verbatim from the M24 tip.
	 */
	public function test_purchase_order_lines_pre_existing_method_signatures_present() {
		$src = $this->read( 'class-wc-inventory-overview-purchase-order-lines.php' );

		$expected_signatures = array(
			'public static function table_name(): string {',
			'public static function get( int $id ) {',
			'public static function get_for_update( int $id ) {',
			'public static function list_for_po( int $po_id ): array {',
			'public static function list_by_po( int $po_id ): array {',
			'public static function list_by_ids( array $ids ): array {',
			'public static function create( int $po_id, array $data ) {',
			'public static function update( int $id, array $data ) {',
			'public static function delete( int $id ) {',
			'public static function resequence( int $po_id ) {',
			'public static function delete_for_po( int $po_id ): void {',
			'public static function outstanding( array $line ): float {',
			'public static function increment_qty_received( int $line_id, float $delta ): int {',
			'public static function next_line_index( int $po_id ): int {',
			'public static function list_open_lines_for_product_ids( array $product_ids ): array {',
			'public static function list_open_lines_for_variation_ids( array $variation_ids ): array {',
			'public static function distinct_supplier_history_for_item( int $product_id, int $variation_id = 0 ): array {',
			'public static function distinct_supplier_history_for_items_bulk( array $product_ids, array $variation_ids ): array {',
		);

		foreach ( $expected_signatures as $signature ) {
			$this->assertStringContainsString( $signature, $src, 'Pre-existing Purchase_Order_Lines method signature missing or changed: ' . $signature );
		}
	}
}
