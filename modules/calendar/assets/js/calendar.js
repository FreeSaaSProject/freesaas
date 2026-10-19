(function () {
  'use strict';

  function bindBoardPopups(root) {
    var scope = root || document;
    var info = scope.querySelector('#calendar-board-info') || document.getElementById('calendar-board-info');
    if (!info) return;
    var touch = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
    var openChip = null;

    function hideInfo() {
      info.hidden = true;
      info.innerHTML = '';
      openChip = null;
    }
    function showInfo(chip, fromTouch) {
      var html = chip.getAttribute('data-info') || '';
      if (!html) return;
      info.innerHTML = html + (fromTouch
        ? '<div class="calendar-info-actions"><button type="button" class="btn-secondary btn-sm" data-info-close>OK</button></div>'
        : '');
      info.hidden = false;
      var rect = chip.getBoundingClientRect();
      info.style.top = (rect.bottom + window.scrollY + 4) + 'px';
      info.style.left = Math.max(8, rect.left + window.scrollX) + 'px';
      openChip = chip;
      var closer = info.querySelector('[data-info-close]');
      if (closer) closer.addEventListener('click', hideInfo);
    }

    scope.querySelectorAll('.calendar-board-chip').forEach(function (chip) {
      if (!touch) {
        chip.addEventListener('mouseenter', function () { showInfo(chip, false); });
        chip.addEventListener('mouseleave', function () { hideInfo(); });
      }
      chip.addEventListener('click', function (e) {
        e.preventDefault();
        if (openChip === chip && !info.hidden) {
          hideInfo();
          return;
        }
        showInfo(chip, true);
      });
    });
    document.addEventListener('click', function (e) {
      if (!info || info.hidden) return;
      if (info.contains(e.target)) return;
      if (openChip && openChip.contains(e.target)) return;
      hideInfo();
    });
  }

  function bindEventForm(form) {
    if (!form || form.getAttribute('data-event-form-bound') === '1') return;
    form.setAttribute('data-event-form-bound', '1');

    var blockCb = form.querySelector('#event-is-block');
    var clientSec = form.querySelector('#event-client-sec');
    var contentSec = form.querySelector('#event-content-sec');
    function syncBlock() {
      if (!blockCb || !clientSec || !contentSec) return;
      var on = blockCb.checked;
      clientSec.hidden = on;
      contentSec.hidden = on;
    }
    if (blockCb) {
      blockCb.addEventListener('change', syncBlock);
      syncBlock();
    }

    var phone = form.querySelector('#event-phone');
    var clientId = form.querySelector('#event-client-id');
    var clientName = form.querySelector('#event-client-name');
    var suggest = form.querySelector('#event-client-suggest');
    var statusEl = form.querySelector('#event-client-status');
    var msgPicked = form.getAttribute('data-i18n-client-picked') || '';
    var msgNew = form.getAttribute('data-i18n-client-new') || '';
    var msgEmpty = form.getAttribute('data-i18n-client-empty') || '';
    var clientTimer = null;

    function digits(v) { return String(v || '').replace(/\D+/g, ''); }
    function tpl(s, map) {
      return String(s || '').replace(/\{(\w+)\}/g, function (_, k) {
        return map[k] != null ? String(map[k]) : '';
      });
    }
    function setWalkin(extra) {
      if (clientId) clientId.value = '0';
      if (statusEl) statusEl.textContent = extra || msgNew;
    }
    function setPicked(item) {
      if (clientId) clientId.value = String(item.id || 0);
      if (phone && item.tel && window.SitesPhoneRu) {
        phone.value = SitesPhoneRu.format(item.tel);
      } else if (phone && item.tel) {
        phone.value = digits(item.tel);
      }
      if (clientName && item.name) clientName.value = item.name;
      if (statusEl) {
        statusEl.textContent = tpl(msgPicked, {
          name: item.name || '',
          tel: item.tel || '',
          id: item.id || ''
        });
      }
      if (suggest) {
        suggest.hidden = true;
        suggest.innerHTML = '';
      }
    }
    function renderClientList(items) {
      if (!suggest) return;
      suggest.innerHTML = '';
      if (!items.length) {
        suggest.hidden = true;
        setWalkin(msgEmpty + ' ' + msgNew);
        return;
      }
      var ul = document.createElement('ul');
      items.forEach(function (item) {
        var li = document.createElement('li');
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-secondary btn-sm';
        btn.textContent = (item.name || '') + ' · ' + (item.tel || '') + ' (#' + item.id + ')';
        btn.addEventListener('click', function () { setPicked(item); });
        li.appendChild(btn);
        ul.appendChild(li);
      });
      suggest.appendChild(ul);
      suggest.hidden = false;
    }
    function searchClients(q) {
      q = String(q || '').trim();
      if (q.length < 2) {
        if (suggest) { suggest.hidden = true; suggest.innerHTML = ''; }
        setWalkin();
        return;
      }
      fetch('/calendar/clients?q=' + encodeURIComponent(q), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin'
      }).then(function (r) { return r.json(); }).then(function (data) {
        renderClientList((data && data.items) || []);
      }).catch(function () { setWalkin(); });
    }
    function onClientInput() {
      if (clientId) clientId.value = '0';
      clearTimeout(clientTimer);
      var q = clientName && clientName.value.trim().length >= 2
        ? clientName.value.trim()
        : digits(phone && phone.value);
      clientTimer = setTimeout(function () { searchClients(q); }, 250);
    }
    if (phone) phone.addEventListener('input', onClientInput);
    if (clientName) clientName.addEventListener('input', onClientInput);

    // Optional phone: clear incomplete so phone-ru submit does not block.
    form.addEventListener('submit', function () {
      if (phone && window.SitesPhoneRu && !SitesPhoneRu.isComplete(phone.value)) {
        var d = SitesPhoneRu.normalize(phone.value);
        if (d === '' || d === '7') phone.value = '';
      }
    }, true);

    var selected = form.querySelector('#event-selected-items');
    var productQ = form.querySelector('#event-product-q');
    var productSuggest = form.querySelector('#event-product-suggest');
    var sumDisplay = form.querySelector('#event-sum-display');
    var durationHint = form.querySelector('#event-duration-hint');
    var autoDurInp = form.querySelector('#event-auto-duration');
    var dtStartInp = form.querySelector('input[name="dtstart"]');
    var dtEndInp = form.querySelector('input[name="dtend"]');
    var operatorSel = form.querySelector('#event-operator') || form.querySelector('#event-operator-locked');
    var frequentWrap = form.querySelector('#event-frequent-wrap');
    var frequentBox = form.querySelector('#event-frequent');
    var removeLabel = form.getAttribute('data-i18n-remove') || '×';
    var priceLabel = form.getAttribute('data-i18n-price') || '';
    var qtyLabel = form.getAttribute('data-i18n-qty') || '';
    var durationI18n = form.getAttribute('data-i18n-duration') || '';
    var productTimer = null;
    var readonly = selected && selected.getAttribute('data-readonly') === '1';
    var autoDuration = true;
    var allowedIds = null; // null = unknown / no filter yet

    function staffId() {
      return operatorSel ? parseInt(operatorSel.value || '0', 10) || 0 : 0;
    }
    function dayParam() {
      var v = dtStartInp ? String(dtStartInp.value || '') : '';
      return v.length >= 10 ? v.slice(0, 10) : '';
    }
    function toLocalInput(ts) {
      var d = new Date(ts);
      if (isNaN(d.getTime())) return '';
      var pad = function (n) { return n < 10 ? '0' + n : String(n); };
      // Local wall time — avoid UTC shift from toISOString.
      return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
        + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }
    function parseLocalInput(v) {
      var m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
      if (!m) return 0;
      return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], 0, 0).getTime();
    }
    function selectedIds() {
      var ids = {};
      if (!selected) return ids;
      selected.querySelectorAll('.calendar-selected-row').forEach(function (row) {
        ids[String(row.getAttribute('data-id'))] = true;
      });
      return ids;
    }
    function basketDurationSec() {
      var total = 0;
      if (!selected) return 0;
      selected.querySelectorAll('.calendar-selected-row').forEach(function (row) {
        var sec = parseInt(row.getAttribute('data-time') || '0', 10) || 0;
        if (sec < 1) sec = 1800;
        var qty = parseInt((row.querySelector('input[name^="content_qty"]') || {}).value || '1', 10) || 1;
        total += sec * qty;
      });
      return total;
    }
    function applyDuration() {
      if (!autoDuration || !dtStartInp || !dtEndInp || readonly) return;
      var start = parseLocalInput(dtStartInp.value);
      var dur = basketDurationSec();
      if (start < 1 || dur < 1) {
        if (durationHint) durationHint.textContent = '';
        return;
      }
      dtEndInp.value = toLocalInput(start + dur * 1000);
      if (autoDurInp) autoDurInp.value = '1';
      if (durationHint) {
        var mins = Math.round(dur / 60);
        durationHint.textContent = ' · ' + tpl(durationI18n, { mins: mins });
      }
    }
    function recalcSum() {
      if (!selected || !sumDisplay) return;
      var sum = 0;
      selected.querySelectorAll('.calendar-selected-row').forEach(function (row) {
        var price = parseFloat((row.querySelector('input[name^="content_price"]') || {}).value || '0') || 0;
        var qty = parseInt((row.querySelector('input[name^="content_qty"]') || {}).value || '1', 10) || 1;
        sum += price * qty;
      });
      sumDisplay.textContent = String(Math.round(sum)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
      applyDuration();
    }
    function bindRow(row) {
      var rem = row.querySelector('.calendar-selected-remove');
      if (rem) {
        rem.addEventListener('click', function () {
          row.remove();
          recalcSum();
        });
      }
      row.querySelectorAll('input[type="number"]').forEach(function (inp) {
        inp.addEventListener('input', recalcSum);
      });
    }
    function addItem(item) {
      if (!selected || readonly) return;
      var id = String(item.id || 0);
      if (!id || id === '0' || selectedIds()[id]) return;
      if (allowedIds && !allowedIds[id]) return;
      var timeSec = parseInt(item.time_sec || item.time || 0, 10) || 0;
      var qtyLocked = timeSec > 0;
      var row = document.createElement('div');
      row.className = 'calendar-selected-row';
      row.setAttribute('data-id', id);
      row.setAttribute('data-time', String(timeSec));
      row.setAttribute('data-qty-locked', qtyLocked ? '1' : '0');
      row.innerHTML =
        '<input type="hidden" name="content[]" value="' + id + '">' +
        '<span class="calendar-selected-name"></span>' +
        '<label class="calendar-selected-price"></label>' +
        '<button type="button" class="btn-secondary btn-sm calendar-selected-remove" aria-label="' + removeLabel + '">×</button>';
      row.querySelector('.calendar-selected-name').textContent = item.name || ('#' + id);
      var priceLab = row.querySelector('.calendar-selected-price');
      priceLab.appendChild(document.createTextNode(priceLabel + ' '));
      var priceInp = document.createElement('input');
      priceInp.type = 'number';
      priceInp.min = '0';
      priceInp.step = '1';
      priceInp.name = 'content_price[' + id + ']';
      priceInp.value = String(Math.round(parseFloat(item.price) || 0));
      priceLab.appendChild(priceInp);
      var remBtn = row.querySelector('.calendar-selected-remove');
      if (qtyLocked) {
        var hid = document.createElement('input');
        hid.type = 'hidden';
        hid.name = 'content_qty[' + id + ']';
        hid.value = '1';
        row.insertBefore(hid, remBtn);
        var qtyMark = document.createElement('span');
        qtyMark.className = 'calendar-selected-qty muted';
        qtyMark.textContent = '×1';
        row.insertBefore(qtyMark, remBtn);
      } else {
        var qtyLab = document.createElement('label');
        qtyLab.className = 'calendar-selected-qty';
        qtyLab.appendChild(document.createTextNode(qtyLabel + ' '));
        var qtyInp = document.createElement('input');
        qtyInp.type = 'number';
        qtyInp.min = '1';
        qtyInp.step = '1';
        qtyInp.name = 'content_qty[' + id + ']';
        qtyInp.value = '1';
        qtyLab.appendChild(qtyInp);
        row.insertBefore(qtyLab, remBtn);
      }
      bindRow(row);
      selected.appendChild(row);
      recalculateAutoDurationFlag();
      recalcSum();
      if (productSuggest) { productSuggest.hidden = true; productSuggest.innerHTML = ''; }
      if (productQ) productQ.value = '';
    }
    function recalculateAutoDurationFlag() {
      autoDuration = true;
      if (autoDurInp) autoDurInp.value = '1';
    }
    if (selected) {
      selected.querySelectorAll('.calendar-selected-row').forEach(bindRow);
    }
    function bindFrequentButtons(root) {
      (root || form).querySelectorAll('.calendar-frequent-btn').forEach(function (btn) {
        if (btn.getAttribute('data-bound') === '1') return;
        btn.setAttribute('data-bound', '1');
        btn.addEventListener('click', function () {
          addItem({
            id: btn.getAttribute('data-id'),
            name: btn.getAttribute('data-name'),
            price: btn.getAttribute('data-price'),
            time_sec: btn.getAttribute('data-time')
          });
        });
      });
    }
    bindFrequentButtons();

    function renderFrequent(items) {
      if (!frequentBox || !frequentWrap) return;
      frequentBox.innerHTML = '';
      var list = items || [];
      if (!list.length) {
        frequentWrap.hidden = true;
        return;
      }
      frequentWrap.hidden = false;
      list.forEach(function (item) {
        if (selectedIds()[String(item.id)]) return;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-secondary btn-sm calendar-frequent-btn';
        btn.setAttribute('data-id', String(item.id));
        btn.setAttribute('data-name', item.name || '');
        btn.setAttribute('data-price', String(Math.round(item.price || 0)));
        btn.setAttribute('data-time', String(item.time_sec || 0));
        btn.textContent = item.name || ('#' + item.id);
        frequentBox.appendChild(btn);
      });
      bindFrequentButtons(frequentBox);
    }
    function renderProductList(items) {
      if (!productSuggest) return;
      productSuggest.innerHTML = '';
      var taken = selectedIds();
      var list = (items || []).filter(function (it) { return !taken[String(it.id)]; });
      if (!list.length) {
        productSuggest.hidden = true;
        return;
      }
      var ul = document.createElement('ul');
      list.forEach(function (item) {
        var li = document.createElement('li');
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-secondary btn-sm';
        var mins = Math.round((item.time_sec || 0) / 60);
        btn.textContent = (item.name || '') + ' · ' + Math.round(item.price || 0) + ' ₽'
          + (mins > 0 ? (' · ' + mins + '′') : '');
        btn.addEventListener('click', function () { addItem(item); });
        li.appendChild(btn);
        ul.appendChild(li);
      });
      productSuggest.appendChild(ul);
      productSuggest.hidden = false;
    }
    function productsUrl(q) {
      var url = '/calendar/products?q=' + encodeURIComponent(q || '')
        + '&staff=' + encodeURIComponent(String(staffId()))
        + '&date=' + encodeURIComponent(dayParam());
      Object.keys(selectedIds()).forEach(function (id) {
        url += '&keep[]=' + encodeURIComponent(id);
      });
      return url;
    }
    function searchProducts(q) {
      if (staffId() < 1) {
        if (productSuggest) {
          productSuggest.hidden = true;
          productSuggest.innerHTML = '';
        }
        return;
      }
      fetch(productsUrl(q), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin'
      }).then(function (r) { return r.json(); }).then(function (data) {
        var items = (data && data.items) || [];
        if (!q) {
          renderFrequent(items);
          if (productQ && document.activeElement === productQ) {
            renderProductList(items);
          } else if (productSuggest) {
            productSuggest.hidden = true;
            productSuggest.innerHTML = '';
          }
        } else {
          renderProductList(items);
        }
      }).catch(function () {
        if (productSuggest) { productSuggest.hidden = true; }
      });
    }
    function pruneSelectedForStaff() {
      if (!selected || !allowedIds) return;
      selected.querySelectorAll('.calendar-selected-row').forEach(function (row) {
        var id = String(row.getAttribute('data-id') || '');
        if (id && !allowedIds[id]) {
          row.remove();
        }
      });
      recalculateAutoDurationFlag();
      recalcSum();
    }
    /** Load full staff catalog into allowedIds; optionally drop incompatible basket lines. */
    function refreshStaffCatalog(prune) {
      if (staffId() < 1) {
        allowedIds = null;
        return;
      }
      fetch(productsUrl('') + '&mode=catalog&limit=500', {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin'
      }).then(function (r) { return r.json(); }).then(function (data) {
        allowedIds = {};
        ((data && data.items) || []).forEach(function (it) {
          allowedIds[String(it.id)] = true;
        });
        if (!prune) {
          Object.keys(selectedIds()).forEach(function (id) { allowedIds[id] = true; });
        } else {
          pruneSelectedForStaff();
        }
      }).catch(function () { allowedIds = null; });
      searchProducts('');
    }
    function onStaffOrDayChange() {
      refreshStaffCatalog(true);
    }
    if (productQ) {
      productQ.addEventListener('focus', function () {
        if (!productQ.value) searchProducts('');
      });
      productQ.addEventListener('input', function () {
        clearTimeout(productTimer);
        productTimer = setTimeout(function () { searchProducts(productQ.value.trim()); }, 200);
      });
    }
    if (operatorSel) {
      operatorSel.addEventListener('change', onStaffOrDayChange);
    }
    var lastStartDay = dayParam();
    /**
     * Chrome/Safari: Enter inside datetime-local often submits the form before the
     * edited segment is committed → POST keeps the old time (looks like a reset).
     * Enter = commit like blur; user saves explicitly.
     */
    function bindDatetimeEnterCommit(el) {
      if (!el) return;
      el.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.keyCode !== 13) return;
        e.preventDefault();
        e.stopPropagation();
        el.blur();
      });
    }
    bindDatetimeEnterCommit(dtStartInp);
    bindDatetimeEnterCommit(dtEndInp);

    if (dtStartInp) {
      // Keep end aligned while typing start (auto duration); do not touch staff catalog yet.
      dtStartInp.addEventListener('input', function () {
        if (autoDuration) {
          applyDuration();
        }
      });
      dtStartInp.addEventListener('change', function () {
        if (autoDuration) {
          applyDuration();
        } else if (dtEndInp) {
          // Manual end: if range inverted, snap end forward by previous span or 30′.
          var start = parseLocalInput(dtStartInp.value);
          var end = parseLocalInput(dtEndInp.value);
          if (start > 0 && end > 0 && end <= start) {
            dtEndInp.value = toLocalInput(start + 30 * 60 * 1000);
          }
        }
        var day = dayParam();
        // Re-filter goods only when the calendar day changes — not on every minute tweak.
        if (day !== lastStartDay) {
          lastStartDay = day;
          onStaffOrDayChange();
        }
      });
    }
    if (dtEndInp) {
      var endTouchedByUser = false;
      dtEndInp.addEventListener('input', function () {
        if (!endTouchedByUser) return;
        autoDuration = false;
        if (autoDurInp) autoDurInp.value = '0';
        if (durationHint) durationHint.textContent = '';
      });
      dtEndInp.addEventListener('change', function () {
        if (!endTouchedByUser) return;
        autoDuration = false;
        if (autoDurInp) autoDurInp.value = '0';
      });
      // Mark intentional edits (focus then change), ignore programmatic .value writes.
      dtEndInp.addEventListener('focus', function () { endTouchedByUser = true; });
      dtEndInp.addEventListener('blur', function () { endTouchedByUser = false; });
    }
    // Initial duration hint for existing basket; keep current lines even if rates drifted.
    recalcSum();
    if (staffId() > 0) {
      refreshStaffCatalog(false);
    }
  }

  function boot() {
    bindBoardPopups(document);
    document.querySelectorAll('form[data-calendar-event-form]').forEach(bindEventForm);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
