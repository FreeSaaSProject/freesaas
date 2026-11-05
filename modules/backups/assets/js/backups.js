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
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!(form instanceof HTMLFormElement) || ev.defaultPrevented) return;
    if (form.hasAttribute('data-bk-run') || form.hasAttribute('data-bk-replica-pull')) return;
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

  /* ——— Replica pull: tick job so the timer keeps moving and proxies don't kill one long request ——— */
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!(form instanceof HTMLFormElement) || ev.defaultPrevented) return;
    if (!form.hasAttribute('data-bk-replica-pull')) return;
    ev.preventDefault();
    replicaPullPopup(form);
  });

  function replicaPullPopup(form) {
    var ui = makeOverlay(L.replicaPullTitle || '');
    var msg = el('p', 'db-job-msg muted', L.starting || '');
    ui.panel.appendChild(msg);

    var steps = el('ol', 'bk-steps');
    ui.panel.appendChild(steps);

    var barBlock = el('div', 'db-job-bar-block');
    var barLabel = el('div', 'db-job-bar-label');
    var phaseName = el('span', '', '');
    var pctEl = el('span', '', '');
    barLabel.appendChild(phaseName);
    barLabel.appendChild(pctEl);
    var bar = el('div', 'db-job-bar');
    var fill = el('div', 'db-job-fill');
    bar.appendChild(fill);
    barBlock.appendChild(barLabel);
    barBlock.appendChild(bar);
    var bars = el('div', 'db-job-bars');
    bars.appendChild(barBlock);
    ui.panel.appendChild(bars);

    var meta = el('div', 'db-job-metric');
    var sizeEl = el('span', 'muted', '');
    meta.appendChild(ui.clock);
    meta.appendChild(sizeEl);
    ui.panel.appendChild(meta);

    var actions = el('div', 'db-job-actions actions');
    var hideBtn = el('button', 'btn-secondary', L.hide || 'Hide');
    hideBtn.type = 'button';
    hideBtn.title = L.replicaHideHint || '';
    var retryBtn = el('button', 'btn-primary', L.retry || 'Retry');
    retryBtn.type = 'button';
    retryBtn.hidden = true;
    var okBtn = el('button', 'btn-primary', L.login || L.ok || 'OK');
    okBtn.type = 'button';
    okBtn.hidden = true;
    actions.appendChild(hideBtn);
    actions.appendChild(retryBtn);
    actions.appendChild(okBtn);
    ui.panel.appendChild(actions);

    var stopped = false;
    var tickToken = '';
    var failStreak = 0;
    var needRelogin = false;
    var phases = L.replicaPhases || {};

    function renderProgress(p) {
      if (!p) return;
      steps.innerHTML = '';
      (p.phases || []).forEach(function (ph, i) {
        var li = el('li', '', phases[ph] || ph);
        if (i < p.index) li.className = 'is-done';
        else if (i === p.index) li.className = 'is-current';
        steps.appendChild(li);
      });
      phaseName.textContent = phases[p.phase] || p.phase || '';
      if (p.pct == null) {
        bar.classList.add('is-indeterminate');
        fill.style.width = '';
        pctEl.textContent = '';
      } else {
        bar.classList.remove('is-indeterminate');
        fill.style.width = Math.max(0, Math.min(100, p.pct)) + '%';
        pctEl.textContent = p.pct + '%';
      }
      if (p.bytes) sizeEl.textContent = (L.size || '') + ' ' + fmtBytes(p.bytes);
    }

    function finish(status, text, opts) {
      opts = opts || {};
      stopped = true;
      ui.stop();
      hideBtn.hidden = true;
      bar.classList.remove('is-indeterminate');
      msg.classList.toggle('bk-error', status !== 'done');
      if (status === 'done') {
        retryBtn.hidden = true;
        okBtn.hidden = false;
        ui.title.textContent = L.replicaDoneTitle || L.doneTitle || '';
        fill.style.width = '100%';
        pctEl.textContent = '100%';
        needRelogin = !!opts.relogin;
        okBtn.textContent = needRelogin ? (L.login || L.ok || 'OK') : (L.ok || 'OK');
        okBtn.focus();
      } else {
        ui.title.textContent = L.errorTitle || '';
        var canResume = !!opts.resumable && !!tickToken;
        retryBtn.hidden = !canResume;
        okBtn.hidden = false;
        okBtn.textContent = L.ok || 'OK';
        needRelogin = false;
        if (canResume) retryBtn.focus(); else okBtn.focus();
      }
      msg.textContent = text || '';
    }

    function parseJson(r) {
      return r.text().then(function (t) {
        var j = null;
        try { j = JSON.parse(t); } catch (e) { j = null; }
        if (!r.ok || !j) {
          var err = new Error((j && (j.error || j.message)) || ((L.httpError || 'HTTP') + ' ' + r.status));
          err.status = r.status;
          err.retryable = !j || r.status >= 500 || r.status === 0;
          throw err;
        }
        return j;
      });
    }

    function handleJob(j) {
      failStreak = 0;
      renderProgress(j.progress);
      var st = j.status || '';
      var ph = (j.progress && j.progress.phase) || j.phase || '';
      var mode = j.pull_mode || (j.progress && j.progress.pull_mode) || '';
      msg.textContent = (phases[ph] || ph || '')
        + (mode ? ' · ' + mode : '')
        + (j.baseline_name ? ' · ' + j.baseline_name : '');
      if (st === 'running') {
        setTimeout(tick, 400);
      } else if (st === 'done') {
        finish('done', j.message || L.replicaDoneHint || '', { relogin: !!j.relogin });
      } else if (st === 'paused') {
        finish('error', j.error || '', { resumable: true });
      } else {
        finish('error', j.error || '', { resumable: !!j.resumable });
      }
    }

    function tick() {
      if (stopped) return;
      var fd = new FormData();
      fd.set('tick_token', tickToken);
      fd.set('wall', '20');
      fetch('/backups/replica/tick', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(parseJson).then(handleJob).catch(function (e) {
        failStreak += 1;
        // Transient proxy/PHP timeouts during restore — keep pumping; cron also advances.
        if (!stopped && failStreak < 12 && (!e || e.retryable !== false)) {
          msg.textContent = (L.retrying || '') + ' (' + failStreak + ')';
          setTimeout(tick, Math.min(8000, 1000 * failStreak));
          return;
        }
        finish('error', e && e.message ? e.message : String(e), { resumable: !!tickToken });
      });
    }

    function resumeUi() {
      stopped = false;
      failStreak = 0;
      msg.classList.remove('bk-error');
      retryBtn.hidden = true;
      okBtn.hidden = true;
      hideBtn.hidden = false;
      ui.title.textContent = L.replicaPullTitle || '';
      msg.textContent = L.retrying || L.starting || '';
    }

    hideBtn.addEventListener('click', function () {
      stopped = true;
      ui.close();
      window.alert(L.replicaHideHint || '');
      window.location.reload();
    });
    retryBtn.addEventListener('click', function () {
      resumeUi();
      // Prefer tick (resumes paused). If token lost, re-POST start.
      if (tickToken) {
        tick();
        return;
      }
      var startFd = new FormData(form);
      startFd.set('action', 'pull_start');
      fetch(form.getAttribute('action') || '/backups/replica', {
        method: 'POST',
        body: startFd,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(parseJson).then(function (j) {
        if (!j.ok && j.status === 'error' && !j.resumable) {
          finish('error', j.error || '', { resumable: false });
          return;
        }
        if (j.status === 'done') {
          finish('done', j.message || L.replicaDoneHint || '', { relogin: !!j.relogin });
          return;
        }
        tickToken = j.tick_token || tickToken;
        handleJob(j);
      }).catch(function (e) {
        finish('error', e && e.message ? e.message : String(e), { resumable: !!tickToken });
      });
    });
    okBtn.addEventListener('click', function () {
      ui.close();
      if (needRelogin) {
        window.location.href = '/login';
      } else {
        window.location.reload();
      }
    });

    var startFd = new FormData(form);
    startFd.set('action', 'pull_start');
    fetch(form.getAttribute('action') || '/backups/replica', {
      method: 'POST',
      body: startFd,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(parseJson).then(function (j) {
      if (!j.ok && j.status !== 'paused') {
        finish('error', j.error || '', { resumable: !!j.resumable });
        return;
      }
      if (j.status === 'done') {
        finish('done', j.message || L.replicaDoneHint || '', { relogin: !!j.relogin });
        return;
      }
      tickToken = j.tick_token || '';
      if (!tickToken) {
        finish('error', L.httpError || 'no tick token', { resumable: false });
        return;
      }
      handleJob(j);
    }).catch(function (e) {
      finish('error', e && e.message ? e.message : String(e), { resumable: false });
    });
  }

  /* ——— Run popup: start/continue a backup and pump it with progress ——— */
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!(form instanceof HTMLFormElement) || ev.defaultPrevented) return;
    var kind = form.getAttribute('data-bk-run');
    if (!kind) return;
    ev.preventDefault();
    runPopup(form, kind);
  });

  function runPopup(form, kind) {
    var titles = L.runTitle || {};
    var ui = makeOverlay(titles[kind] || titles.continue || '');
    var msg = el('p', 'db-job-msg muted', L.starting || '');
    ui.panel.appendChild(msg);

    var steps = el('ol', 'bk-steps');
    ui.panel.appendChild(steps);

    var barBlock = el('div', 'db-job-bar-block');
    var barLabel = el('div', 'db-job-bar-label');
    var phaseName = el('span', '', '');
    var pctEl = el('span', '', '');
    barLabel.appendChild(phaseName);
    barLabel.appendChild(pctEl);
    var bar = el('div', 'db-job-bar');
    var fill = el('div', 'db-job-fill');
    bar.appendChild(fill);
    barBlock.appendChild(barLabel);
    barBlock.appendChild(bar);
    var bars = el('div', 'db-job-bars');
    bars.appendChild(barBlock);
    ui.panel.appendChild(bars);

    var meta = el('div', 'db-job-metric');
    var sizeEl = el('span', 'muted', '');
    meta.appendChild(ui.clock);
    meta.appendChild(sizeEl);
    ui.panel.appendChild(meta);

    var actions = el('div', 'db-job-actions actions');
    var hideBtn = el('button', 'btn-secondary', L.hide || 'Hide');
    hideBtn.type = 'button';
    hideBtn.title = L.hideHint || '';
    var abortBtn = el('button', 'btn-secondary', L.abort || 'Cancel');
    abortBtn.type = 'button';
    var okBtn = el('button', 'btn-primary', L.ok || 'OK');
    okBtn.type = 'button';
    okBtn.hidden = true;
    actions.appendChild(hideBtn);
    actions.appendChild(abortBtn);
    actions.appendChild(okBtn);
    ui.panel.appendChild(actions);

    var stopped = false;
    var firstCall = true;
    var phases = L.phases || {};

    function renderProgress(p, run) {
      if (!p) return;
      steps.innerHTML = '';
      (p.phases || []).forEach(function (ph, i) {
        var li = el('li', '', phases[ph] || ph);
        if (i < p.index) li.className = 'is-done';
        else if (i === p.index && run.status === 'running') li.className = 'is-current';
        steps.appendChild(li);
      });
      phaseName.textContent = phases[p.phase] || p.phase || '';
      if (p.pct == null) {
        bar.classList.add('is-indeterminate');
        fill.style.width = '';
        pctEl.textContent = '';
      } else {
        bar.classList.remove('is-indeterminate');
        fill.style.width = Math.max(0, Math.min(100, p.pct)) + '%';
        pctEl.textContent = p.pct + '%';
      }
      if (run.bytes) sizeEl.textContent = (L.size || '') + ' ' + fmtBytes(run.bytes);
    }

    function finish(status, text) {
      stopped = true;
      ui.stop();
      hideBtn.hidden = true;
      abortBtn.hidden = true;
      okBtn.hidden = false;
      bar.classList.remove('is-indeterminate');
      if (status === 'done') {
        ui.title.textContent = L.doneTitle || '';
        fill.style.width = '100%';
        pctEl.textContent = '100%';
      } else if (status === 'cancelled') {
        ui.title.textContent = L.cancelledTitle || '';
      } else {
        ui.title.textContent = L.errorTitle || '';
        msg.classList.add('bk-error');
      }
      msg.textContent = text || '';
      okBtn.focus();
    }

    function post(url, extra) {
      var fd = new FormData(form);
      if (!firstCall) fd.delete('kind');
      fd.set('wall', '20');
      Object.keys(extra || {}).forEach(function (k) { fd.set(k, extra[k]); });
      return fetch(url, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(function (r) {
        return r.text().then(function (t) {
          var j = null;
          try { j = JSON.parse(t); } catch (e) { j = null; }
          if (!r.ok || !j) {
            throw new Error((j && (j.error || j.message)) || ((L.httpError || 'HTTP') + ' ' + r.status));
          }
          return j;
        });
      });
    }

    function step() {
      if (stopped) return;
      post(form.getAttribute('action') || '/backups/run').then(function (j) {
        firstCall = false;
        var run = j.run || {};
        renderProgress(j.progress, run);
        msg.textContent = '#' + (run.id || j.run_id || '') + (run.remote_dir ? ' · ' + run.remote_dir : '');
        if (run.status === 'running') {
          setTimeout(step, 300);
        } else if (run.status === 'done') {
          finish('done', run.remote_dir ? (run.remote_dir + ' · ' + fmtBytes(run.bytes || 0)) : '');
        } else if (run.status === 'cancelled') {
          finish('cancelled', '');
        } else {
          finish('error', run.error || '');
        }
      }).catch(function (e) {
        finish('error', e && e.message ? e.message : String(e));
      });
    }

    hideBtn.addEventListener('click', function () {
      // Run keeps going on the server (cron); just leave the popup.
      stopped = true;
      ui.close();
      window.location.reload();
    });
    abortBtn.addEventListener('click', function () {
      if (!window.confirm(L.abortConfirm || '')) return;
      stopped = true;
      abortBtn.disabled = true;
      var fd = new FormData(form);
      fd.delete('kind');
      fetch('/backups/cancel', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(function () {
        finish('cancelled', '');
      }).catch(function (e) {
        finish('error', e && e.message ? e.message : String(e));
      });
    });
    okBtn.addEventListener('click', function () {
      ui.close();
      window.location.reload();
    });

    step();
  }
})();
