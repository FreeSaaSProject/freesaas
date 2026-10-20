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
  var tool = 'box';
  var imgEl = new Image();
  var current = null;
  var labels = [];
  var predictions = [];
  var undoStack = [];
  var drawing = null;
  var dragId = null;
  var hoverPeek = null; // prediction index temporarily shown
  var conf = (data.project && data.project.conf) || 0.25;
  var classes = (data.project && data.project.classes) || [];

  function classColor(id) {
    var c = classes[id];
    return (c && c.color) || '#3b82f6';
  }

  function className(id) {
    var c = classes[id];
    return (c && c.name) || String(id);
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
    undoStack = [];
    document.getElementById('vl-infer-image-id').value = String(id);
    document.getElementById('vl-split-image-id').value = String(id);
    document.getElementById('vl-split').value = current.split || 'train';
    imgEl.onload = function () {
      canvas.width = imgEl.naturalWidth;
      canvas.height = imgEl.naturalHeight;
      redraw();
    };
    imgEl.src = current.url;
    renderStrip();
    renderBoxList();
  }

  function renderStrip() {
    strip.innerHTML = '';
    (data.images || []).forEach(function (im) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = current && current.id === im.id ? 'is-current' : '';
      var img = document.createElement('img');
      img.src = im.url;
      img.alt = im.filename;
      btn.appendChild(img);
      btn.addEventListener('click', function () {
        selectImage(im.id);
      });
      strip.appendChild(btn);
    });
  }

  function renderBoxList() {
    boxlist.innerHTML = '';
    labels.forEach(function (b, idx) {
      var li = document.createElement('li');
      li.textContent = className(b.class_id) + ' (' + (b.conf != null ? Number(b.conf).toFixed(2) : '—') + ')';
      var del = document.createElement('button');
      del.type = 'button';
      del.textContent = '×';
      del.addEventListener('click', function () {
        pushUndo();
        labels.splice(idx, 1);
        redraw();
        renderBoxList();
      });
      li.appendChild(del);
      boxlist.appendChild(li);
    });
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
      var c = p.conf != null ? Number(p.conf) : 1;
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
        var c = item.box.conf != null ? Number(item.box.conf) : 1;
        return c < conf;
      });
  }

  function hitTestHidden(mx, my) {
    var hits = hiddenPredictions();
    var best = null;
    var bestConf = -1;
    hits.forEach(function (item) {
      var r = yoloToPx(item.box);
      if (mx >= r.x && mx <= r.x + r.w && my >= r.y && my <= r.y + r.h) {
        var c = item.box.conf != null ? Number(item.box.conf) : 0;
        if (c > bestConf) {
          bestConf = c;
          best = item.idx;
        }
      }
    });
    return best;
  }

  function drawBox(b, opts) {
    var r = yoloToPx(b);
    ctx.save();
    ctx.strokeStyle = opts.color || classColor(b.class_id || 0);
    ctx.lineWidth = opts.peek ? 3 : 2;
    if (opts.dashed) {
      ctx.setLineDash([6, 4]);
    }
    if (opts.peek) {
      ctx.globalAlpha = 0.95;
      ctx.fillStyle = 'rgba(255,200,0,0.15)';
      ctx.fillRect(r.x, r.y, r.w, r.h);
    }
    ctx.strokeRect(r.x, r.y, r.w, r.h);
    ctx.setLineDash([]);
    ctx.fillStyle = ctx.strokeStyle;
    ctx.font = '12px sans-serif';
    var label = className(b.class_id || 0);
    if (b.conf != null) label += ' ' + Number(b.conf).toFixed(2);
    ctx.fillText(label, r.x + 2, Math.max(12, r.y - 2));
    ctx.restore();
  }

  function redraw() {
    if (!imgEl.complete) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(imgEl, 0, 0);
    visiblePredictions().forEach(function (item) {
      drawBox(item.box, {
        color: '#f59e0b',
        dashed: true,
        peek: item.peek,
      });
    });
    labels.forEach(function (b) {
      drawBox(b, { color: classColor(b.class_id || 0) });
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

  document.querySelectorAll('#vl-toolbar [data-tool]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      tool = btn.getAttribute('data-tool');
      document.querySelectorAll('#vl-toolbar [data-tool]').forEach(function (b) {
        b.classList.toggle('is-active', b === btn);
      });
    });
  });

  document.getElementById('vl-undo').addEventListener('click', function () {
    if (!undoStack.length) return;
    labels = JSON.parse(undoStack.pop());
    redraw();
    renderBoxList();
  });

  document.getElementById('vl-save').addEventListener('click', function () {
    if (!current) return;
    document.getElementById('vl-save-image-id').value = String(current.id);
    document.getElementById('vl-save-labels').value = JSON.stringify(labels);
    document.getElementById('vl-save-form').submit();
  });

  canvas.addEventListener('mousedown', function (ev) {
    if (!current) return;
    var p = canvasPoint(ev);
    if (tool === 'null') {
      pushUndo();
      labels = [];
      redraw();
      renderBoxList();
      return;
    }
    if (tool === 'box') {
      drawing = { x0: p.x, y0: p.y, x1: p.x, y1: p.y };
    }
    if (tool === 'drag') {
      // promote hover peek on click
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
            conf: pred.conf,
          });
          renderBoxList();
        }
        hoverPeek = null;
        redraw();
      }
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
      renderBoxList();
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
