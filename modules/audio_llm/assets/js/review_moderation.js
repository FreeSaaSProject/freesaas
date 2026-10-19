(function () {
  'use strict';

  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  function escapeHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /** Escape + light markdown: **bold**, __bold__, blank lines → paragraphs, - lists. */
  function formatReportHtml(text) {
    var raw = String(text || '').replace(/\r\n/g, '\n').trim();
    if (!raw) return '';

    var blocks = raw.split(/\n{2,}/);
    var html = [];
    for (var bi = 0; bi < blocks.length; bi++) {
      var block = blocks[bi].trim();
      if (!block) continue;

      var lines = block.split('\n');
      var listItems = [];
      var allList = lines.length > 0 && lines.every(function (ln) {
        return /^\s*[-*•]\s+/.test(ln) || /^\s*\d+\.\s+/.test(ln);
      });

      if (allList) {
        var ol = /^\s*\d+\.\s+/.test(lines[0]);
        var tag = ol ? 'ol' : 'ul';
        for (var li = 0; li < lines.length; li++) {
          var item = lines[li].replace(/^\s*[-*•]\s+/, '').replace(/^\s*\d+\.\s+/, '');
          listItems.push('<li>' + inlineMd(item) + '</li>');
        }
        html.push('<' + tag + '>' + listItems.join('') + '</' + tag + '>');
        continue;
      }

      var para = lines.map(function (ln) {
        return inlineMd(ln);
      }).join('<br>');
      if (/^(ЕСТЬ СОБЫТИЯ|ЗНАЧИМЫХ СОБЫТИЙ НЕТ|THERE ARE EVENTS|NO SIGNIFICANT EVENTS)/i.test(block.replace(/\*+/g, ''))) {
        html.push('<p class="arm-verdict">' + para + '</p>');
      } else {
        html.push('<p>' + para + '</p>');
      }
    }
    return html.join('');
  }

  function inlineMd(s) {
    var esc = escapeHtml(s);
    esc = esc.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    esc = esc.replace(/__([^_]+)__/g, '<strong>$1</strong>');
    esc = esc.replace(/(^|[^*\w])\*([^*\n]+)\*(?=[^*\w]|$)/g, '$1<em>$2</em>');
    return esc;
  }

  function bind(panel) {
    if (!panel || panel.getAttribute('data-bound') === '1') return;
    panel.setAttribute('data-bound', '1');

    var btn = qs('[data-moderation-run]', panel);
    var status = qs('[data-moderation-status]', panel);
    var result = qs('[data-moderation-result]', panel);
    if (!btn) return;

    var endpoint = panel.getAttribute('data-endpoint') || '/audio/review-moderate';
    var year = panel.getAttribute('data-year') || '0';
    var month = panel.getAttribute('data-month') || '0';
    var day = panel.getAttribute('data-day') || '0';
    var admin = panel.getAttribute('data-admin') || '0';
    var i18n = {};
    try {
      i18n = JSON.parse(panel.getAttribute('data-i18n') || '{}') || {};
    } catch (e) {
      i18n = {};
    }

    btn.addEventListener('click', function () {
      if (btn.disabled) return;
      btn.disabled = true;
      if (status) status.textContent = i18n.working || '…';
      if (result) {
        result.hidden = true;
        result.innerHTML = '';
      }

      var body = new URLSearchParams();
      body.set('year', year);
      body.set('month', month);
      body.set('day', day);
      body.set('admin', admin);

      fetch(endpoint, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: body,
        credentials: 'same-origin',
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          btn.disabled = false;
          if (!data || !data.ok) {
            if (status) {
              status.textContent = (data && data.error) || (i18n.error || 'Error');
            }
            return;
          }
          if (status) {
            var meta = '';
            if (data.pending_count || data.context_count) {
              meta = (i18n.meta || '')
                .replace('{pending}', String(data.pending_count || 0))
                .replace('{context}', String(data.context_count || 0));
            }
            status.textContent = meta || (i18n.done || 'OK');
          }
          if (result) {
            result.hidden = false;
            result.innerHTML = formatReportHtml(data.text || '');
          }
        })
        .catch(function () {
          btn.disabled = false;
          if (status) status.textContent = i18n.network || 'Network error';
        });
    });
  }

  function init() {
    var panels = document.querySelectorAll('[data-review-moderation]');
    for (var i = 0; i < panels.length; i++) {
      bind(panels[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
