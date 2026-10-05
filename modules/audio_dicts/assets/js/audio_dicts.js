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

  function partRoles(part) {
    if (Array.isArray(part.roles) && part.roles.length) {
      return part.roles.slice();
    }
    var out = [];
    if (part.role && part.role !== 'ordinary') out.push(part.role);
    if (part.collection_killer || part.killer) {
      if (out.indexOf('killer') < 0) out.push('killer');
    }
    if (part.is_req) {
      if (out.indexOf('trigger') < 0) out.push('trigger');
    }
    if (part.finish) {
      if (out.indexOf('conversion') < 0) out.push('conversion');
    }
    if (part.break_flag || part.break) {
      if (out.indexOf('breaker') < 0) out.push('breaker');
    }
    return out.length ? out : ['ordinary'];
  }

  function hasRole(part, role) {
    return partRoles(part).indexOf(role) >= 0;
  }

  function setPartRoles(part, roles) {
    var cleaned = [];
    (roles || []).forEach(function (r) {
      if (r && r !== 'ordinary' && cleaned.indexOf(r) < 0) cleaned.push(r);
    });
    part.roles = cleaned.length ? cleaned : ['ordinary'];
    part.role = part.roles[0];
    part.collection_killer = hasRole(part, 'killer') ? 1 : 0;
  }

  function togglePartRole(part, role) {
    if (role === 'ordinary') {
      setPartRoles(part, ['ordinary']);
      return;
    }
    var cur = partRoles(part).filter(function (r) { return r !== 'ordinary'; });
    var idx = cur.indexOf(role);
    if (idx >= 0) {
      cur.splice(idx, 1);
    } else if (role === 'killer') {
      // Killer cancels the open collection and cannot build it — exclusive.
      cur = ['killer'];
    } else {
      cur = cur.filter(function (r) { return r !== 'killer'; });
      cur.push(role);
    }
    setPartRoles(part, cur);
  }

  function rolesHint(part, hints) {
    var list = partRoles(part);
    var texts = [];
    list.forEach(function (r) {
      if (hints[r]) texts.push(hints[r]);
    });
    return texts.join(' ');
  }

  function roleBadgeHtml(part, roles) {
    var list = partRoles(part);
    var html = '';
    list.forEach(function (role) {
      if (role === 'ordinary') return;
      html += '<span class="scripts-badge scripts-badge--' + esc(role) + '">' + esc(roles[role] || role) + '</span>';
    });
    if (part.score) {
      html += '<span class="scripts-badge">+' + esc(part.score) + '</span>';
    }
    return html;
  }

  /** Checkbox table: trigger/conversion/breaker can combine; killer is exclusive. */
  function roleTableHtml(part, roles, hints, i18n, canEdit) {
    var list = partRoles(part);
    var hasKiller = list.indexOf('killer') >= 0;
    var order = ['trigger', 'conversion', 'breaker', 'killer'];
    var html = '<table class="scripts-roles-table"><thead><tr>' +
      '<th></th><th>' + esc(i18n.roleCol || i18n.role || 'Role') + '</th>' +
      '<th>' + esc(i18n.roleHintCol || '') + '</th></tr></thead><tbody>';
    order.forEach(function (rk) {
      if (!roles[rk]) return;
      var checked = list.indexOf(rk) >= 0;
      var blocked = hasKiller && rk !== 'killer';
      html += '<tr' + (blocked ? ' class="is-disabled-role"' : '') + '>' +
        '<td><input type="checkbox" data-role="' + esc(rk) + '"' +
        (checked ? ' checked' : '') +
        (canEdit === false || blocked ? ' disabled' : '') + '></td>' +
        '<td class="scripts-role-name">' + esc(roles[rk]) + '</td>' +
        '<td class="scripts-role-hint-cell">' + esc(hints[rk] || '') + '</td></tr>';
    });
    html += '</tbody></table>';
    html += '<p class="scripts-roles-note">' + esc(i18n.rolesNoneNote || '') + '</p>';
    if (hasKiller) {
      html += '<p class="scripts-roles-note">' + esc(i18n.rolesKillerExclusive || '') + '</p>';
    }
    return html;
  }

  function bindRoleTable(rootEl, part, onChange, canEdit) {
    rootEl.querySelectorAll('input[data-role]').forEach(function (cb) {
      cb.addEventListener('change', function () {
        if (canEdit === false) return;
        var role = cb.getAttribute('data-role');
        var cur = partRoles(part).filter(function (r) { return r !== 'ordinary'; });
        if (cb.checked) {
          if (role === 'killer') {
            cur = ['killer'];
          } else {
            cur = cur.filter(function (r) { return r !== 'killer'; });
            if (cur.indexOf(role) < 0) cur.push(role);
          }
        } else {
          cur = cur.filter(function (r) { return r !== role; });
        }
        setPartRoles(part, cur);
        onChange();
      });
    });
  }

  function normalizePart(part) {
    if (!part.dict_ids) part.dict_ids = [];
    setPartRoles(part, partRoles(part));
    return part;
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
      badges.innerHTML = roleBadgeHtml(part, roles);
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
        parts.push({ name: '', dict_ids: [], roles: ['ordinary'], score: 0, timeout: 0, diversity: 0, instances_min: 0 });
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
        normalizePart(part);
        var card = document.createElement('div');
        card.className = 'scripts-block-card';
        card.innerHTML =
          '<h3>' + esc(part.name || ('#' + (idx + 1))) + '</h3>' +
          roleTableHtml(part, roles, hints, i18n, true);
        bindRoleTable(card, parts[idx], function () { renderRoles(); }, true);
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
          '<p class="scripts-field-hint">' + esc(i18n.scoreHint || '') + '</p>' +
          '<label>' + esc(i18n.timeout) + '<input type="number" min="0" data-field="timeout" value="' + esc(part.timeout || 0) + '"></label>' +
          '<p class="scripts-field-hint">' + esc(i18n.timeoutHint || '') + '</p>' +
          '<label>' + esc(i18n.diversity) + '<input type="number" min="0" data-field="diversity" value="' + esc(part.diversity || 0) + '"></label>' +
          '<p class="scripts-field-hint">' + esc(i18n.diversityHint || '') + '</p>' +
          '<label>' + esc(i18n.instMin) + '<input type="number" min="0" data-field="instances_min" value="' + esc(part.instances_min || 0) + '"></label>' +
          '<p class="scripts-field-hint">' + esc(i18n.instMinHint || '') + '</p>';
        card.querySelectorAll('[data-field]').forEach(function (inp) {
          var field = inp.getAttribute('data-field');
          inp.addEventListener('change', function () {
            parts[idx][field] = parseInt(inp.value, 10) || 0;
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
      parts.push({ name: '', dict_ids: [], roles: ['ordinary'], score: 0, timeout: 0, diversity: 0, instances_min: 0 });
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
    parts.forEach(normalizePart);
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
      var part = normalizePart(parts[selected]);
      var dictChecks = '';
      Object.keys(dicts).forEach(function (did) {
        var checked = (part.dict_ids || []).map(Number).indexOf(parseInt(did, 10)) >= 0;
        dictChecks += '<label class="logo-remove"><input type="checkbox" data-dict="' + esc(did) + '"' + (checked ? ' checked' : '') + (canEdit ? '' : ' disabled') + '> ' + esc(dicts[did]) + '</label>';
      });
      panelBody.innerHTML =
        '<div class="scripts-panel-section"><h3>' + esc(i18n.main) + '</h3>' +
        '<label>' + esc(i18n.name) + '<input type="text" maxlength="40" data-field="name" value="' + esc(part.name || '') + '"' + (canEdit ? '' : ' readonly') + '></label></div>' +
        '<div class="scripts-panel-section"><h3>' + esc(i18n.role) + '</h3>' +
        roleTableHtml(part, roles, hints, i18n, canEdit) + '</div>' +
        '<div class="scripts-panel-section"><h3>' + esc(i18n.dicts) + '</h3>' +
        '<div class="scripts-dict-grid">' + dictChecks + '</div></div>' +
        '<div class="scripts-panel-section"><h3>' + esc(i18n.thresholds) + '</h3>' +
        '<label>' + esc(i18n.score) + '<input type="number" data-field="score" value="' + esc(part.score || 0) + '"' + (canEdit ? '' : ' readonly') + '></label>' +
        '<p class="scripts-field-hint">' + esc(i18n.scoreHint || '') + '</p>' +
        '<label>' + esc(i18n.timeout) + '<input type="number" min="0" data-field="timeout" value="' + esc(part.timeout || 0) + '"' + (canEdit ? '' : ' readonly') + '></label>' +
        '<p class="scripts-field-hint">' + esc(i18n.timeoutHint || '') + '</p>' +
        '<label>' + esc(i18n.diversity) + '<input type="number" min="0" data-field="diversity" value="' + esc(part.diversity || 0) + '"' + (canEdit ? '' : ' readonly') + '></label>' +
        '<p class="scripts-field-hint">' + esc(i18n.diversityHint || '') + '</p>' +
        '<label>' + esc(i18n.instMin) + '<input type="number" min="0" data-field="instances_min" value="' + esc(part.instances_min || 0) + '"' + (canEdit ? '' : ' readonly') + '></label>' +
        '<p class="scripts-field-hint">' + esc(i18n.instMinHint || '') + '</p></div>' +
        (canEdit
          ? '<div class="scripts-panel-section form-actions">' +
            '<button type="button" class="btn-secondary" data-save-part>' + esc(i18n.savePart) + '</button> ' +
            '<button type="button" class="btn-secondary" data-delete-part>' + esc(i18n.removeBlock) + '</button></div>'
          : '');

      panelBody.querySelectorAll('[data-field]').forEach(function (inp) {
        var field = inp.getAttribute('data-field');
        inp.addEventListener('change', function () {
          if (inp.type === 'number') parts[selected][field] = parseInt(inp.value, 10) || 0;
          else parts[selected][field] = inp.value;
          syncHidden();
          if (field === 'name' || field === 'score') refresh();
        });
        if (inp.type === 'text') {
          inp.addEventListener('input', function () {
            parts[selected][field] = inp.value;
            syncHidden();
          });
        }
      });
      bindRoleTable(panelBody, parts[selected], function () { refresh(); }, canEdit);
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
          var partDel = parts[selected];
          if (partDel.id) {
            postParts({ action: 'delete', part_id: partDel.id }).then(function (res) {
              if (res && res.parts) {
                parts = res.parts;
                parts.forEach(normalizePart);
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
      normalizePart(part);
      if (!(part.name || '').trim()) {
        alert(i18n.needName);
        return;
      }
      postParts({
        action: part.id ? 'save' : 'add',
        part_id: part.id || 0,
        name: part.name,
        roles: partRoles(part),
        score: part.score || 0,
        timeout: part.timeout || 0,
        diversity: part.diversity || 0,
        instances_min: part.instances_min || 0,
        dict_ids: part.dict_ids || []
      }).then(function (res) {
        if (res && res.parts) {
          parts = res.parts;
          parts.forEach(normalizePart);
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
          roles: ['ordinary'],
          score: 0,
          timeout: 0,
          diversity: 0,
          instances_min: 0
        });
        selected = parts.length - 1;
        refresh();
      });
    }

    if (form) {
      form.addEventListener('submit', function () {
        parts.forEach(normalizePart);
        syncHidden();
      });
    }

    parts.forEach(normalizePart);
    refresh();
  }

  document.addEventListener('DOMContentLoaded', function () {
    var wizard = document.getElementById('scripts-wizard');
    if (wizard) initWizard(wizard);
    var canvas = document.getElementById('scripts-canvas');
    if (canvas) initCanvas(canvas);
  });
})();
