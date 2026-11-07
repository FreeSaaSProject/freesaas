/**
 * Shared LongJob / db-job overlay poller.
 * Mount: LongJobPanel.mount({ root, startUrl, tickUrl, cancelUrl, i18n, dualBars, onDone })
 * Or bind forms: LongJobPanel.bindForms({ formSelector, ... })
 *
 * Replica pull extras: tickTokenField, maxFailStreak, cancelMode:'hide', resumeOnPaused, onOk(job).
 */
(function (global) {
  'use strict';

  function fmtDur(sec) {
    sec = Math.max(0, Math.floor(sec || 0));
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }
  function fmtBytes(n) {
    n = Math.max(0, n || 0);
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
    return (n / 1048576).toFixed(2) + ' MB';
  }

  function createPanel(opts) {
    var root = opts.root;
    if (!root) return null;
    var i18n = opts.i18n || {};
    var dualBars = opts.dualBars !== false;
    var showBytes = opts.showBytes !== false;
    var reloadOnOk = opts.reloadOnOk !== false;
    var maxFailStreak = Math.max(1, parseInt(opts.maxFailStreak, 10) || 3);
    var cancelMode = opts.cancelMode || 'cancel'; // 'cancel' | 'hide'
    var resumeOnPaused = opts.resumeOnPaused === true;

    var overlay = root.querySelector('[data-longjob-overlay]') || root;
    var msgEl = root.querySelector('[data-longjob-msg]');
    var fillOverall = root.querySelector('[data-longjob-fill-overall]');
    var fillDetail = root.querySelector('[data-longjob-fill-detail]');
    var barOverall = root.querySelector('[data-longjob-bar-overall]');
    var barDetail = root.querySelector('[data-longjob-bar-detail]');
    var pctOverall = root.querySelector('[data-longjob-pct-overall]');
    var pctDetail = root.querySelector('[data-longjob-pct-detail]');
    var detailLabel = root.querySelector('[data-longjob-detail-label]');
    var elapsedEl = root.querySelector('[data-longjob-elapsed]');
    var etaEl = root.querySelector('[data-longjob-eta]');
    var bytesEl = root.querySelector('[data-longjob-bytes]');
    var abortBtn = root.querySelector('[data-longjob-abort]');
    var okBtn = root.querySelector('[data-longjob-ok]');
    var titleEl = root.querySelector('[data-longjob-title]');
    var logEl = root.querySelector('[data-longjob-log]');
    var detailBlock = root.querySelector('[data-longjob-detail-block]');

    if (!dualBars && detailBlock) detailBlock.hidden = true;
    if (!showBytes && bytesEl) bytesEl.hidden = true;

    if (abortBtn && cancelMode === 'hide') {
      abortBtn.textContent = i18n.hide || i18n.abort || abortBtn.textContent;
      if (i18n.hideHint) abortBtn.title = i18n.hideHint;
    }

    var activeJobId = null;
    var activeTickToken = '';
    var lastJob = null;
    var aborting = false;
    var finished = false;
    var timerIv = null;
    var startedAt = 0;
    var logLen = 0;
    var csrfToken = opts.csrf || '';
    var csrfField = opts.csrfField || 'csrf';
    var resumeBtn = null;

    function ensureResumeBtn() {
      if (resumeBtn || !okBtn || !okBtn.parentNode) return resumeBtn;
      resumeBtn = document.createElement('button');
      resumeBtn.type = 'button';
      resumeBtn.className = 'btn-primary';
      resumeBtn.hidden = true;
      resumeBtn.textContent = i18n.retry || 'Retry';
      okBtn.parentNode.insertBefore(resumeBtn, okBtn);
      resumeBtn.addEventListener('click', function () {
        if (!activeTickToken && !activeJobId) return;
        finished = false;
        aborting = false;
        setActionButtons(true);
        resumeBtn.hidden = true;
        if (titleEl) titleEl.textContent = opts.title || i18n.title || '';
        if (msgEl) msgEl.textContent = i18n.retrying || i18n.starting || '';
        tickLoop(activeJobId, activeTickToken, 0);
      });
      return resumeBtn;
    }

    function setBar(fill, bar, pctEl, pct) {
      pct = Math.max(0, Math.min(100, pct || 0));
      if (fill) fill.style.width = pct + '%';
      if (pctEl) pctEl.textContent = Math.round(pct) + '%';
      if (bar) bar.setAttribute('aria-valuenow', String(Math.round(pct)));
    }
    function updateClock() {
      if (!startedAt || finished) return;
      if (elapsedEl) elapsedEl.textContent = fmtDur(Math.max(0, Math.floor((Date.now() - startedAt) / 1000)));
    }
    function setActionButtons(running) {
      if (abortBtn) {
        abortBtn.hidden = !running;
        abortBtn.disabled = !running;
      }
      if (okBtn) {
        okBtn.hidden = running;
        okBtn.disabled = running;
      }
      if (resumeBtn) resumeBtn.hidden = true;
    }
    var logFingerprint = '';
    function clearLog() {
      logLen = 0;
      logFingerprint = '';
      if (logEl) logEl.innerHTML = '';
    }
    function appendLogLines(lines) {
      if (!logEl || !lines || !lines.length) return;
      for (var i = 0; i < lines.length; i++) {
        var line = document.createElement('div');
        line.className = 'db-job-log-line';
        line.textContent = String(lines[i]);
        logEl.appendChild(line);
      }
      logLen += lines.length;
      logEl.scrollTop = logEl.scrollHeight;
    }
    function syncLog(j) {
      if (!j || !j.log) return;
      // Phase checklists reuse the same length while labels change (… 22% → ✓).
      // Fingerprint replace; append-only when the list only grew.
      var fp = j.log.join('\n');
      if (fp === logFingerprint) return;
      if (j.log.length < logLen || (j.log.length === logLen && fp !== logFingerprint)) {
        clearLog();
        appendLogLines(j.log);
        logFingerprint = fp;
        return;
      }
      appendLogLines(j.log.slice(logLen));
      logFingerprint = fp;
    }
    function showJob(title) {
      if (overlay && overlay !== root) overlay.hidden = false;
      else if (root.hasAttribute('hidden')) root.hidden = false;
      document.body.classList.add('db-job-busy');
      finished = false;
      aborting = false;
      lastJob = null;
      activeTickToken = '';
      startedAt = Date.now();
      if (titleEl) titleEl.textContent = title || i18n.title || 'Working…';
      setActionButtons(true);
      if (msgEl) msgEl.textContent = i18n.starting || 'Starting…';
      clearLog();
      setBar(fillOverall, barOverall, pctOverall, 0);
      setBar(fillDetail, barDetail, pctDetail, 0);
      if (bytesEl) bytesEl.textContent = '0 MB';
      if (etaEl) etaEl.textContent = '';
      if (elapsedEl) elapsedEl.textContent = '0:00';
      if (timerIv) clearInterval(timerIv);
      timerIv = setInterval(updateClock, 250);
    }
    function finishJob(status, message, job) {
      finished = true;
      lastJob = job || lastJob;
      if (status !== 'paused') {
        activeJobId = null;
      }
      if (timerIv) { clearInterval(timerIv); timerIv = null; }
      if (startedAt && elapsedEl) {
        elapsedEl.textContent = fmtDur(Math.max(0, Math.floor((Date.now() - startedAt) / 1000)));
      }
      setActionButtons(false);
      if (msgEl && message) msgEl.textContent = message;
      if (titleEl) {
        if (status === 'done') titleEl.textContent = i18n.doneTitle || 'Done';
        else if (status === 'cancelled') titleEl.textContent = i18n.cancelledTitle || 'Cancelled';
        else titleEl.textContent = i18n.errorTitle || 'Error';
      }
      if ((status === 'paused' || status === 'error') && resumeOnPaused && activeTickToken) {
        ensureResumeBtn();
        if (resumeBtn) {
          resumeBtn.hidden = false;
          resumeBtn.focus();
        }
      }
      if (okBtn && lastJob && lastJob.relogin && status === 'done') {
        okBtn.textContent = i18n.login || i18n.ok || 'OK';
      } else if (okBtn) {
        okBtn.textContent = i18n.ok || okBtn.textContent || 'OK';
      }
      if (typeof opts.onFinish === 'function') opts.onFinish(status, message, lastJob);
    }
    function hideJob() {
      if (overlay && overlay !== root) overlay.hidden = true;
      else root.hidden = true;
      document.body.classList.remove('db-job-busy');
      if (timerIv) { clearInterval(timerIv); timerIv = null; }
      activeJobId = null;
      setActionButtons(true);
    }
    function applyJob(j) {
      if (!j) return;
      lastJob = j;
      if (typeof opts.onJob === 'function') {
        try { opts.onJob(j); } catch (ignore) { /* ignore */ }
      }
      if (msgEl) msgEl.textContent = j.message || '';
      syncLog(j);
      var overall = j.progress_overall != null ? j.progress_overall : (j.progress || 0);
      var detail = j.progress_detail != null ? j.progress_detail
        : (j.progress_table != null ? j.progress_table : 0);
      setBar(fillOverall, barOverall, pctOverall, overall);
      setBar(fillDetail, barDetail, pctDetail, detail);
      if (detailLabel && dualBars) {
        var base = i18n.barDetail || i18n.barTable || 'Current';
        detailLabel.textContent = j.table
          ? (base + ': ' + j.table + (j.tables_total ? (' (' + ((j.table_idx || 0) + 1) + '/' + j.tables_total + ')') : ''))
          : (j.phase ? (base + ': ' + j.phase) : base);
      } else if (detailLabel && j.phase) {
        detailLabel.textContent = j.phase;
      }
      if (bytesEl && showBytes) bytesEl.textContent = fmtBytes(j.bytes || 0);
      if (etaEl) {
        if (j.eta_seconds != null && j.status === 'running' && !finished) {
          etaEl.textContent = (i18n.eta || 'ETA {eta}').replace('{eta}', fmtDur(j.eta_seconds));
        } else {
          etaEl.textContent = '';
        }
      }
    }

    function scrubErrorText(text, httpStatus) {
      var raw = String(text || '');
      var plain = raw
        .replace(/<script[\s\S]*?<\/script>/gi, ' ')
        .replace(/<style[\s\S]*?<\/style>/gi, ' ')
        .replace(/<[^>]+>/g, ' ')
        .replace(/&nbsp;/gi, ' ')
        .replace(/&amp;/gi, '&')
        .replace(/&lt;/gi, '<')
        .replace(/&gt;/gi, '>')
        .replace(/&quot;/gi, '"')
        .replace(/\s+/g, ' ')
        .trim();
      if (!plain) {
        return (i18n.badJson || 'Bad JSON') + (httpStatus ? (' HTTP ' + httpStatus) : '');
      }
      // Proxy HTML pages → short hint; keep real JSON/API errors readable.
      if (/<!DOCTYPE|Internal Server Error|Gateway Time-?out|Bad Gateway/i.test(raw)) {
        return (i18n.badJson || 'Server timeout or error') + (httpStatus ? (' HTTP ' + httpStatus) : '');
      }
      if (plain.length > 160) plain = plain.slice(0, 160) + '…';
      return plain;
    }
    function postForm(url, formData) {
      return fetch(url, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(function (r) {
        return r.text().then(function (text) {
          var j = null;
          try {
            j = text ? JSON.parse(text) : null;
          } catch (parseErr) {
            var errParse = new Error(scrubErrorText(text, r.status));
            errParse.status = r.status;
            errParse.retryable = r.status >= 500 || r.status === 0;
            throw errParse;
          }
          if (!r.ok || (j && j.ok === false)) {
            var err = new Error(scrubErrorText((j && (j.error || j.message)) || '', r.status) || ('HTTP ' + r.status));
            err.status = r.status;
            err.retryable = !j || r.status >= 500 || r.status === 0;
            throw err;
          }
          return j || {};
        });
      });
    }

    function withCsrf(fd) {
      if (csrfToken && csrfField) fd.set(csrfField, csrfToken);
      fd.set('format', 'json');
      return fd;
    }

    function handleTerminal(j) {
      applyJob(j);
      if (j.status === 'done') {
        setBar(fillOverall, barOverall, pctOverall, 100);
        setBar(fillDetail, barDetail, pctDetail, 100);
        finishJob('done', j.message || '', j);
        if (typeof opts.onDone === 'function') opts.onDone(j);
        return true;
      }
      if (j.status === 'cancelled') {
        finishJob('cancelled', j.message || '', j);
        return true;
      }
      if (j.status === 'paused') {
        finishJob('paused', j.error || j.message || '', j);
        return true;
      }
      if (j.status === 'error') {
        finishJob('error', j.error || j.message || 'Job failed', j);
        return true;
      }
      return false;
    }

    function tickLoop(id, tickToken, failStreak) {
      if (finished || aborting) return Promise.resolve();
      var tfd = new FormData();
      if (tickToken && opts.tickTokenField) {
        tfd.set(opts.tickTokenField, tickToken);
        if (opts.tickWallField) {
          tfd.set(opts.tickWallField, String(opts.tickWall || 20));
        }
        // Public token endpoint — no CSRF required after wipe.
        tfd.set('format', 'json');
      } else {
        withCsrf(tfd);
        tfd.set(opts.tickActionField || 'action', opts.tickAction || 'job_tick');
        tfd.set(opts.jobIdField || 'job_id', id);
      }
      if (opts.tickExtra) {
        Object.keys(opts.tickExtra).forEach(function (k) { tfd.set(k, opts.tickExtra[k]); });
      }
      var tickUrl = (tickToken && opts.tickTokenUrl) ? opts.tickTokenUrl : (opts.tickUrl || opts.startUrl);
      return postForm(tickUrl, tfd).then(function (res) {
        failStreak = 0;
        if (finished || aborting) return;
        var j = res.job || res;
        if (j.tick_token) activeTickToken = j.tick_token;
        if (handleTerminal(j)) return;
        applyJob(j);
        return new Promise(function (resolve) {
          setTimeout(resolve, opts.tickPauseMs || 0);
        }).then(function () {
          return tickLoop(id, activeTickToken || tickToken, 0);
        });
      }).catch(function (err) {
        if (finished || aborting) return;
        failStreak++;
        if (failStreak <= maxFailStreak && (!err || err.retryable !== false)) {
          if (msgEl) {
            msgEl.textContent = (err && err.message ? err.message : String(err))
              + ' — ' + (i18n.retrying || 'retry') + ' ' + failStreak + '/' + maxFailStreak;
          }
          return new Promise(function (resolve) {
            setTimeout(resolve, Math.min(8000, 800 * failStreak));
          }).then(function () {
            return tickLoop(id, activeTickToken || tickToken, failStreak);
          });
        }
        finishJob('error', err && err.message ? err.message : String(err), lastJob);
      });
    }

    if (okBtn) {
      okBtn.addEventListener('click', function () {
        var job = lastJob;
        hideJob();
        if (typeof opts.onOk === 'function') opts.onOk(job);
        else if (job && job.relogin) window.location.href = opts.loginUrl || '/login';
        else if (reloadOnOk) window.location.reload();
      });
    }
    if (abortBtn) {
      abortBtn.addEventListener('click', function () {
        if (finished || aborting) return;
        if (cancelMode === 'hide') {
          aborting = true;
          finished = true;
          hideJob();
          if (i18n.hideHint) window.alert(i18n.hideHint);
          window.location.reload();
          return;
        }
        if (cancelMode === 'stop') {
          aborting = true;
          finishJob('cancelled', i18n.cancelledTitle || 'Stopped', lastJob);
          return;
        }
        if (opts.abortConfirm && !window.confirm(opts.abortConfirm)) return;
        if (!activeJobId) return;
        aborting = true;
        abortBtn.disabled = true;
        var tfd = withCsrf(new FormData());
        tfd.set(opts.cancelActionField || 'action', opts.cancelAction || 'job_cancel');
        tfd.set(opts.jobIdField || 'job_id', activeJobId);
        if (opts.cancelExtra) {
          Object.keys(opts.cancelExtra).forEach(function (k) { tfd.set(k, opts.cancelExtra[k]); });
        }
        postForm(opts.cancelUrl || opts.tickUrl || opts.startUrl, tfd).then(function (res) {
          applyJob(res.job || {});
          finishJob('cancelled', (res.job && res.job.message) || '', res.job || {});
        }).catch(function (err) {
          finishJob('error', err && err.message ? err.message : String(err), lastJob);
        });
      });
    }

    function runFromFormData(fd, startExtras) {
      withCsrf(fd);
      fd.set(opts.startActionField || 'action', opts.startAction || 'job_start');
      var showTitle = opts.title;
      if (startExtras) {
        if (startExtras.title) showTitle = startExtras.title;
        Object.keys(startExtras).forEach(function (k) {
          if (k === 'title') return; // UI-only, not posted
          fd.set(k, startExtras[k]);
        });
      }
      showJob(showTitle);
      return postForm(opts.startUrl, fd).then(function (start) {
        var job = start.job || start;
        var id = job.id;
        if (!id) throw new Error('No job id');
        activeJobId = id;
        if (job.tick_token) activeTickToken = job.tick_token;
        applyJob(job);
        if (handleTerminal(job)) return;
        return tickLoop(id, activeTickToken, 0);
      }).catch(function (err) {
        finishJob('error', err && err.message ? err.message : String(err), lastJob);
      });
    }

    return {
      show: showJob,
      hide: hideJob,
      apply: applyJob,
      runFormData: runFromFormData,
      runForm: function (form, startExtras) {
        return runFromFormData(new FormData(form), startExtras);
      }
    };
  }

  global.LongJobPanel = {
    mount: createPanel,
    bindForms: function (opts) {
      var panel = createPanel(opts);
      if (!panel) return null;
      var sel = opts.formSelector || 'form[data-longjob]';
      document.querySelectorAll(sel).forEach(function (form) {
        form.addEventListener('submit', function (ev) {
          var extras = {};
          var op = form.getAttribute('data-longjob-op') || form.getAttribute('data-db-job');
          if (op) extras.op = op;
          var submitter = ev.submitter || document.activeElement;
          if (submitter && submitter.getAttribute) {
            var dop = submitter.getAttribute('data-db-op') || submitter.getAttribute('data-longjob-op');
            if (dop) extras.op = dop;
          }
          if (typeof opts.mapFormExtras === 'function') {
            var mapped = opts.mapFormExtras(form, ev);
            if (mapped === null) return; // not a long-job submit
            extras = Object.assign(extras, mapped || {});
          }
          var conf = form.getAttribute('data-longjob-confirm') || form.getAttribute('data-db-confirm');
          if (conf && !window.confirm(conf)) {
            ev.preventDefault();
            return;
          }
          ev.preventDefault();
          ev.stopPropagation();
          panel.runForm(form, extras);
        }, true);
      });
      return panel;
    }
  };
})(window);
