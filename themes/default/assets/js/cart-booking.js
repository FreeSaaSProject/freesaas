(function () {
  'use strict';

  var form = document.getElementById('cart-checkout-form');
  if (!form || form.getAttribute('data-booking') !== '1') {
    return;
  }

  var dateEl = document.getElementById('cart-booking-date');
  var opEl = document.getElementById('cart-booking-operator');
  var slotEl = document.getElementById('cart-booking-slot');
  var statusEl = document.getElementById('cart-booking-status');
  var ftWrap = document.getElementById('cart-free-ticket-wrap');
  var ftCb = document.getElementById('cart-free-ticket');
  var ftHint = document.getElementById('cart-free-ticket-hint');

  var productIds = form.getAttribute('data-product-ids') || '';
  var ftThreshold = parseInt(form.getAttribute('data-ft-threshold') || '0', 10) || 0;
  var ftEligible = form.getAttribute('data-ft-eligible') === '1';
  var ftHintText = form.getAttribute('data-ft-hint') || '';
  var ftNotToday = form.getAttribute('data-ft-not-today') || '';
  var i18nLoading = form.getAttribute('data-i18n-loading') || '…';
  var i18nEmpty = form.getAttribute('data-i18n-empty') || '';
  var i18nPick = form.getAttribute('data-i18n-pick') || '';

  var cache = null;
  var reqToken = 0;

  function clearSelect(el, placeholder) {
    el.innerHTML = '';
    var opt = document.createElement('option');
    opt.value = '';
    opt.textContent = placeholder;
    el.appendChild(opt);
  }

  function setStatus(msg, show) {
    if (!statusEl) {
      return;
    }
    statusEl.textContent = msg || '';
    statusEl.hidden = !show;
  }

  function todayYmd() {
    var d = new Date();
    var m = d.getMonth() + 1;
    var day = d.getDate();
    return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
  }

  function updateFreeTicket() {
    if (!ftWrap || !ftCb) {
      return;
    }
    var date = dateEl ? dateEl.value : '';
    var isToday = date === todayYmd();
    var show = ftEligible && ftThreshold > 0 && !isToday;
    ftWrap.hidden = !show;
    if (!show) {
      ftCb.checked = false;
    }
    if (ftHint) {
      if (!ftEligible && ftHintText) {
        ftHint.textContent = ftHintText;
      } else if (ftEligible && isToday && ftNotToday) {
        ftHint.textContent = ftNotToday;
      } else if (ftEligible && !isToday && ftHintText) {
        ftHint.textContent = ftHintText;
      } else {
        ftHint.textContent = '';
      }
    }
  }

  function fillSlots(opId) {
    clearSelect(slotEl, i18nPick);
    if (!cache || !opId) {
      return;
    }
    var op = null;
    for (var i = 0; i < cache.operators.length; i++) {
      if (String(cache.operators[i].id) === String(opId)) {
        op = cache.operators[i];
        break;
      }
    }
    if (!op) {
      return;
    }
    var slots = op.slots || [];
    var hhmm = op.slots_hhmm || [];
    for (var j = 0; j < slots.length; j++) {
      var opt = document.createElement('option');
      opt.value = String(slots[j]);
      opt.textContent = hhmm[j] || String(slots[j]);
      slotEl.appendChild(opt);
    }
  }

  function fillOperators(data) {
    clearSelect(opEl, i18nPick);
    clearSelect(slotEl, i18nPick);
    var ops = (data && data.operators) || [];
    if (ops.length === 0) {
      setStatus(i18nEmpty, true);
      return;
    }
    setStatus('', false);
    for (var i = 0; i < ops.length; i++) {
      var op = ops[i];
      var opt = document.createElement('option');
      opt.value = String(op.id);
      opt.textContent = op.name + ' (' + (op.slots_hhmm || []).length + ')';
      opEl.appendChild(opt);
    }
    if (ops.length === 1) {
      opEl.value = String(ops[0].id);
      fillSlots(ops[0].id);
    }
  }

  function loadAvailability() {
    if (!dateEl || !productIds) {
      return;
    }
    var token = ++reqToken;
    clearSelect(opEl, i18nLoading);
    clearSelect(slotEl, i18nPick);
    setStatus(i18nLoading, true);
    cache = null;

    var url = '/cart/availability?date=' + encodeURIComponent(dateEl.value) +
      '&product_ids=' + encodeURIComponent(productIds);
    fetch(url, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (token !== reqToken) {
          return;
        }
        if (!data || !data.ok) {
          setStatus((data && data.error) || i18nEmpty, true);
          clearSelect(opEl, i18nPick);
          return;
        }
        cache = data;
        fillOperators(data);
        updateFreeTicket();
      })
      .catch(function () {
        if (token !== reqToken) {
          return;
        }
        setStatus(i18nEmpty, true);
        clearSelect(opEl, i18nPick);
      });
  }

  if (dateEl) {
    dateEl.addEventListener('change', function () {
      updateFreeTicket();
      loadAvailability();
    });
  }
  if (opEl) {
    opEl.addEventListener('change', function () {
      fillSlots(opEl.value);
    });
  }

  updateFreeTicket();
  loadAvailability();
})();
