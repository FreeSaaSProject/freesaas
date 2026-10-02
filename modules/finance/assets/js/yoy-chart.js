(function () {
  'use strict';
  var cfg = window.FINANCE_YOY_CHART;
  if (!cfg || typeof Chart === 'undefined') {
    return;
  }
  var canvas = document.getElementById('yoyChartCanvas');
  if (!canvas) {
    return;
  }

  var comparePayload = cfg.compare || { labels: [], series: {} };
  var yearsPayload = cfg.years || null;
  var labelA = cfg.labelA || 'A';
  var labelB = cfg.labelB || 'B';
  var chartView = cfg.view || 'compare';
  var currencySuffix = cfg.currency || '';
  var reloadBase = cfg.reloadBase || '/finance/balance';
  var yearPalette = [
    { border: 'rgb(66,133,244)', bg: 'rgba(66,133,244,0.08)', point: 'rectRot' },
    { border: 'rgb(251,146,60)', bg: 'rgba(251,146,60,0.08)', point: 'rect' },
    { border: 'rgb(156,163,175)', bg: 'rgba(156,163,175,0.08)', point: 'triangle' },
    { border: 'rgb(250,204,21)', bg: 'rgba(250,204,21,0.08)', point: 'cross' },
    { border: 'rgb(34,197,94)', bg: 'rgba(34,197,94,0.08)', point: 'circle' },
    { border: 'rgb(168,85,247)', bg: 'rgba(168,85,247,0.08)', point: 'star' },
    { border: 'rgb(236,72,153)', bg: 'rgba(236,72,153,0.08)', point: 'rectRounded' },
    { border: 'rgb(20,184,166)', bg: 'rgba(20,184,166,0.08)', point: 'dash' }
  ];

  var chart = null;
  var currentMetric = 'revenue';
  var textColor = getComputedStyle(document.documentElement).getPropertyValue('--text').trim() || '#e8eef4';
  var mutedColor = getComputedStyle(document.documentElement).getPropertyValue('--muted').trim() || '#8b9aab';
  var gridColor = getComputedStyle(document.documentElement).getPropertyValue('--line').trim() || 'rgba(255,255,255,0.08)';

  function seriesTitle(metricId) {
    if (comparePayload.series && comparePayload.series[metricId]) {
      return comparePayload.series[metricId].title || metricId;
    }
    if (yearsPayload && yearsPayload.series && yearsPayload.series[metricId]) {
      return yearsPayload.series[metricId].title || metricId;
    }
    return metricId;
  }

  function yAxisFmt(v) {
    var s = seriesTitle(currentMetric);
    if (s.indexOf('%') >= 0) {
      return v + '%';
    }
    return currencySuffix ? (v + ' ' + currencySuffix) : String(v);
  }

  function buildYearsDatasets(metricId) {
    if (!yearsPayload || !yearsPayload.series || !yearsPayload.series[metricId]) {
      return [];
    }
    var s = yearsPayload.series[metricId];
    var yrs = (yearsPayload.years || []).slice().reverse();
    var ds = [];
    for (var i = 0; i < yrs.length; i++) {
      var y = yrs[i];
      var pal = yearPalette[i % yearPalette.length];
      ds.push({
        label: String(y),
        data: s.years[String(y)] || s.years[y] || [],
        borderColor: pal.border,
        backgroundColor: pal.bg,
        pointStyle: pal.point,
        pointRadius: 5,
        pointHoverRadius: 7,
        borderWidth: 2,
        tension: 0.15,
        fill: false,
        spanGaps: false
      });
    }
    return ds;
  }

  function commonOptions(title) {
    return {
      responsive: true,
      maintainAspectRatio: true,
      plugins: {
        legend: {
          position: chartView === 'years' ? 'right' : 'top',
          labels: {
            color: textColor,
            usePointStyle: chartView === 'years',
            pointStyleWidth: 18,
            padding: 14
          }
        },
        title: {
          display: true,
          text: title,
          color: textColor
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            color: mutedColor,
            callback: function (v) {
              return yAxisFmt(v);
            }
          },
          grid: { color: gridColor }
        },
        x: {
          ticks: { color: mutedColor },
          grid: { display: false, color: gridColor }
        }
      }
    };
  }

  function renderChart(view, metricId) {
    currentMetric = metricId;
    chartView = view;
    if (chart) {
      chart.destroy();
      chart = null;
    }
    var isYears = view === 'years' && yearsPayload && yearsPayload.series && yearsPayload.series[metricId];
    if (isYears) {
      chart = new Chart(canvas, {
        type: 'line',
        data: {
          labels: yearsPayload.labels,
          datasets: buildYearsDatasets(metricId)
        },
        options: commonOptions(yearsPayload.series[metricId].title)
      });
      return;
    }
    var s = comparePayload.series[metricId];
    if (!s) {
      return;
    }
    chart = new Chart(canvas, {
      type: 'bar',
      data: {
        labels: comparePayload.labels,
        datasets: [
          { label: 'A: ' + labelA, data: s.a, backgroundColor: 'rgba(100,100,100,0.55)' },
          { label: 'B: ' + labelB, data: s.b, backgroundColor: 'rgba(37,99,235,0.65)' }
        ]
      },
      options: commonOptions(s.title)
    });
  }

  function reloadChartView() {
    var vEl = document.getElementById('yoyChartViewSel');
    var yEl = document.getElementById('yoyYearsSel');
    var v = vEl ? vEl.value : 'compare';
    var yrs = yEl ? yEl.value : '4';
    var u = reloadBase;
    u += (u.indexOf('?') >= 0 ? '&' : '?') + 'yoyChartView=' + encodeURIComponent(v) + '&yoyYears=' + encodeURIComponent(yrs);
    window.location.href = u;
  }

  renderChart(chartView, 'revenue');

  var sel = document.getElementById('yoyChartMetric');
  if (sel) {
    sel.addEventListener('change', function () {
      var vEl = document.getElementById('yoyChartViewSel');
      renderChart(vEl ? vEl.value : chartView, this.value);
    });
  }
  var vsel = document.getElementById('yoyChartViewSel');
  if (vsel) {
    vsel.addEventListener('change', reloadChartView);
  }
  var ysel = document.getElementById('yoyYearsSel');
  if (ysel) {
    ysel.addEventListener('change', reloadChartView);
  }
})();
