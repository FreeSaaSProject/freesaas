(function () {
  'use strict';

  function fmtDisplay(n) {
    var s = (Math.round(n * 100) / 100).toFixed(2);
    return s.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  }

  function fmtField(n) {
    return (Math.round(n * 100) / 100).toFixed(2);
  }

  function parseQty(raw) {
    var s = String(raw || '').replace(/\s+/g, '').replace(',', '.');
    if (s === '') {
      return 0;
    }
    var n = parseFloat(s);
    return isFinite(n) && n >= 0 ? n : 0;
  }

  function selectAllOnFocus(input) {
    input.addEventListener('focus', function () {
      var el = this;
      requestAnimationFrame(function () {
        el.select();
      });
    });
    input.addEventListener('mouseup', function (e) {
      e.preventDefault();
    });
  }

  function bind(root) {
    var rows = root.querySelectorAll('[data-cash-row]');
    var totalEl = root.querySelector('[data-cash-total]');
    var clearBtn = root.querySelector('[data-cash-clear]');
    var targetInput = null;
    var syncEnabled = false;
    var sum = 0;

    function anyNonZero() {
      var found = false;
      rows.forEach(function (row) {
        var input = row.querySelector('[data-cash-qty]');
        if (input && parseQty(input.value) !== 0) {
          found = true;
        }
      });
      return found;
    }

    function updateClearVisibility() {
      if (!clearBtn) {
        return;
      }
      if (anyNonZero()) {
        clearBtn.removeAttribute('hidden');
      } else {
        clearBtn.setAttribute('hidden', 'hidden');
      }
    }

    function pushToTarget() {
      if (!syncEnabled || !targetInput) {
        return;
      }
      targetInput.value = fmtField(sum);
      targetInput.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function recalc() {
      sum = 0;
      rows.forEach(function (row) {
        var denom = parseFloat(row.getAttribute('data-denom') || '0') || 0;
        var input = row.querySelector('[data-cash-qty]');
        var lineEl = row.querySelector('[data-cash-line]');
        var qty = input ? parseQty(input.value) : 0;
        var line = denom * qty;
        sum += line;
        if (lineEl) {
          lineEl.textContent = fmtDisplay(line);
        }
      });
      if (totalEl) {
        totalEl.textContent = fmtDisplay(sum);
      }
      updateClearVisibility();
      pushToTarget();
    }

    function resetQtys() {
      rows.forEach(function (row) {
        var input = row.querySelector('[data-cash-qty]');
        if (input) {
          input.value = '0';
        }
      });
      recalc();
    }

    function setActiveOpenBtn(openBtn) {
      document.querySelectorAll('[data-cash-open]').forEach(function (btn) {
        btn.classList.toggle('is-active', btn === openBtn);
      });
    }

    function openFor(input, openBtn) {
      targetInput = input || null;
      syncEnabled = false;
      resetQtys();
      root.removeAttribute('hidden');
      setActiveOpenBtn(openBtn);
      root.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      var first = root.querySelector('[data-cash-qty]');
      if (first) {
        first.focus();
        first.select();
      }
    }

    function close() {
      targetInput = null;
      syncEnabled = false;
      root.setAttribute('hidden', 'hidden');
      setActiveOpenBtn(null);
    }

    rows.forEach(function (row) {
      var input = row.querySelector('[data-cash-qty]');
      if (!input) {
        return;
      }
      selectAllOnFocus(input);
      input.addEventListener('input', function () {
        syncEnabled = true;
        recalc();
      });
      input.addEventListener('change', function () {
        syncEnabled = true;
        recalc();
      });
    });

    if (clearBtn) {
      clearBtn.addEventListener('click', function (e) {
        e.preventDefault();
        syncEnabled = true;
        resetQtys();
      });
    }

    document.querySelectorAll('[data-cash-open]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var form = btn.closest('form');
        var input = form ? form.querySelector('input[name="bank_balance"]') : null;
        if (!input) {
          return;
        }
        if (!root.hasAttribute('hidden') && targetInput === input) {
          close();
          return;
        }
        openFor(input, btn);
      });
    });

    recalc();
  }

  document.querySelectorAll('[data-recons-cash-calc]').forEach(bind);
})();
