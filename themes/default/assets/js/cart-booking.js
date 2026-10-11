(function () {
  'use strict';

  var form = document.getElementById('cart-checkout-form');
  if (!form || form.getAttribute('data-booking') !== '1') {
    return;
  }

  var productIds = form.getAttribute('data-product-ids') || '';
  var ftThreshold = parseInt(form.getAttribute('data-ft-threshold') || '0', 10) || 0;
  var ftEligible = form.getAttribute('data-ft-eligible') === '1';
  var ftHintText = form.getAttribute('data-ft-hint') || '';
  var ftNotToday = form.getAttribute('data-ft-not-today') || '';
  var i18n = {
    loading: form.getAttribute('data-i18n-loading') || '…',
    empty: form.getAttribute('data-i18n-empty') || '',
    pick: form.getAttribute('data-i18n-pick') || '',
    pickMaster: form.getAttribute('data-i18n-pick-master') || '',
    change: form.getAttribute('data-i18n-change') || '×',
    needDate: form.getAttribute('data-i18n-need-date') || '',
    needMaster: form.getAttribute('data-i18n-need-master') || '',
    stepOk: form.getAttribute('data-i18n-step-ok') || 'OK',
    anyMaster: form.getAttribute('data-i18n-any-master') || 'Any',
    months: (form.getAttribute('data-i18n-months') || '').split('|'),
    weekdays: (form.getAttribute('data-i18n-weekdays') || '').split('|')
  };
  if (i18n.months.length < 12) {
    i18n.months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  }
  if (i18n.weekdays.length < 7) {
    i18n.weekdays = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
  }

  var ANY = 'any';
  var mode = 'date'; // date | master
  var state = {
    date: '',
    operatorId: ANY, // ANY | number
    dtStart: 0,
    dayCache: null,
    masters: null,
    dates: null,
    openDates: {}, // ymd -> slots count (bookable days only)
    openDatesReady: false,
    slotOps: {} // ts -> preferred operator id
  };
  var reqToken = 0;
  var calMonth = null; // Date at 1st of visible month

  var modeBtns = form.querySelectorAll('[data-booking-mode]');
  var stepEls = {
    date: document.getElementById('cart-step-date'),
    master: document.getElementById('cart-step-master'),
    time: document.getElementById('cart-step-time')
  };
  var dateEl = document.getElementById('cart-booking-date');
  var calEl = document.getElementById('cart-booking-calendar');
  var opHidden = document.getElementById('cart-booking-operator');
  var slotHidden = document.getElementById('cart-booking-slot');
  var mastersEl = document.getElementById('cart-masters');
  var slotsEl = document.getElementById('cart-slots');
  var datesEl = document.getElementById('cart-dates');
  var statusEl = document.getElementById('cart-booking-status');
  var ftWrap = document.getElementById('cart-free-ticket-wrap');
  var ftCb = document.getElementById('cart-free-ticket');
  var ftHint = document.getElementById('cart-free-ticket-hint');
  var contactEl = document.getElementById('cart-booking-contact');
  var submitBtn = form.querySelector('[type="submit"]');

  function todayYmd() {
    var d = new Date();
    return ymd(d);
  }

  function ymd(d) {
    var m = d.getMonth() + 1;
    var day = d.getDate();
    return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
  }

  function parseYmd(s) {
    var p = String(s || '').split('-');
    if (p.length !== 3) return null;
    var d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
    return isNaN(d.getTime()) ? null : d;
  }

  function setStatus(msg, show) {
    if (!statusEl) return;
    statusEl.textContent = msg || '';
    statusEl.hidden = !show;
  }

  function stepOrder() {
    return mode === 'master' ? ['master', 'date', 'time'] : ['date', 'master', 'time'];
  }

  function currentStepIndex() {
    var order = stepOrder();
    if (mode === 'date') {
      if (!state.date) return 0;
      if (state.operatorId === 0 || state.operatorId === '') return 1;
      if (!state.dtStart) return 2;
      return 3;
    }
    if (!state.operatorId || state.operatorId === 0) return 0;
    if (!state.date) return 1;
    if (!state.dtStart) return 2;
    return 3;
  }

  function isAnyMaster() {
    return state.operatorId === ANY;
  }

  function concreteOperatorId() {
    if (isAnyMaster()) {
      if (state.dtStart && state.slotOps[state.dtStart]) {
        return state.slotOps[state.dtStart];
      }
      return 0;
    }
    return parseInt(state.operatorId, 10) || 0;
  }

  function renderSteps() {
    var order = stepOrder();
    var cur = currentStepIndex();
    var root = document.getElementById('cart-booking-steps');
    if (root) {
      order.forEach(function (key, idx) {
        var el = stepEls[key];
        if (!el) return;
        root.appendChild(el);
        var locked = idx > cur;
        var done = idx < cur;
        el.classList.toggle('is-locked', locked);
        el.classList.toggle('is-active', idx === cur);
        el.classList.toggle('is-done', done);
        el.setAttribute('aria-disabled', locked ? 'true' : 'false');
        var fields = el.querySelectorAll('input, select, button, textarea');
        for (var i = 0; i < fields.length; i++) {
          if (fields[i].type === 'hidden') continue;
          if (fields[i].closest('.cart-calendar')) {
            fields[i].disabled = locked;
            continue;
          }
          fields[i].disabled = locked;
        }
        var mark = el.querySelector('.cart-step-ok');
        if (mark) mark.hidden = !done;
      });
    }

    if (dateEl) {
      dateEl.disabled = mode === 'master' && !state.operatorId;
    }
    if (calEl) {
      calEl.hidden = mode === 'master';
    }
    if (datesEl) {
      datesEl.hidden = mode !== 'master';
    }

    var hintMaster = form.querySelector('[data-hint-for="master"]');
    var hintTime = form.querySelector('[data-hint-for="time"]');
    if (hintMaster) {
      if (mode === 'date' && !state.date) {
        hintMaster.textContent = i18n.needDate;
        hintMaster.hidden = false;
      } else {
        hintMaster.hidden = true;
      }
    }
    if (hintTime) {
      if (!state.date || (state.operatorId !== ANY && !state.operatorId)) {
        hintTime.textContent = i18n.needMaster;
        hintTime.hidden = false;
      } else {
        hintTime.hidden = true;
      }
    }

    var ready = !!(state.date && state.dtStart && (isAnyMaster() || concreteOperatorId()));
    if (contactEl) {
      contactEl.classList.toggle('is-locked', !ready);
      contactEl.querySelectorAll('input, textarea, button').forEach(function (el) {
        if (el === submitBtn) return;
        el.disabled = !ready;
      });
    }
    if (submitBtn) submitBtn.disabled = !ready;

    if (opHidden) {
      opHidden.value = concreteOperatorId() ? String(concreteOperatorId()) : (isAnyMaster() ? '0' : '');
    }
    if (slotHidden) slotHidden.value = state.dtStart ? String(state.dtStart) : '';
    updateFreeTicket();
  }

  function updateFreeTicket() {
    if (ftThreshold < 1) {
      if (ftWrap) ftWrap.hidden = true;
      if (ftCb) { ftCb.checked = false; ftCb.disabled = true; }
      return;
    }
    var isToday = !!(state.date && state.date === todayYmd());
    // Checkbox only when eligible and not booking for today (slot optional for visibility).
    var canCheck = ftEligible && !!state.date && !isToday;
    if (ftWrap) ftWrap.hidden = !canCheck;
    if (ftCb) {
      ftCb.disabled = !canCheck;
      if (!canCheck) ftCb.checked = false;
    }
    if (ftHint) {
      if (!ftEligible && ftHintText) ftHint.textContent = ftHintText;
      else if (ftEligible && isToday && ftNotToday) ftHint.textContent = ftNotToday;
      else if (ftEligible && ftHintText) ftHint.textContent = ftHintText;
      else ftHint.textContent = '';
    }
  }

  function fetchJson(url) {
    var token = ++reqToken;
    return fetch(url, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (token !== reqToken) return null;
      return data;
    });
  }

  function masterCardHtml(op, selected) {
    var photo = op.photo || '';
    var name = op.display_name || i18n.pickMaster;
    var initial = op.initial || '•';
    var media = photo
      ? '<img class="cart-master-photo" src="' + escapeAttr(photo) + '" alt="">'
      : '<span class="cart-master-initial" aria-hidden="true">' + escapeHtml(initial) + '</span>';
    var idAttr = op.id === ANY ? 'any' : String(op.id);
    return '<button type="button" class="cart-master' + (selected ? ' is-selected' : '') +
      (op.id === ANY ? ' cart-master-any' : '') + '" data-op="' + escapeAttr(idAttr) + '">' +
      media +
      '<span class="cart-master-name">' + escapeHtml(name) + '</span>' +
      (selected ? '<span class="cart-master-change">' + escapeHtml(i18n.change) + '</span>' : '') +
      '</button>';
  }

  function anyMasterCard(selected) {
    return masterCardHtml({
      id: ANY,
      display_name: i18n.anyMaster,
      photo: '',
      initial: '★'
    }, selected);
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }
  function escapeAttr(s) {
    return escapeHtml(s).replace(/`/g, '');
  }

  function renderMasters(list, selectedOnly) {
    if (!mastersEl) return;
    mastersEl.innerHTML = '';
    var html = '';
    if (!selectedOnly || isAnyMaster()) {
      html += anyMasterCard(isAnyMaster());
    } else if (isAnyMaster()) {
      html += anyMasterCard(true);
    }
    if (selectedOnly && !isAnyMaster()) {
      var sel = findOp(list, state.operatorId);
      if (sel) html += masterCardHtml(sel, true);
      else html += anyMasterCard(false);
      mastersEl.innerHTML = html;
      return;
    }
    for (var i = 0; i < (list || []).length; i++) {
      var op = list[i];
      html += masterCardHtml(op, op.id === state.operatorId);
    }
    if (!list || !list.length) {
      if (!html) {
        mastersEl.innerHTML = '<p class="muted">' + escapeHtml(i18n.empty) + '</p>';
        return;
      }
    }
    mastersEl.innerHTML = html;
  }

  function mergedSlotsFromDay(data) {
    var byTs = {};
    var ops = (data && data.operators) || [];
    for (var i = 0; i < ops.length; i++) {
      var op = ops[i];
      var slots = op.slots || [];
      for (var j = 0; j < slots.length; j++) {
        var ts = slots[j];
        if (!byTs[ts]) {
          byTs[ts] = {
            ts: ts,
            label: (op.slots_hhmm && op.slots_hhmm[j]) || '',
            operators: []
          };
        }
        byTs[ts].operators.push(op.id);
        if (!byTs[ts].label && op.slots_hhmm && op.slots_hhmm[j]) {
          byTs[ts].label = op.slots_hhmm[j];
        }
      }
    }
    var list = Object.keys(byTs).map(function (k) { return byTs[k]; });
    list.sort(function (a, b) { return a.ts - b.ts; });
    state.slotOps = {};
    for (var n = 0; n < list.length; n++) {
      state.slotOps[list[n].ts] = list[n].operators[0];
    }
    return list;
  }

  function renderSlotsForOperator(op) {
    if (!slotsEl) return;
    slotsEl.innerHTML = '';
    if (!op || !op.slots || !op.slots.length) {
      slotsEl.innerHTML = '<p class="muted">' + escapeHtml(i18n.empty) + '</p>';
      return;
    }
    state.slotOps = {};
    var html = '';
    for (var i = 0; i < op.slots.length; i++) {
      var ts = op.slots[i];
      state.slotOps[ts] = op.id;
      var label = (op.slots_hhmm && op.slots_hhmm[i]) || String(ts);
      var sel = state.dtStart === ts ? ' is-selected' : '';
      html += '<button type="button" class="cart-slot' + sel + '" data-ts="' + ts + '">' + escapeHtml(label) + '</button>';
    }
    slotsEl.innerHTML = html;
  }

  function renderMergedSlots(data) {
    if (!slotsEl) return;
    var list = mergedSlotsFromDay(data);
    slotsEl.innerHTML = '';
    if (!list.length) {
      slotsEl.innerHTML = '<p class="muted">' + escapeHtml(i18n.empty) + '</p>';
      return;
    }
    var html = '';
    for (var i = 0; i < list.length; i++) {
      var row = list[i];
      var label = row.label || '';
      if (!label) {
        var d = new Date(row.ts * 1000);
        var hh = d.getHours();
        var mm = d.getMinutes();
        label = (hh < 10 ? '0' : '') + hh + ':' + (mm < 10 ? '0' : '') + mm;
      }
      var sel = state.dtStart === row.ts ? ' is-selected' : '';
      html += '<button type="button" class="cart-slot' + sel + '" data-ts="' + row.ts + '">' + escapeHtml(label) + '</button>';
    }
    slotsEl.innerHTML = html;
  }

  function renderDates(list) {
    if (!datesEl) return;
    datesEl.innerHTML = '';
    datesEl.hidden = mode !== 'master';
    if (mode !== 'master') return;
    if (!list || !list.length) {
      datesEl.innerHTML = '<p class="muted">' + escapeHtml(i18n.empty) + '</p>';
      return;
    }
    var html = '';
    for (var i = 0; i < list.length; i++) {
      var d = list[i];
      var sel = state.date === d.date ? ' is-selected' : '';
      html += '<button type="button" class="cart-date' + sel + '" data-date="' + escapeAttr(d.date) + '">' +
        escapeHtml(formatDateLabel(d.date)) + '</button>';
    }
    datesEl.innerHTML = html;
  }

  function formatDateLabel(ymdStr) {
    var d = parseYmd(ymdStr);
    if (!d) return ymdStr;
    return d.getDate() + ' ' + i18n.months[d.getMonth()] + ' ' + d.getFullYear();
  }

  function findOp(list, id) {
    var nid = parseInt(id, 10);
    for (var i = 0; i < (list || []).length; i++) {
      if (list[i].id === nid) return list[i];
    }
    return null;
  }

  function isOpenDate(ymdStr) {
    if (!ymdStr || ymdStr < todayYmd()) return false;
    if (!state.openDatesReady) return false;
    return !!state.openDates[ymdStr];
  }

  function firstOpenDate() {
    var keys = Object.keys(state.openDates).sort();
    return keys.length ? keys[0] : '';
  }

  function renderCalendar() {
    if (!calEl) return;
    if (!calMonth) {
      var base = parseYmd(state.date) || parseYmd(firstOpenDate()) || new Date();
      calMonth = new Date(base.getFullYear(), base.getMonth(), 1);
    }
    var y = calMonth.getFullYear();
    var m = calMonth.getMonth();
    var firstDow = (new Date(y, m, 1).getDay() + 6) % 7; // Mon=0
    var daysInMonth = new Date(y, m + 1, 0).getDate();
    var today = todayYmd();
    // Block navigating to months with no bookable days (past or beyond openDates).
    var todayD = parseYmd(today) || new Date();
    var canPrev = !(y < todayD.getFullYear() || (y === todayD.getFullYear() && m <= todayD.getMonth()));
    var nextMonthStart = ymd(new Date(y, m + 1, 1));
    var canNext = false;
    if (state.openDatesReady) {
      var openKeys = Object.keys(state.openDates);
      for (var oi = 0; oi < openKeys.length; oi++) {
        if (openKeys[oi] >= nextMonthStart) { canNext = true; break; }
      }
    }
    var html = '';
    html += '<div class="cart-calendar">';
    html += '<div class="cart-calendar-head">';
    html += '<button type="button" class="cart-calendar-nav" data-cal-nav="-1" aria-label="prev"' +
      (canPrev ? '' : ' disabled') + '>‹</button>';
    html += '<div class="cart-calendar-title">' + escapeHtml(i18n.months[m] + ' ' + y) + '</div>';
    html += '<button type="button" class="cart-calendar-nav" data-cal-nav="1" aria-label="next"' +
      (canNext ? '' : ' disabled') + '>›</button>';
    html += '</div>';
    html += '<div class="cart-calendar-weekdays">';
    for (var w = 0; w < 7; w++) {
      html += '<span>' + escapeHtml(i18n.weekdays[w] || '') + '</span>';
    }
    html += '</div><div class="cart-calendar-grid">';
    for (var pad = 0; pad < firstDow; pad++) {
      html += '<span class="cart-calendar-cell is-empty"></span>';
    }
    for (var day = 1; day <= daysInMonth; day++) {
      var ds = ymd(new Date(y, m, day));
      var open = isOpenDate(ds);
      var disabled = !open;
      var selected = ds === state.date;
      var isToday = ds === today;
      html += '<button type="button" class="cart-calendar-cell' +
        (selected ? ' is-selected' : '') +
        (isToday ? ' is-today' : '') +
        (open ? ' is-open' : ' is-closed') +
        '"' +
        (disabled ? ' disabled' : '') +
        ' data-date="' + ds + '">' + day + '</button>';
    }
    html += '</div></div>';
    calEl.innerHTML = html;
  }

  function selectDate(ymdStr) {
    if (!isOpenDate(ymdStr)) {
      return;
    }
    state.date = ymdStr;
    state.dtStart = 0;
    if (dateEl) dateEl.value = ymdStr;
    if (mode === 'date') {
      state.operatorId = ANY;
    }
    renderCalendar();
    loadDay();
    renderSteps();
  }

  function loadOpenDates() {
    var op = (mode === 'master' && !isAnyMaster() && state.operatorId)
      ? String(state.operatorId)
      : '0';
    return fetchJson('/cart/availability?mode=dates&operator_id=' + encodeURIComponent(op) +
      '&product_ids=' + encodeURIComponent(productIds))
      .then(function (data) {
        state.openDates = {};
        state.openDatesReady = true;
        if (data && data.ok && data.dates) {
          for (var i = 0; i < data.dates.length; i++) {
            var row = data.dates[i];
            if (row && row.date) state.openDates[row.date] = row.slots || 1;
          }
        }
        // Snap selection to a bookable day.
        if (!isOpenDate(state.date)) {
          var next = firstOpenDate();
          state.date = next;
          if (dateEl) dateEl.value = next || '';
          state.dtStart = 0;
        }
        renderCalendar();
        if (state.date) return loadDay();
        renderSteps();
        return null;
      });
  }

  function loadDay() {
    if (!state.date || !productIds) return Promise.resolve();
    setStatus(i18n.loading, true);
    var url = '/cart/availability?date=' + encodeURIComponent(state.date) +
      '&product_ids=' + encodeURIComponent(productIds);
    if (mode === 'master' && !isAnyMaster() && state.operatorId) {
      url += '&operator_id=' + encodeURIComponent(String(state.operatorId));
    }
    return fetchJson(url).then(function (data) {
      if (!data) return;
      if (!data.ok) {
        setStatus(data.error || i18n.empty, true);
        state.dayCache = { operators: [] };
        renderMasters([], false);
        if (slotsEl) slotsEl.innerHTML = '<p class="muted">' + escapeHtml(i18n.empty) + '</p>';
        renderSteps();
        return;
      }
      setStatus('', false);
      state.dayCache = data;
      if (mode === 'date') {
        if (!state.operatorId) state.operatorId = ANY;
        if (isAnyMaster()) {
          renderMasters(data.operators, false);
          renderMergedSlots(data);
        } else {
          var op = findOp(data.operators, state.operatorId);
          if (!op) {
            state.operatorId = ANY;
            state.dtStart = 0;
            renderMasters(data.operators, false);
            renderMergedSlots(data);
          } else {
            renderMasters(data.operators, true);
            renderSlotsForOperator(op);
          }
        }
      } else {
        if (isAnyMaster()) {
          renderMergedSlots(data);
        } else {
          renderSlotsForOperator(findOp(data.operators, state.operatorId));
        }
      }
      renderSteps();
    }).catch(function () {
      setStatus(i18n.empty, true);
    });
  }

  function loadMasters() {
    setStatus(i18n.loading, true);
    return fetchJson('/cart/availability?mode=masters&product_ids=' + encodeURIComponent(productIds))
      .then(function (data) {
        if (!data) return;
        if (!data.ok) {
          setStatus(data.error || i18n.empty, true);
          state.masters = [];
          renderMasters([], false);
          renderSteps();
          return;
        }
        setStatus('', false);
        state.masters = data.operators || [];
        if (!state.operatorId) state.operatorId = ANY;
        renderMasters(state.masters, !!state.operatorId && !isAnyMaster());
        renderSteps();
      });
  }

  function loadDatesForMaster() {
    if (isAnyMaster() || !state.operatorId) return Promise.resolve();
    setStatus(i18n.loading, true);
    return fetchJson('/cart/availability?mode=dates&operator_id=' + encodeURIComponent(String(state.operatorId)) +
      '&product_ids=' + encodeURIComponent(productIds))
      .then(function (data) {
        if (!data) return;
        if (!data.ok) {
          setStatus(data.error || i18n.empty, true);
          state.dates = [];
          renderDates([]);
          renderSteps();
          return;
        }
        setStatus('', false);
        state.dates = data.dates || [];
        renderDates(state.dates);
        renderSteps();
      });
  }

  function setMode(next) {
    if (next !== 'date' && next !== 'master') return;
    mode = next;
    state = {
      date: dateEl ? dateEl.value : '',
      operatorId: ANY,
      dtStart: 0,
      dayCache: null,
      masters: null,
      dates: null,
      openDates: {},
      openDatesReady: false,
      slotOps: {}
    };
    if (mode === 'date' && !state.date && dateEl) {
      state.date = dateEl.value;
    }
    if (mode === 'master') {
      state.date = '';
      if (dateEl) dateEl.value = '';
    }
    modeBtns.forEach(function (btn) {
      btn.classList.toggle('is-active', btn.getAttribute('data-booking-mode') === mode);
    });
    if (datesEl) datesEl.hidden = mode !== 'master';
    if (calEl) calEl.hidden = mode === 'master';
    if (mode === 'date') {
      renderDates([]);
      setStatus(i18n.loading, true);
      loadOpenDates();
    } else {
      if (mastersEl) mastersEl.innerHTML = '';
      if (slotsEl) slotsEl.innerHTML = '';
      loadMasters();
    }
    renderSteps();
  }

  modeBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      setMode(btn.getAttribute('data-booking-mode'));
    });
  });

  if (calEl) {
    calEl.addEventListener('click', function (ev) {
      var nav = ev.target.closest('[data-cal-nav]');
      if (nav) {
        if (nav.disabled) return;
        var dir = parseInt(nav.getAttribute('data-cal-nav'), 10) || 0;
        if (!calMonth) calMonth = new Date();
        var next = new Date(calMonth.getFullYear(), calMonth.getMonth() + dir, 1);
        var todayD = parseYmd(todayYmd()) || new Date();
        if (dir < 0 && (next.getFullYear() < todayD.getFullYear()
          || (next.getFullYear() === todayD.getFullYear() && next.getMonth() < todayD.getMonth()))) {
          return;
        }
        calMonth = next;
        renderCalendar();
        return;
      }
      var cell = ev.target.closest('[data-date]');
      if (!cell || cell.disabled) return;
      selectDate(cell.getAttribute('data-date') || '');
    });
  }

  if (dateEl) {
    dateEl.addEventListener('change', function () {
      selectDate(dateEl.value);
    });
  }

  if (mastersEl) {
    mastersEl.addEventListener('click', function (ev) {
      var btn = ev.target.closest('[data-op]');
      if (!btn || btn.disabled) return;
      var raw = btn.getAttribute('data-op') || '';
      var id = raw === 'any' ? ANY : (parseInt(raw, 10) || 0);
      if (state.operatorId === id && id !== ANY) {
        // change master → back to any
        state.operatorId = ANY;
        state.dtStart = 0;
        if (mode === 'master') {
          state.date = '';
          if (dateEl) dateEl.value = '';
          renderDates([]);
          renderMasters(state.masters || (state.dayCache && state.dayCache.operators) || [], false);
          if (slotsEl) slotsEl.innerHTML = '';
        } else {
          renderMasters((state.dayCache && state.dayCache.operators) || [], false);
          renderMergedSlots(state.dayCache || { operators: [] });
        }
        renderSteps();
        return;
      }
      if (state.operatorId === ANY && id === ANY) {
        return;
      }
      state.operatorId = id;
      state.dtStart = 0;
      if (mode === 'date') {
        if (isAnyMaster()) {
          renderMasters((state.dayCache && state.dayCache.operators) || [], false);
          renderMergedSlots(state.dayCache || { operators: [] });
        } else {
          renderMasters((state.dayCache && state.dayCache.operators) || [], true);
          renderSlotsForOperator(findOp(state.dayCache.operators, id));
        }
        renderSteps();
      } else {
        renderMasters(state.masters || [], !isAnyMaster());
        if (isAnyMaster()) {
          state.date = '';
          renderDates([]);
          if (slotsEl) slotsEl.innerHTML = '';
          renderSteps();
        } else {
          loadDatesForMaster();
        }
      }
    });
  }

  if (slotsEl) {
    slotsEl.addEventListener('click', function (ev) {
      var btn = ev.target.closest('[data-ts]');
      if (!btn || btn.disabled) return;
      state.dtStart = parseInt(btn.getAttribute('data-ts'), 10) || 0;
      if (isAnyMaster()) {
        renderMergedSlots(state.dayCache || { operators: [] });
      } else {
        var op = null;
        if (state.dayCache) op = findOp(state.dayCache.operators, state.operatorId);
        renderSlotsForOperator(op);
      }
      renderSteps();
    });
  }

  if (datesEl) {
    datesEl.addEventListener('click', function (ev) {
      var btn = ev.target.closest('[data-date]');
      if (!btn || btn.disabled) return;
      state.date = btn.getAttribute('data-date') || '';
      state.dtStart = 0;
      if (dateEl) dateEl.value = state.date;
      renderDates(state.dates || []);
      loadDay();
    });
  }

  // init default date-first + any master
  if (dateEl && dateEl.value) state.date = dateEl.value;
  state.operatorId = ANY;
  setMode('date');
})();
