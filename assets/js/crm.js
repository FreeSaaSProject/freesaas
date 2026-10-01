(function () {
  var THEME_KEY = 'freesaas.theme';
  var RAIL_KEY = 'freesaas.rail.collapsed';
  var mq = window.matchMedia('(prefers-color-scheme: dark)');

  window.t = function (key, replace) {
    var dict = window.FREESAAS_I18N || {};
    var text = dict[key] || key;
    if (replace && typeof replace === 'object') {
      Object.keys(replace).forEach(function (k) {
        text = text.split('{' + k + '}').join(String(replace[k]));
      });
    }
    return text;
  };

  function resolveTheme(mode) {
    if (mode === 'light' || mode === 'dark') return mode;
    return mq.matches ? 'dark' : 'light';
  }

  function getThemeMode() {
    try {
      return localStorage.getItem(THEME_KEY) || 'system';
    } catch (e) {
      return 'system';
    }
  }

  function applyTheme(mode) {
    var m = mode || getThemeMode();
    if (m !== 'light' && m !== 'dark' && m !== 'system') m = 'system';
    var resolved = resolveTheme(m);
    document.documentElement.setAttribute('data-theme', resolved);
    document.documentElement.setAttribute('data-theme-mode', m);
    try { localStorage.setItem(THEME_KEY, m); } catch (e) {}
    document.querySelectorAll('[data-theme-switch]').forEach(function (group) {
      group.querySelectorAll('[data-theme-set]').forEach(function (btn) {
        var on = btn.getAttribute('data-theme-set') === m;
        btn.classList.toggle('is-active', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    });
  }

  function bindThemeControls() {
    document.querySelectorAll('[data-theme-set]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        applyTheme(btn.getAttribute('data-theme-set'));
      });
    });
    if (typeof mq.addEventListener === 'function') {
      mq.addEventListener('change', function () {
        if (getThemeMode() === 'system') applyTheme('system');
      });
    } else if (typeof mq.addListener === 'function') {
      mq.addListener(function () {
        if (getThemeMode() === 'system') applyTheme('system');
      });
    }
    applyTheme(getThemeMode());
  }

  // Command palette
  var input = document.getElementById('cmd');
  var list = document.getElementById('cmd-list');
  if (input && list) {
    var commands = [];
    try { commands = JSON.parse(input.getAttribute('data-commands') || '[]'); } catch (e) { commands = []; }

    function render(q) {
      var query = (q || '').toLowerCase().trim();
      list.innerHTML = '';
      var matches = commands.filter(function (c) {
        if (!query) return true;
        return String(c.title || '').toLowerCase().indexOf(query) >= 0
          || String(c.path || '').toLowerCase().indexOf(query) >= 0
          || String(c.group || '').toLowerCase().indexOf(query) >= 0;
      }).slice(0, 8);

      if (!matches.length) {
        list.hidden = true;
        return;
      }
      matches.forEach(function (c) {
        var li = document.createElement('li');
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = (c.group ? c.group + ' · ' : '') + c.title + '  ' + c.path;
        btn.addEventListener('click', function () { window.location.href = c.path; });
        li.appendChild(btn);
        list.appendChild(li);
      });
      list.hidden = false;
    }

    input.addEventListener('input', function () { render(input.value); });
    input.addEventListener('focus', function () { render(input.value); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { list.hidden = true; }
      if (e.key === 'Enter') {
        var first = list.querySelector('button');
        if (first) { e.preventDefault(); first.click(); }
      }
    });
    document.addEventListener('click', function (e) {
      if (!input.contains(e.target) && !list.contains(e.target)) list.hidden = true;
    });
  }

  // Auto-submit filters
  document.querySelectorAll('form[data-auto-filter]').forEach(function (form) {
    form.querySelectorAll('select[data-auto-submit], select').forEach(function (sel) {
      if (sel.hasAttribute('data-auto-submit-ignore')) return;
      sel.addEventListener('change', function () {
        form.submit();
      });
    });
    var search = form.querySelector('input[data-filter-debounce], input[type="search"]');
    if (search) {
      var t = null;
      var last = search.value;
      search.addEventListener('input', function () {
        var val = search.value;
        clearTimeout(t);
        t = setTimeout(function () {
          if (val === last) return;
          last = val;
          form.submit();
        }, 400);
      });
      search.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          clearTimeout(t);
          form.submit();
        }
      });
    }
  });

  // Pager: keyboard ←/→ and horizontal swipe
  (function () {
    function activePager() {
      return document.querySelector('.pager[data-pager-prev], .pager[data-pager-next]');
    }
    function go(dir) {
      var pager = activePager();
      if (!pager) return;
      var href = pager.getAttribute(dir === 'prev' ? 'data-pager-prev' : 'data-pager-next');
      if (href) location.href = href;
    }
    function typingTarget(el) {
      if (!el || !el.tagName) return false;
      var tag = el.tagName.toLowerCase();
      if (tag === 'input' || tag === 'textarea' || tag === 'select') return true;
      if (el.isContentEditable) return true;
      return !!(el.closest && el.closest('[contenteditable="true"]'));
    }
    document.addEventListener('keydown', function (e) {
      if (e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey) return;
      if (typingTarget(e.target)) return;
      if (e.key === 'ArrowLeft') {
        e.preventDefault();
        go('prev');
      } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        go('next');
      }
    });

    var touchStartX = 0;
    var touchStartY = 0;
    var tracking = false;
    var area = document.querySelector('.main') || document.body;
    area.addEventListener('touchstart', function (e) {
      if (!e.touches || e.touches.length !== 1) return;
      if (!activePager()) return;
      tracking = true;
      touchStartX = e.touches[0].clientX;
      touchStartY = e.touches[0].clientY;
    }, { passive: true });
    area.addEventListener('touchend', function (e) {
      if (!tracking || !e.changedTouches || !e.changedTouches.length) return;
      tracking = false;
      var dx = e.changedTouches[0].clientX - touchStartX;
      var dy = e.changedTouches[0].clientY - touchStartY;
      if (Math.abs(dx) < 70 || Math.abs(dx) < Math.abs(dy) * 1.4) return;
      // swipe left → next page; swipe right → prev
      go(dx < 0 ? 'next' : 'prev');
    }, { passive: true });
    area.addEventListener('touchcancel', function () { tracking = false; }, { passive: true });
  })();

  document.querySelectorAll('select[data-auto-submit]').forEach(function (sel) {
    if (sel.closest('form[data-auto-filter]')) return;
    sel.addEventListener('change', function () {
      if (sel.form) sel.form.submit();
    });
  });

  // Desktop collapsible rail
  var body = document.body;
  var toggle = document.getElementById('rail-toggle');
  var expand = document.getElementById('rail-expand');

  function isMobileNav() {
    return window.matchMedia('(max-width: 900px)').matches;
  }

  function setCollapsed(on) {
    if (isMobileNav()) return;
    body.classList.toggle('rail-collapsed', !!on);
    if (expand) expand.hidden = !on;
    try { localStorage.setItem(RAIL_KEY, on ? '1' : '0'); } catch (e) {}
  }

  try {
    if (!isMobileNav() && localStorage.getItem(RAIL_KEY) === '1') setCollapsed(true);
  } catch (e) {}

  if (toggle) {
    toggle.addEventListener('click', function () { setCollapsed(true); });
  }
  if (expand) {
    expand.addEventListener('click', function () { setCollapsed(false); });
  }

  // Home news mobile tabs
  var homeCols = document.getElementById('home-news-cols');
  var homeTabs = document.querySelectorAll('.home-news-tab');
  if (homeCols && homeTabs.length) {
    var cards = homeCols.querySelectorAll('.home-news-card');
    function goSlide(i) {
      if (!cards[i]) return;
      cards[i].scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' });
      homeTabs.forEach(function (t, j) { t.classList.toggle('is-active', j === i); });
    }
    homeTabs.forEach(function (tab, i) {
      tab.addEventListener('click', function () { goSlide(i); });
    });
    homeCols.addEventListener('scroll', function () {
      var center = homeCols.scrollLeft + homeCols.clientWidth / 2;
      var idx = 0;
      cards.forEach(function (card, j) {
        if (center >= card.offsetLeft) idx = j;
      });
      homeTabs.forEach(function (t, j) { t.classList.toggle('is-active', j === idx); });
    });
  }

  // Mobile drawer
  var navOpenBtn = document.getElementById('nav-open');
  var navCloseBtn = document.getElementById('nav-close');
  var backdrop = document.getElementById('nav-backdrop');
  var rail = document.getElementById('rail');

  function setNavOpen(on) {
    body.classList.toggle('nav-open', !!on);
    if (navOpenBtn) navOpenBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
    if (backdrop) backdrop.hidden = !on;
    if (on && rail) {
      var focusable = rail.querySelector('a, button');
      if (focusable) focusable.focus();
    }
  }

  function closeNav() { setNavOpen(false); }

  if (navOpenBtn) {
    navOpenBtn.addEventListener('click', function () { setNavOpen(true); });
  }
  if (navCloseBtn) {
    navCloseBtn.addEventListener('click', closeNav);
  }
  if (backdrop) {
    backdrop.addEventListener('click', closeNav);
  }
  if (rail) {
    rail.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        if (isMobileNav()) closeNav();
      });
    });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && body.classList.contains('nav-open')) {
      closeNav();
    }
  });
  window.addEventListener('resize', function () {
    if (!isMobileNav()) {
      closeNav();
      try {
        if (localStorage.getItem(RAIL_KEY) === '1') setCollapsed(true);
        else setCollapsed(false);
      } catch (e) {}
    } else {
      body.classList.remove('rail-collapsed');
      if (expand) expand.hidden = true;
    }
  });

  bindThemeControls();

  // Comment reply
  var replyPid = document.getElementById('reply_pid');
  var commentField = document.getElementById('comment-field');
  var replyHint = document.getElementById('reply-hint');
  var replyClear = document.getElementById('reply-clear');

  function clearReply() {
    if (replyPid) replyPid.value = '0';
    if (replyHint) {
      replyHint.hidden = true;
      replyHint.textContent = '';
    }
  }

  document.querySelectorAll('.js-reply').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var pid = btn.getAttribute('data-pid') || '0';
      var name = btn.getAttribute('data-name') || '';
      if (replyPid) replyPid.value = pid;
      if (commentField) {
        var prefix = name ? (name + ', ') : '';
        if (commentField.value.indexOf(prefix) !== 0) {
          commentField.value = prefix + commentField.value.replace(/^\S+\s+\S+,\s*/, '');
        }
        commentField.focus();
      }
      if (replyHint) {
        replyHint.hidden = false;
        replyHint.textContent = (window.t ? window.t('crm.reply_hint', {id: pid}) : ('Reply to comment #' + pid))
          + (name ? (' · ' + name) : '');
      }
      var form = document.getElementById('comment-form');
      if (form) form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  });
  if (replyClear) {
    replyClear.addEventListener('click', function () {
      clearReply();
      if (commentField) commentField.value = '';
    });
  }

  // Bulk selection / group actions on list tables
  document.querySelectorAll('[data-bulk-form]').forEach(function (form) {
    var selectAll = form.querySelector('[data-bulk-select-all]');
    var actionSel = form.querySelector('[data-bulk-action]');
    var submitBtn = form.querySelector('[data-bulk-submit]');
    var fields = form.querySelectorAll('[data-bulk-field-for]');

    function rowChecks() {
      return Array.prototype.slice.call(form.querySelectorAll('[data-bulk-id]'));
    }

    function syncFields() {
      var action = actionSel ? String(actionSel.value || '0') : '0';
      fields.forEach(function (el) {
        var forAction = el.getAttribute('data-bulk-field-for') || '';
        var show = forAction !== '' && forAction === action;
        el.hidden = !show;
        el.querySelectorAll('select, input').forEach(function (inp) {
          inp.disabled = !show;
        });
      });
    }

    function syncSubmit() {
      if (!submitBtn) return;
      var any = rowChecks().some(function (c) { return c.checked; });
      var action = actionSel ? String(actionSel.value || '0') : '0';
      submitBtn.disabled = !(any && action !== '0');
    }

    if (selectAll) {
      selectAll.addEventListener('change', function () {
        var on = selectAll.checked;
        rowChecks().forEach(function (c) { c.checked = on; });
        syncSubmit();
      });
    }
    form.addEventListener('change', function (e) {
      var t = e.target;
      if (t && t.getAttribute && t.getAttribute('data-bulk-id') !== null) {
        if (selectAll) {
          var all = rowChecks();
          selectAll.checked = all.length > 0 && all.every(function (c) { return c.checked; });
          selectAll.indeterminate = !selectAll.checked && all.some(function (c) { return c.checked; });
        }
        syncSubmit();
      }
      if (t === actionSel) {
        syncFields();
        syncSubmit();
      }
    });
    form.addEventListener('submit', function (e) {
      if (!actionSel || String(actionSel.value || '0') === '0') {
        e.preventDefault();
        return;
      }
      var opt = actionSel.options[actionSel.selectedIndex];
      var msg = opt && opt.getAttribute ? opt.getAttribute('data-confirm') : '';
      if (msg && !window.confirm(msg)) {
        e.preventDefault();
      }
    });
    syncFields();
    syncSubmit();
  });

  // Jump to comment form / focus field
  function focusCommentForm() {
    var form = document.getElementById('comment-form');
    var field = document.getElementById('comment-field');
    if (form) {
      form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    if (field) {
      setTimeout(function () { field.focus(); }, 280);
    }
  }
  var jumpComments = document.getElementById('jump-comments');
  if (jumpComments) {
    jumpComments.addEventListener('click', function (e) {
      e.preventDefault();
      if (history.replaceState) {
        history.replaceState(null, '', '#comment-form');
      } else {
        window.location.hash = 'comment-form';
      }
      focusCommentForm();
    });
  }
  if (window.location.hash === '#comment-form' || window.location.hash === '#comments') {
    setTimeout(focusCommentForm, 50);
  }

  // Lightweight visual / source editor
  document.querySelectorAll('[data-rte]').forEach(function (root) {
    var visual = root.querySelector('.rte-visual');
    var source = root.querySelector('.rte-source');
    var modes = root.querySelectorAll('[data-rte-mode]');
    if (!visual || !source) return;

    var mode = 'visual';
    visual.innerHTML = source.value || '';

    function setMode(next) {
      if (next === mode) return;
      if (next === 'source') {
        source.value = visual.innerHTML;
        root.classList.add('is-source');
        source.hidden = false;
        mode = 'source';
        source.focus();
      } else {
        visual.innerHTML = source.value;
        root.classList.remove('is-source');
        source.hidden = true;
        mode = 'visual';
        visual.focus();
      }
      modes.forEach(function (btn) {
        btn.classList.toggle('is-active', btn.getAttribute('data-rte-mode') === mode);
      });
    }

    modes.forEach(function (btn) {
      btn.addEventListener('click', function () {
        setMode(btn.getAttribute('data-rte-mode') || 'visual');
      });
    });

    root.querySelectorAll('[data-cmd]').forEach(function (btn) {
      btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
      btn.addEventListener('click', function () {
        if (mode !== 'visual') return;
        var cmd = btn.getAttribute('data-cmd');
        var val = btn.getAttribute('data-value') || undefined;
        visual.focus();
        if (cmd === 'createLink') {
          var url = window.prompt(window.t ? window.t('crm.link_prompt') : 'Link URL', 'https://');
          if (!url) return;
          document.execCommand('createLink', false, url);
          return;
        }
        if (cmd === 'formatBlock' && val) {
          document.execCommand('formatBlock', false, val);
          return;
        }
        document.execCommand(cmd, false, val);
      });
    });

    visual.addEventListener('input', function () {
      if (mode === 'visual') source.value = visual.innerHTML;
    });

    var form = root.closest('form');
    if (form) {
      form.addEventListener('submit', function () {
        if (mode === 'visual') source.value = visual.innerHTML;
      });
    }
  });
})();
