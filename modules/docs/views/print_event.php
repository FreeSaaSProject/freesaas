<?php
declare(strict_types=1);
/** @var string $title */
/** @var int $eventId */
/** @var list<array{id:int,name:string,printed:bool,html:string,orientation:string,format:string}> $sheets */
/** @var bool $allPrinted */
/** @var string $error */
/** @var string $backUrl */
/** @var bool $autoPrint */
/** @var array<int,true>|null $preselectIds */
$autoPrint = !empty($autoPrint);
$preselectIds = isset($preselectIds) && is_array($preselectIds) ? $preselectIds : null;
$backUrl = (string)($backUrl ?? ('/calendar/events/' . (int)$eventId . '/edit'));
$first = $sheets[0] ?? null;
$page = $first !== null
    ? (($first['format'] === 'A5' ? 'A5' : 'A4') . ' ' . ($first['orientation'] === 'landscape' ? 'landscape' : 'portrait'))
    : 'A4 portrait';
?><!DOCTYPE html>
<html lang="<?= e(I18n::locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<style>
@page{size:<?= e($page) ?>;margin:14mm}
body{font-family:"PT Serif","Times New Roman",serif;font-size:11.5pt;color:#000;background:#eef0f2;margin:0}
.docs-print-toolbar{font-family:system-ui,sans-serif;font-size:14px;display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;padding:.75rem 1rem;background:#fff;border-bottom:1px solid #d5d9de;position:sticky;top:0;z-index:2}
.docs-print-toolbar .muted{color:#667}
.docs-print-toolbar .warn{color:#a40}
.docs-print-toolbar button,.docs-print-toolbar a{font:inherit;padding:.4rem .8rem;border:1px solid #b9c0c8;border-radius:6px;background:#fff;color:#123;text-decoration:none;cursor:pointer}
.docs-print-toolbar .primary{background:#1f4f8a;border-color:#1f4f8a;color:#fff}
.docs-print-toolbar .primary:disabled{opacity:.45;cursor:not-allowed}
.docs-print-sheet-wrap{max-width:190mm;margin:1rem auto}
.docs-print-sheet-bar{font-family:system-ui,sans-serif;font-size:13px;display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;padding:.45rem .65rem;margin-bottom:.35rem;background:#f6f7f9;border:1px solid #d5d9de;border-radius:6px}
.docs-print-sheet-bar label{display:inline-flex;align-items:center;gap:.4rem;cursor:pointer;user-select:none}
.docs-print-sheet-bar .docs-print-sheet-name{font-weight:600;color:#123}
.docs-print-sheet-bar .docs-print-sheet-meta{color:#667}
.docs-print-sheet{background:#fff;padding:14mm;box-shadow:0 1px 4px rgba(0,0,0,.12)}
.docs-print-sheet-wrap+.docs-print-sheet-wrap{page-break-before:always;break-before:page}
.docs-print-sheet-wrap.is-excluded{opacity:.55}
.docs-print-sheet-wrap.is-excluded .docs-print-sheet{outline:2px dashed #9aa3ad;outline-offset:-2px}
.docs-page-break{page-break-before:always;break-before:page;height:0}
@media screen{.docs-page-break{border-top:1px dashed #bbb;margin:1.5em 0}}
.docs-print-sheet h2{font-size:15pt;margin:.2em 0 .6em}
.docs-print-sheet h3{font-size:12pt;margin:1em 0 .3em}
.docs-print-fields,.docs-print-items{width:100%;border-collapse:collapse;margin:.3em 0 .8em}
.docs-print-fields th{width:34%;text-align:left;vertical-align:top;font-weight:600;padding:.25em .5em .25em 0}
.docs-print-fields td{vertical-align:top;padding:.25em 0;border-bottom:1px dotted #999}
.docs-print-items th,.docs-print-items td{border:1px solid #777;padding:.2em .4em}
.docs-print-qr{margin-top:.6em;display:inline-flex;align-items:center;gap:.4em}
.docs-print-qr svg{width:48px;height:48px;flex:0 0 auto}
.docs-f025-procedures{margin:.3em 0;padding-left:1.4em}
.docs-print-sheet table[border] th,.docs-print-sheet table[border] td{border:1px solid #000;vertical-align:top}
.docs-print-sheet table[border]{border-collapse:collapse;margin:.3em 0 .6em}
@media print{
  body{background:#fff}
  .docs-print-toolbar,.docs-print-sheet-bar{display:none!important}
  .docs-print-sheet-wrap{max-width:none;margin:0}
  .docs-print-sheet-wrap.is-excluded{display:none!important}
  .docs-print-sheet{box-shadow:none;margin:0;padding:0;max-width:none;outline:none}
  .docs-print-sheet-wrap+.docs-print-sheet-wrap:not(.is-excluded){page-break-before:always;break-before:page}
}
</style>
</head>
<body>
<div class="docs-print-toolbar" id="docs-print-toolbar"
     data-i18n-count="<?= e(t('docs.print_event.count', ['n' => '{n}'])) ?>"
     data-i18n-selected="<?= e(t('docs.print_event.selected', ['n' => '{n}', 'total' => '{total}'])) ?>">
  <a href="<?= e($backUrl) ?>">&larr; <?= te('docs.print_event.back') ?></a>
  <?php if ($sheets === []): ?>
    <span class="muted"><?= te('docs.print_event.empty') ?></span>
  <?php elseif ($allPrinted): ?>
    <button type="button" class="primary" id="docs-print-do"><?= te('docs.print.do') ?></button>
    <span class="muted" id="docs-print-count"><?= te('docs.print_event.count', ['n' => count($sheets)]) ?></span>
  <?php else: ?>
    <form method="post" id="docs-print-freeze-form" style="display:inline">
      <?php if (NavContext::returnUrl() !== ''): ?>
        <input type="hidden" name="ret" value="<?= e(NavContext::returnUrl()) ?>">
      <?php endif; ?>
      <input type="hidden" name="action" value="freeze">
      <div id="docs-print-ids"></div>
      <button type="submit" class="primary" id="docs-print-freeze"><?= te('docs.print_event.freeze_selected') ?></button>
    </form>
    <button type="button" id="docs-print-do"><?= te('docs.print.do') ?></button>
    <span class="muted" id="docs-print-count"><?= te('docs.print_event.selected', ['n' => count($sheets), 'total' => count($sheets)]) ?></span>
    <span class="muted"><?= te('docs.print_event.exclude_hint') ?></span>
  <?php endif; ?>
  <?php if ($error !== ''): ?><span class="warn"><?= e($error) ?></span><?php endif; ?>
</div>
<?php foreach ($sheets as $sheet):
    $sid = (int)$sheet['id'];
    $included = $preselectIds === null || isset($preselectIds[$sid]);
?>
  <div class="docs-print-sheet-wrap<?= $included ? '' : ' is-excluded' ?>" data-doc-id="<?= $sid ?>">
    <div class="docs-print-sheet-bar">
      <label>
        <input type="checkbox" class="docs-print-include"<?= $included ? ' checked' : '' ?>>
        <?= te('docs.print_event.include') ?>
      </label>
      <span class="docs-print-sheet-name"><?= e((string)$sheet['name']) ?></span>
      <span class="docs-print-sheet-meta">#<?= (int)$sheet['id'] ?><?= !empty($sheet['printed']) ? ' · ' . te('docs.print_event.already_printed') : '' ?></span>
    </div>
    <div class="docs-print-sheet">
      <?= $sheet['html'] ?>
    </div>
  </div>
<?php endforeach; ?>
<script>
(function () {
  var wraps = Array.prototype.slice.call(document.querySelectorAll('.docs-print-sheet-wrap'));
  var toolbar = document.getElementById('docs-print-toolbar');
  var countEl = document.getElementById('docs-print-count');
  var freezeForm = document.getElementById('docs-print-freeze-form');
  var idsBox = document.getElementById('docs-print-ids');
  var freezeBtn = document.getElementById('docs-print-freeze');
  var printBtn = document.getElementById('docs-print-do');
  var tplCount = toolbar ? (toolbar.getAttribute('data-i18n-count') || 'Documents: {n}') : '';
  var tplSelected = toolbar ? (toolbar.getAttribute('data-i18n-selected') || '{n} / {total}') : '';

  function selectedWraps() {
    return wraps.filter(function (w) {
      var cb = w.querySelector('.docs-print-include');
      return cb && cb.checked;
    });
  }

  function sync() {
    wraps.forEach(function (w) {
      var cb = w.querySelector('.docs-print-include');
      w.classList.toggle('is-excluded', !(cb && cb.checked));
    });
    var sel = selectedWraps();
    var n = sel.length;
    var total = wraps.length;
    if (countEl) {
      if (freezeForm) {
        countEl.textContent = tplSelected.replace('{n}', String(n)).replace('{total}', String(total));
      } else {
        countEl.textContent = tplCount.replace('{n}', String(n));
      }
    }
    if (freezeBtn) freezeBtn.disabled = n < 1;
    if (printBtn) printBtn.disabled = n < 1;
    if (idsBox) {
      idsBox.innerHTML = '';
      sel.forEach(function (w) {
        var id = w.getAttribute('data-doc-id') || '';
        if (!id) return;
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'ids[]';
        inp.value = id;
        idsBox.appendChild(inp);
      });
    }
  }

  wraps.forEach(function (w) {
    var cb = w.querySelector('.docs-print-include');
    if (cb) cb.addEventListener('change', sync);
  });
  if (printBtn) {
    printBtn.addEventListener('click', function () {
      sync();
      if (selectedWraps().length < 1) return;
      window.print();
    });
  }
  if (freezeForm) {
    freezeForm.addEventListener('submit', function (e) {
      sync();
      if (selectedWraps().length < 1) {
        e.preventDefault();
      }
    });
  }
  sync();

  <?php if ($autoPrint): ?>
  window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 200);
  });
  <?php endif; ?>
})();
</script>
</body>
</html>
