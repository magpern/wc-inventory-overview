(function () {
	'use strict';

	function qs(sel, root) {
		return (root || document).querySelector(sel);
	}

	function qsa(sel, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(sel));
	}

	function toggleVariations(btn) {
		var id = btn.getAttribute('data-wc-io-parent');
		if (!id) {
			return;
		}
		var open = btn.getAttribute('aria-expanded') === 'true';
		var next = !open;
		btn.setAttribute('aria-expanded', next ? 'true' : 'false');
		qsa('tr.wc-io-under-parent-' + id).forEach(function (row) {
			row.hidden = !next;
		});
	}

	function toggleDetails(btn) {
		var id = btn.getAttribute('data-wc-io-detail');
		if (!id) {
			return;
		}
		var panel = document.getElementById('wc-io-detail-panel-' + id);
		var row = document.getElementById('wc-io-detail-row-' + id);
		if (!panel || !row) {
			return;
		}
		var open = btn.getAttribute('aria-expanded') === 'true';
		var next = !open;
		btn.setAttribute('aria-expanded', next ? 'true' : 'false');
		row.hidden = !next;
	}

	function openStockEditor(wrap) {
		var disp = qs('.wc-io-stock-display', wrap);
		var edit = qs('.wc-io-stock-edit', wrap);
		if (!disp || !edit) {
			return;
		}
		disp.hidden = true;
		edit.hidden = false;
		var inp = qs('.wc-io-stock-input', edit);
		if (inp) {
			inp.focus();
			inp.select();
		}
	}

	function closeStockEditor(wrap, showDisplay) {
		var disp = qs('.wc-io-stock-display', wrap);
		var edit = qs('.wc-io-stock-edit', wrap);
		if (!disp || !edit) {
			return;
		}
		edit.hidden = true;
		disp.hidden = false;
	}

	function saveStock(wrap) {
		if (typeof wcIoInventory === 'undefined') {
			return;
		}
		var id = wrap.getAttribute('data-product-id');
		var inp = qs('.wc-io-stock-input', wrap);
		if (!id || !inp) {
			return;
		}
		var fd = new FormData();
		fd.append('action', 'wc_io_save_inline_stock');
		fd.append('nonce', wcIoInventory.nonce);
		fd.append('product_id', id);
		fd.append('stock_qty', inp.value);

		var saveBtn = qs('.wc-io-stock-save', wrap);
		if (saveBtn) {
			saveBtn.disabled = true;
		}

		fetch(wcIoInventory.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd,
		})
			.then(function (r) {
				return r.json();
			})
			.then(function (json) {
				if (!json || !json.success) {
					window.alert(json && json.data && json.data.message ? json.data.message : wcIoInventory.strings.error);
					return;
				}
				var disp = qs('.wc-io-stock-display', wrap);
				var v = json.data && json.data.formatted ? json.data.formatted : inp.value;
				if (disp) {
					disp.textContent = v;
				}
				if (json.data && json.data.badgesHtml && wrap.closest('tr')) {
					var statusCell = wrap.closest('tr').querySelector('.column-status');
					if (statusCell) {
						statusCell.innerHTML = json.data.badgesHtml;
					}
				}
				closeStockEditor(wrap);
			})
			.catch(function () {
				window.alert(wcIoInventory.strings.error);
			})
			.finally(function () {
				if (saveBtn) {
					saveBtn.disabled = false;
				}
			});
	}

	document.addEventListener('click', function (e) {
		var t = e.target;
		if (!t || !t.closest) {
			return;
		}
		var vbtn = t.closest('.wc-io-toggle-variations');
		if (vbtn) {
			e.preventDefault();
			toggleVariations(vbtn);
			return;
		}
		var dbtn = t.closest('.wc-io-details-toggle');
		if (dbtn) {
			e.preventDefault();
			toggleDetails(dbtn);
			return;
		}
		var sdis = t.closest('.wc-io-stock-display');
		if (sdis) {
			e.preventDefault();
			openStockEditor(sdis.closest('.wc-io-stock'));
			return;
		}
		var cancel = t.closest('.wc-io-stock-cancel');
		if (cancel) {
			e.preventDefault();
			var w = cancel.closest('.wc-io-stock');
			if (w) {
				closeStockEditor(w);
			}
			return;
		}
		var save = t.closest('.wc-io-stock-save');
		if (save) {
			e.preventDefault();
			var w2 = save.closest('.wc-io-stock');
			if (w2) {
				saveStock(w2);
			}
		}
	});

	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') {
			return;
		}
		var open = qs('.wc-io-stock-edit:not([hidden])');
		if (open) {
			var w = open.closest('.wc-io-stock');
			if (w) {
				closeStockEditor(w);
			}
		}
	});
})();
