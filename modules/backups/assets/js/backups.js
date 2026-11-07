(function () {
  'use strict';

  var root = document.getElementById('bk-ui');
  if (!root) return;
  var L = {};
  try { L = JSON.parse(root.getAttribute('data-i18n') || '{}') || {}; } catch (e) { L = {}; }

  /* ——— Settings sub-tabs (one form, panels toggled client-side) ——— */
  var tabNav = document.querySelector('[data-bk-tabs]');
  if (tabNav) {
    tabNav.addEventListener('click', function (ev) {
      var a = ev.target.closest('[data-bk-tab]');
      if (!a) return;
      ev.preventDefault();
      var id = a.getAttribute('data-bk-tab');
      tabNav.querySelectorAll('[data-bk-tab]').forEach(function (t) {
        var on = t === a;
        t.classList.toggle('is-active', on);
        if (on) t.setAttribute('aria-current', 'page'); else t.removeAttribute('aria-current');
      });
      document.querySelectorAll('[data-bk-panel]').forEach(function (p) {
        p.hidden = p.getAttribute('data-bk-panel') !== id;
      });
      document.querySelectorAll('[data-bk-tab-input]').forEach(function (inp) { inp.value = id; });
      if (window.history && history.replaceState) {
        var u = new URL(window.location.href);
        u.searchParams.set('tab', id);
        history.replaceState(null, '', u.pathname + u.search);
      }
    });
  }

  /* ——— Popup shell (reuses core .db-job-overlay / .db-job-panel) ——— */
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }
  function fmtDur(sec) {
    sec = Math.max(0, Math.floor(sec || 0));
    var m = Math.floor(sec / 60), s = sec % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }
  function fmtBytes(n) {
    n = Math.max(0, n || 0);
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' KiB';
    if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MiB';
    return (n / 1073741824).toFixed(2) + ' GiB';
  }

  function makeOverlay(title) {
    var overlay = el('div', 'db-job-overlay bk-overlay');
    var panel = el('div', 'db-job-panel');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    var h = el('h3', 'subhead', title);
    panel.appendChild(h);
    overlay.appendChild(panel);
    document.body.appendChild(overlay);
    document.body.classList.add('db-job-busy');
    var started = Date.now();
    var clock = el('span', 'bk-elapsed muted', (L.elapsed || '') + ' 0:00');
    var iv = setInterval(function () {
      clock.textContent = (L.elapsed || '') + ' ' + fmtDur((Date.now() - started) / 1000);
    }, 500);
    return {
      overlay: overlay,
      panel: panel,
      title: h,
      clock: clock,
      stop: function () { clearInterval(iv); },
      close: function () {
        clearInterval(iv);
        overlay.remove();
        document.body.classList.remove('db-job-busy');
      }
    };
  }

  /* ——— Busy popup: long form submits (FTP test, doctor, restore…) ——— */
  /* Replica check/pull use LongJobPanel on /backups/replica. */
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!(form instanceof HTMLFormElement) || ev.defaultPrevented) return;
    if (form.hasAttribute('data-bk-run') || form.hasAttribute('data-longjob-op')) return;
    var sub = ev.submitter || null;
    var title = (sub && sub.getAttribute('data-bk-busy')) || form.getAttribute('data-bk-busy');
    if (!title) return;
    var ui = makeOverlay(title);
    var row = el('div', 'bk-busy-row');
    row.appendChild(el('span', 'bk-spinner'));
    row.appendChild(el('span', 'db-job-msg muted', L.busyHint || ''));
    ui.panel.appendChild(row);
    var meta = el('div', 'db-job-metric');
    meta.appendChild(ui.clock);
    ui.panel.appendChild(meta);
    // Let the browser navigate normally; the server response replaces the page.
  });

  /* Backup run uses LongJobPanel on /backups (list.php). */
})();
