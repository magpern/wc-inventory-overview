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
