<?php
/** @var string $date */
/** @var string $dateLabel */
/** @var string $prevDate */
/** @var string $nextDate */
/** @var string $today */
/** @var int $staffId */
/** @var bool $staffLocked */
/** @var bool $canAssign */
/** @var string $shiftFlash */
/** @var string $shiftError */
/** @var bool $canCreate */
/** @var list $dayColumns */
/** @var list<int> $hours */
/** @var int $gridHeight */
/** @var int $pxPerHour */
/** @var string $retUrl */
/** @var string $openShiftDay */
/** @var array<string,string> $dayStatsHtml */

$canAssign = !empty($canAssign);
$dayStatsHtml = $dayStatsHtml ?? [];
$shiftFlash = $shiftFlash ?? '';
$shiftError = $shiftError ?? '';
$openShiftDay = $openShiftDay ?? '';
$retUrl = $retUrl ?? ('/calendar/day/' . $date);

if ($canAssign) {
    Assets::need('workweeks');
}

$dayUrl = static function (string $d): string {
    return '/calendar/day/' . rawurlencode($d);
};
$newEventUrl = '/calendar/events/new?date=' . rawurlencode($date);
if ($staffId > 0) {
    $newEventUrl .= '&staff=' . $staffId;
}
$newEventUrl .= '&ret=' . rawurlencode($dayUrl($date));
?>
<section class="page-head page-head-compact">
  <div class="page-actions calendar-period-nav" data-calendar-nav="day"
       data-prev="<?= e($dayUrl($prevDate)) ?>"
       data-next="<?= e($dayUrl($nextDate)) ?>">
    <?php if (!empty($canCreate)): ?>
      <a class="btn-primary" href="<?= e($newEventUrl) ?>"><?= te('calendar.day.add_event') ?></a>
    <?php endif; ?>
    <a class="btn-secondary" href="<?= e($dayUrl($prevDate)) ?>" title="<?= te('calendar.day.prev') ?>" aria-label="<?= te('calendar.day.prev') ?>">«</a>
    <a class="btn-secondary" href="<?= e($dayUrl($nextDate)) ?>" title="<?= te('calendar.day.next') ?>" aria-label="<?= te('calendar.day.next') ?>">»</a>
    <a class="btn-secondary" href="/calendar/week/<?= e(rawurlencode($date)) ?>"><?= te('calendar.day.to_week') ?></a>
  </div>
</section>

<?php if ($shiftFlash !== ''): ?><div class="flash flash-ok"><?= e($shiftFlash) ?></div><?php endif; ?>
<?php if ($shiftError !== ''): ?><div class="flash flash-warn"><?= e($shiftError) ?></div><?php endif; ?>

<?php
$ariaLabel = t('calendar.day.aria');
$showShiftsRow = true;
require __DIR__ . '/_timeline.php';
?>

<div id="calendar-info" class="calendar-info" hidden></div>

<?php if ($canAssign): ?>
<dialog id="calendar-shift-dialog" class="calendar-shift-dialog">
  <div class="calendar-shift-dialog-bar">
    <strong id="calendar-shift-dialog-title"><?= te('workweeks.nazop.title') ?></strong>
    <button type="button" class="btn-secondary btn-sm" id="calendar-shift-dialog-close"><?= te('core.common.cancel') ?></button>
  </div>
  <div id="calendar-shift-dialog-body" class="calendar-shift-panel">
    <p class="muted"><?= te('calendar.week.nazop_loading') ?></p>
  </div>
</dialog>
<?php endif; ?>

<script>
(function () {
  var nav = document.querySelector('[data-calendar-nav="day"]');
  document.addEventListener('keydown', function (e) {
    if (!nav) return;
    if (e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) return;
    var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
    if (tag === 'input' || tag === 'textarea' || tag === 'select' || (e.target && e.target.isContentEditable)) return;
    if (document.getElementById('calendar-shift-dialog') && document.getElementById('calendar-shift-dialog').open) return;
    if (e.key === 'ArrowLeft') {
      e.preventDefault();
      location.href = nav.getAttribute('data-prev');
    } else if (e.key === 'ArrowRight') {
      e.preventDefault();
      location.href = nav.getAttribute('data-next');
    }
  });

  var info = document.getElementById('calendar-info');
  var touch = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
  var openChip = null;
  function hideInfo() {
    if (!info) return;
    info.hidden = true;
    info.innerHTML = '';
    openChip = null;
  }
  function showInfo(chip, fromTouch) {
    if (!info || !chip) return;
    var html = chip.getAttribute('data-info') || '';
    if (!html) return;
    info.innerHTML = html + (fromTouch
      ? '<div class="calendar-info-actions"><button type="button" class="btn-secondary btn-sm" data-info-close><?= te('core.common.cancel') ?></button></div>'
      : '');
    info.hidden = false;
    var rect = chip.getBoundingClientRect();
    info.style.top = (rect.bottom + window.scrollY + 4) + 'px';
    info.style.left = Math.max(8, rect.left + window.scrollX) + 'px';
    openChip = chip;
    var closer = info.querySelector('[data-info-close]');
    if (closer) closer.addEventListener('click', hideInfo);
  }
  document.querySelectorAll('.calendar-tl-chip').forEach(function (chip) {
    if (!touch) {
      chip.addEventListener('mouseenter', function () { showInfo(chip, false); });
      chip.addEventListener('mouseleave', function () { hideInfo(); });
    } else {
      chip.addEventListener('click', function (e) {
        if (openChip === chip) return;
        e.preventDefault();
        showInfo(chip, true);
      });
    }
  });
  document.addEventListener('click', function (e) {
    if (!info || info.hidden) return;
    if (info.contains(e.target)) return;
    if (openChip && openChip.contains(e.target)) return;
    hideInfo();
  });

  <?php if ($canAssign): ?>
  var dlg = document.getElementById('calendar-shift-dialog');
  var body = document.getElementById('calendar-shift-dialog-body');
  var titleEl = document.getElementById('calendar-shift-dialog-title');
  var closeBtn = document.getElementById('calendar-shift-dialog-close');
  var ret = <?= json_encode($retUrl, JSON_UNESCAPED_UNICODE) ?>;
  var currentDay = 'all';

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
        fetch(form.getAttribute('action') || '/workweeks/nazop', {
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
              throw new Error(<?= json_encode(t('calendar.week.nazop_error'), JSON_UNESCAPED_UNICODE) ?>);
            });
          }
          return r.text().then(function (html) {
            body.innerHTML = html;
            bindNazop(body);
          });
        }).catch(function () {
          body.innerHTML = '<div class="flash flash-warn"><?= te('calendar.week.nazop_error') ?></div>';
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
    body.innerHTML = '<p class="muted"><?= te('calendar.week.nazop_loading') ?></p>';
    if (typeof dlg.showModal === 'function') dlg.showModal();
    else dlg.setAttribute('open', 'open');
    var url = '/workweeks/nazop?fragment=1&day=' + encodeURIComponent(day)
      + '&ret=' + encodeURIComponent(ret);
    if (ops) url += '&ops=' + encodeURIComponent(ops);
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        body.innerHTML = html;
        bindNazop(body);
      })
      .catch(function () {
        body.innerHTML = '<div class="flash flash-warn"><?= te('calendar.week.nazop_error') ?></div>';
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
  var auto = <?= json_encode($openShiftDay) ?>;
  if (auto) loadNazop(auto, '');
  <?php endif; ?>
})();
</script>
