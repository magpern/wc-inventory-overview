/**
 * WC Inventory Overview — Dashboard Chart.js (data from wcIoDashboardCharts, no CDN).
 */
(function () {
	'use strict';

	if (typeof Chart === 'undefined') {
		return;
	}

	var cfg = typeof wcIoDashboardCharts === 'undefined' ? null : wcIoDashboardCharts;
	if (!cfg) {
		return;
	}

	Chart.defaults.font.family =
		'-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';
	Chart.defaults.font.size = 12;
	Chart.defaults.color = '#646970';

	function esc(s) {
		var d = document.createElement('div');
		d.textContent = s;
		return d.innerHTML;
	}

	function safeDashicon(cls) {
		return cls && /^dashicons-[a-z0-9-]+$/.test(cls) ? cls : 'dashicons-chart-area';
	}

	var chartAnim = { duration: 520, easing: 'easeOutQuart' };

	function moneyLabel(n) {
		var sym = cfg.currencySymbol || '';
		var dec = typeof cfg.decimals === 'number' ? cfg.decimals : 2;
		var abs = Math.abs(Number(n) || 0);
		var sign = Number(n) < 0 ? '-' : '';
		var fixed = abs.toFixed(dec);
		var parts = fixed.split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '\u202f');
		return sign + sym + parts.join(dec > 0 ? '.' : '');
	}

	function showChartEmpty(mount, block, iconClass) {
		if (!mount) {
			return;
		}
		var title = '';
		var hint = '';
		var detail = '';
		if (typeof block === 'string') {
			title = block;
		} else if (block && typeof block === 'object') {
			title = block.message || (cfg.strings && cfg.strings.noData) || '';
			hint = block.hint || '';
			detail = block.detail || '';
		}
		var icon = safeDashicon(iconClass);
		var detailHtml = detail
			? '<p class="wc-io-dash-chart-empty__detail description">' + esc(detail) + '</p>'
			: '';
		var hintHtml = hint ? '<p class="wc-io-dash-chart-empty__hint">' + esc(hint) + '</p>' : '';
		mount.innerHTML =
			'<div class="wc-io-dash-chart-empty" role="status">' +
			'<span class="wc-io-dash-chart-empty__icon dashicons ' +
			icon +
			'" aria-hidden="true"></span>' +
			'<p class="wc-io-dash-chart-empty__title">' +
			esc(title) +
			'</p>' +
			hintHtml +
			detailHtml +
			'</div>';
	}

		animation: { duration: 520, easing: 'easeOutQuart' },

	function mountLine(mountId, key, palette) {
		var mount = document.getElementById(mountId);
		var block = cfg[key];
		if (!mount || !block) {
			return;
		}
		if (!block.available || !block.labels || !block.labels.length) {
			showChartEmpty(mount, block, 'dashicons-chart-line');
			return;
		}
		mount.innerHTML = '<canvas></canvas>';
		var canvas = mount.querySelector('canvas');
		new Chart(canvas.getContext('2d'), {
			type: 'line',
			data: {
				labels: block.labels,
				datasets: [
					{
						label: cfg.strings.revenue,
						data: block.revenue,
						borderColor: palette.rev,
						backgroundColor: palette.revFill,
						fill: false,
						tension: 0.35,
						cubicInterpolationMode: 'monotone',
						pointRadius: 0,
						pointHoverRadius: 5,
						pointHitRadius: 12,
						pointBackgroundColor: palette.rev,
						borderWidth: 2.5,
					},
					{
						label: cfg.strings.grossProfit,
						data: block.gross_profit,
						borderColor: palette.gp,
						backgroundColor: palette.gpFill,
						fill: false,
						tension: 0.35,
						cubicInterpolationMode: 'monotone',
						pointRadius: 0,
						pointHoverRadius: 5,
						pointHitRadius: 12,
						pointBackgroundColor: palette.gp,
						borderWidth: 2.5,
					},
				],
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				animation: chartAnim,
				interaction: { mode: 'index', intersect: false },
				layout: { padding: { top: 8, right: 6, bottom: 4, left: 4 } },
				scales: {
					x: {
						border: { display: false },
						grid: { color: 'rgba(0, 0, 0, 0.045)', drawTicks: true },
						ticks: { maxRotation: 45, minRotation: 0, padding: 6 },
					},
					y: {
						border: { display: false },
						grid: { color: 'rgba(0, 0, 0, 0.045)' },
						beginAtZero: false,
						ticks: { padding: 6 },
					},
				},
				plugins: {
					legend: {
						position: 'bottom',
						labels: {
							boxWidth: 10,
							boxHeight: 10,
							padding: 16,
							usePointStyle: true,
							pointStyle: 'circle',
						},
					},
					tooltip: {
						callbacks: {
							label: function (ctx) {
								return ctx.dataset.label + ': ' + moneyLabel(ctx.raw);
							},
						},
					},
				},
			},
		});
	}

	function mountBarH(mountId, key, palette) {
		var mount = document.getElementById(mountId);
		var block = cfg[key];
		if (!mount || !block) {
			return;
		}
		if (!block.available || !block.labels || !block.labels.length) {
			showChartEmpty(mount, block, 'dashicons-chart-bar');
			return;
		}
		mount.innerHTML = '<canvas></canvas>';
		var canvas = mount.querySelector('canvas');
		new Chart(canvas.getContext('2d'), {
			type: 'bar',
			data: {
				labels: block.labels,
				datasets: [
					{
						label: cfg.strings.profit,
						data: block.values,
						backgroundColor: palette.bar,
						borderColor: palette.barBorder,
						borderWidth: 1,
						borderRadius: 4,
						maxBarThickness: 20,
					},
				],
			},
			options: {
				indexAxis: 'y',
				responsive: true,
				maintainAspectRatio: false,
				animation: chartAnim,
				layout: { padding: { top: 4, right: 8, bottom: 4, left: 4 } },
				scales: {
					x: {
						beginAtZero: false,
						border: { display: false },
						grid: { color: 'rgba(0, 0, 0, 0.045)' },
						ticks: { callback: function (v) { return moneyLabel(v); }, padding: 6 },
					},
					y: {
						border: { display: false },
						grid: { display: false },
						ticks: { font: { size: 11 }, padding: 4 },
					},
				},
				plugins: {
					legend: { display: false },
					tooltip: {
						callbacks: {
							label: function (ctx) {
								return moneyLabel(ctx.raw);
							},
						},
					},
				},
			},
		});
	}

	function mountDoughnut(mountId, key, colors) {
		var mount = document.getElementById(mountId);
		var block = cfg[key];
		if (!mount || !block) {
			return;
		}
		if (!block.available || !block.labels || !block.labels.length) {
			showChartEmpty(mount, block, 'dashicons-chart-pie');
			return;
		}
		mount.innerHTML = '<canvas></canvas>';
		var canvas = mount.querySelector('canvas');
		var bg = block.labels.map(function (_, i) {
			return colors[i % colors.length];
		});
		new Chart(canvas.getContext('2d'), {
			type: 'doughnut',
			data: {
				labels: block.labels,
				datasets: [
					{
						data: block.values,
						backgroundColor: bg,
						borderWidth: 2,
						borderColor: '#f6f7f9',
						hoverOffset: 5,
					},
				],
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				animation: chartAnim,
				cutout: '60%',
				layout: { padding: { top: 10, right: 10, bottom: 10, left: 10 } },
				plugins: {
					legend: {
						position: 'bottom',
						labels: {
							boxWidth: 8,
							boxHeight: 8,
							padding: 12,
							usePointStyle: true,
							font: { size: 11 },
						},
					},
					tooltip: {
						callbacks: {
							label: function (ctx) {
								var l = ctx.label || '';
								return l + ': ' + moneyLabel(ctx.raw);
							},
						},
					},
				},
			},
		});
	}

	function mountLowStock(mountId, palette) {
		var mount = document.getElementById(mountId);
		var block = cfg.lowStock;
		if (!mount || !block) {
			return;
		}
		if (!block.available || !block.labels || !block.labels.length) {
			showChartEmpty(mount, block, 'dashicons-warning');
			return;
		}
		mount.innerHTML = '<canvas></canvas>';
		var canvas = mount.querySelector('canvas');
		new Chart(canvas.getContext('2d'), {
			type: 'bar',
			data: {
				labels: block.labels,
				datasets: [
					{
						label: cfg.strings.qty,
						data: block.values,
						backgroundColor: palette.low,
						borderColor: palette.lowBorder,
						borderWidth: 1,
						borderRadius: 4,
						maxBarThickness: 20,
					},
				],
			},
			options: {
				indexAxis: 'y',
				responsive: true,
				maintainAspectRatio: false,
				animation: chartAnim,
				layout: { padding: { top: 4, right: 8, bottom: 4, left: 4 } },
				scales: {
					x: {
						beginAtZero: true,
						ticks: { precision: 0, padding: 6 },
						border: { display: false },
						grid: { color: 'rgba(0, 0, 0, 0.045)' },
					},
					y: {
						border: { display: false },
						grid: { display: false },
						ticks: { font: { size: 11 }, padding: 4 },
					},
				},
				plugins: {
					legend: { display: false },
				},
			},
		});
	}

	var palette = {
		rev: '#4a6b96',
		revFill: 'rgba(74, 107, 150, 0.12)',
		gp: '#5a7a5a',
		gpFill: 'rgba(90, 122, 90, 0.12)',
		bar: 'rgba(74, 107, 150, 0.36)',
		barBorder: 'rgba(74, 107, 150, 0.52)',
		low: 'rgba(176, 148, 72, 0.42)',
		lowBorder: 'rgba(130, 108, 48, 0.55)',
	};

	var doughnutColors = [
		'#6d849e',
		'#7f9a7f',
		'#9a90ad',
		'#ad9090',
		'#b5a472',
		'#8f9dad',
		'#868b90',
		'#a88a9a',
		'#86a0b8',
		'#96aab8',
	];

	mountLine('wc-io-dash-mount-revenue-profit', 'revenueProfit', palette);
	mountBarH('wc-io-dash-mount-profit-product', 'profitProduct', palette);
	mountDoughnut('wc-io-dash-mount-profit-category', 'profitCategory', doughnutColors);
	mountLowStock('wc-io-dash-mount-low-stock', palette);
})();
