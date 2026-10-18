(function () {
  'use strict';

  function loadDay(details) {
    if (!details || details.getAttribute('data-loaded') === '1') {
      return;
    }
    var url = details.getAttribute('data-day-url') || '';
    var body = details.querySelector('.procents-day-body');
    if (!url || !body) {
      return;
    }
    details.setAttribute('data-loaded', '1');
    fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'text/html' },
    })
      .then(function (res) {
        if (!res.ok) {
          throw new Error('HTTP ' + res.status);
        }
        return res.text();
      })
      .then(function (html) {
        body.innerHTML = html;
      })
      .catch(function () {
        details.removeAttribute('data-loaded');
        var tree = details.closest('.procents-day-tree');
        var audit = details.closest('.procents-audit');
        var msg =
          (tree && tree.getAttribute('data-error')) ||
          (audit && audit.getAttribute('data-error')) ||
          'Error';
        var p = document.createElement('p');
        p.className = 'flash flash-error';
        p.textContent = msg;
        body.innerHTML = '';
        body.appendChild(p);
      });
  }

  function bindDays(root) {
    var scope = root || document;
    scope.querySelectorAll('details.procents-day[data-day-url]').forEach(function (el) {
      if (el.getAttribute('data-bound') === '1') {
        return;
      }
      el.setAttribute('data-bound', '1');
      el.addEventListener('toggle', function () {
        if (el.open) {
          loadDay(el);
        }
      });
    });
  }

  function loadMonthAudit(block) {
    if (!block || block.getAttribute('data-loaded') === '1') {
      return;
    }
    var url = block.getAttribute('data-days-url') || '';
    var body = block.querySelector('.procents-audit-body');
    var btn = block.querySelector('.procents-audit-open');
    if (!url || !body) {
      return;
    }
    block.setAttribute('data-loaded', '1');
    if (btn) {
      btn.disabled = true;
    }
    body.hidden = false;
    body.innerHTML = '<p class="muted procents-day-loading"></p>';
    var loading = body.querySelector('.procents-day-loading');
    if (loading) {
      loading.textContent = (btn && btn.getAttribute('data-loading')) || '…';
    }
    fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'text/html' },
    })
      .then(function (res) {
        if (!res.ok) {
          throw new Error('HTTP ' + res.status);
        }
        return res.text();
      })
      .then(function (html) {
        body.innerHTML = html;
        if (btn) {
          btn.hidden = true;
        }
        bindDays(body);
      })
      .catch(function () {
        block.removeAttribute('data-loaded');
        if (btn) {
          btn.disabled = false;
          btn.hidden = false;
        }
        var msg = block.getAttribute('data-error') || 'Error';
        var p = document.createElement('p');
        p.className = 'flash flash-error';
        p.textContent = msg;
        body.innerHTML = '';
        body.appendChild(p);
      });
  }

  function bindAuditButtons(root) {
    var scope = root || document;
    scope.querySelectorAll('.procents-audit[data-days-url]').forEach(function (block) {
      if (block.getAttribute('data-audit-bound') === '1') {
        return;
      }
      block.setAttribute('data-audit-bound', '1');
      var btn = block.querySelector('.procents-audit-open');
      if (!btn) {
        return;
      }
      btn.addEventListener('click', function () {
        loadMonthAudit(block);
      });
    });
  }

  function init() {
    bindDays(document);
    bindAuditButtons(document);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
