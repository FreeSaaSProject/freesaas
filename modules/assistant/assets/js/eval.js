(function () {
  'use strict';

  var root = document.getElementById('assistant-eval');
  if (!root) {
    return;
  }

  var url = root.getAttribute('data-eval-url') || '/assistant/eval';
  var cases = [];
  try {
    cases = JSON.parse(root.getAttribute('data-cases') || '[]') || [];
  } catch (e) {
    cases = [];
  }
  var i18n = {};
  try {
    i18n = JSON.parse(root.getAttribute('data-i18n') || '{}') || {};
  } catch (e2) {
    i18n = {};
  }
  var lastRun = null;
  try {
    lastRun = JSON.parse(root.getAttribute('data-last-run') || 'null');
  } catch (e3) {
    lastRun = null;
  }
  var profileId = root.getAttribute('data-profile') || '';

  var form = document.getElementById('assistant-eval-form');
  var liveEl = document.getElementById('assistant-eval-live');
  var runBtn = document.getElementById('assistant-eval-run');
  var retryFailsBtn = document.getElementById('assistant-eval-retry-fails');
  var abortBtn = document.getElementById('assistant-eval-abort');
  var panel = document.getElementById('assistant-eval-panel');
  var lastWrap = document.getElementById('assistant-eval-last');
  var lastMetaEl = document.getElementById('assistant-eval-last-meta');
  var msgEl = document.getElementById('assistant-eval-msg');
  var fillEl = document.getElementById('assistant-eval-fill');
  var barEl = document.getElementById('assistant-eval-bar');
  var pctEl = document.getElementById('assistant-eval-pct');
  var summaryEl = document.getElementById('assistant-eval-summary');
  var totalsEl = document.getElementById('assistant-eval-totals');
  var logEl = document.getElementById('assistant-eval-log');
  var systemEl = document.getElementById('assistant-eval-system');

  var aborting = false;
  var running = false;
  var t0 = 0;
  /** @type {{profile:string,live:boolean,rows:Array,summary:Object,ms:number,system_prompt:string,finished:boolean,finished_at:?string}} */
  var currentRun = emptyRun();

  function emptyRun() {
    return {
      profile: profileId || '',
      live: true,
      rows: [],
      summary: { pass: 0, fail: 0, skip: 0, ms: 0 },
      ms: 0,
      system_prompt: '',
      finished: false,
      finished_at: null,
    };
  }

  function caseMeta(id) {
    for (var i = 0; i < cases.length; i++) {
      if (cases[i] && String(cases[i].id) === String(id)) {
        return cases[i];
      }
    }
    return null;
  }

  function csrfToken() {
    if (window.freesaasCsrfToken) {
      var t = window.freesaasCsrfToken();
      if (t) {
        return t;
      }
    }
    var m = document.querySelector('meta[name="csrf-token"]');
    if (m && m.getAttribute('content')) {
      return m.getAttribute('content');
    }
    var inp = form ? form.querySelector('input[name="csrf"]') : null;
    return inp ? String(inp.value || '') : '';
  }

  function applyCsrf(tok) {
    tok = String(tok || '').trim();
    if (!tok) {
      return;
    }
    var m = document.querySelector('meta[name="csrf-token"]');
    if (m) {
      m.setAttribute('content', tok);
    }
    var nodes = document.querySelectorAll('input[name="csrf"]');
    for (var i = 0; i < nodes.length; i++) {
      nodes[i].value = tok;
    }
  }

  function setProgress(done, total) {
    var pct = total > 0 ? Math.round((done / total) * 100) : 0;
    if (fillEl) {
      fillEl.style.width = pct + '%';
    }
    if (barEl) {
      barEl.setAttribute('aria-valuenow', String(pct));
    }
    if (pctEl) {
      pctEl.textContent = pct + '%';
    }
  }

  function recount(rows) {
    var pass = 0;
    var clean = 0;
    var repaired = 0;
    var fail = 0;
    var skip = 0;
    var ms = 0;
    for (var i = 0; i < rows.length; i++) {
      var row = rows[i] || {};
      var st = String(row.status || 'skip');
      if (st === 'pass') {
        pass++;
        if (row.repaired) {
          repaired++;
        } else {
          clean++;
        }
      } else if (st === 'fail') {
        fail++;
      } else {
        skip++;
      }
      ms += row.ms != null ? Number(row.ms) || 0 : 0;
    }
    return {
      pass: pass,
      clean: clean,
      repaired: repaired,
      fail: fail,
      skip: skip,
      ms: ms,
      tests: rows.length,
    };
  }

  function setSummary(pass, fail, skip, msOverride) {
    if (!summaryEl) {
      return;
    }
    var tpl = i18n.summary || 'pass {pass}, fail {fail}, skip {skip} · {ms} ms';
    var ms =
      msOverride != null
        ? msOverride
        : t0
          ? Date.now() - t0
          : currentRun.ms || 0;
    summaryEl.textContent = tpl
      .replace('{pass}', String(pass))
      .replace('{fail}', String(fail))
      .replace('{skip}', String(skip))
      .replace('{ms}', String(ms));
  }

  function setTotals(stats) {
    if (!totalsEl) {
      return;
    }
    stats = stats || recount(currentRun.rows || []);
    if (!stats.tests) {
      totalsEl.hidden = true;
      totalsEl.textContent = '';
      return;
    }
    var tpl =
      i18n.totals ||
      'tests: {tests} · pass-clean: {clean} · pass-repaired: {repaired} · failed: {failed} · skip: {skip}';
    totalsEl.textContent = tpl
      .replace('{tests}', String(stats.tests))
      .replace('{clean}', String(stats.clean))
      .replace('{repaired}', String(stats.repaired))
      .replace('{failed}', String(stats.fail))
      .replace('{skip}', String(stats.skip));
    totalsEl.hidden = false;
  }

  function setSystemPrompt(text) {
    if (!systemEl || text == null || text === '') {
      return;
    }
    systemEl.textContent = String(text);
    currentRun.system_prompt = String(text);
  }

  function statusLabel(st, repaired) {
    if (st === 'pass' && repaired) {
      return i18n.repaired || 'repaired';
    }
    if (st === 'pass') return i18n.pass || 'pass';
    if (st === 'fail') return i18n.fail || 'fail';
    return i18n.skip || 'skip';
  }

  function friendlyError(raw, status) {
    var msg = String(raw || '').trim();
    var low = msg.toLowerCase();
    if (low === 'csrf' || low === 'cross_site' || low.indexOf('csrf') !== -1) {
      return i18n.err_csrf || msg;
    }
    if (
      status === 500 ||
      low.indexOf('internal server error') !== -1 ||
      low.indexOf('<!doctype') !== -1 ||
      low.indexOf('<html') !== -1
    ) {
      return i18n.err_500 || msg;
    }
    if (status && status >= 400) {
      var tpl = i18n.err_http || 'HTTP {status}';
      return tpl.replace('{status}', String(status)) + (msg ? ': ' + msg.slice(0, 160) : '');
    }
    return msg || i18n.err || 'Request failed';
  }

  function updateRetryFailsVisibility() {
    if (!retryFailsBtn) {
      return;
    }
    var n = recount(currentRun.rows || []).fail;
    retryFailsBtn.hidden = running || n < 1;
  }

  function updateLastBanner() {
    if (lastWrap) {
      lastWrap.hidden = !(currentRun.rows && currentRun.rows.length);
    }
    if (lastMetaEl) {
      var tpl = i18n.last_meta || 'profile {profile} · {when}';
      lastMetaEl.textContent = tpl
        .replace('{profile}', currentRun.profile || profileId || '—')
        .replace('{when}', currentRun.finished_at || currentRun.saved_at || '');
    }
    updateRetryFailsVisibility();
  }

  function upsertLocalRow(row) {
    if (!row || !row.id) {
      return;
    }
    var id = String(row.id);
    var rows = currentRun.rows || [];
    var found = false;
    for (var i = 0; i < rows.length; i++) {
      if (rows[i] && String(rows[i].id) === id) {
        rows[i] = row;
        found = true;
        break;
      }
    }
    if (!found) {
      rows.push(row);
    }
    currentRun.rows = rows;
    var s = recount(rows);
    currentRun.summary = s;
    currentRun.ms = s.ms;
  }

  function persist(finished) {
    currentRun.finished = !!finished;
    if (finished) {
      currentRun.finished_at = new Date().toISOString();
    }
    currentRun.live = !!(liveEl && liveEl.checked);
    if (!currentRun.profile) {
      currentRun.profile = profileId || '';
    }
    var s = recount(currentRun.rows || []);
    currentRun.summary = s;
    if (finished || !currentRun.ms) {
      currentRun.ms = t0 ? Date.now() - t0 : s.ms;
    }
    return postJson({
      action: 'save',
      profile: currentRun.profile,
      run: currentRun,
    })
      .then(function (res) {
        if (res && res.run && res.run.profile) {
          currentRun.profile = String(res.run.profile);
          profileId = currentRun.profile;
          root.setAttribute('data-profile', profileId);
        }
        if (res && res.run && res.run.saved_at) {
          currentRun.saved_at = res.run.saved_at;
        }
        updateLastBanner();
        return res;
      })
      .catch(function () {
        /* persistence is best-effort */
        return null;
      });
  }

  function findCard(id) {
    if (!logEl) {
      return null;
    }
    var cards = logEl.querySelectorAll('.assistant-eval__card');
    for (var i = 0; i < cards.length; i++) {
      if (cards[i].getAttribute('data-case-id') === String(id)) {
        return cards[i];
      }
    }
    return null;
  }

  function appendRow(row, replaceExisting) {
    if (!logEl || !row) {
      return;
    }
    var st = String(row.status || 'skip');
    var repaired = !!row.repaired && st === 'pass';
    var layer = String(row.layer || '');
    var isLive = layer === 'live';
    var id = String(row.id || '');

    if (replaceExisting && id) {
      var old = findCard(id);
      if (old && old.parentNode) {
        old.parentNode.removeChild(old);
      }
    }

    var card = document.createElement('article');
    card.className =
      'assistant-eval__card assistant-eval__card--' +
      (repaired ? 'repaired' : st);
    if (id) {
      card.setAttribute('data-case-id', id);
    }
    if (repaired) {
      card.setAttribute('data-repaired', '1');
    }

    var head = document.createElement('div');
    head.className = 'assistant-eval__card-head';
    var badge = document.createElement('strong');
    badge.className = 'assistant-eval__badge';
    badge.textContent = statusLabel(st, repaired);
    var meta = document.createElement('span');
    meta.className = 'muted';
    meta.textContent =
      (row.id || '') +
      (layer ? ' · ' + layer : '') +
      (row.ms != null ? ' · ' + row.ms + ' ms' : '');
    head.appendChild(badge);
    head.appendChild(meta);
    if (id) {
      var retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'btn-secondary assistant-eval__retry';
      retry.textContent = i18n.retry || 'Retry';
      retry.setAttribute('data-retry-id', id);
      retry.disabled = !!running;
      retry.addEventListener('click', function () {
        if (!running) {
          runSuite({ ids: [id], merge: true });
        }
      });
      head.appendChild(retry);
    }
    card.appendChild(head);

    if (repaired) {
      var note = document.createElement('p');
      note.className = 'assistant-eval__repaired-note';
      note.textContent = i18n.repaired_note || '';
      card.appendChild(note);
    }

    if (row.title) {
      var title = document.createElement('div');
      title.className = 'assistant-eval__card-title';
      title.textContent = String(row.title);
      card.appendChild(title);
    }

    var qLab = document.createElement('div');
    qLab.className = 'assistant-eval__q-label muted';
    qLab.textContent = isLive
      ? i18n.question || 'Question'
      : i18n.check || 'Check';
    card.appendChild(qLab);

    var q = document.createElement(isLive ? 'p' : 'div');
    q.className = isLive ? 'assistant-eval__q assistant-eval__q--bold' : 'assistant-eval__q';
    q.textContent = String(row.question || row.explain || row.title || '');
    card.appendChild(q);

    var aLab = document.createElement('div');
    aLab.className = 'assistant-eval__a-label muted';
    aLab.textContent = isLive
      ? i18n.answer || 'Answer'
      : i18n.result || 'Result';
    card.appendChild(aLab);
    var a = document.createElement('pre');
    a.className = 'assistant-eval__a';
    var ans = String(row.answer || row.excerpt || row.detail || '');
    a.textContent = ans;
    card.appendChild(a);

    if (row.detail && row.detail !== ans) {
      var d = document.createElement('div');
      d.className = 'assistant-eval__detail muted';
      d.textContent = String(row.detail);
      card.appendChild(d);
    }

    logEl.appendChild(card);
    try {
      card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (e4) {
      /* ignore */
    }
  }

  function knownCaseIds() {
    var map = {};
    for (var i = 0; i < cases.length; i++) {
      if (cases[i] && cases[i].id) {
        map[String(cases[i].id)] = true;
      }
    }
    return map;
  }

  function renderRun(run, opts) {
    opts = opts || {};
    if (!run || !Array.isArray(run.rows)) {
      return;
    }
    var known = knownCaseIds();
    var kept = [];
    for (var r = 0; r < run.rows.length; r++) {
      var row = run.rows[r];
      if (row && row.id && known[String(row.id)]) {
        kept.push(row);
      }
    }
    currentRun = {
      profile: String(run.profile || profileId || ''),
      live: run.live !== false,
      rows: kept,
      summary: recount(kept),
      ms: run.ms != null ? Number(run.ms) : recount(kept).ms,
      system_prompt: String(run.system_prompt || ''),
      finished: run.finished !== false,
      finished_at: run.finished_at || run.saved_at || null,
      saved_at: run.saved_at || null,
    };
    if (currentRun.profile) {
      profileId = currentRun.profile;
      root.setAttribute('data-profile', profileId);
    }
    if (liveEl && typeof run.live === 'boolean') {
      liveEl.checked = !!run.live;
    }
    if (panel) {
      panel.hidden = false;
    }
    if (currentRun.system_prompt) {
      setSystemPrompt(currentRun.system_prompt);
    }
    if (logEl && !opts.keepLog) {
      logEl.innerHTML = '';
      for (var i = 0; i < currentRun.rows.length; i++) {
        appendRow(currentRun.rows[i], false);
      }
    }
    var s = recount(currentRun.rows);
    setSummary(s.pass, s.fail, s.skip, currentRun.ms);
    setTotals(s);
    setProgress(currentRun.rows.length, currentRun.rows.length);
    updateLastBanner();
  }

  function postJson(body) {
    var headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    };
    var tok = csrfToken();
    if (tok) {
      headers['X-CSRF-Token'] = tok;
      body.csrf = tok;
    }
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers,
      body: JSON.stringify(body),
    }).then(function (res) {
      return res.text().then(function (txt) {
        var data = null;
        try {
          data = JSON.parse(txt);
        } catch (e) {
          data = null;
        }
        if (data && data.csrf) {
          applyCsrf(data.csrf);
        }
        if (!res.ok) {
          var raw =
            (data && (data.error || data.message || data.error_code)) ||
            txt.slice(0, 240) ||
            'HTTP ' + res.status;
          throw { message: friendlyError(raw, res.status), status: res.status, raw: raw };
        }
        if (!data || typeof data !== 'object') {
          throw { message: friendlyError(txt.slice(0, 240), res.status), status: res.status };
        }
        return data;
      });
    });
  }

  function finish(msg) {
    running = false;
    aborting = false;
    if (runBtn) {
      runBtn.disabled = false;
    }
    if (abortBtn) {
      abortBtn.hidden = true;
    }
    if (msgEl) {
      msgEl.textContent = msg || '';
    }
    if (logEl) {
      var btns = logEl.querySelectorAll('.assistant-eval__retry');
      for (var b = 0; b < btns.length; b++) {
        btns[b].disabled = false;
      }
    }
    setTotals(recount(currentRun.rows || []));
    updateRetryFailsVisibility();
  }

  /**
   * @param {{ids?:string[],merge?:boolean}|undefined} opts
   */
  function runSuite(opts) {
    opts = opts || {};
    if (running || !cases.length) {
      return;
    }
    var merge = !!opts.merge;
    var idFilter = Array.isArray(opts.ids) ? opts.ids.map(String) : null;
    var queue = [];
    if (idFilter && idFilter.length) {
      for (var f = 0; f < idFilter.length; f++) {
        var metaF = caseMeta(idFilter[f]);
        if (metaF) {
          queue.push(metaF);
        } else {
          queue.push({ id: idFilter[f], layer: 'live', title: idFilter[f] });
        }
      }
    } else {
      queue = cases.slice();
    }
    if (!queue.length) {
      return;
    }

    running = true;
    aborting = false;
    t0 = Date.now();
    if (panel) {
      panel.hidden = false;
    }
    if (!merge) {
      if (logEl) {
        logEl.innerHTML = '';
      }
      currentRun = emptyRun();
      currentRun.live = !!(liveEl && liveEl.checked);
      currentRun.profile = profileId || '';
    } else {
      currentRun.live = !!(liveEl && liveEl.checked);
    }
    if (runBtn) {
      runBtn.disabled = true;
    }
    if (retryFailsBtn) {
      retryFailsBtn.hidden = true;
    }
    if (abortBtn) {
      abortBtn.hidden = false;
    }
    if (msgEl) {
      msgEl.textContent = i18n.running || 'Running…';
    }
    setProgress(0, queue.length);

    var live = !!(liveEl && liveEl.checked);
    var i = 0;

    function paintCounts() {
      var s = recount(currentRun.rows || []);
      setSummary(s.pass, s.fail, s.skip);
      setTotals(s);
      updateRetryFailsVisibility();
    }

    function setRetryButtonsDisabled(disabled) {
      if (!logEl) {
        return;
      }
      var btns = logEl.querySelectorAll('.assistant-eval__retry');
      for (var b = 0; b < btns.length; b++) {
        btns[b].disabled = !!disabled;
      }
    }
    setRetryButtonsDisabled(true);

    function next() {
      if (aborting) {
        return persist(true).then(function () {
          finish(i18n.aborted || 'Aborted');
        });
      }
      if (i >= queue.length) {
        setProgress(queue.length, queue.length);
        paintCounts();
        return persist(true).then(function () {
          finish(i18n.done || 'Done');
        });
      }
      var c = queue[i];
      var id = c && c.id ? String(c.id) : '';
      var meta = caseMeta(id) || c || {};
      if (msgEl) {
        msgEl.textContent =
          (i18n.running || 'Running…') +
          ' ' +
          (i + 1) +
          '/' +
          queue.length +
          (meta.title ? ' — ' + meta.title : id ? ' — ' + id : '');
      }
      var body = {
        action: 'run_one',
        case_id: id,
        live: live ? 1 : 0,
      };
      if (currentRun.profile || profileId) {
        body.profile = currentRun.profile || profileId;
      }
      return postJson(body)
        .then(function (res) {
          if (res.csrf) {
            applyCsrf(res.csrf);
          }
          if (res.profile) {
            currentRun.profile = String(res.profile);
            profileId = currentRun.profile;
            root.setAttribute('data-profile', profileId);
          }
          if (res.system_prompt) {
            setSystemPrompt(res.system_prompt);
          }
          var row = res.row || {};
          upsertLocalRow(row);
          appendRow(row, merge);
          i++;
          setProgress(i, queue.length);
          paintCounts();
          return persist(false).then(function () {
            return next();
          });
        })
        .catch(function (err) {
          var errMsg =
            err && err.message
              ? String(err.message)
              : friendlyError(String(err || ''), 0);
          var layer = meta.layer || '';
          var isLiveCase = layer === 'live';
          var failRow = {
            id: id || 'error',
            layer: layer,
            title: meta.title || id,
            explain: meta.explain || '',
            question: isLiveCase
              ? meta.prompt || meta.explain || meta.title || id
              : meta.explain || meta.title || id,
            answer: errMsg,
            status: 'fail',
            detail: errMsg,
            ms: 0,
          };
          upsertLocalRow(failRow);
          appendRow(failRow, merge);
          i++;
          setProgress(i, queue.length);
          paintCounts();
          if (err && (err.status === 403 || /csrf/i.test(String(err.raw || err.message || '')))) {
            return persist(true).then(function () {
              finish(errMsg);
            });
          }
          return persist(false).then(function () {
            return next();
          });
        });
    }

    next();
  }

  // Restore last saved run on entry.
  if (lastRun && Array.isArray(lastRun.rows) && lastRun.rows.length) {
    renderRun(lastRun);
  }

  if (form) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      runSuite({ merge: false });
    });
  }
  if (retryFailsBtn) {
    retryFailsBtn.addEventListener('click', function () {
      if (running) {
        return;
      }
      var ids = [];
      var rows = currentRun.rows || [];
      for (var i = 0; i < rows.length; i++) {
        if (rows[i] && String(rows[i].status) === 'fail' && rows[i].id) {
          ids.push(String(rows[i].id));
        }
      }
      if (ids.length) {
        runSuite({ ids: ids, merge: true });
      }
    });
  }
  if (abortBtn) {
    abortBtn.addEventListener('click', function () {
      if (running) {
        aborting = true;
      }
    });
  }
})();
