(function () {
  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  function isTextEntry(el) {
    if (!el || !el.tagName) return false;
    var tag = el.tagName.toLowerCase();
    if (tag === 'textarea') return true;
    if (tag === 'input') {
      var type = (el.type || 'text').toLowerCase();
      return type === 'text' || type === 'search' || type === 'email' || type === 'number' || type === 'password' || type === 'url' || type === 'tel';
    }
    if (el.isContentEditable) return true;
    return !!(el.closest && el.closest('[contenteditable="true"]'));
  }

  function setSelectValue(sel, value) {
    if (!sel) return;
    var v = String(value);
    var found = false;
    Array.prototype.forEach.call(sel.options, function (opt) {
      if (opt.value === v) found = true;
    });
    if (!found) {
      var opt = document.createElement('option');
      opt.value = v;
      opt.textContent = v;
      sel.appendChild(opt);
    }
    sel.value = v;
  }

  function currentGrain(form) {
    var grainEl = qs('select[name="grain"]', form);
    if (grainEl && grainEl.value === 'year') return 'year';
    return form.getAttribute('data-bi-grain') === 'year' ? 'year' : 'month';
  }

  function syncGrainUi(form) {
    var grain = currentGrain(form);
    form.setAttribute('data-bi-grain', grain);
    var monthWrap = qs('[data-bi-month-wrap]', form);
    if (monthWrap) monthWrap.hidden = grain === 'year';
  }

  function shiftMonth(form, dir) {
    var yearEl = qs('select[name="year"]', form);
    var monthEl = qs('select[name="month"]', form);
    if (!yearEl) return;
    var y = parseInt(yearEl.value, 10) || (new Date()).getFullYear();
    if (currentGrain(form) === 'year') {
      setSelectValue(yearEl, y + dir);
      syncNavAttrs(form);
      return;
    }
    if (!monthEl) return;
    var m = parseInt(monthEl.value, 10) || ((new Date()).getMonth() + 1);
    m += dir;
    if (m < 1) {
      m = 12;
      y -= 1;
    } else if (m > 12) {
      m = 1;
      y += 1;
    }
    setSelectValue(yearEl, y);
    setSelectValue(monthEl, m);
    syncNavAttrs(form);
  }

  function syncNavAttrs(form) {
    var yearEl = qs('select[name="year"]', form);
    var monthEl = qs('select[name="month"]', form);
    if (!yearEl) return;
    syncGrainUi(form);
    var y = parseInt(yearEl.value, 10);
    var m = monthEl ? (parseInt(monthEl.value, 10) || 1) : 1;
    var prevM = m - 1;
    var prevY = y;
    if (prevM < 1) {
      prevM = 12;
      prevY -= 1;
    }
    var nextM = m + 1;
    var nextY = y;
    if (nextM > 12) {
      nextM = 1;
      nextY += 1;
    }
    if (currentGrain(form) === 'year') {
      prevY = y - 1;
      nextY = y + 1;
      prevM = m;
      nextM = m;
    }
    form.setAttribute('data-prev-year', String(prevY));
    form.setAttribute('data-prev-month', String(prevM));
    form.setAttribute('data-next-year', String(nextY));
    form.setAttribute('data-next-month', String(nextM));
  }

  function appendLog(msg) {
    var log = qs('#bi-progress-log');
    var footer = qs('#bi-build-footer');
    if (footer) footer.hidden = false;
    if (!log) return;
    var line = document.createElement('div');
    line.className = 'bi-log-line';
    line.textContent = msg;
    log.appendChild(line);
    log.scrollTop = log.scrollHeight;
    var status = qs('#bi-progress-status');
    if (status) status.textContent = msg;
  }

  window.biProgressFromFrame = function (msg) {
    // Ignore iframe color arg — parent log uses theme CSS.
    appendLog(msg);
  };

  var biChartInstances = [];
  var biChartJsPromise = null;
  var biChartPalette = [
    { border: 'rgb(66,133,244)', bg: 'rgba(66,133,244,0.35)' },
    { border: 'rgb(251,146,60)', bg: 'rgba(251,146,60,0.35)' },
    { border: 'rgb(34,197,94)', bg: 'rgba(34,197,94,0.35)' },
    { border: 'rgb(168,85,247)', bg: 'rgba(168,85,247,0.35)' },
    { border: 'rgb(236,72,153)', bg: 'rgba(236,72,153,0.35)' },
    { border: 'rgb(20,184,166)', bg: 'rgba(20,184,166,0.35)' }
  ];

  function destroyBiCharts() {
    biChartInstances.forEach(function (c) {
      try { c.destroy(); } catch (e) { /* ignore */ }
    });
    biChartInstances = [];
  }

  function loadChartJs() {
    if (typeof Chart !== 'undefined') {
      return Promise.resolve();
    }
    if (biChartJsPromise) {
      return biChartJsPromise;
    }
    biChartJsPromise = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
      s.async = true;
      s.onload = function () { resolve(); };
      s.onerror = function () { reject(new Error('chartjs_load_failed')); };
      document.head.appendChild(s);
    });
    return biChartJsPromise;
  }

  function cssVar(name, fallback) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return v || fallback;
  }

  function renderBiCharts(root) {
    destroyBiCharts();
    if (!root) return;
    var nodes = root.querySelectorAll('.bi-chart-wrap');
    if (!nodes.length) return;
    loadChartJs().then(function () {
      if (typeof Chart === 'undefined') return;
      var textColor = cssVar('--text', '#0f172a');
      var mutedColor = cssVar('--muted', '#64748b');
      var gridColor = cssVar('--line', 'rgba(15,23,42,0.08)');
      Array.prototype.forEach.call(nodes, function (wrap) {
        var canvas = wrap.querySelector('.bi-chart-canvas');
        var dataEl = wrap.querySelector('.bi-chart-data');
        if (!canvas || !dataEl) return;
        var payload;
        try {
          payload = JSON.parse(dataEl.textContent || '{}');
        } catch (e) {
          return;
        }
        var labels = payload.labels || [];
        var series = payload.datasets || [];
        var defaultType = payload.chart_type || 'line';
        var percentAxis = payload.percent_axis || {};
        var hasRight = false;
        var datasets = series.map(function (ser, i) {
          var pal = biChartPalette[i % biChartPalette.length];
          var t = ser.chart_type || defaultType;
          var yAxis = ser.y_axis === 'right' ? 'y1' : 'y';
          if (yAxis === 'y1') hasRight = true;
          var ds = {
            label: ser.label || ('s' + i),
            data: ser.data || [],
            type: t === 'doughnut' ? 'doughnut' : t,
            borderColor: pal.border,
            backgroundColor: t === 'line' ? pal.bg.replace('0.35', '0.12') : pal.bg,
            borderWidth: 2,
            tension: 0.2,
            fill: t === 'line' ? false : true,
            yAxisID: yAxis,
            asPercent: !!ser.as_percent,
            pct: Array.isArray(ser.pct) ? ser.pct : null
          };
          if (t === 'line') {
            ds.pointRadius = 3;
            ds.pointHoverRadius = 5;
          }
          return ds;
        });
        var isDoughnut = defaultType === 'doughnut' && datasets.every(function (d) { return d.type === 'doughnut'; });
        function pctTick(v) {
          if (v == null || !isFinite(v)) return '';
          var n = Math.round(v * 10) / 10;
          return (Math.abs(n - Math.round(n)) < 0.05 ? String(Math.round(n)) : String(n)) + '%';
        }
        function formatPctTip(v) {
          if (v == null || !isFinite(v)) return '';
          return pctTick(v);
        }
        var scales = isDoughnut ? {} : {
          x: {
            ticks: { color: mutedColor, maxRotation: 45, minRotation: 0 },
            grid: { color: gridColor }
          },
          y: {
            position: 'left',
            ticks: {
              color: mutedColor,
              callback: percentAxis.left ? function (v) { return pctTick(v); } : undefined
            },
            grid: { color: gridColor },
            beginAtZero: true,
            max: percentAxis.left ? 100 : undefined
          }
        };
        if (!isDoughnut && hasRight) {
          scales.y1 = {
            position: 'right',
            ticks: {
              color: mutedColor,
              callback: percentAxis.right ? function (v) { return pctTick(v); } : undefined
            },
            grid: { drawOnChartArea: false },
            beginAtZero: true,
            max: percentAxis.right ? 100 : undefined
          };
        }
        var chart = new Chart(canvas.getContext('2d'), {
          type: isDoughnut ? 'doughnut' : (datasets[0] && datasets[0].type === 'bar' && datasets.every(function (d) { return d.type === 'bar'; }) ? 'bar' : 'bar'),
          data: { labels: labels, datasets: datasets },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
              legend: { labels: { color: textColor } },
              tooltip: {
                callbacks: {
                  label: function (ctx) {
                    var ds = ctx.dataset || {};
                    var label = ds.label || '';
                    var raw = ctx.parsed;
                    var val = raw && typeof raw === 'object'
                      ? (raw.y != null ? raw.y : raw.r)
                      : raw;
                    var text = label ? (label + ': ') : '';
                    if (ds.asPercent) {
                      return text + formatPctTip(val);
                    }
                    text += (val == null ? '' : val);
                    if (ds.pct && ds.pct[ctx.dataIndex] != null) {
                      text += ' (' + formatPctTip(ds.pct[ctx.dataIndex]) + ')';
                    }
                    return text;
                  }
                }
              }
            },
            scales: scales
          }
        });
        biChartInstances.push(chart);
      });
    }).catch(function () {
      Array.prototype.forEach.call(nodes, function (wrap) {
        if (!wrap.querySelector('.bi-chart-fallback')) {
          var p = document.createElement('p');
          p.className = 'muted bi-chart-fallback';
          p.textContent = 'Chart.js unavailable';
          wrap.appendChild(p);
        }
      });
    });
  }

  window.biWorkDone = function (data) {
    var strip = qs('#bi-progress-strip');
    var report = qs('#bi-report');
    if (strip) {
      strip.classList.remove('is-running');
      strip.hidden = true;
    }
    destroyBiCharts();
    if (report) {
      if (data && data.ok && data.html) {
        report.innerHTML = data.html;
        renderBiCharts(report);
      } else {
        report.innerHTML = '<p class="flash-err">' + (data && data.error ? data.error : 'error') + '</p>';
      }
    }
  };

  function buildWorkUrl(cfg, form, action) {
    var fd = new FormData(form);
    var params = new URLSearchParams();
    params.set('action', action || cfg.action || 'run');
    if (!action || action === 'run') params.set('stream', '1');
    if (cfg.family) params.set('family', cfg.family);
    if (cfg.shareToken) params.set('share', cfg.shareToken);
    fd.forEach(function (v, k) {
      if (k === 'family' && cfg.family) return;
      if (k === 'share' && cfg.shareToken) return;
      if (v === '' || v === null) return;
      if ((k === 'id_operator' || k === 'id_admin' || k === 'id_good') && String(v) === '0') return;
      params.set(k, String(v));
    });
    var base = cfg.workUrl || '/bi/work';
    return base + (base.indexOf('?') >= 0 ? '&' : '?') + params.toString();
  }

  var runTimer = null;

  function startRun(cfg, form, immediate) {
    var doStart = function () {
      var strip = qs('#bi-progress-strip');
      var log = qs('#bi-progress-log');
      var footer = qs('#bi-build-footer');
      var frame = qs('#bi-work-frame');
      var report = qs('#bi-report');
      if (!frame) return;
      if (log) log.innerHTML = '';
      if (footer) footer.hidden = false;
      if (report) report.innerHTML = '';
      if (strip) {
        strip.hidden = false;
        strip.classList.add('is-running');
      }
      var status = qs('#bi-progress-status');
      if (status) status.textContent = status.getAttribute('data-running') || 'Running…';
      frame.src = buildWorkUrl(cfg, form);
    };
    if (runTimer) clearTimeout(runTimer);
    if (immediate) {
      doStart();
    } else {
      runTimer = setTimeout(doStart, 180);
    }
  }

  function bindRun() {
    var cfg = window.biWorkConfig;
    if (!cfg) return;
    var form = qs('#bi-run-form');
    if (!form) return;

    var stripStatus = qs('#bi-progress-status');
    if (stripStatus) {
      stripStatus.setAttribute('data-running', stripStatus.textContent || 'Running…');
    }

    syncNavAttrs(form);

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      startRun(cfg, form, true);
    });

    form.querySelectorAll('[data-bi-auto]').forEach(function (el) {
      el.addEventListener('change', function () {
        syncNavAttrs(form);
        startRun(cfg, form, false);
      });
    });

    form.querySelectorAll('[data-bi-xlsx]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        syncNavAttrs(form);
        window.location.href = buildWorkUrl(cfg, form, 'xlsx');
      });
    });

    form.querySelectorAll('[data-bi-nav-dir]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var dir = parseInt(btn.getAttribute('data-bi-nav-dir') || '0', 10);
        if (!dir) return;
        shiftMonth(form, dir);
        startRun(cfg, form, true);
      });
    });

    // Capture so month <select> focus does not eat ←/→.
    document.addEventListener('keydown', function (e) {
      if (e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) return;
      if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
      if (isTextEntry(e.target)) return;
      e.preventDefault();
      shiftMonth(form, e.key === 'ArrowLeft' ? -1 : 1);
      startRun(cfg, form, true);
    }, true);

    if (cfg.autoRun) {
      startRun(cfg, form, true);
    }
  }

  function bindSharesAudience() {
    var audience = qs('#bi-share-audience');
    var staffBox = qs('#bi-share-staff-box');
    var ttl = qs('#bi-share-ttl');
    if (!audience) return;
    var sync = function () {
      var isStaff = audience.value === 'staff';
      if (staffBox) staffBox.hidden = !isStaff;
      if (ttl) {
        Array.prototype.forEach.call(ttl.options, function (opt) {
          if (opt.getAttribute('data-bi-ttl-staff-only') === '1') {
            opt.hidden = !isStaff;
            opt.disabled = !isStaff;
          }
        });
        var cur = ttl.options[ttl.selectedIndex];
        if (cur && cur.disabled) {
          ttl.value = '1d';
        }
      }
    };
    audience.addEventListener('change', sync);
    sync();
  }

  function bindCopy() {
    document.querySelectorAll('[data-bi-copy]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var sel = btn.getAttribute('data-bi-copy');
        var el = sel ? qs(sel) : null;
        var text = el ? (el.textContent || '') : '';
        if (!text) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text);
        }
      });
    });
  }

  function bind() {
    bindRun();
    bindSharesAudience();
    bindCopy();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
