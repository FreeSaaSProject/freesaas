(function () {
  'use strict';

  function parseHidden(raw) {
    if (!raw) return [];
    return String(raw)
      .split(',')
      .map(function (x) { return parseInt(x, 10); })
      .filter(function (n) { return n > 0; });
  }

  function unique(ids) {
    var seen = {};
    var out = [];
    ids.forEach(function (id) {
      if (!seen[id]) {
        seen[id] = true;
        out.push(id);
      }
    });
    return out;
  }

  function sheetEl() {
    return document.querySelector('[data-price-list]');
  }

  function clampScale(pct) {
    pct = parseInt(pct, 10) || 100;
    if (pct < 50) return 50;
    if (pct > 200) return 200;
    return pct;
  }

  function applyScale(pct) {
    var sheet = sheetEl();
    if (!sheet) return;
    pct = clampScale(pct);
    sheet.style.setProperty('--pl-scale', String(pct / 100));
    sheet.setAttribute('data-scale', String(pct));
    sheet.setAttribute('data-scale-auto', '0');
    var label = document.querySelector('[data-price-scale-label]');
    if (label) label.textContent = pct + '%';
  }

  function syncUrl(pct) {
    var sheet = sheetEl();
    if (!sheet) return;
    var params = new URLSearchParams(sheet.getAttribute('data-base-qs') || window.location.search.replace(/^\?/, ''));
    params.set('scale', String(clampScale(pct)));
    params.set('print', '1');
    var qs = params.toString();
    var url = '/catalog/prices/print' + (qs ? '?' + qs : '');
    sheet.setAttribute('data-base-qs', qs);
    if (window.history && window.history.replaceState) {
      window.history.replaceState(null, '', url);
    }
  }

  function rebuildUrl(sheet, hiddenIds) {
    var base = sheet.getAttribute('data-base-qs') || '';
    var params = new URLSearchParams(base);
    if (hiddenIds.length) {
      params.set('hidden', hiddenIds.join(','));
    } else {
      params.delete('hidden');
    }
    var scale = clampScale(sheet.getAttribute('data-scale') || '100');
    params.set('scale', String(scale));
    var qs = params.toString();
    return '/catalog/prices/print' + (qs ? '?' + qs : '');
  }

  /**
   * Printable A4 content height in CSS px.
   * Matches layout_print @page margin 10mm + sheet padding ~3mm top/bottom.
   */
  function pageContentHeightPx(landscape) {
    var pageMargin = 10;
    var sheetPad = 3;
    var pageH = landscape ? 210 : 297;
    var mm = pageH - 2 * pageMargin - 2 * sheetPad;
    var probe = document.createElement('div');
    probe.style.cssText = 'position:absolute;visibility:hidden;height:' + mm + 'mm;';
    document.body.appendChild(probe);
    var px = probe.offsetHeight;
    document.body.removeChild(probe);
    return Math.max(px, 100);
  }

  function contentHeight() {
    var sheet = sheetEl();
    if (!sheet) return 0;
    return sheet.scrollHeight;
  }

  /** Maximize scale so the sheet fits one printable page. */
  function autoFit() {
    var sheet = sheetEl();
    if (!sheet) return 100;
    var landscape = sheet.getAttribute('data-landscape') === '1';
    var limit = pageContentHeightPx(landscape) * 0.98;
    var lo = 50;
    var hi = 200;
    var best = 50;
    var i;
    // Coarse then fine binary search
    for (i = 0; i < 18; i++) {
      var mid = Math.round((lo + hi) / 2);
      applyScale(mid);
      // Force layout
      void sheet.offsetHeight;
      if (contentHeight() <= limit) {
        best = mid;
        lo = mid + 1;
      } else {
        hi = mid - 1;
      }
    }
    applyScale(best);
    syncUrl(best);
    return best;
  }

  document.addEventListener('click', function (ev) {
    var hideBtn = ev.target.closest('.price-list-hide-btn');
    if (hideBtn) {
      ev.preventDefault();
      var sheet = sheetEl();
      if (!sheet) return;
      var id = parseInt(hideBtn.getAttribute('data-good-id') || '0', 10);
      if (id < 1) return;
      var hidden = unique(parseHidden(sheet.getAttribute('data-hidden')).concat([id]));
      window.location.href = rebuildUrl(sheet, hidden);
      return;
    }

    var deltaBtn = ev.target.closest('[data-price-scale-delta]');
    if (deltaBtn) {
      ev.preventDefault();
      var sheet2 = sheetEl();
      if (!sheet2) return;
      var delta = parseInt(deltaBtn.getAttribute('data-price-scale-delta') || '0', 10);
      var next = clampScale((parseInt(sheet2.getAttribute('data-scale') || '100', 10) || 100) + delta);
      applyScale(next);
      syncUrl(next);
      return;
    }

    var fitBtn = ev.target.closest('[data-price-scale-autofit]');
    if (fitBtn) {
      ev.preventDefault();
      autoFit();
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    var sheet = sheetEl();
    if (!sheet) return;
    var pct = clampScale(sheet.getAttribute('data-scale') || '100');
    applyScale(pct);
    // Print mode without explicit scale → fill the page automatically.
    if (sheet.classList.contains('price-list-sheet--print') && sheet.getAttribute('data-scale-auto') === '1') {
      // Defer so fonts/CSS columns settle.
      window.setTimeout(function () {
        autoFit();
      }, 50);
    }
  });
})();
