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
  var wrap = document.getElementById('vl-canvas-wrap');
  var stage = document.getElementById('vl-stage');
  var strip = document.getElementById('vl-strip');
  var boxlist = document.getElementById('vl-boxlist');
  var predlist = document.getElementById('vl-predlist');
  var predMeta = document.getElementById('vl-pred-meta');
  var predStatus = document.getElementById('vl-pred-status');
  var boxCount = document.getElementById('vl-box-count');
  var ctxMenu = document.getElementById('vl-ctx');
  var filterEl = document.getElementById('vl-filter');
  var bulkEl = document.getElementById('vl-bulk');
  var bulkCount = document.getElementById('vl-bulk-count');
  var zoomFitBtn = document.getElementById('vl-zoom-fit');

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
  var marked = {};
  var filterMode = 'all';
  var lastMarkAnchor = null;
  var fitScale = 1;
  var userZoom = 1;
  var minZoom = 0.25;
  var maxZoom = 8;
  var pinch = null;

  var i18n = {
    promote: root.getAttribute('data-i18n-promote') || 'Promote',
    delete: root.getAttribute('data-i18n-delete') || 'Delete',
    hidden: root.getAttribute('data-i18n-hidden') || 'hidden',
    shown: root.getAttribute('data-i18n-shown') || 'shown',
    noPreds: root.getAttribute('data-i18n-nopreds') || 'No model cache',
    marked: root.getAttribute('data-i18n-marked') || 'Marked',
  };

  var FILTERS = [
    { id: 'all', label: root.getAttribute('data-i18n-filter-all') || 'All' },
    { id: 'unlabeled', label: root.getAttribute('data-i18n-filter-unlabeled') || 'No labels' },
    { id: 'labeled', label: root.getAttribute('data-i18n-filter-labeled') || 'With labels' },
    { id: 'cache', label: root.getAttribute('data-i18n-filter-cache') || 'With model data' },
    { id: 'train', label: root.getAttribute('data-i18n-filter-train') || 'Train' },
    { id: 'val', label: root.getAttribute('data-i18n-filter-val') || 'Val' },
    { id: 'test', label: root.getAttribute('data-i18n-filter-test') || 'Test' },
  ];

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
  function classLetter(id) {
    var c = findClass(id);
    var n = (c && (c.name || c.label)) || String(id);
    return n.charAt(0).toLowerCase();
  }
  function boxConf(b) {
    if (!b) return null;
    if (b.conf != null && b.conf !== '') return Number(b.conf);
    if (b.confidence != null && b.confidence !== '') return Number(b.confidence);
    return null;
  }
  function labelCount(im) {
    if (im.label_count != null) return Number(im.label_count);
    return (im.labels || []).length;
  }
  function hasPreds(im) {
    if (im.has_preds != null) return !!im.has_preds;
    return (im.predictions || []).length > 0;
  }

  function findImage(id) {
    var list = data.images || [];
    for (var i = 0; i < list.length; i++) {
      if (list[i].id === id) return list[i];
    }
    return null;
  }
  function imageIndex(id) {
    var list = data.images || [];
    for (var i = 0; i < list.length; i++) {
      if (list[i].id === id) return i;
    }
    return -1;
  }

  function pushUndo() {
    undoStack.push(JSON.stringify(labels));
    if (undoStack.length > 30) undoStack.shift();
  }

  function syncCurrentLabels() {
    if (!current) return;
    current.labels = labels.slice();
    current.label_count = labels.length;
    var cc = {};
    labels.forEach(function (b) {
      var cid = b.class_id || 0;
      cc[cid] = (cc[cid] || 0) + 1;
    });
    current.class_counts = cc;
  }

  function applyDisplaySize() {
    if (!canvas.width || !canvas.height) return;
    var scale = fitScale * userZoom;
    var w = Math.max(1, Math.round(canvas.width * scale));
    var h = Math.max(1, Math.round(canvas.height * scale));
    canvas.style.width = w + 'px';
    canvas.style.height = h + 'px';
    if (zoomFitBtn) {
      zoomFitBtn.textContent = Math.round(userZoom * 100) + '%';
    }
  }

  function computeFit() {
    if (!wrap || !canvas.width || !canvas.height) {
      fitScale = 1;
      return;
    }
    var pad = 8;
    var availW = Math.max(40, wrap.clientWidth - pad);
    var availH = Math.max(40, wrap.clientHeight - pad);
    fitScale = Math.min(availW / canvas.width, availH / canvas.height);
    if (!isFinite(fitScale) || fitScale <= 0) fitScale = 1;
  }

  function fitAndDraw() {
    computeFit();
    applyDisplaySize();
    redraw();
  }

  function setUserZoom(z, centerClient) {
    var prev = userZoom;
    userZoom = Math.min(maxZoom, Math.max(minZoom, z));
    applyDisplaySize();
    if (wrap && centerClient && prev > 0) {
      var ratio = userZoom / prev;
      var rect = wrap.getBoundingClientRect();
      var cx = centerClient.x - rect.left + wrap.scrollLeft;
      var cy = centerClient.y - rect.top + wrap.scrollTop;
      wrap.scrollLeft = cx * ratio - (centerClient.x - rect.left);
      wrap.scrollTop = cy * ratio - (centerClient.y - rect.top);
    }
  }

  function filteredImages() {
    return (data.images || []).filter(function (im) {
      var lc = labelCount(im);
      if (filterMode === 'unlabeled') return lc === 0;
      if (filterMode === 'labeled') return lc > 0;
      if (filterMode === 'cache') return hasPreds(im);
      if (filterMode === 'train' || filterMode === 'val' || filterMode === 'test') {
        return (im.split || 'train') === filterMode;
      }
      return true;
    });
  }

  function updateBulkBar() {
    var ids = Object.keys(marked).filter(function (k) { return marked[k]; });
    if (!bulkEl) return;
    if (!ids.length) {
      bulkEl.hidden = true;
      return;
    }
    bulkEl.hidden = false;
    if (bulkCount) bulkCount.textContent = i18n.marked + ' (' + ids.length + ')';
  }

  function submitBulk(op, split) {
    var ids = Object.keys(marked)
      .filter(function (k) { return marked[k]; })
      .map(Number);
    if (!ids.length) return;
    if (op === 'delete' || op === 'clear_labels') {
      if (!window.confirm(i18n.delete + ' ×' + ids.length + '?')) return;
    }
    document.getElementById('vl-bulk-op').value = op;
    document.getElementById('vl-bulk-split-val').value = split || 'train';
    document.getElementById('vl-bulk-ids').value = JSON.stringify(ids);
    document.getElementById('vl-bulk-form').submit();
  }

  function renderFilter() {
    if (!filterEl || filterEl.options.length) return;
    FILTERS.forEach(function (f) {
      var opt = document.createElement('option');
      opt.value = f.id;
      opt.textContent = f.label;
      filterEl.appendChild(opt);
    });
    filterEl.value = filterMode;
    filterEl.addEventListener('change', function () {
      filterMode = filterEl.value;
      renderStrip({ force: true });
    });
  }

  function summaryHtml(im) {
    var counts = im.class_counts || {};
    var keys = Object.keys(counts);
    if (!keys.length) {
      return labelCount(im) === 0 ? '<span class="muted">·</span>' : '';
    }
    return (
      '<span class="video-label-thumb-summary">' +
      keys
        .sort(function (a, b) {
          return Number(a) - Number(b);
        })
        .map(function (k) {
          return (
            '<span style="color:' +
            classColor(k) +
            '">' +
            classLetter(k) +
            ':' +
            counts[k] +
            '</span>'
          );
        })
        .join(' ') +
      '</span>'
    );
  }

  function updateStripRowState(row, im, listOffset) {
    if (!row || !im) return;
    row.classList.toggle('is-current', !!(current && current.id === im.id));
    row.classList.toggle('is-marked', !!marked[im.id]);
    var num = row.querySelector('.video-label-thumb-num');
    if (num) num.textContent = String(listOffset + 1);
    var meta = row.querySelector('.video-label-thumb-meta');
    if (meta) {
      var keepNum = meta.querySelector('.video-label-thumb-num');
      meta.innerHTML = '';
      if (keepNum) meta.appendChild(keepNum);
      else {
        var n = document.createElement('span');
        n.className = 'video-label-thumb-num';
        n.textContent = String(listOffset + 1);
        meta.appendChild(n);
      }
      var wrap = document.createElement('div');
      wrap.innerHTML = summaryHtml(im);
      while (wrap.firstChild) meta.appendChild(wrap.firstChild);
    }
    var markBtn = row.querySelector('.video-label-mark');
    if (markBtn) {
      markBtn.classList.toggle('is-on', !!marked[im.id]);
      markBtn.textContent = marked[im.id] ? '☑' : '☐';
    }
    var thumb = row.querySelector('.video-label-thumb');
    if (thumb) {
      thumb.className = 'video-label-thumb split-' + (im.split || 'train');
      var dot = thumb.querySelector('.dot');
      if (dot) dot.className = 'dot ' + (labelCount(im) > 0 ? 'is-ok' : 'is-warn');
      var cache = thumb.querySelector('.cache');
      if (hasPreds(im)) {
        if (!cache) {
          cache = document.createElement('span');
          cache.className = 'cache';
          cache.textContent = 'C';
          var badges = thumb.querySelector('.video-label-thumb-badges');
          if (badges) badges.appendChild(cache);
        }
      } else if (cache) {
        cache.remove();
      }
    }
  }

  /** Soft: reuse existing <img> nodes (no reload). Full rebuild only when filter set changes. */
  function renderStrip(opts) {
    if (!strip) return;
    opts = opts || {};
    var force = !!opts.force;
    var list = filteredImages();
    var wanted = {};
    list.forEach(function (im) {
      wanted[im.id] = true;
    });

    if (force) {
      strip.innerHTML = '';
    } else {
      Array.prototype.slice.call(strip.querySelectorAll('.video-label-thumb-row')).forEach(function (row) {
        var id = parseInt(row.dataset.id, 10);
        if (!wanted[id]) row.remove();
      });
    }

    list.forEach(function (im, listOffset) {
      var row = strip.querySelector('.video-label-thumb-row[data-id="' + im.id + '"]');
      if (row) {
        updateStripRowState(row, im, listOffset);
        if (row.nextSibling !== strip.children[listOffset]) {
          strip.insertBefore(row, strip.children[listOffset] || null);
        }
        return;
      }

      row = document.createElement('div');
      row.className = 'video-label-thumb-row';
      row.dataset.id = String(im.id);

      var meta = document.createElement('div');
      meta.className = 'video-label-thumb-meta';
      var num = document.createElement('span');
      num.className = 'video-label-thumb-num';
      num.textContent = String(listOffset + 1);
      meta.appendChild(num);
      var sumWrap = document.createElement('div');
      sumWrap.innerHTML = summaryHtml(im);
      while (sumWrap.firstChild) meta.appendChild(sumWrap.firstChild);

      var markBtn = document.createElement('button');
      markBtn.type = 'button';
      markBtn.className = 'video-label-mark';
      markBtn.addEventListener('click', function (ev) {
        ev.stopPropagation();
        if (ev.shiftKey && lastMarkAnchor != null) {
          markRange(lastMarkAnchor, im.id);
        } else {
          marked[im.id] = !marked[im.id];
          lastMarkAnchor = im.id;
        }
        renderStrip();
        updateBulkBar();
      });

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'video-label-thumb';
      var img = document.createElement('img');
      img.alt = im.filename;
      img.loading = 'lazy';
      img.decoding = 'async';
      img.src = im.url;
      btn.appendChild(img);
      var badges = document.createElement('span');
      badges.className = 'video-label-thumb-badges';
      var dot = document.createElement('span');
      dot.className = 'dot';
      badges.appendChild(dot);
      btn.appendChild(badges);
      btn.addEventListener('click', function (ev) {
        if (ev.shiftKey) {
          if (lastMarkAnchor != null) markRange(lastMarkAnchor, im.id);
          else {
            marked[im.id] = true;
            lastMarkAnchor = im.id;
          }
          renderStrip();
          updateBulkBar();
          return;
        }
        selectImage(im.id);
      });

      row.appendChild(meta);
      row.appendChild(markBtn);
      row.appendChild(btn);
      strip.insertBefore(row, strip.children[listOffset] || null);
      updateStripRowState(row, im, listOffset);
    });

    updateBulkBar();
    var cur = strip.querySelector('.is-current');
    if (cur && cur.scrollIntoView) {
      try {
        cur.scrollIntoView({ block: 'nearest' });
      } catch (err) {
        cur.scrollIntoView(false);
      }
    }
  }

  function markRange(a, b) {
    var list = filteredImages();
    var ia = -1;
    var ib = -1;
    list.forEach(function (im, i) {
      if (im.id === a) ia = i;
      if (im.id === b) ib = i;
    });
    if (ia < 0 || ib < 0) {
      marked[b] = true;
      return;
    }
    var lo = Math.min(ia, ib);
    var hi = Math.max(ia, ib);
    for (var i = lo; i <= hi; i++) {
      marked[list[i].id] = true;
    }
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
      userZoom = 1;
      fitAndDraw();
    };
    imgEl.src = current.url;
    renderStrip();
    renderBoxList();
    renderPredList();
    updatePredStatus();
    if (history.replaceState) {
      var u = new URL(window.location.href);
      u.searchParams.set('image', String(id));
      history.replaceState({}, '', u.toString());
    }
  }

  function renderBoxList() {
    if (!boxlist) return;
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
      del.addEventListener('click', function (ev) {
        ev.stopPropagation();
        pushUndo();
        labels.splice(idx, 1);
        if (selectedLabel === idx) selectedLabel = null;
        else if (selectedLabel != null && selectedLabel > idx) selectedLabel--;
        syncCurrentLabels();
        redraw();
        renderBoxList();
        renderStrip();
      });
      li.appendChild(swatch);
      li.appendChild(meta);
      li.appendChild(del);
      boxlist.appendChild(li);
    });
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
  function isCoveredByLabel(pred) {
    for (var i = 0; i < labels.length; i++) {
      if (iou(pred, labels[i]) >= 0.55) return true;
    }
    return false;
  }

  function renderPredList() {
    if (!predlist) return;
    predlist.innerHTML = '';
    var above = 0;
    var below = 0;
    var covered = 0;
    predictions.forEach(function (p, idx) {
      var c = boxConf(p);
      if (isCoveredByLabel(p)) {
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
        renderStrip();
        updatePredStatus();
      });
      li.appendChild(swatch);
      li.appendChild(meta);
      predlist.appendChild(li);
    });
    if (predMeta) {
      if (!predictions.length) predMeta.textContent = i18n.noPreds;
      else {
        predMeta.textContent =
          above + ' ' + i18n.shown + ' · ' + below + ' ' + i18n.hidden +
          (covered ? ' · ' + covered + ' overlapped' : '');
      }
    }
  }

  function updatePredStatus() {
    if (!predStatus) return;
    var withConf = predictions.filter(function (p) { return boxConf(p) != null; }).length;
    if (!predictions.length || !withConf) {
      predStatus.hidden = false;
      predStatus.textContent = i18n.noPreds;
    } else {
      predStatus.hidden = true;
    }
  }

  function yoloToPx(b) {
    var W = canvas.width;
    var H = canvas.height;
    var bw = b.w * W;
    var bh = b.h * H;
    return { x: b.x * W - bw / 2, y: b.y * H - bh / 2, w: bw, h: bh };
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
      if (c == null) {
        if (hoverPeek === idx) out.push({ box: p, idx: idx, peek: true });
        return;
      }
      if (c >= conf) out.push({ box: p, idx: idx, peek: false });
      else if (hoverPeek === idx) out.push({ box: p, idx: idx, peek: true });
    });
    return out;
  }
  function hiddenPredictions() {
    return predictions
      .map(function (p, idx) { return { box: p, idx: idx }; })
      .filter(function (item) {
        if (isCoveredByLabel(item.box)) return false;
        var c = boxConf(item.box);
        return c == null || c < conf;
      });
  }
  function hitTestHidden(mx, my) {
    var best = null;
    var bestConf = -1;
    hiddenPredictions().forEach(function (item) {
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
      if (mx >= r.x && mx <= r.x + r.w && my >= r.y && my <= r.y + r.h) return i;
    }
    return null;
  }

  function drawBox(b, opts) {
    var r = yoloToPx(b);
    ctx.save();
    ctx.strokeStyle = opts.color || classColor(b.class_id || 0);
    ctx.lineWidth = opts.peek || opts.selected ? 3 : 2;
    if (opts.dashed) ctx.setLineDash([6, 4]);
    if (opts.peek) {
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
      drawBox(item.box, { color: '#f59e0b', dashed: true, peek: item.peek });
    });
    labels.forEach(function (b, idx) {
      drawBox(b, { color: classColor(b.class_id || 0), selected: selectedLabel === idx });
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
    classes.forEach(function (c, i) {
      if (!c) return;
      var id = Number(c.id != null ? c.id : i);
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'video-label-ctx-item';
      var sw = document.createElement('span');
      sw.className = 'video-label-swatch';
      sw.style.background = c.color || '#888';
      btn.appendChild(sw);
      btn.appendChild(document.createTextNode(c.label || c.name || String(id)));
      if (Number(labels[labelIdx].class_id) === id) btn.classList.add('is-current');
      btn.addEventListener('click', function () {
        pushUndo();
        labels[labelIdx].class_id = id;
        syncCurrentLabels();
        hideCtx();
        redraw();
        renderBoxList();
        renderStrip();
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
      renderStrip();
    });
    ctxMenu.appendChild(del);
    ctxMenu.hidden = false;
    var mw = ctxMenu.offsetWidth;
    var mh = ctxMenu.offsetHeight;
    ctxMenu.style.left = Math.max(8, Math.min(clientX, window.innerWidth - mw - 8)) + 'px';
    ctxMenu.style.top = Math.max(8, Math.min(clientY, window.innerHeight - mh - 8)) + 'px';
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
      renderStrip();
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

  document.getElementById('vl-zoom-in').addEventListener('click', function () {
    setUserZoom(userZoom * 1.25);
  });
  document.getElementById('vl-zoom-out').addEventListener('click', function () {
    setUserZoom(userZoom / 1.25);
  });
  document.getElementById('vl-zoom-fit').addEventListener('click', function () {
    userZoom = 1;
    fitAndDraw();
    if (wrap) {
      wrap.scrollLeft = 0;
      wrap.scrollTop = 0;
    }
  });

  if (wrap) {
    wrap.addEventListener(
      'wheel',
      function (ev) {
        if (!(ev.ctrlKey || ev.metaKey)) return;
        ev.preventDefault();
        var factor = ev.deltaY < 0 ? 1.08 : 1 / 1.08;
        setUserZoom(userZoom * factor, { x: ev.clientX, y: ev.clientY });
      },
      { passive: false }
    );
    wrap.addEventListener(
      'touchstart',
      function (ev) {
        if (ev.touches.length === 2) {
          var dx = ev.touches[0].clientX - ev.touches[1].clientX;
          var dy = ev.touches[0].clientY - ev.touches[1].clientY;
          pinch = {
            dist: Math.hypot(dx, dy),
            zoom: userZoom,
            cx: (ev.touches[0].clientX + ev.touches[1].clientX) / 2,
            cy: (ev.touches[0].clientY + ev.touches[1].clientY) / 2,
          };
        }
      },
      { passive: true }
    );
    wrap.addEventListener(
      'touchmove',
      function (ev) {
        if (!pinch || ev.touches.length !== 2) return;
        ev.preventDefault();
        var dx = ev.touches[0].clientX - ev.touches[1].clientX;
        var dy = ev.touches[0].clientY - ev.touches[1].clientY;
        var dist = Math.hypot(dx, dy);
        if (pinch.dist <= 0) return;
        var cx = (ev.touches[0].clientX + ev.touches[1].clientX) / 2;
        var cy = (ev.touches[0].clientY + ev.touches[1].clientY) / 2;
        setUserZoom(pinch.zoom * (dist / pinch.dist), { x: cx, y: cy });
      },
      { passive: false }
    );
    wrap.addEventListener('touchend', function (ev) {
      if (ev.touches && ev.touches.length >= 2) return;
      pinch = null;
    });
  }

  window.addEventListener('resize', function () {
    fitAndDraw();
  });

  canvas.addEventListener('contextmenu', function (ev) {
    ev.preventDefault();
    if (!current) return;
    var p = canvasPoint(ev);
    var hit = hitTestLabel(p.x, p.y);
    if (hit == null && hoverPeek != null) {
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
        renderStrip();
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
    if (ev.target && /INPUT|TEXTAREA|SELECT/.test(ev.target.tagName)) return;
    if (ev.key === 'b' || ev.key === 'B') {
      tool = 'box';
      document.querySelectorAll('#vl-toolbar [data-tool]').forEach(function (b) {
        b.classList.toggle('is-active', b.getAttribute('data-tool') === 'box');
      });
    }
    if (ev.key === 'd' || ev.key === 'D') {
      tool = 'drag';
      document.querySelectorAll('#vl-toolbar [data-tool]').forEach(function (b) {
        b.classList.toggle('is-active', b.getAttribute('data-tool') === 'drag');
      });
    }
    if (ev.key === 'ArrowUp' || ev.key === 'ArrowDown') {
      ev.preventDefault();
      var list = filteredImages();
      var cur = current ? list.findIndex(function (im) { return im.id === current.id; }) : -1;
      var next = ev.key === 'ArrowUp' ? cur - 1 : cur + 1;
      if (next >= 0 && next < list.length) selectImage(list[next].id);
    }
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
      renderStrip();
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
          renderStrip();
        }
        hoverPeek = null;
        redraw();
        return;
      }
      selectedLabel = hitTestLabel(p.x, p.y);
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
      renderStrip();
    }
    redraw();
  });

  canvas.addEventListener('mouseleave', function () {
    if (hoverPeek != null) {
      hoverPeek = null;
      redraw();
    }
  });

  var fileImages = document.getElementById('vl-file-images');
  if (fileImages) {
    fileImages.addEventListener('change', function () {
      if (fileImages.files && fileImages.files.length) {
        document.getElementById('vl-upload-form').submit();
      }
    });
  }
  var fileZip = document.getElementById('vl-file-zip');
  if (fileZip) {
    fileZip.addEventListener('change', function () {
      if (fileZip.files && fileZip.files.length) {
        document.getElementById('vl-zip-form').submit();
      }
    });
  }

  document.getElementById('vl-mark-all').addEventListener('click', function () {
    filteredImages().forEach(function (im) {
      marked[im.id] = true;
    });
    renderStrip();
    updateBulkBar();
  });
  document.getElementById('vl-mark-clear').addEventListener('click', function () {
    marked = {};
    renderStrip();
    updateBulkBar();
  });
  document.getElementById('vl-bulk-split-btn').addEventListener('click', function () {
    submitBulk('split', document.getElementById('vl-bulk-split').value);
  });
  var autoSplitBtn = document.getElementById('vl-bulk-auto-split');
  if (autoSplitBtn) {
    autoSplitBtn.addEventListener('click', function () {
      submitBulk('auto_split');
    });
  }
  document.getElementById('vl-bulk-clear-labels').addEventListener('click', function () {
    submitBulk('clear_labels');
  });
  document.getElementById('vl-bulk-clear-preds').addEventListener('click', function () {
    submitBulk('clear_preds');
  });
  document.getElementById('vl-bulk-delete').addEventListener('click', function () {
    submitBulk('delete');
  });

  renderFilter();
  if (data.current_id) selectImage(data.current_id);
  else renderStrip();
})();
