<?php
/**
 * Purchase Order events repository — append-only (M2-C).
 *
 * Public mutation surface is add() only. There is no update() or delete() API.
 *
 * Draft hard-delete (before placement) may remove a draft's event rows via the
 * narrowly named delete_for_draft_aggregate() method, which exists solely so
 * PO_Service::delete_draft() can remove the aggregate as one transaction without
 * weakening append-only semantics for placed/terminal POs. Placed and terminal
 * PO events are never deleted.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Append-only PO event log.
 */
class WC_Inventory_Overview_PO_Events {

	/**
	 * Event types written by M2.
	 */
	const TYPE_CREATED               = 'po_created';
	const TYPE_DUPLICATED            = 'po_duplicated';
	const TYPE_EDITED                = 'po_edited';
	const TYPE_PLACED                = 'po_placed';
	const TYPE_CANCELLED             = 'po_cancelled';
	const TYPE_CLOSED_SHORT          = 'po_closed_short';
	const TYPE_EXPECTED_DATE_CHANGED = 'po_expected_date_changed';
	const TYPE_CONFIDENCE_CHANGED    = 'po_confidence_changed';
	const TYPE_LINE_ADDED            = 'po_line_added';
	const TYPE_LINE_CHANGED          = 'po_line_changed';
	const TYPE_LINE_REMOVED          = 'po_line_removed';
	const TYPE_LINE_CANCELLED        = 'po_line_cancelled';

	/**
	 * Prefixed table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_io_po_events';
	}

	/**
	 * Known M2 event types.
	 *
	 * @return array<int,string>
	 */
	public static function known_types(): array {
		return array(
			self::TYPE_CREATED,
			self::TYPE_DUPLICATED,
			self::TYPE_EDITED,
			self::TYPE_PLACED,
			self::TYPE_CANCELLED,
			self::TYPE_CLOSED_SHORT,
			self::TYPE_EXPECTED_DATE_CHANGED,
			self::TYPE_CONFIDENCE_CHANGED,
			self::TYPE_LINE_ADDED,
			self::TYPE_LINE_CHANGED,
			self::TYPE_LINE_REMOVED,
			self::TYPE_LINE_CANCELLED,
		);
	}

	/**
	 * Append an event. Never updates or deletes existing rows.
	 *
	 * @param array{po_id:int,event_type:string,summary?:string,data?:array|string|null,po_line_id?:int|null,reason_code?:string|null,user_id?:int} $args Event payload.
	 * @return int|WP_Error New event id.
	 */
	public static function add( array $args ) {
		global $wpdb;

		$po_id = isset( $args['po_id'] ) ? absint( $args['po_id'] ) : 0;
		if ( $po_id <= 0 ) {
			return new WP_Error( 'wc_io_po_event_po_id', 'po_id is required' );
		}

		$event_type = isset( $args['event_type'] ) ? sanitize_key( (string) $args['event_type'] ) : '';
		if ( '' === $event_type ) {
			return new WP_Error( 'wc_io_po_event_type', 'event_type is required' );
		}

		$reason_code = array_key_exists( 'reason_code', $args ) ? $args['reason_code'] : null;
		$validated   = WC_Inventory_Overview_PO_Reason_Codes::validate( $reason_code );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( null === $reason_code || '' === $reason_code ) {
			$reason_code = null;
		} else {
			$reason_code = (string) $reason_code;
		}

		$data = null;
		if ( array_key_exists( 'data', $args ) && null !== $args['data'] ) {
			if ( is_array( $args['data'] ) ) {
				$encoded = wp_json_encode( $args['data'] );
				if ( false === $encoded ) {
					return new WP_Error( 'wc_io_po_event_json', 'Failed to encode event data as JSON' );
				}
				$data = $encoded;
			} else {
				$data = (string) $args['data'];
			}
		}

		$row     = array(
			'po_id'      => $po_id,
			'event_type' => $event_type,
			'summary'    => isset( $args['summary'] ) ? sanitize_textarea_field( (string) $args['summary'] ) : null,
			'data'       => $data,
			'user_id'    => isset( $args['user_id'] ) ? absint( $args['user_id'] ) : get_current_user_id(),
			'created_at' => current_time( 'mysql', true ),
		);
		$formats = array( '%d', '%s', '%s', '%s', '%d', '%s' );

		if ( isset( $args['po_line_id'] ) && null !== $args['po_line_id'] && '' !== $args['po_line_id'] ) {
			$row['po_line_id'] = absint( $args['po_line_id'] );
			$formats[]         = '%d';
		}
		if ( null !== $reason_code ) {
			$row['reason_code'] = $reason_code;
			$formats[]          = '%s';
		}

		$inserted = $wpdb->insert( self::table_name(), $row, $formats );
		if ( false === $inserted ) {
			return new WP_Error( 'wc_io_po_event_insert_failed', 'Failed to insert PO event', array( 'db_error' => $wpdb->last_error ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * List events for a PO, newest first.
	 *
	 * @param int $po_id PO id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_for_po( int $po_id ): array {
		global $wpdb;
		$table = self::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE po_id = %d ORDER BY created_at DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$po_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Alias for list_for_po().
	 *
	 * @param int $po_id PO id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_by_po( int $po_id ): array {
		return self::list_for_po( $po_id );
	}

	/**
	 * Count events for a PO.
	 *
	 * @param int $po_id PO id.
	 */
	public static function count_by_po( int $po_id ): int {
		global $wpdb;
		$table = self::table_name();
		return absint(
			$wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE po_id = %d", $po_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);
	}

	/**
	 * Latest event for a PO (newest first), or null.
	 *
	 * @param int         $po_id      PO id.
	 * @param string|null $event_type Optional type filter.
	 * @return array<string,mixed>|null
	 */
	public static function get_latest( int $po_id, $event_type = null ) {
		global $wpdb;
		$table = self::table_name();

		if ( null !== $event_type && '' !== $event_type ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE po_id = %d AND event_type = %s ORDER BY created_at DESC, id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$po_id,
					sanitize_key( (string) $event_type )
				),
				ARRAY_A
			);
		} else {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE po_id = %d ORDER BY created_at DESC, id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$po_id
				),
				ARRAY_A
			);
		}

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get a single event by id.
	 *
	 * @param int $id Event id.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get( int $id ) {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return new WP_Error( 'wc_io_po_event_not_found', sprintf( 'PO event %d not found', $id ) );
		}
		return $row;
	}

	/**
	 * Remove all events belonging to a draft PO during aggregate hard-delete.
	 *
	 * This is not a generic delete API. PO_Service::delete_draft() is the only
	 * intended caller, and only after verifying the PO is still draft. Placed
	 * and terminal PO events must never be removed.
	 *
	 * @param int $po_id Draft PO id.
	 * @return true|WP_Error
	 */
	public static function delete_for_draft_aggregate( int $po_id ) {
		global $wpdb;

		$po_id = absint( $po_id );
		if ( $po_id <= 0 ) {
			return new WP_Error( 'wc_io_po_event_po_id', 'po_id is required' );
		}

		$result = $wpdb->delete( self::table_name(), array( 'po_id' => $po_id ), array( '%d' ) );
		if ( false === $result ) {
			return new WP_Error( 'wc_io_po_event_draft_cleanup_failed', 'Failed to remove draft PO events', array( 'db_error' => $wpdb->last_error ) );
		}

		return true;
	}
}
