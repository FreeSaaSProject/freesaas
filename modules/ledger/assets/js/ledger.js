(function () {
  function normalizeBank(value) {
    return String(value || '')
      .replace(/[\u200B-\u200D\u2060\uFEFF]/g, '')
      .replace(/\s+/g, '')
      .replace(/,/g, '.');
  }

  document.addEventListener('input', function (e) {
    var el = e.target;
    if (!el || !el.getAttribute || !el.getAttribute('data-ledger-bank-normalize')) {
      return;
    }
    var next = normalizeBank(el.value);
    if (next !== el.value) {
      el.value = next;
    }
  });

  function readTypeMeta(form) {
    var node = form.querySelector('#ledger-type-meta');
    if (!node) {
      return {};
    }
    try {
      return JSON.parse(node.textContent || '{}') || {};
    } catch (err) {
      return {};
    }
  }

  function setHidden(el, hidden) {
    if (!el) {
      return;
    }
    if (hidden) {
      el.setAttribute('hidden', 'hidden');
    } else {
      el.removeAttribute('hidden');
    }
  }

  function applyTypeFields(form, typeId, opts) {
    opts = opts || {};
    var metaMap = readTypeMeta(form);
    var meta = typeId && metaMap[typeId] ? metaMap[typeId] : null;
    var hasType = !!meta;

    setHidden(form.querySelector('[data-ledger-field="direction"]'), !hasType);
    setHidden(form.querySelector('[data-ledger-field="account"]'), !hasType);
    setHidden(form.querySelector('[data-ledger-field="payment"]'), !(hasType && Number(meta.payment_enabled) > 0));
    setHidden(form.querySelector('[data-ledger-field="user_payment"]'), !(hasType && Number(meta.user_payment_enabled) > 0));
    setHidden(form.querySelector('[data-ledger-field="supplier"]'), !(hasType && Number(meta.supplier_enabled) > 0));
    setHidden(form.querySelector('[data-ledger-field="act"]'), !(hasType && Number(meta.act_in_enabled) > 0));
    setHidden(form.querySelector('[data-ledger-field="act_not_req"]'), !(hasType && Number(meta.act_in_enabled) > 0));
    setHidden(form.querySelector('[data-ledger-field="fund"]'), !(hasType && Number(meta.fund_enabled) > 0));
    setHidden(form.querySelector('[data-ledger-field="unconfirmed"]'), !hasType);
    setHidden(form.querySelector('[data-ledger-field="promised"]'), !hasType);
    setHidden(form.querySelector('[data-ledger-save]'), !hasType);
    setHidden(form.querySelector('[data-ledger-type-hint]'), hasType);
    syncPromiseExecuted(form);

    var accountSel = form.querySelector('#ledger-move-account, [name="account_id"]');
    if (accountSel) {
      accountSel.required = hasType;
      if (hasType && opts.applyDefaultAccount) {
        var defAcc = String(meta.account_id || '0');
        if ((!accountSel.value || accountSel.value === '0') && defAcc !== '0') {
          accountSel.value = defAcc;
        }
      }
    }
  }

  function syncPromiseExecuted(form) {
    var dateEl = form.querySelector('[data-ledger-promised-date]');
    var execWrap = form.querySelector('[data-ledger-promised-executed]');
    if (!dateEl || !execWrap) {
      return;
    }
    var raw = String(dateEl.value || '').trim();
    var show = false;
    if (raw) {
      var ts = Date.parse(raw + 'T00:00:00');
      show = !isNaN(ts) && ts <= Date.now();
    }
    setHidden(execWrap, !show);
    if (!show) {
      var cb = execWrap.querySelector('input[name="promise_executed"]');
      if (cb) {
        cb.checked = false;
      }
    }
  }

  function prevFill(form, field, val) {
    if (field === 'direction') {
      var radios = form.querySelectorAll('input[name="direction"]');
      for (var i = 0; i < radios.length; i++) {
        radios[i].checked = String(radios[i].value) === String(val);
      }
      return;
    }
    var el = form.querySelector('[name="' + field + '"]');
    if (el) {
      el.value = String(val);
    }
  }

  function reloadNewWithType(form, typeId) {
    var params = new URLSearchParams();
    if (typeId) {
      params.set('type_id', typeId);
    }
    var descr = form.querySelector('[name="descr"]');
    var summ = form.querySelector('[name="summ"]');
    var dt = form.querySelector('[name="dt"]');
    var account = form.querySelector('[name="account_id"]');
    var supplier = form.querySelector('[name="supplier_id"]');
    var dir = form.querySelector('input[name="direction"]:checked');
    if (descr && descr.value) params.set('descr', descr.value);
    if (summ && summ.value) params.set('summ', summ.value);
    if (dt && dt.value) params.set('dt', dt.value);
    if (account && account.value) params.set('account_id', account.value);
    if (supplier && supplier.value) params.set('supplier_id', supplier.value);
    if (dir) params.set('direction', dir.value);
    // Keep list return URL across type-reload (filters + page).
    var cur = new URLSearchParams(window.location.search);
    var ret = cur.get('ret');
    if (ret) {
      params.set('ret', ret);
    } else {
      var retInput = form.querySelector('input[name="ret"]');
      if (retInput && retInput.value) {
        params.set('ret', retInput.value);
      }
    }
    var qs = params.toString();
    // Full navigation — core.js click handler does not run; keep ret manually.
    window.location.href = '/ledger/moves/new' + (qs ? '?' + qs : '');
  }

  function bindMoveForm(form) {
    if (!form || form.getAttribute('data-ledger-bound') === '1') {
      return;
    }
    form.setAttribute('data-ledger-bound', '1');
    var typeSel = form.querySelector('[data-ledger-type-select]');
    if (!typeSel) {
      return;
    }
    applyTypeFields(form, String(typeSel.value || ''), { applyDefaultAccount: false });
    typeSel.addEventListener('change', function () {
      var typeId = String(typeSel.value || '');
      // Boolean data-* attrs are "" in getAttribute — use hasAttribute.
      if (typeSel.hasAttribute('data-ledger-type-reload') && form.hasAttribute('data-ledger-move-new')) {
        reloadNewWithType(form, typeId);
        return;
      }
      applyTypeFields(form, typeId, { applyDefaultAccount: true });
    });

    form.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('[data-ledger-prev-fill]') : null;
      if (!btn || !form.contains(btn)) {
        return;
      }
      e.preventDefault();
      prevFill(form, btn.getAttribute('data-ledger-prev-fill'), btn.getAttribute('data-val'));
    });

    var promisedDate = form.querySelector('[data-ledger-promised-date]');
    if (promisedDate) {
      promisedDate.addEventListener('change', function () {
        syncPromiseExecuted(form);
      });
      promisedDate.addEventListener('input', function () {
        syncPromiseExecuted(form);
      });
    }
  }

  function initMoveForms() {
    var forms = document.querySelectorAll('form[data-ledger-move-form]');
    for (var i = 0; i < forms.length; i++) {
      bindMoveForm(forms[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMoveForms);
  } else {
    initMoveForms();
  }
})();
