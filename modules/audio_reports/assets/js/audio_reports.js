/**
 * Audio summaries UI: leaders sort, abs/per-hour, day drill-down, constructor.
 */
(function () {
  if (window.__arSumBound) return;
  window.__arSumBound = true;

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function formatVal(v, dec) {
    if (dec > 0) return Number(v).toFixed(dec);
    return Math.round(Number(v)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  }

  /* ——— Leaders: sort + abs/per-hour ——— */
  function bindLeaders(root) {
    root = root || document;
    var table = qs('.ar-sum-detail-table', root);
    if (!table) return;
    var modeSel = qs('.ar-sum-detail-mode', root);
    var sortCol = -1;
    var sortAsc = true;

    function applyMode() {
      var mode = modeSel ? modeSel.value : 'sum';
      qsa('.ar-sum-detail-row', table).forEach(function (tr) {
        var hours = parseFloat(tr.getAttribute('data-hours') || '0') || 0;
        qsa('.ar-sum-abs, .ar-sum-th-abs ~ * .ar-sum-abs', tr).forEach(function () {});
        qsa('td.ar-sum-abs, td .ar-sum-abs', tr).forEach(function (td) {
          var el = td.classList.contains('ar-sum-abs') ? td : qs('.ar-sum-abs', td);
          if (!el) return;
          var sum = parseFloat(el.getAttribute('data-sum') || '0') || 0;
          var dec = parseInt(el.getAttribute('data-decimals') || '0', 10) || 0;
          var shown = mode === 'per_hour' && hours > 0 ? (sum / hours) : sum;
          if (mode === 'per_hour') dec = Math.max(dec, 2);
          el.textContent = (el.textContent || '').indexOf('=') === 0
            ? ('=' + formatVal(shown, dec === 1 ? 0 : dec))
            : formatVal(shown, mode === 'per_hour' ? 2 : dec);
        });
      });
    }

    function sortBy(col) {
      var tbody = qs('tbody', table);
      if (!tbody) return;
      var rows = qsa('tr', tbody);
      if (sortCol === col) sortAsc = !sortAsc;
      else { sortCol = col; sortAsc = true; }
      rows.sort(function (a, b) {
        var ca = a.children[col];
        var cb = b.children[col];
        var va = ca ? ca.getAttribute('data-sort') : '';
        var vb = cb ? cb.getAttribute('data-sort') : '';
        var na = parseFloat(va);
        var nb = parseFloat(vb);
        var cmp;
        if (!isNaN(na) && !isNaN(nb) && String(va).match(/^-?\d/) && String(vb).match(/^-?\d/)) {
          cmp = na - nb;
        } else {
          cmp = String(va).localeCompare(String(vb), undefined, { sensitivity: 'base' });
        }
        return sortAsc ? cmp : -cmp;
      });
      rows.forEach(function (r) { tbody.appendChild(r); });
      qsa('.ar-sum-sort', table).forEach(function (th, i) {
        var ind = qs('.ar-sum-sort-ind', th);
        if (ind) ind.textContent = (i === col) ? (sortAsc ? ' ▲' : ' ▼') : '';
      });
    }

    qsa('.ar-sum-sort', table).forEach(function (th) {
      th.addEventListener('click', function () {
        sortBy(parseInt(th.getAttribute('data-col') || '0', 10) || 0);
      });
    });
    if (modeSel) modeSel.addEventListener('change', applyMode);

    /* Day drill-down */
    var panel = qs('#ar-sum-days-panel', root);
    function openDays(btn) {
      if (!panel) return;
      var days = [];
      try { days = JSON.parse(btn.getAttribute('data-days') || '[]'); } catch (e) { days = []; }
      var cols = {};
      try { cols = JSON.parse(panel.getAttribute('data-cols') || '{}'); } catch (e2) { cols = {}; }
      var name = btn.getAttribute('data-emp-name') || btn.getAttribute('data-emp-short') || '';
      var thr = parseFloat(btn.getAttribute('data-threshold') || cols.threshold || '1') || 1;
      var html = '<div class="ar-sum-days-head"><strong>' + escapeHtml(name) + '</strong> '
        + '<button type="button" class="ar-sum-days-close btn-secondary">×</button></div>';
      html += '<div class="ar-sum-table-wrap"><table class="ar-sum-detail-table ar-sum-days-table"><thead><tr>';
      html += '<th>' + (window.arSumI18n && arSumI18n.day || 'Day') + '</th>';
      html += '<th>' + (window.arSumI18n && arSumI18n.hours || 'Hours') + '</th>';
      if (cols.show_score) html += '<th>SCORE®</th>';
      if (cols.show_pass) html += '<th>✓</th>';
      if (cols.show_script_sum) html += '<th>Σ%</th>';
      (cols.scripts || []).forEach(function (s) { html += '<th>' + escapeHtml(s.name || '') + '</th>'; });
      if (cols.show_script_score) html += '<th>₽</th>';
      (cols.criteria || []).forEach(function (c) { html += '<th>' + escapeHtml(c.name || '') + '</th>'; });
      (cols.tracking || []).forEach(function (t) { html += '<th>' + (t.code || escapeHtml(t.name || '')) + '</th>'; });
      if (cols.show_keys) html += '<th>#</th>';
      (cols.external || []).forEach(function (x) { html += '<th>' + escapeHtml(x.title || x.key || '') + '</th>'; });
      (cols.computed || []).forEach(function (c) { html += '<th>' + escapeHtml(c.title || c.key || '') + '</th>'; });
      html += '</tr></thead><tbody>';
      days.forEach(function (d) {
        var score = parseFloat(d.avg_score || 0) || 0;
        html += '<tr>';
        html += '<td>' + escapeHtml(d.day_code || d.date || '') + '</td>';
        html += '<td>' + (d.audio_hours != null ? d.audio_hours : '') + '</td>';
        if (cols.show_score) html += '<td>' + score + '</td>';
        if (cols.show_pass) html += '<td>' + (score >= thr ? '✓' : '↓') + '</td>';
        if (cols.show_script_sum) {
          html += '<td>' + (d.script_conversion || 0) + '% <span class="muted">(' + (d.script_finishes || 0) + '/' + (d.script_starts || 0) + ')</span></td>';
        }
        (cols.scripts || []).forEach(function (s) {
          var sd = (d.scripts_detail && d.scripts_detail[s.id]) || {};
          html += '<td>' + (sd.conversion || 0) + '%</td>';
        });
        if (cols.show_script_score) html += '<td>' + Math.round(d.script_score || 0) + '</td>';
        (cols.criteria || []).forEach(function (c) {
          var pct = (d.criteria_pct && d.criteria_pct[c.id]) || 0;
          html += '<td>' + pct + '%</td>';
        });
        (cols.tracking || []).forEach(function (t) {
          html += '<td>' + ((d.tracking_detail && d.tracking_detail[t.id]) || 0) + '</td>';
        });
        if (cols.show_keys) html += '<td>' + (d.instances || 0) + '</td>';
        (cols.external || []).forEach(function (x) {
          html += '<td>' + ((d.external_detail && d.external_detail[x.key]) || 0) + '</td>';
        });
        (cols.computed || []).forEach(function (c) {
          var v = d.computed_detail && d.computed_detail[c.key];
          if (c.kind === 'check') {
            var p = d.computed_pass && d.computed_pass[c.key];
            html += '<td>' + (p === 1 || p === '1' ? '✓' : (p === 0 || p === '0' ? '✗' : '—')) + '</td>';
          } else {
            html += '<td>' + (v == null ? '—' : v) + (c.unit ? ' ' + escapeHtml(c.unit) : '') + '</td>';
          }
        });
        html += '</tr>';
      });
      html += '</tbody></table></div>';
      panel.innerHTML = html;
      panel.style.display = '';
      var close = qs('.ar-sum-days-close', panel);
      if (close) close.addEventListener('click', function () { panel.style.display = 'none'; });
      panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    root.addEventListener('click', function (ev) {
      var btn = ev.target.closest ? ev.target.closest('.ar-sum-hours-btn') : null;
      if (btn) openDays(btn);
    });
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  /* ——— Month nav with count animation ——— */
  function bindMonthNav(root) {
    root = root || document;
    var report = qs('#ar-sum-report', root) || root;
    var busy = false;
    function goUrl(url) { location.href = url; }
    function loadMonth(year, month, url) {
      if (busy) return;
      busy = true;
      var label = qs('.ar-sum-month-label', report);
      if (label) label.style.opacity = '0.55';
      var profile = (qs('.ar-sum-month-pager', report) || {}).getAttribute
        ? qs('.ar-sum-month-pager', report).getAttribute('data-profile') : '0';
      var fd = new FormData();
      fd.append('action', 'month');
      fd.append('format', 'json');
      fd.append('id_records_summary', profile || '0');
      fd.append('year', String(year));
      fd.append('month', String(month));
      fetch('/audio/summaries/work', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.ok || !data.counts) { goUrl(url); return; }
          if (label && data.month_label) { label.textContent = data.month_label; label.style.opacity = '1'; }
          animateCounts(data.counts, function () { goUrl(url); });
        })
        .catch(function () { goUrl(url); });
    }
    function animateCounts(targets, onDone) {
      var nodes = qsa('.ar-sum-count[data-rs-key]', report);
      if (!nodes.length) { if (onDone) onDone(); return; }
      var t0 = null, dur = 600;
      var from = [], to = [], decs = [];
      nodes.forEach(function (n, i) {
        var key = n.getAttribute('data-rs-key');
        from[i] = parseFloat(String(n.textContent).replace(/\s/g, '').replace(',', '.')) || 0;
        to[i] = (targets && typeof targets[key] !== 'undefined') ? parseFloat(targets[key]) : from[i];
        decs[i] = parseInt(n.getAttribute('data-decimals') || '0', 10);
      });
      function tick(ts) {
        if (t0 === null) t0 = ts;
        var p = Math.min(1, (ts - t0) / dur);
        var e = 1 - Math.pow(1 - p, 3);
        nodes.forEach(function (n, i) {
          n.textContent = formatVal(from[i] + (to[i] - from[i]) * e, decs[i]);
        });
        if (p < 1) requestAnimationFrame(tick);
        else if (onDone) onDone();
      }
      requestAnimationFrame(tick);
    }
    function navLink(dir) {
      var pager = qs('.ar-sum-month-pager', report);
      if (!pager || pager.getAttribute('data-mode') === 'range') return null;
      var links = qsa('a.ar-sum-month-nav', pager);
      if (!links.length) return null;
      // Markup order: [prev] then optional [next]. Do not fall back next→prev.
      if (dir < 0) return links[0] || null;
      return links[1] || null;
    }
    function goNav(a) {
      if (!a || !a.getAttribute) return;
      loadMonth(a.getAttribute('data-year'), a.getAttribute('data-month'), a.getAttribute('href'));
    }
    document.addEventListener('click', function (ev) {
      var a = ev.target.closest ? ev.target.closest('a.ar-sum-month-nav') : null;
      if (!a || ev.metaKey || ev.ctrlKey) return;
      var pager = qs('.ar-sum-month-pager', report);
      if (!pager || pager.getAttribute('data-mode') === 'range') return;
      ev.preventDefault();
      goNav(a);
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight') return;
      if (ev.metaKey || ev.ctrlKey || ev.altKey || ev.shiftKey) return;
      var t = ev.target;
      if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
      if (!qs('#ar-sum-report')) return;
      var a = navLink(ev.key === 'ArrowLeft' ? -1 : 1);
      if (!a) return;
      ev.preventDefault();
      goNav(a);
    });
  }

  /* ——— Constructor ——— */
  function nextIndex(listSel, itemSel) {
    var max = -1;
    qsa(itemSel, qs(listSel)).forEach(function (el) {
      var i = parseInt(el.getAttribute('data-index') || '-1', 10);
      if (i > max) max = i;
    });
    return max + 1;
  }

  function bindConstructor() {
    var form = qs('#ar-sum-form');
    if (!form) return;

    var extAdd = qs('#ar-sum-ext-add');
    var extTpl = qs('#ar-sum-ext-tpl');
    if (extAdd && extTpl) {
      extAdd.addEventListener('click', function () {
        var idx = nextIndex('#ar-sum-ext-list', '.ar-sum-ext-item');
        var html = extTpl.innerHTML
          .replace(/__IDX__/g, String(idx))
          .replace(/__NUM__/g, String(idx + 1));
        qs('#ar-sum-ext-list').insertAdjacentHTML('beforeend', html);
      });
    }
    document.addEventListener('click', function (ev) {
      var rm = ev.target.closest ? ev.target.closest('.ar-sum-ext-remove') : null;
      if (rm) {
        var item = rm.closest('.ar-sum-ext-item');
        if (item) item.parentNode.removeChild(item);
      }
      var crm = ev.target.closest ? ev.target.closest('.ar-sum-calc-remove') : null;
      if (crm) {
        var citem = crm.closest('.ar-sum-calc-item');
        if (citem) citem.parentNode.removeChild(citem);
      }
    });

    var calcAdd = qs('#ar-sum-calc-add');
    var calcTpl = qs('#ar-sum-calc-tpl');
    if (calcAdd && calcTpl) {
      calcAdd.addEventListener('click', function () {
        var idx = nextIndex('#ar-sum-calc-list', '.ar-sum-calc-item');
        var html = calcTpl.innerHTML
          .replace(/__IDX__/g, String(idx))
          .replace(/__NUM__/g, String(idx + 1));
        qs('#ar-sum-calc-list').insertAdjacentHTML('beforeend', html);
        syncCalcPanes(qs('#ar-sum-calc-list .ar-sum-calc-item:last-child'));
      });
    }

    function syncCalcPanes(item) {
      if (!item) return;
      var kind = (qs('.ar-sum-calc-kind', item) || {}).value || 'percent';
      item.setAttribute('data-kind', kind);
      item.classList.toggle('is-percent', kind === 'percent');
      item.classList.toggle('is-check', kind === 'check');
      item.classList.toggle('is-result', kind === 'result');
    }
    document.addEventListener('change', function (ev) {
      if (ev.target && ev.target.classList && ev.target.classList.contains('ar-sum-calc-kind')) {
        syncCalcPanes(ev.target.closest('.ar-sum-calc-item'));
      }
      if (ev.target && ev.target.classList && ev.target.classList.contains('ar-sum-calc-insert')) {
        var item = ev.target.closest('.ar-sum-calc-item');
        var ta = qs('.ar-sum-calc-formula', item);
        if (ta && ev.target.value) {
          ta.value += ev.target.value;
          ev.target.value = '';
        }
      }
    });
    qsa('.ar-sum-calc-item').forEach(syncCalcPanes);

    /* Propose / preview via iframe */
    window.rsProgressFromFrame = function (msg, color) {
      var log = qs('#ar-sum-progress-log');
      var panel = qs('#ar-sum-progress');
      if (!log || !panel) return;
      panel.style.display = '';
      var line = document.createElement('div');
      line.style.color = color || '';
      line.textContent = msg;
      log.appendChild(line);
      log.scrollTop = log.scrollHeight;
    };
    window.rsWorkDone = function (data) {
      if (!data || !data.ok) return;
      if (data.action === 'propose') {
        applyPropose(data);
      } else if (data.action === 'preview' && data.html) {
        var wrap = qs('#ar-sum-preview-wrap');
        var box = qs('#ar-sum-preview');
        if (wrap && box) {
          wrap.style.display = '';
          box.innerHTML = data.html;
          bindLeaders(box);
          wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      }
    };

    function startWork(action) {
      var log = qs('#ar-sum-progress-log');
      var panel = qs('#ar-sum-progress');
      var frame = qs('#ar-sum-work-frame');
      if (log) log.innerHTML = '';
      if (panel) panel.style.display = '';
      form.setAttribute('target', 'ar_sum_work_frame');
      var act = document.createElement('input');
      act.type = 'hidden';
      act.name = 'action';
      act.value = action;
      act.id = 'ar-sum-work-action';
      var prev = qs('#ar-sum-work-action');
      if (prev) prev.parentNode.removeChild(prev);
      form.appendChild(act);
      form.action = '/audio/summaries/work';
      form.method = 'post';
      form.submit();
      setTimeout(function () {
        form.removeAttribute('target');
        form.action = form.getAttribute('data-orig-action') || form.action;
        var a = qs('#ar-sum-work-action');
        if (a) a.parentNode.removeChild(a);
      }, 500);
    }

    form.setAttribute('data-orig-action', form.getAttribute('action') || '');

    function applyPropose(data) {
      if (data.name) {
        var name = qs('#ar-sum-name');
        if (name) name.value = data.name;
      }
      var metrics = qs('#ar-sum-metrics-block');
      if (metrics) metrics.style.display = '';
      if (data.catalog) {
        var info = qs('#ar-sum-catalog-info');
        if (info) {
          info.textContent = (data.catalog.position_name || '') + ': '
            + (data.catalog.types || []).length + ' / '
            + (data.catalog.scripts || []).length + ' / '
            + (data.catalog.dicts || []).length;
        }
        fillChecks('ar-sum-wrap-criteria', 'criteria_type_ids', data.catalog.types || [], (data.config && data.config.criteria_type_ids) || []);
        fillChecks('ar-sum-wrap-scripts', 'script_ids', data.catalog.scripts || [], (data.config && data.config.script_ids) || []);
        fillChecks('ar-sum-wrap-dicts', 'tracking_dict_ids', data.catalog.dicts || [], (data.config && data.config.tracking_dict_ids) || [], 'TRACKING_CODE');
        fillChecks('ar-sum-wrap-detail-criteria', 'detail_criteria_ids', data.catalog.types || [], (data.config && data.config.detail_criteria_ids) || []);
        fillChecks('ar-sum-wrap-detail-tracking', 'detail_tracking_dict_ids', data.catalog.dicts || [], (data.config && data.config.detail_tracking_dict_ids) || [], 'TRACKING_CODE');
      }
      if (data.config && data.config.cards) {
        qsa('input[name="cards[]"]').forEach(function (cb) {
          cb.checked = data.config.cards.indexOf(cb.value) >= 0;
        });
      }
      if (data.labels) {
        Object.keys(data.labels).forEach(function (k) {
          var ta = qs('[name="label_' + k + '"]');
          if (ta) ta.value = data.labels[k] || '';
        });
      }
    }

    function fillChecks(wrapId, name, items, selected, extraKey) {
      var wrap = qs('#' + wrapId);
      if (!wrap) return;
      var html = '<div class="ar-sum-check-list">';
      if (!items.length) {
        html += '<div class="muted">—</div>';
      } else {
        items.forEach(function (it) {
          var id = parseInt(it.ID, 10) || 0;
          var checked = selected.indexOf(id) >= 0 || selected.indexOf(String(id)) >= 0 ? ' checked' : '';
          var extra = '';
          if (extraKey && it[extraKey]) extra = ' <span class="muted">[' + it[extraKey] + ']</span>';
          html += '<label><input type="checkbox" name="' + name + '[]" value="' + id + '"' + checked + '> '
            + escapeHtml(it.NAME || '') + extra + '</label>';
        });
      }
      html += '</div>';
      wrap.innerHTML = html;
    }

    var btnPropose = qs('#ar-sum-btn-propose');
    if (btnPropose) btnPropose.addEventListener('click', function () {
      var pos = qs('#ar-sum-position');
      if (!pos || parseInt(pos.value, 10) <= 0) {
        alert((window.arSumI18n && arSumI18n.needPosition) || 'Select position');
        return;
      }
      startWork('propose');
    });
    var btnPreview = qs('#ar-sum-btn-preview');
    if (btnPreview) btnPreview.addEventListener('click', function () {
      var pos = qs('#ar-sum-position');
      if (!pos || parseInt(pos.value, 10) <= 0) {
        alert((window.arSumI18n && arSumI18n.needPosition) || 'Select position');
        return;
      }
      startWork('preview');
    });
  }

  /* ——— Calendar bulk reparse (oper period panel) ——— */
  function bindBulkReparse(root) {
    root = root || document;
    var panel = qs('#ar-bulk-reparse', root);
    if (!panel || panel.getAttribute('data-bound') === '1') return;
    panel.setAttribute('data-bound', '1');

    var i18n = {};
    try { i18n = JSON.parse(panel.getAttribute('data-i18n') || '{}') || {}; } catch (e) { i18n = {}; }
    var endpoint = panel.getAttribute('data-endpoint') || '/audio/calendar/reparse';
    var btn = qs('#ar-bulk-start', panel);
    var fromEl = qs('#ar-bulk-from', panel);
    var toEl = qs('#ar-bulk-to', panel);
    var progress = qs('#ar-bulk-progress', panel);
    var logEl = qs('#ar-bulk-log', panel);
    var statusEl = qs('#ar-bulk-status', panel);
    var barEl = qs('#ar-bulk-bar', panel);
    var curEl = qs('#ar-bulk-current', panel);
    var doneEl = qs('#ar-bulk-done', panel);
    var totalEl = qs('#ar-bulk-total', panel);
    var busy = false;

    function addLog(msg, cls) {
      if (!logEl) return;
      var row = document.createElement('div');
      if (cls) row.className = cls;
      var t = new Date().toLocaleTimeString();
      row.textContent = '[' + t + '] ' + msg;
      logEl.appendChild(row);
      logEl.scrollTop = logEl.scrollHeight;
    }

    function buildDates(fromStr, toStr) {
      var out = [];
      var a = fromStr.split('-').map(Number);
      var b = toStr.split('-').map(Number);
      if (a.length !== 3 || b.length !== 3) return out;
      var cur = new Date(a[0], a[1] - 1, a[2]);
      var end = new Date(b[0], b[1] - 1, b[2]);
      if (isNaN(cur.getTime()) || isNaN(end.getTime()) || cur > end) return out;
      var guard = 0;
      while (cur <= end && guard < 400) {
        var y = cur.getFullYear();
        var m = String(cur.getMonth() + 1).padStart(2, '0');
        var d = String(cur.getDate()).padStart(2, '0');
        out.push(y + '-' + m + '-' + d);
        cur.setDate(cur.getDate() + 1);
        guard++;
      }
      return out;
    }

    function qsCost(params) {
      var parts = [];
      Object.keys(params || {}).forEach(function (k) {
        if (params[k] === undefined || params[k] === null || params[k] === '') return;
        parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(String(params[k])));
      });
      return parts.join('&');
    }

    function fetchJson(url) {
      return fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (res) {
        return res.text().then(function (txt) {
          var data = null;
          try { data = JSON.parse(txt); } catch (e) { data = null; }
          if (!res.ok) {
            var err = (data && data.message) ? data.message : ('HTTP ' + res.status);
            throw new Error(err);
          }
          if (!data) throw new Error('bad_json');
          return data;
        });
      });
    }

    function sleep(ms) {
      return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    function reparseDay(date) {
      var step = 'auto';
      var costQ = {};
      var guard = 0;
      function tick() {
        if (guard++ > 80) {
          return Promise.reject(new Error('too_many_steps'));
        }
        var url = endpoint + '?json=1&date=' + encodeURIComponent(date)
          + '&step=' + encodeURIComponent(step)
          + (Object.keys(costQ).length ? '&' + qsCost(costQ) : '');
        return fetchJson(url).then(function (data) {
          if (data.cost_query && typeof data.cost_query === 'object') {
            costQ = data.cost_query;
          }
          if (data.next === 'stuck' || data.ok === false) {
            throw new Error(data.message || 'stuck');
          }
          if (data.message === 'reparse_done' || data.next === 'done') {
            return data;
          }
          if (data.next === 'stem') {
            step = 'stem';
            var pend = data.pending != null ? data.pending : '?';
            addLog((i18n.dayStem || '{date}: stem, pending {pending}')
              .replace('{date}', date).replace('{pending}', String(pend)), 'ar-bulk-log-info');
            return sleep(300).then(tick);
          }
          if (data.next === 'collect') {
            step = 'collect';
            return sleep(200).then(tick);
          }
          // Unexpected — treat as done if ok
          if (data.ok) return data;
          throw new Error(data.message || 'unknown');
        });
      }
      return tick();
    }

    function setProgress(done, total, current) {
      if (doneEl) doneEl.textContent = String(done);
      if (totalEl) totalEl.textContent = String(total);
      if (curEl) curEl.textContent = current || '—';
      if (barEl) {
        var pct = total > 0 ? Math.round((done / total) * 100) : 0;
        barEl.style.width = pct + '%';
      }
    }

    if (!btn || !fromEl || !toEl) return;
    btn.addEventListener('click', function () {
      if (busy) {
        alert(i18n.busy || 'Busy');
        return;
      }
      var dates = buildDates(fromEl.value, toEl.value);
      if (!dates.length) {
        alert(i18n.badRange || i18n.empty || 'Bad range');
        return;
      }
      var conf = (i18n.confirm || 'Reparse {n} days ({from} — {to})?')
        .replace('{n}', String(dates.length))
        .replace('{from}', fromEl.value)
        .replace('{to}', toEl.value);
      if (!window.confirm(conf)) return;

      busy = true;
      btn.disabled = true;
      if (progress) progress.hidden = false;
      if (logEl) logEl.innerHTML = '';
      if (statusEl) {
        statusEl.textContent = i18n.running || 'Running…';
        statusEl.className = 'ar-cal-bulk-reparse-status is-run';
      }
      setProgress(0, dates.length, dates[0]);

      var idx = 0;
      function next() {
        if (idx >= dates.length) {
          busy = false;
          btn.disabled = false;
          setProgress(dates.length, dates.length, '—');
          if (statusEl) {
            statusEl.textContent = i18n.done || 'Done';
            statusEl.className = 'ar-cal-bulk-reparse-status is-ok';
          }
          addLog(i18n.done || 'Done', 'ar-bulk-log-ok');
          return;
        }
        var date = dates[idx];
        setProgress(idx, dates.length, date);
        addLog((i18n.dayStart || '{date}…').replace('{date}', date), 'ar-bulk-log-info');
        reparseDay(date).then(function (data) {
          var cols = (data && data.collections != null) ? data.collections : 0;
          var proc = (data && data.processed != null) ? data.processed : 0;
          var sec = (data && data.time != null) ? Number(data.time) : 0;
          addLog((i18n.dayOk || '{date}: ok (admins {processed}, collections {collections}, {time}s)')
            .replace('{date}', date)
            .replace('{processed}', String(proc))
            .replace('{collections}', String(cols))
            .replace('{time}', sec ? sec.toFixed(2) : '?'), 'ar-bulk-log-ok');
          idx++;
          setProgress(idx, dates.length, dates[idx] || '—');
          return sleep(150).then(next);
        }).catch(function (err) {
          addLog((i18n.dayErr || '{date}: error ({message})')
            .replace('{date}', date)
            .replace('{message}', (err && err.message) ? err.message : String(err)), 'ar-bulk-log-err');
          idx++;
          setProgress(idx, dates.length, dates[idx] || '—');
          return sleep(800).then(next);
        });
      }
      next();
    });
  }

  function bindCollectionSeek(root) {
    root = root || document;
    var wrap = qs('#ar-col-transcript', root) || qs('.ar-col-transcript-full', root);
    if (!wrap) return;
    wrap.addEventListener('click', function (ev) {
      var line = ev.target.closest('.transcript-line');
      if (!line) return;
      var block = line.closest('.ar-col-record-block');
      if (!block) return;
      var audio = qs('audio', block);
      if (!audio) return;
      var t = parseFloat(line.getAttribute('data-time-start') || '0');
      if (!(t >= 0)) return;
      wrap.querySelectorAll('.transcript-line.is-active').forEach(function (el) {
        el.classList.remove('is-active');
      });
      line.classList.add('is-active');
      try {
        audio.currentTime = t;
        var p = audio.play();
        if (p && typeof p.catch === 'function') p.catch(function () {});
      } catch (e) { /* ignore */ }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    bindLeaders(document);
    bindMonthNav(document);
    bindConstructor();
    bindBulkReparse(document);
    bindCollectionSeek(document);
  });
})();
