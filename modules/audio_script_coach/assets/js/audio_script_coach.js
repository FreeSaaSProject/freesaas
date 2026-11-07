/* Script coach — LongJobPanel phase ticks (one CoachRunner phase per tick). */
(function () {
  'use strict';

  var root = document.querySelector('[data-asc-run]');
  if (!root) return;

  var runId = parseInt(root.getAttribute('data-asc-run') || '0', 10);
  if (runId < 1) return;

  var csrf = typeof window.freesaasCsrfToken === 'function' ? window.freesaasCsrfToken() : '';

  function boot() {
    var overlay = document.getElementById('asc-longjob-overlay');
    if (!overlay) return;
    if (!window.LongJobPanel) {
      setTimeout(boot, 30);
      return;
    }

    var panel = LongJobPanel.mount({
      root: overlay,
      startUrl: '/audio/script-coach/work',
      tickUrl: '/audio/script-coach/work',
      startAction: 'tick',
      tickAction: 'tick',
      dualBars: false,
      showBytes: false,
      cancelMode: 'stop',
      maxFailStreak: 4,
      tickPauseMs: 80,
      csrf: csrf,
      reloadOnOk: false,
      resumeOnPaused: true,
      // Keep run_id on every tick (panel also sends job_id = run id).
      tickExtra: { run_id: String(runId) },
      title: root.getAttribute('data-asc-title') || '',
      i18n: {
        title: root.getAttribute('data-asc-title') || '',
        starting: root.getAttribute('data-asc-msg-working') || '',
        badJson: root.getAttribute('data-asc-msg-badjson') || 'Bad JSON',
        doneTitle: root.getAttribute('data-asc-msg-done') || '',
        errorTitle: root.getAttribute('data-asc-msg-error') || 'Error',
        cancelledTitle: root.getAttribute('data-asc-msg-aborted') || '',
        ok: root.getAttribute('data-asc-msg-ok') || 'OK',
        hide: root.getAttribute('data-asc-msg-aborted') || 'Stop',
        retry: root.getAttribute('data-asc-msg-retry') || 'Retry',
        retrying: root.getAttribute('data-asc-msg-working') || ''
      },
      onOk: function () {
        window.location.href = '/audio/script-coach/run/' + runId;
      }
    });

    function drain() {
      var fd = new FormData();
      fd.set('run_id', String(runId));
      panel.runFormData(fd, { run_id: String(runId) });
    }

    var drainBtn = root.querySelector('[data-asc-drain]');
    if (drainBtn) {
      drainBtn.addEventListener('click', function (e) {
        e.preventDefault();
        drain();
      });
    }
    if (root.getAttribute('data-asc-auto') === '1') {
      drain();
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
