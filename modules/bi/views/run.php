<?php
declare(strict_types=1);
/** @var ReportFamily $family */
/** @var string $familyId */
/** @var array<string,mixed> $params */
/** @var bool $ready */
/** @var string $readyNote */
/** @var list<array{id:int,label:string}> $operators */
/** @var list<array{id:int,label:string}> $admins */
/** @var list<array{id:int,label:string}> $products */
$operators = $operators ?? [];
$admins = $admins ?? [];
$products = $products ?? [];
$workUrl = '/bi/work';
?>
<?php if (!$ready): ?>
  <p class="flash-err"><?= e($readyNote !== '' ? $readyNote : t('bi.err.not_ready')) ?></p>
<?php else: ?>
  <p class="bi-report-toolbar">
    <a class="btn-secondary" href="/bi/families/<?= e(rawurlencode($familyId)) ?>"><?= te('bi.index.edit') ?></a>
  </p>
  <?php
  $shareMode = false;
  require __DIR__ . '/_run_filters.php';
  ?>
  <script>
  window.biWorkConfig = {
    workUrl: <?= json_encode($workUrl, JSON_UNESCAPED_UNICODE) ?>,
    family: <?= json_encode($familyId, JSON_UNESCAPED_UNICODE) ?>,
    shareMode: false,
    action: 'run',
    autoRun: true
  };
  </script>
<?php endif; ?>
