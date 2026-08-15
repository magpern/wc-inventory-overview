<?php
/**
 * M24+M25 feature-train pre-release acceptance (disposable fixtures).
 *
 * Run: wp eval-file wp-content/plugins/wc-inventory-overview/scripts/m24-m25-release-acceptance.php
 *
 * @package WC_Inventory_Overview
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

class WC_IO_M24_M25_Release_Acceptance {
	private $failures = array();
	private $passed   = 0;
	private $marker   = 'wc_io_m24_m25_accept_';
	private $cleanup  = array(
		'po_ids'       => array(),
		'product_ids'  => array(),
		'supplier_ids' => array(),
		'user_ids'     => array(),
		'transients'   => array(),
	);

	public function run(): int {
		WP_CLI::log( '=== M24+M25 release acceptance starting ===' );
		WP_CLI::log( 'Plugin version: ' . WC_INVENTORY_OVERVIEW_VERSION );
		WP_CLI::log( 'DB_VERSION: ' . WC_Inventory_Overview_Install::DB_VERSION );

		try {
			$this->assert_true( '1.42.0' === WC_INVENTORY_OVERVIEW_VERSION, 'version is 1.42.0' );
			$this->assert_true( '11' === WC_Inventory_Overview_Install::DB_VERSION, 'DB_VERSION is 11' );
			$this->assert_true( class_exists( 'WC_Inventory_Overview_Replenishment_Planning_Service' ), 'M24 planning class present' );
			$this->assert_true( class_exists( 'WC_Inventory_Overview_Replenishment_Commit_Service' ), 'M25 commit class present' );
			$this->assert_true( class_exists( 'WC_Inventory_Overview_Replenishment_Item_Lock' ), 'M25 lock class present' );

			$admin_id = $this->ensure_admin();
			wp_set_current_user( $admin_id );

			$supplier_a = $this->create_supplier( 'AAA Accept A' );
			$supplier_b = $this->create_supplier( 'BBB Accept B' );
			$supplier_c = $this->create_supplier( 'CCC Accept C' );
			$supplier_v = $this->create_supplier( 'VVV Accept V' );

			$p_simple = $this->make_needs_reorder_product( 'Simple Accept' );
			WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $p_simple->get_id(), $supplier_a['id'] );
			WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $p_simple->get_id(), 7 );

			// M21 signal (via Position + sole resolver).
			$on_hand   = (float) $p_simple->get_stock_quantity();
			$threshold = (float) WC_Inventory_Overview_Settings::get_effective_low_stock_amount( $p_simple );
			$position  = WC_Inventory_Overview_Inventory_Position_Service::get_position(
				WC_Inventory_Overview_Inventory_Position_Service::TYPE_PRODUCT,
				$p_simple->get_id(),
				$on_hand
			);
			$signal = WC_Inventory_Overview_Reorder_Signal_Resolver::resolve( (float) $position['position'], $threshold );
			$this->assert_true( ! empty( $signal['needs_reorder'] ), 'M21 needs_reorder true for fixture' );

			// M22 prefill path (M23 preferred supplier + default qty).
			$prefill = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $p_simple->get_id(), 0 );
			$this->assert_true( is_array( $prefill ) && 'prefilled' === ( $prefill['status'] ?? '' ), 'M22 prefill status prefilled' );
			$this->assert_true( (int) ( $prefill['supplier_id'] ?? 0 ) === (int) $supplier_a['id'], 'M23 preferred supplier used by prefill' );
			$this->assert_true( abs( (float) ( $prefill['line']['qty_ordered'] ?? 0 ) - 7.0 ) < 0.0001, 'M23 default qty used by prefill' );

			// Variation + parent exclusion.
			$variable = new WC_Product_Variable();
			$variable->set_name( $this->marker . 'Variable' );
			$variable->set_status( 'publish' );
			$variable->save();
			$this->cleanup['product_ids'][] = $variable->get_id();
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $variable->get_id() );
			$variation->set_status( 'publish' );
			$variation->set_regular_price( '1' );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( 1 );
			$variation->set_low_stock_amount( 5 );
			$variation->save();
			$this->cleanup['product_ids'][] = $variation->get_id();
			WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $variation->get_id(), $supplier_v['id'] );

			$p_multi = $this->make_needs_reorder_product( 'Multi hist' );
			$this->seed_history( $p_multi->get_id(), $supplier_a['id'] );
			$this->seed_history( $p_multi->get_id(), $supplier_b['id'] );

			$p_none = $this->make_needs_reorder_product( 'No hist' );

			$p_b = $this->make_needs_reorder_product( 'Group B' );
			WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $p_b->get_id(), $supplier_b['id'] );
			$p_c = $this->make_needs_reorder_product( 'Group C' );
			WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $p_c->get_id(), $supplier_c['id'] );

			// M24 catalog plan.
			$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();
			$this->assert_true( isset( $plan['groups'], $plan['unresolved'] ), 'M24 catalog plan shape' );
			$resolved_ids = array();
			foreach ( $plan['groups'] as $g ) {
				foreach ( $g['lines'] as $line ) {
					$resolved_ids[] = $line['variation_id'] > 0 ? $line['variation_id'] : $line['product_id'];
				}
			}
			$this->assert_true( in_array( $p_simple->get_id(), $resolved_ids, true ), 'simple resolved in catalog plan' );
			$this->assert_true( in_array( $variation->get_id(), $resolved_ids, true ), 'variation resolved in catalog plan' );
			$this->assert_true( ! in_array( $variable->get_id(), $resolved_ids, true ), 'variable parent excluded from resolved' );

			$unresolved_map = array();
			foreach ( $plan['unresolved'] as $u ) {
				$id = $u['variation_id'] > 0 ? $u['variation_id'] : $u['product_id'];
				$unresolved_map[ $id ] = $u['reason'];
			}
			$this->assert_true( ( $unresolved_map[ $p_none->get_id() ] ?? '' ) === 'no_supplier', 'unresolved no_supplier' );
			$this->assert_true( ( $unresolved_map[ $p_multi->get_id() ] ?? '' ) === 'multiple_suppliers', 'unresolved multiple_suppliers' );

			// M24 scoped plan.
			$scoped = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan( array(), array( $p_simple->get_id() ) );
			$scoped_count = 0;
			foreach ( $scoped['groups'] as $g ) {
				$scoped_count += count( $g['lines'] );
			}
			$this->assert_true( 1 === $scoped_count, 'scoped plan returns one line' );

			// Capability gating for commit form: EDIT_PO required.
			$this->assert_true(
				WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::EDIT_PO ),
				'admin has EDIT_PO for form'
			);

			// M25 single-group commit with edited qty.
			$r1 = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
				array(
					array(
						'product_id'   => $p_simple->get_id(),
						'variation_id' => 0,
						'qty'          => 11,
					),
				)
			);
			$this->assert_true( is_array( $r1 ) && 1 === count( $r1['created'] ), 'single-group commit created 1' );
			$po1 = (int) $r1['created'][0]['po_id'];
			$this->cleanup['po_ids'][] = $po1;
			$po_row = WC_Inventory_Overview_Purchase_Orders::get( $po1 );
			$this->assert_true( WC_Inventory_Overview_PO_Statuses::DRAFT === $po_row['status'], 'PO is draft' );
			$this->assert_true( (int) $po_row['supplier_id'] === (int) $supplier_a['id'], 'supplier server-derived' );
			$this->assert_true( 'Created from Replenishment Planning' === $po_row['note'], 'provenance note' );
			$this->assert_true( null === $po_row['expected_date'] || '' === (string) $po_row['expected_date'], 'expected date unset' );
			$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po1 );
			$this->assert_true( 1 === count( $lines ), 'one line' );
			$this->assert_true( abs( (float) $lines[0]['qty_ordered'] - 11.0 ) < 0.0001, 'edited qty preserved' );
			$this->assert_true( abs( (float) $lines[0]['unit_cost'] ) < 0.0001, 'unit_cost 0' );
			$this->assert_true( $po_row['currency'] === $supplier_a['default_currency'] || ! empty( $po_row['currency'] ), 'currency present from supplier path' );

			// Immediate duplicate retry.
			$r2 = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
				array(
					array(
						'product_id'   => $p_simple->get_id(),
						'variation_id' => 0,
						'qty'          => 3,
					),
				)
			);
			$this->assert_true( empty( $r2['created'] ), 'immediate retry creates nothing' );
			$this->assert_true( 'already_has_open_po_line' === ( $r2['skipped'][0]['reason'] ?? '' ), 'skip already_has_open_po_line' );

			// Multi-group commit: three items, three distinct suppliers → 3 POs.
			$r3 = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
				array(
					array(
						'product_id'   => $variable->get_id(),
						'variation_id' => $variation->get_id(),
						'qty'          => 4,
					),
					array(
						'product_id'   => $p_b->get_id(),
						'variation_id' => 0,
						'qty'          => 5,
					),
					array(
						'product_id'   => $p_c->get_id(),
						'variation_id' => 0,
						'qty'          => 6,
					),
				)
			);
			$this->assert_true( 3 === count( $r3['created'] ), 'multi-group created 3 lines' );
			$po_ids = array_unique( wp_list_pluck( $r3['created'], 'po_id' ) );
			$this->assert_true( 3 === count( $po_ids ), 'three distinct POs for three suppliers' );
			foreach ( $po_ids as $pid ) {
				$this->cleanup['po_ids'][] = (int) $pid;
			}
			$var_line_ok = false;
			foreach ( $r3['created'] as $c ) {
				if ( (int) $c['variation_id'] === (int) $variation->get_id() ) {
					$var_line_ok = true;
					$vlines      = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( (int) $c['po_id'] );
					$this->assert_true( (int) $vlines[0]['variation_id'] === (int) $variation->get_id(), 'variation identity on PO line' );
				}
			}
			$this->assert_true( $var_line_ok, 'variation among created' );

			// Unresolved cannot commit into created.
			$r_bad = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
				array(
					array(
						'product_id'   => $p_none->get_id(),
						'variation_id' => 0,
						'qty'          => 2,
					),
				)
			);
			$this->assert_true( empty( $r_bad['created'] ), 'unresolved creates nothing' );
			$this->assert_true( in_array( ( $r_bad['skipped'][0]['reason'] ?? '' ), array( 'no_supplier', 'multiple_suppliers' ), true ), 'unresolved skipped' );

			// Conflict statuses: draft already covered; place one and partially_received one.
			$p_placed = $this->make_needs_reorder_product( 'Placed conflict' );
			WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $p_placed->get_id(), $supplier_a['id'] );
			$po_placed = WC_Inventory_Overview_PO_Service::create_draft(
				array( 'supplier_id' => $supplier_a['id'] ),
				array(
					array(
						'product_id'   => $p_placed->get_id(),
						'variation_id' => 0,
						'qty_ordered'  => 1,
					),
				)
			);
			$this->assert_true( ! is_wp_error( $po_placed ), 'placed fixture draft created' );
			$this->cleanup['po_ids'][] = (int) $po_placed;
			$place = WC_Inventory_Overview_PO_Service::place( (int) $po_placed );
			$this->assert_true( ! is_wp_error( $place ), 'PO placed' );
			$r_placed = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
				array(
					array(
						'product_id'   => $p_placed->get_id(),
						'variation_id' => 0,
						'qty'          => 1,
					),
				)
			);
			$this->assert_true( 'already_has_open_po_line' === ( $r_placed['skipped'][0]['reason'] ?? '' ), 'placed conflicts' );

			// received-only history must not conflict-block.
			$p_recv = $this->make_needs_reorder_product( 'Received hist' );
			WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $p_recv->get_id(), $supplier_a['id'] );
			$po_recv = WC_Inventory_Overview_PO_Service::create_draft(
				array( 'supplier_id' => $supplier_a['id'] ),
				array(
					array(
						'product_id'   => $p_recv->get_id(),
						'variation_id' => 0,
						'qty_ordered'  => 1,
					),
				)
			);
			$this->cleanup['po_ids'][] = (int) $po_recv;
			global $wpdb;
			$wpdb->update(
				WC_Inventory_Overview_Purchase_Orders::table_name(),
				array( 'status' => WC_Inventory_Overview_PO_Statuses::RECEIVED ),
				array( 'id' => (int) $po_recv )
			);
			$r_recv = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
				array(
					array(
						'product_id'   => $p_recv->get_id(),
						'variation_id' => 0,
						'qty'          => 2,
					),
				)
			);
			$this->assert_true( 1 === count( $r_recv['created'] ), 'received history does not falsely block' );
			$this->cleanup['po_ids'][] = (int) $r_recv['created'][0]['po_id'];

			// Token replay.
			$token = WC_Inventory_Overview_PO_Request_Token::issue( 'replenishment_commit' );
			$this->assert_true( WC_Inventory_Overview_PO_Request_Token::consume( $token, 'replenishment_commit' ), 'token consume once' );
			$this->assert_true( ! WC_Inventory_Overview_PO_Request_Token::consume( $token, 'replenishment_commit' ), 'token replay denied' );

			// GET/render non-mutating: build_plan again should not add POs beyond our tracked set.
			$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore
			WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();
			$after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore
			$this->assert_true( $before === $after, 'GET/plan rebuild does not create POs' );

			$this->assert_true( empty( $this->failures ), 'zero assertion failures before cleanup' );
		} catch ( Throwable $e ) {
			$this->failures[] = 'EXCEPTION: ' . $e->getMessage();
		}

		$this->cleanup_all();

		WP_CLI::log( 'Passed checks: ' . $this->passed );
		if ( ! empty( $this->failures ) ) {
			foreach ( $this->failures as $f ) {
				WP_CLI::error( $f, false );
			}
			WP_CLI::error( 'M24+M25 acceptance FAILED', true );
			return 1;
		}
		WP_CLI::success( 'M24+M25 acceptance PASSED' );
		return 0;
	}

	private function assert_true( bool $cond, string $label ): void {
		if ( $cond ) {
			++$this->passed;
			WP_CLI::log( '  OK  ' . $label );
			return;
		}
		$this->failures[] = $label;
		WP_CLI::warning( 'FAIL ' . $label );
	}

	private function ensure_admin(): int {
		$user = get_user_by( 'login', 'bp_manager' );
		if ( $user ) {
			return (int) $user->ID;
		}
		$id = wp_insert_user(
			array(
				'user_login' => $this->marker . 'admin',
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => 'administrator',
			)
		);
		$this->cleanup['user_ids'][] = (int) $id;
		return (int) $id;
	}

	private function create_supplier( string $name ): array {
		$id = WC_Inventory_Overview_Suppliers::create(
			array(
				'name'              => $this->marker . $name,
				'default_currency'  => 'EUR',
				'status'            => 'active',
			)
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( $id->get_error_message() );
		}
		$this->cleanup['supplier_ids'][] = (int) $id;
		$row = WC_Inventory_Overview_Suppliers::get( (int) $id );
		return $row;
	}

	private function make_needs_reorder_product( string $name ): WC_Product_Simple {
		$p = new WC_Product_Simple();
		$p->set_name( $this->marker . $name );
		$p->set_status( 'publish' );
		$p->set_regular_price( '1' );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( 1 );
		$p->set_low_stock_amount( 5 );
		$p->save();
		$this->cleanup['product_ids'][] = $p->get_id();
		return $p;
	}

	private function seed_history( int $product_id, int $supplier_id ): void {
		$po = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => $supplier_id ),
			array(
				array(
					'product_id'   => $product_id,
					'variation_id' => 0,
					'qty_ordered'  => 1,
				),
			)
		);
		if ( is_wp_error( $po ) ) {
			throw new RuntimeException( $po->get_error_message() );
		}
		$this->cleanup['po_ids'][] = (int) $po;
		WC_Inventory_Overview_PO_Service::place( (int) $po );
		global $wpdb;
		$wpdb->update(
			WC_Inventory_Overview_Purchase_Orders::table_name(),
			array( 'status' => WC_Inventory_Overview_PO_Statuses::RECEIVED ),
			array( 'id' => (int) $po )
		);
	}

	private function cleanup_all(): void {
		WP_CLI::log( '=== cleanup ===' );
		global $wpdb;
		foreach ( array_unique( $this->cleanup['po_ids'] ) as $po_id ) {
			$wpdb->delete( WC_Inventory_Overview_PO_Events::table_name(), array( 'po_id' => $po_id ) );
			$wpdb->delete( WC_Inventory_Overview_Purchase_Order_Lines::table_name(), array( 'po_id' => $po_id ) );
			$wpdb->delete( WC_Inventory_Overview_Purchase_Orders::table_name(), array( 'id' => $po_id ) );
		}
		foreach ( array_unique( $this->cleanup['product_ids'] ) as $pid ) {
			wp_delete_post( (int) $pid, true );
		}
		foreach ( array_unique( $this->cleanup['supplier_ids'] ) as $sid ) {
			$wpdb->delete( WC_Inventory_Overview_Suppliers::table_name(), array( 'id' => $sid ) );
		}
		foreach ( array_unique( $this->cleanup['user_ids'] ) as $uid ) {
			if ( $uid > 1 ) {
				wp_delete_user( (int) $uid );
			}
		}
		// Residue check: no marker products/suppliers.
		$left_posts = get_posts(
			array(
				's'              => $this->marker,
				'post_type'      => array( 'product', 'product_variation' ),
				'posts_per_page' => 5,
				'post_status'    => 'any',
				'fields'         => 'ids',
			)
		);
		$left_sup = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Suppliers::table_name() . ' WHERE name LIKE %s', // phpcs:ignore
				$this->marker . '%'
			)
		);
		if ( ! empty( $left_posts ) || (int) $left_sup > 0 ) {
			WP_CLI::warning( 'Residue detected posts=' . count( (array) $left_posts ) . ' suppliers=' . (int) $left_sup );
			$this->failures[] = 'cleanup residue remains';
		} else {
			WP_CLI::log( '  OK  zero disposable residue' );
			++$this->passed;
		}
	}
}

$runner = new WC_IO_M24_M25_Release_Acceptance();
exit( $runner->run() );
