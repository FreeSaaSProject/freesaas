<?php
declare(strict_types=1);
/** @var ReportFamily $family */
/** @var string $familyId */
/** @var array<string,mixed> $params */
/** @var array<string,mixed> $share */
/** @var string $token */
$workUrl = '/bi/s/' . rawurlencode($token);
$exp = (int)($share['DT_EXPIRE'] ?? 0);
$operators = $operators ?? BiFilterOptions::staffOperators();
$admins = $admins ?? BiFilterOptions::staffAdmins();
$products = $products ?? BiFilterOptions::products();
?>
<div class="bi-share-shell">
  <header class="bi-share-head">
    <h1><?= e(t($family->titleKey())) ?></h1>
    <?php if (!empty($share['LABEL'])): ?>
      <p class="muted"><?= e((string)$share['LABEL']) ?></p>
    <?php endif; ?>
    <p class="bi-share-exp"><?= te('bi.share.expires') ?>:
      <strong><?= $exp > 0 ? e(date('Y-m-d H:i', $exp)) : '—' ?></strong>
    </p>
  </header>

  <?php
  $shareMode = true;
  $formAction = $workUrl;
  require __DIR__ . '/_run_filters.php';
  ?>

  <script>
  window.biWorkConfig = {
    workUrl: <?= json_encode($workUrl, JSON_UNESCAPED_UNICODE) ?>,
    family: <?= json_encode($familyId, JSON_UNESCAPED_UNICODE) ?>,
    shareMode: true,
    action: 'run',
    autoRun: true
  };
  </script>
</div>
