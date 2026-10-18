/**
 * Nazop matrix helpers (delegation — works for AJAX-loaded dialog fragments too).
 */
(function () {
  'use strict';

  function applyShiftToChecked(root, priceCol) {
    var val = String(priceCol);
    root.querySelectorAll('.nazop-cell').forEach(function (cell) {
      var cb = cell.querySelector('input[type="checkbox"]');
      var sel = cell.querySelector('select.nazop-op-price');
      if (cb && sel && cb.checked) {
        sel.value = val;
      }
    });
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) {
      return;
    }
    var btn = t.closest('[data-nazop-set-shift]');
    if (!btn) {
      return;
    }
    e.preventDefault();
    var form = btn.closest('form.nazop-form');
    if (!form) {
      return;
    }
    var bulk = form.querySelector('[data-nazop-bulk-shift]');
    if (!bulk) {
      return;
    }
    applyShiftToChecked(form, bulk.value);
  });
})();
