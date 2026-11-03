(function () {
  function syncModule(mod) {
    var star = mod.querySelector('[data-ag-module-star]');
    var list = mod.querySelector('[data-ag-ops-list]');
    if (!star || !list) return;
    var on = !!star.checked;
    list.hidden = on;
    list.querySelectorAll('[data-ag-op]').forEach(function (cb) {
      cb.disabled = on;
      if (on) cb.checked = true;
    });
  }

  document.querySelectorAll('[data-ag-ops-catalog] .ag-ops-mod').forEach(function (mod) {
    var star = mod.querySelector('[data-ag-module-star]');
    if (!star) return;
    syncModule(mod);
    star.addEventListener('change', function () {
      syncModule(mod);
      if (star.checked) mod.open = true;
    });
  });

  // Before submit: re-enable disabled ops so unchecked modules don't lose nothing;
  // when star is on, strip individual ids (server prefers module.*).
  document.querySelectorAll('form.ag-settings').forEach(function (form) {
    form.addEventListener('submit', function () {
      form.querySelectorAll('[data-ag-ops-catalog] .ag-ops-mod').forEach(function (mod) {
        var star = mod.querySelector('[data-ag-module-star]');
        var list = mod.querySelector('[data-ag-ops-list]');
        if (!star || !list) return;
        list.querySelectorAll('[data-ag-op]').forEach(function (cb) {
          if (star.checked) {
            cb.disabled = true;
            cb.checked = false; // covered by module.*
          } else {
            cb.disabled = false;
          }
        });
      });
    });
  });

  function csrfToken(form) {
    var inp = form && form.querySelector('input[name="csrf"], input[name="_csrf"]');
    if (inp && inp.value) return inp.value;
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? (meta.getAttribute('content') || '') : '';
  }

  function tpl(form, attr, fallback) {
    var raw = (form && form.getAttribute(attr)) || fallback || '';
    return String(raw);
  }

  function fill(template, map) {
    var s = String(template || '');
    Object.keys(map).forEach(function (k) {
      s = s.split('{' + k + '}').join(String(map[k]));
    });
    return s;
  }

  document.querySelectorAll('[data-ag-fetch-title]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var row = btn.closest('[data-ag-chat-row]');
      var form = btn.closest('form.ag-settings');
      if (!row || !form) return;
      var idInp = row.querySelector('[data-ag-chat-id]');
      var titleInp = row.querySelector('[data-ag-chat-title]');
      var descrInp = row.querySelector('[data-ag-chat-description]');
      var status = row.querySelector('[data-ag-fetch-status]');
      var chatId = idInp ? String(idInp.value || '').trim() : '';
      var failTpl = tpl(form, 'data-ag-msg-fail', 'Could not fetch {id}: {error}');
      if (!chatId || chatId.charAt(0) !== '-') {
        if (status) {
          status.hidden = false;
          status.textContent = fill(failTpl, { id: chatId || '—', error: 'invalid_chat_id' });
        }
        return;
      }
      btn.disabled = true;
      if (status) {
        status.hidden = false;
        status.textContent = tpl(form, 'data-ag-msg-working', 'Fetching…');
      }
      var body = new FormData();
      body.append('action', 'lookup_chat');
      body.append('chat_id', chatId);
      var tok = csrfToken(form);
      if (tok) body.append('csrf', tok);
      fetch('/assistant-groups/settings?format=json', {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      })
        .then(function (res) {
          return res.json().then(function (data) {
            return { okHttp: res.ok, data: data || {} };
          });
        })
        .then(function (pack) {
          var data = pack.data;
          if (!pack.okHttp || !data.ok) {
            var err = (data && (data.error || data.message)) || 'error';
            if (status) {
              status.hidden = false;
              status.textContent = fill(failTpl, { id: chatId, error: err });
            }
            return;
          }
          if (titleInp && data.title) titleInp.value = String(data.title);
          if (descrInp && data.description && !String(descrInp.value || '').trim()) {
            descrInp.value = String(data.description);
          }
          if (status) {
            status.hidden = false;
            status.textContent = tpl(
              form,
              'data-ag-msg-ok',
              'Title filled — press Save to persist.'
            );
          }
        })
        .catch(function (e) {
          if (status) {
            status.hidden = false;
            status.textContent = fill(failTpl, {
              id: chatId,
              error: e && e.message ? e.message : e
            });
          }
        })
        .finally(function () {
          btn.disabled = false;
        });
    });
  });
})();
