/**
 * Stock Adjustments admin line editor + cost preview (M27).
 */
( function( $ ) {
	'use strict';

	var cfg = window.wcIoStockAdjustment || {};

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
				placeholder: $select.data( 'placeholder' ) || '',
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

	function formatDecimal( value, places ) {
		var n = parseFloat( value );
		if ( isNaN( n ) ) {
			return '—';
		}
		return n.toFixed( places == null ? 4 : places );
	}

	function renderPreview( $cell, data ) {
		if ( ! data ) {
			$cell.html( '<span class="wc-io-muted">' + ( cfg.i18n && cfg.i18n.previewError ? cfg.i18n.previewError : '' ) + '</span>' );
			return;
		}
		var html = '<div class="wc-io-sa-preview-grid">';
		html += '<div><strong>On hand:</strong> ' + formatDecimal( data.on_hand ) + '</div>';
		html += '<div><strong>WAC:</strong> ' + formatDecimal( data.avg_cost ) + '</div>';
		html += '<div><strong>Removed:</strong> ' + formatDecimal( data.value_removed ) + '</div>';
		html += '<div><strong>Result stock:</strong> ' + formatDecimal( data.resulting_stock ) + '</div>';
		html += '<div><strong>Result value:</strong> ' + formatDecimal( data.resulting_value ) + '</div>';
		if ( data.zero_cost_warning ) {
			html += '<div class="wc-io-sa-zero-cost-warning">' + data.zero_cost_warning + '</div>';
		}
		html += '</div>';
		$cell.html( html );
	}

	function requestPreview( $row ) {
		var $product = $row.find( '.wc-io-sa-product' );
		var $qty = $row.find( '.wc-io-sa-qty' );
		var $cell = $row.find( '.wc-io-sa-preview' );
		var itemId = parseInt( $product.val(), 10 );
		var qty = parseFloat( $qty.val() );

		if ( ! itemId || ! qty || qty <= 0 ) {
			$cell.html( '<span class="wc-io-muted">' + ( cfg.i18n && cfg.i18n.previewLoading ? 'Enter product and qty' : '' ) + '</span>' );
			return;
		}

		$cell.html( '<span class="wc-io-muted">' + ( cfg.i18n && cfg.i18n.previewLoading ? cfg.i18n.previewLoading : '…' ) + '</span>' );

		$.post(
			cfg.ajaxUrl,
			{
				action: 'wc_io_stock_adjustment_preview',
				nonce: cfg.nonce,
				item_id: itemId,
				qty: qty
			}
		).done( function( resp ) {
			if ( resp && resp.success ) {
				renderPreview( $cell, resp.data );
			} else {
				var msg = resp && resp.data && resp.data.message ? resp.data.message : ( cfg.i18n && cfg.i18n.previewError );
				$cell.html( '<span class="wc-io-sa-preview-error">' + msg + '</span>' );
			}
		} ).fail( function() {
			$cell.html( '<span class="wc-io-sa-preview-error">' + ( cfg.i18n && cfg.i18n.previewError ? cfg.i18n.previewError : '' ) + '</span>' );
		} );
	}

	function reindexLines() {
		$( '#wc-io-sa-lines tbody tr.wc-io-sa-line-row' ).each( function( index ) {
			var $row = $( this );
			$row.find( '.wc-io-sa-product' ).attr( 'name', 'wc_io_sa_line_product[' + index + ']' );
			$row.find( '.wc-io-sa-qty' ).attr( 'name', 'wc_io_sa_line_qty[' + index + ']' );
			$row.find( '.wc-io-sa-preview' ).attr( 'data-row', String( index ) );
		} );
	}

	function updateAddLineState() {
		var max = parseInt( cfg.maxLines, 10 ) || 100;
		var count = $( '#wc-io-sa-lines tbody tr.wc-io-sa-line-row' ).length;
		$( '#wc-io-sa-add-line' ).prop( 'disabled', count >= max );
	}

	$( function() {
		var $form = $( '#wc-io-sa-form' );
		if ( ! $form.length ) {
			return;
		}

		$( '#wc-io-sa-lines' ).on( 'change input', '.wc-io-sa-product, .wc-io-sa-qty', function() {
			requestPreview( $( this ).closest( 'tr' ) );
		} );

		$( '#wc-io-sa-lines tbody tr.wc-io-sa-line-row' ).each( function() {
			requestPreview( $( this ) );
		} );

		$( '#wc-io-sa-add-line' ).on( 'click', function() {
			var max = parseInt( $( this ).data( 'max-lines' ), 10 ) || parseInt( cfg.maxLines, 10 ) || 100;
			var $tbody = $( '#wc-io-sa-lines tbody' );
			if ( $tbody.find( 'tr.wc-io-sa-line-row' ).length >= max ) {
				window.alert( cfg.i18n && cfg.i18n.maxLines ? cfg.i18n.maxLines : '' );
				return;
			}
			var template = $( '#wc-io-sa-line-template' ).html();
			if ( ! template ) {
				return;
			}
			var index = $tbody.find( 'tr.wc-io-sa-line-row' ).length;
			$tbody.append( template.replace( /__INDEX__/g, String( index ) ) );
			initProductSearch( $tbody.find( 'tr.wc-io-sa-line-row' ).last() );
			reindexLines();
			updateAddLineState();
		} );

		$( '#wc-io-sa-lines' ).on( 'click', '.wc-io-sa-remove-line', function( e ) {
			e.preventDefault();
			var $rows = $( '#wc-io-sa-lines tbody tr.wc-io-sa-line-row' );
			if ( $rows.length <= 1 ) {
				return;
			}
			$( this ).closest( 'tr' ).remove();
			reindexLines();
			updateAddLineState();
		} );

		updateAddLineState();
	} );
}( jQuery ) );
