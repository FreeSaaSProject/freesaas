(function () {
  'use strict';

  function parseJson(el, attr, fallback) {
    try {
      return JSON.parse(el.getAttribute(attr) || '') || fallback;
    } catch (e) {
      return fallback;
    }
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function roleBadgeHtml(part, roles, i18n) {
    var role = part.role || 'ordinary';
    var html = '';
    if (role !== 'ordinary') {
      html += '<span class="scripts-badge scripts-badge--' + esc(role) + '">' + esc(roles[role] || role) + '</span>';
    }
    if (part.collection_killer || part.killer) {
      html += '<span class="scripts-badge scripts-badge--killer">' + esc(i18n.killer || 'Killer') + '</span>';
    }
    if (part.score) {
      html += '<span class="scripts-badge">+' + esc(part.score) + '</span>';
    }
    return html;
  }

  function dictNames(part, dicts) {
    var ids = part.dict_ids || [];
    var names = [];
    for (var i = 0; i < ids.length; i++) {
      var id = ids[i];
      if (dicts[id]) names.push(dicts[id]);
      else if (dicts[String(id)]) names.push(dicts[String(id)]);
    }
    return names.join(', ');
  }

  function renderFlow(container, parts, dicts, roles, i18n, opts) {
    opts = opts || {};
    container.innerHTML = '';
    if (!parts.length) {
      container.innerHTML = '<p class="muted">' + esc(i18n.previewEmpty || i18n.empty || '—') + '</p>';
      return;
    }
    parts.forEach(function (part, idx) {
      if (idx > 0) {
        var arrow = document.createElement('div');
        arrow.className = 'scripts-flow-arrow';
        arrow.setAttribute('aria-hidden', 'true');
        container.appendChild(arrow);
      }
      var node = document.createElement('div');
      node.className = 'scripts-flow-node' + (opts.selectedIndex === idx ? ' is-selected' : '');
      node.setAttribute('data-index', String(idx));
      if (part.id) node.setAttribute('data-id', String(part.id));
      if (opts.draggable) {
        node.setAttribute('draggable', 'true');
      }
      var title = document.createElement('div');
      title.innerHTML = '<strong>' + esc(part.name || ('#' + (idx + 1))) + '</strong>';
      node.appendChild(title);
      var badges = document.createElement('div');
      badges.className = 'scripts-flow-badges';
      badges.innerHTML = roleBadgeHtml(part, roles, i18n);
      node.appendChild(badges);
      var dn = dictNames(part, dicts);
      if (dn) {
        var d = document.createElement('div');
        d.className = 'scripts-flow-dicts';
        d.textContent = dn;
        node.appendChild(d);
      }
      if (opts.onSelect) {
        node.addEventListener('click', function (ev) {
          if (ev.target.closest('.scripts-flow-actions')) return;
          opts.onSelect(idx);
        });
      }
      if (opts.canEdit && opts.onMove) {
        var actions = document.createElement('div');
        actions.className = 'scripts-flow-actions';
        actions.innerHTML =
          '<button type="button" class="btn-secondary btn-sm" data-move="-1">' + esc(i18n.moveUp || '↑') + '</button>' +
          '<button type="button" class="btn-secondary btn-sm" data-move="1">' + esc(i18n.moveDown || '↓') + '</button>';
        actions.addEventListener('click', function (ev) {
          var btn = ev.target.closest('[data-move]');
          if (!btn) return;
          opts.onMove(idx, parseInt(btn.getAttribute('data-move'), 10));
        });
        node.appendChild(actions);
      }
      if (opts.draggable && opts.onReorder) {
        node.addEventListener('dragstart', function (ev) {
          node.classList.add('is-dragging');
          ev.dataTransfer.setData('text/plain', String(idx));
          ev.dataTransfer.effectAllowed = 'move';
        });
        node.addEventListener('dragend', function () {
          node.classList.remove('is-dragging');
        });
        node.addEventListener('dragover', function (ev) {
          ev.preventDefault();
          ev.dataTransfer.dropEffect = 'move';
        });
        node.addEventListener('drop', function (ev) {
          ev.preventDefault();
          var from = parseInt(ev.dataTransfer.getData('text/plain'), 10);
          var to = idx;
          if (!isNaN(from) && from !== to) opts.onReorder(from, to);
        });
      }
      container.appendChild(node);
    });
  }

  /* ---------- Wizard ---------- */
  function initWizard(root) {
    var parts = parseJson(root, 'data-parts', []);
    var dicts = parseJson(root, 'data-dicts', {});
    var roles = parseJson(root, 'data-roles', {});
    var hints = parseJson(root, 'data-hints', {});
    var i18n = parseJson(root, 'data-i18n', {});
    var step = 1;
    var form = document.getElementById('scripts-wizard-form');
    var partsInput = document.getElementById('scripts-parts-json');
    var btnBack = document.getElementById('wizard-back');
    var btnNext = document.getElementById('wizard-next');
    var btnSave = document.getElementById('wizard-save');

    function ensureParts() {
      if (!parts.length) {
        parts.push({ name: '', dict_ids: [], role: 'ordinary', score: 0, timeout: 0, diversity: 0, instances_min: 0, collection_killer: 0 });
      }
    }

    function syncHidden() {
      partsInput.value = JSON.stringify(parts);
    }

    function setStep(n) {
      step = n;
      root.querySelectorAll('.scripts-wizard-step').forEach(function (el) {
        el.classList.toggle('is-active', parseInt(el.getAttribute('data-step'), 10) === step);
      });
      root.querySelectorAll('.scripts-wizard-panel').forEach(function (el) {
        var p = parseInt(el.getAttribute('data-panel'), 10);
        var on = p === step;
        el.hidden = !on;
        el.classList.toggle('is-active', on);
      });
      btnBack.hidden = step <= 1;
      btnNext.hidden = step >= 5;
      btnSave.hidden = step < 5;
      if (step === 2) renderBlocks();
      if (step === 3) renderRoles();
      if (step === 4) renderThresholds();
      if (step === 5) renderPreview();
    }

    function renderBlocks() {
      ensureParts();
      var list = document.getElementById('wizard-blocks-list');
      list.innerHTML = '';
      parts.forEach(function (part, idx) {
        var card = document.createElement('div');
        card.className = 'scripts-block-card';
        var dictChecks = '';
        Object.keys(dicts).forEach(function (did) {
          var checked = (part.dict_ids || []).indexOf(parseInt(did, 10)) >= 0 || (part.dict_ids || []).indexOf(did) >= 0;
          dictChecks += '<label class="logo-remove"><input type="checkbox" data-dict="' + esc(did) + '"' + (checked ? ' checked' : '') + '> ' + esc(dicts[did]) + '</label>';
        });
        card.innerHTML =
          '<h3>#' + (idx + 1) + '</h3>' +
          '<label>' + esc(i18n.blockName) + '<input type="text" maxlength="40" data-field="name" value="' + esc(part.name || '') + '"></label>' +
          '<div><span class="muted">' + esc(i18n.dicts) + '</span><div class="scripts-dict-grid">' + dictChecks + '</div></div>' +
          (parts.length > 1 ? '<button type="button" class="btn-secondary btn-sm" data-remove>' + esc(i18n.removeBlock) + '</button>' : '');
        card.querySelector('[data-field="name"]').addEventListener('input', function (ev) {
          parts[idx].name = ev.target.value;
        });
        card.querySelectorAll('[data-dict]').forEach(function (cb) {
          cb.addEventListener('change', function () {
            var ids = [];
            card.querySelectorAll('[data-dict]:checked').forEach(function (c) {
              ids.push(parseInt(c.getAttribute('data-dict'), 10));
            });
            parts[idx].dict_ids = ids;
          });
        });
        var rm = card.querySelector('[data-remove]');
        if (rm) {
          rm.addEventListener('click', function () {
            parts.splice(idx, 1);
            renderBlocks();
          });
        }
        list.appendChild(card);
      });
    }

    function renderRoles() {
      var list = document.getElementById('wizard-roles-list');
      list.innerHTML = '';
      parts.forEach(function (part, idx) {
        var card = document.createElement('div');
        card.className = 'scripts-block-card';
        var chips = '';
        Object.keys(roles).forEach(function (rk) {
          chips += '<button type="button" class="scripts-role-chip' + (part.role === rk ? ' is-active' : '') + '" data-role="' + esc(rk) + '">' + esc(roles[rk]) + '</button>';
        });
        card.innerHTML =
          '<h3>' + esc(part.name || ('#' + (idx + 1))) + '</h3>' +
          '<div class="scripts-role-chips">' + chips + '</div>' +
          '<p class="scripts-role-hint" data-hint></p>';
        var hint = card.querySelector('[data-hint]');
        hint.textContent = hints[part.role || 'ordinary'] || '';
        card.querySelectorAll('[data-role]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            parts[idx].role = btn.getAttribute('data-role');
            renderRoles();
          });
        });
        list.appendChild(card);
      });
    }

    function renderThresholds() {
      var list = document.getElementById('wizard-thresholds-list');
      list.innerHTML = '';
      parts.forEach(function (part, idx) {
        var card = document.createElement('div');
        card.className = 'scripts-block-card';
        card.innerHTML =
          '<h3>' + esc(part.name || ('#' + (idx + 1))) + '</h3>' +
          '<label>' + esc(i18n.score) + '<input type="number" data-field="score" value="' + esc(part.score || 0) + '"></label>' +
          '<details><summary>' + esc(i18n.advanced) + '</summary>' +
          '<label>' + esc(i18n.timeout) + '<input type="number" min="0" data-field="timeout" value="' + esc(part.timeout || 0) + '"></label>' +
          '<label>' + esc(i18n.diversity) + '<input type="number" min="0" data-field="diversity" value="' + esc(part.diversity || 0) + '"></label>' +
          '<label>' + esc(i18n.instMin) + '<input type="number" min="0" data-field="instances_min" value="' + esc(part.instances_min || 0) + '"></label>' +
          '<label class="logo-remove"><input type="checkbox" data-field="collection_killer"' + (part.collection_killer ? ' checked' : '') + '> ' + esc(i18n.killer) + '</label>' +
          '</details>';
        card.querySelectorAll('[data-field]').forEach(function (inp) {
          var field = inp.getAttribute('data-field');
          inp.addEventListener('change', function () {
            if (inp.type === 'checkbox') {
              parts[idx][field] = inp.checked ? 1 : 0;
            } else {
              parts[idx][field] = parseInt(inp.value, 10) || 0;
            }
          });
        });
        list.appendChild(card);
      });
    }

    function renderPreview() {
      renderFlow(document.getElementById('wizard-preview'), parts, dicts, roles, i18n, {});
    }

    function validateStep() {
      if (step === 1) {
        var name = (document.getElementById('script-name').value || '').trim();
        if (!name) {
          alert(i18n.needName);
          return false;
        }
      }
      if (step === 2) {
        ensureParts();
        for (var i = 0; i < parts.length; i++) {
          if (!(parts[i].name || '').trim()) {
            alert(i18n.needName);
            return false;
          }
          if (!(parts[i].dict_ids || []).length) {
            alert(i18n.needDict);
            return false;
          }
        }
      }
      return true;
    }

    document.getElementById('wizard-add-block').addEventListener('click', function () {
      parts.push({ name: '', dict_ids: [], role: 'ordinary', score: 0, timeout: 0, diversity: 0, instances_min: 0, collection_killer: 0 });
      renderBlocks();
    });

    btnNext.addEventListener('click', function () {
      if (!validateStep()) return;
      setStep(Math.min(5, step + 1));
    });
    btnBack.addEventListener('click', function () {
      setStep(Math.max(1, step - 1));
    });
    root.querySelectorAll('.scripts-wizard-step').forEach(function (el) {
      el.addEventListener('click', function () {
        var target = parseInt(el.getAttribute('data-step'), 10);
        if (target < step || validateStep()) setStep(target);
      });
    });
    form.addEventListener('submit', function (ev) {
      if (!validateStep()) {
        ev.preventDefault();
        return;
      }
      syncHidden();
    });

    if (!parts.length) ensureParts();
    // Normalize roles
    parts.forEach(function (p) {
      if (!p.role) p.role = 'ordinary';
      if (!p.dict_ids) p.dict_ids = [];
    });
    setStep(1);
  }

  /* ---------- Canvas ---------- */
  function initCanvas(root) {
    var parts = parseJson(root, 'data-parts', []);
    var dicts = parseJson(root, 'data-dicts', {});
    var roles = parseJson(root, 'data-roles', {});
    var hints = parseJson(root, 'data-hints', {});
    var i18n = parseJson(root, 'data-i18n', {});
    var canEdit = root.getAttribute('data-can-edit') === '1';
    var partsUrl = root.getAttribute('data-parts-url') || '';
    var selected = parts.length ? 0 : -1;
    var partsInput = document.getElementById('canvas-parts-json');
    var form = document.getElementById('scripts-canvas-form');
    var panelEmpty = document.getElementById('canvas-panel-empty');
    var panelBody = document.getElementById('canvas-panel-body');
    var flow = document.getElementById('canvas-flow');

    function syncHidden() {
      partsInput.value = JSON.stringify(parts);
    }

    function refresh() {
      renderFlow(flow, parts, dicts, roles, i18n, {
        selectedIndex: selected,
        canEdit: canEdit,
        draggable: canEdit,
        onSelect: function (idx) {
          selected = idx;
          refresh();
        },
        onMove: function (idx, dir) {
          var to = idx + dir;
          if (to < 0 || to >= parts.length) return;
          var tmp = parts[idx];
          parts[idx] = parts[to];
          parts[to] = tmp;
          selected = to;
          refresh();
          persistReorder();
        },
        onReorder: function (from, to) {
          var item = parts.splice(from, 1)[0];
          parts.splice(to, 0, item);
          selected = to;
          refresh();
          persistReorder();
        }
      });
      renderPanel();
      syncHidden();
    }

    function renderPanel() {
      if (selected < 0 || !parts[selected]) {
        panelEmpty.hidden = false;
        panelBody.hidden = true;
        panelBody.innerHTML = '';
        return;
      }
      panelEmpty.hidden = true;
      panelBody.hidden = false;
      var part = parts[selected];
      var chips = '';
      Object.keys(roles).forEach(function (rk) {
        chips += '<button type="button" class="scripts-role-chip' + ((part.role || 'ordinary') === rk ? ' is-active' : '') + '" data-role="' + esc(rk) + '"' + (canEdit ? '' : ' disabled') + '>' + esc(roles[rk]) + '</button>';
      });
      var dictChecks = '';
      Object.keys(dicts).forEach(function (did) {
        var checked = (part.dict_ids || []).map(Number).indexOf(parseInt(did, 10)) >= 0;
        dictChecks += '<label class="logo-remove"><input type="checkbox" data-dict="' + esc(did) + '"' + (checked ? ' checked' : '') + (canEdit ? '' : ' disabled') + '> ' + esc(dicts[did]) + '</label>';
      });
      panelBody.innerHTML =
        '<div class="scripts-panel-section"><h3>' + esc(i18n.main) + '</h3>' +
        '<label>' + esc(i18n.name) + '<input type="text" maxlength="40" data-field="name" value="' + esc(part.name || '') + '"' + (canEdit ? '' : ' readonly') + '></label></div>' +
        '<div class="scripts-panel-section"><h3>' + esc(i18n.role) + '</h3>' +
        '<div class="scripts-role-chips">' + chips + '</div>' +
        '<p class="scripts-role-hint">' + esc(hints[part.role || 'ordinary'] || '') + '</p></div>' +
        '<div class="scripts-panel-section"><h3>' + esc(i18n.dicts) + '</h3>' +
        '<div class="scripts-dict-grid">' + dictChecks + '</div></div>' +
        '<div class="scripts-panel-section"><h3>' + esc(i18n.thresholds) + '</h3>' +
        '<label>' + esc(i18n.score) + '<input type="number" data-field="score" value="' + esc(part.score || 0) + '"' + (canEdit ? '' : ' readonly') + '></label>' +
        '<label>' + esc(i18n.timeout) + '<input type="number" min="0" data-field="timeout" value="' + esc(part.timeout || 0) + '"' + (canEdit ? '' : ' readonly') + '></label>' +
        '<label>' + esc(i18n.diversity) + '<input type="number" min="0" data-field="diversity" value="' + esc(part.diversity || 0) + '"' + (canEdit ? '' : ' readonly') + '></label>' +
        '<label>' + esc(i18n.instMin) + '<input type="number" min="0" data-field="instances_min" value="' + esc(part.instances_min || 0) + '"' + (canEdit ? '' : ' readonly') + '></label></div>' +
        '<div class="scripts-panel-section"><h3>' + esc(i18n.dangerous) + '</h3>' +
        '<label class="logo-remove"><input type="checkbox" data-field="collection_killer"' + (part.collection_killer ? ' checked' : '') + (canEdit ? '' : ' disabled') + '> ' + esc(i18n.killer) + '</label>' +
        '<p class="muted small">' + esc(i18n.killerHint || '') + '</p>' +
        (canEdit ? '<button type="button" class="btn-secondary" data-save-part>' + esc(i18n.savePart) + '</button> ' +
          '<button type="button" class="btn-secondary" data-delete-part>' + esc(i18n.removeBlock) + '</button>' : '') +
        '</div>';

      panelBody.querySelectorAll('[data-field]').forEach(function (inp) {
        var field = inp.getAttribute('data-field');
        inp.addEventListener('change', function () {
          if (inp.type === 'checkbox') parts[selected][field] = inp.checked ? 1 : 0;
          else if (inp.type === 'number') parts[selected][field] = parseInt(inp.value, 10) || 0;
          else parts[selected][field] = inp.value;
          syncHidden();
          if (field === 'name' || field === 'score' || field === 'collection_killer') refresh();
        });
        if (inp.type === 'text') {
          inp.addEventListener('input', function () {
            parts[selected][field] = inp.value;
            syncHidden();
          });
        }
      });
      panelBody.querySelectorAll('[data-role]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          if (!canEdit) return;
          parts[selected].role = btn.getAttribute('data-role');
          refresh();
        });
      });
      panelBody.querySelectorAll('[data-dict]').forEach(function (cb) {
        cb.addEventListener('change', function () {
          var ids = [];
          panelBody.querySelectorAll('[data-dict]:checked').forEach(function (c) {
            ids.push(parseInt(c.getAttribute('data-dict'), 10));
          });
          parts[selected].dict_ids = ids;
          syncHidden();
          refresh();
        });
      });
      var saveBtn = panelBody.querySelector('[data-save-part]');
      if (saveBtn) {
        saveBtn.addEventListener('click', function () {
          persistPart(parts[selected]);
        });
      }
      var delBtn = panelBody.querySelector('[data-delete-part]');
      if (delBtn) {
        delBtn.addEventListener('click', function () {
          if (!confirm(i18n.confirmDelete)) return;
          var part = parts[selected];
          if (part.id) {
            postParts({ action: 'delete', part_id: part.id }).then(function (res) {
              if (res && res.parts) {
                parts = res.parts;
                selected = parts.length ? Math.min(selected, parts.length - 1) : -1;
                refresh();
              }
            });
          } else {
            parts.splice(selected, 1);
            selected = parts.length ? Math.min(selected, parts.length - 1) : -1;
            refresh();
          }
        });
      }
    }

    function postParts(data) {
      var body = new FormData();
      Object.keys(data).forEach(function (k) {
        var v = data[k];
        if (Array.isArray(v)) {
          body.append(k, JSON.stringify(v));
        } else {
          body.append(k, v == null ? '' : String(v));
        }
      });
      return fetch(partsUrl, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res || !res.ok) {
          alert((res && res.error) || i18n.error);
          return null;
        }
        return res;
      }).catch(function () {
        alert(i18n.error);
        return null;
      });
    }

    function persistReorder() {
      if (!canEdit) return;
      var order = parts.map(function (p) { return p.id; }).filter(Boolean);
      if (order.length !== parts.length) {
        syncHidden();
        return; // unsaved new parts — full form save later
      }
      postParts({ action: 'reorder', order: order });
    }

    function persistPart(part) {
      if (!canEdit) return;
      if (!(part.name || '').trim()) {
        alert(i18n.needName);
        return;
      }
      postParts({
        action: part.id ? 'save' : 'add',
        part_id: part.id || 0,
        name: part.name,
        role: part.role || 'ordinary',
        score: part.score || 0,
        timeout: part.timeout || 0,
        diversity: part.diversity || 0,
        instances_min: part.instances_min || 0,
        collection_killer: part.collection_killer ? 1 : 0,
        dict_ids: part.dict_ids || []
      }).then(function (res) {
        if (res && res.parts) {
          parts = res.parts;
          if (res.part && res.part.id) {
            for (var i = 0; i < parts.length; i++) {
              if (parts[i].id === res.part.id) selected = i;
            }
          }
          refresh();
        }
      });
    }

    var addBtn = document.getElementById('canvas-add-block');
    if (addBtn) {
      addBtn.addEventListener('click', function () {
        parts.push({
          id: 0,
          name: '',
          dict_ids: [],
          role: 'ordinary',
          score: 0,
          timeout: 0,
          diversity: 0,
          instances_min: 0,
          collection_killer: 0
        });
        selected = parts.length - 1;
        refresh();
      });
    }

    if (form) {
      form.addEventListener('submit', function () {
        syncHidden();
      });
    }

    parts.forEach(function (p) {
      if (!p.role) p.role = 'ordinary';
      if (!p.dict_ids) p.dict_ids = [];
    });
    refresh();
  }

  document.addEventListener('DOMContentLoaded', function () {
    var wizard = document.getElementById('scripts-wizard');
    if (wizard) initWizard(wizard);
    var canvas = document.getElementById('scripts-canvas');
    if (canvas) initCanvas(canvas);
  });
})();
