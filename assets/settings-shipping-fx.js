(function ($) {
	'use strict';

	function resolveFxDate(raw, d) {
		var s = (raw || '').trim();
		if (s) {
			return s;
		}
		return (d && d.siteTodayYmd) || '';
	}

	function parseMoney(raw) {
		if (raw === undefined || raw === null) {
			return NaN;
		}
		var s = String(raw).trim();
		if (!s) {
			return NaN;
		}
		var n = parseFloat(s.replace(',', '.'));
		return isNaN(n) ? NaN : n;
	}

	function fetchRate(currency, date, done) {
		var d = window.wcIoSettingsShipping;
		if (!d) {
			done(null, {});
			return;
		}
		date = resolveFxDate(date, d);
		if (currency === d.currencyEur) {
			done({ rate: 1, source: 'eur', rate_date: null }, null);
			return;
		}
		if (!date || !d.exchangeRateNonce) {
			done(null, { code: 'wc_io_fx_date' });
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
					done(null, { code: code });
				}
			})
			.fail(function () {
				done(null, {});
			});
	}

	function setRateHint(text) {
		$('#wc-io-def-ship-rate-hint').text(text || '');
	}

	function updateConvertedPreview() {
		var amt = parseMoney($('#wc-io-def-ship-entered-amount').val());
		var rate = parseMoney($('#wc-io-def-ship-exchange-rate').val());
		var $live = $('#wc-io-def-ship-converted-live');
		if (!isFinite(amt) || amt < 0 || !isFinite(rate) || rate <= 0) {
			$live.text('—');
			return;
		}
		$live.text((amt * rate).toFixed(6));
	}

	function applyEurUi() {
		var d = window.wcIoSettingsShipping;
		if (!d) {
			return;
		}
		var cur = $('#wc-io-def-ship-currency').val();
		var $rate = $('#wc-io-def-ship-exchange-rate');
		if (cur === d.currencyEur) {
			$rate.val('1').prop('readonly', true).attr('data-server-rate', '1').removeClass('wc-io-batch-rate-manual-needed');
			setRateHint(d.strings.rateHintEur || '');
		} else {
			$rate.prop('readonly', false);
		}
		updateConvertedPreview();
	}

	function refreshRateFromHistory() {
		var d = window.wcIoSettingsShipping;
		if (!d) {
			return;
		}
		var cur = $('#wc-io-def-ship-currency').val();
		var dateVal = resolveFxDate($('#wc-io-def-ship-fx-date').val(), d);
		var $rate = $('#wc-io-def-ship-exchange-rate');

		if (cur === d.currencyEur) {
			applyEurUi();
			return;
		}

		fetchRate(cur, dateVal, function (data, err) {
			if (!err && data && typeof data.rate !== 'undefined') {
				var rv = String(parseFloat(data.rate, 10));
				if (isNaN(parseFloat(rv, 10))) {
					rv = '';
				}
				$rate.val(rv).attr('data-server-rate', rv);
				if (parseFloat(rv, 10) > 0) {
					$rate.removeClass('wc-io-batch-rate-manual-needed');
				} else {
					$rate.addClass('wc-io-batch-rate-manual-needed');
				}
				var hint = '';
				if (data.source === 'history' && data.rate_date && d.strings.rateHintHistory) {
					hint = d.strings.rateHintHistory.split('%s').join(data.rate_date);
				} else if (data.source === 'eur' && d.strings.rateHintEur) {
					hint = d.strings.rateHintEur;
				}
				setRateHint(hint);
			} else {
				$rate.val('').attr('data-server-rate', '');
				$rate.addClass('wc-io-batch-rate-manual-needed');
				var hint =
					err && err.code === 'wc_io_fx_missing' && d.strings.rateHintNoHistory
						? d.strings.rateHintNoHistory
						: d.strings.rateHintAjaxError || '';
				setRateHint(hint);
			}
			updateConvertedPreview();
		});
	}

	$(function () {
		if (!$('#wc-io-def-ship-entered-amount').length) {
			return;
		}

		applyEurUi();

		$('#wc-io-def-ship-currency').on('change', function () {
			applyEurUi();
			if ($('#wc-io-def-ship-currency').val() !== (window.wcIoSettingsShipping && wcIoSettingsShipping.currencyEur)) {
				refreshRateFromHistory();
			}
		});

		$('#wc-io-def-ship-fx-date').on('change', function () {
			if ($('#wc-io-def-ship-currency').val() !== (window.wcIoSettingsShipping && wcIoSettingsShipping.currencyEur)) {
				refreshRateFromHistory();
			}
		});

		$('#wc-io-def-ship-entered-amount, #wc-io-def-ship-exchange-rate').on('input change', updateConvertedPreview);
	});
})(jQuery);
