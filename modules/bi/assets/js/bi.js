(function () {
  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  function isTextEntry(el) {
    if (!el || !el.tagName) return false;
    var tag = el.tagName.toLowerCase();
    if (tag === 'textarea') return true;
    if (tag === 'input') {
      var type = (el.type || 'text').toLowerCase();
      return type === 'text' || type === 'search' || type === 'email' || type === 'number' || type === 'password' || type === 'url' || type === 'tel';
    }
    if (el.isContentEditable) return true;
    return !!(el.closest && el.closest('[contenteditable="true"]'));
  }

  function setSelectValue(sel, value) {
    if (!sel) return;
    var v = String(value);
    var found = false;
    Array.prototype.forEach.call(sel.options, function (opt) {
      if (opt.value === v) found = true;
    });
    if (!found) {
      var opt = document.createElement('option');
      opt.value = v;
      opt.textContent = v;
      sel.appendChild(opt);
    }
    sel.value = v;
  }

  function shiftMonth(form, dir) {
    var yearEl = qs('select[name="year"]', form);
    var monthEl = qs('select[name="month"]', form);
    if (!yearEl || !monthEl) return;
    var y = parseInt(yearEl.value, 10) || (new Date()).getFullYear();
    var m = parseInt(monthEl.value, 10) || ((new Date()).getMonth() + 1);
    m += dir;
    if (m < 1) {
      m = 12;
      y -= 1;
    } else if (m > 12) {
      m = 1;
      y += 1;
    }
    setSelectValue(yearEl, y);
    setSelectValue(monthEl, m);
    syncNavAttrs(form);
  }

  function syncNavAttrs(form) {
    var yearEl = qs('select[name="year"]', form);
    var monthEl = qs('select[name="month"]', form);
    if (!yearEl || !monthEl) return;
    var y = parseInt(yearEl.value, 10);
    var m = parseInt(monthEl.value, 10);
    var prevM = m - 1;
    var prevY = y;
    if (prevM < 1) {
      prevM = 12;
      prevY -= 1;
    }
    var nextM = m + 1;
    var nextY = y;
    if (nextM > 12) {
      nextM = 1;
      nextY += 1;
    }
    form.setAttribute('data-prev-year', String(prevY));
    form.setAttribute('data-prev-month', String(prevM));
    form.setAttribute('data-next-year', String(nextY));
    form.setAttribute('data-next-month', String(nextM));
  }

  function appendLog(msg) {
    var log = qs('#bi-progress-log');
    var footer = qs('#bi-build-footer');
    if (footer) footer.hidden = false;
    if (!log) return;
    var line = document.createElement('div');
    line.className = 'bi-log-line';
    line.textContent = msg;
    log.appendChild(line);
    log.scrollTop = log.scrollHeight;
    var status = qs('#bi-progress-status');
    if (status) status.textContent = msg;
  }

  window.biProgressFromFrame = function (msg) {
    // Ignore iframe color arg — parent log uses theme CSS.
    appendLog(msg);
  };

  window.biWorkDone = function (data) {
    var strip = qs('#bi-progress-strip');
    var report = qs('#bi-report');
    if (strip) {
      strip.classList.remove('is-running');
      strip.hidden = true;
    }
    if (report) {
      if (data && data.ok && data.html) {
        report.innerHTML = data.html;
      } else {
        report.innerHTML = '<p class="flash-err">' + (data && data.error ? data.error : 'error') + '</p>';
      }
    }
  };

  function buildWorkUrl(cfg, form) {
    var fd = new FormData(form);
    var params = new URLSearchParams();
    params.set('action', cfg.action || 'run');
    params.set('stream', '1');
    if (cfg.family) params.set('family', cfg.family);
    fd.forEach(function (v, k) {
      if (k === 'family' && cfg.family) return;
      if (v === '' || v === null) return;
      if ((k === 'id_operator' || k === 'id_admin' || k === 'id_good') && String(v) === '0') return;
      params.set(k, String(v));
    });
    var base = cfg.workUrl || '/bi/work';
    return base + (base.indexOf('?') >= 0 ? '&' : '?') + params.toString();
  }

  var runTimer = null;

  function startRun(cfg, form, immediate) {
    var doStart = function () {
      var strip = qs('#bi-progress-strip');
      var log = qs('#bi-progress-log');
      var footer = qs('#bi-build-footer');
      var frame = qs('#bi-work-frame');
      var report = qs('#bi-report');
      if (!frame) return;
      if (log) log.innerHTML = '';
      if (footer) footer.hidden = false;
      if (report) report.innerHTML = '';
      if (strip) {
        strip.hidden = false;
        strip.classList.add('is-running');
      }
      var status = qs('#bi-progress-status');
      if (status) status.textContent = status.getAttribute('data-running') || 'Running…';
      frame.src = buildWorkUrl(cfg, form);
    };
    if (runTimer) clearTimeout(runTimer);
    if (immediate) {
      doStart();
    } else {
      runTimer = setTimeout(doStart, 180);
    }
  }

  function bindRun() {
    var cfg = window.biWorkConfig;
    if (!cfg) return;
    var form = qs('#bi-run-form');
    if (!form) return;

    var stripStatus = qs('#bi-progress-status');
    if (stripStatus) {
      stripStatus.setAttribute('data-running', stripStatus.textContent || 'Running…');
    }

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      startRun(cfg, form, true);
    });

    form.querySelectorAll('[data-bi-auto]').forEach(function (el) {
      el.addEventListener('change', function () {
        syncNavAttrs(form);
        startRun(cfg, form, false);
      });
    });

    form.querySelectorAll('[data-bi-nav-dir]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var dir = parseInt(btn.getAttribute('data-bi-nav-dir') || '0', 10);
        if (!dir) return;
        shiftMonth(form, dir);
        startRun(cfg, form, true);
      });
    });

    // Capture so month <select> focus does not eat ←/→.
    document.addEventListener('keydown', function (e) {
      if (e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) return;
      if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
      if (isTextEntry(e.target)) return;
      e.preventDefault();
      shiftMonth(form, e.key === 'ArrowLeft' ? -1 : 1);
      startRun(cfg, form, true);
    }, true);

    if (cfg.autoRun) {
      startRun(cfg, form, true);
    }
  }

  function bindSharesAudience() {
    var audience = qs('#bi-share-audience');
    var staffBox = qs('#bi-share-staff-box');
    var ttl = qs('#bi-share-ttl');
    if (!audience) return;
    var sync = function () {
      var isStaff = audience.value === 'staff';
      if (staffBox) staffBox.hidden = !isStaff;
      if (ttl) {
        Array.prototype.forEach.call(ttl.options, function (opt) {
          if (opt.getAttribute('data-bi-ttl-staff-only') === '1') {
            opt.hidden = !isStaff;
            opt.disabled = !isStaff;
          }
        });
        var cur = ttl.options[ttl.selectedIndex];
        if (cur && cur.disabled) {
          ttl.value = '1d';
        }
      }
    };
    audience.addEventListener('change', sync);
    sync();
  }

  function bindCopy() {
    document.querySelectorAll('[data-bi-copy]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var sel = btn.getAttribute('data-bi-copy');
        var el = sel ? qs(sel) : null;
        var text = el ? (el.textContent || '') : '';
        if (!text) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text);
        }
      });
    });
  }

  function bind() {
    bindRun();
    bindSharesAudience();
    bindCopy();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
