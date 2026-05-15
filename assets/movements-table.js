(function ($) {
	'use strict';

	$(function () {
		var $form = $('#wc-io-movements-filter');
		if (!$form.length) {
			return;
		}

		$('#wc-io-mv-type').on('change', function () {
			var f = $(this).closest('form');
			if (f.length) {
				f[0].submit();
			}
		});

		$form.on('click', '.wc-io-mv-toggle-detail', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var rid = $btn.data('wc-io-mv-row');
			if (!rid) {
				return;
			}
			var $row = $('#wc-io-mv-detail-row-' + rid);
			if (!$row.length) {
				return;
			}
			var open = $btn.attr('aria-expanded') === 'true';
			var next = !open;
			$btn.attr('aria-expanded', next ? 'true' : 'false');
			$row.prop('hidden', !next);
		});
	});
})(jQuery);
