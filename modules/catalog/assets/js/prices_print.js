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

  function rebuildUrl(sheet, hiddenIds) {
    var base = sheet.getAttribute('data-base-qs') || '';
    var params = new URLSearchParams(base);
    if (hiddenIds.length) {
      params.set('hidden', hiddenIds.join(','));
    } else {
      params.delete('hidden');
    }
    var qs = params.toString();
    return '/catalog/prices/print' + (qs ? '?' + qs : '');
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.price-list-hide-btn');
    if (!btn) return;
    ev.preventDefault();
    var sheet = document.querySelector('[data-price-list]');
    if (!sheet) return;
    var id = parseInt(btn.getAttribute('data-good-id') || '0', 10);
    if (id < 1) return;
    var hidden = unique(parseHidden(sheet.getAttribute('data-hidden')).concat([id]));
    window.location.href = rebuildUrl(sheet, hidden);
  });
})();
