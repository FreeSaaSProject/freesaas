(function () {
  'use strict';

  var root = document.getElementById('vl-root');
  if (!root) return;

  var data;
  try {
    data = JSON.parse(root.getAttribute('data-workspace') || '{}');
  } catch (e) {
    return;
  }

  var canvas = document.getElementById('vl-canvas');
  var ctx = canvas.getContext('2d');
  var strip = document.getElementById('vl-strip');
  var boxlist = document.getElementById('vl-boxlist');
  var predlist = document.getElementById('vl-predlist');
  var predMeta = document.getElementById('vl-pred-meta');
  var predStatus = document.getElementById('vl-pred-status');
  var boxCount = document.getElementById('vl-box-count');
  var ctxMenu = document.getElementById('vl-ctx');
  var tool = 'box';
  var imgEl = new Image();
  var current = null;
  var labels = [];
  var predictions = [];
  var undoStack = [];
  var drawing = null;
  var hoverPeek = null;
  var selectedLabel = null;
  var conf = (data.project && data.project.conf) || 0.25;
  var classes = (data.project && data.project.classes) || [];
  var i18n = {
    promote: root.getAttribute('data-i18n-promote') || 'Promote',
    delete: root.getAttribute('data-i18n-delete') || 'Delete',
    hidden: root.getAttribute('data-i18n-hidden') || 'hidden below conf',
    shown: root.getAttribute('data-i18n-shown') || 'above conf (dashed)',
    noPreds: root.getAttribute('data-i18n-nopreds') || 'No model cache — run Infer to get low-conf peeks',
  };

  function findClass(id) {
    var nid = Number(id);
    for (var i = 0; i < classes.length; i++) {
      var c = classes[i];
      if (!c) continue;
      if (Number(c.id != null ? c.id : i) === nid) return c;
    }
    return null;
  }

  function classColor(id) {
    var c = findClass(id);
    return (c && c.color) || '#3b82f6';
  }

  function className(id) {
    var c = findClass(id);
    return (c && (c.label || c.name)) || String(id);
  }

  function boxConf(b) {
    if (!b) return null;
    if (b.conf != null && b.conf !== '') return Number(b.conf);
    if (b.confidence != null && b.confidence !== '') return Number(b.confidence);
    return null;
  }

  function iou(a, b) {
    var ra = yoloToPx(a);
    var rb = yoloToPx(b);
    var x1 = Math.max(ra.x, rb.x);
    var y1 = Math.max(ra.y, rb.y);
    var x2 = Math.min(ra.x + ra.w, rb.x + rb.w);
    var y2 = Math.min(ra.y + ra.h, rb.y + rb.h);
    var iw = Math.max(0, x2 - x1);
    var ih = Math.max(0, y2 - y1);
    var inter = iw * ih;
    if (inter <= 0) return 0;
    var ua = ra.w * ra.h + rb.w * rb.h - inter;
    return ua > 0 ? inter / ua : 0;
  }

  /** Predictions that already match a stored label (same place) — do not draw dashed under solids. */
  function isCoveredByLabel(pred) {
    for (var i = 0; i < labels.length; i++) {
      if (iou(pred, labels[i]) >= 0.55) return true;
    }
    return false;
  }

  function findImage(id) {
    var list = data.images || [];
    for (var i = 0; i < list.length; i++) {
      if (list[i].id === id) return list[i];
    }
    return null;
  }

  function pushUndo() {
    undoStack.push(JSON.stringify(labels));
    if (undoStack.length > 30) undoStack.shift();
  }

  function selectImage(id) {
    current = findImage(id);
    if (!current) return;
    labels = (current.labels || []).slice();
    predictions = (current.predictions || []).slice();
    hoverPeek = null;
    selectedLabel = null;
    undoStack = [];
    hideCtx();
    var inferId = document.getElementById('vl-infer-image-id');
    var splitId = document.getElementById('vl-split-image-id');
    var split = document.getElementById('vl-split');
    if (inferId) inferId.value = String(id);
    if (splitId) splitId.value = String(id);
    if (split) split.value = current.split || 'train';
    imgEl.onload = function () {
      canvas.width = imgEl.naturalWidth;
      canvas.height = imgEl.naturalHeight;
      redraw();
    };
    imgEl.src = current.url;
    renderStrip();
    renderBoxList();
    renderPredList();
    updatePredStatus();
  }

  function renderStrip() {
    strip.innerHTML = '';
    (data.images || []).forEach(function (im) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'video-label-thumb' + (current && current.id === im.id ? ' is-current' : '');
      var img = document.createElement('img');
      img.src = im.url;
      img.alt = im.filename;
      btn.appendChild(img);
      btn.title = im.filename;
      btn.addEventListener('click', function () {
        selectImage(im.id);
      });
      strip.appendChild(btn);
    });
  }

  function renderBoxList() {
    boxlist.innerHTML = '';
    if (boxCount) boxCount.textContent = String(labels.length);
    labels.forEach(function (b, idx) {
      var li = document.createElement('li');
      li.className = 'video-label-boxitem' + (selectedLabel === idx ? ' is-selected' : '');
      var swatch = document.createElement('span');
      swatch.className = 'video-label-swatch';
      swatch.style.background = classColor(b.class_id || 0);
      var meta = document.createElement('button');
      meta.type = 'button';
      meta.className = 'video-label-boxmeta';
      var c = boxConf(b);
      meta.textContent = className(b.class_id || 0) + (c != null ? ' ' + c.toFixed(2) : '');
      meta.addEventListener('click', function () {
        selectedLabel = idx;
        renderBoxList();
        redraw();
      });
      var del = document.createElement('button');
      del.type = 'button';
      del.className = 'btn-ghost btn-sm';
      del.textContent = '×';
      del.title = i18n.delete;
      del.addEventListener('click', function (ev) {
        ev.stopPropagation();
        pushUndo();
        labels.splice(idx, 1);
        if (selectedLabel === idx) selectedLabel = null;
        else if (selectedLabel != null && selectedLabel > idx) selectedLabel--;
        syncCurrentLabels();
        redraw();
        renderBoxList();
      });
      li.appendChild(swatch);
      li.appendChild(meta);
      li.appendChild(del);
      boxlist.appendChild(li);
    });
  }

  function renderPredList() {
    if (!predlist) return;
    predlist.innerHTML = '';
    var above = 0;
    var below = 0;
    var covered = 0;
    predictions.forEach(function (p, idx) {
      var c = boxConf(p);
      var cov = isCoveredByLabel(p);
      if (cov) {
        covered++;
        return;
      }
      if (c == null || c >= conf) above++;
      else below++;
      var li = document.createElement('li');
      li.className = 'video-label-boxitem video-label-preditem' + (c != null && c < conf ? ' is-low' : '');
      var swatch = document.createElement('span');
      swatch.className = 'video-label-swatch';
      swatch.style.background = classColor(p.class_id || 0);
      var meta = document.createElement('button');
      meta.type = 'button';
      meta.className = 'video-label-boxmeta';
      meta.textContent =
        className(p.class_id || 0) +
        (c != null ? ' ' + c.toFixed(2) : '') +
        (c != null && c < conf ? ' · peek' : '');
      meta.addEventListener('click', function () {
        if (c != null && c < conf) {
          hoverPeek = idx;
        }
        pushUndo();
        labels.push({
          class_id: p.class_id || 0,
          x: p.x,
          y: p.y,
          w: p.w,
          h: p.h,
          conf: c,
        });
        syncCurrentLabels();
        hoverPeek = null;
        redraw();
        renderBoxList();
        renderPredList();
        updatePredStatus();
      });
      li.appendChild(swatch);
      li.appendChild(meta);
      predlist.appendChild(li);
    });
    if (predMeta) {
      if (!predictions.length) {
        predMeta.textContent = i18n.noPreds;
      } else {
        predMeta.textContent =
          above + ' ' + i18n.shown + ' · ' + below + ' ' + i18n.hidden +
          (covered ? ' · ' + covered + ' overlapped' : '');
      }
    }
  }

  function updatePredStatus() {
    if (!predStatus) return;
    var withConf = predictions.filter(function (p) { return boxConf(p) != null; }).length;
    if (!predictions.length) {
      predStatus.hidden = false;
      predStatus.textContent = i18n.noPreds;
    } else if (!withConf) {
      predStatus.hidden = false;
      predStatus.textContent = i18n.noPreds;
    } else {
      predStatus.hidden = true;
    }
  }

  function syncCurrentLabels() {
    if (current) current.labels = labels.slice();
  }

  function yoloToPx(b) {
    var W = canvas.width;
    var H = canvas.height;
    var bw = b.w * W;
    var bh = b.h * H;
    var x = b.x * W - bw / 2;
    var y = b.y * H - bh / 2;
    return { x: x, y: y, w: bw, h: bh };
  }

  function pxToYolo(x, y, w, h) {
    var W = canvas.width;
    var H = canvas.height;
    return {
      class_id: 0,
      x: (x + w / 2) / W,
      y: (y + h / 2) / H,
      w: w / W,
      h: h / H,
    };
  }

  function visiblePredictions() {
    var out = [];
    predictions.forEach(function (p, idx) {
      if (isCoveredByLabel(p)) return;
      var c = boxConf(p);
      // Missing conf: treat as assist hint only if not covered (legacy) — hide by default
      if (c == null) {
        if (hoverPeek === idx) out.push({ box: p, idx: idx, peek: true });
        return;
      }
      if (c >= conf) {
        out.push({ box: p, idx: idx, peek: false });
      } else if (hoverPeek === idx) {
        out.push({ box: p, idx: idx, peek: true });
      }
    });
    return out;
  }

  function hiddenPredictions() {
    return predictions
      .map(function (p, idx) {
        return { box: p, idx: idx };
      })
      .filter(function (item) {
        if (isCoveredByLabel(item.box)) return false;
        var c = boxConf(item.box);
        return c == null || c < conf;
      });
  }

  function hitTestHidden(mx, my) {
    var hits = hiddenPredictions();
    var best = null;
    var bestConf = -1;
    hits.forEach(function (item) {
      var r = yoloToPx(item.box);
      if (mx >= r.x && mx <= r.x + r.w && my >= r.y && my <= r.y + r.h) {
        var c = boxConf(item.box);
        var score = c != null ? c : 0;
        if (score > bestConf) {
          bestConf = score;
          best = item.idx;
        }
      }
    });
    return best;
  }

  function hitTestLabel(mx, my) {
    for (var i = labels.length - 1; i >= 0; i--) {
      var r = yoloToPx(labels[i]);
      if (mx >= r.x && mx <= r.x + r.w && my >= r.y && my <= r.y + r.h) {
        return i;
      }
    }
    return null;
  }

  function drawBox(b, opts) {
    var r = yoloToPx(b);
    ctx.save();
    ctx.strokeStyle = opts.color || classColor(b.class_id || 0);
    ctx.lineWidth = opts.peek ? 3 : opts.selected ? 3 : 2;
    if (opts.dashed) {
      ctx.setLineDash([6, 4]);
    }
    if (opts.peek) {
      ctx.globalAlpha = 0.95;
      ctx.fillStyle = 'rgba(255,200,0,0.15)';
      ctx.fillRect(r.x, r.y, r.w, r.h);
    } else if (opts.selected) {
      ctx.fillStyle = 'rgba(59,130,246,0.12)';
      ctx.fillRect(r.x, r.y, r.w, r.h);
    }
    ctx.strokeRect(r.x, r.y, r.w, r.h);
    ctx.setLineDash([]);
    ctx.fillStyle = ctx.strokeStyle;
    ctx.font = '12px sans-serif';
    var label = className(b.class_id || 0);
    var c = boxConf(b);
    if (c != null) label += ' ' + c.toFixed(2);
    ctx.fillText(label, r.x + 2, Math.max(12, r.y - 2));
    ctx.restore();
  }

  function redraw() {
    if (!imgEl.complete || !imgEl.naturalWidth) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(imgEl, 0, 0);
    visiblePredictions().forEach(function (item) {
      drawBox(item.box, {
        color: '#f59e0b',
        dashed: true,
        peek: item.peek,
      });
    });
    labels.forEach(function (b, idx) {
      drawBox(b, {
        color: classColor(b.class_id || 0),
        selected: selectedLabel === idx,
      });
    });
    if (drawing) {
      ctx.save();
      ctx.strokeStyle = '#22c55e';
      ctx.strokeRect(drawing.x0, drawing.y0, drawing.x1 - drawing.x0, drawing.y1 - drawing.y0);
      ctx.restore();
    }
  }

  function canvasPoint(ev) {
    var rect = canvas.getBoundingClientRect();
    var sx = canvas.width / rect.width;
    var sy = canvas.height / rect.height;
    return {
      x: (ev.clientX - rect.left) * sx,
      y: (ev.clientY - rect.top) * sy,
    };
  }

  function hideCtx() {
    if (!ctxMenu) return;
    ctxMenu.hidden = true;
    ctxMenu.innerHTML = '';
  }

  function showCtx(clientX, clientY, labelIdx) {
    if (!ctxMenu || labelIdx == null || !labels[labelIdx]) return;
    selectedLabel = labelIdx;
    renderBoxList();
    redraw();
    ctxMenu.innerHTML = '';
    classes.forEach(function (c) {
      if (!c) return;
      var id = Number(c.id != null ? c.id : classes.indexOf(c));
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'video-label-ctx-item';
      btn.setAttribute('role', 'menuitem');
      var sw = document.createElement('span');
      sw.className = 'video-label-swatch';
      sw.style.background = c.color || '#888';
      btn.appendChild(sw);
      btn.appendChild(document.createTextNode(c.label || c.name || String(id)));
      if (Number(labels[labelIdx].class_id) === id) {
        btn.classList.add('is-current');
      }
      btn.addEventListener('click', function () {
        pushUndo();
        labels[labelIdx].class_id = id;
        syncCurrentLabels();
        hideCtx();
        redraw();
        renderBoxList();
      });
      ctxMenu.appendChild(btn);
    });
    var sep = document.createElement('div');
    sep.className = 'video-label-ctx-sep';
    ctxMenu.appendChild(sep);
    var del = document.createElement('button');
    del.type = 'button';
    del.className = 'video-label-ctx-item is-danger';
    del.textContent = i18n.delete;
    del.addEventListener('click', function () {
      pushUndo();
      labels.splice(labelIdx, 1);
      selectedLabel = null;
      syncCurrentLabels();
      hideCtx();
      redraw();
      renderBoxList();
      renderPredList();
    });
    ctxMenu.appendChild(del);

    ctxMenu.hidden = false;
    var pad = 8;
    var vw = window.innerWidth;
    var vh = window.innerHeight;
    ctxMenu.style.left = '0px';
    ctxMenu.style.top = '0px';
    var mw = ctxMenu.offsetWidth;
    var mh = ctxMenu.offsetHeight;
    var left = Math.min(clientX, vw - mw - pad);
    var top = Math.min(clientY, vh - mh - pad);
    ctxMenu.style.left = Math.max(pad, left) + 'px';
    ctxMenu.style.top = Math.max(pad, top) + 'px';
  }

  document.querySelectorAll('#vl-toolbar [data-tool]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      tool = btn.getAttribute('data-tool');
      document.querySelectorAll('#vl-toolbar [data-tool]').forEach(function (b) {
        b.classList.toggle('is-active', b === btn);
      });
    });
  });

  var undoBtn = document.getElementById('vl-undo');
  if (undoBtn) {
    undoBtn.addEventListener('click', function () {
      if (!undoStack.length) return;
      labels = JSON.parse(undoStack.pop());
      syncCurrentLabels();
      redraw();
      renderBoxList();
      renderPredList();
    });
  }

  var saveBtn = document.getElementById('vl-save');
  if (saveBtn) {
    saveBtn.addEventListener('click', function () {
      if (!current) return;
      document.getElementById('vl-save-image-id').value = String(current.id);
      document.getElementById('vl-save-labels').value = JSON.stringify(labels);
      document.getElementById('vl-save-form').submit();
    });
  }

  canvas.addEventListener('contextmenu', function (ev) {
    ev.preventDefault();
    if (!current) return;
    var p = canvasPoint(ev);
    var hit = hitTestLabel(p.x, p.y);
    if (hit == null && hoverPeek != null) {
      // right-click on peeked pred → promote then open menu
      var pred = predictions[hoverPeek];
      if (pred) {
        pushUndo();
        labels.push({
          class_id: pred.class_id || 0,
          x: pred.x,
          y: pred.y,
          w: pred.w,
          h: pred.h,
          conf: boxConf(pred),
        });
        syncCurrentLabels();
        hit = labels.length - 1;
        hoverPeek = null;
        redraw();
        renderBoxList();
        renderPredList();
      }
    }
    if (hit == null) {
      hideCtx();
      return;
    }
    showCtx(ev.clientX, ev.clientY, hit);
  });

  document.addEventListener('click', function (ev) {
    if (!ctxMenu || ctxMenu.hidden) return;
    if (ctxMenu.contains(ev.target)) return;
    hideCtx();
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') hideCtx();
  });

  canvas.addEventListener('mousedown', function (ev) {
    if (!current || ev.button !== 0) return;
    hideCtx();
    var p = canvasPoint(ev);
    if (tool === 'null') {
      pushUndo();
      labels = [];
      selectedLabel = null;
      syncCurrentLabels();
      redraw();
      renderBoxList();
      renderPredList();
      return;
    }
    if (tool === 'box') {
      drawing = { x0: p.x, y0: p.y, x1: p.x, y1: p.y };
      return;
    }
    if (tool === 'drag') {
      if (hoverPeek != null) {
        pushUndo();
        var pred = predictions[hoverPeek];
        if (pred) {
          labels.push({
            class_id: pred.class_id || 0,
            x: pred.x,
            y: pred.y,
            w: pred.w,
            h: pred.h,
            conf: boxConf(pred),
          });
          syncCurrentLabels();
          selectedLabel = labels.length - 1;
          renderBoxList();
          renderPredList();
        }
        hoverPeek = null;
        redraw();
        return;
      }
      var hit = hitTestLabel(p.x, p.y);
      selectedLabel = hit;
      renderBoxList();
      redraw();
    }
  });

  canvas.addEventListener('mousemove', function (ev) {
    var p = canvasPoint(ev);
    if (drawing) {
      drawing.x1 = p.x;
      drawing.y1 = p.y;
      redraw();
      return;
    }
    if (tool === 'drag') {
      var hit = hitTestHidden(p.x, p.y);
      if (hit !== hoverPeek) {
        hoverPeek = hit;
        redraw();
      }
    }
  });

  canvas.addEventListener('mouseup', function (ev) {
    if (!drawing) return;
    var p = canvasPoint(ev);
    drawing.x1 = p.x;
    drawing.y1 = p.y;
    var x = Math.min(drawing.x0, drawing.x1);
    var y = Math.min(drawing.y0, drawing.y1);
    var w = Math.abs(drawing.x1 - drawing.x0);
    var h = Math.abs(drawing.y1 - drawing.y0);
    drawing = null;
    if (w > 4 && h > 4) {
      pushUndo();
      var box = pxToYolo(x, y, w, h);
      box.class_id = 0;
      labels.push(box);
      selectedLabel = labels.length - 1;
      syncCurrentLabels();
      renderBoxList();
      renderPredList();
    }
    redraw();
  });

  canvas.addEventListener('mouseleave', function () {
    if (hoverPeek != null) {
      hoverPeek = null;
      redraw();
    }
  });

  if (data.current_id) {
    selectImage(data.current_id);
  } else {
    renderStrip();
  }
})();
