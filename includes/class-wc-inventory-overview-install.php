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

	const DB_VERSION = '5';

	/**
	 * Register activation hook target.
	 */
	public static function activate() {
		self::create_tables();
		self::cleanup_legacy_fx_options();
		update_option( 'wc_io_db_version', self::DB_VERSION );
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
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY variation_id (variation_id),
			KEY movement_type (movement_type),
			KEY created_at (created_at)
		) {$collate};";
		dbDelta( $sql );

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
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY user_id (user_id)
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
	}
}
