<?php
/**
 * M26 v1.43.0 release acceptance (disposable fixtures).
 *
 * Run:
 *   wp eval-file wp-content/plugins/wc-inventory-overview/scripts/m26-release-acceptance.php
 *
 * @package WC_Inventory_Overview
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

/**
 * Compact live smoke for M26 bulk apply on a bind-mounted / released plugin.
 */
class WC_IO_M26_Release_Acceptance {
	/** @var string[] */
	private $failures = array();

	/** @var int */
	private $passed = 0;

	/** @var string */
	private $marker = 'wc_io_m26_accept_';

	/** @var array{product_ids:int[],supplier_ids:int[],user_ids:int[]} */
	private $cleanup = array(
		'product_ids'  => array(),
		'supplier_ids' => array(),
		'user_ids'     => array(),
	);

	/**
	 * @return int Exit code.
	 */
	public function run(): int {
		WP_CLI::log( '=== M26 release acceptance starting ===' );
		WP_CLI::log( 'Plugin version: ' . WC_INVENTORY_OVERVIEW_VERSION );
		WP_CLI::log( 'DB_VERSION: ' . WC_Inventory_Overview_Install::DB_VERSION );

		try {
			$this->assert_true( '1.43.0' === WC_INVENTORY_OVERVIEW_VERSION, 'version is 1.43.0' );
			$this->assert_true( '11' === WC_Inventory_Overview_Install::DB_VERSION, 'DB_VERSION is 11' );
			$this->assert_true( class_exists( 'WC_Inventory_Overview_Replenishment_Defaults' ), 'defaults owner present' );
			$this->assert_true( 100 === WC_Inventory_Overview_Replenishment_Defaults::MAX_APPLY_VARIATIONS, 'cap constant equals 100' );
			$this->assert_true(
				method_exists( 'WC_Inventory_Overview_Replenishment_Defaults', 'apply_to_variations' ),
				'apply_to_variations exists'
			);
			$this->assert_true(
				file_exists( WC_INVENTORY_OVERVIEW_PATH . 'assets/product-replenishment-bulk.js' ),
				'bulk JS asset present'
			);
			$this->assert_true(
				file_exists( WC_INVENTORY_OVERVIEW_PATH . 'assets/product-replenishment-bulk.css' ),
				'bulk CSS asset present'
			);

			$admin_id = $this->ensure_admin();
			wp_set_current_user( $admin_id );

			$supplier = $this->create_supplier( $this->marker . 'Supplier A' );
			$parent   = $this->create_variable_with_variations( 3 );
			$children = array_map( 'absint', wc_get_product( $parent )->get_children() );
			$this->assert_true( 3 === count( $children ), 'fixture has 3 variations' );

			$po_before    = $this->count_rows( WC_Inventory_Overview_Purchase_Orders::table_name() );
			$stock_before = array();
			foreach ( $children as $vid ) {
				$stock_before[ $vid ] = (float) wc_get_product( $vid )->get_stock_quantity();
			}

			$set = WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
				$parent,
				$children,
				array(
					'update_preferred_supplier' => true,
					'preferred_supplier_id'     => (int) $supplier['id'],
					'update_default_qty'        => true,
					'default_qty'               => '7',
				)
			);
			$this->assert_true( is_array( $set ), 'bulk set returns array' );
			$this->assert_true( 3 === (int) $set['variations_updated'], 'set updates 3 variations' );
			$this->assert_true( 3 === (int) $set['supplier_updates'], 'set supplier_updates=3' );
			$this->assert_true( 3 === (int) $set['qty_updates'], 'set qty_updates=3' );

			foreach ( $children as $vid ) {
				$this->assert_true(
					(int) $supplier['id'] === WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $vid ),
					"variation {$vid} supplier set"
				);
				$this->assert_true(
					7.0 === WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $vid ),
					"variation {$vid} qty set"
				);
			}

			$parent_meta_supplier = get_post_meta( $parent, WC_Inventory_Overview_Replenishment_Defaults::META_PREFERRED_SUPPLIER, true );
			$parent_meta_qty      = get_post_meta( $parent, WC_Inventory_Overview_Replenishment_Defaults::META_DEFAULT_QTY, true );
			$this->assert_true( '' === (string) $parent_meta_supplier || '0' === (string) $parent_meta_supplier, 'parent has no preferred supplier meta' );
			$this->assert_true( '' === (string) $parent_meta_qty, 'parent has no default qty meta' );

			$prefill = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $parent, $children[0] );
			$this->assert_true( 'prefilled' === $prefill['status'], 'downstream prefill status prefilled' );
			$this->assert_true( (int) $supplier['id'] === (int) $prefill['supplier_id'], 'downstream prefill supplier' );
			$this->assert_true( '7' === (string) $prefill['line']['qty_ordered'], 'downstream prefill qty' );

			$clear_supplier = WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
				$parent,
				$children,
				array(
					'update_preferred_supplier' => true,
					'preferred_supplier_id'     => 0,
				)
			);
			$this->assert_true( is_array( $clear_supplier ), 'clear supplier returns array' );
			foreach ( $children as $vid ) {
				$this->assert_true(
					0 === WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $vid ),
					"variation {$vid} supplier cleared"
				);
				$this->assert_true(
					7.0 === WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $vid ),
					"qty preserved during supplier clear (isolation)"
				);
			}

			$clear_qty = WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
				$parent,
				$children,
				array(
					'update_default_qty' => true,
					'default_qty'        => '',
				)
			);
			$this->assert_true( is_array( $clear_qty ), 'clear qty returns array' );
			foreach ( $children as $vid ) {
				$this->assert_true(
					0.0 === WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $vid ),
					"variation {$vid} qty cleared"
				);
			}

			$over = WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
				$parent,
				array_fill( 0, 101, $children[0] ),
				array(
					'update_default_qty' => true,
					'default_qty'        => '2',
				)
			);
			$this->assert_true( is_wp_error( $over ), '>100 targets rejected' );

			$po_after = $this->count_rows( WC_Inventory_Overview_Purchase_Orders::table_name() );
			$this->assert_true( $po_before === $po_after, 'no PO mutation' );
			foreach ( $children as $vid ) {
				$qty = (float) wc_get_product( $vid )->get_stock_quantity();
				$this->assert_true( $stock_before[ $vid ] === $qty, "stock unchanged for variation {$vid}" );
			}

			WP_CLI::log( sprintf( 'Passed assertions: %d', $this->passed ) );
			return empty( $this->failures ) ? 0 : 1;
		} catch ( Throwable $e ) {
			$this->failures[] = 'exception: ' . $e->getMessage();
			WP_CLI::error( $e->getMessage(), false );
			return 1;
		} finally {
			$this->cleanup();
			if ( ! empty( $this->failures ) ) {
				foreach ( $this->failures as $f ) {
					WP_CLI::warning( $f );
				}
				WP_CLI::error( 'M26 release acceptance FAILED', false );
			} else {
				WP_CLI::success( 'M26 release acceptance PASSED — fixtures cleaned' );
			}
		}
	}

	/**
	 * @param bool   $cond Condition.
	 * @param string $label Label.
	 */
	private function assert_true( bool $cond, string $label ): void {
		if ( $cond ) {
			++$this->passed;
			WP_CLI::log( 'OK: ' . $label );
			return;
		}
		$this->failures[] = $label;
		WP_CLI::warning( 'FAIL: ' . $label );
	}

	/**
	 * @return int
	 */
	private function ensure_admin(): int {
		$user = get_user_by( 'login', $this->marker . 'admin' );
		if ( $user ) {
			$this->cleanup['user_ids'][] = (int) $user->ID;
			return (int) $user->ID;
		}
		$id = wp_create_user( $this->marker . 'admin', wp_generate_password( 24 ), $this->marker . 'admin@example.test' );
		$user = new WP_User( $id );
		$user->set_role( 'administrator' );
		$this->cleanup['user_ids'][] = (int) $id;
		return (int) $id;
	}

	/**
	 * @param string $name Supplier name.
	 * @return array
	 */
	private function create_supplier( string $name ): array {
		$id = WC_Inventory_Overview_Suppliers::create(
			array(
				'name'             => $name,
				'default_currency' => 'EUR',
				'status'           => 'active',
			)
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( $id->get_error_message() );
		}
		$this->cleanup['supplier_ids'][] = (int) $id;
		$row = WC_Inventory_Overview_Suppliers::get( (int) $id );
		if ( is_wp_error( $row ) || ! is_array( $row ) ) {
			throw new RuntimeException( 'Failed to load created supplier.' );
		}
		return $row;
	}

	/**
	 * @param int $n Variation count.
	 * @return int Parent product id.
	 */
	private function create_variable_with_variations( int $n ): int {
		$product = new WC_Product_Variable();
		$product->set_name( $this->marker . 'Variable' );
		$product->set_status( 'publish' );
		$product->save();
		$parent_id = (int) $product->get_id();
		$this->cleanup['product_ids'][] = $parent_id;

		for ( $i = 0; $i < $n; $i++ ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_status( 'publish' );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( 1 );
			$variation->set_low_stock_amount( 5 );
			$variation->save();
			$this->cleanup['product_ids'][] = (int) $variation->get_id();
		}

		wc_delete_product_transients( $parent_id );
		return $parent_id;
	}

	/**
	 * @param string $table Table name.
	 * @return int
	 */
	private function count_rows( string $table ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	private function cleanup(): void {
		foreach ( $this->cleanup['product_ids'] as $id ) {
			wp_delete_post( (int) $id, true );
		}
		foreach ( $this->cleanup['supplier_ids'] as $id ) {
			global $wpdb;
			$wpdb->delete( WC_Inventory_Overview_Suppliers::table_name(), array( 'id' => (int) $id ), array( '%d' ) );
		}
		foreach ( $this->cleanup['user_ids'] as $id ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( (int) $id );
		}
		WP_CLI::log( 'Cleanup complete.' );
	}
}

$runner = new WC_IO_M26_Release_Acceptance();
exit( $runner->run() );
