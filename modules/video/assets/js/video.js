(function () {
  'use strict';

  var live = document.querySelector('.video-live[data-camera-id]');
  if (!live) {
    return;
  }

  var pollUrl = live.getAttribute('data-poll-url');
  var img = live.querySelector('.video-live-frame');
  var tbody = live.querySelector('.video-det-body');
  var emptyEl = live.querySelector('.video-live-empty');
  var wrap = live.querySelector('.video-live-frame-wrap');
  var refreshBtn = live.querySelector('.video-live-soft-refresh');

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function renderDetections(list, classes) {
    if (!tbody) return;
    classes = classes || [];
    list = Array.isArray(list) ? list : [];
    if (list.length === 0) {
      tbody.innerHTML = '<tr><td colspan="3" class="muted">' + esc(tbody.getAttribute('data-empty') || '—') + '</td></tr>';
      return;
    }
    var html = '';
    list.forEach(function (d) {
      if (!d || typeof d !== 'object') return;
      var cid = parseInt(d.class_id, 10) || 0;
      var cname = d.class_name || d.class || ('#' + cid);
      var conf = Number(d.confidence != null ? d.confidence : d.conf) || 0;
      var color = d.color || '';
      if (!color) {
        for (var i = 0; i < classes.length; i++) {
          if (parseInt(classes[i].id, 10) === cid) {
            color = classes[i].color || '';
            break;
          }
        }
      }
      if (!color) color = '#888888';
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
          '<img class="video-live-frame" src="' +
          esc(url) +
          '" alt="">';
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
})();
