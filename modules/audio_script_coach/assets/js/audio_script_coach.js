/* Script coach — batched phase ticks with progress overlay (DB-job style). */
(function () {
  'use strict';

  var root = document.querySelector('[data-asc-run]');
  if (!root) {
    return;
  }

  var runId = parseInt(root.getAttribute('data-asc-run') || '0', 10);
  if (runId < 1) {
    return;
  }

  var overlay = document.getElementById('asc-job-overlay');
  var msgEl = document.getElementById('asc-job-msg');
  var pctEl = document.getElementById('asc-job-pct');
  var fillEl = document.getElementById('asc-job-fill');
  var logEl = document.getElementById('asc-job-log');
  var okBtn = document.getElementById('asc-job-ok');
  var abortBtn = document.getElementById('asc-job-abort');
  var retryBtn = document.getElementById('asc-job-retry');
  var busy = false;
  var aborted = false;

  function csrf() {
    return typeof window.freesaasCsrfToken === 'function' ? window.freesaasCsrfToken() : '';
  }

  function setProgress(done, total) {
    total = Math.max(1, total || 5);
    done = Math.max(0, Math.min(done || 0, total));
    var pct = Math.round((100 * done) / total);
    if (pctEl) {
      pctEl.textContent = pct + '%';
    }
    if (fillEl) {
      fillEl.style.width = pct + '%';
    }
    var bar = fillEl && fillEl.parentElement;
    if (bar) {
      bar.setAttribute('aria-valuenow', String(pct));
    }
  }

  function logLine(text, isErr, color) {
    if (!logEl) {
      return;
    }
    var line = document.createElement('div');
    line.className = isErr ? 'asc-job-log-err' : '';
    if (color && !isErr) {
      line.style.color = color;
    }
    line.textContent = text;
    logEl.appendChild(line);
    logEl.scrollTop = logEl.scrollHeight;
  }

  function logStages(stages) {
    if (!Array.isArray(stages) || !stages.length) {
      return;
    }
    stages.forEach(function (s) {
      if (!s || !s.msg) {
        return;
      }
      var isErr = (s.color || '') === '#fca5a5';
      var prefix = typeof s.at === 'number' ? ('[' + s.at.toFixed(1) + 's] ') : '';
      logLine(prefix + s.msg, isErr, s.color || '');
    });
  }

  function showOverlay() {
    if (!overlay) {
      return;
    }
    overlay.hidden = false;
    aborted = false;
    if (okBtn) {
      okBtn.hidden = true;
    }
    if (retryBtn) {
      retryBtn.hidden = true;
    }
    if (abortBtn) {
      abortBtn.hidden = false;
    }
  }

  function finishOverlay(ok, finalMsg, canRetry) {
    busy = false;
    if (msgEl && finalMsg) {
      msgEl.textContent = finalMsg;
    }
    if (abortBtn) {
      abortBtn.hidden = true;
    }
    if (okBtn) {
      okBtn.hidden = false;
      okBtn.onclick = function () {
        window.location.reload();
      };
    }
    if (retryBtn) {
      retryBtn.hidden = !canRetry;
      if (canRetry) {
        retryBtn.onclick = function () {
          if (busy) {
            return;
          }
          if (retryBtn) {
            retryBtn.hidden = true;
          }
          if (okBtn) {
            okBtn.hidden = true;
          }
          continueDrain();
        };
      }
    }
    if (ok) {
      window.setTimeout(function () {
        window.location.href = '/audio/script-coach/run/' + runId;
      }, 400);
    }
  }

  function parseTickResponse(r) {
    return r.text().then(function (text) {
      var data = null;
      var trim = (text || '').replace(/^\uFEFF/, '').trim();
      if (trim !== '') {
        try {
          data = JSON.parse(trim);
        } catch (e) {
          var snippet = trim.replace(/\s+/g, ' ').slice(0, 180);
          var err = new Error(
            'HTTP ' + r.status + (snippet ? ': ' + snippet : ' (empty/non-JSON)')
          );
          err.httpStatus = r.status;
          err.bodySnippet = snippet;
          throw err;
        }
      }
      if (!r.ok) {
        var msg =
          (data && (data.error || data.message)) ||
          ('HTTP ' + r.status);
        var err2 = new Error(String(msg));
        err2.httpStatus = r.status;
        err2.data = data;
        throw err2;
      }
      if (!data || typeof data !== 'object') {
        throw new Error('HTTP ' + r.status + ' (empty JSON)');
      }
      return data;
    });
  }

  function tickOnce() {
    var body = new FormData();
    body.append('action', 'tick');
    body.append('run_id', String(runId));
    body.append('csrf', csrf());
    return fetch('/audio/script-coach/work?format=json', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() },
      body: body,
    }).then(parseTickResponse);
  }

  function continueDrain() {
    if (busy || runId < 1) {
      return;
    }
    busy = true;
    showOverlay();
    if (msgEl) {
      msgEl.textContent = root.getAttribute('data-asc-msg-working') || '…';
    }
    var total = parseInt(root.getAttribute('data-asc-phases-total') || '5', 10) || 5;
    setProgress(parseInt(root.getAttribute('data-asc-phases-done') || '0', 10) || 0, total);

    function step() {
      if (aborted) {
        finishOverlay(false, root.getAttribute('data-asc-msg-aborted') || 'Aborted', true);
        return;
      }
      tickOnce()
        .then(function (data) {
          if (!data || data.ok === false) {
            logLine((data && data.error) || 'error', true);
            finishOverlay(false, (data && data.error) || 'error', true);
            return;
          }
          var done = parseInt(data.phases_done || 0, 10) || 0;
          total = parseInt(data.phases_total || total, 10) || total;
          setProgress(done, total);
          root.setAttribute('data-asc-phases-done', String(done));
          root.setAttribute('data-asc-phases-total', String(total));
          if (Array.isArray(data.stages) && data.stages.length) {
            logStages(data.stages);
          } else {
            logLine('✓ ' + (data.phase || '—') + ' (' + done + '/' + total + ')');
          }
          if (msgEl && data.phase) {
            msgEl.textContent = (data.phase || '') + ' · ' + done + '/' + total;
          }
          if (data.done) {
            setProgress(total, total);
            finishOverlay(true, root.getAttribute('data-asc-msg-done') || 'Done', false);
            return;
          }
          step();
        })
        .catch(function (err) {
          var msg = String(err && err.message ? err.message : err);
          logLine(msg, true);
          finishOverlay(false, msg, true);
        });
    }

    step();
  }

  function drain() {
    if (logEl) {
      logEl.innerHTML = '';
    }
    continueDrain();
  }

  var drainBtn = root.querySelector('[data-asc-drain]');
  if (drainBtn) {
    drainBtn.addEventListener('click', function (e) {
      e.preventDefault();
      drain();
    });
  }

  if (abortBtn) {
    abortBtn.addEventListener('click', function () {
      aborted = true;
    });
  }

  if (root.getAttribute('data-asc-auto') === '1') {
    drain();
  }

  // Optional iframe stream callbacks (work?stream=1).
  window.ascProgressFromFrame = function (msg) {
    showOverlay();
    logLine(msg);
  };
  window.ascWorkDone = function (data) {
    if (data && data.run_id) {
      window.location.href = '/audio/script-coach/run/' + data.run_id;
      return;
    }
    window.location.reload();
  };
})();
