(function ($) {
	'use strict';

	function dash() {
		return '\u2014';
	}

	function resetPreview($sel, $avg, $lead) {
		var d = dash();
		$('#wc-io-cost-adj-stock').text(d);
		$('#wc-io-cost-adj-avg').text(d);
		$('#wc-io-cost-adj-value').empty().text(d);
		$avg.val('');
		$lead.show().text(wcIoCostAdj.strings.selectProduct);
	}

	function loadPreview(productId, $sel, $avg, $lead) {
		var id = parseInt(String(productId), 10) || 0;
		if (!id) {
			resetPreview($sel, $avg, $lead);
			return;
		}

		$lead.show().text(wcIoCostAdj.strings.loading);

		$.ajax({
			url: wcIoCostAdj.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'wc_io_get_cost_adjustment_preview',
				nonce: wcIoCostAdj.nonce,
				product_id: id,
			},
		})
			.done(function (res) {
				if (!res || !res.success) {
					var msg =
						res && res.data && res.data.message
							? res.data.message
							: wcIoCostAdj.strings.error;
					window.alert(msg);
					resetPreview($sel, $avg, $lead);
					return;
				}
				var d = res.data;
				$('#wc-io-cost-adj-stock').text(d.stock_display);
				$('#wc-io-cost-adj-avg').text(d.avg_display);
				$('#wc-io-cost-adj-value').html(d.value_html);
				$avg.val(d.avg_input || '');
				$lead.hide().text('');
			})
			.fail(function () {
				window.alert(wcIoCostAdj.strings.error);
				resetPreview($sel, $avg, $lead);
			});
	}

	$(function () {
		if (typeof wcIoCostAdj === 'undefined') {
			return;
		}

		var $sel = $('#wc-io-adj-line-id');
		var $avg = $('#wc-io-new-avg');
		var $lead = $('#wc-io-cost-adj-lead');

		if (!$sel.length || !$avg.length) {
			return;
		}

		$(document.body).on('change', '#wc-io-adj-line-id', function () {
			loadPreview($(this).val(), $sel, $avg, $lead);
		});

		setTimeout(function () {
			var v = $sel.val();
			if (v) {
				loadPreview(v, $sel, $avg, $lead);
			}
		}, 600);
	});
})(jQuery);
