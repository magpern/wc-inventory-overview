/**
 * Supplier autocomplete picker for Batch Intake and Quick Restock forms.
 * Dedicated JS + own nonce, not reliant on WooCommerce core internals.
 *
 * @package WC_Inventory_Overview
 */

( function( $ ) {
	'use strict';

	$( document ).ready( function() {
		initSupplierPicker();
	} );

	function initSupplierPicker() {
		const pickers = $( '.wc-io-supplier-search' );
		if ( ! pickers.length ) {
			return;
		}

		pickers.each( function() {
			const $select = $( this );
			const $input = $select.closest( '.wc-io-supplier-picker' ).find( 'input[type="text"]' );
			if ( ! $input.length ) {
				return;
			}

			// Initialize Select2 on the hidden select.
			$select.select2( {
				ajax: {
					url: wcIoSupplierPicker.ajaxUrl,
					dataType: 'json',
					delay: 250,
					data: function( params ) {
						return {
							term: params.term,
							action: 'wc_io_search_suppliers',
							security: wcIoSupplierPicker.nonce,
						};
					},
					processResults: function( data ) {
						if ( data.success ) {
							return {
								results: data.data || [],
							};
						}
						return { results: [] };
					},
				},
				minimumInputLength: 1,
				placeholder: 'Search suppliers...',
				allowClear: true,
			} );

			// On select, fill the text input and reset the select.
			$select.on( 'select2:select', function( e ) {
				const selected = e.params.data;
				$input.val( selected.text ).trigger( 'change' );
				$select.val( null ).trigger( 'change' );
			} );

			// Quick-create button (if present).
			const $quickCreateBtn = $select.closest( '.wc-io-supplier-picker' ).find( '.wc-io-supplier-quick-create' );
			if ( $quickCreateBtn.length ) {
				$quickCreateBtn.on( 'click', function( e ) {
					e.preventDefault();
					quickCreateSupplier( $input );
				} );
			}
		} );
	}

	function quickCreateSupplier( $textInput ) {
		const name = $textInput.val().trim();
		if ( ! name ) {
			alert( 'Please enter a supplier name.' );
			return;
		}

		$.ajax( {
			url: wcIoSupplierPicker.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'wc_io_quick_create_supplier',
				security: wcIoSupplierPicker.quickNonce,
				name: name,
				currency: 'EUR',
			},
			success: function( response ) {
				if ( response.success ) {
					// Pre-fill the created supplier name and keep the value in the text input.
					alert( 'Supplier created: ' + response.data.name );
					// Optionally clear the text input to show the supplier was created.
					$textInput.val( '' ).trigger( 'change' );
				} else {
					alert( wcIoSupplierPicker.strings.error + ': ' + response.data );
				}
			},
			error: function() {
				alert( wcIoSupplierPicker.strings.error );
			},
		} );
	}

} )( jQuery );
