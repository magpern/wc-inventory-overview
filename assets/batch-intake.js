(function ($) {
	'use strict';

	var batchRateManual = false;

	function triggerEnhancedSelect() {
		$(document.body).trigger('wc-enhanced-select-init');
	}

	function bindRemoveRow($root) {
		$root.on('click', '.wc-io-batch-remove-row', function (e) {
			e.preventDefault();
			var $tr = $(this).closest('tr');
			var $tbody = $tr.parent();
			if ($tbody.find('tr').length <= 1) {
				$tr.find('input').val('');
				$tr.find('.wc-io-batch-line-eur-preview').text('—');
				$tr.find('select').val('').trigger('change');
				if ($tr.find('.wc-product-search').length) {
					$tr.find('.wc-product-search').val(null).trigger('change');
				}
				return;
			}
			$tr.remove();
			refreshLineEurPreviews();
		});
	}

	function syncApplyGate() {
		var $confirm = $('#wc-io-batch-confirm');
		var $apply = $('#wc-io-batch-apply-btn');
		if (!$confirm.length || !$apply.length) {
			return;
		}
		if ($confirm.is(':checked')) {
			$apply.prop('disabled', false).attr('aria-disabled', 'false');
		} else {
			$apply.prop('disabled', true).attr('aria-disabled', 'true');
		}
	}

	function formatRate(r) {
		var n = parseFloat(r, 10);
		if (isNaN(n)) {
			return '1';
		}
		return String(n);
	}

	function updateLineSubtotalHeading() {
		var d = window.wcIoBatchIntake;
		var $th = $('#wc-io-batch-line-subtotal-heading');
		if (!$th.length) {
			return;
		}
		var cur = ($('#wc-io-batch-purchase-currency').val() || 'EUR').toString();
		var tpl = (d && d.lineSubtotalHeadingTpl) || 'Line subtotal (%s)';
		$th.text(tpl.replace('%s', cur));
	}

	function parseMoneyInput(raw) {
		if (raw === undefined || raw === null) {
			return null;
		}
		var s = String(raw).trim();
		if (s === '') {
			return null;
		}
		var n = parseFloat(s.replace(',', '.'));
		if (isNaN(n)) {
			return null;
		}
		return n;
	}

	function getBatchFxRateNumber() {
		var n = parseMoneyInput($('#wc-io-batch-exchange-rate').val());
		if (n === null || n <= 0) {
			return NaN;
		}
		return n;
	}

	function refreshLineEurPreviews() {
		var rate = getBatchFxRateNumber();
		$('#wc-io-batch-lines-body tr.wc-io-batch-line-row').each(function () {
			var cost = parseMoneyInput($(this).find('[name="wc_io_batch_line_cost[]"]').val());
			var $span = $(this).find('.wc-io-batch-line-eur-preview');
			if (cost === null || isNaN(rate)) {
				$span.text('—');
				return;
			}
			var eur = cost * rate;
			$span.text(eur.toFixed(4));
		});
	}

	function setBatchRateHint(text) {
		var $h = $('#wc-io-batch-rate-source-hint');
		if ($h.length) {
			$h.text(text || '');
		}
	}

	function setExchangeRateManualFlag($input, needed) {
		if (!$input || !$input.length) {
			return;
		}
		if (needed) {
			$input.addClass('wc-io-batch-rate-manual-needed');
		} else {
			$input.removeClass('wc-io-batch-rate-manual-needed');
		}
	}

	function hintForSource(source, rateDate, strings) {
		if (source === 'history' && rateDate) {
			var t = strings.rateHintHistory || '';
			return t.split('%s').join(rateDate);
		}
		if (source === 'eur') {
			return strings.rateHintEur || '';
		}
		return '';
	}

	function resolveFxDate(raw, d) {
		var s = (raw || '').trim();
		if (s) {
			return s;
		}
		return (d && d.siteTodayYmd) || '';
	}

	function fetchExchangeRate(currency, date, done) {
		var d = window.wcIoBatchIntake;
		if (!d) {
			done(null, { message: 'no config', code: '' });
			return;
		}
		date = resolveFxDate(date, d);
		if (currency === d.currencyEur) {
			done({ rate: 1, source: 'eur', rate_date: null }, null);
			return;
		}
		if (!date) {
			done(null, { message: 'no date', code: 'wc_io_fx_date' });
			return;
		}
		if (!d.exchangeRateNonce) {
			done(null, { message: 'no nonce', code: '' });
			return;
		}
		$.post(d.ajaxUrl, {
			action: 'wc_io_get_exchange_rate',
			nonce: d.exchangeRateNonce,
			currency: currency,
			date: date,
		})
			.done(function (res) {
				if (res && res.success && res.data) {
					done(res.data, null);
				} else {
					var code = res && res.data && res.data.code ? res.data.code : '';
					var msg = res && res.data && res.data.message ? res.data.message : 'error';
					done(null, { message: msg, code: code });
				}
			})
			.fail(function (xhr) {
				var msg = 'error';
				var code = '';
				if (xhr.responseJSON && xhr.responseJSON.data) {
					if (xhr.responseJSON.data.message) {
						msg = xhr.responseJSON.data.message;
					}
					if (xhr.responseJSON.data.code) {
						code = xhr.responseJSON.data.code;
					}
				}
				done(null, { message: msg, code: code });
			});
	}

	function refreshOffBatchLandedRates() {
		var bcur = $('#wc-io-batch-purchase-currency').val();
		$('#wc-io-batch-landed-body tr.wc-io-batch-landed-row').each(function () {
			var $row = $(this);
			var lc = $row.find('.wc-io-batch-cost-currency').val();
			if (lc && lc !== bcur) {
				setLandedRowFx($row);
			}
		});
	}

	function setBatchCurrencyUi() {
		var d = window.wcIoBatchIntake;
		if (!d) {
			return;
		}
		var $sel = $('#wc-io-batch-purchase-currency');
		var $rate = $('#wc-io-batch-exchange-rate');
		var $date = $('#wc-io-batch-exchange-rate-date');
		if (!$sel.length || !$rate.length) {
			return;
		}
		updateLineSubtotalHeading();
		var cur = $sel.val();
		var dateVal = resolveFxDate($date.length ? $date.val() : '', d);

		if (cur === d.currencyEur) {
			batchRateManual = false;
			$rate.val('1').prop('readonly', true).attr('data-server-rate', '1');
			setExchangeRateManualFlag($rate, false);
			setBatchRateHint(d.strings && d.strings.rateHintEur ? d.strings.rateHintEur : '');
			syncLandedRatesFromBatch();
			refreshOffBatchLandedRates();
			refreshLineEurPreviews();
			return;
		}

		$rate.prop('readonly', false);
		fetchExchangeRate(cur, dateVal, function (data, err) {
			if (err) {
				if (!batchRateManual) {
					$rate.val('').attr('data-server-rate', '');
					setExchangeRateManualFlag($rate, err.code === 'wc_io_fx_missing');
				}
				var hint;
				if (err.code === 'wc_io_fx_missing') {
					hint = (d.strings && d.strings.rateHintNoHistory) || err.message || '';
				} else {
					hint = (d.strings && d.strings.rateHintAjaxError) || err.message || '';
				}
				setBatchRateHint(hint);
				syncLandedRatesFromBatch();
				refreshOffBatchLandedRates();
				refreshLineEurPreviews();
				return;
			}
			setExchangeRateManualFlag($rate, false);
			if (!batchRateManual) {
				var rv = formatRate(data.rate);
				$rate.val(rv).attr('data-server-rate', rv);
			}
			setBatchRateHint(hintForSource(data.source, data.rate_date, d.strings || {}));
			syncLandedRatesFromBatch();
			refreshOffBatchLandedRates();
			refreshLineEurPreviews();
		});
	}

	function syncLandedRatesFromBatch() {
		var d = window.wcIoBatchIntake;
		if (!d) {
			return;
		}
		var bcur = $('#wc-io-batch-purchase-currency').val();
		var br = $('#wc-io-batch-exchange-rate').val();
		$('#wc-io-batch-landed-body tr.wc-io-batch-landed-row').each(function () {
			var $r = $(this);
			var lc = $r.find('.wc-io-batch-cost-currency').val();
			var $rate = $r.find('.wc-io-batch-cost-exchange-rate');
			if (lc === d.currencyEur) {
				$rate.val('1').prop('readonly', true);
				setExchangeRateManualFlag($rate, false);
				return;
			}
			$rate.prop('readonly', false);
			if (lc === bcur) {
				$rate.val(br);
				var brs = (br || '').toString().trim();
				if (lc !== d.currencyEur && brs === '') {
					setExchangeRateManualFlag($rate, true);
				} else {
					setExchangeRateManualFlag($rate, false);
				}
				return;
			}
		});
	}

	function setLandedRowFx($row) {
		var d = window.wcIoBatchIntake;
		if (!d || !$row || !$row.length) {
			return;
		}
		var bcur = $('#wc-io-batch-purchase-currency').val();
		var br = $('#wc-io-batch-exchange-rate').val();
		var bdate = resolveFxDate($('#wc-io-batch-exchange-rate-date').val(), d);
		var cur = $row.find('.wc-io-batch-cost-currency').val();
		var $rate = $row.find('.wc-io-batch-cost-exchange-rate');
		if (cur === d.currencyEur) {
			$rate.val('1').prop('readonly', true);
			setExchangeRateManualFlag($rate, false);
			return;
		}
		$rate.prop('readonly', false);
		if (cur === bcur) {
			$rate.val(br);
			var brs = (br || '').toString().trim();
			setExchangeRateManualFlag($rate, cur !== d.currencyEur && brs === '');
			return;
		}
		fetchExchangeRate(cur, bdate, function (data, err) {
			if (data && !err) {
				$rate.val(formatRate(data.rate));
				setExchangeRateManualFlag($rate, false);
			} else {
				$rate.val('');
				setExchangeRateManualFlag($rate, err && err.code === 'wc_io_fx_missing');
			}
		});
	}

	function runPreview() {
		if (typeof wcIoBatchIntake === 'undefined') {
			return;
		}
		var $form = $('#wc-io-batch-intake-form');
		var $out = $('#wc-io-batch-preview-root');
		if (!$form.length || !$out.length) {
			return;
		}

		var s = $form.serialize();
		s = s.replace(/&?wc_io_batch_confirm=1/g, '');
		s += (s.length ? '&' : '') + 'action=' + encodeURIComponent('wc_io_batch_preview');
		s += '&nonce=' + encodeURIComponent(wcIoBatchIntake.nonce);

		var $btn = $('#wc-io-batch-preview-btn');
		var busy = (wcIoBatchIntake.strings && wcIoBatchIntake.strings.previewBusy) || '…';
		var loading = (wcIoBatchIntake.strings && wcIoBatchIntake.strings.loading) || '…';
		var origLabel = $btn.length ? $btn.text() : '';
		if ($btn.length) {
			$btn.prop('disabled', true).addClass('wc-io-is-busy').text(busy);
		}
		$out.html('<p class="description wc-io-batch-preview-loading">' + $('<span/>').text(loading).html() + '</p>');

		$.ajax({
			url: wcIoBatchIntake.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: s,
			contentType: 'application/x-www-form-urlencoded; charset=UTF-8',
		})
			.always(function () {
				if ($btn.length) {
					$btn.prop('disabled', false).removeClass('wc-io-is-busy').text(origLabel);
				}
			})
			.done(function (res) {
				if (!res || !res.success || !res.data || !res.data.html) {
					var msg =
						res && res.data && res.data.message
							? res.data.message
							: wcIoBatchIntake.strings.previewError;
					$out.html('<div class="notice notice-error inline wc-io-batch-preview-error"><p>' + $('<div/>').text(msg).html() + '</p></div>');
					return;
				}
				$out.html(res.data.html);
				var root = document.getElementById('wc-io-batch-preview-root');
				if (root) {
					root.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
				}
			})
			.fail(function (xhr) {
				var msg = wcIoBatchIntake.strings.previewError;
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				$out.html('<div class="notice notice-error inline wc-io-batch-preview-error"><p>' + $('<div/>').text(msg).html() + '</p></div>');
			});
	}

	$(function () {
		var $root = $('#wc-io-batch-intake-form').closest('.wc-io-batch-intake');
		if (!$root.length) {
			return;
		}

		bindRemoveRow($root);

		$('#wc-io-batch-add-product-line').on('click', function (e) {
			e.preventDefault();
			var tpl = document.getElementById('wc-io-batch-line-template');
			if (!tpl || !tpl.content) {
				return;
			}
			var node = tpl.content.firstElementChild.cloneNode(true);
			$('#wc-io-batch-lines-body').append(node);
			triggerEnhancedSelect();
			refreshLineEurPreviews();
		});

		$('#wc-io-batch-add-landed').on('click', function (e) {
			e.preventDefault();
			var tpl = document.getElementById('wc-io-batch-landed-template');
			if (!tpl || !tpl.content) {
				return;
			}
			var node = tpl.content.firstElementChild.cloneNode(true);
			$('#wc-io-batch-landed-body').append(node);
			var $nr = $('#wc-io-batch-landed-body tr.wc-io-batch-landed-row').last();
			var bcur = $('#wc-io-batch-purchase-currency').val();
			$nr.find('.wc-io-batch-cost-currency').val(bcur);
			setLandedRowFx($nr);
		});

		$(document).on('change', '#wc-io-batch-purchase-currency', function () {
			batchRateManual = false;
			setBatchCurrencyUi();
		});

		$(document).on('change', '#wc-io-batch-exchange-rate-date', function () {
			batchRateManual = false;
			setBatchCurrencyUi();
		});

		$(document).on('input', '#wc-io-batch-exchange-rate', function () {
			var $r = $('#wc-io-batch-exchange-rate');
			var srv = $r.attr('data-server-rate') || '';
			if ($r.val() !== srv) {
				batchRateManual = true;
				$r.removeClass('wc-io-batch-rate-manual-needed');
				var st = wcIoBatchIntake.strings && wcIoBatchIntake.strings.rateHintManual;
				setBatchRateHint(st || '');
			}
			refreshLineEurPreviews();
		});

		$(document).on('change', '#wc-io-batch-exchange-rate', function () {
			syncLandedRatesFromBatch();
			refreshLineEurPreviews();
		});

		$(document).on('input change', '#wc-io-batch-lines-body [name="wc_io_batch_line_cost[]"]', function () {
			refreshLineEurPreviews();
		});

		$(document).on('change', '.wc-io-batch-cost-currency', function () {
			setLandedRowFx($(this).closest('tr'));
		});

		$('#wc-io-batch-preview-btn').on('click', function (e) {
			e.preventDefault();
			runPreview();
		});

		$('#wc-io-batch-confirm').on('change', syncApplyGate);
		syncApplyGate();

		setBatchCurrencyUi();

		refreshLineEurPreviews();

		triggerEnhancedSelect();
	});
})(jQuery);
