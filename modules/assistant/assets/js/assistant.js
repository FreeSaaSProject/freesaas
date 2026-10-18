(function () {
  function readPageContext(root) {
    var raw = root.getAttribute('data-page-context') || '';
    if (raw) {
      try {
        var fromRoot = JSON.parse(raw);
        if (fromRoot && typeof fromRoot === 'object') {
          return fromRoot;
        }
      } catch (e) {}
    }
    var el = document.getElementById('freesaas-page-context');
    if (el && el.textContent) {
      try {
        var fromStamp = JSON.parse(el.textContent);
        if (fromStamp && typeof fromStamp === 'object') {
          return fromStamp;
        }
      } catch (e2) {}
    }
    return {
      path: window.location.pathname || '/',
      title: document.title || '',
    };
  }

  function initChat(root) {
  if (!root || root.getAttribute('data-assistant-ready') === '1') {
    return;
  }
  root.setAttribute('data-assistant-ready', '1');

  var isFab = root.getAttribute('data-mode') === 'page';
  var idPrefix = isFab ? 'assistant-fab-chat' : 'assistant-chat';
  var log = document.getElementById(idPrefix + '-log') || root.querySelector('.assistant-chat__log');
  var form = document.getElementById(idPrefix + '-form') || root.querySelector('.assistant-chat__form');
  var input = document.getElementById(idPrefix + '-input') || root.querySelector('textarea');
  var sendBtn = document.getElementById(idPrefix + '-send') || root.querySelector('button[type="submit"]');
  var chatUrl = root.getAttribute('data-chat-url') || '/assistant/chat';
  var confirmUrl = root.getAttribute('data-confirm-url') || '/assistant/confirm';
  var clearUrl = root.getAttribute('data-clear-url') || '/assistant/clear';
  var feedbackUrl = root.getAttribute('data-feedback-url') || '/assistant/feedback';
  var adminBase = (root.getAttribute('data-admin-base') || '').replace(/\/$/, '');
  var clearBtn = isFab
    ? document.getElementById('assistant-fab-clear')
    : document.getElementById('assistant-chat-clear');
  var fbModal = isFab ? null : document.getElementById('assistant-fb-modal');
  var fbChips = isFab ? null : document.getElementById('assistant-fb-chips');
  var fbComment = isFab ? null : document.getElementById('assistant-fb-comment');
  var fbSubmit = isFab ? null : document.getElementById('assistant-fb-submit');
  var fbPendingIdx = -1;
  var fbSelected = {};
  var pageContext = isFab ? readPageContext(root) : null;
  var i18n = {};
  try {
    i18n = JSON.parse(root.getAttribute('data-i18n') || '{}') || {};
  } catch (e) {
    i18n = {};
  }
  var menuLabels = {};
  try {
    menuLabels = JSON.parse(root.getAttribute('data-menu-labels') || '{}') || {};
  } catch (e) {
    menuLabels = {};
  }

  var history = [];
  try {
    history = JSON.parse(root.getAttribute('data-history') || '[]') || [];
  } catch (e) {
    history = [];
  }
  if (!Array.isArray(history)) {
    history = [];
  }

  function t(key, fallback) {
    return i18n[key] || fallback || key;
  }

  function whoLabel(role) {
    return role === 'user' ? t('you', 'You') : t('assistant', 'Assistant');
  }

  /**
   * Keep the composer (query line) on screen; scroll the log inside itself
   * so the newest messages sit just above the form — not scrollIntoView on
   * the last bubble (that hid the input).
   */
  function scrollLogToEnd() {
    if (!log) {
      return;
    }
    var go = function () {
      log.scrollTop = log.scrollHeight;
      var anchor = form || sendBtn || input;
      if (anchor && typeof anchor.scrollIntoView === 'function') {
        try {
          anchor.scrollIntoView({ block: 'end', behavior: 'auto', inline: 'nearest' });
        } catch (e) {
          anchor.scrollIntoView(false);
        }
      }
    };
    go();
    requestAnimationFrame(function () {
      go();
      requestAnimationFrame(go);
    });
    setTimeout(go, 60);
    setTimeout(go, 320);
  }

  function escapeHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function labelForAdminUrl(url) {
    if (!adminBase || String(url).indexOf(adminBase) !== 0) {
      return '';
    }
    var path = String(url).slice(adminBase.length).split('#')[0].split('?')[0];
    path = path.replace(/\/+$/, '') || '/';
    if (menuLabels[path]) {
      return menuLabels[path];
    }
    // Longest menu prefix match (/payments/123/edit → /payments)
    var best = '';
    var bestLen = -1;
    Object.keys(menuLabels).forEach(function (p) {
      if (p === '/') {
        return;
      }
      if (path === p || path.indexOf(p + '/') === 0) {
        if (p.length > bestLen) {
          best = menuLabels[p];
          bestLen = p.length;
        }
      }
    });
    return best;
  }

  function anchorHtml(href, label) {
    return (
      '<a href="' +
      escapeHtml(href) +
      '" target="_blank" rel="noopener noreferrer">' +
      escapeHtml(label) +
      '</a>'
    );
  }

  /** Escape, then **bold**, markdown links, bare admin URLs (with Russian menu titles). */
  function linkifyHtml(text) {
    var esc = escapeHtml(text);
    // **bold** → <b> (UI chrome; after escape so content stays safe)
    esc = esc.replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>');
    // __bold__
    esc = esc.replace(/__([^_]+)__/g, '<b>$1</b>');
    // [label](https://…)
    esc = esc.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, function (_, label, href) {
      return anchorHtml(href, label);
    });
    // Bare https URLs (skip ones already inside href=")
    esc = esc.replace(/(^|[^"'>])(https?:\/\/[^\s<&]+)/g, function (_, lead, url) {
      var trailing = '';
      var clean = url.replace(/[.,;:!?)»]+$/g, function (m) {
        trailing = m;
        return '';
      });
      var label = labelForAdminUrl(clean) || clean;
      return lead + anchorHtml(clean, label) + trailing;
    });
    if (adminBase) {
      esc = esc.replace(
        /(^|[\s(«"'])(\/[a-z][a-z0-9_-]*(?:\/[\w./?&=%~-]*)?)/g,
        function (_, lead, path) {
          var href = adminBase + path;
          var label = labelForAdminUrl(href) || path;
          return lead + anchorHtml(href, label);
        }
      );
    }
    return esc;
  }

  function pendingHeadline(p) {
    if (!p) {
      return '';
    }
    if (p.headline) {
      return String(p.headline);
    }
    var summary = String(p.summary || p.op_id || p.token || '');
    var nl = summary.indexOf('\n');
    return nl >= 0 ? summary.slice(0, nl) : summary;
  }

  function pendingLines(p) {
    if (!p) {
      return [];
    }
    if (Array.isArray(p.lines) && p.lines.length) {
      return p.lines.map(function (ln) {
        return String(ln || '').trim();
      }).filter(Boolean);
    }
    var summary = String(p.summary || '');
    if (summary.indexOf('\n') < 0) {
      return [];
    }
    return summary
      .split(/\n/)
      .slice(1)
      .map(function (ln) {
        return ln.trim();
      })
      .filter(Boolean);
  }

  function attachPending(wrap, pending) {
    if (!wrap || !pending || !pending.length) {
      return;
    }
    var tokens = [];
    var list = document.createElement('div');
    list.className = 'assistant-chat__pending-list';
    pending.forEach(function (p) {
      if (!p || !p.token) {
        return;
      }
      tokens.push(p.token);
      var headline = pendingHeadline(p);
      var lines = pendingLines(p);
      var item;
      if (lines.length) {
        item = document.createElement('details');
        item.className = 'assistant-chat__pending-item';
        item.open = true;
        var sum = document.createElement('summary');
        sum.textContent = '• ' + headline;
        item.appendChild(sum);
        var detail = document.createElement('ul');
        detail.className = 'assistant-chat__pending-detail';
        lines.forEach(function (ln) {
          var li = document.createElement('li');
          li.textContent = ln;
          detail.appendChild(li);
        });
        item.appendChild(detail);
      } else {
        item = document.createElement('div');
        item.className = 'assistant-chat__pending-item';
        item.textContent = '• ' + (headline || p.op_id || p.token);
      }
      list.appendChild(item);
    });
    if (!tokens.length) {
      return;
    }
    wrap.appendChild(list);
    var row = document.createElement('div');
    row.className = 'assistant-chat__pending';
    var ok = document.createElement('button');
    ok.type = 'button';
    ok.className = 'btn-primary';
    ok.textContent =
      tokens.length > 1
        ? t('confirm_all', 'Confirm all')
        : t('confirm', 'Confirm');
    var no = document.createElement('button');
    no.type = 'button';
    no.className = 'btn-secondary';
    no.textContent =
      tokens.length > 1
        ? t('cancel_all', 'Cancel all')
        : t('cancel', 'Cancel');
    ok.addEventListener('click', function () {
      resolvePendingBatch(tokens, 'confirm', wrap);
    });
    no.addEventListener('click', function () {
      resolvePendingBatch(tokens, 'cancel', wrap);
    });
    row.appendChild(ok);
    row.appendChild(no);
    wrap.appendChild(row);
  }

  function formatTrace(trace) {
    if (!trace || !trace.length) {
      return '';
    }
    return trace
      .map(function (step) {
        if (!step || (!step.op_id && !step.label)) {
          return '';
        }
        var st = step.status || '';
        var mark =
          st === 'ok'
            ? '✅'
            : st === 'pending'
              ? '⏳'
              : st === 'forbidden'
                ? '🚫'
                : st === 'error'
                  ? '❌'
                  : '🔧';
        var title = step.label || step.op_id;
        var args = step.args || {};
        var bits = [];
        if (args.q) {
          bits.push('«' + String(args.q).slice(0, 40) + '»');
        }
        if (args.summ) {
          bits.push(String(args.summ));
        }
        if (args.account_id) {
          bits.push('#' + args.account_id);
        }
        if (args.type_id) {
          bits.push('t' + args.type_id);
        }
        if (args.supplier_id) {
          bits.push('s' + args.supplier_id);
        }
        if (args.descr) {
          bits.push(String(args.descr).slice(0, 32));
        }
        return mark + ' ' + title + (bits.length ? ' · ' + bits.join(' · ') : '');
      })
      .filter(Boolean)
      .join('\n');
  }

  function csrfToken() {
    if (window.freesaasCsrfToken) {
      var tok = window.freesaasCsrfToken();
      if (tok) {
        return tok;
      }
    }
    var m = document.querySelector('meta[name="csrf-token"]');
    return m && m.getAttribute('content') ? m.getAttribute('content') : '';
  }

  function contextAround(histIdx) {
    var out = [];
    if (histIdx < 0 || histIdx >= history.length) {
      return out;
    }
    // Previous turn (user + assistant) if present.
    var prevAsst = -1;
    for (var i = histIdx - 1; i >= 0; i--) {
      if (history[i] && history[i].role === 'assistant') {
        prevAsst = i;
        break;
      }
    }
    if (prevAsst >= 0) {
      var prevUser = -1;
      for (var j = prevAsst - 1; j >= 0; j--) {
        if (history[j] && history[j].role === 'user') {
          prevUser = j;
          break;
        }
      }
      if (prevUser >= 0) {
        out.push({
          role: 'user',
          content: String(history[prevUser].content || ''),
        });
      }
      out.push({
        role: 'assistant',
        content: String(history[prevAsst].content || ''),
      });
    }
    var curUser = -1;
    for (var k = histIdx - 1; k >= 0; k--) {
      if (history[k] && history[k].role === 'user') {
        curUser = k;
        break;
      }
    }
    if (curUser >= 0) {
      out.push({
        role: 'user',
        content: String(history[curUser].content || ''),
      });
    }
    out.push({
      role: 'assistant',
      content: String(history[histIdx].content || ''),
    });
    return out;
  }

  function closeFbMenus() {
    if (!log) {
      return;
    }
    log.querySelectorAll('.assistant-fb__menu').forEach(function (el) {
      el.hidden = true;
      el.classList.remove('is-open');
    });
  }

  function messagesForFeedback(histIdx, wrap) {
    var msgs = contextAround(histIdx);
    if (msgs.length) {
      return msgs;
    }
    var bodyEl = wrap && wrap.querySelector
      ? wrap.querySelector('.assistant-chat__body')
      : null;
    var text = bodyEl ? String(bodyEl.textContent || '').trim() : '';
    if (text) {
      return [{ role: 'assistant', content: text }];
    }
    return [];
  }

  function openFbModal(histIdx) {
    fbPendingIdx = histIdx;
    fbSelected = {};
    if (fbComment) {
      fbComment.value = '';
    }
    if (fbChips) {
      fbChips.querySelectorAll('.assistant-fb-chip').forEach(function (chip) {
        chip.classList.remove('is-on');
      });
    }
    if (fbModal) {
      fbModal.hidden = false;
    }
  }

  function closeFbModal() {
    fbPendingIdx = -1;
    if (fbModal) {
      fbModal.hidden = true;
    }
  }

  function markRated(wrap, rating) {
    if (!wrap) {
      return;
    }
    wrap.setAttribute('data-fb-rated', rating);
    var bar = wrap.querySelector('.assistant-fb');
    if (bar) {
      bar.innerHTML = '';
      var done = document.createElement('span');
      done.className = 'assistant-fb__done muted';
      done.textContent = t('fb_thanks', 'Thanks');
      bar.appendChild(done);
    }
  }

  function postFeedback(payload, wrap) {
    var body = payload;
    var tok = csrfToken();
    if (tok) {
      body.csrf = tok;
    }
    return fetch(feedbackUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': tok || '',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(body),
    })
      .then(function (r) {
        return r.json().then(function (j) {
          return { ok: r.ok, json: j };
        });
      })
      .then(function (res) {
        if (!res.ok || !res.json || !res.json.ok) {
          throw new Error(
            (res.json && res.json.error) || t('fb_err', 'Could not save')
          );
        }
        markRated(wrap, payload.rating);
        closeFbModal();
        closeFbMenus();
      });
  }

  function attachFeedback(wrap, histIdx) {
    if (!wrap || histIdx < 0) {
      return;
    }
    if (wrap.querySelector('.assistant-fb')) {
      return;
    }
    var bar = document.createElement('div');
    bar.className = 'assistant-fb';
    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'assistant-fb__toggle';
    toggle.setAttribute('aria-label', t('fb_rate', 'Rate reply'));
    toggle.innerHTML =
      '<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M2 21h4V9H2v12zm20-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32a1 1 0 0 0-.29-.7L13.17 2 7.59 7.59A2 2 0 0 0 7 9v10c0 1.1.9 2 2 2h7c.82 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z"/></svg>';
    var menu = document.createElement('div');
    menu.className = 'assistant-fb__menu';
    menu.hidden = true;
    var goodBtn = document.createElement('button');
    goodBtn.type = 'button';
    goodBtn.className = 'assistant-fb__opt';
    goodBtn.innerHTML =
      '<svg class="assistant-fb__ico" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M2 21h4V9H2v12zm20-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32a1 1 0 0 0-.29-.7L13.17 2 7.59 7.59A2 2 0 0 0 7 9v10c0 1.1.9 2 2 2h7c.82 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z"/></svg> ' +
      t('fb_good', 'Good answer');
    var badBtn = document.createElement('button');
    badBtn.type = 'button';
    badBtn.className = 'assistant-fb__opt';
    badBtn.innerHTML =
      '<svg class="assistant-fb__ico" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M22 3h-4v12h4V3zM2.17 11.12A2 2 0 0 1 4 10h6.31l-.95-4.57-.03-.32a1 1 0 0 1 .29-.7L10.83 3l5.58 5.59A2 2 0 0 1 17 10v8c0 1.1-.9 2-2 2H8c-.82 0-1.54-.5-1.84-1.22L3.14 11.73c-.09-.23-.14-.47-.14-.73 0-.28.06-.54.17-.88z"/></svg> ' +
      t('fb_bad', 'Bad answer');
    menu.appendChild(goodBtn);
    menu.appendChild(badBtn);
    bar.appendChild(toggle);
    bar.appendChild(menu);
    wrap.appendChild(bar);

    toggle.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var willOpen = menu.hidden || !menu.classList.contains('is-open');
      closeFbMenus();
      if (willOpen) {
        menu.hidden = false;
        menu.classList.add('is-open');
      }
    });
    goodBtn.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var msgs = messagesForFeedback(histIdx, wrap);
      if (!msgs.length) {
        window.alert(t('fb_err', 'Could not save'));
        return;
      }
      postFeedback(
        {
          rating: 'good',
          reasons: [],
          comment: '',
          messages: msgs,
        },
        wrap
      ).catch(function (err) {
        window.alert(err && err.message ? err.message : t('fb_err', 'Error'));
      });
    });
    badBtn.addEventListener('click', function (ev) {
      ev.stopPropagation();
      closeFbMenus();
      openFbModal(histIdx);
      wrap.setAttribute('data-fb-open', '1');
    });
  }

  function appendMsg(who, text, pending, trace, role, histIdx) {
    var wrap = document.createElement('div');
    var side = role === 'user' ? 'user' : 'assistant';
    wrap.className = 'assistant-chat__msg assistant-chat__msg--' + side;
    var whoEl = document.createElement('div');
    whoEl.className = 'assistant-chat__who';
    whoEl.textContent = who;
    var body = document.createElement('div');
    body.className = 'assistant-chat__body';
    body.innerHTML = linkifyHtml(text || '');
    wrap.appendChild(whoEl);
    wrap.appendChild(body);
    var traceText = formatTrace(trace);
    if (traceText) {
      var toolsEl = document.createElement('details');
      toolsEl.className = 'assistant-chat__trace';
      var sum = document.createElement('summary');
      sum.textContent = t('tools', 'Tools');
      var pre = document.createElement('pre');
      pre.textContent = traceText;
      toolsEl.appendChild(sum);
      toolsEl.appendChild(pre);
      wrap.appendChild(toolsEl);
    }
    attachPending(wrap, pending);
    if (
      side === 'assistant' &&
      histIdx != null &&
      histIdx >= 0 &&
      String(text || '') !== t('thinking', '…')
    ) {
      wrap.setAttribute('data-hist-idx', String(histIdx));
      attachFeedback(wrap, histIdx);
    }
    log.appendChild(wrap);
    scrollLogToEnd();
    return wrap;
  }

  function renderHistory(list) {
    if (!log) {
      return;
    }
    log.innerHTML = '';
    (list || []).forEach(function (m, idx) {
      if (!m || !m.content) {
        return;
      }
      appendMsg(
        whoLabel(m.role),
        m.content,
        null,
        null,
        m.role === 'user' ? 'user' : 'assistant',
        m.role === 'assistant' ? idx : null
      );
    });
    scrollLogToEnd();
  }

  function autosizeInput() {
    if (!input) {
      return;
    }
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 144) + 'px';
  }

  function applyServerHistory(list, pending, trace) {
    if (!Array.isArray(list)) {
      return;
    }
    history = list;
    renderHistory(history);
    if (!log || !log.lastElementChild) {
      return;
    }
    var last = log.lastElementChild;
    if (trace && trace.length && !last.querySelector('.assistant-chat__trace')) {
      var toolsEl = document.createElement('details');
      toolsEl.className = 'assistant-chat__trace';
      var sum = document.createElement('summary');
      sum.textContent = t('tools', 'Tools');
      var pre = document.createElement('pre');
      pre.textContent = formatTrace(trace);
      toolsEl.appendChild(sum);
      toolsEl.appendChild(pre);
      last.appendChild(toolsEl);
    }
    if (pending && pending.length) {
      attachPending(last, pending);
    }
  }

  function setBusy(busy) {
    if (sendBtn) {
      sendBtn.disabled = !!busy;
    }
    if (input) {
      input.disabled = !!busy;
    }
  }

  function resolvePendingBatch(tokens, action, wrap) {
    setBusy(true);
    var body =
      tokens.length === 1
        ? { token: tokens[0], action: action }
        : { tokens: tokens, action: action };
    fetch(confirmUrl, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify(body),
    })
      .then(function (r) {
        return r.json().then(function (j) {
          return { ok: r.ok, json: j };
        });
      })
      .then(function (res) {
        setBusy(false);
        if (wrap) {
          var stale = wrap.querySelectorAll(
            '.assistant-chat__pending, .assistant-chat__pending-list'
          );
          stale.forEach(function (el) {
            el.remove();
          });
        }
        if (res.json && Array.isArray(res.json.history)) {
          applyServerHistory(res.json.history);
          return;
        }
        var msg =
          res.json && res.json.message
            ? res.json.message
            : res.json && res.json.error
              ? res.json.error
              : t('error', 'Error');
        history.push({ role: 'assistant', content: msg });
        appendMsg(
          t('assistant', 'Assistant'),
          msg,
          null,
          null,
          'assistant',
          history.length - 1
        );
      })
      .catch(function () {
        setBusy(false);
        appendMsg(t('assistant', 'Assistant'), t('error', 'Error'), null, null, 'assistant');
      });
  }

  function sendMessage(text) {
    text = String(text || '').trim();
    if (!text) {
      return;
    }
    appendMsg(t('you', 'You'), text, null, null, 'user');
    history.push({ role: 'user', content: text });
    if (input) {
      input.value = '';
      autosizeInput();
    }
    setBusy(true);
    var thinking = appendMsg(t('assistant', 'Assistant'), t('thinking', '…'), null, null, 'assistant');

    var payload = { message: text };
    if (isFab) {
      payload.mode = 'page';
      payload.page_context = pageContext || readPageContext(root);
    }
    fetch(chatUrl, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    })
      .then(function (r) {
        return r.json().then(function (j) {
          return { ok: r.ok, json: j };
        });
      })
      .then(function (res) {
        setBusy(false);
        if (thinking && thinking.parentNode) {
          thinking.parentNode.removeChild(thinking);
        }
        var trace = (res.json && res.json.tool_trace) || [];
        if (res.json && Array.isArray(res.json.history)) {
          applyServerHistory(
            res.json.history,
            res.ok && res.json.ok ? res.json.pending_actions || [] : [],
            res.ok && res.json.ok ? trace : []
          );
          return;
        }
        if (!res.ok || !res.json || !res.json.ok) {
          var err = (res.json && res.json.error) || t('error', 'Error');
          history.push({ role: 'assistant', content: err });
          appendMsg(
            t('assistant', 'Assistant'),
            err,
            null,
            trace,
            'assistant',
            history.length - 1
          );
          return;
        }
        var reply = res.json.text || '';
        history.push({ role: 'assistant', content: reply });
        appendMsg(
          t('assistant', 'Assistant'),
          reply,
          res.json.pending_actions || [],
          trace,
          'assistant',
          history.length - 1
        );
      })
      .catch(function () {
        setBusy(false);
        if (thinking && thinking.parentNode) {
          thinking.parentNode.removeChild(thinking);
        }
        appendMsg(t('assistant', 'Assistant'), t('error', 'Error'), null, null, 'assistant');
      });
  }

  if (!form || !input || !log) {
    return;
  }

  if (fbChips) {
    var reasons = i18n.fb_reasons || {};
    Object.keys(reasons).forEach(function (id) {
      var chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'assistant-fb-chip';
      chip.setAttribute('data-reason', id);
      chip.textContent = '+ ' + reasons[id];
      chip.addEventListener('click', function () {
        if (fbSelected[id]) {
          delete fbSelected[id];
          chip.classList.remove('is-on');
        } else {
          fbSelected[id] = true;
          chip.classList.add('is-on');
        }
      });
      fbChips.appendChild(chip);
    });
  }
  if (fbModal) {
    fbModal.querySelectorAll('[data-fb-close]').forEach(function (el) {
      el.addEventListener('click', closeFbModal);
    });
  }
  if (fbSubmit) {
    fbSubmit.addEventListener('click', function () {
      var reasons = Object.keys(fbSelected);
      var comment = fbComment ? String(fbComment.value || '').trim() : '';
      if (!reasons.length && !comment) {
        window.alert(t('fb_need_reason', 'Pick a reason'));
        return;
      }
      var wrap =
        fbPendingIdx >= 0
          ? log.querySelector(
              '.assistant-chat__msg[data-hist-idx="' + fbPendingIdx + '"]'
            )
          : null;
      var msgs = messagesForFeedback(fbPendingIdx, wrap);
      if (!msgs.length) {
        window.alert(t('fb_err', 'Could not save'));
        return;
      }
      fbSubmit.disabled = true;
      postFeedback(
        {
          rating: 'bad',
          reasons: reasons,
          comment: comment,
          messages: msgs,
        },
        wrap
      )
        .catch(function (err) {
          window.alert(err && err.message ? err.message : t('fb_err', 'Error'));
        })
        .then(function () {
          fbSubmit.disabled = false;
        });
    });
  }
  document.addEventListener('click', function () {
    closeFbMenus();
  });

  renderHistory(history);

  input.addEventListener('input', autosizeInput);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      if (!sendBtn || sendBtn.disabled) {
        return;
      }
      form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
    }
  });
  autosizeInput();

  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      setBusy(true);
      fetch(clearUrl, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: '{}',
      })
        .then(function (r) {
          return r.json().then(function (j) {
            return { ok: r.ok, json: j };
          });
        })
        .then(function (res) {
          setBusy(false);
          if (!res.ok || !res.json || !res.json.ok) {
            appendMsg(t('assistant', 'Assistant'), (res.json && res.json.error) || t('error', 'Error'), null, null, 'assistant');
            return;
          }
          history = [];
          renderHistory(history);
          appendMsg(t('assistant', 'Assistant'), res.json.text || t('cleared', 'Dialog cleared.'), null, null, 'assistant');
        })
        .catch(function () {
          setBusy(false);
          appendMsg(t('assistant', 'Assistant'), t('error', 'Error'), null, null, 'assistant');
        });
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    sendMessage(input.value || '');
  });

  // Home invite → /assistant?q=… auto-sends once, then cleans the URL.
  var seedQ = (root.getAttribute('data-seed-q') || '').trim();
  if (seedQ) {
    if (window.history && window.history.replaceState) {
      try {
        var clean = window.location.pathname + window.location.hash;
        window.history.replaceState({}, '', clean);
      } catch (e) {}
    }
    sendMessage(seedQ);
  }
  } // end initChat

  function initFabShell() {
    var fab = document.getElementById('assistant-fab');
    if (!fab || fab.getAttribute('data-fab-ready') === '1') {
      return;
    }
    fab.setAttribute('data-fab-ready', '1');
    var btn = document.getElementById('assistant-fab-btn');
    var panel = document.getElementById('assistant-fab-panel');
    var closeBtn = document.getElementById('assistant-fab-close');
    var hideBtn = document.getElementById('assistant-fab-hide');
    var chatRoot = document.getElementById('assistant-fab-chat');
    var hideUrl = fab.getAttribute('data-hide-url') || '/assistant/fab/hide';
    var path = fab.getAttribute('data-path') || window.location.pathname || '/';
    var i18n = {};
    try {
      i18n = JSON.parse((chatRoot && chatRoot.getAttribute('data-i18n')) || '{}') || {};
    } catch (e) {
      i18n = {};
    }

    function setOpen(open) {
      if (!panel || !btn) {
        return;
      }
      if (open) {
        panel.hidden = false;
        fab.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
        var input = document.getElementById('assistant-fab-chat-input');
        if (input) {
          setTimeout(function () {
            input.focus();
          }, 50);
        }
      } else {
        panel.hidden = true;
        fab.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
      }
    }

    if (btn) {
      btn.addEventListener('click', function () {
        setOpen(panel && panel.hidden);
      });
    }
    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        setOpen(false);
      });
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && fab.classList.contains('is-open')) {
        setOpen(false);
      }
    });
    if (hideBtn) {
      hideBtn.addEventListener('click', function () {
        hideBtn.disabled = true;
        fetch(hideUrl, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
          },
          credentials: 'same-origin',
          body: JSON.stringify({ path: path }),
        })
          .then(function (r) {
            return r.json().then(function (j) {
              return { ok: r.ok, json: j };
            });
          })
          .then(function (res) {
            if (!res.ok || !res.json || !res.json.ok) {
              hideBtn.disabled = false;
              window.alert(
                (res.json && res.json.error) || i18n.error || 'Error'
              );
              return;
            }
            fab.parentNode && fab.parentNode.removeChild(fab);
          })
          .catch(function () {
            hideBtn.disabled = false;
            window.alert(i18n.error || 'Error');
          });
      });
    }
    if (chatRoot) {
      initChat(chatRoot);
    }
  }

  var full = document.getElementById('assistant-chat');
  if (full) {
    initChat(full);
  }
  initFabShell();
})();
