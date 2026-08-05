<?php
/**
 * Supplier service: CRUD + repository + normalization.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Supplier entity service.
 */
class WC_Inventory_Overview_Suppliers {

	const STATUS_ACTIVE   = 'active';
	const STATUS_ARCHIVED = 'archived';

	/**
	 * Get table name (prefixed).
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_io_suppliers';
	}

	/**
	 * Normalize supplier name: whitespace collapse + trim + casefold.
	 * No punctuation stripping, no suffix stripping, no accent folding.
	 */
	public static function normalize_name( string $raw ): string {
		$normalized = $raw;
		$normalized = preg_replace( '/[\s\x{00A0}]+/u', ' ', $normalized );
		$normalized = trim( $normalized );
		if ( function_exists( 'mb_strtolower' ) ) {
			$normalized = mb_strtolower( $normalized, 'UTF-8' );
		} else {
			$normalized = strtolower( $normalized );
		}
		$normalized = mb_substr( $normalized, 0, 191 );
		return $normalized;
	}

	/**
	 * Get supplier by ID. Returns WP_Error if not found.
	 */
	public static function get( int $id ) {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'wc_io_supplier_not_found', "Supplier {$id} not found" );
		}
		return $row;
	}

	/**
	 * Get supplier by normalized name. Returns null if not found (for dedupe checks).
	 */
	public static function get_by_normalized_name( string $normalized ) {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE normalized_name = %s", $normalized ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * List suppliers with filters and pagination.
	 *
	 * @param array{status?:string,search?:string,orderby?:string,order?:string,per_page?:int,offset?:int} $args
	 * @return array<int,array<string,mixed>>
	 */
	public static function list( array $args = array() ): array {
		global $wpdb;
		$table = self::table_name();

		$status   = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : '';
		$search   = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';
		$orderby  = isset( $args['orderby'] ) ? sanitize_key( $args['orderby'] ) : 'name';
		$order    = isset( $args['order'] ) ? strtoupper( sanitize_key( $args['order'] ) ) : 'ASC';
		$per_page = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 20;
		$offset   = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

		$where = '1=1';
		if ( $status ) {
			$where .= $wpdb->prepare( ' AND status = %s', $status );
		}
		if ( $search ) {
			$where .= $wpdb->prepare( ' AND name LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
		}

		$order = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'ASC';

		$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		return $wpdb->get_results( $wpdb->prepare( $sql, $per_page, $offset ), ARRAY_A );
	}

	/**
	 * Count suppliers (respects filters but not pagination).
	 */
	public static function count( array $args = array() ): int {
		global $wpdb;
		$table = self::table_name();

		$status = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : '';
		$search = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';

		$where = '1=1';
		if ( $status ) {
			$where .= $wpdb->prepare( ' AND status = %s', $status );
		}
		if ( $search ) {
			$where .= $wpdb->prepare( ' AND name LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
		}

		$sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		return absint( $wpdb->get_var( $sql ) );
	}

	/**
	 * Create a new supplier. Returns ID or WP_Error.
	 */
	public static function create( array $data ) {
		$validated = self::validate( $data );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		global $wpdb;
		$table = self::table_name();

		$insert_data = array(
			'name'                    => $validated['name'],
			'normalized_name'         => $validated['normalized_name'],
			'default_currency'        => $validated['default_currency'],
			'default_lead_time_days'  => $validated['default_lead_time_days'],
			'email'                   => $validated['email'],
			'phone'                   => $validated['phone'],
			'supplier_reference'      => $validated['supplier_reference'],
			'note'                    => $validated['note'],
			'status'                  => self::STATUS_ACTIVE,
			'created_at'              => current_time( 'mysql', true ),
			'updated_at'              => current_time( 'mysql', true ),
		);

		$inserted = $wpdb->insert( $table, $insert_data );
		if ( false === $inserted ) {
			if ( false !== strpos( $wpdb->last_error, 'Duplicate' ) ) {
				return new WP_Error( 'wc_io_supplier_duplicate', sprintf( 'A supplier with normalized name "%s" already exists.', $validated['normalized_name'] ) );
			}
			return new WP_Error( 'wc_io_supplier_db', 'Database error: ' . $wpdb->last_error );
		}

		$new_id = $wpdb->insert_id;
		$row    = self::get( $new_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		do_action( 'wc_io_supplier_created', $new_id, $row );
		return $new_id;
	}

	/**
	 * Update an existing supplier. Returns true or WP_Error.
	 */
	public static function update( int $id, array $data ) {
		$existing = self::get( $id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$validated = self::validate( $data, $existing );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		global $wpdb;
		$table = self::table_name();

		$update_data = array(
			'name'                   => $validated['name'],
			'normalized_name'        => $validated['normalized_name'],
			'default_currency'       => $validated['default_currency'],
			'default_lead_time_days' => $validated['default_lead_time_days'],
			'email'                  => $validated['email'],
			'phone'                  => $validated['phone'],
			'supplier_reference'     => $validated['supplier_reference'],
			'note'                   => $validated['note'],
			'updated_at'             => current_time( 'mysql', true ),
		);

		$updated = $wpdb->update( $table, $update_data, array( 'id' => $id ) );
		if ( false === $updated ) {
			if ( false !== strpos( $wpdb->last_error, 'Duplicate' ) ) {
				return new WP_Error( 'wc_io_supplier_duplicate', sprintf( 'A supplier with normalized name "%s" already exists.', $validated['normalized_name'] ) );
			}
			return new WP_Error( 'wc_io_supplier_db', 'Database error: ' . $wpdb->last_error );
		}

		return true;
	}

	/**
	 * Archive a supplier (idempotent).
	 */
	public static function archive( int $id ): bool {
		$existing = self::get( $id );
		if ( is_wp_error( $existing ) ) {
			return false;
		}

		if ( self::STATUS_ARCHIVED === $existing['status'] ) {
			return true;
		}

		global $wpdb;
		$table = self::table_name();

		$updated = $wpdb->update(
			$table,
			array(
				'status'     => self::STATUS_ARCHIVED,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);

		return false !== $updated;
	}

	/**
	 * Reactivate an archived supplier (idempotent).
	 */
	public static function reactivate( int $id ): bool {
		$existing = self::get( $id );
		if ( is_wp_error( $existing ) ) {
			return false;
		}

		if ( self::STATUS_ACTIVE === $existing['status'] ) {
			return true;
		}

		global $wpdb;
		$table = self::table_name();

		$updated = $wpdb->update(
			$table,
			array(
				'status'     => self::STATUS_ACTIVE,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);

		return false !== $updated;
	}

	/**
	 * Validate supplier data. Requires 'name' and 'default_currency'; others optional.
	 */
	private static function validate( array $data, array $existing = null ) {
		$name = isset( $data['name'] ) ? trim( sanitize_text_field( $data['name'] ) ) : '';
		if ( ! $name || strlen( $name ) > 190 ) {
			return new WP_Error( 'wc_io_supplier_name', 'Supplier name is required and must be <= 190 characters.' );
		}

		$currency = isset( $data['default_currency'] ) ? strtoupper( sanitize_key( $data['default_currency'] ) ) : 'EUR';
		$valid_currencies = array( 'EUR', 'USD', 'SEK' );
		if ( ! in_array( $currency, $valid_currencies, true ) ) {
			return new WP_Error( 'wc_io_supplier_currency', 'Invalid currency. Must be EUR, USD, or SEK.' );
		}

		$normalized = self::normalize_name( $name );

		if ( $existing && $existing['normalized_name'] !== $normalized ) {
			$duplicate = self::get_by_normalized_name( $normalized );
			if ( $duplicate ) {
				return new WP_Error( 'wc_io_supplier_duplicate', sprintf( 'A supplier named "%s" already exists (as "%s"). Choose a different name or edit the existing supplier.', $name, $duplicate['name'] ) );
			}
		} elseif ( ! $existing ) {
			$duplicate = self::get_by_normalized_name( $normalized );
			if ( $duplicate ) {
				return new WP_Error( 'wc_io_supplier_duplicate', sprintf( 'A supplier named "%s" already exists (as "%s"). Choose a different name or edit the existing supplier.', $name, $duplicate['name'] ) );
			}
		}

		$lead_time = isset( $data['default_lead_time_days'] ) ? $data['default_lead_time_days'] : null;
		if ( '' !== $lead_time && null !== $lead_time ) {
			$lead_time = absint( $lead_time );
		} else {
			$lead_time = null;
		}

		$email = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
		if ( $email && ! is_email( $email ) ) {
			return new WP_Error( 'wc_io_supplier_email', 'Invalid email address.' );
		}
		$email = $email ?: null;

		$phone = isset( $data['phone'] ) ? trim( sanitize_text_field( $data['phone'] ) ) : '';
		if ( $phone && strlen( $phone ) > 64 ) {
			return new WP_Error( 'wc_io_supplier_phone', 'Phone must be <= 64 characters.' );
		}
		$phone = $phone ?: null;

		$ref = isset( $data['supplier_reference'] ) ? trim( sanitize_text_field( $data['supplier_reference'] ) ) : '';
		if ( $ref && strlen( $ref ) > 100 ) {
			return new WP_Error( 'wc_io_supplier_reference', 'Supplier reference must be <= 100 characters.' );
		}
		$ref = $ref ?: null;

		$note = isset( $data['note'] ) ? sanitize_textarea_field( $data['note'] ) : '';
		$note = $note ?: null;

		return array(
			'name'                   => $name,
			'normalized_name'        => $normalized,
			'default_currency'       => $currency,
			'default_lead_time_days' => $lead_time,
			'email'                  => $email,
			'phone'                  => $phone,
			'supplier_reference'     => $ref,
			'note'                   => $note,
		);
	}
}
