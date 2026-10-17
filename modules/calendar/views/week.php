<?php
declare(strict_types=1);
/** @var string $monday */
/** @var string $rangeLabel */
/** @var string $prevWeek */
/** @var string $nextWeek */
/** @var string $today */
/** @var list $dayColumns */
/** @var list<int> $hours */
/** @var int $gridHeight */
/** @var int $pxPerHour */
/** @var int $staffId */
/** @var bool $staffLocked */
/** @var bool $canCreate */
/** @var bool $canAssign */
/** @var bool $canTables */
/** @var list<array{value:string,label:string}> $weekOptions */
/** @var string $shiftFlash */
/** @var string $shiftError */
/** @var string $openShiftDay */
/** @var string $weekRet */
/** @var array<string,string> $dayStatsHtml */

// Nazop dialog loads workweeks HTML via AJAX — queue CSS on the parent page.
Assets::need('workweeks');
$dayStatsHtml = $dayStatsHtml ?? [];

$canAssign = !empty($canAssign);
$canTables = !empty($canTables);
$weekOptions = $weekOptions ?? [];
$shiftFlash = $shiftFlash ?? '';
$shiftError = $shiftError ?? '';
$openShiftDay = $openShiftDay ?? '';
$weekRet = $weekRet ?? ('/calendar/week/' . $monday);

$weekUrl = static function (string $d): string {
    return '/calendar/week/' . rawurlencode($d);
};
$dayUrl = static function (string $d): string {
    return '/calendar/day/' . rawurlencode($d);
};
$dayLinkDate = $monday;
foreach ($dayColumns as $col) {
    if (!empty($col['day']['is_today'])) {
        $dayLinkDate = $today;
        break;
    }
}
$canBook = class_exists(ModuleManager::class, false)
    && ModuleManager::isEnabled('booking')
    && Auth::can('booking.edit')
    && class_exists(BookingConfig::class, false)
    && BookingConfig::showCalendarCta();
$newBookingUrl = '/booking/new?date=' . rawurlencode($dayLinkDate)
    . ($staffId > 0 ? ('&staff=' . $staffId) : '')
    . '&ret=' . rawurlencode($weekUrl($monday));
?>
<section class="page-head page-head-compact">
  <div class="page-actions calendar-period-nav" data-calendar-nav="week"
       data-prev="<?= e($weekUrl($prevWeek)) ?>"
       data-next="<?= e($weekUrl($nextWeek)) ?>">
    <a class="btn-secondary" href="<?= e($weekUrl($prevWeek)) ?>" title="<?= te('calendar.week.prev') ?>" aria-label="<?= te('calendar.week.prev') ?>">«</a>
    <select id="calendar-week-select" aria-label="<?= te('calendar.week.pick') ?>">
      <?php foreach ($weekOptions as $opt): ?>
        <option value="<?= e($opt['value']) ?>"<?= $opt['value'] === $monday ? ' selected' : '' ?><?= !empty($opt['current']) ? ' class="is-current-week"' : '' ?>><?= e($opt['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <a class="btn-secondary" href="<?= e($weekUrl($nextWeek)) ?>" title="<?= te('calendar.week.next') ?>" aria-label="<?= te('calendar.week.next') ?>">»</a>
    <?php
    $todayMonday = class_exists(CalendarWeek::class, false)
        ? date('Y-m-d', CalendarWeek::mondayOf(strtotime($today . ' 00:00:00') ?: time()))
        : $today;
    if ($monday !== $todayMonday):
    ?>
      <a class="btn-secondary" href="<?= e($weekUrl($todayMonday)) ?>"><?= te('calendar.day.today') ?></a>
    <?php endif; ?>
    <a class="btn-secondary" href="<?= e($dayUrl($dayLinkDate)) ?>"><?= te('calendar.week.to_day') ?></a>
    <?php if ($canBook): ?>
      <a class="btn-secondary" href="<?= e($newBookingUrl) ?>"><?= te('booking.calendar.add_booking') ?></a>
    <?php endif; ?>
  </div>
</section>

<?php if ($shiftFlash !== ''): ?><div class="flash flash-ok"><?= e($shiftFlash) ?></div><?php endif; ?>
<?php if ($shiftError !== ''): ?><div class="flash flash-warn"><?= e($shiftError) ?></div><?php endif; ?>

<?php
$retUrl = $weekRet;
$ariaLabel = t('calendar.week.aria');
$showShiftsRow = true;
require __DIR__ . '/_timeline.php';
?>

<div id="calendar-info" class="calendar-info" hidden></div>

<?php if ($canAssign || $canTables): ?>
<dialog id="calendar-shift-dialog" class="calendar-shift-dialog">
  <div class="calendar-shift-dialog-bar">
    <strong id="calendar-shift-dialog-title"><?= $canAssign ? te('workweeks.nazop.title') : te('workweeks.nazcab.title') ?></strong>
    <button type="button" class="btn-secondary btn-sm" id="calendar-shift-dialog-close"><?= te('core.common.cancel') ?></button>
  </div>
  <div id="calendar-shift-dialog-body" class="calendar-shift-panel">
    <p class="muted"><?= $canAssign ? te('calendar.week.nazop_loading') : te('workweeks.nazcab.loading') ?></p>
  </div>
</dialog>
<?php endif; ?>

<script>
(function () {
  var weekSel = document.getElementById('calendar-week-select');
  if (weekSel) {
    weekSel.addEventListener('change', function () {
      if (!weekSel.value) return;
      location.href = '/calendar/week/' + encodeURIComponent(weekSel.value);
    });
  }

  var nav = document.querySelector('[data-calendar-nav="week"]');
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
  var hideTimer = null;

  function cancelHideInfo() {
    if (hideTimer) {
      clearTimeout(hideTimer);
      hideTimer = null;
    }
  }
  function hideInfo() {
    cancelHideInfo();
    if (!info) return;
    info.hidden = true;
    info.innerHTML = '';
    openChip = null;
  }
  function scheduleHideInfo() {
    cancelHideInfo();
    hideTimer = setTimeout(hideInfo, 120);
  }
  function showInfo(chip, fromTouch) {
    if (!info || !chip) return;
    cancelHideInfo();
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

  if (info && !touch) {
    info.addEventListener('mouseenter', cancelHideInfo);
    info.addEventListener('mouseleave', scheduleHideInfo);
  }

  document.querySelectorAll('.calendar-tl-chip').forEach(function (chip) {
    if (!touch) {
      chip.addEventListener('mouseenter', function () { showInfo(chip, false); });
      chip.addEventListener('mouseleave', scheduleHideInfo);
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

  <?php if ($canAssign || $canTables): ?>
  var dlg = document.getElementById('calendar-shift-dialog');
  var body = document.getElementById('calendar-shift-dialog-body');
  var titleEl = document.getElementById('calendar-shift-dialog-title');
  var closeBtn = document.getElementById('calendar-shift-dialog-close');
  var ret = <?= json_encode($weekRet, JSON_UNESCAPED_UNICODE) ?>;
  var currentDay = 'all';

  function closeDlg() {
    if (!dlg) return;
    if (typeof dlg.close === 'function') dlg.close();
    else dlg.removeAttribute('open');
  }

  if (closeBtn) closeBtn.addEventListener('click', closeDlg);
  if (dlg) {
    dlg.addEventListener('click', function (e) {
      var t = e.target;
      if (!t || !t.closest) return;
      if (t.closest('[data-nazop-close]') || t.closest('[data-nazcab-close]')) {
        e.preventDefault();
        e.stopPropagation();
        closeDlg();
      }
    });
  }

  <?php if ($canAssign): ?>
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

  document.querySelectorAll('[data-shift-open]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      loadNazop(btn.getAttribute('data-shift-open') || 'all', '');
    });
  });

  var auto = <?= json_encode($openShiftDay) ?>;
  if (auto) loadNazop(auto, '');
  <?php endif; ?>

  <?php if ($canTables): ?>
  function bindNazcab(root) {
    if (!root) return;
    root.querySelectorAll('form.nazcab-form').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(form);
        var btn = form.querySelector('button[type="submit"]');
        if (btn) btn.disabled = true;
        fetch(form.getAttribute('action') || '/workweeks/nazcab', {
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
              throw new Error(<?= json_encode(t('workweeks.nazcab.error'), JSON_UNESCAPED_UNICODE) ?>);
            });
          }
          return r.text().then(function (html) {
            body.innerHTML = html;
            bindNazcab(body);
          });
        }).catch(function () {
          body.innerHTML = '<div class="flash flash-warn"><?= te('workweeks.nazcab.error') ?></div>';
        }).finally(function () {
          if (btn) btn.disabled = false;
        });
      });
    });
  }

  function loadNazcab(day) {
    if (!dlg || !body) return;
    day = day || 'all';
    currentDay = day;
    if (titleEl) {
      titleEl.textContent = day === 'all'
        ? <?= json_encode(t('workweeks.nazcab.title_all'), JSON_UNESCAPED_UNICODE) ?>
        : (<?= json_encode(t('workweeks.nazcab.title_day'), JSON_UNESCAPED_UNICODE) ?> + ': ' + day);
    }
    body.innerHTML = '<p class="muted"><?= te('workweeks.nazcab.loading') ?></p>';
    if (typeof dlg.showModal === 'function') dlg.showModal();
    else dlg.setAttribute('open', 'open');
    var url = '/workweeks/nazcab?fragment=1&day=' + encodeURIComponent(day)
      + '&ret=' + encodeURIComponent(ret);
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        body.innerHTML = html;
        bindNazcab(body);
      })
      .catch(function () {
        body.innerHTML = '<div class="flash flash-warn"><?= te('workweeks.nazcab.error') ?></div>';
      });
  }

  document.querySelectorAll('[data-table-open]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      loadNazcab(btn.getAttribute('data-table-open') || 'all');
    });
  });
  <?php endif; ?>
  <?php endif; ?>
})();
</script>
