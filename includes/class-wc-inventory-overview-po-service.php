<?php
/**
 * Purchase Order application service — sole mutation entry point (M2-C).
 *
 * Every successful business mutation and its event persist in the same database
 * transaction. Public hooks fire only after a successful commit.
 *
 * Lifecycle eligibility: WC_Inventory_Overview_PO_Lifecycle only.
 * Validation: M2-B validation classes only.
 * Stock / costing / inventory movements: never mutated.
 *
 * Header event strategy (one event per logical operation):
 * - only expected_date changed → po_expected_date_changed
 * - only expected_confidence changed → po_confidence_changed
 * - otherwise → po_edited
 *
 * Header reason-code inference:
 * - supplier_id or supplier_reference → supplier_change
 * - expected_date or expected_confidence → schedule_change
 * - both supplier and schedule categories → other
 * - other header fields alone → other (or explicit manual/other when supplied)
 *
 * Line mixed-change reason-code policy (deterministic):
 * - exactly one of {price_change, quantity_change, schedule_change} → that code
 * - two or more of those categories → other
 * - note/supplier_sku-only → other
 *
 * Draft deletion vs append-only events:
 * - delete_draft() may remove the draft aggregate and its events in one
 *   transaction via PO_Events::delete_for_draft_aggregate() before placement
 * - the event repository exposes no generic update/delete API
 * - placed/terminal PO events are never deleted
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * PO application service.
 */
class WC_Inventory_Overview_PO_Service {

	/**
	 * Header fields that may be edited on draft/placed POs.
	 *
	 * @var array<int,string>
	 */
	private static $header_edit_fields = array(
		'supplier_id',
		'currency',
		'order_date',
		'expected_date',
		'expected_confidence',
		'supplier_reference',
		'note',
	);

	/**
	 * Line fields that may be edited.
	 *
	 * @var array<int,string>
	 */
	private static $line_edit_fields = array(
		'product_id',
		'variation_id',
		'supplier_sku',
		'qty_ordered',
		'unit_cost',
		'expected_date',
		'expected_confidence',
		'note',
	);

	/**
	 * Create a draft PO with optional lines in one transaction.
	 *
	 * @param array<string,mixed>            $header Header fields.
	 * @param array<int,array<string,mixed>> $lines  Optional line payloads.
	 * @return int|WP_Error New PO id.
	 */
	public static function create_draft( array $header, array $lines = array() ) {
		global $wpdb;

		$supplier_id = isset( $header['supplier_id'] ) ? absint( $header['supplier_id'] ) : 0;
		if ( ! $supplier_id ) {
			return new WP_Error( 'wc_io_po_supplier', 'A resolvable supplier is required' );
		}

		// M17: Start transaction early to lock supplier row.
		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );
		if ( ! $txn->begin() ) {
			return new WP_Error( 'wc_io_po_txn', 'Failed to begin transaction' );
		}

		// M17: Lock supplier row and validate eligibility (BR-M17-18).
		$supplier = WC_Inventory_Overview_Suppliers::get_for_update( $supplier_id );
		if ( is_wp_error( $supplier ) ) {
			$txn->rollback();
			return $supplier;
		}
		if ( WC_Inventory_Overview_Suppliers::STATUS_ACTIVE !== $supplier['status'] || ! empty( $supplier['merged_into_supplier_id'] ) ) {
			$txn->rollback();
			return new WP_Error( 'wc_io_po_supplier_inactive', 'Supplier must be active and not merged' );
		}

		$header['status']                 = WC_Inventory_Overview_PO_Statuses::DRAFT;
		$header['supplier_name_snapshot'] = isset( $header['supplier_name_snapshot'] )
			? (string) $header['supplier_name_snapshot']
			: (string) $supplier['name'];
		if ( empty( $header['currency'] ) && ! empty( $supplier['default_currency'] ) ) {
			$header['currency'] = $supplier['default_currency'];
		}

		$header_ok = WC_Inventory_Overview_PO_Validation::validate_header( $header );
		if ( is_wp_error( $header_ok ) ) {
			$txn->rollback();
			return $header_ok;
		}

		$prepared_lines = array();
		foreach ( $lines as $index => $line_input ) {
			$prepared = self::prepare_line_input( $line_input, (string) $header['currency'], true );
			if ( is_wp_error( $prepared ) ) {
				$txn->rollback();
				return new WP_Error(
					$prepared->get_error_code(),
					sprintf( 'Line %d: %s', $index + 1, $prepared->get_error_message() )
				);
			}
			$prepared_lines[] = $prepared;
		}

		$po_id = WC_Inventory_Overview_Purchase_Orders::create_draft( $header );
		if ( is_wp_error( $po_id ) ) {
			$txn->rollback();
			return $po_id;
		}

		$line_ids = array();
		foreach ( $prepared_lines as $prepared ) {
			$line_id = WC_Inventory_Overview_Purchase_Order_Lines::create( $po_id, $prepared );
			if ( is_wp_error( $line_id ) ) {
				$txn->rollback();
				return $line_id;
			}
			$line_ids[] = $line_id;
		}

		$event = WC_Inventory_Overview_PO_Events::add(
			array(
				'po_id'      => $po_id,
				'event_type' => WC_Inventory_Overview_PO_Events::TYPE_CREATED,
				'summary'    => 'Purchase order draft created',
				'data'       => array(
					'line_count' => count( $line_ids ),
					'line_ids'   => $line_ids,
				),
			)
		);
		if ( is_wp_error( $event ) ) {
			$txn->rollback();
			return $event;
		}

		if ( ! $txn->commit() ) {
			$txn->rollback();
			return new WP_Error( 'wc_io_po_txn', 'Failed to commit transaction' );
		}

		$po = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		/**
		 * Fires after a purchase order draft is created and committed.
		 *
		 * @param int                  $po_id New PO id.
		 * @param array<string,mixed>  $po    PO header row.
		 */
		do_action( 'wc_io_purchase_order_created', $po_id, $po );

		return $po_id;
	}

	/**
	 * Update a draft PO header.
	 *
	 * @param int                 $id      PO id.
	 * @param array<string,mixed> $data    Changed fields.
	 * @param array<string,mixed> $options Optional reason_code override.
	 * @return array<string,mixed>|WP_Error Updated PO.
	 */
	public static function update_draft( int $id, array $data, array $options = array() ) {
		return self::update_header( $id, $data, WC_Inventory_Overview_PO_Statuses::DRAFT, $options );
	}

	/**
	 * Update a placed PO header.
	 *
	 * @param int                 $id      PO id.
	 * @param array<string,mixed> $data    Changed fields.
	 * @param array<string,mixed> $options Optional reason_code override.
	 * @return array<string,mixed>|WP_Error Updated PO.
	 */
	public static function update_placed( int $id, array $data, array $options = array() ) {
		return self::update_header( $id, $data, WC_Inventory_Overview_PO_Statuses::PLACED, $options );
	}

	/**
	 * Add a line to a draft or placed PO.
	 *
	 * @param int                 $po_id PO id.
	 * @param array<string,mixed> $data  Line fields.
	 * @return int|WP_Error New line id.
	 */
	public static function add_line( int $po_id, array $data ) {
		global $wpdb;

		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );
		if ( ! $txn->begin() ) {
			return new WP_Error( 'wc_io_po_txn', 'Failed to begin transaction' );
		}

		$po = WC_Inventory_Overview_Purchase_Orders::get_for_update( $po_id );
		if ( is_wp_error( $po ) ) {
			$txn->rollback();
			return $po;
		}

		$editable = WC_Inventory_Overview_PO_Lifecycle::assert_editable( $po['status'] );
		if ( is_wp_error( $editable ) ) {
			$txn->rollback();
			return $editable;
		}

		$prepared = self::prepare_line_input( $data, (string) $po['currency'], true );
		if ( is_wp_error( $prepared ) ) {
			$txn->rollback();
			return $prepared;
		}

		$line_id = WC_Inventory_Overview_Purchase_Order_Lines::create( $po_id, $prepared );
		if ( is_wp_error( $line_id ) ) {
			$txn->rollback();
			return $line_id;
		}

		$event = WC_Inventory_Overview_PO_Events::add(
			array(
				'po_id'      => $po_id,
				'po_line_id' => $line_id,
				'event_type' => WC_Inventory_Overview_PO_Events::TYPE_LINE_ADDED,
				'summary'    => sprintf( 'Line added for product %d', (int) $prepared['product_id'] ),
				'data'       => array(
					'line_id'     => $line_id,
					'product_id'  => (int) $prepared['product_id'],
					'qty_ordered' => (float) $prepared['qty_ordered'],
					'unit_cost'   => (float) $prepared['unit_cost'],
				),
			)
		);
		if ( is_wp_error( $event ) ) {
			$txn->rollback();
			return $event;
		}

		$touch = WC_Inventory_Overview_Purchase_Orders::update_fields( $po_id, array() );
		if ( is_wp_error( $touch ) ) {
			$txn->rollback();
			return $touch;
		}

		if ( ! $txn->commit() ) {
			$txn->rollback();
			return new WP_Error( 'wc_io_po_txn', 'Failed to commit transaction' );
		}

		return $line_id;
	}

	/**
	 * Update a line on a draft or placed PO.
	 *
	 * @param int                 $line_id Line id.
	 * @param array<string,mixed> $data    Changed fields.
	 * @param array<string,mixed> $options Optional reason_code override.
	 * @return array<string,mixed>|WP_Error Updated line.
	 */
	public static function update_line( int $line_id, array $data, array $options = array() ) {
		global $wpdb;

		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );
		if ( ! $txn->begin() ) {
			return new WP_Error( 'wc_io_po_txn', 'Failed to begin transaction' );
		}

		$line = WC_Inventory_Overview_Purchase_Order_Lines::get_for_update( $line_id );
		if ( is_wp_error( $line ) ) {
			$txn->rollback();
			return $line;
		}

		$po = WC_Inventory_Overview_Purchase_Orders::get_for_update( (int) $line['po_id'] );
		if ( is_wp_error( $po ) ) {
			$txn->rollback();
			return $po;
		}

		$editable = WC_Inventory_Overview_PO_Lifecycle::assert_editable( $po['status'] );
		if ( is_wp_error( $editable ) ) {
			$txn->rollback();
			return $editable;
		}

		$merged = $line;
		foreach ( self::$line_edit_fields as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$merged[ $field ] = $data[ $field ];
			}
		}
		$merged['currency']      = $po['currency'];
		$merged['qty_cancelled'] = $line['qty_cancelled'];

		$product_changed = ( isset( $data['product_id'] ) && (int) $data['product_id'] !== (int) $line['product_id'] )
			|| ( isset( $data['variation_id'] ) && (int) $data['variation_id'] !== (int) $line['variation_id'] );

		$prepared = self::prepare_line_input( $merged, (string) $po['currency'], true, $product_changed );
		if ( is_wp_error( $prepared ) ) {
			$txn->rollback();
			return $prepared;
		}

		// Preserve snapshots unless product identity changed.
		if ( ! $product_changed ) {
			$prepared['sku_snapshot']  = $line['sku_snapshot'];
			$prepared['name_snapshot'] = $line['name_snapshot'];
		}

		$before_after = self::line_before_after( $line, $prepared );
		if ( empty( $before_after['changed'] ) ) {
			$txn->rollback();
			return $line;
		}

		$reason = self::resolve_reason_code(
			isset( $options['reason_code'] ) ? $options['reason_code'] : null,
			self::infer_line_reason( $before_after['changed'] )
		);
		if ( is_wp_error( $reason ) ) {
			$txn->rollback();
			return $reason;
		}

		$updated = WC_Inventory_Overview_Purchase_Order_Lines::update( $line_id, $prepared );
		if ( is_wp_error( $updated ) ) {
			$txn->rollback();
			return $updated;
		}

		$event = WC_Inventory_Overview_PO_Events::add(
			array(
				'po_id'       => (int) $po['id'],
				'po_line_id'  => $line_id,
				'event_type'  => WC_Inventory_Overview_PO_Events::TYPE_LINE_CHANGED,
				'summary'     => sprintf( 'Line %d updated', $line_id ),
				'reason_code' => $reason,
				'data'        => $before_after,
			)
		);
		if ( is_wp_error( $event ) ) {
			$txn->rollback();
			return $event;
		}

		$touch = WC_Inventory_Overview_Purchase_Orders::update_fields( (int) $po['id'], array() );
		if ( is_wp_error( $touch ) ) {
			$txn->rollback();
			return $touch;
		}

		if ( ! $txn->commit() ) {
			$txn->rollback();
			return new WP_Error( 'wc_io_po_txn', 'Failed to commit transaction' );
		}

		return WC_Inventory_Overview_Purchase_Order_Lines::get( $line_id );
	}

	/**
	 * Remove a line (hard delete of the line row) from a draft or placed PO.
	 *
	 * @param int $line_id Line id.
	 * @return true|WP_Error
	 */
	public static function remove_line( int $line_id ) {
		global $wpdb;

		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );
		if ( ! $txn->begin() ) {
			return new WP_Error( 'wc_io_po_txn', 'Failed to begin transaction' );
		}

		$line = WC_Inventory_Overview_Purchase_Order_Lines::get_for_update( $line_id );
		if ( is_wp_error( $line ) ) {
			$txn->rollback();
			return $line;
		}

		$po = WC_Inventory_Overview_Purchase_Orders::get_for_update( (int) $line['po_id'] );
		if ( is_wp_error( $po ) ) {
			$txn->rollback();
			return $po;
		}

		$editable = WC_Inventory_Overview_PO_Lifecycle::assert_editable( $po['status'] );
		if ( is_wp_error( $editable ) ) {
			$txn->rollback();
			return $editable;
		}

		$deleted = WC_Inventory_Overview_Purchase_Order_Lines::delete( $line_id );
		if ( is_wp_error( $deleted ) ) {
			$txn->rollback();
			return $deleted;
		}

		$reseq = WC_Inventory_Overview_Purchase_Order_Lines::resequence( (int) $po['id'] );
		if ( is_wp_error( $reseq ) ) {
			$txn->rollback();
			return $reseq;
		}

		$event = WC_Inventory_Overview_PO_Events::add(
			array(
				'po_id'      => (int) $po['id'],
				'po_line_id' => $line_id,
				'event_type' => WC_Inventory_Overview_PO_Events::TYPE_LINE_REMOVED,
				'summary'    => sprintf( 'Line %d removed', $line_id ),
				'data'       => array(
					'line_id'     => $line_id,
					'product_id'  => (int) $line['product_id'],
					'qty_ordered' => (float) $line['qty_ordered'],
				),
			)
		);
		if ( is_wp_error( $event ) ) {
			$txn->rollback();
			return $event;
		}

		$touch = WC_Inventory_Overview_Purchase_Orders::update_fields( (int) $po['id'], array() );
		if ( is_wp_error( $touch ) ) {
			$txn->rollback();
			return $touch;
		}

		if ( ! $txn->commit() ) {
			$txn->rollback();
			return new WP_Error( 'wc_io_po_txn', 'Failed to commit transaction' );
		}

		return true;
	}

	/**
	 * Cancel outstanding quantity on a line (sets qty_cancelled toward qty_ordered).
	 *
	 * @param int        $line_id Line id.
	 * @param float|null $qty     Quantity to cancel; null cancels all outstanding.
	 * @return array<string,mixed>|WP_Error Updated line.
	 */
	public static function cancel_line( int $line_id, $qty = null ) {
		global $wpdb;

		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );
		if ( ! $txn->begin() ) {
			return new WP_Error( 'wc_io_po_txn', 'Failed to begin transaction' );
		}

		$line = WC_Inventory_Overview_Purchase_Order_Lines::get_for_update( $line_id );
		if ( is_wp_error( $line ) ) {
			$txn->rollback();
			return $line;
		}

		$po = WC_Inventory_Overview_Purchase_Orders::get_for_update( (int) $line['po_id'] );
		if ( is_wp_error( $po ) ) {
			$txn->rollback();
			return $po;
		}

		$editable = WC_Inventory_Overview_PO_Lifecycle::assert_editable( $po['status'] );
		if ( is_wp_error( $editable ) ) {
			$txn->rollback();
			return $editable;
		}

		$ordered     = (float) $line['qty_ordered'];
		$cancelled   = (float) $line['qty_cancelled'];
		$outstanding = WC_Inventory_Overview_Purchase_Order_Lines::outstanding( $line );
		$cancel_qty  = null === $qty ? $outstanding : (float) $qty;

		if ( $cancel_qty <= 0 ) {
			$txn->rollback();
			return new WP_Error( 'wc_io_po_qty', 'Cancel quantity must be greater than zero' );
		}
		if ( $cancel_qty > $outstanding + 0.0000001 ) {
			$txn->rollback();
			return new WP_Error( 'wc_io_po_qty', 'Cannot cancel more than outstanding quantity' );
		}

		$new_cancelled = $cancelled + $cancel_qty;
		$qty_ok        = WC_Inventory_Overview_PO_Quantities::validate_quantities( $ordered, $new_cancelled );
		if ( is_wp_error( $qty_ok ) ) {
			$txn->rollback();
			return $qty_ok;
		}

		$updated = WC_Inventory_Overview_Purchase_Order_Lines::update(
			$line_id,
			array( 'qty_cancelled' => $new_cancelled )
		);
		if ( is_wp_error( $updated ) ) {
			$txn->rollback();
			return $updated;
		}

		$event = WC_Inventory_Overview_PO_Events::add(
			array(
				'po_id'       => (int) $po['id'],
				'po_line_id'  => $line_id,
				'event_type'  => WC_Inventory_Overview_PO_Events::TYPE_LINE_CANCELLED,
				'summary'     => sprintf( 'Cancelled %s on line %d', (string) $cancel_qty, $line_id ),
				'reason_code' => WC_Inventory_Overview_PO_Reason_Codes::QUANTITY_CHANGE,
				'data'        => array(
					'line_id'             => $line_id,
					'qty_cancelled_from'  => $cancelled,
					'qty_cancelled_to'    => $new_cancelled,
					'qty_cancelled_delta' => $cancel_qty,
				),
			)
		);
		if ( is_wp_error( $event ) ) {
			$txn->rollback();
			return $event;
		}

		$touch = WC_Inventory_Overview_Purchase_Orders::update_fields( (int) $po['id'], array() );
		if ( is_wp_error( $touch ) ) {
			$txn->rollback();
			return $touch;
		}

		if ( ! $txn->commit() ) {
			$txn->rollback();
			return new WP_Error( 'wc_io_po_txn', 'Failed to commit transaction' );
		}

		return WC_Inventory_Overview_Purchase_Order_Lines::get( $line_id );
	}

	/**
	 * Place a draft PO. Idempotent if already placed.
	 *
	 * @param int $id PO id.
	 * @return array<string,mixed>|WP_Error PO header.
	 */
	public static function place( int $id ) {
		global $wpdb;

		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );
		if ( ! $txn->begin() ) {
			return new WP_Error( 'wc_io_po_txn', 'Failed to begin transaction' );
		}

		$po = WC_Inventory_Overview_Purchase_Orders::get_for_update( $id );
		if ( is_wp_error( $po ) ) {
			$txn->rollback();
			return $po;
		}

		if ( WC_Inventory_Overview_PO_Statuses::PLACED === $po['status'] ) {
			$txn->rollback();
			return $po;
		}

		$lines    = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $id );
		$supplier = WC_Inventory_Overview_Suppliers::get( (int) $po['supplier_id'] );
		if ( is_wp_error( $supplier ) ) {
			$supplier = null;
		}

		$ready = WC_Inventory_Overview_PO_Validation::validate_for_place( $po, $lines, $supplier );
		if ( is_wp_error( $ready ) ) {
			$txn->rollback();
			return $ready;
		}

		$now     = current_time( 'mysql', true );
		$updated = WC_Inventory_Overview_Purchase_Orders::update_fields(
			$id,
			array(
				'status'    => WC_Inventory_Overview_PO_Statuses::PLACED,
				'placed_at' => $now,
			)
		);
		if ( is_wp_error( $updated ) ) {
			$txn->rollback();
			return $updated;
		}

		$event = WC_Inventory_Overview_PO_Events::add(
			array(
				'po_id'      => $id,
				'event_type' => WC_Inventory_Overview_PO_Events::TYPE_PLACED,
				'summary'    => 'Purchase order placed',
				'data'       => array(
					'from_status' => $po['status'],
					'to_status'   => WC_Inventory_Overview_PO_Statuses::PLACED,
					'placed_at'   => $now,
				),
			)
		);
		if ( is_wp_error( $event ) ) {
			$txn->rollback();
			return $event;
		}

		if ( ! $txn->commit() ) {
			$txn->rollback();
			return new WP_Error( 'wc_io_po_txn', 'Failed to commit transaction' );
		}

		$placed = WC_Inventory_Overview_Purchase_Orders::get( $id );
		/**
		 * Fires after a purchase order is placed and committed.
		 *
		 * @param int                 $id     PO id.
		 * @param array<string,mixed> $placed PO header row.
		 */
		do_action( 'wc_io_purchase_order_placed', $id, $placed );

		return $placed;
	}

	/**
	 * Cancel a draft or placed PO. Idempotent if already cancelled.
	 *
	 * @param int $id PO id.
	 * @return array<string,mixed>|WP_Error PO header.
	 */
	public static function cancel( int $id ) {
		global $wpdb;

		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );
		if ( ! $txn->begin() ) {
			return new WP_Error( 'wc_io_po_txn', 'Failed to begin transaction' );
		}

		$po = WC_Inventory_Overview_Purchase_Orders::get_for_update( $id );
		if ( is_wp_error( $po ) ) {
			$txn->rollback();
			return $po;
		}

		if ( WC_Inventory_Overview_PO_Statuses::CANCELLED === $po['status'] ) {
			$txn->rollback();
			return $po;
		}

		$transition = WC_Inventory_Overview_PO_Lifecycle::assert_transition(
			$po['status'],
			WC_Inventory_Overview_PO_Statuses::CANCELLED
		);
		if ( is_wp_error( $transition ) ) {
			$txn->rollback();
			return $transition;
		}

		$now     = current_time( 'mysql', true );
		$updated = WC_Inventory_Overview_Purchase_Orders::update_fields(
			$id,
			array(
				'status'    => WC_Inventory_Overview_PO_Statuses::CANCELLED,
				'closed_at' => $now,
			)
		);
		if ( is_wp_error( $updated ) ) {
			$txn->rollback();
			return $updated;
		}

		$event = WC_Inventory_Overview_PO_Events::add(
			array(
				'po_id'      => $id,
				'event_type' => WC_Inventory_Overview_PO_Events::TYPE_CANCELLED,
				'summary'    => 'Purchase order cancelled',
				'data'       => array(
					'from_status' => $po['status'],
					'to_status'   => WC_Inventory_Overview_PO_Statuses::CANCELLED,
					'closed_at'   => $now,
				),
			)
		);
		if ( is_wp_error( $event ) ) {
			$txn->rollback();
			return $event;
		}

		if ( ! $txn->commit() ) {
			$txn->rollback();
			return new WP_Error( 'wc_io_po_txn', 'Failed to commit transaction' );
		}

		$cancelled = WC_Inventory_Overview_Purchase_Orders::get( $id );
		/**
		 * Fires after a purchase order is cancelled and committed.
		 *
		 * @param int                 $id        PO id.
		 * @param array<string,mixed> $cancelled PO header row.
		 */
		do_action( 'wc_io_purchase_order_cancelled', $id, $cancelled );

		return $cancelled;
	}

	/**
	 * Close a placed PO short. Cancels remaining outstanding qty. Idempotent if already closed_short.
	 *
	 * @param int $id PO id.
	 * @return array<string,mixed>|WP_Error PO header.
	 */
	public static function close_short( int $id ) {
		global $wpdb;

		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );
		if ( ! $txn->begin() ) {
			return new WP_Error( 'wc_io_po_txn', 'Failed to begin transaction' );
		}

		$po = WC_Inventory_Overview_Purchase_Orders::get_for_update( $id );
		if ( is_wp_error( $po ) ) {
			$txn->rollback();
			return $po;
		}

		if ( WC_Inventory_Overview_PO_Statuses::CLOSED_SHORT === $po['status'] ) {
			$txn->rollback();
			return $po;
		}

		$transition = WC_Inventory_Overview_PO_Lifecycle::assert_transition(
			$po['status'],
			WC_Inventory_Overview_PO_Statuses::CLOSED_SHORT
		);
		if ( is_wp_error( $transition ) ) {
			$txn->rollback();
			return $transition;
		}

		$lines         = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $id );
		$cancelled_map = array();
		foreach ( $lines as $line ) {
			$ordered     = (float) $line['qty_ordered'];
			$received    = (float) ( $line['qty_received'] ?? 0 );
			$outstanding = WC_Inventory_Overview_Purchase_Order_Lines::outstanding( $line );
			if ( $outstanding <= 0 ) {
				continue;
			}
			// Cancel exactly what remains unreceived, never the full ordered qty
			// (M5 audit remediation WP4) — preserves qty_received + qty_cancelled
			// == qty_ordered so the "Cancelled" figure stays accurate to display
			// even when some of the line was already received before closing short.
			$new_cancelled = round( $ordered - $received, 4 );
			$updated       = WC_Inventory_Overview_Purchase_Order_Lines::update(
				(int) $line['id'],
				array( 'qty_cancelled' => $new_cancelled )
			);
			if ( is_wp_error( $updated ) ) {
				$txn->rollback();
				return $updated;
			}
			$cancelled_map[ (int) $line['id'] ] = array(
				'from'  => (float) $line['qty_cancelled'],
				'to'    => $new_cancelled,
				'delta' => $outstanding,
			);
		}

		$now     = current_time( 'mysql', true );
		$updated = WC_Inventory_Overview_Purchase_Orders::update_fields(
			$id,
			array(
				'status'    => WC_Inventory_Overview_PO_Statuses::CLOSED_SHORT,
				'closed_at' => $now,
			)
		);
		if ( is_wp_error( $updated ) ) {
			$txn->rollback();
			return $updated;
		}

		$event = WC_Inventory_Overview_PO_Events::add(
			array(
				'po_id'      => $id,
				'event_type' => WC_Inventory_Overview_PO_Events::TYPE_CLOSED_SHORT,
				'summary'    => 'Purchase order closed short',
				'data'       => array(
					'from_status'         => $po['status'],
					'to_status'           => WC_Inventory_Overview_PO_Statuses::CLOSED_SHORT,
					'closed_at'           => $now,
					'lines_cancelled_qty' => $cancelled_map,
				),
			)
		);
		if ( is_wp_error( $event ) ) {
			$txn->rollback();
			return $event;
		}

		if ( ! $txn->commit() ) {
			$txn->rollback();
			return new WP_Error( 'wc_io_po_txn', 'Failed to commit transaction' );
		}

		$closed = WC_Inventory_Overview_Purchase_Orders::get( $id );
		/**
		 * Fires after a purchase order is closed short and committed.
		 *
		 * @param int                 $id     PO id.
		 * @param array<string,mixed> $closed PO header row.
		 */
		do_action( 'wc_io_purchase_order_closed_short', $id, $closed );

		return $closed;
	}

	/**
	 * Hard-delete a draft PO aggregate (header, lines, and that draft's events).
	 *
	 * @param int $id PO id.
	 * @return true|WP_Error
	 */
	public static function delete_draft( int $id ) {
		global $wpdb;

		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );
		if ( ! $txn->begin() ) {
			return new WP_Error( 'wc_io_po_txn', 'Failed to begin transaction' );
		}

		$po = WC_Inventory_Overview_Purchase_Orders::get_for_update( $id );
		if ( is_wp_error( $po ) ) {
			$txn->rollback();
			return $po;
		}

		if ( ! WC_Inventory_Overview_PO_Lifecycle::can_delete( $po['status'] ) ) {
			$txn->rollback();
			return new WP_Error( 'wc_io_po_delete_not_draft', 'Only draft purchase orders may be hard-deleted' );
		}

		$events = WC_Inventory_Overview_PO_Events::delete_for_draft_aggregate( $id );
		if ( is_wp_error( $events ) ) {
			$txn->rollback();
			return $events;
		}

		WC_Inventory_Overview_Purchase_Order_Lines::delete_for_po( $id );

		$header = WC_Inventory_Overview_Purchase_Orders::delete_draft_record( $id );
		if ( is_wp_error( $header ) ) {
			$txn->rollback();
			return $header;
		}

		if ( ! $txn->commit() ) {
			$txn->rollback();
			return new WP_Error( 'wc_io_po_txn', 'Failed to commit transaction' );
		}

		return true;
	}

	/**
	 * Duplicate any PO into a new draft. Not domain-idempotent.
	 *
	 * @param int $source_po_id Source PO id.
	 * @return int|WP_Error New draft PO id.
	 */
	public static function duplicate( int $source_po_id ) {
		global $wpdb;

		$source = WC_Inventory_Overview_Purchase_Orders::get( $source_po_id );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( ! WC_Inventory_Overview_PO_Lifecycle::can_duplicate( $source['status'] ) ) {
			return new WP_Error( 'wc_io_po_duplicate', 'Cannot duplicate purchase order with invalid status' );
		}

		$source_lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $source_po_id );

		$header = array(
			'supplier_id'            => (int) $source['supplier_id'],
			'supplier_name_snapshot' => (string) $source['supplier_name_snapshot'],
			'currency'               => (string) $source['currency'],
			'supplier_reference'     => $source['supplier_reference'],
			'note'                   => $source['note'],
			'expected_date'          => $source['expected_date'],
			'expected_confidence'    => $source['expected_confidence'],
			'order_date'             => null,
		);

		$lines = array();
		foreach ( $source_lines as $line ) {
			$lines[] = array(
				'product_id'          => (int) $line['product_id'],
				'variation_id'        => (int) $line['variation_id'],
				'supplier_sku'        => $line['supplier_sku'],
				'qty_ordered'         => (float) $line['qty_ordered'],
				'qty_cancelled'       => 0,
				'unit_cost'           => (float) $line['unit_cost'],
				'expected_date'       => $line['expected_date'],
				'expected_confidence' => $line['expected_confidence'],
				'note'                => $line['note'],
			);
		}

		// Re-validate INV-8 and refresh snapshots before opening the create transaction.
		$prepared_lines = array();
		foreach ( $lines as $index => $line_input ) {
			$prepared = self::prepare_line_input( $line_input, (string) $header['currency'], true );
			if ( is_wp_error( $prepared ) ) {
				return new WP_Error(
					$prepared->get_error_code(),
					sprintf( 'Line %d: %s', $index + 1, $prepared->get_error_message() )
				);
			}
			$prepared['qty_cancelled'] = 0;
			$prepared_lines[]          = $prepared;
		}

		$supplier = WC_Inventory_Overview_Suppliers::get( (int) $header['supplier_id'] );
		if ( is_wp_error( $supplier ) || ! is_array( $supplier ) ) {
			return new WP_Error( 'wc_io_po_supplier', 'A resolvable supplier is required' );
		}
		$header['supplier_name_snapshot'] = (string) $supplier['name'];

		$header_ok = WC_Inventory_Overview_PO_Validation::validate_header(
			array_merge( $header, array( 'status' => WC_Inventory_Overview_PO_Statuses::DRAFT ) )
		);
		if ( is_wp_error( $header_ok ) ) {
			return $header_ok;
		}

		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );
		if ( ! $txn->begin() ) {
			return new WP_Error( 'wc_io_po_txn', 'Failed to begin transaction' );
		}

		// Re-read source inside the transaction — must remain unmodified.
		$source_locked = WC_Inventory_Overview_Purchase_Orders::get( $source_po_id );
		if ( is_wp_error( $source_locked ) ) {
			$txn->rollback();
			return $source_locked;
		}

		$po_id = WC_Inventory_Overview_Purchase_Orders::create_draft( $header );
		if ( is_wp_error( $po_id ) ) {
			$txn->rollback();
			return $po_id;
		}

		$line_ids = array();
		foreach ( $prepared_lines as $prepared ) {
			$line_id = WC_Inventory_Overview_Purchase_Order_Lines::create( $po_id, $prepared );
			if ( is_wp_error( $line_id ) ) {
				$txn->rollback();
				return $line_id;
			}
			$line_ids[] = $line_id;
		}

		$event = WC_Inventory_Overview_PO_Events::add(
			array(
				'po_id'      => $po_id,
				'event_type' => WC_Inventory_Overview_PO_Events::TYPE_DUPLICATED,
				'summary'    => sprintf( 'Duplicated from %s', $source_locked['po_number'] ),
				'data'       => array(
					'source_po_id'     => (int) $source_locked['id'],
					'source_po_number' => (string) $source_locked['po_number'],
					'line_count'       => count( $line_ids ),
				),
			)
		);
		if ( is_wp_error( $event ) ) {
			$txn->rollback();
			return $event;
		}

		if ( ! $txn->commit() ) {
			$txn->rollback();
			return new WP_Error( 'wc_io_po_txn', 'Failed to commit transaction' );
		}

		$po = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		/**
		 * Fires after a duplicated purchase order draft is created and committed.
		 *
		 * @param int                 $po_id New PO id.
		 * @param array<string,mixed> $po    PO header row.
		 */
		do_action( 'wc_io_purchase_order_created', $po_id, $po );

		return $po_id;
	}

	/**
	 * Get a purchase order by id.
	 *
	 * @param int $id PO id.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get( int $id ) {
		return WC_Inventory_Overview_Purchase_Orders::get( $id );
	}

	/**
	 * Shared header update for draft/placed.
	 *
	 * @param int                 $id             PO id.
	 * @param array<string,mixed> $data           Changed fields.
	 * @param string              $required_status Required status.
	 * @param array<string,mixed> $options        Options.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function update_header( int $id, array $data, string $required_status, array $options = array() ) {
		global $wpdb;

		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );
		if ( ! $txn->begin() ) {
			return new WP_Error( 'wc_io_po_txn', 'Failed to begin transaction' );
		}

		$po = WC_Inventory_Overview_Purchase_Orders::get_for_update( $id );
		if ( is_wp_error( $po ) ) {
			$txn->rollback();
			return $po;
		}

		if ( $po['status'] !== $required_status ) {
			$txn->rollback();
			return new WP_Error(
				'wc_io_po_wrong_status',
				sprintf( 'Purchase order must be %s to use this update method (current: %s)', $required_status, $po['status'] )
			);
		}

		$editable = WC_Inventory_Overview_PO_Lifecycle::assert_editable( $po['status'] );
		if ( is_wp_error( $editable ) ) {
			$txn->rollback();
			return $editable;
		}

		$update = array();
		foreach ( self::$header_edit_fields as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = $data[ $field ];
			}
		}

		if ( empty( $update ) ) {
			$txn->rollback();
			return $po;
		}

		if ( isset( $update['supplier_id'] ) ) {
			$supplier = WC_Inventory_Overview_Suppliers::get( absint( $update['supplier_id'] ) );
			if ( is_wp_error( $supplier ) || ! is_array( $supplier ) ) {
				$txn->rollback();
				return new WP_Error( 'wc_io_po_supplier', 'A resolvable supplier is required' );
			}
			$update['supplier_name_snapshot'] = (string) $supplier['name'];
		}

		$merged    = array_merge( $po, $update );
		$header_ok = WC_Inventory_Overview_PO_Validation::validate_header( $merged );
		if ( is_wp_error( $header_ok ) ) {
			$txn->rollback();
			return $header_ok;
		}

		$changed = array();
		foreach ( $update as $key => $value ) {
			$before = array_key_exists( $key, $po ) ? $po[ $key ] : null;
			if ( ! self::values_equal( $before, $value, $key ) ) {
				$changed[ $key ] = array(
					'from' => $before,
					'to'   => $value,
				);
			}
		}
		if ( isset( $update['supplier_name_snapshot'] ) && ! self::values_equal( $po['supplier_name_snapshot'], $update['supplier_name_snapshot'], 'supplier_name_snapshot' ) ) {
			$changed['supplier_name_snapshot'] = array(
				'from' => $po['supplier_name_snapshot'],
				'to'   => $update['supplier_name_snapshot'],
			);
		}

		if ( empty( $changed ) ) {
			$txn->rollback();
			return $po;
		}

		$event_plan = self::plan_header_event( $changed );
		$reason     = self::resolve_reason_code(
			isset( $options['reason_code'] ) ? $options['reason_code'] : null,
			$event_plan['reason_code']
		);
		if ( is_wp_error( $reason ) ) {
			$txn->rollback();
			return $reason;
		}

		$persisted = WC_Inventory_Overview_Purchase_Orders::update_fields( $id, $update );
		if ( is_wp_error( $persisted ) ) {
			$txn->rollback();
			return $persisted;
		}

		$event = WC_Inventory_Overview_PO_Events::add(
			array(
				'po_id'       => $id,
				'event_type'  => $event_plan['event_type'],
				'summary'     => $event_plan['summary'],
				'reason_code' => $reason,
				'data'        => array( 'changed' => $changed ),
			)
		);
		if ( is_wp_error( $event ) ) {
			$txn->rollback();
			return $event;
		}

		if ( ! $txn->commit() ) {
			$txn->rollback();
			return new WP_Error( 'wc_io_po_txn', 'Failed to commit transaction' );
		}

		return WC_Inventory_Overview_Purchase_Orders::get( $id );
	}

	/**
	 * Prepare and validate a line payload, optionally refreshing product snapshots.
	 *
	 * @param array<string,mixed> $data             Line input.
	 * @param string              $header_currency  PO currency.
	 * @param bool                $validate_product Run INV-8.
	 * @param bool                $refresh_snapshot Force snapshot refresh.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function prepare_line_input( array $data, string $header_currency, bool $validate_product = true, bool $refresh_snapshot = true ) {
		$line = array(
			'product_id'          => isset( $data['product_id'] ) ? absint( $data['product_id'] ) : 0,
			'variation_id'        => isset( $data['variation_id'] ) ? absint( $data['variation_id'] ) : 0,
			'supplier_sku'        => array_key_exists( 'supplier_sku', $data ) ? $data['supplier_sku'] : null,
			'qty_ordered'         => isset( $data['qty_ordered'] ) ? (float) $data['qty_ordered'] : 0,
			'qty_cancelled'       => isset( $data['qty_cancelled'] ) ? (float) $data['qty_cancelled'] : 0,
			'unit_cost'           => isset( $data['unit_cost'] ) ? (float) $data['unit_cost'] : 0,
			'currency'            => $header_currency,
			'expected_date'       => array_key_exists( 'expected_date', $data ) ? $data['expected_date'] : null,
			'expected_confidence' => array_key_exists( 'expected_confidence', $data ) ? $data['expected_confidence'] : null,
			'note'                => array_key_exists( 'note', $data ) ? $data['note'] : null,
			'status'              => isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : 'open',
		);

		if ( isset( $data['line_index'] ) ) {
			$line['line_index'] = absint( $data['line_index'] );
		}

		$ok = WC_Inventory_Overview_PO_Validation::validate_line( $line, $validate_product );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		if ( $validate_product && $refresh_snapshot ) {
			$resolved = WC_Inventory_Overview_PO_Product_Validator::validate(
				(int) $line['product_id'],
				(int) $line['variation_id']
			);
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$product               = $resolved['product'];
			$line['product_id']    = (int) $resolved['product_id'];
			$line['variation_id']  = (int) $resolved['variation_id'];
			$line['sku_snapshot']  = $product->get_sku();
			$line['name_snapshot'] = $product->get_name();
		} elseif ( isset( $data['sku_snapshot'] ) || isset( $data['name_snapshot'] ) ) {
			$line['sku_snapshot']  = isset( $data['sku_snapshot'] ) ? $data['sku_snapshot'] : null;
			$line['name_snapshot'] = isset( $data['name_snapshot'] ) ? $data['name_snapshot'] : null;
		}

		return $line;
	}

	/**
	 * Build before/after payload for a line update.
	 *
	 * @param array<string,mixed> $before Before row.
	 * @param array<string,mixed> $after  Prepared after values.
	 * @return array{changed:array<string,array{from:mixed,to:mixed}>}
	 */
	private static function line_before_after( array $before, array $after ): array {
		$keys    = array( 'product_id', 'variation_id', 'supplier_sku', 'qty_ordered', 'unit_cost', 'expected_date', 'expected_confidence', 'note', 'sku_snapshot', 'name_snapshot' );
		$changed = array();
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $after ) ) {
				continue;
			}
			$from = array_key_exists( $key, $before ) ? $before[ $key ] : null;
			$to   = $after[ $key ];
			if ( ! self::values_equal( $from, $to, $key ) ) {
				$changed[ $key ] = array(
					'from' => $from,
					'to'   => $to,
				);
			}
		}
		return array( 'changed' => $changed );
	}

	/**
	 * Compare values with numeric normalization for qty/cost fields.
	 *
	 * @param mixed  $from From value.
	 * @param mixed  $to   To value.
	 * @param string $key  Field name.
	 */
	private static function values_equal( $from, $to, string $key ): bool {
		$numeric = array( 'qty_ordered', 'qty_cancelled', 'unit_cost', 'product_id', 'variation_id', 'supplier_id', 'line_index' );
		if ( in_array( $key, $numeric, true ) ) {
			return (float) $from === (float) $to;
		}
		$from_n = null === $from ? '' : (string) $from;
		$to_n   = null === $to ? '' : (string) $to;
		return $from_n === $to_n;
	}

	/**
	 * Infer line reason code from changed fields.
	 *
	 * Mixed-change policy: exactly one of price/quantity/schedule → that code;
	 * two or more of those categories → other; note/sku-only → other.
	 *
	 * @param array<string,mixed> $changed Changed map.
	 * @return string|null
	 */
	private static function infer_line_reason( array $changed ) {
		$categories = array();
		if ( isset( $changed['unit_cost'] ) ) {
			$categories[ WC_Inventory_Overview_PO_Reason_Codes::PRICE_CHANGE ] = true;
		}
		if ( isset( $changed['qty_ordered'] ) ) {
			$categories[ WC_Inventory_Overview_PO_Reason_Codes::QUANTITY_CHANGE ] = true;
		}
		if ( isset( $changed['expected_date'] ) || isset( $changed['expected_confidence'] ) ) {
			$categories[ WC_Inventory_Overview_PO_Reason_Codes::SCHEDULE_CHANGE ] = true;
		}

		$count = count( $categories );
		if ( 1 === $count ) {
			$keys = array_keys( $categories );
			return $keys[0];
		}
		return WC_Inventory_Overview_PO_Reason_Codes::OTHER;
	}

	/**
	 * Plan a single header event for the changed set.
	 *
	 * @param array<string,array{from:mixed,to:mixed}> $changed Changed fields.
	 * @return array{event_type:string,summary:string,reason_code:string}
	 */
	private static function plan_header_event( array $changed ): array {
		$keys          = array_keys( $changed );
		$schedule_only = array( 'expected_date', 'expected_confidence' );
		$non_schedule  = array_diff( $keys, $schedule_only, array( 'supplier_name_snapshot' ) );

		if ( array( 'expected_date' ) === $keys || ( 1 === count( $keys ) && isset( $changed['expected_date'] ) ) ) {
			return array(
				'event_type'  => WC_Inventory_Overview_PO_Events::TYPE_EXPECTED_DATE_CHANGED,
				'summary'     => 'Expected date changed',
				'reason_code' => WC_Inventory_Overview_PO_Reason_Codes::SCHEDULE_CHANGE,
			);
		}
		if ( array( 'expected_confidence' ) === $keys || ( 1 === count( $keys ) && isset( $changed['expected_confidence'] ) ) ) {
			return array(
				'event_type'  => WC_Inventory_Overview_PO_Events::TYPE_CONFIDENCE_CHANGED,
				'summary'     => 'Expected confidence changed',
				'reason_code' => WC_Inventory_Overview_PO_Reason_Codes::SCHEDULE_CHANGE,
			);
		}

		$has_supplier = isset( $changed['supplier_id'] ) || isset( $changed['supplier_reference'] );
		$has_schedule = isset( $changed['expected_date'] ) || isset( $changed['expected_confidence'] );

		if ( $has_supplier && $has_schedule ) {
			$reason = WC_Inventory_Overview_PO_Reason_Codes::OTHER;
		} elseif ( $has_supplier ) {
			$reason = WC_Inventory_Overview_PO_Reason_Codes::SUPPLIER_CHANGE;
		} elseif ( $has_schedule ) {
			$reason = WC_Inventory_Overview_PO_Reason_Codes::SCHEDULE_CHANGE;
		} else {
			$reason = WC_Inventory_Overview_PO_Reason_Codes::OTHER;
		}

		unset( $non_schedule ); // Used for clarity in the schedule-only branch above.

		return array(
			'event_type'  => WC_Inventory_Overview_PO_Events::TYPE_EDITED,
			'summary'     => 'Purchase order edited',
			'reason_code' => $reason,
		);
	}

	/**
	 * Resolve an optional explicit reason code against an inferred default.
	 *
	 * @param mixed       $explicit Explicit code or null.
	 * @param string|null $inferred Inferred code.
	 * @return string|null|WP_Error
	 */
	private static function resolve_reason_code( $explicit, $inferred ) {
		if ( null !== $explicit && '' !== $explicit ) {
			$ok = WC_Inventory_Overview_PO_Reason_Codes::validate( $explicit );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			return (string) $explicit;
		}
		if ( null === $inferred || '' === $inferred ) {
			return null;
		}
		$ok = WC_Inventory_Overview_PO_Reason_Codes::validate( $inferred );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return (string) $inferred;
	}
}
