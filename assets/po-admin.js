/**
 * Purchase Order admin line repeater (M2-D).
 */
( function( $ ) {
	'use strict';

	function initProductSearch( $context ) {
		if ( ! $.fn.selectWoo && ! $.fn.select2 ) {
			return;
		}
		$context.find( 'select.wc-product-search' ).each( function() {
			var $select = $( this );
			if ( $select.hasClass( 'enhanced' ) || $select.data( 'select2' ) ) {
				return;
			}
			var select2 = $.fn.selectWoo ? 'selectWoo' : 'select2';
			$select[ select2 ]( {
				allowClear: true,
				placeholder: $select.data( 'placeholder' ) || ( window.wcIoPoAdmin && wcIoPoAdmin.i18n.product ) || '',
				minimumInputLength: 1,
				escapeMarkup: function( m ) {
					return m;
				},
				ajax: {
					url: window.ajaxurl || '/wp-admin/admin-ajax.php',
					dataType: 'json',
					delay: 250,
					data: function( params ) {
						return {
							term: params.term,
							action: $select.data( 'action' ) || 'woocommerce_json_search_products_and_variations',
							security: ( window.wc_enhanced_select_params && wc_enhanced_select_params.search_products_nonce ) || ''
						};
					},
					processResults: function( data ) {
						var terms = [];
						if ( data ) {
							$.each( data, function( id, text ) {
								terms.push( { id: id, text: text } );
							} );
						}
						return { results: terms };
					}
				}
			} );
			$select.addClass( 'enhanced' );
		} );
	}

	/**
	 * Expected-date suggestion assistance (M10, docs/milestones/m10-implementation-plan.md
	 * WP-D). Purely additive progressive enhancement: pre-fills the create
	 * form's Expected Date + Confidence fields from wcIoPoAdmin.leadTimeSuggestions
	 * when a supplier is selected. Never runs on the edit/view screens
	 * (wcIoPoAdmin.isNewPurchaseOrder gates the whole thing) -- an existing
	 * DRAFT PO shares the same field IDs, and this must never touch its
	 * already-stored values (INV-M10-1, plan §4 non-goal #1).
	 */
	function initExpectedDateSuggestions() {
		if ( ! window.wcIoPoAdmin || ! wcIoPoAdmin.isNewPurchaseOrder ) {
			return;
		}

		var $supplier   = $( '#wc_io_po_supplier_id' );
		var $orderDate  = $( '#wc_io_po_order_date' );
		var $expected   = $( '#wc_io_po_expected_date' );
		var $confidence = $( '#wc_io_po_expected_confidence' );

		if ( ! $supplier.length || ! $expected.length || ! $confidence.length ) {
			return;
		}

		var suggestions = wcIoPoAdmin.leadTimeSuggestions || {};

		// Permanent one-way switch (plan §6 rule 3): once the operator
		// manually edits either field, suggestions are disabled for the
		// rest of this page session. Never reset, never re-enabled.
		var manuallyEdited = false;

		/**
		 * Adds `days` CALENDAR days to a 'YYYY-MM-DD' date string, using
		 * UTC internally purely to avoid local-timezone off-by-one drift
		 * in the arithmetic -- this is calendar-day math, never business
		 * days, never elapsed-hour or holiday-aware (plan §5.2).
		 *
		 * @param {string} dateStr 'YYYY-MM-DD'.
		 * @param {number} days    Calendar days to add.
		 * @return {string} 'YYYY-MM-DD'.
		 */
		function addCalendarDays( dateStr, days ) {
			var parts = dateStr.split( '-' );
			var d = new Date( Date.UTC( parseInt( parts[ 0 ], 10 ), parseInt( parts[ 1 ], 10 ) - 1, parseInt( parts[ 2 ], 10 ) ) );
			d.setUTCDate( d.getUTCDate() + days );
			var y = d.getUTCFullYear();
			var m = String( d.getUTCMonth() + 1 ).padStart( 2, '0' );
			var day = String( d.getUTCDate() ).padStart( 2, '0' );
			return y + '-' + m + '-' + day;
		}

		/**
		 * 'YYYY-MM-DD' for the merchant's local today, matching what a
		 * native date input's own "today" affordance would show.
		 *
		 * @return {string}
		 */
		function todayLocal() {
			var d = new Date();
			var y = d.getFullYear();
			var m = String( d.getMonth() + 1 ).padStart( 2, '0' );
			var day = String( d.getDate() ).padStart( 2, '0' );
			return y + '-' + m + '-' + day;
		}

		function applySuggestionForSelectedSupplier() {
			if ( manuallyEdited ) {
				return;
			}

			var supplierId = $supplier.val();
			var suggestion = supplierId ? suggestions[ supplierId ] : null;

			if ( ! suggestion ) {
				// Clear a stale, still-untouched suggestion from a
				// previously-selected supplier (plan §6 rule 5) -- these
				// fields can only hold a prior auto-fill here, never a
				// manual edit, since manuallyEdited would have returned
				// above otherwise.
				$expected.val( '' );
				$confidence.val( 'unknown' );
				return;
			}

			var basisDate = $orderDate.val() || todayLocal();
			$expected.val( addCalendarDays( basisDate, suggestion.days ) );
			$confidence.val( suggestion.confidence );
		}

		// Programmatic fills above use .val() only (no change/input event),
		// so this listener only ever observes genuine operator edits.
		$expected.add( $confidence ).on( 'input change', function() {
			manuallyEdited = true;
		} );

		$supplier.on( 'change', applySuggestionForSelectedSupplier );
	}

	function reindexLines() {
		$( '#wc-io-po-lines tbody tr.wc-io-po-line-row' ).each( function( index ) {
			$( this ).find( ':input[name]' ).each( function() {
				var name = $( this ).attr( 'name' );
				if ( ! name ) {
					return;
				}
				$( this ).attr( 'name', name.replace( /lines\[(?:\d+|__INDEX__)\]/, 'lines[' + index + ']' ) );
			} );
		} );
	}

	$( function() {
		initProductSearch( $( document ) );
		initExpectedDateSuggestions();

		$( '#wc-io-po-add-line' ).on( 'click', function( e ) {
			e.preventDefault();
			var tmpl = $( '#tmpl-wc-io-po-line-row' ).html();
			if ( ! tmpl ) {
				return;
			}
			var $row = $( tmpl );
			$( '#wc-io-po-lines tbody' ).append( $row );
			reindexLines();
			initProductSearch( $row );
		} );

		$( document ).on( 'click', '.wc-io-po-remove-line', function( e ) {
			e.preventDefault();
			var $rows = $( '#wc-io-po-lines tbody tr.wc-io-po-line-row' );
			if ( $rows.length <= 1 ) {
				$rows.find( ':input' ).val( '' ).trigger( 'change' );
				return;
			}
			$( this ).closest( 'tr' ).remove();
			reindexLines();
		} );
	} );
}( jQuery ) );
