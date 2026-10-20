/**
 * Nazop matrix helpers (delegation — works for AJAX-loaded dialog fragments too).
 */
(function () {
  'use strict';

  var SHIFTS_HIDDEN_KEY = 'freesaas.nazop.shiftsHidden';

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

  function setShiftsHidden(form, hidden) {
    if (!form) {
      return;
    }
    form.classList.toggle('is-shifts-hidden', !!hidden);
    var hideBtn = form.querySelector('[data-nazop-hide-shifts]');
    var showBtn = form.querySelector('[data-nazop-show-shifts]');
    if (hideBtn) {
      hideBtn.hidden = !!hidden;
    }
    if (showBtn) {
      showBtn.hidden = !hidden;
    }
    try {
      sessionStorage.setItem(SHIFTS_HIDDEN_KEY, hidden ? '1' : '0');
    } catch (err) { /* ignore */ }
  }

  function restoreShiftsHidden(form) {
    var hidden = false;
    try {
      hidden = sessionStorage.getItem(SHIFTS_HIDDEN_KEY) === '1';
    } catch (err) { /* ignore */ }
    if (hidden) {
      setShiftsHidden(form, true);
    }
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) {
      return;
    }

    var hideBtn = t.closest('[data-nazop-hide-shifts]');
    if (hideBtn) {
      e.preventDefault();
      setShiftsHidden(hideBtn.closest('form.nazop-form'), true);
      return;
    }
    var showBtn = t.closest('[data-nazop-show-shifts]');
    if (showBtn) {
      e.preventDefault();
      setShiftsHidden(showBtn.closest('form.nazop-form'), false);
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

  function bootForms(root) {
    (root || document).querySelectorAll('form.nazop-form').forEach(restoreShiftsHidden);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { bootForms(document); });
  } else {
    bootForms(document);
  }
  // Calendar/dialog loads nazop via fetch+innerHTML — restore hide state on insert.
  if (typeof MutationObserver !== 'undefined') {
    var mo = new MutationObserver(function (mutations) {
      for (var i = 0; i < mutations.length; i++) {
        var nodes = mutations[i].addedNodes;
        for (var j = 0; j < nodes.length; j++) {
          var n = nodes[j];
          if (!n || n.nodeType !== 1) {
            continue;
          }
          if (n.matches && n.matches('form.nazop-form')) {
            restoreShiftsHidden(n);
          } else if (n.querySelector) {
            bootForms(n);
          }
        }
      }
    });
    mo.observe(document.documentElement, { childList: true, subtree: true });
  }
})();
