(function () {
  'use strict';

  /* —— Schedule mode panels —— */
  document.querySelectorAll('[data-schedule-root]').forEach(function (root) {
    var sel = root.querySelector('[data-schedule-mode]');
    if (!sel) return;
    function sync() {
      var mode = sel.value || 'always';
      root.querySelectorAll('[data-schedule-panel]').forEach(function (panel) {
        var show = panel.getAttribute('data-schedule-panel') === mode;
        if (show) {
          panel.removeAttribute('hidden');
        } else {
          panel.setAttribute('hidden', 'hidden');
        }
      });
    }
    sel.addEventListener('change', sync);
    sync();
  });

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function classDisplay(classes, cid, fallback) {
    classes = classes || [];
    for (var i = 0; i < classes.length; i++) {
      if (parseInt(classes[i].id, 10) === cid) {
        return classes[i].label || classes[i].name || fallback;
      }
    }
    return fallback;
  }

  function classColor(classes, cid, fallback) {
    classes = classes || [];
    for (var i = 0; i < classes.length; i++) {
      if (parseInt(classes[i].id, 10) === cid) {
        return classes[i].color || fallback;
      }
    }
    return fallback || '#888888';
  }

  /* —— Live camera —— */
  var live = document.querySelector('.video-live[data-camera-id]');
  if (live) {
    var pollUrl = live.getAttribute('data-poll-url');
    var img = live.querySelector('.video-live-frame');
    var tbody = live.querySelector('.video-det-body');
    var emptyEl = live.querySelector('.video-live-empty');
    var wrap = live.querySelector('.video-live-frame-wrap');
    var refreshBtn = live.querySelector('.video-live-soft-refresh');

    function renderDetections(list, classes) {
      if (!tbody) return;
      classes = classes || [];
      list = Array.isArray(list) ? list : [];
      if (list.length === 0) {
        tbody.innerHTML =
          '<tr><td colspan="3" class="muted">' +
          esc(tbody.getAttribute('data-empty') || '—') +
          '</td></tr>';
        return;
      }
      var html = '';
      list.forEach(function (d) {
        if (!d || typeof d !== 'object') return;
        var cid = parseInt(d.class_id, 10) || 0;
        var cname = d.class_name || d.class || classDisplay(classes, cid, '#' + cid);
        var conf = Number(d.confidence != null ? d.confidence : d.conf) || 0;
        var color = d.color || classColor(classes, cid, '#888888');
        html +=
          '<tr><td><span class="video-swatch" style="background:' +
          esc(color) +
          '"></span></td><td>' +
          esc(cname) +
          '</td><td>' +
          esc((conf * 100).toFixed(1)) +
          '%</td></tr>';
      });
      tbody.innerHTML = html;
    }

    function applyPayload(data) {
      if (!data || !data.ok) return;
      var url = data.annotated_url || data.frame_url;
      if (url) {
        if (img) {
          img.src = url;
          img.style.display = '';
        } else if (wrap) {
          wrap.innerHTML =
            '<img class="video-live-frame" src="' + esc(url) + '" alt="">';
          img = wrap.querySelector('.video-live-frame');
          if (emptyEl) emptyEl.remove();
        }
      }
      renderDetections(data.detections, data.classes);
    }

    function softRefresh() {
      if (!pollUrl || document.hidden) return;
      fetch(pollUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(applyPayload)
        .catch(function () {});
    }

    if (refreshBtn) {
      refreshBtn.addEventListener('click', softRefresh);
    }
    setInterval(softRefresh, 5000);
  }

  /* —— Train session coverage poll —— */
  var train = document.querySelector('.video-train-session[data-session-id]');
  if (train) {
    var tPoll = train.getAttribute('data-poll-url');
    var tImg = train.querySelector('.video-train-frame');
    var gridEl = train.querySelector('.video-coverage');

    function paintCoverage(coverage) {
      if (!gridEl || !coverage) return;
      var grid = parseInt(coverage.grid, 10) || 8;
      var cells = coverage.cells || [];
      var max = 1;
      cells.forEach(function (v) {
        max = Math.max(max, parseInt(v, 10) || 0);
      });
      gridEl.style.setProperty('--grid', String(grid));
      var html = '';
      for (var i = 0; i < grid * grid; i++) {
        var v = parseInt(cells[i], 10) || 0;
        var pct = max > 0 ? v / max : 0;
        var poor = v === 0 ? ' is-poor' : '';
        html +=
          '<span class="video-coverage-cell' +
          poor +
          '" style="opacity:' +
          Math.max(0.15, pct) +
          '" title="' +
          v +
          '"></span>';
      }
      gridEl.innerHTML = html;
    }

    function trainRefresh() {
      if (!tPoll || document.hidden) return;
      fetch(tPoll, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.ok) return;
          var url = data.annotated_url || data.frame_url;
          if (url && tImg) {
            tImg.src = url;
          } else if (url) {
            var wrap = train.querySelector('.video-train-frame-wrap');
            var empty = train.querySelector('.video-train-empty');
            if (wrap) {
              var existing = wrap.querySelector('.video-train-frame');
              if (!existing) {
                var im = document.createElement('img');
                im.className = 'video-live-frame video-train-frame';
                im.src = url;
                if (empty) empty.remove();
                wrap.insertBefore(im, wrap.firstChild);
                tImg = im;
              } else {
                existing.src = url;
              }
            }
          }
          paintCoverage(data.coverage);
        })
        .catch(function () {});
    }

    setInterval(trainRefresh, 2000);
  }
})();
