(function () {
  'use strict';

  /* Pipeline → stage select refill (deal edit / quick create). */
  function initStageSelects(root) {
    root.querySelectorAll('select[data-crm-pipeline]').forEach(function (pipe) {
      var form = pipe.form;
      var stage = form ? form.querySelector('select[data-crm-stage]') : null;
      if (!stage) return;
      var map = {};
      try { map = JSON.parse(stage.getAttribute('data-stages') || '{}'); } catch (e) { map = {}; }
      pipe.addEventListener('change', function () {
        var list = map[pipe.value] || [];
        stage.innerHTML = '';
        list.forEach(function (s) {
          var o = document.createElement('option');
          o.value = s.id;
          o.textContent = s.name;
          stage.appendChild(o);
        });
      });
    });
  }

  function formatMoney(n) {
    var abs = Math.abs(n);
    var digits = Math.abs(abs - Math.round(abs)) > 0.001 ? 2 : 0;
    return n.toLocaleString(document.documentElement.lang || 'ru', {
      minimumFractionDigits: digits, maximumFractionDigits: digits
    });
  }

  function refreshColumn(col) {
    var cards = col.querySelectorAll('.crm-kanban-card');
    var sums = {};
    cards.forEach(function (c) {
      var cur = c.getAttribute('data-currency') || 'RUB';
      sums[cur] = (sums[cur] || 0) + (parseFloat(c.getAttribute('data-amount')) || 0);
    });
    var parts = Object.keys(sums).filter(function (k) { return sums[k] !== 0; }).map(function (k) {
      return formatMoney(sums[k]) + ' ' + k;
    });
    var cnt = col.querySelector('[data-col-count]');
    var sum = col.querySelector('[data-col-sum]');
    if (cnt) cnt.textContent = String(cards.length);
    if (sum) sum.textContent = parts.length ? parts.join(' · ') : '0';
  }

  /* Kanban drag & drop → POST /crm/deals/{id} action=move_stage (same op as crm.deals.move_stage). */
  function initKanban(board) {
    if (board.getAttribute('data-can-edit') !== '1') return;
    var errText = board.getAttribute('data-err') || 'Error';
    var dragging = null;
    var fromZone = null;
    var nextSibling = null;

    board.addEventListener('dragstart', function (ev) {
      var card = ev.target.closest && ev.target.closest('.crm-kanban-card');
      if (!card) return;
      dragging = card;
      fromZone = card.parentElement;
      nextSibling = card.nextElementSibling;
      card.classList.add('is-dragging');
      if (ev.dataTransfer) {
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('text/plain', card.getAttribute('data-deal-id') || '');
      }
    });
    board.addEventListener('dragend', function () {
      if (dragging) dragging.classList.remove('is-dragging');
      board.querySelectorAll('.is-over').forEach(function (z) { z.classList.remove('is-over'); });
    });
    board.querySelectorAll('[data-drop-zone]').forEach(function (zone) {
      zone.addEventListener('dragover', function (ev) {
        if (!dragging) return;
        ev.preventDefault();
        zone.classList.add('is-over');
      });
      zone.addEventListener('dragleave', function () { zone.classList.remove('is-over'); });
      zone.addEventListener('drop', function (ev) {
        ev.preventDefault();
        zone.classList.remove('is-over');
        if (!dragging || zone === fromZone) return;
        var card = dragging;
        var origin = fromZone;
        var originNext = nextSibling;
        var col = zone.closest('.crm-kanban-col');
        var stageId = col ? col.getAttribute('data-stage-id') : '';
        var dealId = card.getAttribute('data-deal-id');
        zone.insertBefore(card, zone.firstChild);
        refreshColumn(col);
        refreshColumn(origin.closest('.crm-kanban-col'));
        card.classList.add('is-saving');
        var body = new FormData();
        body.append('action', 'move_stage');
        body.append('stage_id', stageId);
        fetch('/crm/deals/' + encodeURIComponent(dealId), {
          method: 'POST',
          body: body,
          headers: { 'Accept': 'application/json' },
          credentials: 'same-origin'
        }).then(function (r) {
          return r.json().catch(function () { return { ok: false }; }).then(function (j) {
            if (!r.ok || !j || !j.ok) throw new Error((j && j.error) || errText);
          });
        }).catch(function (e) {
          origin.insertBefore(card, originNext && originNext.parentElement === origin ? originNext : null);
          refreshColumn(col);
          refreshColumn(origin.closest('.crm-kanban-col'));
          window.alert(e && e.message ? e.message : errText);
        }).then(function () {
          card.classList.remove('is-saving');
        });
        dragging = null;
      });
    });
  }

  function init() {
    initStageSelects(document);
    document.querySelectorAll('[data-crm-kanban]').forEach(initKanban);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
