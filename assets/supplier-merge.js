/**
 * Supplier merge admin UI: target picker (Select2 AJAX) + typed-confirmation
 * submit gate. UX only -- the server-side confirmation check inside the
 * locked transaction (BR-M17-16) is the sole authority; this script never
 * has and never gains any ability to bypass it.
 *
 * @package WC_Inventory_Overview
 */

( function( $ ) {
	'use strict';

	$( document ).ready( function() {
		initMergeTargetPicker();
		initMergeConfirmationGate();
	} );

	function initMergeTargetPicker() {
		const $select = $( '.wc-io-supplier-merge-target-select' );
		if ( ! $select.length || ! $.fn.select2 ) {
			return;
		}

		const ajaxUrl = ( typeof ajaxurl !== 'undefined' ) ? ajaxurl : window.ajaxurl;
		const excludeId = $select.data( 'exclude-supplier-id' );
		const action = $select.data( 'ajax-action' );
		const nonce = $select.data( 'nonce' );

		$select.select2( {
			ajax: {
				url: ajaxUrl,
				dataType: 'json',
				delay: 250,
				data: function( params ) {
					return {
						term: params.term,
						action: action,
						security: nonce,
						exclude_supplier_id: excludeId,
					};
				},
				processResults: function( data ) {
					if ( data.success ) {
						return {
							results: data.data.results || [],
						};
					}
					return { results: [] };
				},
			},
			minimumInputLength: 0,
			placeholder: 'Search suppliers…',
			allowClear: true,
		} );
	}

	function initMergeConfirmationGate() {
		const $input = $( '#wc_io_supplier_merge_confirmation' );
		const $submit = $( '#wc-io-supplier-merge-submit' );
		if ( ! $input.length || ! $submit.length ) {
			return;
		}

		const expectedName = $input.data( 'expected-name' );

		function updateGate() {
			$submit.prop( 'disabled', $input.val() !== expectedName );
		}

		$input.on( 'input', updateGate );
		updateGate();
	}

} )( jQuery );
