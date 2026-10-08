<?php
/**
 * Shared nazop dialog + loader (calendar / audio calendar).
 *
 * @var string $retUrl
 * @var string $openShiftDay optional auto-open day|all
 * @var string $dialogId
 * @var string $nazopEndpoint
 * @var string $loadingText
 * @var string $errorText
 */
Assets::need('workweeks');
$retUrl = (string)($retUrl ?? '/workweeks');
$openShiftDay = (string)($openShiftDay ?? '');
$dialogId = (string)($dialogId ?? 'workweeks-shift-dialog');
$nazopEndpoint = (string)($nazopEndpoint ?? '/workweeks/nazop');
$loadingText = (string)($loadingText ?? t('workweeks.nazop.loading'));
$errorText = (string)($errorText ?? t('workweeks.nazop.error'));
$bodyId = $dialogId . '-body';
$titleId = $dialogId . '-title';
$closeId = $dialogId . '-close';
?>
<dialog id="<?= e($dialogId) ?>" class="calendar-shift-dialog workweeks-shift-dialog">
  <div class="calendar-shift-dialog-bar">
    <strong id="<?= e($titleId) ?>"><?= te('workweeks.nazop.title') ?></strong>
    <button type="button" class="btn-secondary btn-sm" id="<?= e($closeId) ?>"><?= te('core.common.cancel') ?></button>
  </div>
  <div id="<?= e($bodyId) ?>" class="calendar-shift-panel">
    <p class="muted"><?= e($loadingText) ?></p>
  </div>
</dialog>
<script>
(function () {
  var dlg = document.getElementById(<?= json_encode($dialogId, JSON_UNESCAPED_UNICODE) ?>);
  var body = document.getElementById(<?= json_encode($bodyId, JSON_UNESCAPED_UNICODE) ?>);
  var titleEl = document.getElementById(<?= json_encode($titleId, JSON_UNESCAPED_UNICODE) ?>);
  var closeBtn = document.getElementById(<?= json_encode($closeId, JSON_UNESCAPED_UNICODE) ?>);
  var ret = <?= json_encode($retUrl, JSON_UNESCAPED_UNICODE) ?>;
  var endpoint = <?= json_encode($nazopEndpoint, JSON_UNESCAPED_UNICODE) ?>;
  var currentDay = 'all';
  var errHtml = <?= json_encode('<div class="flash flash-warn">' . e($errorText) . '</div>', JSON_UNESCAPED_UNICODE) ?>;
  var loadingHtml = <?= json_encode('<p class="muted">' . e($loadingText) . '</p>', JSON_UNESCAPED_UNICODE) ?>;

  function closeDlg() {
    if (!dlg) return;
    if (typeof dlg.close === 'function') dlg.close();
    else dlg.removeAttribute('open');
  }

  function syncUncheckedFromDefault(tr) {
    if (!tr) return;
    var def = tr.querySelector('select[name^="price["]');
    if (!def) return;
    tr.querySelectorAll('.nazop-cell').forEach(function (cell) {
      var cb = cell.querySelector('input[type="checkbox"]');
      var sel = cell.querySelector('select.nazop-op-price');
      if (cb && sel && !cb.checked) sel.value = def.value;
    });
  }

  function bindNazop(root) {
    if (!root) return;
    root.querySelectorAll('button[data-nazop-ops]').forEach(function (b) {
      b.addEventListener('click', function () {
        loadNazop(currentDay, b.getAttribute('data-nazop-ops') || '');
      });
    });
    root.querySelectorAll('button[data-nazop-check]').forEach(function (b) {
      b.addEventListener('click', function () {
        var on = b.getAttribute('data-nazop-check') === '1';
        root.querySelectorAll('.nazop-table input[type="checkbox"]:not([disabled])').forEach(function (cb) {
          cb.checked = on;
        });
      });
    });
    root.querySelectorAll('.nazop-table tbody tr').forEach(function (tr) {
      var def = tr.querySelector('select[name^="price["]');
      if (def) {
        def.addEventListener('change', function () { syncUncheckedFromDefault(tr); });
      }
      tr.querySelectorAll('.nazop-cell').forEach(function (cell) {
        var cb = cell.querySelector('input[type="checkbox"]');
        var sel = cell.querySelector('select.nazop-op-price');
        if (!cb || !sel) return;
        cb.addEventListener('change', function () {
          if (!cb.checked) return;
          var d = tr.querySelector('select[name^="price["]');
          if (d) sel.value = d.value;
        });
      });
    });
    root.querySelectorAll('form.nazop-form').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(form);
        var btn = form.querySelector('button[type="submit"]');
        if (btn) btn.disabled = true;
        fetch(form.getAttribute('action') || endpoint, {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin'
        }).then(function (r) {
          var ct = (r.headers.get('content-type') || '');
          if (ct.indexOf('application/json') !== -1) {
            return r.json().then(function (data) {
              if (data && data.redirect) {
                location.href = data.redirect;
                return;
              }
              throw new Error('nazop');
            });
          }
          return r.text().then(function (html) {
            body.innerHTML = html;
            bindNazop(body);
          });
        }).catch(function () {
          body.innerHTML = errHtml;
        }).finally(function () {
          if (btn) btn.disabled = false;
        });
      });
    });
  }

  function loadNazop(day, ops) {
    if (!dlg || !body) return;
    currentDay = day;
    if (titleEl) {
      titleEl.textContent = day === 'all'
        ? <?= json_encode(t('workweeks.nazop.title_all'), JSON_UNESCAPED_UNICODE) ?>
        : (<?= json_encode(t('workweeks.nazop.title_day'), JSON_UNESCAPED_UNICODE) ?> + ': ' + day);
    }
    body.innerHTML = loadingHtml;
    if (typeof dlg.showModal === 'function') dlg.showModal();
    else dlg.setAttribute('open', 'open');
    var url = endpoint + '?fragment=1&day=' + encodeURIComponent(day)
      + '&ret=' + encodeURIComponent(ret);
    if (ops) url += '&ops=' + encodeURIComponent(ops);
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        body.innerHTML = html;
        bindNazop(body);
      })
      .catch(function () {
        body.innerHTML = errHtml;
      });
  }

  if (closeBtn) closeBtn.addEventListener('click', closeDlg);
  if (dlg) {
    dlg.addEventListener('click', function (e) {
      var t = e.target;
      if (!t || !t.closest) return;
      if (t.closest('[data-nazop-close]')) {
        e.preventDefault();
        e.stopPropagation();
        closeDlg();
      }
    });
  }
  document.querySelectorAll('[data-shift-open]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      loadNazop(btn.getAttribute('data-shift-open') || 'all', '');
    });
  });
  var auto = <?= json_encode($openShiftDay, JSON_UNESCAPED_UNICODE) ?>;
  if (auto) loadNazop(auto, '');
})();
</script>
