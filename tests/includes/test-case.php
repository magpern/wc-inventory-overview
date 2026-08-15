<?php
/**
 * Base test case for WC Inventory Overview
 *
 * Extends WordPress PHPUnit with plugin-specific helpers.
 *
 * @package WC_Inventory_Overview_Tests
 */

/**
 * WC_Inventory_Overview_Test_Case
 *
 * Base class for integration tests. Ensures proper isolation and cleanup.
 */
abstract class WC_Inventory_Overview_Test_Case extends WP_UnitTestCase {

	/**
	 * Set up test fixtures (run before each test method).
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure database is fresh for each test.
		self::flush_cache();
	}

	/**
	 * Tear down after each test (run after each test method).
	 */
	public function tearDown(): void {
		parent::tearDown();

		// Flush any remaining caches.
		self::flush_cache();
	}

	/**
	 * Flush all WordPress caches (object cache, transients, etc.).
	 *
	 * Static to match WP_UnitTestCase_Base::flush_cache()'s signature in
	 * current WordPress core -- PHP does not allow narrowing a static parent
	 * method to non-static in a subclass. $this->flush_cache() calls below
	 * remain valid (PHP permits calling a static method via $this->).
	 */
	public static function flush_cache(): void {
		wp_cache_flush();

		// Also flush product-specific transients if WooCommerce is active.
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			// WooCommerce transient cleanup would be called per-product in real tests.
		}
	}

	/**
	 * Create a simple WooCommerce product for testing.
	 *
	 * @param array $props Product properties to override defaults.
	 * @return WC_Product_Simple The created product.
	 */
	protected function create_simple_product( $props = array() ): WC_Product_Simple {
		$defaults = array(
			'name'         => 'Test Product',
			'type'         => 'simple',
			'status'       => 'publish',
			'stock_qty'    => 0,
			'manage_stock' => true,
		);

		$props   = array_merge( $defaults, $props );
		$product = new WC_Product_Simple();
		$product->set_name( $props['name'] );
		$product->set_status( $props['status'] );
		$product->set_manage_stock( $props['manage_stock'] );
		$product->set_stock_quantity( $props['stock_qty'] );

		$product->save();

		return $product;
	}

	/**
	 * Create a variable WooCommerce product with variations.
	 *
	 * @param array $props Product properties.
	 * @param array $variations Array of variation data.
	 * @return WC_Product_Variable The created variable product.
	 */
	protected function create_variable_product( $props = array(), $variations = array() ): WC_Product_Variable {
		$defaults = array(
			'name'   => 'Test Variable Product',
			'type'   => 'variable',
			'status' => 'publish',
		);

		$props   = array_merge( $defaults, $props );
		$product = new WC_Product_Variable();
		$product->set_name( $props['name'] );
		$product->set_status( $props['status'] );

		$product->save();

		// Add variations.
		foreach ( $variations as $var_data ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_name( $var_data['name'] ?? 'Variation' );
			$variation->set_status( 'publish' );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $var_data['stock_qty'] ?? 0 );

			// Set attributes.
			if ( isset( $var_data['attributes'] ) ) {
				foreach ( $var_data['attributes'] as $attr_name => $attr_value ) {
					$variation->set_attributes( array( $attr_name => $attr_value ) );
				}
			}

			$variation->save();
		}

		return $product;
	}

	/**
	 * Set a product's average cost meta (used in golden fixture setup).
	 *
	 * @param WC_Product $product The product to update.
	 * @param float      $avg_cost The average cost value.
	 * @return void
	 */
	protected function set_product_average_cost( WC_Product $product, float $avg_cost ): void {
		$product->update_meta_data( '_wc_io_average_unit_cost', $avg_cost );
		$product->save();
	}

	/**
	 * Set a product's inventory value meta (used in golden fixture setup).
	 *
	 * @param WC_Product $product The product to update.
	 * @param float      $inventory_value The inventory value.
	 * @return void
	 */
	protected function set_product_inventory_value( WC_Product $product, float $inventory_value ): void {
		$product->update_meta_data( '_wc_io_inventory_value', $inventory_value );
		$product->save();
	}

	/**
	 * Get a product's average cost (as persisted in meta).
	 *
	 * @param WC_Product $product The product.
	 * @return float|null The average cost or null if not set.
	 */
	protected function get_product_average_cost( WC_Product $product ): ?float {
		$cost = $product->get_meta( '_wc_io_average_unit_cost' );
		return ( '' === $cost || null === $cost ) ? null : (float) $cost;
	}

	/**
	 * Get a product's inventory value (as persisted in meta).
	 *
	 * @param WC_Product $product The product.
	 * @return float The inventory value.
	 */
	protected function get_product_inventory_value( WC_Product $product ): float {
		return (float) $product->get_meta( '_wc_io_inventory_value', true );
	}

	/**
	 * Assert that two decimal values are equal to a given precision.
	 *
	 * @param float  $expected The expected value.
	 * @param float  $actual The actual value.
	 * @param int    $decimals Number of decimal places to compare (default 4).
	 * @param string $message Optional failure message.
	 * @return void
	 */
	protected function assertDecimalEqual( float $expected, float $actual, int $decimals = 4, string $message = '' ): void {
		$expected_str = number_format( $expected, $decimals, '.', '' );
		$actual_str   = number_format( $actual, $decimals, '.', '' );

		$this->assertEquals( $expected_str, $actual_str, $message ?: "Decimal values do not match to {$decimals} places" );
	}

	/**
	 * Create a supplier for testing.
	 *
	 * @param array $props Override properties (default: reasonable test values).
	 * @return array The created supplier row.
	 */
	protected function create_supplier( array $props = array() ): array {
		$defaults = array(
			'name'             => 'Test Supplier ' . uniqid(),
			'default_currency' => 'EUR',
		);
		$data     = wp_parse_args( $props, $defaults );

		$id = WC_Inventory_Overview_Suppliers::create( $data );
		if ( is_wp_error( $id ) ) {
			$this->fail( 'Failed to create supplier: ' . $id->get_error_message() );
		}

		return WC_Inventory_Overview_Suppliers::get( $id );
	}

	/**
	 * Create a draft purchase order for testing. Creates a supplier first if
	 * none given, following create_supplier()'s fail-fast pattern.
	 *
	 * @param array $props Override properties (default: reasonable test values).
	 * @return array The created PO row.
	 */
	protected function create_purchase_order( array $props = array() ): array {
		if ( ! isset( $props['supplier_id'] ) ) {
			$supplier             = $this->create_supplier();
			$props['supplier_id'] = $supplier['id'];
		}

		$defaults = array(
			'currency' => 'EUR',
		);
		$data     = wp_parse_args( $props, $defaults );

		$id = WC_Inventory_Overview_Purchase_Orders::create_draft( $data );
		if ( is_wp_error( $id ) ) {
			$this->fail( 'Failed to create purchase order: ' . $id->get_error_message() );
		}

		return WC_Inventory_Overview_Purchase_Orders::get( $id );
	}

	/**
	 * Add a line to a purchase order for testing. Creates a fresh simple,
	 * stock-managed product if none given.
	 *
	 * @param int   $po_id PO ID.
	 * @param array $props Override properties (default: reasonable test values).
	 * @return array The created line row.
	 */
	protected function add_po_line( int $po_id, array $props = array() ): array {
		if ( ! isset( $props['product_id'] ) ) {
			$product             = $this->create_simple_product();
			$props['product_id'] = $product->get_id();
		}

		$defaults = array(
			'qty_ordered' => 1,
			'unit_cost'   => 1,
		);
		$data     = wp_parse_args( $props, $defaults );

		$id = WC_Inventory_Overview_Purchase_Order_Lines::create( $po_id, $data );
		if ( is_wp_error( $id ) ) {
			$this->fail( 'Failed to add purchase order line: ' . $id->get_error_message() );
		}

		return WC_Inventory_Overview_Purchase_Order_Lines::get( $id );
	}

	/**
	 * Create a legacy Batch Intake batch by writing directly to the
	 * wc_io_purchase_batches* tables and mutating stock/cost through
	 * WC_Inventory_Overview_Restock_Service::apply_purchase_line_change() --
	 * the same live, non-deprecated mutator the original (M8-removed)
	 * WC_Inventory_Overview_Batch_Intake_Service::apply_batch_from_post()
	 * called internally. This reproduces exactly the persisted row shape and
	 * stock/cost mutation that method produced (single product line, at most
	 * one landed-cost row -- the only shapes any M6 migration test actually
	 * exercises), without depending on the deleted class. A batch built this
	 * way is a realistic historical fixture: it has already mutated current
	 * stock/cost precisely like a real pre-M6 batch would have, before any
	 * M6 migration test runs against it.
	 *
	 * @param array<string,mixed> $props Overrides: product (WC_Product), supplier_name,
	 *                                    reference, note, currency, fx_rate, fx_date, qty,
	 *                                    line_cost, landed_type, landed_amount.
	 * @return array{batch_id:int, product_id:int}
	 */
	protected function create_legacy_batch( array $props = array() ): array {
		global $wpdb;

		$product = $props['product'] ?? $this->create_simple_product( array( 'stock_qty' => 0 ) );

		$supplier_name = (string) ( $props['supplier_name'] ?? 'Legacy Supplier' );
		$reference     = (string) ( $props['reference'] ?? 'INV-0001' );
		$note          = (string) ( $props['note'] ?? '' );
		$cur           = strtoupper( (string) ( $props['currency'] ?? WC_Inventory_Overview_Settings::CURRENCY_EUR ) );
		$fx_date       = (string) ( $props['fx_date'] ?? wp_date( 'Y-m-d', null, wp_timezone() ) );
		$qty           = (float) ( $props['qty'] ?? 10 );
		$line_cost     = (string) ( $props['line_cost'] ?? 100 );

		if ( WC_Inventory_Overview_Settings::CURRENCY_EUR === $cur ) {
			$rate = 1.0;
		} elseif ( isset( $props['fx_rate'] ) ) {
			$rate = (float) wc_format_decimal( (string) $props['fx_rate'], 8 );
		} else {
			$lk   = WC_Inventory_Overview_Exchange_Rates::get_exchange_rate_to_eur( $cur, $fx_date );
			$rate = is_wp_error( $lk ) ? 1.0 : (float) $lk['rate'];
		}

		$entered_line  = (float) wc_format_decimal( $line_cost, 4 );
		$converted_eur = (float) wc_format_decimal( $entered_line * $rate, 4 );
		$base_line     = $converted_eur; // Single line: product subtotal == this line's base cost.

		$has_landed     = isset( $props['landed_type'] );
		$landed_type    = $has_landed ? (string) $props['landed_type'] : '';
		$landed_entered = $has_landed ? (float) wc_format_decimal( (string) ( $props['landed_amount'] ?? 10 ), 4 ) : 0.0;
		// Landed row currency/rate always mirror the batch's own (no test exercises an independent landed-row currency).
		$landed_converted = $has_landed ? (float) wc_format_decimal( $landed_entered * $rate, 4 ) : 0.0;
		$landed_total      = $landed_converted;
		$landed_total_entered = $has_landed ? $landed_entered : 0.0; // Only counted when landed currency === purchase currency (always true here).

		// Single-line allocation: with n=1, the whole landed total allocates to this one line (mirrors the original's last-index remainder branch).
		$allocated_landed = $landed_total;
		$true_line         = (float) wc_format_decimal( $base_line + $allocated_landed, 4 );
		$true_unit          = (float) wc_format_decimal( $true_line / $qty, 6 );

		if ( ! WC_Inventory_Overview_Settings::allow_zero_supplier_cost() && $true_unit <= 0.0 ) {
			$this->fail( 'create_legacy_batch(): true unit cost must be greater than zero (see Settings: allow zero supplier cost).' );
		}

		$batches_table = $wpdb->prefix . 'wc_io_purchase_batches';
		$batch_total_entered = (float) wc_format_decimal( $entered_line + $landed_total_entered, 4 );
		$batch_total          = (float) wc_format_decimal( $base_line + $landed_total, 4 );

		$ins_batch = $wpdb->insert(
			$batches_table,
			array(
				'supplier_name'            => $supplier_name,
				'reference'                => $reference,
				'purchase_currency'        => $cur,
				'exchange_rate_to_eur'     => wc_format_decimal( $rate, 8 ),
				'exchange_rate_date'       => $fx_date,
				'product_subtotal_entered' => wc_format_decimal( $entered_line, 4 ),
				'landed_total_entered'     => wc_format_decimal( $landed_total_entered, 4 ),
				'batch_total_entered'      => wc_format_decimal( $batch_total_entered, 4 ),
				'product_subtotal'         => wc_format_decimal( $base_line, 4 ),
				'landed_total'             => wc_format_decimal( $landed_total, 4 ),
				'batch_total'              => wc_format_decimal( $batch_total, 4 ),
				'note'                     => $note,
				'user_id'                  => get_current_user_id(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
		if ( false === $ins_batch ) {
			$this->fail( 'create_legacy_batch(): could not insert batch header: ' . $wpdb->last_error );
		}
		$batch_id = (int) $wpdb->insert_id;

		$applied = WC_Inventory_Overview_Restock_Service::apply_purchase_line_change(
			$product->get_id(),
			$qty,
			$true_unit
		);
		if ( is_wp_error( $applied ) ) {
			$this->fail( 'create_legacy_batch(): stock/cost mutation failed: ' . $applied->get_error_message() );
		}

		$note_lines = array(
			sprintf( 'Batch ID: %d', $batch_id ),
			sprintf( 'Reference: %s', '' !== trim( $reference ) ? $reference : '—' ),
			sprintf(
				'Purchase currency: %1$s; Exchange rate to EUR: %2$s; Exchange rate date: %3$s',
				$cur,
				wc_format_decimal( (string) $rate, 8 ),
				'' !== trim( $fx_date ) ? $fx_date : '—'
			),
			'',
			sprintf(
				'Entered subtotal: %1$s %2$s; Converted EUR: %3$s; Allocated landed EUR: %4$s; True unit EUR: %5$s',
				wc_format_decimal( (string) $entered_line, 4 ),
				$cur,
				wc_format_decimal( (string) $converted_eur, 4 ),
				wc_format_decimal( (string) $allocated_landed, 4 ),
				wc_format_decimal( (string) $true_unit, 6 )
			),
		);
		if ( $has_landed ) {
			$labels = WC_Inventory_Overview_Landed_Cost_Types::landed_cost_type_labels();
			$label  = $labels[ $landed_type ] ?? $landed_type;
			$note_lines[] = '';
			$note_lines[] = 'Additional landed costs (EUR after row FX):';
			$note_lines[] = sprintf(
				'%1$s: %2$s %3$s → %4$s EUR',
				$label,
				wc_format_decimal( (string) $landed_entered, 4 ),
				$cur,
				wc_format_decimal( (string) $landed_converted, 4 )
			);
		}
		$movement_note = implode( "\n", $note_lines );

		$mrow = array_merge(
			$applied['movement'],
			array(
				'total_value'   => (float) wc_format_decimal( $true_line, 4 ),
				'unit_cost'     => (float) wc_format_decimal( $true_unit, 6 ),
				'supplier_name' => $supplier_name,
				'note'          => $movement_note,
			)
		);
		$mov_ok = WC_Inventory_Overview_Movements::insert_purchase_batch( $mrow );
		if ( ! $mov_ok ) {
			$this->fail( 'create_legacy_batch(): could not write movement log: ' . $wpdb->last_error );
		}

		if ( $has_landed ) {
			$costs_table = $wpdb->prefix . 'wc_io_purchase_batch_costs';
			$ins_c       = $wpdb->insert(
				$costs_table,
				array(
					'batch_id'             => $batch_id,
					'cost_type'            => $landed_type,
					'entered_currency'     => $cur,
					'exchange_rate_to_eur' => wc_format_decimal( $rate, 8 ),
					'entered_amount'       => wc_format_decimal( $landed_entered, 4 ),
					'converted_amount_eur' => wc_format_decimal( $landed_converted, 4 ),
					'amount'               => wc_format_decimal( $landed_converted, 4 ),
					'note'                 => '',
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( false === $ins_c ) {
				$this->fail( 'create_legacy_batch(): could not insert landed cost row: ' . $wpdb->last_error );
			}
		}

		$parent_id    = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		$variation_id = $product->is_type( 'variation' ) ? (int) $product->get_id() : 0;

		$line_insert = array(
			'batch_id'                => $batch_id,
			'product_id'              => $parent_id,
			'variation_id'            => $variation_id,
			'quantity'                => wc_format_decimal( $qty, 4 ),
			'entered_currency'        => $cur,
			'exchange_rate_to_eur'    => wc_format_decimal( $rate, 8 ),
			'entered_line_cost'       => wc_format_decimal( $entered_line, 4 ),
			'converted_line_cost_eur' => wc_format_decimal( $converted_eur, 4 ),
			'base_line_cost'          => wc_format_decimal( $base_line, 4 ),
			'allocated_landed_cost'   => wc_format_decimal( $allocated_landed, 4 ),
			'true_line_cost'          => wc_format_decimal( $true_line, 4 ),
			'true_unit_cost'          => wc_format_decimal( $true_unit, 6 ),
			'old_stock'               => wc_format_decimal( $applied['movement']['old_stock'], 4 ),
			'new_stock'               => wc_format_decimal( $applied['movement']['new_stock'], 4 ),
		);
		$line_formats = array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		$old_avg_raw = $applied['movement']['old_average_unit_cost'];
		if ( null !== $old_avg_raw && '' !== $old_avg_raw ) {
			$line_insert['old_average_unit_cost'] = wc_format_decimal( $old_avg_raw, 6 );
			$line_formats[]                       = '%s';
		}
		$line_insert['new_average_unit_cost'] = wc_format_decimal( $applied['movement']['new_average_unit_cost'], 6 );
		$line_insert['old_inventory_value']   = wc_format_decimal( $applied['movement']['old_inventory_value'], 4 );
		$line_insert['new_inventory_value']   = wc_format_decimal( $applied['movement']['new_inventory_value'], 4 );
		$line_formats[] = '%s';
		$line_formats[] = '%s';
		$line_formats[] = '%s';

		$lines_table = $wpdb->prefix . 'wc_io_purchase_batch_lines';
		$ins_l       = $wpdb->insert( $lines_table, $line_insert, $line_formats );
		if ( false === $ins_l ) {
			$this->fail( 'create_legacy_batch(): could not insert batch line row: ' . $wpdb->last_error );
		}

		wc_delete_product_transients( $product->get_id() );
		clean_post_cache( $product->get_id() );
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'product', true );
		}

		return array(
			'batch_id'   => $batch_id,
			'product_id' => $product->get_id(),
		);
	}

	/**
	 * Open a genuine second, independent MySQL/MariaDB connection, distinct
	 * from the shared $wpdb connection this PHP process's own request uses.
	 *
	 * Used by M25 lock-serialization tests to prove GET_LOCK()/RELEASE_LOCK()
	 * blocking behavior empirically rather than only by sequential
	 * simulation -- a real second session is required because a single
	 * MySQL/MariaDB connection can re-acquire its own already-held named
	 * lock without blocking (session-reentrant since MySQL 5.7), so a
	 * same-connection "simulation" of a held lock would not actually
	 * exercise GET_LOCK()'s blocking/timeout path at all.
	 *
	 * @return mysqli|null A connected mysqli instance, or null if the DB_*
	 *                      constants required to open one are unavailable
	 *                      (test harness limitation -- callers should mark
	 *                      the test skipped in that case, never fail).
	 */
	protected function open_second_db_connection() {
		if ( ! defined( 'DB_HOST' ) || ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) ) {
			return null;
		}
		if ( ! class_exists( 'mysqli' ) ) {
			return null;
		}
		$host = DB_HOST;
		$port = 3306;
		if ( false !== strpos( $host, ':' ) ) {
			list( $host, $port_raw ) = explode( ':', $host, 2 );
			$port = (int) $port_raw;
		}
		$mysqli = @mysqli_connect( $host, DB_USER, DB_PASSWORD, DB_NAME, $port ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- deliberately non-fatal; caller treats a failed connection as "harness doesn't support this," not a test failure.
		return $mysqli instanceof mysqli ? $mysqli : null;
	}

	/**
	 * Backdate a legacy batch's created_at (direct SQL — simulates a batch
	 * genuinely applied in a prior year, for receipt-numbering-year tests).
	 *
	 * @param int    $batch_id Legacy batch id.
	 * @param string $datetime MySQL datetime string, e.g. '2023-05-01 10:00:00'.
	 */
	protected function backdate_legacy_batch( int $batch_id, string $datetime ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'wc_io_purchase_batches',
			array( 'created_at' => $datetime ),
			array( 'id' => $batch_id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
