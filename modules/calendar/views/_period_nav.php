<?php
declare(strict_types=1);
/**
 * Top period pager: « select » Today.
 *
 * @var string $navMode 'day'|'week'
 * @var string $prevUrl
 * @var string $nextUrl
 * @var string $todayUrl
 * @var bool $showToday
 * @var string $prevLabel
 * @var string $nextLabel
 * @var string $todayLabel
 * @var string $selectId
 * @var string $selectAria
 * @var string $selectBasePath
 * @var list<array{value:string,label:string,current?:bool}> $periodOptions
 * @var string $selectedValue
 * @var string $optionCurrentClass
 * @var list<array{href:string,label:string,primary?:bool}> $periodCtas
 */
$navMode = ($navMode ?? '') === 'week' ? 'week' : 'day';
$periodOptions = $periodOptions ?? [];
$periodCtas = $periodCtas ?? [];
$showToday = !empty($showToday);
$optionCurrentClass = (string)($optionCurrentClass ?? ($navMode === 'week' ? 'is-current-week' : 'is-today'));
?>
<div class="page-actions calendar-period-nav" data-calendar-nav="<?= e($navMode) ?>"
     data-prev="<?= e($prevUrl) ?>"
     data-next="<?= e($nextUrl) ?>">
  <a class="btn-secondary" href="<?= e($prevUrl) ?>" title="<?= e($prevLabel) ?>" aria-label="<?= e($prevLabel) ?>">«</a>
  <select id="<?= e($selectId) ?>" aria-label="<?= e($selectAria) ?>" data-calendar-period-base="<?= e($selectBasePath) ?>">
    <?php foreach ($periodOptions as $opt): ?>
      <option value="<?= e($opt['value']) ?>"<?= $opt['value'] === $selectedValue ? ' selected' : '' ?><?= !empty($opt['current']) ? ' class="' . e($optionCurrentClass) . '"' : '' ?>><?= e($opt['label']) ?></option>
    <?php endforeach; ?>
  </select>
  <a class="btn-secondary" href="<?= e($nextUrl) ?>" title="<?= e($nextLabel) ?>" aria-label="<?= e($nextLabel) ?>">»</a>
  <?php if ($showToday): ?>
    <a class="btn-secondary" href="<?= e($todayUrl) ?>"><?= e($todayLabel) ?></a>
  <?php endif; ?>
  <?php foreach ($periodCtas as $cta): ?>
    <a class="<?= !empty($cta['primary']) ? 'btn-primary' : 'btn-secondary' ?> calendar-period-cta" href="<?= e((string)$cta['href']) ?>"><?= e((string)$cta['label']) ?></a>
  <?php endforeach; ?>
</div>
<script>
(function () {
  var sel = document.getElementById(<?= json_encode($selectId, JSON_UNESCAPED_UNICODE) ?>);
  if (sel) {
    sel.addEventListener('change', function () {
      if (!sel.value) return;
      var base = sel.getAttribute('data-calendar-period-base') || '';
      location.href = base + encodeURIComponent(sel.value);
    });
  }
  var nav = document.querySelector('[data-calendar-nav="<?= e($navMode) ?>"]');
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
})();
</script>
