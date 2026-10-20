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
  // Full-page: reload with new ops columns (dialog does this via fetch).
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    var b = t.closest('button[data-nazop-ops]');
    if (!b || !b.closest('form.nazop-form')) return;
    e.preventDefault();
    var form = b.closest('form.nazop-form');
    var day = (form && form.getAttribute('data-nazop-day')) || 'all';
    var ret = (form && form.getAttribute('data-nazop-ret')) || '/workweeks';
    var ops = b.getAttribute('data-nazop-ops') || '';
    var url = '/workweeks/nazop?day=' + encodeURIComponent(day)
      + '&ret=' + encodeURIComponent(ret);
    if (ops) url += '&ops=' + encodeURIComponent(ops);
    location.href = url;
  });
})();
</script>
