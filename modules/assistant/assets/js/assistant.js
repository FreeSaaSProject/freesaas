(function () {
  var root = document.getElementById('assistant-chat');
  if (!root) {
    return;
  }

  var log = document.getElementById('assistant-chat-log');
  var form = document.getElementById('assistant-chat-form');
  var input = document.getElementById('assistant-chat-input');
  var sendBtn = document.getElementById('assistant-chat-send');
  var chatUrl = root.getAttribute('data-chat-url') || '/assistant/chat';
  var confirmUrl = root.getAttribute('data-confirm-url') || '/assistant/confirm';
  var clearUrl = root.getAttribute('data-clear-url') || '/assistant/clear';
  var clearBtn = document.getElementById('assistant-chat-clear');
  var i18n = {};
  try {
    i18n = JSON.parse(root.getAttribute('data-i18n') || '{}') || {};
  } catch (e) {
    i18n = {};
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
      var line = document.createElement('div');
      line.className = 'assistant-chat__pending-item';
      line.textContent = '• ' + (p.summary || p.op_id || p.token);
      list.appendChild(line);
    });
    if (!tokens.length) {
      return;
    }
    wrap.appendChild(list);
    var row = document.createElement('div');
    row.className = 'assistant-chat__pending';
    var ok = document.createElement('button');
    ok.type = 'button';
    ok.className = 'btn';
    ok.textContent =
      tokens.length > 1
        ? t('confirm_all', 'Confirm all')
        : t('confirm', 'Confirm');
    var no = document.createElement('button');
    no.type = 'button';
    no.className = 'btn';
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

  function appendMsg(who, text, pending, trace) {
    var wrap = document.createElement('div');
    wrap.className = 'assistant-chat__msg';
    var whoEl = document.createElement('div');
    whoEl.className = 'assistant-chat__who';
    whoEl.textContent = who;
    var body = document.createElement('pre');
    body.textContent = text || '';
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
    log.appendChild(wrap);
    log.scrollTop = log.scrollHeight;
    return wrap;
  }

  function renderHistory(list) {
    if (!log) {
      return;
    }
    log.innerHTML = '';
    (list || []).forEach(function (m) {
      if (!m || !m.content) {
        return;
      }
      appendMsg(whoLabel(m.role), m.content);
    });
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
        appendMsg(t('assistant', 'Assistant'), msg);
        history.push({ role: 'assistant', content: msg });
      })
      .catch(function () {
        setBusy(false);
        appendMsg(t('assistant', 'Assistant'), t('error', 'Error'));
      });
  }

  if (!form || !input || !log) {
    return;
  }

  renderHistory(history);

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
            appendMsg(t('assistant', 'Assistant'), (res.json && res.json.error) || t('error', 'Error'));
            return;
          }
          history = [];
          renderHistory(history);
          appendMsg(t('assistant', 'Assistant'), res.json.text || t('cleared', 'Dialog cleared.'));
        })
        .catch(function () {
          setBusy(false);
          appendMsg(t('assistant', 'Assistant'), t('error', 'Error'));
        });
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = (input.value || '').trim();
    if (!text) {
      return;
    }
    appendMsg(t('you', 'You'), text);
    history.push({ role: 'user', content: text });
    input.value = '';
    setBusy(true);
    var thinking = appendMsg(t('assistant', 'Assistant'), t('thinking', '…'));

    fetch(chatUrl, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify({ message: text }),
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
          appendMsg(t('assistant', 'Assistant'), err, null, trace);
          history.push({ role: 'assistant', content: err });
          return;
        }
        var reply = res.json.text || '';
        history.push({ role: 'assistant', content: reply });
        appendMsg(
          t('assistant', 'Assistant'),
          reply,
          res.json.pending_actions || [],
          trace
        );
      })
      .catch(function () {
        setBusy(false);
        if (thinking && thinking.parentNode) {
          thinking.parentNode.removeChild(thinking);
        }
        appendMsg(t('assistant', 'Assistant'), t('error', 'Error'));
      });
  });
})();
