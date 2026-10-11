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

  /** CSRF token from <meta name="csrf-token"> for same-origin fetch/XHR. */
  window.freesaasCsrfToken = function () {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? (m.getAttribute('content') || '') : '';
  };

  // Attach X-CSRF-Token on same-origin mutating fetch (session cookie auth).
  if (typeof window.fetch === 'function') {
    var _fetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
      init = init ? Object.assign({}, init) : {};
      var method = String(init.method || 'GET').toUpperCase();
      var url = typeof input === 'string' ? input : (input && input.url) || '';
      var mutating = method !== 'GET' && method !== 'HEAD' && method !== 'OPTIONS';
      var sameOrigin = !url || url.charAt(0) === '/' || url.indexOf(window.location.origin) === 0;
      if (mutating && sameOrigin) {
        var token = window.freesaasCsrfToken();
        if (token) {
          var headers = init.headers;
          if (!headers || typeof headers === 'undefined') {
            init.headers = { 'X-CSRF-Token': token };
          } else if (typeof Headers !== 'undefined' && headers instanceof Headers) {
            if (!headers.has('X-CSRF-Token') && !headers.has('x-csrf-token')) {
              headers.set('X-CSRF-Token', token);
            }
          } else if (Array.isArray(headers)) {
            init.headers = headers.concat([['X-CSRF-Token', token]]);
          } else {
            var h = Object.assign({}, headers);
            if (!h['X-CSRF-Token'] && !h['x-csrf-token']) {
              h['X-CSRF-Token'] = token;
            }
            init.headers = h;
          }
        }
      }
      return _fetch(input, init);
    };
  }

  // MPA navigation progress bar
  // Must paint synchronously in the click/submit handler — browsers freeze
  // timers/paints on the old document once navigation starts, so a delayed
  // setTimeout(show) never becomes visible.
  var NAV_SAFETY_MS = 15000;
  var navEl = null;
  var navBar = null;
  var navSafetyTimer = null;
  var navTrickleTimer = null;
  var navActive = false;
  var navPct = 0;

  function ensureNavProgress() {
    if (navEl) return navEl;
    navEl = document.createElement('div');
    navEl.id = 'nav-progress';
    navEl.className = 'nav-progress';
    navEl.setAttribute('role', 'progressbar');
    navEl.setAttribute('aria-hidden', 'true');
    navEl.setAttribute('aria-valuemin', '0');
    navEl.setAttribute('aria-valuemax', '100');
    navBar = document.createElement('div');
    navBar.className = 'nav-progress-bar';
    navEl.appendChild(navBar);
    (document.body || document.documentElement).appendChild(navEl);
    return navEl;
  }

  function navClearTimers() {
    if (navSafetyTimer) { clearTimeout(navSafetyTimer); navSafetyTimer = null; }
    if (navTrickleTimer) { clearInterval(navTrickleTimer); navTrickleTimer = null; }
  }

  function navSetWidth(pct) {
    navPct = pct;
    if (!navBar) return;
    navBar.style.width = pct + '%';
    if (navEl) navEl.setAttribute('aria-valuenow', String(Math.round(pct)));
  }

  function navStart() {
    ensureNavProgress();
    navClearTimers();
    navActive = true;
    navEl.classList.remove('is-done');
    navEl.setAttribute('aria-label', window.t('core.layout.nav_loading'));
    navEl.setAttribute('aria-hidden', 'false');
    navEl.setAttribute('aria-busy', 'true');
    navEl.classList.add('is-active');
    navSetWidth(0);
    // Force layout so the first paint includes the bar before navigation freezes the page
    void navEl.offsetWidth;
    navSetWidth(18);
    void navEl.offsetWidth;

    navTrickleTimer = setInterval(function () {
      if (navPct >= 90) return;
      var next = navPct + Math.max(0.5, (90 - navPct) * 0.08);
      if (next > 90) next = 90;
      navSetWidth(next);
    }, 200);

    navSafetyTimer = setTimeout(function () {
      navDone();
    }, NAV_SAFETY_MS);
  }

  function navDone() {
    navClearTimers();
    var wasActive = navActive;
    navActive = false;
    if (!navEl) return;
    if (!wasActive || !navEl.classList.contains('is-active')) {
      navEl.classList.remove('is-done', 'is-active');
      navEl.setAttribute('aria-hidden', 'true');
      navEl.removeAttribute('aria-busy');
      navSetWidth(0);
      return;
    }
    navEl.classList.add('is-done');
    navSetWidth(100);
    setTimeout(function () {
      if (!navEl || navActive) return;
      navEl.classList.remove('is-active', 'is-done');
      navEl.setAttribute('aria-hidden', 'true');
      navEl.removeAttribute('aria-busy');
      navSetWidth(0);
    }, 220);
  }

  window.FreesaasNav = { start: navStart, done: navDone };

  function navSameOriginUrl(href) {
    if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return null;
    try {
      var url = new URL(href, window.location.href);
      if (url.origin !== window.location.origin) return null;
      if (url.pathname === window.location.pathname
          && url.search === window.location.search
          && url.hash) {
        return null;
      }
      return url;
    } catch (err) {
      return null;
    }
  }

  function navNormPath(pathname) {
    if (!pathname || pathname === '/') return '/';
    return pathname.replace(/\/+$/, '') || '/';
  }

  /** Mirror PHP NavContext::rewriteHref for dynamic / missed anchors. */
  function navApplyRet(url, anchor) {
    if (!url || (anchor && anchor.hasAttribute('data-nav-no-ret'))) return url;
    var cur = new URL(window.location.href);
    cur.hash = '';
    cur.searchParams.delete('ret');
    var current = cur.pathname + cur.search;
    var ret = new URLSearchParams(window.location.search).get('ret') || '';
    var path = navNormPath(url.pathname);
    var retPath = '';
    if (ret && ret.charAt(0) === '/') {
      try {
        retPath = navNormPath(new URL(ret, window.location.origin).pathname);
      } catch (err) {
        retPath = '';
      }
    }
    // Cancel/up: bare link to ret path → full filtered return URL.
    if (ret && retPath && path === retPath) {
      var bare = true;
      url.searchParams.forEach(function (_v, k) {
        if (k !== 'ret') bare = false;
      });
      if (bare) {
        try {
          return new URL(ret, window.location.origin);
        } catch (err2) {
          return url;
        }
      }
      return url;
    }
    // Outbound to another path: attach current screen as ret.
    if (!current || current === '/') return url;
    if (path === navNormPath(cur.pathname)) return url;
    if (url.searchParams.has('ret')) return url;
    url.searchParams.set('ret', current);
    return url;
  }

  function navEnsureFormRet(form) {
    if (!form || form.hasAttribute('data-nav-no-ret')) return;
    var ret = new URLSearchParams(window.location.search).get('ret') || '';
    if (!ret) return;
    var existing = form.querySelector('input[name="ret"]');
    if (existing) {
      existing.value = ret;
      return;
    }
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'ret';
    input.value = ret;
    form.insertBefore(input, form.firstChild);
  }

  function navGo(href) {
    navStart();
    // Yield one frame so the bar paints, then navigate
    requestAnimationFrame(function () {
      window.location.href = href;
    });
  }

  document.addEventListener('click', function (e) {
    if (e.defaultPrevented) return;
    if (e.button !== 0) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) return;
    if (a.hasAttribute('download')) return;
    var target = (a.getAttribute('target') || '').toLowerCase();
    if (target && target !== '_self') return;
    var url = navSameOriginUrl(a.getAttribute('href'));
    if (!url) return;
    url = navApplyRet(url, a);
    e.preventDefault();
    navGo(url.href);
  });

  document.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;
    var form = e.target;
    if (!form || !form.tagName || form.tagName.toLowerCase() !== 'form') return;
    var fTarget = (form.getAttribute('target') || '').toLowerCase();
    if (fTarget && fTarget !== '_self') return;
    navEnsureFormRet(form);
    navStart();
  });

  window.addEventListener('pageshow', function () {
    navDone();
  });

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
        btn.addEventListener('click', function () {
          if (window.FreesaasNav) {
            window.FreesaasNav.start();
            requestAnimationFrame(function () {
              window.location.href = c.path;
            });
          } else {
            window.location.href = c.path;
          }
        });
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
    function submitFilter() {
      if (window.FreesaasNav) window.FreesaasNav.start();
      // Native submit() skips the submit event; yield a frame so the bar paints
      requestAnimationFrame(function () {
        form.submit();
      });
    }
    form.querySelectorAll('select').forEach(function (sel) {
      if (sel.hasAttribute('data-auto-submit-ignore')) return;
      sel.addEventListener('change', function () {
        submitFilter();
      });
    });
    // Number/checkbox/etc. marked for immediate apply (attribute was unused until now)
    form.querySelectorAll('input[data-filter-change], textarea[data-filter-change]').forEach(function (el) {
      el.addEventListener('change', function () {
        submitFilter();
      });
    });
    // Date / datetime filters (incl. data-auto-submit on procedures etc.)
    form.querySelectorAll('input[type="date"], input[type="datetime-local"]').forEach(function (el) {
      if (el.hasAttribute('data-filter-change')) return; // already bound above
      el.addEventListener('change', function () {
        submitFilter();
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
          submitFilter();
        }, 400);
      });
      search.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          clearTimeout(t);
          submitFilter();
        }
      });
    }
  });

  // Pager: keyboard ←/→ (no swipe — conflicts with horizontal table scroll on mobile)
  (function () {
    function activePager() {
      return document.querySelector('.pager[data-pager-prev], .pager[data-pager-next]');
    }
    function go(dir) {
      var pager = activePager();
      if (!pager) return;
      var href = pager.getAttribute(dir === 'prev' ? 'data-pager-prev' : 'data-pager-next');
      if (href) {
        if (window.FreesaasNav) {
          window.FreesaasNav.start();
          requestAnimationFrame(function () {
            location.href = href;
          });
        } else {
          location.href = href;
        }
      }
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
  })();

  document.querySelectorAll('select[data-auto-submit]').forEach(function (sel) {
    if (sel.closest('form[data-auto-filter]')) return;
    sel.addEventListener('change', function () {
      if (!sel.form) return;
      if (window.FreesaasNav) window.FreesaasNav.start();
      requestAnimationFrame(function () {
        sel.form.submit();
      });
    });
  });

  // Desktop collapsible rail
  var body = document.body;
  var toggle = document.getElementById('rail-toggle');
  var expand = document.getElementById('rail-expand');
  var expandAllBtn = document.getElementById('rail-expand-all');
  var RAIL_GROUP_KEY = 'freesaas.rail.group';
  var railNav = document.getElementById('rail-nav');

  function isMobileNav() {
    return window.matchMedia('(max-width: 900px)').matches;
  }

  function setCollapsed(on) {
    if (isMobileNav() || body.classList.contains('rail-mega')) return;
    body.classList.toggle('rail-collapsed', !!on);
    if (expand) expand.hidden = !on;
    try { localStorage.setItem(RAIL_KEY, on ? '1' : '0'); } catch (e) {}
  }

  function railPeekEnabled() {
    return body.getAttribute('data-rail-peek') === '1';
  }

  function applyRailGroupOpen(isOpenFn, persistEl) {
    if (!railNav) return;
    var groups = railNav.querySelectorAll('.rail-group');
    groups.forEach(function (g) {
      var open = !!isOpenFn(g);
      g.classList.toggle('is-open', open);
      var btn = g.querySelector('.rail-group-toggle');
      if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    if (persistEl) {
      var key = persistEl.getAttribute('data-group-key') || '';
      if (key) {
        try { localStorage.setItem(RAIL_GROUP_KEY, key); } catch (e) {}
      }
    }
  }

  var pinnedGroup = null;
  var showOnlyPinned = function () {};
  var clearPeekTimer = function () {};

  function setRailMega(on) {
    on = !!on;
    var closeBtn = document.getElementById('nav-close');
    var i18n = window.FREESAAS_I18N || {};
    if (on) {
      body.classList.remove('rail-collapsed');
      if (expand) expand.hidden = true;
      clearPeekTimer();
      applyRailGroupOpen(function () { return true; }, null);
      body.classList.add('rail-mega');
      if (expandAllBtn) expandAllBtn.setAttribute('aria-expanded', 'true');
      if (closeBtn) {
        var closeLabel = i18n['core.layout.menu_expand_close'] || closeBtn.getAttribute('aria-label') || '';
        if (closeLabel) {
          closeBtn.setAttribute('aria-label', closeLabel);
          closeBtn.setAttribute('title', closeLabel);
        }
        closeBtn.focus();
      }
    } else {
      body.classList.remove('rail-mega');
      if (expandAllBtn) expandAllBtn.setAttribute('aria-expanded', 'false');
      if (closeBtn) {
        var navClose = i18n['core.layout.close_menu'] || '';
        if (navClose) {
          closeBtn.setAttribute('aria-label', navClose);
          closeBtn.setAttribute('title', navClose);
        }
      }
      showOnlyPinned(false);
      try {
        if (!isMobileNav() && localStorage.getItem(RAIL_KEY) === '1') setCollapsed(true);
      } catch (e) {}
    }
  }

  function isRailMega() {
    return body.classList.contains('rail-mega');
  }

  if (railNav) {
    function findPinnedRailGroup() {
      var activeLink = railNav.querySelector('a.is-active');
      if (activeLink) return activeLink.closest('.rail-group');
      var open = railNav.querySelector('.rail-group.is-open');
      if (open) return open;
      var saved = null;
      try { saved = localStorage.getItem(RAIL_GROUP_KEY); } catch (e) {}
      var groups = railNav.querySelectorAll('.rail-group');
      if (saved) {
        for (var i = 0; i < groups.length; i++) {
          if (groups[i].getAttribute('data-group-key') === saved) return groups[i];
        }
      }
      return groups[0] || null;
    }

    pinnedGroup = findPinnedRailGroup();

    showOnlyPinned = function (persist) {
      if (isRailMega()) return;
      if (!pinnedGroup) {
        applyRailGroupOpen(function () { return false; }, null);
        return;
      }
      applyRailGroupOpen(function (g) { return g === pinnedGroup; }, persist ? pinnedGroup : null);
    };

    function peekRailGroup(groupEl) {
      if (!groupEl || isRailMega()) return;
      applyRailGroupOpen(function (g) {
        return g === groupEl || g === pinnedGroup;
      }, null);
    }

    if (pinnedGroup) showOnlyPinned(!railNav.querySelector('a.is-active'));

    function canHoverRail() {
      return !isRailMega()
        && railPeekEnabled()
        && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    }

    var peekTimer = null;
    var peekPending = null;
    var PEEK_DELAY_MS = 500;

    clearPeekTimer = function () {
      if (peekTimer) {
        clearTimeout(peekTimer);
        peekTimer = null;
      }
      peekPending = null;
    };

    function schedulePeek(group) {
      if (!canHoverRail()) return;
      if (group === peekPending) return;

      if (group === pinnedGroup) {
        clearPeekTimer();
        showOnlyPinned(false);
        return;
      }

      if (group.classList.contains('is-open')) return;

      clearPeekTimer();
      peekPending = group;
      peekTimer = setTimeout(function () {
        peekTimer = null;
        peekPending = null;
        peekRailGroup(group);
      }, PEEK_DELAY_MS);
    }

    railNav.addEventListener('mouseover', function (ev) {
      if (!canHoverRail()) return;
      var group = ev.target.closest('.rail-group');
      if (!group || !railNav.contains(group)) return;
      schedulePeek(group);
    });

    railNav.addEventListener('mouseleave', function () {
      if (!canHoverRail()) return;
      clearPeekTimer();
      showOnlyPinned(false);
    });

    railNav.addEventListener('click', function (ev) {
      if (isRailMega()) return;
      var btn = ev.target.closest('.rail-group-toggle');
      if (!btn || !railNav.contains(btn)) return;
      var group = btn.closest('.rail-group');
      if (!group) return;
      clearPeekTimer();
      if (group === pinnedGroup && group.classList.contains('is-open')) {
        pinnedGroup = null;
        try { localStorage.removeItem(RAIL_GROUP_KEY); } catch (e) {}
        applyRailGroupOpen(function () { return false; }, null);
        return;
      }
      pinnedGroup = group;
      showOnlyPinned(true);
    });
  }

  if (expandAllBtn) {
    expandAllBtn.setAttribute('aria-expanded', 'false');
    expandAllBtn.addEventListener('click', function () {
      setRailMega(true);
    });
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

  function closeRailOverlay() {
    if (isRailMega()) {
      setRailMega(false);
      return;
    }
    closeNav();
  }

  if (navOpenBtn) {
    navOpenBtn.addEventListener('click', function () { setNavOpen(true); });
  }
  if (navCloseBtn) {
    navCloseBtn.addEventListener('click', closeRailOverlay);
  }
  if (backdrop) {
    backdrop.addEventListener('click', closeNav);
  }
  if (rail) {
    rail.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        if (isRailMega()) setRailMega(false);
        if (isMobileNav()) closeNav();
      });
    });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (isRailMega()) {
      setRailMega(false);
      return;
    }
    if (body.classList.contains('nav-open')) {
      closeNav();
    }
  });
  window.addEventListener('resize', function () {
    if (isRailMega()) setRailMega(false);
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


  // Bulk selection / group actions on list tables
  document.querySelectorAll('[data-bulk-form]').forEach(function (form) {
    var selectAll = form.querySelector('[data-bulk-select-all]');
    var actionSel = form.querySelector('[data-bulk-action]');
    var submitBtn = form.querySelector('[data-bulk-submit]');
    var fields = form.querySelectorAll('[data-bulk-field-for]');
    var lastCheck = null;

    function rowChecks() {
      return Array.prototype.slice.call(form.querySelectorAll('[data-bulk-id]'));
    }

    function syncRowClasses() {
      rowChecks().forEach(function (c) {
        var tr = c.closest('tr');
        if (tr) tr.classList.toggle('is-bulk-selected', !!c.checked);
      });
    }

    function syncSelectAll() {
      if (!selectAll) return;
      var all = rowChecks();
      selectAll.checked = all.length > 0 && all.every(function (c) { return c.checked; });
      selectAll.indeterminate = !selectAll.checked && all.some(function (c) { return c.checked; });
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

    function syncActionLimits() {
      if (!actionSel) return;
      var n = rowChecks().filter(function (c) { return c.checked; }).length;
      Array.prototype.forEach.call(actionSel.options, function (opt) {
        var maxAttr = opt.getAttribute('data-bulk-max-selection');
        if (maxAttr === null || maxAttr === '') {
          opt.disabled = false;
          return;
        }
        var maxN = parseInt(maxAttr, 10);
        if (!(maxN > 0)) {
          opt.disabled = false;
          return;
        }
        opt.disabled = n > maxN;
      });
      var selected = actionSel.options[actionSel.selectedIndex];
      if (selected && selected.disabled) {
        actionSel.value = '0';
        syncFields();
      }
    }

    function syncSubmit() {
      if (!submitBtn) return;
      var any = rowChecks().some(function (c) { return c.checked; });
      var action = actionSel ? String(actionSel.value || '0') : '0';
      var selected = actionSel && actionSel.options[actionSel.selectedIndex];
      var actionOk = action !== '0' && !(selected && selected.disabled);
      submitBtn.disabled = !(any && actionOk);
    }

    function syncAll() {
      syncRowClasses();
      syncSelectAll();
      syncActionLimits();
      syncSubmit();
    }

    if (selectAll) {
      selectAll.addEventListener('change', function () {
        var on = selectAll.checked;
        rowChecks().forEach(function (c) { c.checked = on; });
        lastCheck = null;
        syncAll();
      });
    }

    // Expand hit-area to the whole checkbox cell (tall rows), but do not
    // re-toggle when the click already hit the input/label — that would
    // cancel the native checkbox flip (double switch).
    form.addEventListener('click', function (e) {
      var col = e.target && e.target.closest ? e.target.closest('.bulk-check-col') : null;
      if (!col || !form.contains(col)) return;

      var rowInput = col.querySelector('[data-bulk-id]');
      if (!rowInput) return; // header select-all: native + change handler

      var t = e.target;
      var onControl = t === rowInput
        || (t.closest && !!t.closest('label.bulk-check-hit') && col.contains(t));

      var boxes = rowChecks();
      if (e.shiftKey && lastCheck) {
        e.preventDefault();
        e.stopPropagation();
        var a = boxes.indexOf(lastCheck);
        var b = boxes.indexOf(rowInput);
        if (a >= 0 && b >= 0) {
          var from = Math.min(a, b);
          var to = Math.max(a, b);
          // Capture runs before native flip; current checked is pre-click.
          var on = !rowInput.checked;
          for (var i = from; i <= to; i++) {
            boxes[i].checked = on;
          }
          lastCheck = rowInput;
          syncAll();
        }
        return;
      }

      if (onControl) {
        // Native (or label) toggles; change handler will syncAll.
        lastCheck = rowInput;
        return;
      }

      e.preventDefault();
      e.stopPropagation();
      rowInput.checked = !rowInput.checked;
      lastCheck = rowInput;
      syncAll();
    }, true);

    form.addEventListener('change', function (e) {
      var t = e.target;
      if (t && t.getAttribute && t.getAttribute('data-bulk-id') !== null) {
        syncAll();
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
    syncAll();
  });

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

// Dashboard sortable lists (widgets + menu)
(function () {
  function bindSortable(list) {
    var dragEl = null;

    function items() {
      return Array.prototype.slice.call(list.children).filter(function (el) {
        return el.classList.contains('dashboard-layout-item');
      });
    }

    list.addEventListener('dragstart', function (e) {
      var handle = e.target.closest('.dashboard-drag-handle');
      if (!handle || !list.contains(handle)) return;
      var li = handle.closest('.dashboard-layout-item');
      if (!li || !list.contains(li)) return;
      dragEl = li;
      li.classList.add('is-dragging');
      try {
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', '1');
      } catch (err) {}
    });

    list.addEventListener('dragend', function () {
      if (dragEl) dragEl.classList.remove('is-dragging');
      items().forEach(function (el) { el.classList.remove('is-drag-over'); });
      dragEl = null;
    });

    list.addEventListener('dragover', function (e) {
      if (!dragEl) return;
      e.preventDefault();
      var over = e.target.closest('.dashboard-layout-item');
      if (!over || over === dragEl || !list.contains(over)) return;
      items().forEach(function (el) { el.classList.toggle('is-drag-over', el === over); });
      var rect = over.getBoundingClientRect();
      var before = (e.clientY - rect.top) < rect.height / 2;
      if (before) {
        list.insertBefore(dragEl, over);
      } else {
        list.insertBefore(dragEl, over.nextSibling);
      }
    });

    list.addEventListener('drop', function (e) {
      e.preventDefault();
      items().forEach(function (el) { el.classList.remove('is-drag-over'); });
    });
  }

  document.querySelectorAll('[data-sortable]').forEach(bindSortable);

  document.querySelectorAll('[data-password-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var wrap = btn.closest('.auth-pass');
      var input = wrap && wrap.querySelector('input');
      if (!input) return;
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      var label = show
        ? (btn.getAttribute('data-label-hide') || '')
        : (btn.getAttribute('data-label-show') || '');
      if (label) btn.setAttribute('aria-label', label);
    });
  });

  // Date / datetime quick tools: − УД + (and УТ for datetime-local)
  (function () {
    function pad2(n) {
      return n < 10 ? '0' + n : String(n);
    }
    function todayYmd(d) {
      d = d || new Date();
      return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }
    function nowHm(d) {
      d = d || new Date();
      return pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    }
    function parseDatePart(val) {
      if (!val) return null;
      var m = String(val).match(/^(\d{4})-(\d{2})-(\d{2})/);
      if (!m) return null;
      var dt = new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
      if (isNaN(dt.getTime())) return null;
      return dt;
    }
    function timePart(val) {
      var m = String(val || '').match(/T(\d{2}:\d{2})/);
      return m ? m[1] : null;
    }
    function shiftYmd(ymd, delta) {
      var dt = parseDatePart(ymd) || new Date();
      dt.setDate(dt.getDate() + delta);
      return todayYmd(dt);
    }
    function notify(input) {
      try {
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
      } catch (e) { /* IE-less */ }
    }
    function setValue(input, next) {
      if (input.value === next) {
        notify(input);
        return;
      }
      input.value = next;
      notify(input);
    }
    function makeBtn(label, title, onClick) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'date-tools-btn';
      btn.textContent = label;
      if (title) {
        btn.title = title;
        btn.setAttribute('aria-label', title);
      }
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        onClick();
      });
      return btn;
    }
    function enhance(input) {
      if (!input || input.closest('.date-tools')) return;
      if (input.hasAttribute('data-no-date-tools')) return;
      if (input.disabled || input.readOnly) return;

      var isDt = input.type === 'datetime-local';
      var wrap = document.createElement('span');
      wrap.className = 'date-tools';
      input.parentNode.insertBefore(wrap, input);
      wrap.appendChild(input);

      var btns = document.createElement('span');
      btns.className = 'date-tools-btns';
      btns.setAttribute('role', 'group');

      btns.appendChild(makeBtn(
        window.t('core.date.btn_prev'),
        window.t('core.date.day_prev'),
        function () {
          if (isDt) {
            var t = timePart(input.value) || '00:00';
            setValue(input, shiftYmd(input.value, -1) + 'T' + t);
          } else {
            setValue(input, shiftYmd(input.value, -1));
          }
        }
      ));
      btns.appendChild(makeBtn(
        window.t('core.date.btn_today'),
        window.t('core.date.set_today'),
        function () {
          var ymd = todayYmd();
          if (isDt) {
            var t = timePart(input.value) || '00:00';
            setValue(input, ymd + 'T' + t);
          } else {
            setValue(input, ymd);
          }
        }
      ));
      btns.appendChild(makeBtn(
        window.t('core.date.btn_next'),
        window.t('core.date.day_next'),
        function () {
          if (isDt) {
            var t = timePart(input.value) || '00:00';
            setValue(input, shiftYmd(input.value, 1) + 'T' + t);
          } else {
            setValue(input, shiftYmd(input.value, 1));
          }
        }
      ));
      if (isDt) {
        btns.appendChild(makeBtn(
          window.t('core.date.btn_now'),
          window.t('core.date.set_now'),
          function () {
            var base = parseDatePart(input.value);
            var ymd = base ? todayYmd(base) : todayYmd();
            setValue(input, ymd + 'T' + nowHm());
          }
        ));
      }

      wrap.appendChild(btns);
    }

    document.querySelectorAll('input[type="date"], input[type="datetime-local"]').forEach(enhance);
  })();
})();
