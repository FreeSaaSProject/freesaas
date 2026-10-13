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

  function initSuggest() {
    document.querySelectorAll('[data-wellness-suggest]').forEach(function (input) {
      if (input._wellnessSuggestBound) {
        return;
      }
      input._wellnessSuggestBound = true;
      var wrap = input.closest('.wellness-suggest-wrap');
      var list = wrap ? wrap.querySelector('.wellness-suggest-list') : null;
      var form = input.closest('form');
      var url = input.getAttribute('data-wellness-suggest') || '';
      var target = input.getAttribute('data-suggest-target') || '';
      var openOnFocus = input.getAttribute('data-suggest-open') === '1';
      var timer = null;
      var hidden = null;
      if (form) {
        hidden = target
          ? form.querySelector('[data-suggest-id="' + target + '"]')
          : form.querySelector('input[data-suggest-id]');
      }
      if (!list || !url) {
        return;
      }

      function hide() {
        list.hidden = true;
        list.innerHTML = '';
      }

      function pick(item) {
        input.value = item.name || '';
        if (hidden) {
          hidden.value = String(item.id || '');
        }
        hide();
        input.focus();
      }

      function render(items) {
        list.innerHTML = '';
        if (!items.length) {
          hide();
          return;
        }
        var ul = document.createElement('ul');
        items.forEach(function (item) {
          var li = document.createElement('li');
          var btn = document.createElement('button');
          btn.type = 'button';
          var label = item.name || '';
          if (item.ccals !== undefined && item.ccals !== null) {
            label += ' · ' + item.ccals + ' kcal/100g';
          }
          btn.textContent = label;
          btn.addEventListener('mousedown', function (ev) {
            ev.preventDefault();
            pick(item);
          });
          li.appendChild(btn);
          ul.appendChild(li);
        });
        list.appendChild(ul);
        list.hidden = false;
      }

      function search(q) {
        var sep = url.indexOf('?') >= 0 ? '&' : '?';
        fetch(url + sep + 'q=' + encodeURIComponent(q) + '&format=json&per_page=20', {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
        })
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            if (input.value !== q && !(q === '' && input.value === '')) {
              return;
            }
            render((data && data.items) || []);
          })
          .catch(function () {
            hide();
          });
      }

      function schedule() {
        if (hidden) {
          hidden.value = '';
        }
        clearTimeout(timer);
        timer = setTimeout(function () {
          search(String(input.value || '').trim());
        }, 250);
      }

      input.addEventListener('input', schedule);
      input.addEventListener('focus', function () {
        if (openOnFocus) {
          search(String(input.value || '').trim());
        }
      });
      input.addEventListener('blur', function () {
        setTimeout(hide, 180);
      });
    });
  }

  function boot() {
    initCharts();
    initSuggest();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
