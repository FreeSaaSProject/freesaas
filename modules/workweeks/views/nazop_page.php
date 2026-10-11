<?php
declare(strict_types=1);
/**
 * Full-page nazop (non-AJAX fallback).
 * @var string $html
 */
Assets::need('workweeks');
?>
<section class="page-head">
  <h1><?= te('workweeks.nazop.title') ?></h1>
</section>
<?= $html ?>
<script>
(function () {
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
  document.querySelectorAll('.nazop-table tbody tr').forEach(function (tr) {
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
})();
</script>
