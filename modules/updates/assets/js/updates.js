(function () {
  'use strict';
  var root = document.getElementById('updates-app');
  if (!root) return;

  var i18n = {};
  try {
    i18n = JSON.parse(root.getAttribute('data-i18n') || '{}');
  } catch (e) {
    i18n = {};
  }

  var overlay = document.getElementById('upd-job-overlay');
  var titleEl = document.getElementById('upd-job-title');
  var msgEl = document.getElementById('upd-job-msg');
  var fillEl = document.getElementById('upd-job-fill');
  var barEl = document.getElementById('upd-job-bar');
  var pctEl = document.getElementById('upd-job-pct');
  var metaEl = document.getElementById('upd-job-meta');
  var abortBtn = document.getElementById('upd-job-abort');
  var okBtn = document.getElementById('upd-job-ok');
  var applyFoundBtn = document.getElementById('upd-job-apply-found');
  var foundWrap = document.getElementById('upd-job-found');
  var foundTitle = document.getElementById('upd-job-found-title');
  var foundList = document.getElementById('upd-job-found-list');
  var canApply = root.getAttribute('data-can-apply') === '1';

  var activeJobId = null;
  var activeKind = 'apply';
  var finished = false;
  var aborting = false;
  var startedAt = 0;
  var timerIv = null;
  var lastFoundCount = 0;

  function formatFoundLine(item) {
    var tpl = i18n.foundLine || '{name}: {local} → {remote}';
    return tpl
      .replace('{name}', item.label || item.id || '')
      .replace('{local}', item.local_version || '—')
      .replace('{remote}', item.remote_version || '—');
  }

  function renderFound(found) {
    if (!foundWrap || !foundList) return;
    found = found || [];
    lastFoundCount = found.length;
    if (activeKind !== 'check') {
      foundWrap.hidden = true;
      foundList.innerHTML = '';
      return;
    }
    foundWrap.hidden = false;
    if (foundTitle) {
      foundTitle.textContent = found.length
        ? (i18n.foundTitle || 'Found updates:')
        : (i18n.foundEmpty || 'No updates found yet…');
    }
    foundList.innerHTML = '';
    found.forEach(function (item) {
      var li = document.createElement('li');
      li.textContent = formatFoundLine(item);
      foundList.appendChild(li);
    });
  }

  function setApplyFoundVisible(show) {
    if (!applyFoundBtn) return;
    var visible = !!(show && canApply && lastFoundCount > 0 && finished && activeKind === 'check');
    applyFoundBtn.hidden = !visible;
    applyFoundBtn.disabled = !visible;
  }

  function setBar(pct) {
    pct = Math.max(0, Math.min(100, pct | 0));
    if (fillEl) fillEl.style.width = pct + '%';
    if (barEl) barEl.setAttribute('aria-valuenow', String(pct));
    if (pctEl) pctEl.textContent = pct + '%';
  }

  function showJob(kind) {
    activeKind = kind || 'apply';
    finished = false;
    aborting = false;
    startedAt = Date.now();
    lastFoundCount = 0;
    if (overlay) overlay.hidden = false;
    document.body.classList.add('upd-job-busy');
    if (okBtn) { okBtn.hidden = true; okBtn.disabled = true; }
    if (applyFoundBtn) {
      applyFoundBtn.hidden = true;
      applyFoundBtn.disabled = true;
    }
    if (abortBtn) { abortBtn.hidden = false; abortBtn.disabled = false; }
    if (titleEl) {
      titleEl.textContent = activeKind === 'check'
        ? (i18n.checkTitle || 'Checking…')
        : (i18n.title || 'Updating…');
    }
    renderFound([]);
    setBar(0);
    if (timerIv) clearInterval(timerIv);
    timerIv = setInterval(function () {
      if (!metaEl || finished) return;
      var sec = Math.floor((Date.now() - startedAt) / 1000);
      var m = Math.floor(sec / 60);
      var s = sec % 60;
      metaEl.textContent = m + ':' + (s < 10 ? '0' : '') + s;
    }, 500);
  }

  function finishJob(status, message) {
    finished = true;
    if (timerIv) { clearInterval(timerIv); timerIv = null; }
    if (abortBtn) abortBtn.hidden = true;
    if (okBtn) { okBtn.hidden = false; okBtn.disabled = false; }
    setApplyFoundVisible(status === 'done' && activeKind === 'check');
    if (msgEl && message) msgEl.textContent = message;
    if (titleEl) {
      if (status === 'done') {
        titleEl.textContent = activeKind === 'check'
          ? (i18n.checkDoneTitle || i18n.doneTitle || 'Done')
          : (i18n.doneTitle || 'Done');
      } else if (status === 'cancelled') {
        titleEl.textContent = i18n.cancelledTitle || 'Cancelled';
      } else {
        titleEl.textContent = i18n.errorTitle || 'Error';
      }
    }
    if (status === 'done') setBar(100);
  }

  function hideJob() {
    if (overlay) overlay.hidden = true;
    document.body.classList.remove('upd-job-busy');
    if (timerIv) { clearInterval(timerIv); timerIv = null; }
    activeJobId = null;
  }

  function applyJob(j) {
    if (!j) return;
    if (msgEl) {
      var msg = j.message || '';
      if (activeKind !== 'check' && (j.target_label || j.target)) {
        var label = j.target_label || j.target;
        msg = (label + (j.targets_total ? (' (' + ((j.target_index || 0) + 1) + '/' + j.targets_total + ')') : '') + ': ') + msg;
      }
      msgEl.textContent = msg;
    }
    if (activeKind === 'check') {
      renderFound(j.found || []);
    }
    setBar(j.progress || 0);
  }

  function postForm(fd) {
    return fetch('/updates', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    }).then(function (r) {
      return r.text().then(function (text) {
        var j = null;
        try { j = text ? JSON.parse(text) : null; } catch (err) {
          throw new Error((i18n.badJson || 'Bad JSON') + ' HTTP ' + r.status);
        }
        if (!r.ok || (j && j.ok === false)) {
          throw new Error((j && (j.error || j.message)) || ('HTTP ' + r.status));
        }
        return j || {};
      });
    });
  }

  if (okBtn) {
    okBtn.addEventListener('click', function () {
      hideJob();
      window.location.reload();
    });
  }
  if (applyFoundBtn) {
    applyFoundBtn.addEventListener('click', function () {
      if (!canApply || lastFoundCount < 1) return;
      var confirmMsg = i18n.confirmApply || '';
      if (confirmMsg && !window.confirm(confirmMsg)) return;
      setApplyFoundVisible(false);
      if (okBtn) { okBtn.hidden = true; okBtn.disabled = true; }
      runApply('all');
    });
  }
  if (abortBtn) {
    abortBtn.addEventListener('click', function () {
      if (!activeJobId || finished || aborting) return;
      aborting = true;
      abortBtn.disabled = true;
      var fd = new FormData();
      fd.set('action', 'update_job_cancel');
      fd.set('job_id', activeJobId);
      fd.set('format', 'json');
      postForm(fd).then(function (res) {
        applyJob(res.job || {});
        finishJob('cancelled', (res.job && res.job.message) || '');
      }).catch(function (err) {
        finishJob('error', err && err.message ? err.message : String(err));
      });
    });
  }

  function runLoop(startAction, tickAction, kind) {
    var fd = new FormData();
    fd.set('action', startAction);
    fd.set('format', 'json');
    showJob(kind);
    return postForm(fd).then(function (start) {
      var job = start.job || {};
      var id = job.id;
      if (!id) throw new Error('No job id');
      activeJobId = id;
      applyJob(job);

      function tick() {
        if (finished || aborting) return;
        var tfd = new FormData();
        tfd.set('action', tickAction);
        tfd.set('job_id', id);
        tfd.set('format', 'json');
        return postForm(tfd).then(function (res) {
          if (finished || aborting) return;
          var j = res.job || {};
          applyJob(j);
          if (j.status === 'done') {
            finishJob('done', j.message || '');
            return;
          }
          if (j.status === 'cancelled') {
            finishJob('cancelled', j.message || '');
            return;
          }
          if (j.status === 'error') {
            finishJob('error', j.error || j.message || 'Job failed');
            return;
          }
          return new Promise(function (resolve) { setTimeout(resolve, 50); }).then(tick);
        });
      }
      return tick();
    }).catch(function (err) {
      finishJob('error', err && err.message ? err.message : String(err));
    });
  }

  function runApply(target) {
    var fd = new FormData();
    fd.set('action', 'update_job_start');
    fd.set('target', target || 'all');
    fd.set('format', 'json');
    showJob('apply');
    return postForm(fd).then(function (start) {
      var job = start.job || {};
      var id = job.id;
      if (!id) throw new Error('No job id');
      activeJobId = id;
      applyJob(job);

      function tick() {
        if (finished || aborting) return;
        var tfd = new FormData();
        tfd.set('action', 'update_job_tick');
        tfd.set('job_id', id);
        tfd.set('format', 'json');
        return postForm(tfd).then(function (res) {
          if (finished || aborting) return;
          var j = res.job || {};
          applyJob(j);
          if (j.status === 'done') {
            finishJob('done', j.message || '');
            return;
          }
          if (j.status === 'cancelled') {
            finishJob('cancelled', j.message || '');
            return;
          }
          if (j.status === 'error') {
            finishJob('error', j.error || j.message || 'Job failed');
            return;
          }
          return new Promise(function (resolve) { setTimeout(resolve, 200); }).then(tick);
        });
      }
      return tick();
    }).catch(function (err) {
      finishJob('error', err && err.message ? err.message : String(err));
    });
  }

  function runCheck() {
    return runLoop('update_check_job_start', 'update_check_job_tick', 'check');
  }

  document.querySelectorAll('[data-upd-apply]').forEach(function (btn) {
    btn.addEventListener('click', function (ev) {
      ev.preventDefault();
      var target = btn.getAttribute('data-upd-apply') || 'all';
      var confirmMsg = i18n.confirmApply || '';
      if (confirmMsg && !window.confirm(confirmMsg)) return;
      runApply(target);
    });
  });

  document.querySelectorAll('[data-upd-check]').forEach(function (btn) {
    btn.addEventListener('click', function (ev) {
      ev.preventDefault();
      runCheck();
    });
  });
})();
