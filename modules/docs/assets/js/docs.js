(function () {
  'use strict';

  function bindConfirm(root) {
    root.querySelectorAll('[data-confirm]').forEach(function (btn) {
      if (btn.getAttribute('data-confirm-bound') === '1') return;
      btn.setAttribute('data-confirm-bound', '1');
      btn.addEventListener('click', function (e) {
        var msg = btn.getAttribute('data-confirm') || '';
        if (msg && !window.confirm(msg)) e.preventDefault();
      });
    });
  }

  function bindSegments(root) {
    root.querySelectorAll('[data-docs-segments]').forEach(function (box) {
      var name = box.getAttribute('data-name') || '';
      var rows = box.querySelector('.docs-segments-rows');
      var add = box.querySelector('[data-docs-seg-add]');
      function wireRemove(row) {
        var rm = row.querySelector('[data-docs-seg-remove]');
        if (rm) rm.addEventListener('click', function () { row.remove(); });
      }
      rows.querySelectorAll('.docs-segment-row').forEach(wireRemove);
      if (!add) return;
      add.addEventListener('click', function () {
        var row = document.createElement('div');
        row.className = 'docs-segment-row';
        ['from', 'to'].forEach(function (k) {
          var inp = document.createElement('input');
          inp.type = 'time';
          inp.name = name + '[' + k + '][]';
          row.appendChild(inp);
        });
        var note = document.createElement('input');
        note.type = 'text';
        note.maxLength = 255;
        note.name = name + '[note][]';
        note.placeholder = add.getAttribute('data-placeholder') || '';
        row.appendChild(note);
        var rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'btn-secondary btn-sm';
        rm.setAttribute('data-docs-seg-remove', '1');
        rm.setAttribute('aria-label', add.getAttribute('data-remove-label') || '×');
        rm.textContent = '×';
        row.appendChild(rm);
        rows.appendChild(row);
        wireRemove(row);
      });
    });
  }

  function insertAtCursor(ta, text) {
    var start = ta.selectionStart || 0;
    var end = ta.selectionEnd || 0;
    ta.value = ta.value.slice(0, start) + text + ta.value.slice(end);
    ta.selectionStart = ta.selectionEnd = start + text.length;
    ta.focus();
  }

  function bindPlaceholders(root) {
    var box = root.querySelector('[data-docs-placeholders]');
    var ta = root.querySelector('[data-docs-template]');
    if (!box || !ta) return;
    box.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-ph]');
      if (!btn) return;
      insertAtCursor(ta, btn.getAttribute('data-ph') || '');
    });
  }

  function bindBuilder(root) {
    var wrap = root.querySelector('[data-docs-builder]');
    var ta = root.querySelector('[data-docs-schema]');
    if (!wrap || !ta) return;
    var list = wrap.querySelector('[data-docs-builder-list]');
    var types = {};
    try { types = JSON.parse(wrap.getAttribute('data-types') || '{}'); } catch (err) { types = {}; }
    var L = function (k) { return wrap.getAttribute('data-i18n-' + k) || ''; };
    var schema = { sections: [] };
    var chips = root.querySelector('[data-docs-placeholders]');

    function load() {
      try {
        var parsed = JSON.parse(ta.value || '{}');
        schema = parsed && Array.isArray(parsed.sections) ? parsed : { sections: [] };
        wrap.classList.remove('is-invalid');
        return true;
      } catch (err) {
        wrap.classList.add('is-invalid');
        list.textContent = L('bad-json');
        return false;
      }
    }
    function save() {
      ta.value = JSON.stringify(schema, null, 2);
    }
    function sectionsDatalist() {
      var dl = wrap.querySelector('datalist');
      if (!dl) return;
      dl.innerHTML = '';
      schema.sections.forEach(function (s) {
        var o = document.createElement('option');
        o.value = s.title || s.id || '';
        dl.appendChild(o);
      });
    }
    function allIds() {
      var ids = {};
      schema.sections.forEach(function (s) {
        (s.fields || []).forEach(function (f) { ids[f.id] = true; });
      });
      return ids;
    }
    function move(si, fi, dir) {
      var fields = schema.sections[si].fields;
      var to = fi + dir;
      if (to < 0 || to >= fields.length) return;
      var tmp = fields[fi];
      fields[fi] = fields[to];
      fields[to] = tmp;
      save();
      render();
    }
    function render() {
      list.innerHTML = '';
      sectionsDatalist();
      var any = false;
      schema.sections.forEach(function (s, si) {
        var sec = document.createElement('div');
        sec.className = 'docs-builder-section';
        var h = document.createElement('div');
        h.className = 'docs-builder-section-title';
        h.textContent = s.title || s.id || ('#' + (si + 1));
        sec.appendChild(h);
        (s.fields || []).forEach(function (f, fi) {
          any = true;
          var row = document.createElement('div');
          row.className = 'docs-builder-field';
          var main = document.createElement('span');
          main.className = 'docs-builder-field-main';
          main.textContent = (f.label || f.id) + ' ';
          var code = document.createElement('code');
          code.textContent = '{' + f.id + '}';
          main.appendChild(code);
          row.appendChild(main);
          var meta = document.createElement('span');
          meta.className = 'muted';
          var bits = [types[f.type] || f.type];
          if (f.required) bits.push(L('required'));
          if (f.index) bits.push(L('index'));
          meta.textContent = bits.join(' · ');
          row.appendChild(meta);
          [['up', -1, '↑'], ['down', 1, '↓']].forEach(function (b) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn-secondary btn-sm';
            btn.textContent = b[2];
            btn.setAttribute('aria-label', L(b[0]));
            btn.addEventListener('click', function () { move(si, fi, b[1]); });
            row.appendChild(btn);
          });
          var rm = document.createElement('button');
          rm.type = 'button';
          rm.className = 'btn-secondary btn-sm';
          rm.textContent = '×';
          rm.setAttribute('aria-label', L('remove'));
          rm.addEventListener('click', function () {
            s.fields.splice(fi, 1);
            if (!s.fields.length) schema.sections.splice(si, 1);
            save();
            render();
          });
          row.appendChild(rm);
          sec.appendChild(row);
        });
        list.appendChild(sec);
      });
      if (!any) {
        var p = document.createElement('p');
        p.className = 'muted';
        p.textContent = L('empty');
        list.appendChild(p);
      }
    }
    function slug(s) {
      return String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 40) || ('s' + (schema.sections.length + 1));
    }

    var inSection = wrap.querySelector('[data-b-section]');
    var inId = wrap.querySelector('[data-b-id]');
    var inLabel = wrap.querySelector('[data-b-label]');
    var inType = wrap.querySelector('[data-b-type]');
    var inOptions = wrap.querySelector('[data-b-options]');
    var inReq = wrap.querySelector('[data-b-required]');
    var inIdx = wrap.querySelector('[data-b-index]');
    var optWrap = wrap.querySelector('.docs-builder-options');
    function syncType() {
      var t = inType.value;
      if (optWrap) optWrap.hidden = !(t === 'select' || t === 'multiselect');
    }
    inType.addEventListener('change', syncType);
    syncType();

    wrap.querySelector('[data-b-add]').addEventListener('click', function () {
      if (!load()) return;
      var id = String(inId.value || '').trim();
      if (!/^[a-z][a-z0-9_]{0,63}$/.test(id)) {
        window.alert(L('bad-id') + ' ' + id);
        inId.focus();
        return;
      }
      if (allIds()[id]) {
        window.alert(L('dup-id') + ' ' + id);
        inId.focus();
        return;
      }
      var title = String(inSection.value || '').trim();
      var sec = null;
      schema.sections.forEach(function (s) {
        if (!sec && ((s.title || '') === title || (s.id || '') === title)) sec = s;
      });
      if (!sec) {
        sec = { id: slug(title), title: title, fields: [] };
        schema.sections.push(sec);
      }
      var field = { id: id, type: inType.value, label: String(inLabel.value || '').trim() || id };
      if (inReq.checked) field.required = true;
      if (inIdx.checked) field.index = true;
      if (field.type === 'select' || field.type === 'multiselect') {
        field.options = String(inOptions.value || '').split(/\n+/).map(function (line) {
          line = line.trim();
          if (!line) return null;
          var eq = line.indexOf('=');
          return eq > 0
            ? { value: line.slice(0, eq).trim(), label: line.slice(eq + 1).trim() }
            : { value: line, label: line };
        }).filter(Boolean);
      }
      sec.fields = sec.fields || [];
      sec.fields.push(field);
      save();
      render();
      if (chips) {
        var chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'btn-secondary btn-sm';
        chip.setAttribute('data-ph', '{' + id + '}');
        chip.textContent = '{' + id + '}';
        chips.appendChild(chip);
      }
      inId.value = '';
      inLabel.value = '';
      inOptions.value = '';
      inReq.checked = false;
      inIdx.checked = false;
      inId.focus();
    });

    ta.addEventListener('change', function () { if (load()) render(); });
    if (load()) render();
  }

  function bindCheckAll(root) {
    root.querySelectorAll('[data-docs-check-all]').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var table = cb.closest('table');
        if (!table) return;
        table.querySelectorAll('input[name="files[]"]').forEach(function (x) { x.checked = cb.checked; });
      });
    });
  }

  function boot() {
    bindConfirm(document);
    bindCheckAll(document);
    bindSegments(document);
    document.querySelectorAll('.docs-def-form').forEach(function (form) {
      bindBuilder(form);
      bindPlaceholders(form);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
