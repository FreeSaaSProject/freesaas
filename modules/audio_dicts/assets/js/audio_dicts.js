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
    if (part.closer) {
      if (out.indexOf('closer') < 0) out.push('closer');
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

  /** Checkbox table: trigger/conversion/closer/breaker can combine; killer is exclusive. */
  function roleTableHtml(part, roles, hints, i18n, canEdit) {
    var list = partRoles(part);
    var hasKiller = list.indexOf('killer') >= 0;
    var order = ['trigger', 'conversion', 'closer', 'breaker', 'killer'];
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
    part.score = parseInt(part.score, 10) || 0;
    part.score_rate = parseInt(part.score_rate, 10) || 0;
    part.kpi = Math.max(0, parseInt(part.kpi, 10) || 0);
    part.cond_type = part.cond_type || '';
    part.cond_value = parseInt(part.cond_value, 10) || 0;
    part.start_gate = Math.max(0, Math.min(2, parseInt(part.start_gate, 10) || 0));
    part.extend_timeout = part.extend_timeout === 0 || part.extend_timeout === '0' ? 0 : 1;
    part.timeout = parseInt(part.timeout, 10) || 0;
    if (part.break_gap === undefined || part.break_gap === null || part.break_gap === '') {
      part.break_gap = 120;
    } else {
      part.break_gap = Math.max(0, parseInt(part.break_gap, 10) || 0);
    }
    part.diversity = parseInt(part.diversity, 10) || 0;
    part.instances_min = parseInt(part.instances_min, 10) || 0;
    part.gate_red_max = parseFloat(part.gate_red_max) || 0;
    part.gate_yellow_max = parseFloat(part.gate_yellow_max) || 0;
    part.hide_from_reports = part.hide_from_reports === 1 || part.hide_from_reports === '1' ? 1 : 0;
    setPartRoles(part, partRoles(part));
    return part;
  }

  function condLabel(type, i18n) {
    var map = {
      intersect: i18n.condIntersect,
      no_intersect: i18n.condNoIntersect,
      volume_gt: i18n.condVolumeGt,
      volume_lt: i18n.condVolumeLt,
      strings_gt: i18n.condStringsGt,
      strings_lt: i18n.condStringsLt,
      term_scan_unique: i18n.condTermScanUnique,
      term_scan_conversion: i18n.condTermScanUnique
    };
    return map[type] || type || '';
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

  /** Dict ids used by more than one part (conflict highlight). */
  function sharedDictSet(parts) {
    var counts = {};
    (parts || []).forEach(function (p) {
      (p.dict_ids || []).forEach(function (id) {
        var n = parseInt(id, 10);
        if (n > 0) counts[n] = (counts[n] || 0) + 1;
      });
    });
    var shared = {};
    Object.keys(counts).forEach(function (id) {
      if (counts[id] > 1) shared[parseInt(id, 10)] = true;
    });
    return shared;
  }

  /** Dict ids selected in any block of this script. */
  function usedDictSet(parts) {
    var used = {};
    (parts || []).forEach(function (p) {
      (p.dict_ids || []).forEach(function (id) {
        var n = parseInt(id, 10);
        if (n > 0) used[n] = true;
      });
    });
    return used;
  }

  /** Refresh unused/shared classes on dict labels without rebuilding the panel. */
  function syncDictLabelStyles(rootEl, parts, sharedTitle) {
    if (!rootEl) return;
    var used = usedDictSet(parts);
    var shared = sharedDictSet(parts);
    rootEl.querySelectorAll('.scripts-dict-grid [data-dict]').forEach(function (inp) {
      var lab = inp.closest('label');
      if (!lab) return;
      var id = parseInt(inp.getAttribute('data-dict'), 10);
      var isShared = !!shared[id];
      lab.classList.toggle('is-shared-dict', isShared);
      lab.classList.toggle('is-unused-dict', !used[id] && !isShared);
      if (isShared && sharedTitle) {
        lab.setAttribute('title', sharedTitle);
      } else {
        lab.removeAttribute('title');
      }
    });
  }

  function dictNamesHtml(part, dicts, shared) {
    var ids = part.dict_ids || [];
    var bits = [];
    for (var i = 0; i < ids.length; i++) {
      var id = parseInt(ids[i], 10);
      var name = dicts[id] || dicts[String(id)] || ('#' + id);
      if (shared && shared[id]) {
        bits.push('<span class="is-shared-dict" title="' + esc(i18nSharedTitle()) + '">' + esc(name) + '</span>');
      } else {
        bits.push(esc(name));
      }
    }
    return bits.join(', ');
  }

  var _sharedTitle = '';
  function i18nSharedTitle() {
    return _sharedTitle || '';
  }

  function timeoutBadgesHtml(part, i18n, opts) {
    opts = opts || {};
    var html = '';
    var t = parseInt(part.timeout, 10) || 0;
    if (t > 0) {
      var label = (i18n.timeoutFlow || '{n}s').replace('{n}', String(t));
      var extendsTo = !(part.extend_timeout === 0 || part.extend_timeout === '0');
      var mark = extendsTo ? '↗' : '●';
      var markClass = extendsTo ? 'scripts-timeout-mark scripts-timeout-mark--extend' : 'scripts-timeout-mark scripts-timeout-mark--dot';
      var title = extendsTo
        ? (i18n.extendTimeout || '')
        : (i18n.timeoutNoExtend || i18n.extendTimeoutHint || '');
      html += '<span class="scripts-badge scripts-badge--timeout" title="' + esc(title) + '">' +
        esc(label) + ' <span class="' + markClass + '" aria-hidden="true">' + mark + '</span></span>';
    }
    var kpi = parseInt(part.kpi, 10) || 0;
    if (kpi > 0) {
      var kpiLabel = (i18n.kpiFlow || 'gap {n}s').replace('{n}', String(kpi));
      html += '<span class="scripts-badge scripts-badge--kpi">' + esc(kpiLabel) + '</span>';
    }
    if (opts.dayBonus) {
      var red = parseFloat(part.gate_red_max) || 0;
      var yel = parseFloat(part.gate_yellow_max) || 0;
      if (red > 0 || yel > 0) {
        var pct = (i18n.gateRedFlow || '{n}%').replace('{n}', String(red));
        html += '<span class="scripts-badge scripts-badge--gate-red" title="' + esc(i18n.gateRedMaxHint || '') + '">' +
          '<span class="scripts-gate-dot" aria-hidden="true">●</span> ' + esc(pct) + '</span>';
      }
    }
    return html;
  }

  /** Checked positions on the script form (empty = applies to all). */
  function scriptPositionIds(form) {
    if (!form) return [];
    var ids = [];
    form.querySelectorAll('input[name="positions[]"]:checked').forEach(function (cb) {
      var n = parseInt(cb.value, 10);
      if (n > 0) ids.push(n);
    });
    return ids;
  }

  function dictPositionIds(dictPositions, did) {
    var raw = dictPositions[did];
    if (raw == null) raw = dictPositions[String(did)];
    if (!Array.isArray(raw)) return [];
    return raw.map(function (n) { return parseInt(n, 10); }).filter(function (n) { return n > 0; });
  }

  /**
   * When script has positions: show dicts that match any of them (or are global),
   * plus any already selected on the block. No script positions → show all.
   */
  function visibleDictKeys(dicts, dictPositions, scriptPosIds, selectedDictIds) {
    var keys = Object.keys(dicts);
    if (!scriptPosIds || !scriptPosIds.length) {
      return keys;
    }
    var selected = {};
    (selectedDictIds || []).forEach(function (id) {
      selected[parseInt(id, 10)] = true;
    });
    var scriptSet = {};
    scriptPosIds.forEach(function (id) { scriptSet[id] = true; });
    return keys.filter(function (did) {
      var idNum = parseInt(did, 10);
      if (selected[idNum]) return true;
      var dpos = dictPositionIds(dictPositions, did);
      if (!dpos.length) return true; // global dict
      for (var i = 0; i < dpos.length; i++) {
        if (scriptSet[dpos[i]]) return true;
      }
      return false;
    });
  }

  function renderFlow(container, parts, dicts, roles, i18n, opts) {
    opts = opts || {};
    _sharedTitle = i18n.dictShared || '';
    container.innerHTML = '';
    if (!parts.length) {
      container.innerHTML = '<p class="muted">' + esc(i18n.previewEmpty || i18n.empty || '—') + '</p>';
      return;
    }
    var shared = sharedDictSet(parts);
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
      badges.innerHTML = roleBadgeHtml(part, roles) + timeoutBadgesHtml(part, i18n, opts);
      node.appendChild(badges);
      var dnHtml = dictNamesHtml(part, dicts, shared);
      if (dnHtml) {
        var d = document.createElement('div');
        d.className = 'scripts-flow-dicts';
        d.innerHTML = dnHtml;
        node.appendChild(d);
      } else if (part.cond_type) {
        var c = document.createElement('div');
        c.className = 'scripts-flow-dicts muted';
        c.textContent = condLabel(part.cond_type, i18n) + (part.cond_value ? ' ' + part.cond_value : '');
        node.appendChild(c);
      }
      if (part.score_rate) {
        var sr = document.createElement('div');
        sr.className = 'scripts-flow-score-rate' + (part.score_rate < 0 ? ' is-penalty' : '');
        sr.textContent = (part.score_rate > 0 ? '+' : '') + part.score_rate + ' ®';
        node.appendChild(sr);
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
        var prevLabel = opts.horizontal
          ? (i18n.moveLeft || i18n.moveUp || '←')
          : (i18n.moveUp || '↑');
        var nextLabel = opts.horizontal
          ? (i18n.moveRight || i18n.moveDown || '→')
          : (i18n.moveDown || '↓');
        actions.innerHTML =
          '<button type="button" class="btn-secondary btn-sm" data-move="-1">' + esc(prevLabel) + '</button>' +
          '<button type="button" class="btn-secondary btn-sm" data-move="1">' + esc(nextLabel) + '</button>';
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
    var dictPositions = parseJson(root, 'data-dict-positions', {});
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
      var scriptPos = scriptPositionIds(form);
      parts.forEach(function (part, idx) {
        var card = document.createElement('div');
        card.className = 'scripts-block-card';
        var dictChecks = '';
        var used = usedDictSet(parts);
        visibleDictKeys(dicts, dictPositions, scriptPos, part.dict_ids || []).forEach(function (did) {
          var checked = (part.dict_ids || []).indexOf(parseInt(did, 10)) >= 0 || (part.dict_ids || []).indexOf(did) >= 0;
          var idNum = parseInt(did, 10);
          var unusedCls = !used[idNum] ? ' is-unused-dict' : '';
          dictChecks += '<label class="logo-remove' + unusedCls + '"><input type="checkbox" data-dict="' + esc(did) + '"' + (checked ? ' checked' : '') + '> ' + esc(dicts[did]) + '</label>';
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
            syncDictLabelStyles(list, parts, i18n.dictShared || '');
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
          (hasRole(part, 'breaker')
            ? '<label>' + esc(i18n.breakGap || 'BREAK gap') +
              '<input type="number" min="0" data-field="break_gap" value="' + esc(part.break_gap != null ? part.break_gap : 120) + '"></label>' +
              '<p class="scripts-field-hint">' + esc(i18n.breakGapHint || '') + '</p>'
            : '') +
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
          if (!(parts[i].dict_ids || []).length && !(parts[i].cond_type || '')) {
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
    var dictPositions = parseJson(root, 'data-dict-positions', {});
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
    var dayBonusCb = document.getElementById('script-day-bonus');
    var dayBonusFields = document.getElementById('script-day-bonus-fields');
    var dayGatesBody = document.getElementById('script-day-gates-body');
    var dayGatesAdd = document.getElementById('script-day-gates-add');
    var ratingEnabledCb = form
      ? form.querySelector('input[name="rating_enabled"]')
      : document.querySelector('#scripts-canvas-form input[name="rating_enabled"]');

    function dayBonusEnabled() {
      return !!(dayBonusCb && dayBonusCb.checked);
    }

    function ratingEnabled() {
      return !!(ratingEnabledCb && ratingEnabledCb.checked);
    }

    function syncDayBonusUi() {
      var on = dayBonusEnabled();
      if (dayBonusFields) dayBonusFields.hidden = !on;
    }

    function reindexDayGates() {
      if (!dayGatesBody) return;
      var rows = dayGatesBody.querySelectorAll('tr.scripts-day-gate-row');
      for (var i = 0; i < rows.length; i++) {
        var inputs = rows[i].querySelectorAll('select, input');
        for (var j = 0; j < inputs.length; j++) {
          var name = inputs[j].getAttribute('name') || '';
          var m = name.match(/^day_gates\[\d+\](\[.+\])$/);
          if (m) {
            inputs[j].setAttribute('name', 'day_gates[' + i + ']' + m[1]);
          }
        }
      }
    }

    if (dayGatesAdd && dayGatesBody) {
      dayGatesAdd.addEventListener('click', function () {
        var first = dayGatesBody.querySelector('tr.scripts-day-gate-row');
        if (!first) return;
        var clone = first.cloneNode(true);
        var fields = clone.querySelectorAll('select, input');
        for (var i = 0; i < fields.length; i++) {
          if (fields[i].tagName === 'SELECT') {
            fields[i].selectedIndex = 0;
          } else if (fields[i].type === 'number') {
            fields[i].value = '0';
          } else {
            fields[i].value = '';
          }
        }
        dayGatesBody.appendChild(clone);
        reindexDayGates();
      });
      dayGatesBody.addEventListener('click', function (ev) {
        var btn = ev.target && ev.target.closest
          ? ev.target.closest('.scripts-day-gate-remove')
          : null;
        if (!btn) return;
        var row = btn.closest('tr.scripts-day-gate-row');
        if (!row) return;
        var rows = dayGatesBody.querySelectorAll('tr.scripts-day-gate-row');
        if (rows.length <= 1) {
          var fields = row.querySelectorAll('select, input');
          for (var i = 0; i < fields.length; i++) {
            if (fields[i].tagName === 'SELECT') {
              fields[i].selectedIndex = 0;
            } else if (fields[i].type === 'number') {
              fields[i].value = '0';
            } else {
              fields[i].value = '';
            }
          }
          return;
        }
        row.parentNode.removeChild(row);
        reindexDayGates();
      });
    }

    function syncHidden() {
      partsInput.value = JSON.stringify(parts);
    }

    /** Update flow tiles without rebuilding the editor panel (keeps focus / Save click). */
    function refreshFlow() {
      renderFlow(flow, parts, dicts, roles, i18n, {
        selectedIndex: selected,
        canEdit: canEdit,
        draggable: canEdit,
        horizontal: true,
        dayBonus: dayBonusEnabled(),
        onSelect: function (idx) {
          if (idx === selected) return;
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
      syncHidden();
    }

    function refresh() {
      refreshFlow();
      renderPanel();
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
      var scriptPos = scriptPositionIds(form);
      var shared = sharedDictSet(parts);
      var used = usedDictSet(parts);
      visibleDictKeys(dicts, dictPositions, scriptPos, part.dict_ids || []).forEach(function (did) {
        var checked = (part.dict_ids || []).map(Number).indexOf(parseInt(did, 10)) >= 0;
        var idNum = parseInt(did, 10);
        var cls = shared[idNum] ? ' is-shared-dict' : (!used[idNum] ? ' is-unused-dict' : '');
        var sharedTitle = shared[idNum] ? ' title="' + esc(i18n.dictShared || '') + '"' : '';
        dictChecks += '<label class="logo-remove' + cls + '"' + sharedTitle + '><input type="checkbox" data-dict="' + esc(did) + '"' + (checked ? ' checked' : '') + (canEdit ? '' : ' disabled') + '> ' + esc(dicts[did]) + '</label>';
      });
      var condOpts = [
        ['', i18n.condNone || i18n.dicts || 'Dictionaries'],
        ['intersect', i18n.condIntersect || 'intersect'],
        ['no_intersect', i18n.condNoIntersect || 'no_intersect'],
        ['volume_gt', i18n.condVolumeGt || 'volume_gt'],
        ['volume_lt', i18n.condVolumeLt || 'volume_lt'],
        ['strings_gt', i18n.condStringsGt || 'strings_gt'],
        ['strings_lt', i18n.condStringsLt || 'strings_lt'],
        ['term_scan_unique', i18n.condTermScanUnique || 'term_scan_unique']
      ];
      var isMetric = !!(part.cond_type && part.cond_type !== '');
      var isDayQualityMetric = part.cond_type === 'term_scan_unique'
        || part.cond_type === 'term_scan_conversion';
      var needsCondValue = part.cond_type === 'volume_gt' || part.cond_type === 'volume_lt'
        || part.cond_type === 'strings_gt' || part.cond_type === 'strings_lt';
      var isTrigger = hasRole(part, 'trigger');
      var condSelect = '<select data-field="cond_type"' + (canEdit ? '' : ' disabled') + '>';
      condOpts.forEach(function (o) {
        condSelect += '<option value="' + esc(o[0]) + '"' + (part.cond_type === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
      });
      condSelect += '</select>';

      panelBody.innerHTML =
        '<div class="scripts-panel-section scripts-panel-name">' +
        '<label>' + esc(i18n.name) + '<input type="text" maxlength="40" data-field="name" value="' + esc(part.name || '') + '"' + (canEdit ? '' : ' readonly') + '></label></div>' +
        '<div class="scripts-panel-section"><h3>' + esc(i18n.role) + '</h3>' +
        roleTableHtml(part, roles, hints, i18n, canEdit) + '</div>' +
        '<div class="scripts-panel-section"><h3>' + esc(i18n.activator || i18n.dicts || 'Activator') + '</h3>' +
        '<label>' + esc(i18n.activatorField || i18n.condType || 'Type') + condSelect + '</label>' +
        '<p class="scripts-field-hint">' + esc(i18n.activatorHint || i18n.condTypeHint || '') + '</p>' +
        '<div data-dicts-wrap' + (isMetric ? ' hidden' : '') + '>' +
        '<div class="scripts-dict-grid">' + dictChecks + '</div>' +
        '<div data-dict-thresholds-wrap>' +
        '<label>' + esc(i18n.diversity) + '<input type="number" min="0" data-field="diversity" value="' + esc(part.diversity || 0) + '"' + (canEdit ? '' : ' readonly') + '></label>' +
        '<p class="scripts-field-hint">' + esc(i18n.diversityHint || '') + '</p>' +
        '<label>' + esc(i18n.instMin) + '<input type="number" min="0" data-field="instances_min" value="' + esc(part.instances_min || 0) + '"' + (canEdit ? '' : ' readonly') + '></label>' +
        '<p class="scripts-field-hint">' + esc(i18n.instMinHint || '') + '</p></div></div>' +
        '<div data-cond-value-wrap' + (needsCondValue ? '' : ' hidden') + '>' +
        '<label>' + esc(i18n.condValue || 'value') + '<input type="number" data-field="cond_value" value="' + esc(part.cond_value || 0) + '"' + (canEdit ? '' : ' readonly') + '></label>' +
        '</div>' +
        (isTrigger
          ? '<div data-start-gate-wrap>' +
            '<label>' + esc(i18n.startGate || 'START_GATE') +
            '<select data-field="start_gate"' + (canEdit ? '' : ' disabled') + '>' +
            '<option value="0"' + ((part.start_gate || 0) === 0 ? ' selected' : '') + '>' + esc(i18n.startGate0 || '0') + '</option>' +
            '<option value="1"' + (part.start_gate === 1 ? ' selected' : '') + '>' + esc(i18n.startGate1 || '1') + '</option>' +
            '<option value="2"' + (part.start_gate === 2 ? ' selected' : '') + '>' + esc(i18n.startGate2 || '2') + '</option>' +
            '</select></label>' +
            '<p class="scripts-field-hint">' + esc(i18n.startGateHint || '') + '</p></div>'
          : '') +
        '</div>' +
        '<div class="scripts-panel-section"><h3>' + esc(i18n.timeoutsSec || 'Timeouts') + '</h3>' +
        '<label>' + esc(isDayQualityMetric ? (i18n.scanWindow || i18n.timeout || 'Window, s') : (i18n.timeout || 'TIMEOUT')) +
        '<input type="number" min="0" data-field="timeout" value="' + esc(part.timeout || 0) + '"' + (canEdit ? '' : ' readonly') + '></label>' +
        '<p class="scripts-field-hint">' + esc(isDayQualityMetric
          ? (i18n.scanWindowHint || i18n.condTermScanConversionHint || i18n.timeoutHint || '')
          : (i18n.timeoutHint || '')) + '</p>' +
        (hasRole(part, 'breaker')
          ? '<div data-break-gap-wrap>' +
            '<label>' + esc(i18n.breakGap || 'BREAK gap') +
            '<input type="number" min="0" data-field="break_gap" value="' + esc(part.break_gap != null ? part.break_gap : 120) + '"' + (canEdit ? '' : ' readonly') + '></label>' +
            '<p class="scripts-field-hint">' + esc(i18n.breakGapHint || '') + '</p></div>'
          : '') +
        '<label>' + esc(i18n.kpi || 'KPI') + '<input type="number" min="0" data-field="kpi" value="' + esc(part.kpi || 0) + '"' + (canEdit ? '' : ' readonly') + '></label>' +
        '<p class="scripts-field-hint">' + esc(i18n.kpiHint || '') + '</p>' +
        '<label class="checkbox"><input type="checkbox" data-field="extend_timeout" value="1"' + ((part.extend_timeout === 0) ? '' : ' checked') + (canEdit ? '' : ' disabled') + '> ' + esc(i18n.extendTimeout || 'EXTEND_TIMEOUT') + '</label>' +
        '<p class="scripts-field-hint">' + esc(i18n.extendTimeoutHint || '') + '</p></div>' +
        '<div class="scripts-panel-section"><h3>' + esc(i18n.resultsSec || 'Results') + '</h3>' +
        '<label>' + esc(i18n.score) + '<input type="number" data-field="score" value="' + esc(part.score || 0) + '"' + (canEdit ? '' : ' readonly') + '></label>' +
        '<p class="scripts-field-hint">' + esc(i18n.scoreHint || '') + '</p>' +
        (ratingEnabled()
          ? '<label>' + esc(i18n.scoreRate || 'SCORE_RATE') + '<input type="number" data-field="score_rate" value="' + esc(part.score_rate || 0) + '"' + (canEdit ? '' : ' readonly') + '></label>' +
            '<p class="scripts-field-hint">' + esc(i18n.scoreRateHint || '') + '</p>'
          : '') +
        (dayBonusEnabled()
          ? '<h3 class="scripts-panel-subhead">' + esc(i18n.qualitySec || 'Quality') + '</h3>' +
            (isDayQualityMetric
              ? '<p class="scripts-field-hint">' + esc(i18n.condTermScanConversionHint || '') + '</p>'
              : '') +
            '<label>' + esc(isDayQualityMetric ? (i18n.gateRedMaxScan || i18n.gateRedMax || 'red') : (i18n.gateRedMax || 'red %')) +
            '<input type="number" min="0" step="' + (isDayQualityMetric ? '1' : '0.1') + '" data-field="gate_red_max" value="' + esc(part.gate_red_max || 0) + '"' + (canEdit ? '' : ' readonly') + '></label>' +
            '<p class="scripts-field-hint">' + esc(isDayQualityMetric ? (i18n.gateRedMaxHintScan || i18n.gateRedMaxHint || '') : (i18n.gateRedMaxHint || '')) + '</p>' +
            '<label>' + esc(isDayQualityMetric ? (i18n.gateYellowMaxScan || i18n.gateYellowMax || 'yellow') : (i18n.gateYellowMax || 'yellow %')) +
            '<input type="number" min="0" step="' + (isDayQualityMetric ? '1' : '0.1') + '" data-field="gate_yellow_max" value="' + esc(part.gate_yellow_max || 0) + '"' + (canEdit ? '' : ' readonly') + '></label>' +
            '<p class="scripts-field-hint">' + esc(isDayQualityMetric ? (i18n.gateYellowMaxHintScan || i18n.gateYellowMaxHint || '') : (i18n.gateYellowMaxHint || '')) + '</p>'
          : '') +
        '<label class="checkbox"><input type="checkbox" data-field="hide_from_reports" value="1"' + (part.hide_from_reports ? ' checked' : '') + (canEdit ? '' : ' disabled') + '> ' + esc(i18n.hideFromReports || 'Hide from reports') + '</label>' +
        '<p class="scripts-field-hint">' + esc(i18n.hideFromReportsHint || '') + '</p>' +
        '</div>' +
        (canEdit
          ? '<div class="scripts-panel-section scripts-panel-actions form-actions">' +
            '<button type="button" class="btn-secondary" data-delete-part>' + esc(i18n.removeBlock) + '</button></div>'
          : '');

      panelBody.querySelectorAll('[data-field]').forEach(function (inp) {
        var field = inp.getAttribute('data-field');
        function apply() {
          if (inp.type === 'checkbox') {
            parts[selected][field] = inp.checked ? 1 : 0;
          } else if (inp.type === 'number') {
            if (field === 'gate_red_max' || field === 'gate_yellow_max') {
              parts[selected][field] = parseFloat(inp.value) || 0;
            } else {
              parts[selected][field] = parseInt(inp.value, 10) || 0;
            }
          } else {
            parts[selected][field] = inp.value;
          }
          syncHidden();
          if (field === 'cond_type') {
            var ct = parts[selected].cond_type || '';
            var metricOn = !!ct;
            var showValue = ct === 'volume_gt' || ct === 'volume_lt' || ct === 'strings_gt' || ct === 'strings_lt';
            var dictsWrap = panelBody.querySelector('[data-dicts-wrap]');
            var valueWrap = panelBody.querySelector('[data-cond-value-wrap]');
            var dictThrWrap = panelBody.querySelector('[data-dict-thresholds-wrap]');
            if (dictsWrap) dictsWrap.hidden = metricOn;
            if (valueWrap) valueWrap.hidden = !showValue;
            if (dictThrWrap) dictThrWrap.hidden = metricOn;
            if (metricOn) {
              // Metric activator excludes dictionaries (CollectRunner requires empty dicts).
              parts[selected].dict_ids = [];
              parts[selected].diversity = 0;
              parts[selected].instances_min = 0;
              panelBody.querySelectorAll('[data-dict]').forEach(function (cb) {
                cb.checked = false;
              });
              var divInp = panelBody.querySelector('[data-field="diversity"]');
              var instInp = panelBody.querySelector('[data-field="instances_min"]');
              if (divInp) divInp.value = '0';
              if (instInp) instInp.value = '0';
            }
            if (!showValue) {
              parts[selected].cond_value = 0;
              var cv = panelBody.querySelector('[data-field="cond_value"]');
              if (cv) cv.value = '0';
            }
            refreshFlow();
            return;
          }
          if (field === 'name' || field === 'score' || field === 'score_rate' || field === 'cond_value' || field === 'start_gate') {
            refreshFlow();
          }
        }
        inp.addEventListener('change', apply);
        if (inp.type === 'text') {
          inp.addEventListener('input', function () {
            parts[selected][field] = inp.value;
            syncHidden();
            refreshFlow();
          });
        }
      });
      bindRoleTable(panelBody, parts[selected], function () {
        // START_GATE only for triggers; clear when role drops it.
        if (!hasRole(parts[selected], 'trigger')) {
          parts[selected].start_gate = 0;
        }
        // Role table HTML depends on killer exclusivity — rebuild panel.
        refresh();
      }, canEdit);
      panelBody.querySelectorAll('[data-dict]').forEach(function (cb) {
        cb.addEventListener('change', function () {
          var ids = [];
          panelBody.querySelectorAll('[data-dict]:checked').forEach(function (c) {
            ids.push(parseInt(c.getAttribute('data-dict'), 10));
          });
          parts[selected].dict_ids = ids;
          syncHidden();
          syncDictLabelStyles(panelBody, parts, i18n.dictShared || '');
          refreshFlow();
        });
      });
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

    var addBtn = document.getElementById('canvas-add-block');
    if (addBtn) {
      addBtn.addEventListener('click', function () {
        parts.push({
          id: 0,
          name: '',
          dict_ids: [],
          roles: ['ordinary'],
          score: 0,
          score_rate: 0,
          kpi: 0,
          cond_type: '',
          cond_value: 0,
          start_gate: 0,
          extend_timeout: 1,
          timeout: 0,
          break_gap: 120,
          diversity: 0,
          instances_min: 0,
          gate_red_max: 0,
          gate_yellow_max: 0,
          hide_from_reports: 0
        });
        selected = parts.length - 1;
        refresh();
      });
    }

    if (form) {
      form.addEventListener('submit', function (ev) {
        parts.forEach(normalizePart);
        syncHidden();
        for (var i = 0; i < parts.length; i++) {
          if (!(parts[i].name || '').trim()) {
            ev.preventDefault();
            selected = i;
            refresh();
            alert(i18n.needName);
            return;
          }
        }
      });
      form.querySelectorAll('input[name="positions[]"]').forEach(function (cb) {
        cb.addEventListener('change', function () {
          if (selected >= 0) renderPanel();
        });
      });
    }

    if (dayBonusCb) {
      dayBonusCb.addEventListener('change', function () {
        syncDayBonusUi();
        refresh();
      });
      syncDayBonusUi();
    }
    if (ratingEnabledCb) {
      ratingEnabledCb.addEventListener('change', function () {
        if (selected >= 0) renderPanel();
      });
    }

    Array.prototype.forEach.call(root.querySelectorAll('[data-scripts-download]'), function (a) {
      a.addEventListener('click', function (ev) {
        var msg = a.getAttribute('data-confirm') || '';
        if (msg && !window.confirm(msg)) {
          ev.preventDefault();
        }
      });
    });

    parts.forEach(normalizePart);
    refresh();
  }

  function initScriptImport() {
    var form = document.getElementById('scripts-upload-form');
    var fileInput = document.getElementById('scripts-upload-file');
    var nameHidden = document.getElementById('scripts-upload-name');
    var dialog = document.getElementById('scripts-import-dialog');
    var nameInput = document.getElementById('scripts-import-name');
    var preview = document.getElementById('scripts-import-preview');
    var nameErr = document.getElementById('scripts-import-name-err');
    var btnOk = document.getElementById('scripts-import-ok');
    var btnCancel = document.getElementById('scripts-import-cancel');
    if (!form || !fileInput || !dialog || !nameInput) return;

    var existing = [];
    try {
      existing = JSON.parse(dialog.getAttribute('data-existing-names') || '[]') || [];
    } catch (e) {
      existing = [];
    }
    if (!Array.isArray(existing)) existing = [];

    function clearFile() {
      fileInput.value = '';
      if (nameHidden) nameHidden.value = '';
    }

    function showNameError(msg) {
      if (!nameErr) return;
      if (!msg) {
        nameErr.hidden = true;
        nameErr.textContent = '';
        return;
      }
      nameErr.hidden = false;
      nameErr.textContent = msg;
    }

    function nameTaken(name) {
      var n = String(name || '').trim();
      if (!n) return false;
      for (var i = 0; i < existing.length; i++) {
        if (String(existing[i]).trim() === n) return true;
      }
      return false;
    }

    function takenMessage(name) {
      var tpl = dialog.getAttribute('data-i18n-name-taken') || '';
      return tpl.replace('{name}', name);
    }

    function buildPreview(pack) {
      var script = pack && pack.script ? pack.script : {};
      var parts = Array.isArray(script.parts) ? script.parts : [];
      var partNames = parts.map(function (p) { return (p && p.name) ? String(p.name) : ''; }).filter(Boolean);
      var dictSet = {};
      parts.forEach(function (p) {
        var dicts = (p && Array.isArray(p.dicts)) ? p.dicts : [];
        dicts.forEach(function (d) {
          var dn = String(d || '').trim();
          if (dn) dictSet[dn] = true;
        });
      });
      var dictNames = Object.keys(dictSet);
      var positions = Array.isArray(script.positions) ? script.positions : [];
      var withKeys = !!(pack.include_keys || (Array.isArray(pack.dictionaries) && pack.dictionaries.length));
      var lines = [];
      lines.push((dialog.getAttribute('data-i18n-parts') || 'Parts') + ': ' + parts.length
        + (partNames.length ? ' — ' + partNames.slice(0, 12).join(', ') + (partNames.length > 12 ? '…' : '') : ''));
      lines.push((dialog.getAttribute('data-i18n-dicts') || 'Dicts') + ': '
        + (dictNames.length ? dictNames.join(', ') : '—'));
      if (positions.length) {
        lines.push((dialog.getAttribute('data-i18n-positions') || 'Positions') + ': ' + positions.join(', '));
      }
      lines.push(withKeys
        ? (dialog.getAttribute('data-i18n-keys-yes') || 'With keywords')
        : (dialog.getAttribute('data-i18n-keys-no') || 'Without keywords'));
      return lines.join('\n');
    }

    function openDialog(pack) {
      var script = pack.script || {};
      nameInput.value = String(script.name || '').slice(0, 40);
      if (preview) preview.textContent = buildPreview(pack);
      showNameError('');
      if (typeof dialog.showModal === 'function') {
        dialog.showModal();
      } else {
        dialog.setAttribute('open', 'open');
      }
      nameInput.focus();
      nameInput.select();
    }

    function closeDialog() {
      if (typeof dialog.close === 'function') {
        dialog.close();
      } else {
        dialog.removeAttribute('open');
      }
    }

    fileInput.addEventListener('change', function () {
      var file = fileInput.files && fileInput.files[0];
      if (!file) return;
      var reader = new FileReader();
      reader.onload = function () {
        var raw = String(reader.result || '');
        var pack;
        try {
          pack = JSON.parse(raw);
        } catch (e) {
          alert(dialog.getAttribute('data-i18n-invalid') || 'Invalid JSON');
          clearFile();
          return;
        }
        if (!pack || typeof pack !== 'object' || !pack.script) {
          alert(dialog.getAttribute('data-i18n-invalid') || 'Invalid JSON');
          clearFile();
          return;
        }
        openDialog(pack);
      };
      reader.onerror = function () {
        alert(dialog.getAttribute('data-i18n-invalid') || 'Invalid JSON');
        clearFile();
      };
      reader.readAsText(file);
    });

    if (btnCancel) {
      btnCancel.addEventListener('click', function () {
        closeDialog();
        clearFile();
      });
    }
    dialog.addEventListener('cancel', function () {
      clearFile();
    });

    function trySubmit() {
      var name = String(nameInput.value || '').trim();
      if (!name) {
        showNameError(dialog.getAttribute('data-i18n-name-required') || '');
        nameInput.focus();
        return;
      }
      if (nameTaken(name)) {
        showNameError(takenMessage(name));
        nameInput.focus();
        return;
      }
      if (nameHidden) nameHidden.value = name;
      showNameError('');
      closeDialog();
      form.submit();
    }

    if (btnOk) {
      btnOk.addEventListener('click', trySubmit);
    }
    nameInput.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        trySubmit();
      }
    });
    nameInput.addEventListener('input', function () {
      var name = String(nameInput.value || '').trim();
      if (name && nameTaken(name)) {
        showNameError(takenMessage(name));
      } else {
        showNameError('');
      }
    });
  }

  /** Bulk LLM descr: progressive overlay (one dict per request) — same idea as db-job / calendar reparse. */
  function initSuggestBulk() {
    var overlay = document.getElementById('audio-dicts-suggest-overlay');
    if (!overlay) return;
    var i18n = parseJson(overlay, 'data-i18n', {});
    var msgEl = document.getElementById('audio-dicts-suggest-msg');
    var logEl = document.getElementById('audio-dicts-suggest-log');
    var fillEl = document.getElementById('audio-dicts-suggest-fill');
    var barEl = document.getElementById('audio-dicts-suggest-bar');
    var pctEl = document.getElementById('audio-dicts-suggest-pct');
    var metaEl = document.getElementById('audio-dicts-suggest-meta');
    var titleEl = document.getElementById('audio-dicts-suggest-title');
    var abortBtn = document.getElementById('audio-dicts-suggest-abort');
    var okBtn = document.getElementById('audio-dicts-suggest-ok');
    var busy = false;
    var abort = false;

    function tpl(s, vars) {
      var out = String(s || '');
      Object.keys(vars || {}).forEach(function (k) {
        out = out.split('{' + k + '}').join(String(vars[k]));
      });
      return out;
    }

    function addLog(text, cls) {
      if (!logEl) return;
      var row = document.createElement('div');
      if (cls) row.className = cls;
      var t = new Date().toLocaleTimeString();
      row.textContent = '[' + t + '] ' + text;
      logEl.appendChild(row);
      logEl.scrollTop = logEl.scrollHeight;
    }

    function setProgress(done, total) {
      var pct = total > 0 ? Math.round((done / total) * 100) : 0;
      if (fillEl) fillEl.style.width = pct + '%';
      if (barEl) barEl.setAttribute('aria-valuenow', String(pct));
      if (pctEl) pctEl.textContent = pct + '%';
      if (metaEl) metaEl.textContent = done + ' / ' + total;
    }

    function csrfToken(form) {
      var inp = form.querySelector('input[name="csrf"], input[name="_csrf"], input[name="csrf_token"]');
      if (inp && inp.value) return { name: inp.name, value: inp.value };
      var meta = document.querySelector('meta[name="csrf-token"]');
      if (meta && meta.getAttribute('content')) {
        return { name: 'csrf', value: meta.getAttribute('content') };
      }
      return null;
    }

    function finish(summary) {
      busy = false;
      if (titleEl) titleEl.textContent = i18n.doneTitle || 'Done';
      if (msgEl) msgEl.textContent = summary || '';
      if (abortBtn) abortBtn.hidden = true;
      if (okBtn) {
        okBtn.hidden = false;
        okBtn.disabled = false;
      }
    }

    function closeOverlay() {
      overlay.hidden = true;
      if (okBtn) {
        okBtn.hidden = true;
        okBtn.disabled = true;
      }
      if (abortBtn) abortBtn.hidden = false;
      window.location.reload();
    }

    if (okBtn) {
      okBtn.addEventListener('click', closeOverlay);
    }
    if (abortBtn) {
      abortBtn.addEventListener('click', function () {
        if (!busy) return;
        abort = true;
        if (msgEl) msgEl.textContent = i18n.aborted || 'Stopped';
      });
    }

    function postOne(id, overwrite, csrf) {
      var body = new FormData();
      body.append('save', '1');
      if (overwrite) body.append('overwrite', '1');
      if (csrf) body.append(csrf.name, csrf.value);
      return fetch('/audio/dicts/' + id + '/suggest-descr', {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      }).then(function (res) {
        return res.text().then(function (txt) {
          var data = null;
          try { data = JSON.parse(txt); } catch (e) { data = null; }
          return { http: res.status, data: data, raw: txt };
        });
      });
    }

    function run(ids, overwrite, csrf) {
      busy = true;
      abort = false;
      if (logEl) logEl.innerHTML = '';
      if (titleEl) titleEl.textContent = i18n.title || 'Generate descriptions';
      if (msgEl) msgEl.textContent = i18n.running || 'Processing…';
      if (abortBtn) abortBtn.hidden = false;
      if (okBtn) {
        okBtn.hidden = true;
        okBtn.disabled = true;
      }
      overlay.hidden = false;
      setProgress(0, ids.length);

      var done = 0;
      var skipped = 0;
      var idx = 0;

      function next() {
        if (abort) {
          addLog(i18n.aborted || 'Stopped', 'is-warn');
          finish(tpl(i18n.summary || 'Done: {done}, skipped: {skip}', { done: done, skip: skipped }));
          return;
        }
        if (idx >= ids.length) {
          finish(tpl(i18n.summary || 'Done: {done}, skipped: {skip}', { done: done, skip: skipped }));
          return;
        }
        var id = ids[idx++];
        setProgress(idx - 1, ids.length);
        postOne(id, overwrite, csrf).then(function (res) {
          var data = res.data || {};
          if (data.ok && data.saved) {
            done++;
            addLog(tpl(i18n.itemOk || '#{id}: saved', { id: id }), 'is-ok');
          } else if (data.skipped) {
            skipped++;
            addLog(tpl(i18n.itemSkip || '#{id}: {reason}', {
              id: id,
              reason: data.skipped
            }), 'is-warn');
          } else {
            skipped++;
            var reason = (data && data.error) ? data.error
              : tpl(i18n.http || 'HTTP {status}', { status: res.http });
            addLog(tpl(i18n.itemErr || '#{id}: error — {reason}', {
              id: id,
              reason: reason
            }), 'is-err');
          }
          setProgress(idx, ids.length);
          next();
        }).catch(function (err) {
          skipped++;
          addLog(tpl(i18n.itemErr || '#{id}: error — {reason}', {
            id: id,
            reason: (err && err.message) ? err.message : 'network'
          }), 'is-err');
          setProgress(idx, ids.length);
          next();
        });
      }

      next();
    }

    document.querySelectorAll('[data-bulk-form]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        var actionSel = form.querySelector('[data-bulk-action]');
        if (!actionSel || String(actionSel.value || '') !== 'suggest_descr') return;
        e.preventDefault();
        e.stopImmediatePropagation();

        if (busy) {
          alert(i18n.busy || 'Busy');
          return;
        }
        var confirmMsg = i18n.confirm || '';
        var opt = actionSel.options[actionSel.selectedIndex];
        if (opt && opt.getAttribute('data-confirm')) {
          confirmMsg = opt.getAttribute('data-confirm');
        }
        if (confirmMsg && !window.confirm(confirmMsg)) return;

        var ids = Array.prototype.slice.call(form.querySelectorAll('[data-bulk-id]'))
          .filter(function (c) { return c.checked; })
          .map(function (c) { return parseInt(c.value, 10) || 0; })
          .filter(function (id) { return id > 0; });
        if (!ids.length) {
          alert(i18n.empty || 'Empty');
          return;
        }
        var overwriteField = form.querySelector('[data-bulk-field-for="suggest_descr"] input[name="overwrite"]');
        var overwrite = !!(overwriteField && overwriteField.checked && !overwriteField.disabled);
        var csrf = csrfToken(form);
        run(ids, overwrite, csrf);
      }, true);
    });
  }

  /** Keys edit: live overlap probe (same rules as list add-key / api_dict). */
  function initKeyOverlapForm() {
    var form = document.getElementById('audio-key-form');
    if (!form) return;
    var nameInp = document.getElementById('key-name');
    var lookInp = document.getElementById('key-looklike');
    var panel = document.getElementById('key-overlap-panel');
    var list = document.getElementById('key-overlap-list');
    var status = document.getElementById('key-overlap-status');
    var forceWrap = document.getElementById('key-force-wrap');
    var url = form.getAttribute('data-overlap-url') || '/audio/keys/check-overlap';
    var exclude = parseInt(form.getAttribute('data-exclude') || '0', 10) || 0;
    var i18n = parseJson(form, 'data-i18n', {});
    var timer = null;
    var seq = 0;

    function esc(s) {
      return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    /** Soft "contains" only — existing key is wider; save allowed without force. */
    function softContainsOnly(overlaps) {
      if (!overlaps || !overlaps.length) return true;
      for (var i = 0; i < overlaps.length; i++) {
        if (String(overlaps[i].relation || '') !== 'contains') return false;
      }
      return true;
    }

    function render(overlaps) {
      if (!panel || !list) return;
      list.innerHTML = '';
      if (!overlaps || !overlaps.length) {
        panel.hidden = true;
        if (forceWrap) forceWrap.hidden = true;
        if (status) status.textContent = i18n.none || '';
        return;
      }
      panel.hidden = false;
      var needForce = !softContainsOnly(overlaps);
      if (forceWrap) forceWrap.hidden = !needForce;
      overlaps.forEach(function (o) {
        var li = document.createElement('li');
        var id = parseInt(o.id, 10) || 0;
        var rel = o.relation_label || o.relation || '';
        var dict = o.dict_name
          ? ' <span class="muted">[' + esc(o.dict_name) + ']</span>'
          : '';
        li.innerHTML =
          '<a href="/audio/keys/' + id + '/edit" target="_blank" rel="noopener">#' + id +
          ' «' + esc(o.name || '') + '»</a>' + dict +
          '<span class="audio-key-overlap-rel">— ' + esc(rel) + '</span>';
        list.appendChild(li);
      });
      if (status) {
        status.textContent = needForce ? '' : (i18n.softOnly || '');
      }
      if (lookInp && (!lookInp.value || lookInp.value === '0') && overlaps[0]) {
        lookInp.value = String(parseInt(overlaps[0].id, 10) || 0);
      }
    }

    function check() {
      var name = String(nameInp && nameInp.value || '').trim();
      if (name.length < 2) {
        render([]);
        return;
      }
      var my = ++seq;
      if (status) status.textContent = i18n.checking || '…';
      var q = url + '?name=' + encodeURIComponent(name) + '&exclude=' + exclude;
      fetch(q, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (r) { return r.json(); }).then(function (data) {
        if (my !== seq) return;
        render((data && data.overlaps) || []);
      }).catch(function () {
        if (my !== seq) return;
        if (status) status.textContent = '';
      });
    }

    if (nameInp) {
      nameInp.addEventListener('input', function () {
        if (timer) clearTimeout(timer);
        timer = setTimeout(check, 350);
      });
      nameInp.addEventListener('change', check);
      if (String(nameInp.value || '').trim().length >= 2) {
        check();
      }
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var wizard = document.getElementById('scripts-wizard');
    if (wizard) initWizard(wizard);
    var canvas = document.getElementById('scripts-canvas');
    if (canvas) initCanvas(canvas);
    initScriptImport();
    initSuggestBulk();
    initKeyOverlapForm();
  });
})();
