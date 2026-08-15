/**
 * M26: plugin-owned WooCommerce variation bulk-apply for replenishment defaults.
 *
 * Cancels WC's default bulk AJAX for the four M26 actions (return null from
 * {action}_ajax_data), gathers input via modal/prompt/confirm, then posts to
 * the same woocommerce_bulk_edit_variations endpoint with a success handler
 * that understands { error: "…" }.
 *
 * @package WC_Inventory_Overview
 */

( function( $ ) {
	'use strict';

	var cfg = window.wcIoReplenishmentBulk || {};
	var i18n = cfg.i18n || {};
	var maxVariations = parseInt( cfg.max, 10 ) || 100;

	var ACTIONS = {
		setSupplier: 'wc_io_variable_preferred_supplier',
		clearSupplier: 'wc_io_variable_preferred_supplier_clear',
		setQty: 'wc_io_variable_default_qty',
		clearQty: 'wc_io_variable_default_qty_clear'
	};

	function variationTotal() {
		return parseInt(
			$( '#variable_product_options .woocommerce_variations' ).attr( 'data-total' ),
			10
		) || 0;
	}

	function capExceeded() {
		var total = variationTotal();
		if ( total > maxVariations ) {
			var msg = ( i18n.capExceeded || '' ).replace( '{n}', String( total ) );
			window.alert( msg );
			return true;
		}
		return false;
	}

	function resetBulkSelect() {
		$( '#field_to_edit' ).val( 'bulk_actions' );
		$( 'select.variation_actions' ).val( 'bulk_actions' );
	}

	function unblockVariations() {
		if (
			window.wc_meta_boxes_product_variations_ajax &&
			typeof window.wc_meta_boxes_product_variations_ajax.unblock === 'function'
		) {
			window.wc_meta_boxes_product_variations_ajax.unblock();
		} else {
			$( '#woocommerce-product-data' ).unblock();
			$( '#variable_product_options' ).unblock();
		}
	}

	function blockVariations() {
		if (
			window.wc_meta_boxes_product_variations_ajax &&
			typeof window.wc_meta_boxes_product_variations_ajax.block === 'function'
		) {
			window.wc_meta_boxes_product_variations_ajax.block();
		} else if ( $.fn.block ) {
			$( '#woocommerce-product-data' ).block( {
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			} );
		}
	}

	function goToPageOne() {
		if (
			window.wc_meta_boxes_product_variations_pagenav &&
			typeof window.wc_meta_boxes_product_variations_pagenav.go_to_page === 'function'
		) {
			window.wc_meta_boxes_product_variations_pagenav.go_to_page( 1 );
			return;
		}
		unblockVariations();
	}

	function request( bulkAction, data ) {
		var meta = window.woocommerce_admin_meta_boxes_variations || {};
		blockVariations();

		$.ajax( {
			url: meta.ajax_url || window.ajaxurl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'woocommerce_bulk_edit_variations',
				security: meta.bulk_edit_variations_nonce,
				product_id: meta.post_id,
				product_type: $( '#product-type' ).val(),
				bulk_action: bulkAction,
				data: data || {}
			},
			success: function( response ) {
				if ( response && response.error ) {
					window.alert( response.error );
					unblockVariations();
					resetBulkSelect();
					return;
				}
				goToPageOne();
			},
			error: function() {
				window.alert( i18n.genericError || 'Replenishment bulk apply failed.' );
				unblockVariations();
				resetBulkSelect();
			},
			complete: function() {
				resetBulkSelect();
			}
		} );
	}

	function openSupplierModal( onApply, onCancel ) {
		var $modal = $( '#wc-io-bulk-preferred-supplier-modal' );
		var $select = $( '#wc_io_bulk_preferred_supplier' );
		if ( ! $modal.length || ! $select.length ) {
			onCancel();
			return;
		}

		$select.val( '' );
		$modal.prop( 'hidden', false );

		function cleanup() {
			$modal.prop( 'hidden', true );
			$modal.off( '.wcIoBulk' );
		}

		$modal.on( 'click.wcIoBulk', '[data-wc-io-bulk-cancel]', function( e ) {
			e.preventDefault();
			cleanup();
			onCancel();
		} );

		$modal.on( 'click.wcIoBulk', '[data-wc-io-bulk-apply]', function( e ) {
			e.preventDefault();
			var supplierId = parseInt( $select.val(), 10 );
			if ( ! supplierId ) {
				window.alert( i18n.chooseSupplier || 'Choose a supplier…' );
				return;
			}
			cleanup();
			onApply( supplierId );
		} );
	}

	function bindAjaxData( $select, action, gatherFn ) {
		// Must bind directly on the select: WC uses triggerHandler(), which
		// does not bubble and ignores delegated document handlers.
		$select.on( action + '_ajax_data', function() {
			if ( capExceeded() ) {
				return null;
			}

			gatherFn( function( data ) {
				if ( null === data ) {
					resetBulkSelect();
					return;
				}
				request( action, data );
			} );

			// Always cancel WC's default bulk AJAX (which ignores errors).
			return null;
		} );
	}

	$( function() {
		var $select = $( 'select.variation_actions' );
		if ( ! $select.length ) {
			return;
		}

		bindAjaxData( $select, ACTIONS.setSupplier, function( done ) {
			openSupplierModal(
				function( supplierId ) {
					done( { preferred_supplier_id: supplierId } );
				},
				function() {
					done( null );
				}
			);
		} );

		bindAjaxData( $select, ACTIONS.clearSupplier, function( done ) {
			if ( ! window.confirm( i18n.confirmClearSupplier || '' ) ) {
				done( null );
				return;
			}
			done( {} );
		} );

		bindAjaxData( $select, ACTIONS.setQty, function( done ) {
			var value = window.prompt( i18n.enterQty || '' );
			if ( null === value ) {
				done( null );
				return;
			}
			done( { default_qty: value } );
		} );

		bindAjaxData( $select, ACTIONS.clearQty, function( done ) {
			if ( ! window.confirm( i18n.confirmClearQty || '' ) ) {
				done( null );
				return;
			}
			done( {} );
		} );
	} );

	// Expose for tests / debugging.
	window.wcIoReplenishmentBulkRequest = request;
} )( jQuery );
