<?php
declare(strict_types=1);
/** @var ReportFamily $family */
/** @var string $familyId */
/** @var array<string,mixed> $params */
/** @var bool $ready */
/** @var string $readyNote */
/** @var bool $canManage */
/** @var string $shareToken */
/** @var array<string,mixed>|null $share */
/** @var list<array{id:int,label:string}> $operators */
/** @var list<array{id:int,label:string}> $admins */
/** @var list<array{id:int,label:string}> $products */
$operators = $operators ?? [];
$admins = $admins ?? [];
$products = $products ?? [];
$canManage = !empty($canManage);
$shareToken = (string)($shareToken ?? '');
$workUrl = '/bi/work';
?>
<?php if (!$ready): ?>
  <p class="flash-err"><?= e($readyNote !== '' ? $readyNote : t('bi.err.not_ready')) ?></p>
<?php else: ?>
  <?php if ($canManage && !ReportRegistry::isCodedId($familyId)): ?>
    <p class="bi-report-toolbar">
      <a class="btn-secondary" href="/bi/families/<?= e(rawurlencode($familyId)) ?>"><?= te('bi.index.edit') ?></a>
    </p>
  <?php elseif (is_array($share ?? null) && (int)($share['DT_EXPIRE'] ?? 0) > 0): ?>
    <p class="muted"><?= te('bi.share.expires') ?>:
      <strong><?= e(date('Y-m-d H:i', (int)$share['DT_EXPIRE'])) ?></strong>
    </p>
  <?php endif; ?>
  <?php
  $shareMode = false;
  $formAction = '/bi/run';
  require __DIR__ . '/_run_filters.php';
  ?>
  <script>
  window.biWorkConfig = {
    workUrl: <?= json_encode($workUrl, JSON_UNESCAPED_UNICODE) ?>,
    family: <?= json_encode($familyId, JSON_UNESCAPED_UNICODE) ?>,
    shareMode: false,
    shareToken: <?= json_encode($shareToken, JSON_UNESCAPED_UNICODE) ?>,
    action: 'run',
    autoRun: true
  };
  </script>
<?php endif; ?>
