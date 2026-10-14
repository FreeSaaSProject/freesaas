(function () {
  'use strict';
  var root = document.getElementById('phrases-list');
  if (!root || root.getAttribute('data-can-edit') !== '1') return;

  var LS_DICT = 'audio_phrases.last_dict';
  var i18n = {};
  try { i18n = JSON.parse(root.getAttribute('data-i18n') || '{}'); } catch (e2) { i18n = {}; }
  var statusLabels = i18n.status || {};
  var contextAdminId = parseInt(root.getAttribute('data-admin-id') || '0', 10) || 0;
  var isDayPage = !!root.closest('.ap-day') || (window.location.pathname.indexOf('/phrases/day') !== -1);

  function saveLastDict(id) {
    id = parseInt(id, 10) || 0;
    if (id < 1) return;
    try { localStorage.setItem(LS_DICT, String(id)); } catch (e3) { /* ignore */ }
  }

  function syncCreateButtons(scope) {
    var rows = [];
    if (scope && scope.classList && scope.classList.contains('ap-create-row')) {
      rows = [scope];
    } else {
      rows = Array.prototype.slice.call((scope || root).querySelectorAll('.ap-create-row'));
    }
    rows.forEach(function (row) {
      var sel = row.querySelector('.ap-dict-select');
      var btn = row.querySelector('[data-create-key]');
      if (!sel || !btn) return;
      var dictId = parseInt(sel.value, 10) || 0;
      btn.disabled = dictId < 1;
    });
  }

  function postStatus(id, payload) {
    var body = new FormData();
    Object.keys(payload).forEach(function (k) {
      body.append(k, payload[k] == null ? '' : String(payload[k]));
    });
    return fetch('/audio/phrases/' + id + '/status', {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); });
  }

  function statusLabel(code) {
    return statusLabels[code] || code;
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function updateRow(tr, item) {
    if (!item) return;
    var status = String(item.status || '');
    tr.setAttribute('data-status', status);
    var keyId = parseInt(item.id_key, 10) || 0;
    tr.setAttribute('data-key-id', String(keyId));

    var cell = tr.querySelector('.ap-status');
    if (cell) cell.textContent = statusLabel(status);

    var meta = tr.querySelector('.ap-key-meta');
    if (keyId > 0 && meta) {
      meta.hidden = false;
      var name = item.key_name || ('#' + keyId);
      var dictName = item.dict_name || '';
      var href = '/audio/keys/' + keyId + '/edit';
      var html = '<a class="ap-key-link" href="' + href + '">' + escapeHtml(name) + '</a>';
      if (dictName) {
        html += ' <span class="ap-dict-name muted">' + escapeHtml(dictName) + '</span>';
      }
      meta.innerHTML = html;
    }

    closeEditor(tr);

    var actions = tr.querySelector('.ap-actions');
    if (status === 'junk' || status === 'skip') {
      tr.classList.add('ap-row-dim');
      if (isDayPage && status === 'junk') {
        tr.remove();
        return;
      }
      if (actions) {
        if (status === 'skip') {
          actions.innerHTML =
            '<div class="ap-action-row">' +
            '<button type="button" class="ap-btn ap-btn-restore" data-status="new">' +
            escapeHtml(i18n.markNew || 'new') +
            '</button></div>';
        } else {
          actions.innerHTML = '';
        }
      }
      return;
    }

    tr.classList.remove('ap-row-dim');
    if (actions && (status === 'key' || keyId > 0)) {
      actions.innerHTML = '';
    }
  }

  function defaultKeyName(tr) {
    var ex = String(tr.getAttribute('data-example') || '').trim();
    var lem = String(tr.getAttribute('data-lemmas') || '').trim();
    return ex || lem;
  }

  function closeEditor(tr) {
    var ed = tr.querySelector('.ap-key-editor');
    if (ed) ed.remove();
    var createRow = tr.querySelector('.ap-create-row');
    if (createRow) createRow.hidden = false;
  }

  function renderOverlapList(list) {
    var ul = document.createElement('ul');
    ul.className = 'ap-overlap-list';
    (list || []).slice(0, 12).forEach(function (o) {
      var li = document.createElement('li');
      var id = parseInt(o.id, 10) || 0;
      var href = '/audio/keys/' + id + '/edit';
      var rel = o.relation_label ? ' — ' + escapeHtml(o.relation_label) : '';
      var dict = o.dict_name ? ' <span class="muted">[' + escapeHtml(o.dict_name) + ']</span>' : '';
      li.innerHTML =
        '<a class="ap-key-link" href="' + href + '" target="_blank" rel="noopener">#' + id +
        ' «' + escapeHtml(o.name || '') + '»</a>' + dict +
        '<span class="ap-overlap-rel">' + rel + '</span>';
      ul.appendChild(li);
    });
    return ul;
  }

  function openEditor(tr, dictId) {
    var existing = tr.querySelector('.ap-key-editor');
    if (existing) {
      existing.setAttribute('data-dict-id', String(dictId));
      var ta0 = existing.querySelector('.ap-key-name');
      if (ta0) {
        ta0.focus();
      }
      return;
    }
    var actions = tr.querySelector('.ap-actions');
    if (!actions) return;
    var createRow = tr.querySelector('.ap-create-row');
    if (createRow) createRow.hidden = true;

    var ed = document.createElement('div');
    ed.className = 'ap-key-editor';
    ed.setAttribute('data-dict-id', String(dictId));
    ed.innerHTML =
      '<div class="ap-key-editor-lbl">' + escapeHtml(i18n.editKeyName || 'Key text') + '</div>' +
      '<textarea class="ap-key-name" rows="2" maxlength="255"></textarea>' +
      '<div class="ap-key-editor-actions">' +
      '<button type="button" class="ap-btn ap-btn-create ap-btn-submit-key">' +
      escapeHtml(i18n.submitKey || 'Create key') + '</button>' +
      '<button type="button" class="ap-btn ap-btn-cancel-edit">' +
      escapeHtml(i18n.cancel || 'Cancel') + '</button>' +
      '</div>' +
      '<div class="ap-overlap-panel" hidden></div>';

    // Keep clicks inside the editor from hitting row/bulk handlers.
    ['click', 'mousedown', 'mouseup'].forEach(function (evt) {
      ed.addEventListener(evt, function (e) { e.stopPropagation(); });
    });

    var ta = ed.querySelector('.ap-key-name');
    ta.value = defaultKeyName(tr);
    actions.appendChild(ed);

    ed.querySelector('.ap-btn-cancel-edit').addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      closeEditor(tr);
      syncCreateButtons(tr);
    });

    ed.querySelector('.ap-btn-submit-key').addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var d = parseInt(ed.getAttribute('data-dict-id') || '0', 10) || dictId;
      submitCreate(tr, d, false);
    });

    // Defer focus so the opening click cannot land on Cancel / steal focus.
    setTimeout(function () {
      if (!tr.contains(ed)) return;
      ta.focus();
      try {
        ta.setSelectionRange(ta.value.length, ta.value.length);
      } catch (e5) { /* ignore */ }
    }, 0);
  }

  function showOverlaps(tr, dictId, overlaps) {
    var ed = tr.querySelector('.ap-key-editor');
    if (!ed) return;
    var panel = ed.querySelector('.ap-overlap-panel');
    if (!panel) return;
    panel.hidden = false;
    panel.innerHTML = '';

    var title = document.createElement('div');
    title.className = 'ap-overlap-title';
    title.textContent = i18n.confirmOverlaps || 'Similar keys';
    panel.appendChild(title);
    panel.appendChild(renderOverlapList(overlaps));

    var row = document.createElement('div');
    row.className = 'ap-key-editor-actions';
    var forceBtn = document.createElement('button');
    forceBtn.type = 'button';
    forceBtn.className = 'ap-btn ap-btn-create';
    forceBtn.textContent = i18n.createAnyway || 'Create anyway';
    forceBtn.addEventListener('click', function () {
      submitCreate(tr, dictId, true);
    });
    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'ap-btn';
    cancelBtn.textContent = i18n.cancel || 'Cancel';
    cancelBtn.addEventListener('click', function () {
      panel.hidden = true;
      panel.innerHTML = '';
    });
    var reloadBtn = document.createElement('button');
    reloadBtn.type = 'button';
    reloadBtn.className = 'ap-btn ap-btn-reload';
    reloadBtn.textContent = i18n.reload || 'Reload page';
    reloadBtn.addEventListener('click', function () {
      window.location.reload();
    });
    row.appendChild(forceBtn);
    row.appendChild(cancelBtn);
    row.appendChild(reloadBtn);
    panel.appendChild(row);
  }

  function submitCreate(tr, dictId, force) {
    var ed = tr.querySelector('.ap-key-editor');
    var ta = ed ? ed.querySelector('.ap-key-name') : null;
    var name = ta ? String(ta.value || '').trim() : '';
    if (!name) {
      alert(i18n.textRequired || 'Enter key text');
      if (ta) ta.focus();
      return;
    }
    var payload = {
      status: 'key',
      create_key: '1',
      id_dictonary: dictId,
      key_name: name
    };
    if (force) payload.force = '1';
    if (contextAdminId > 0) payload.admin_id = contextAdminId;

    var btns = ed ? ed.querySelectorAll('button') : [];
    Array.prototype.forEach.call(btns, function (b) { b.disabled = true; });

    var id = parseInt(tr.getAttribute('data-id'), 10);
    postStatus(id, payload).then(function (res) {
      Array.prototype.forEach.call(btns, function (b) { b.disabled = false; });
      if (res && res.needs_confirm && !force) {
        showOverlaps(tr, dictId, res.overlaps || []);
        return;
      }
      if (!res || !res.ok) {
        alert((res && res.error) || i18n.error || 'error');
        syncCreateButtons(tr);
        return;
      }
      updateRow(tr, res.item);
    }).catch(function () {
      Array.prototype.forEach.call(btns, function (b) { b.disabled = false; });
      syncCreateButtons(tr);
      alert(i18n.error || 'error');
    });
  }

  function runStatus(tr, btn, payload) {
    var id = parseInt(tr.getAttribute('data-id'), 10);
    btn.disabled = true;
    postStatus(id, payload).then(function (res) {
      btn.disabled = false;
      if (!res || !res.ok) {
        alert((res && res.error) || i18n.error || 'error');
        syncCreateButtons(tr);
        return;
      }
      updateRow(tr, res.item);
    }).catch(function () {
      btn.disabled = false;
      syncCreateButtons(tr);
      alert(i18n.error || 'error');
    });
  }

  root.addEventListener('change', function (ev) {
    var sel = ev.target.closest('.ap-dict-select');
    if (!sel || !root.contains(sel)) return;
    saveLastDict(sel.value);
    syncCreateButtons(sel.closest('.ap-create-row') || root);
  });

  root.addEventListener('click', function (ev) {
    // Editor has its own handlers; never treat its clicks as row actions.
    if (ev.target.closest('.ap-key-editor')) return;

    var createBtn = ev.target.closest('[data-create-key]');
    var statusBtn = ev.target.closest('[data-status]');
    if (createBtn && root.contains(createBtn)) {
      if (createBtn.disabled) return;
      ev.preventDefault();
      ev.stopPropagation();
      var trC = createBtn.closest('tr[data-id]');
      if (!trC) return;
      var sel = trC.querySelector('.ap-dict-select');
      var dictId = sel ? (parseInt(sel.value, 10) || 0) : 0;
      if (dictId < 1) {
        alert(i18n.pickDict || 'pick dict');
        if (sel) sel.focus();
        return;
      }
      saveLastDict(dictId);
      // After click fully finishes — avoids the same click hitting Cancel.
      setTimeout(function () { openEditor(trC, dictId); }, 0);
      return;
    }
    if (!statusBtn || !root.contains(statusBtn)) return;

    var tr = statusBtn.closest('tr[data-id]');
    if (!tr) return;
    runStatus(tr, statusBtn, { status: statusBtn.getAttribute('data-status') });
  });

  try { localStorage.removeItem(LS_DICT); } catch (e4) { /* ignore */ }
  syncCreateButtons();

  // Highlight row when jumping from pending chips (#phrase-ID).
  function flashHashTarget() {
    var hash = String(window.location.hash || '');
    var m = hash.match(/^#phrase-(\d+)$/);
    if (!m) return;
    var tr = document.getElementById('phrase-' + m[1]);
    if (!tr) return;
    tr.classList.add('ap-pending-flash');
    tr.scrollIntoView({ block: 'center', behavior: 'smooth' });
    setTimeout(function () { tr.classList.remove('ap-pending-flash'); }, 2200);
  }
  window.addEventListener('hashchange', flashHashTarget);
  flashHashTarget();
})();
