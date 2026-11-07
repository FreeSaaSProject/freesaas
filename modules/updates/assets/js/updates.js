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
  var canApply = root.getAttribute('data-can-apply') === '1';
  var csrf = '';
  try {
    csrf = (window.freesaasCsrfToken && window.freesaasCsrfToken()) || '';
    if (!csrf) {
      var inp = document.querySelector('input[name="csrf"]');
      csrf = inp ? String(inp.value || '') : '';
    }
  } catch (ignore) { /* ignore */ }

  var foundWrap = document.getElementById('upd-job-found');
  var foundTitle = document.getElementById('upd-job-found-title');
  var foundList = document.getElementById('upd-job-found-list');
  var applyFoundBtn = document.getElementById('upd-job-apply-found');
  var activeKind = 'apply';
  var lastFoundCount = 0;
  var panel = null;

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
    var visible = !!(show && canApply && lastFoundCount > 0 && activeKind === 'check');
    applyFoundBtn.hidden = !visible;
    applyFoundBtn.disabled = !visible;
  }

  function enrichJob(j) {
    if (!j) return j;
    j.progress_overall = j.progress_overall != null ? j.progress_overall : (j.progress || 0);
    if (activeKind !== 'check' && (j.target_label || j.target)) {
      var label = j.target_label || j.target;
      var prefix = label + (j.targets_total ? (' (' + ((j.target_index || 0) + 1) + '/' + j.targets_total + ')') : '') + ': ';
      if (j.message && j.message.indexOf(prefix) !== 0) {
        j.message = prefix + (j.message || '');
      }
    }
    return j;
  }

  function boot() {
    var overlay = document.getElementById('upd-longjob-overlay');
    if (!overlay) return;
    if (!window.LongJobPanel) {
      setTimeout(boot, 30);
      return;
    }

    // Mount found UI inside LongJob panel actions area.
    var panelBox = overlay.querySelector('.db-job-panel');
    if (panelBox && foundWrap && foundWrap.parentNode !== panelBox) {
      var actions = panelBox.querySelector('.db-job-actions');
      if (actions) panelBox.insertBefore(foundWrap, actions);
      else panelBox.appendChild(foundWrap);
      if (applyFoundBtn && actions) actions.insertBefore(applyFoundBtn, actions.firstChild);
    }

    function makePanel(kind) {
      activeKind = kind;
      lastFoundCount = 0;
      renderFound([]);
      setApplyFoundVisible(false);
      return LongJobPanel.mount({
        root: overlay,
        startUrl: '/updates',
        tickUrl: '/updates',
        cancelUrl: '/updates',
        startAction: kind === 'check' ? 'update_check_job_start' : 'update_job_start',
        tickAction: kind === 'check' ? 'update_check_job_tick' : 'update_job_tick',
        cancelAction: 'update_job_cancel',
        dualBars: false,
        showBytes: false,
        tickPauseMs: kind === 'check' ? 50 : 200,
        csrf: csrf,
        title: kind === 'check' ? (i18n.checkTitle || i18n.title) : (i18n.title || ''),
        i18n: {
          title: kind === 'check' ? (i18n.checkTitle || '') : (i18n.title || ''),
          starting: i18n.starting || '',
          badJson: i18n.badJson || '',
          doneTitle: kind === 'check' ? (i18n.checkDoneTitle || i18n.doneTitle) : (i18n.doneTitle || ''),
          errorTitle: i18n.errorTitle || '',
          cancelledTitle: i18n.cancelledTitle || '',
          ok: i18n.ok || 'OK',
          abort: i18n.abort || ''
        },
        onJob: function (j) {
          enrichJob(j);
          if (activeKind === 'check') renderFound(j.found || []);
        },
        onFinish: function (status) {
          setApplyFoundVisible(status === 'done' && activeKind === 'check');
        }
      });
    }

    function runApply(target) {
      panel = makePanel('apply');
      var fd = new FormData();
      fd.set('target', target || 'all');
      panel.runFormData(fd, {});
    }

    function runCheck(target) {
      panel = makePanel('check');
      var fd = new FormData();
      if (target) fd.set('target', target);
      panel.runFormData(fd, {});
    }

    if (applyFoundBtn) {
      applyFoundBtn.addEventListener('click', function () {
        if (!canApply || lastFoundCount < 1) return;
        var confirmMsg = i18n.confirmApply || '';
        if (confirmMsg && !window.confirm(confirmMsg)) return;
        setApplyFoundVisible(false);
        runApply('all');
      });
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
        var target = btn.getAttribute('data-upd-check') || 'all';
        runCheck(target === 'all' ? '' : target);
      });
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
