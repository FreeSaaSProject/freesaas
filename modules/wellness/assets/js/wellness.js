(function () {
  'use strict';

  function initCharts() {
    var data = window.WELLNESS_CALENDAR;
    if (!data || typeof Chart === 'undefined') {
      return;
    }

    var calorieEl = document.getElementById('wellnessCalorieChart');
    if (calorieEl) {
      var datasets = [
        {
          type: 'bar',
          label: data.i18n.eaten,
          data: data.eaten,
          backgroundColor: 'rgba(58, 110, 165, 0.55)',
          borderRadius: 2,
          order: 2,
        },
        {
          type: 'line',
          label: data.i18n.target,
          data: data.target,
          borderColor: 'rgba(200, 80, 60, 0.9)',
          backgroundColor: 'transparent',
          pointRadius: 0,
          borderWidth: 2,
          tension: 0,
          order: 1,
        },
      ];
      var hasBurned = (data.burned || []).some(function (v) {
        return Number(v) > 0;
      });
      if (hasBurned) {
        datasets.push({
          type: 'bar',
          label: data.i18n.burned,
          data: data.burned,
          backgroundColor: 'rgba(70, 160, 100, 0.45)',
          borderRadius: 2,
          order: 3,
        });
      }
      new Chart(calorieEl, {
        data: { labels: data.labels, datasets: datasets },
        options: {
          responsive: true,
          maintainAspectRatio: true,
          interaction: { mode: 'index', intersect: false },
          plugins: { legend: { position: 'bottom' } },
          scales: {
            x: { stacked: false, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 16 } },
            y: { beginAtZero: true },
          },
        },
      });
    }

    var weightEl = document.getElementById('wellnessWeightChart');
    if (weightEl) {
      var hasWeight = (data.weight || []).some(function (v) {
        return v !== null && v !== undefined && v !== '';
      });
      if (!hasWeight) {
        weightEl.parentElement.style.display = 'none';
        return;
      }
      new Chart(weightEl, {
        type: 'line',
        data: {
          labels: data.labels,
          datasets: [
            {
              label: data.i18n.weight,
              data: data.weight,
              borderColor: 'rgba(120, 90, 40, 0.9)',
              backgroundColor: 'rgba(120, 90, 40, 0.12)',
              spanGaps: true,
              tension: 0.25,
              pointRadius: 2,
            },
          ],
        },
        options: {
          responsive: true,
          plugins: { legend: { position: 'bottom' } },
          scales: {
            x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 16 } },
            y: { beginAtZero: false },
          },
        },
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCharts);
  } else {
    initCharts();
  }
})();
