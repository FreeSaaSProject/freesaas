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
  var busy = false;
  var aborted = false;

  function csrf() {
    return typeof window.freesaasCsrfToken === 'function' ? window.freesaasCsrfToken() : '';
  }

  function setProgress(done, total) {
    total = Math.max(1, total || 4);
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
    if (abortBtn) {
      abortBtn.hidden = false;
    }
    if (logEl) {
      logEl.innerHTML = '';
    }
  }

  function finishOverlay(ok, finalMsg) {
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
    if (ok) {
      window.setTimeout(function () {
        window.location.href = '/audio/script-coach/run/' + runId;
      }, 400);
    }
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
    }).then(function (r) {
      return r.json();
    });
  }

  function drain() {
    if (busy || runId < 1) {
      return;
    }
    busy = true;
    showOverlay();
    if (msgEl) {
      msgEl.textContent = root.getAttribute('data-asc-msg-working') || '…';
    }
    var total = parseInt(root.getAttribute('data-asc-phases-total') || '4', 10) || 4;
    setProgress(parseInt(root.getAttribute('data-asc-phases-done') || '0', 10) || 0, total);

    function step() {
      if (aborted) {
        finishOverlay(false, root.getAttribute('data-asc-msg-aborted') || 'Aborted');
        return;
      }
      tickOnce()
        .then(function (data) {
          if (!data || data.ok === false) {
            logLine((data && data.error) || 'error', true);
            finishOverlay(false, (data && data.error) || 'error');
            return;
          }
          var done = parseInt(data.phases_done || 0, 10) || 0;
          total = parseInt(data.phases_total || total, 10) || total;
          setProgress(done, total);
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
            finishOverlay(true, root.getAttribute('data-asc-msg-done') || 'Done');
            return;
          }
          step();
        })
        .catch(function (err) {
          logLine(String(err && err.message ? err.message : err), true);
          finishOverlay(false, String(err && err.message ? err.message : err));
        });
    }

    step();
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
