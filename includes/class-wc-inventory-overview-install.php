<?php
/**
 * Database schema: inventory movements + batch intake tables (dbDelta).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Install / upgrade custom tables.
 */
class WC_Inventory_Overview_Install {

	const DB_VERSION = '11'; // Unchanged in M18/M19/M20/M21

	/**
	 * Register activation hook target.
	 */
	public static function activate() {
		self::create_tables();
		self::cleanup_legacy_fx_options();
		update_option( 'wc_io_db_version', self::DB_VERSION );
		self::assert_schema_shape();
		WC_Inventory_Overview_Suppliers_Migration::run();
	}

	/**
	 * Run dbDelta when version bumps.
	 */
	public static function maybe_upgrade() {
		$current = get_option( 'wc_io_db_version', '' );
		if ( self::DB_VERSION !== $current ) {
			self::create_tables();
			self::cleanup_legacy_fx_options();
			update_option( 'wc_io_db_version', self::DB_VERSION );
			self::assert_schema_shape();
			WC_Inventory_Overview_Suppliers_Migration::run();
		}
	}

	/**
	 * Remove deprecated Settings FX options (rates now live only in Exchange Rate History).
	 */
	public static function cleanup_legacy_fx_options(): void {
		delete_option( 'wc_io_default_usd_to_eur_rate' );
		delete_option( 'wc_io_default_sek_to_eur_rate' );
		delete_option( 'wc_io_default_exchange_rate_date' );
	}

	/**
	 * Create or update custom tables.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();

		$table = $wpdb->prefix . 'wc_io_inventory_movements';
		$sql   = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			movement_type varchar(32) NOT NULL DEFAULT 'purchase',
			quantity_change decimal(19,4) NOT NULL DEFAULT 0,
			unit_cost decimal(19,4) NOT NULL DEFAULT 0,
			total_value decimal(19,4) NOT NULL DEFAULT 0,
			old_stock decimal(19,4) NOT NULL DEFAULT 0,
			new_stock decimal(19,4) NOT NULL DEFAULT 0,
			old_average_unit_cost decimal(19,6) NULL,
			new_average_unit_cost decimal(19,6) NULL,
			old_inventory_value decimal(19,4) NOT NULL DEFAULT 0,
			new_inventory_value decimal(19,4) NOT NULL DEFAULT 0,
			supplier_name text NULL,
			note text NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			reference_type varchar(32) NULL,
			reference_id bigint(20) unsigned NULL,
			supplier_id bigint(20) unsigned NULL,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY variation_id (variation_id),
			KEY movement_type (movement_type),
			KEY created_at (created_at),
			KEY reference (reference_type, reference_id),
			KEY supplier_id (supplier_id)
		) {$collate};";
		dbDelta( $sql );

		// M6: migrated_receipt_id/migrated_at (schema v10) are migration tracking
		// metadata only — see the M6 implementation plan's Schema change section.
		$batches = $wpdb->prefix . 'wc_io_purchase_batches';
		$sql2    = "CREATE TABLE {$batches} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			supplier_name text NULL,
			reference text NULL,
			purchase_currency varchar(3) NOT NULL DEFAULT 'EUR',
			exchange_rate_to_eur decimal(19,8) NOT NULL DEFAULT 1,
			exchange_rate_date date NULL,
			product_subtotal_entered decimal(19,4) NOT NULL DEFAULT 0,
			landed_total_entered decimal(19,4) NOT NULL DEFAULT 0,
			batch_total_entered decimal(19,4) NOT NULL DEFAULT 0,
			product_subtotal decimal(19,4) NOT NULL DEFAULT 0,
			landed_total decimal(19,4) NOT NULL DEFAULT 0,
			batch_total decimal(19,4) NOT NULL DEFAULT 0,
			note text NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			migrated_receipt_id bigint(20) unsigned NULL,
			migrated_at datetime NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY user_id (user_id),
			KEY migrated_receipt_id (migrated_receipt_id)
		) {$collate};";
		dbDelta( $sql2 );

		$blines = $wpdb->prefix . 'wc_io_purchase_batch_lines';
		$sql3   = "CREATE TABLE {$blines} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			quantity decimal(19,4) NOT NULL DEFAULT 0,
			entered_currency varchar(3) NOT NULL DEFAULT 'EUR',
			exchange_rate_to_eur decimal(19,8) NOT NULL DEFAULT 1,
			entered_line_cost decimal(19,4) NOT NULL DEFAULT 0,
			converted_line_cost_eur decimal(19,4) NOT NULL DEFAULT 0,
			base_line_cost decimal(19,4) NOT NULL DEFAULT 0,
			allocated_landed_cost decimal(19,4) NOT NULL DEFAULT 0,
			true_line_cost decimal(19,4) NOT NULL DEFAULT 0,
			true_unit_cost decimal(19,6) NOT NULL DEFAULT 0,
			old_stock decimal(19,4) NOT NULL DEFAULT 0,
			new_stock decimal(19,4) NOT NULL DEFAULT 0,
			old_average_unit_cost decimal(19,6) NULL,
			new_average_unit_cost decimal(19,6) NULL,
			old_inventory_value decimal(19,4) NOT NULL DEFAULT 0,
			new_inventory_value decimal(19,4) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY batch_id (batch_id),
			KEY product_id (product_id),
			KEY variation_id (variation_id)
		) {$collate};";
		dbDelta( $sql3 );

		$bcosts = $wpdb->prefix . 'wc_io_purchase_batch_costs';
		$sql4   = "CREATE TABLE {$bcosts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch_id bigint(20) unsigned NOT NULL DEFAULT 0,
			cost_type varchar(32) NOT NULL DEFAULT '',
			entered_currency varchar(3) NOT NULL DEFAULT 'EUR',
			exchange_rate_to_eur decimal(19,8) NOT NULL DEFAULT 1,
			entered_amount decimal(19,4) NOT NULL DEFAULT 0,
			converted_amount_eur decimal(19,4) NOT NULL DEFAULT 0,
			amount decimal(19,4) NOT NULL DEFAULT 0,
			note text NULL,
			PRIMARY KEY  (id),
			KEY batch_id (batch_id),
			KEY cost_type (cost_type)
		) {$collate};";
		dbDelta( $sql4 );

		$xrates = $wpdb->prefix . 'wc_io_exchange_rates';
		$sql5   = "CREATE TABLE {$xrates} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			from_currency varchar(3) NOT NULL,
			to_currency varchar(3) NOT NULL DEFAULT 'EUR',
			exchange_rate decimal(19,8) NOT NULL,
			rate_date date NOT NULL,
			source_note text NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY currency_date (from_currency, to_currency, rate_date),
			KEY rate_date (rate_date)
		) {$collate};";
		dbDelta( $sql5 );

		$suppliers = $wpdb->prefix . 'wc_io_suppliers';
		$sql6      = "CREATE TABLE {$suppliers} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			normalized_name varchar(191) NOT NULL DEFAULT '',
			default_currency char(3) NOT NULL DEFAULT 'EUR',
			default_lead_time_days int NULL,
			email varchar(190) NULL,
			phone varchar(64) NULL,
			supplier_reference varchar(100) NULL,
			note text NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			merged_into_supplier_id bigint(20) unsigned NULL DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY normalized_name (normalized_name),
			KEY status (status),
			KEY merged_into_supplier_id (merged_into_supplier_id)
		) {$collate};";
		dbDelta( $sql6 );

		// M2: Purchase Orders (schema v7). qty_received (M5, schema v9) added via ALTER below.
		$purchase_orders = $wpdb->prefix . 'wc_io_purchase_orders';
		$sql7            = "CREATE TABLE {$purchase_orders} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			po_number varchar(32) NOT NULL,
			supplier_id bigint(20) unsigned NOT NULL DEFAULT 0,
			supplier_name_snapshot varchar(190) NOT NULL DEFAULT '',
			currency char(3) NOT NULL DEFAULT 'EUR',
			status varchar(20) NOT NULL DEFAULT 'draft',
			order_date date NULL,
			expected_date date NULL,
			expected_confidence varchar(16) NOT NULL DEFAULT 'unknown',
			supplier_reference varchar(100) NULL,
			note text NULL,
			placed_at datetime NULL,
			closed_at datetime NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY po_number (po_number),
			KEY supplier_id (supplier_id),
			KEY status (status),
			KEY expected_date (expected_date),
			KEY status_expected_date (status, expected_date)
		) {$collate};";
		dbDelta( $sql7 );

		$po_lines = $wpdb->prefix . 'wc_io_purchase_order_lines';
		$sql8     = "CREATE TABLE {$po_lines} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			po_id bigint(20) unsigned NOT NULL DEFAULT 0,
			line_index int NOT NULL DEFAULT 0,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sku_snapshot varchar(100) NULL,
			name_snapshot varchar(190) NULL,
			supplier_sku varchar(100) NULL,
			qty_ordered decimal(19,4) NOT NULL DEFAULT 0,
			qty_received decimal(19,4) NOT NULL DEFAULT 0,
			qty_cancelled decimal(19,4) NOT NULL DEFAULT 0,
			unit_cost decimal(19,6) NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT 'EUR',
			expected_date date NULL,
			expected_confidence varchar(16) NULL,
			status varchar(20) NOT NULL DEFAULT 'open',
			note text NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY po_id (po_id),
			KEY product_id (product_id),
			KEY variation_id (variation_id),
			KEY status (status),
			KEY expected_date (expected_date)
		) {$collate};";
		dbDelta( $sql8 );

		$po_events = $wpdb->prefix . 'wc_io_po_events';
		$sql9      = "CREATE TABLE {$po_events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			po_id bigint(20) unsigned NOT NULL DEFAULT 0,
			po_line_id bigint(20) unsigned NULL,
			event_type varchar(40) NOT NULL,
			summary text NULL,
			data longtext NULL,
			reason_code varchar(32) NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY po_id_created_at (po_id, created_at),
			KEY event_type (event_type),
			KEY reason_code (reason_code)
		) {$collate};";
		dbDelta( $sql9 );

		// M4: Goods Receipts (schema v8). Direct receipts only — po_line_id always NULL in M4.
		$goods_receipts = $wpdb->prefix . 'wc_io_goods_receipts';
		$sql10          = "CREATE TABLE {$goods_receipts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			receipt_number varchar(32) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			source varchar(20) NOT NULL DEFAULT 'direct',
			supplier_id bigint(20) unsigned NULL,
			supplier_name_snapshot varchar(190) NULL,
			currency char(3) NOT NULL DEFAULT 'EUR',
			exchange_rate_to_eur decimal(19,8) NOT NULL DEFAULT 1,
			exchange_rate_date date NULL,
			product_subtotal_entered decimal(19,4) NOT NULL DEFAULT 0,
			landed_total_entered decimal(19,4) NOT NULL DEFAULT 0,
			receipt_total_entered decimal(19,4) NOT NULL DEFAULT 0,
			product_subtotal decimal(19,4) NOT NULL DEFAULT 0,
			landed_total decimal(19,4) NOT NULL DEFAULT 0,
			receipt_total decimal(19,4) NOT NULL DEFAULT 0,
			reference varchar(190) NULL,
			note text NULL,
			posted_at datetime NULL,
			posted_by bigint(20) unsigned NULL,
			voided_at datetime NULL,
			voided_by bigint(20) unsigned NULL,
			void_reason text NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY receipt_number (receipt_number),
			KEY status (status),
			KEY supplier_id (supplier_id),
			KEY created_at (created_at)
		) {$collate};";
		dbDelta( $sql10 );

		$receipt_lines = $wpdb->prefix . 'wc_io_receipt_lines';
		$sql11         = "CREATE TABLE {$receipt_lines} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			receipt_id bigint(20) unsigned NOT NULL,
			line_index int NOT NULL DEFAULT 0,
			po_line_id bigint(20) unsigned NULL,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sku_snapshot varchar(100) NULL,
			name_snapshot varchar(190) NULL,
			qty decimal(19,4) NOT NULL DEFAULT 0,
			entered_currency char(3) NOT NULL DEFAULT 'EUR',
			exchange_rate_to_eur decimal(19,8) NOT NULL DEFAULT 1,
			entered_unit_cost decimal(19,6) NOT NULL DEFAULT 0,
			converted_unit_cost_eur decimal(19,6) NOT NULL DEFAULT 0,
			base_line_cost decimal(19,4) NOT NULL DEFAULT 0,
			allocated_landed_cost decimal(19,4) NOT NULL DEFAULT 0,
			true_line_cost decimal(19,4) NOT NULL DEFAULT 0,
			true_unit_cost decimal(19,6) NOT NULL DEFAULT 0,
			old_stock decimal(19,4) NOT NULL DEFAULT 0,
			new_stock decimal(19,4) NOT NULL DEFAULT 0,
			old_average_unit_cost decimal(19,6) NULL,
			new_average_unit_cost decimal(19,6) NOT NULL DEFAULT 0,
			old_inventory_value decimal(19,4) NOT NULL DEFAULT 0,
			new_inventory_value decimal(19,4) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY receipt_id (receipt_id),
			KEY product_id (product_id),
			KEY variation_id (variation_id),
			KEY po_line_id (po_line_id)
		) {$collate};";
		dbDelta( $sql11 );

		$receipt_costs = $wpdb->prefix . 'wc_io_receipt_costs';
		$sql12         = "CREATE TABLE {$receipt_costs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			receipt_id bigint(20) unsigned NOT NULL,
			cost_type varchar(32) NOT NULL DEFAULT '',
			entered_currency char(3) NOT NULL DEFAULT 'EUR',
			exchange_rate_to_eur decimal(19,8) NOT NULL DEFAULT 1,
			entered_amount decimal(19,4) NOT NULL DEFAULT 0,
			converted_amount_eur decimal(19,4) NOT NULL DEFAULT 0,
			amount decimal(19,4) NOT NULL DEFAULT 0,
			note text NULL,
			post_hoc tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY receipt_id (receipt_id),
			KEY cost_type (cost_type)
		) {$collate};";
		dbDelta( $sql12 );

		// M17: Supplier Merges (schema v11). Append-only audit log.
		$supplier_merges = $wpdb->prefix . 'wc_io_supplier_merges';
		$sql13           = "CREATE TABLE {$supplier_merges} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_supplier_id bigint(20) unsigned NOT NULL,
			source_supplier_name_snapshot varchar(190) NOT NULL DEFAULT '',
			target_supplier_id bigint(20) unsigned NOT NULL,
			target_supplier_name_snapshot varchar(190) NOT NULL DEFAULT '',
			purchase_orders_reassigned int(10) unsigned NOT NULL DEFAULT 0,
			goods_receipts_reassigned int(10) unsigned NOT NULL DEFAULT 0,
			performed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY source_supplier_id (source_supplier_id),
			KEY target_supplier_id (target_supplier_id)
		) {$collate};";
		dbDelta( $sql13 );
	}

	/**
	 * Verify expected schema shape for the current DB_VERSION.
	 *
	 * Writes a canonical `wc_io_schema_assertion` option and mirrors it to
	 * `wc_io_schema_v{DB_VERSION}_assertion` so runbook commands and menu gates
	 * stay version-safe across upgrades.
	 *
	 * @return true|WP_Error
	 */
	public static function assert_schema_shape() {
		global $wpdb;

		$version  = self::DB_VERSION;
		$expected = self::expected_schema( $version );
		$missing  = array();

		foreach ( $expected['tables'] as $table_name ) {
			$full_table = $wpdb->prefix . $table_name;
			$exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $full_table ) ) );
			if ( $full_table !== $exists ) {
				$missing[] = "Table missing: {$table_name}";
			}
		}

		if ( ! empty( $expected['columns'] ) ) {
			foreach ( $expected['columns'] as $short_name => $column_names ) {
				$full_table   = $wpdb->prefix . 'wc_io_' . $short_name;
				$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $full_table ) ) );
				if ( $full_table !== $table_exists ) {
					continue;
				}
				$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$full_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$col_map = array_column( $columns, 'Field' );
				foreach ( $column_names as $col_name ) {
					if ( ! in_array( $col_name, $col_map, true ) ) {
						$missing[] = "Column missing on {$full_table}: {$col_name}";
					}
				}
			}
		}

		if ( ! empty( $expected['forbidden_columns'] ) ) {
			foreach ( $expected['forbidden_columns'] as $short_name => $forbidden ) {
				$full_table   = $wpdb->prefix . 'wc_io_' . $short_name;
				$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $full_table ) ) );
				if ( $full_table !== $table_exists ) {
					continue;
				}
				$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$full_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$col_map = array_column( $columns, 'Field' );
				foreach ( $forbidden as $col_name ) {
					if ( in_array( $col_name, $col_map, true ) ) {
						$missing[] = "Forbidden receiving column present on {$full_table}: {$col_name}";
					}
				}
			}
		}

		$suppliers_table = $wpdb->prefix . 'wc_io_suppliers';
		if ( $suppliers_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $suppliers_table ) ) ) ) {
			$indexes = $wpdb->get_results( "SHOW INDEX FROM {$suppliers_table} WHERE Column_name='normalized_name' AND Non_unique=0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( empty( $indexes ) ) {
				$missing[] = "Unique index missing on {$suppliers_table}.normalized_name";
			}
		}

		$po_table = $wpdb->prefix . 'wc_io_purchase_orders';
		if ( version_compare( $version, '7', '>=' ) && $po_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $po_table ) ) ) ) {
			$indexes = $wpdb->get_results( "SHOW INDEX FROM {$po_table} WHERE Column_name='po_number' AND Non_unique=0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( empty( $indexes ) ) {
				$missing[] = "Unique index missing on {$po_table}.po_number";
			}
		}

		$receipts_table = $wpdb->prefix . 'wc_io_goods_receipts';
		if ( version_compare( $version, '8', '>=' ) && $receipts_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $receipts_table ) ) ) ) {
			$indexes = $wpdb->get_results( "SHOW INDEX FROM {$receipts_table} WHERE Column_name='receipt_number' AND Non_unique=0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( empty( $indexes ) ) {
				$missing[] = "Unique index missing on {$receipts_table}.receipt_number";
			}
		}

		$payload = array(
			'ok'         => empty( $missing ),
			'version'    => $version,
			'checked_at' => current_time( 'mysql', true ),
		);
		if ( ! empty( $missing ) ) {
			$payload['missing'] = $missing;
		}

		update_option( 'wc_io_schema_assertion', $payload );
		update_option( 'wc_io_schema_v' . $version . '_assertion', $payload );

		// Preserve the legacy v6 option name so existing runbook snippets keep working
		// even after the canonical option becomes the primary gate.
		if ( '6' === $version || version_compare( $version, '6', '>=' ) ) {
			update_option( 'wc_io_schema_v6_assertion', $payload );
		}

		if ( ! empty( $missing ) ) {
			return new WP_Error( 'wc_io_schema_v' . $version, implode( '; ', $missing ) );
		}

		return true;
	}

	/**
	 * Dispatch expected schema definition by version.
	 *
	 * @param string $version DB version string.
	 * @return array{tables:array<int,string>,columns:array<string,array<int,string>>,forbidden_columns?:array<string,array<int,string>>}
	 */
	private static function expected_schema( $version ) {
		if ( version_compare( (string) $version, '11', '>=' ) ) {
			return self::expected_schema_v11();
		}
		if ( version_compare( (string) $version, '10', '>=' ) ) {
			return self::expected_schema_v10();
		}
		if ( version_compare( (string) $version, '9', '>=' ) ) {
			return self::expected_schema_v9();
		}
		if ( version_compare( (string) $version, '8', '>=' ) ) {
			return self::expected_schema_v8();
		}
		if ( version_compare( (string) $version, '7', '>=' ) ) {
			return self::expected_schema_v7();
		}
		return self::expected_schema_v6();
	}

	/**
	 * Expected schema shape for DB version 6.
	 *
	 * @return array{tables:array<int,string>,columns:array<string,array<int,string>>}
	 */
	private static function expected_schema_v6() {
		return array(
			'tables'  => array(
				'wc_io_inventory_movements',
				'wc_io_purchase_batches',
				'wc_io_purchase_batch_lines',
				'wc_io_purchase_batch_costs',
				'wc_io_exchange_rates',
				'wc_io_suppliers',
			),
			'columns' => array(
				'suppliers' => array(
					'id',
					'name',
					'normalized_name',
					'default_currency',
					'default_lead_time_days',
					'email',
					'phone',
					'supplier_reference',
					'note',
					'status',
					'created_at',
					'updated_at',
				),
			),
		);
	}

	/**
	 * Expected schema shape for DB version 7 (Purchase Orders, M2).
	 *
	 * Intentionally excludes receiving columns (qty_received) — those arrive in M5.
	 *
	 * @return array{tables:array<int,string>,columns:array<string,array<int,string>>,forbidden_columns:array<string,array<int,string>>}
	 */
	private static function expected_schema_v7() {
		$base = self::expected_schema_v6();

		$base['tables'][] = 'wc_io_purchase_orders';
		$base['tables'][] = 'wc_io_purchase_order_lines';
		$base['tables'][] = 'wc_io_po_events';

		$base['columns']['purchase_orders'] = array(
			'id',
			'po_number',
			'supplier_id',
			'supplier_name_snapshot',
			'currency',
			'status',
			'order_date',
			'expected_date',
			'expected_confidence',
			'supplier_reference',
			'note',
			'placed_at',
			'closed_at',
			'created_by',
			'updated_by',
			'created_at',
			'updated_at',
		);

		$base['columns']['purchase_order_lines'] = array(
			'id',
			'po_id',
			'line_index',
			'product_id',
			'variation_id',
			'sku_snapshot',
			'name_snapshot',
			'supplier_sku',
			'qty_ordered',
			'qty_cancelled',
			'unit_cost',
			'currency',
			'expected_date',
			'expected_confidence',
			'status',
			'note',
			'created_at',
			'updated_at',
		);

		$base['columns']['po_events'] = array(
			'id',
			'po_id',
			'po_line_id',
			'event_type',
			'summary',
			'data',
			'reason_code',
			'user_id',
			'created_at',
		);

		$base['forbidden_columns'] = array(
			'purchase_order_lines' => array( 'qty_received' ),
			'purchase_orders'      => array(),
		);

		return $base;
	}

	/**
	 * Expected schema shape for DB version 8 (Goods Receipts, M4).
	 *
	 * Direct receipts only — po_line_id exists on wc_io_receipt_lines but is never
	 * populated by M4 code. The M2 forbidden-column guard for qty_received on
	 * wc_io_purchase_order_lines carries forward unchanged.
	 *
	 * @return array{tables:array<int,string>,columns:array<string,array<int,string>>,forbidden_columns:array<string,array<int,string>>}
	 */
	private static function expected_schema_v8() {
		$base = self::expected_schema_v7();

		$base['tables'][] = 'wc_io_goods_receipts';
		$base['tables'][] = 'wc_io_receipt_lines';
		$base['tables'][] = 'wc_io_receipt_costs';

		$base['columns']['goods_receipts'] = array(
			'id',
			'receipt_number',
			'status',
			'source',
			'supplier_id',
			'supplier_name_snapshot',
			'currency',
			'exchange_rate_to_eur',
			'exchange_rate_date',
			'product_subtotal_entered',
			'landed_total_entered',
			'receipt_total_entered',
			'product_subtotal',
			'landed_total',
			'receipt_total',
			'reference',
			'note',
			'posted_at',
			'posted_by',
			'voided_at',
			'voided_by',
			'void_reason',
			'created_by',
			'updated_by',
			'created_at',
			'updated_at',
		);

		$base['columns']['receipt_lines'] = array(
			'id',
			'receipt_id',
			'line_index',
			'po_line_id',
			'product_id',
			'variation_id',
			'sku_snapshot',
			'name_snapshot',
			'qty',
			'entered_currency',
			'exchange_rate_to_eur',
			'entered_unit_cost',
			'converted_unit_cost_eur',
			'base_line_cost',
			'allocated_landed_cost',
			'true_line_cost',
			'true_unit_cost',
			'old_stock',
			'new_stock',
			'old_average_unit_cost',
			'new_average_unit_cost',
			'old_inventory_value',
			'new_inventory_value',
			'created_at',
		);

		$base['columns']['receipt_costs'] = array(
			'id',
			'receipt_id',
			'cost_type',
			'entered_currency',
			'exchange_rate_to_eur',
			'entered_amount',
			'converted_amount_eur',
			'amount',
			'note',
			'post_hoc',
		);

		// inventory_movements is pre-existing (v-anything); v8 is the first version to assert its columns,
		// since the ALTER that adds these three columns is itself a v8 change.
		$base['columns']['inventory_movements'] = array(
			'reference_type',
			'reference_id',
			'supplier_id',
		);

		$base['forbidden_columns']['purchase_order_lines'] = array( 'qty_received' );
		$base['forbidden_columns']['purchase_orders']      = array();

		return $base;
	}

	/**
	 * Expected schema shape for DB version 9 (PO Receiving, M5).
	 *
	 * The qty_received column on wc_io_purchase_order_lines becomes a real,
	 * maintained field here (full INV-4 formula). The M2/M4 forbidden-column
	 * guard for qty_received is removed here — this is the one forbidden_columns
	 * entry M5 is permitted to change. No new tables are added;
	 * wc_io_receipt_lines.po_line_id and wc_io_goods_receipts.source were already
	 * schema-ready from M4 and require no further change.
	 *
	 * @return array{tables:array<int,string>,columns:array<string,array<int,string>>,forbidden_columns:array<string,array<int,string>>}
	 */
	private static function expected_schema_v9() {
		$base = self::expected_schema_v8();

		$base['columns']['purchase_order_lines'][] = 'qty_received';

		$base['forbidden_columns']['purchase_order_lines'] = array();

		return $base;
	}

	/**
	 * Expected schema shape for DB version 10 (Migration & Retirement, M6).
	 *
	 * Adds migration-tracking-only columns to wc_io_purchase_batches
	 * (migrated_receipt_id, migrated_at). No new business-domain schema —
	 * the migrated data fits M4's existing Goods Receipt shape exactly (see
	 * the M6 implementation plan's Schema change section for why this bump
	 * exists at all). wc_io_purchase_batches' full column list was never
	 * previously asserted; only the two v10 additions are asserted here,
	 * matching the minimal-diff style v9 used for qty_received.
	 *
	 * @return array{tables:array<int,string>,columns:array<string,array<int,string>>,forbidden_columns:array<string,array<int,string>>}
	 */
	private static function expected_schema_v10() {
		$base = self::expected_schema_v9();

		$base['columns']['purchase_batches'] = array(
			'migrated_receipt_id',
			'migrated_at',
		);

		return $base;
	}

	/**
	 * Expected schema shape for DB version 11 (Supplier Merge, M17).
	 *
	 * Adds merged_into_supplier_id column to wc_io_suppliers and creates
	 * the new wc_io_supplier_merges append-only audit table. See the M17
	 * implementation plan's Schema change section for full details.
	 *
	 * @return array{tables:array<int,string>,columns:array<string,array<int,string>>,forbidden_columns:array<string,array<int,string>>}
	 */
	private static function expected_schema_v11() {
		$base = self::expected_schema_v10();

		$base['tables'][] = 'wc_io_supplier_merges';

		$base['columns']['suppliers'][] = 'merged_into_supplier_id';

		return $base;
	}
}
