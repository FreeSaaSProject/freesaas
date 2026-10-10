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
    pending.forEach(function (p) {
      var row = document.createElement('div');
      row.className = 'assistant-chat__pending';
      var label = document.createElement('span');
      label.textContent = t('pending', 'Confirm: {summary}').replace(
        '{summary}',
        p.summary || p.op_id || ''
      );
      var ok = document.createElement('button');
      ok.type = 'button';
      ok.className = 'btn';
      ok.textContent = t('confirm', 'Confirm');
      var no = document.createElement('button');
      no.type = 'button';
      no.className = 'btn';
      no.textContent = t('cancel', 'Cancel');
      ok.addEventListener('click', function () {
        resolvePending(p.token, 'confirm', wrap);
      });
      no.addEventListener('click', function () {
        resolvePending(p.token, 'cancel', wrap);
      });
      row.appendChild(label);
      row.appendChild(ok);
      row.appendChild(no);
      wrap.appendChild(row);
    });
  }

  function formatTrace(trace) {
    if (!trace || !trace.length) {
      return '';
    }
    return trace
      .map(function (step) {
        if (!step || !step.op_id) {
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
                : '❌';
        var args = step.args || {};
        var bits = [];
        ['q', 'id', 'account_id', 'type_id', 'summ', 'supplier_id', 'descr'].forEach(
          function (k) {
            if (args[k] !== undefined && args[k] !== null && args[k] !== '') {
              bits.push(k + '=' + String(args[k]).slice(0, 40));
            }
          }
        );
        return (
          mark +
          ' ' +
          step.op_id +
          (bits.length ? ' · ' + bits.join(' · ') : '')
        );
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

  function resolvePending(token, action, wrap) {
    setBusy(true);
    fetch(confirmUrl, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify({ token: token, action: action }),
    })
      .then(function (r) {
        return r.json().then(function (j) {
          return { ok: r.ok, json: j };
        });
      })
      .then(function (res) {
        setBusy(false);
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
        if (wrap) {
          var buttons = wrap.querySelectorAll('.assistant-chat__pending');
          buttons.forEach(function (el) {
            el.remove();
          });
        }
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
