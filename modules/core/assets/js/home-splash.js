(function () {
  var root = document.getElementById('home-splash');
  if (!root) {
    return;
  }

  var desk = document.getElementById('home-desk');
  var skipBtn = document.getElementById('home-splash-skip');
  var ask = document.getElementById('home-splash-ask');
  var askInput = ask ? ask.querySelector('.assistant-ask__input') : null;
  var version = root.getAttribute('data-version') || '1';
  var ackUrl = root.getAttribute('data-ack-url') || '/splash/ack';
  var hasAsk = root.getAttribute('data-has-ask') === '1' && !!ask;
  var reduced =
    window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var finished = false;
  var timers = [];
  var companyEl = root.querySelector('[data-splash-company]');
  var welcomeEl = root.querySelector('[data-splash-welcome]');
  var stageEl = root.querySelector('.home-splash__stage');

  // Timeline (ms). Cumulative stage classes — transitions never get redefined mid-flight.
  var T = hasAsk
    ? { by: 1100, welcome: 2000, ask: 3200, type: 3700, out: 5900, flip: 6050, finish: 7350, remove: 8100 }
    : { by: 1100, welcome: 2000, out: 4400, finish: 5600, remove: 6300 };

  function later(ms, fn) {
    var id = setTimeout(fn, ms);
    timers.push(id);
    return id;
  }

  function clearTimers() {
    timers.forEach(function (id) {
      clearTimeout(id);
    });
    timers = [];
  }

  function stage(name) {
    root.classList.add('is-' + name);
  }

  /* ——— Letter cascade for company name (words stay unbreakable) ——— */
  function splitLetters(el) {
    if (!el) {
      return;
    }
    var text = (el.textContent || '').replace(/\s+/g, ' ').trim();
    if (text === '') {
      return;
    }
    var frag = document.createDocumentFragment();
    var raw = text.split(' ');
    var words = [];
    raw.forEach(function (w) {
      // Glue lone punctuation ("-", "—", "·") to the previous word so it never opens a line.
      if (words.length && /^[-–—·|:]$/.test(w)) {
        words[words.length - 1] += '\u00A0' + w;
      } else {
        words.push(w);
      }
    });
    var idx = 0;
    var perLetter = text.length <= 48;
    words.forEach(function (word, wi) {
      if (wi > 0) {
        frag.appendChild(document.createTextNode(' '));
      }
      var w = document.createElement('span');
      w.className = 'home-splash__word';
      if (perLetter) {
        var chars = Array.from ? Array.from(word) : word.split('');
        chars.forEach(function (ch) {
          var s = document.createElement('span');
          s.className = 'home-splash__ch';
          s.style.setProperty('--i', String(idx));
          s.textContent = ch;
          w.appendChild(s);
          idx += 1;
        });
        idx += 1; // breath between words
      } else {
        var one = document.createElement('span');
        one.className = 'home-splash__ch';
        one.style.setProperty('--i', String(wi * 3));
        one.textContent = word;
        w.appendChild(one);
      }
      frag.appendChild(w);
    });
    el.textContent = '';
    el.appendChild(frag);
  }

  /* ——— Fit text to stage width: grow, never overflow; up to maxLines ——— */
  function fitText(el, maxLines) {
    if (!el || !stageEl) {
      return 0;
    }
    var maxW = stageEl.clientWidth;
    if (maxW < 40) {
      return 0;
    }
    var lh = 1.1;
    var words = el.querySelectorAll('.home-splash__word');
    el.style.fontSize = '';
    var lo = 14;
    var hi = Math.min(Math.floor(maxW * 0.28), 140);
    if (hi < lo) {
      hi = lo;
    }
    // Letters are transformed during the intro and inflate scroll* metrics;
    // word boxes are not — measure by them when present.
    function measure(px) {
      if (!words.length) {
        return { w: el.scrollWidth, lines: Math.round(el.scrollHeight / (px * lh)) };
      }
      var minL = Infinity;
      var maxR = -Infinity;
      var tops = [];
      for (var i = 0; i < words.length; i++) {
        var r = words[i].getBoundingClientRect();
        if (r.left < minL) {
          minL = r.left;
        }
        if (r.right > maxR) {
          maxR = r.right;
        }
        var t = Math.round(r.top);
        var seen = false;
        for (var k = 0; k < tops.length; k++) {
          if (Math.abs(tops[k] - t) <= 2) {
            seen = true;
            break;
          }
        }
        if (!seen) {
          tops.push(t);
        }
      }
      return { w: maxR - minL, lines: tops.length };
    }
    var best = lo;
    while (lo <= hi) {
      var mid = (lo + hi) >> 1;
      el.style.fontSize = mid + 'px';
      var m = measure(mid);
      var fitsW = m.w <= maxW + 1;
      var lines = m.lines;
      if (fitsW && lines <= maxLines) {
        best = mid;
        lo = mid + 1;
      } else {
        hi = mid - 1;
      }
    }
    el.style.fontSize = best + 'px';
    return best;
  }

  function fitDecor() {
    // Long company names: if one line ends up tiny, allow two lines (word-wrapped).
    var one = fitText(companyEl, 1);
    if (one > 0 && one < 32 && companyEl && /\s/.test(companyEl.textContent || '')) {
      fitText(companyEl, 2);
    }
    fitText(welcomeEl, 1);
  }

  /* ——— Max types his greeting into the placeholder ——— */
  var typeFull = askInput ? askInput.getAttribute('placeholder') || '' : '';
  var typeTimer = null;

  function typeGreeting() {
    if (!askInput || typeFull === '' || reduced) {
      return;
    }
    var chars = Array.from ? Array.from(typeFull) : typeFull.split('');
    var budget = 1500; // ms for the whole line
    var step = Math.max(22, Math.min(60, Math.floor(budget / Math.max(1, chars.length))));
    var i = 0;
    function tick() {
      if (finished) {
        askInput.setAttribute('placeholder', typeFull);
        return;
      }
      i += 1;
      askInput.setAttribute('placeholder', chars.slice(0, i).join('') + (i < chars.length ? '▍' : ''));
      if (i < chars.length) {
        typeTimer = later(step, tick);
      } else {
        typeTimer = later(420, function () {
          askInput.setAttribute('placeholder', typeFull);
        });
      }
    }
    tick();
  }

  /* ——— Desk handoff ——— */
  function prepareDeskStagger() {
    if (!desk) {
      return;
    }
    var kids = desk.querySelectorAll('.widgets > *');
    for (var i = 0; i < kids.length; i++) {
      kids[i].style.setProperty('--i', String(Math.min(i, 10)));
    }
  }

  function revealDesk(holdAsk) {
    if (!desk) {
      return;
    }
    desk.classList.remove('is-splash-pending');
    desk.classList.add('is-splash-reveal');
    desk.removeAttribute('aria-hidden');
    if (hasAsk) {
      if (holdAsk) {
        desk.classList.add('is-splash-hold-ask');
      } else {
        desk.classList.remove('is-splash-hold-ask');
      }
    }
  }

  function removeSplash() {
    if (root.parentNode) {
      root.parentNode.removeChild(root);
    }
  }

  function ack() {
    try {
      fetch(ackUrl, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({ version: version }),
      }).catch(function () {});
    } catch (e) {}
  }

  function finish() {
    if (finished) {
      return;
    }
    finished = true;
    clearTimers();
    if (askInput) {
      askInput.setAttribute('placeholder', typeFull);
    }
    stage('out');
    stage('done');
    revealDesk(false);
    ack();
    later(reduced ? 0 : 750, removeSplash);
  }

  /* ——— FLIP: splash ask → desk ask ——— */
  function flipAsk(done) {
    if (!hasAsk || !desk) {
      done();
      return;
    }
    var target = desk.querySelector('.assistant-ask');
    if (!target) {
      done();
      return;
    }
    var from = ask.getBoundingClientRect();
    var to = target.getBoundingClientRect();
    if (from.width < 8 || to.width < 8) {
      done();
      return;
    }

    // Stage has a transform (it drifts up on exit) → it would become the containing
    // block for position:fixed. Re-home the strip to the splash root first.
    root.appendChild(ask);
    ask.classList.add('is-flying');
    ask.style.left = from.left + 'px';
    ask.style.top = from.top + 'px';
    ask.style.width = from.width + 'px';
    ask.style.transform = 'translate3d(0,0,0)';

    void ask.offsetWidth;

    var dx = to.left - from.left;
    var dy = to.top - from.top;
    ask.style.width = to.width + 'px';
    ask.style.transform = 'translate3d(' + dx + 'px,' + dy + 'px,0)';

    var ended = false;
    var onEnd = function (e) {
      if (ended || (e && e.propertyName && e.propertyName !== 'transform')) {
        return;
      }
      ended = true;
      ask.removeEventListener('transitionend', onEnd);
      done();
    };
    ask.addEventListener('transitionend', onEnd);
    later(1300, onEnd);
  }

  /* ——— Timeline ——— */
  function runTimeline() {
    if (reduced) {
      stage('brand');
      stage('by');
      stage('welcome');
      if (hasAsk) {
        stage('ask');
      }
      later(400, finish);
      return;
    }

    stage('brand');
    later(T.by, function () {
      stage('by');
    });
    later(T.welcome, function () {
      stage('welcome');
    });

    if (hasAsk) {
      later(T.ask, function () {
        stage('ask');
      });
      later(T.type, typeGreeting);
      later(T.out, function () {
        revealDesk(true);
        stage('out');
      });
      later(T.flip, function () {
        flipAsk(function () {
          finish();
        });
      });
      later(T.finish + 400, finish); // safety net
    } else {
      later(T.out, function () {
        revealDesk(false);
        stage('out');
      });
      later(T.finish, finish);
    }
  }

  /* ——— Skip (revealed on first tap) ——— */
  root.addEventListener(
    'pointerdown',
    function (e) {
      if (finished) {
        return;
      }
      if (skipBtn && (e.target === skipBtn || skipBtn.contains(e.target))) {
        return;
      }
      if (skipBtn && skipBtn.hasAttribute('hidden')) {
        skipBtn.removeAttribute('hidden');
        requestAnimationFrame(function () {
          skipBtn.classList.add('is-visible');
        });
      }
    },
    { passive: true }
  );

  if (skipBtn) {
    skipBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      finish();
    });
  }

  if (ask) {
    ask.addEventListener('submit', function (e) {
      e.preventDefault();
    });
  }

  /* ——— Boot ——— */
  if (askInput && !reduced) {
    // Strip arrives empty; Max types the greeting a bit later.
    askInput.setAttribute('placeholder', '');
  }
  splitLetters(companyEl);
  prepareDeskStagger();
  fitDecor();
  window.addEventListener('resize', fitDecor);

  if (window.requestAnimationFrame) {
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        fitDecor();
        runTimeline();
      });
    });
  } else {
    later(16, function () {
      fitDecor();
      runTimeline();
    });
  }
})();
