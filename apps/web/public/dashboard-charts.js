/* Draws the dashboard charts from data-chart attributes. No inline scripts and no third-party calls. */
(function () {
  'use strict';
  if (typeof Chart === 'undefined') { return; }
  var red = '#f5473a', yellow = '#ffc61a', ink = '#715a50', grid = '#f3e0d0';
  var rupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
  var compact = new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 });
  Chart.defaults.font.family = 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif';
  Chart.defaults.color = ink;

  function read(canvas) {
    try { return JSON.parse(canvas.getAttribute('data-chart')); } catch (e) { return null; }
  }
  function fill(ctx, top, bottom) {
    var g = ctx.createLinearGradient(0, 0, 0, ctx.canvas.height || 300);
    g.addColorStop(0, top); g.addColorStop(1, bottom);
    return g;
  }
  var tooltip = { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + rupiah.format(c.parsed.y !== undefined && c.parsed.y !== null && c.chart.options.indexAxis !== 'y' ? c.parsed.y : c.parsed.x); } } };

  var trend = document.getElementById('chart-trend');
  var t = trend && read(trend);
  if (t) {
    var ctx = trend.getContext('2d');
    new Chart(ctx, {
      type: 'line',
      data: { labels: t.labels, datasets: [
        { label: 'Tunai', data: t.cash, borderColor: red, backgroundColor: fill(ctx, 'rgba(245,71,58,.32)', 'rgba(245,71,58,0)'), fill: true, tension: .35, pointRadius: 3, pointBackgroundColor: red, borderWidth: 3 },
        { label: 'Transfer dan QRIS', data: t.noncash, borderColor: yellow, backgroundColor: fill(ctx, 'rgba(255,198,26,.42)', 'rgba(255,198,26,0)'), fill: true, tension: .35, pointRadius: 3, pointBackgroundColor: yellow, borderWidth: 3 }
      ] },
      options: { maintainAspectRatio: false, interaction: { intersect: false, mode: 'index' }, plugins: { legend: { display: false }, tooltip: tooltip },
        scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: grid, borderDash: [4, 4] }, ticks: { callback: function (v) { return compact.format(v); } } } } }
    });
  }

  var products = document.getElementById('chart-products');
  var p = products && read(products);
  if (p) {
    new Chart(products.getContext('2d'), {
      type: 'bar',
      data: { labels: p.labels, datasets: [{ label: 'Penjualan', data: p.values, backgroundColor: ['#f5473a', '#ff6a3d', '#ff8a3d', '#ffa51f', '#ffc61a'], borderRadius: 8, borderSkipped: false, barThickness: 22 }] },
      options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: tooltip },
        scales: { x: { beginAtZero: true, grid: { color: grid, borderDash: [4, 4] }, ticks: { callback: function (v) { return compact.format(v); } } }, y: { grid: { display: false } } } }
    });
  }
})();
