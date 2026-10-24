<?php
declare(strict_types=1);
/**
 * @var string $message
 * @var string $error
 * @var list<array{id:string,title:string,html:string}> $panels
 * @var string $activeTab
 * @var bool $canWorkerToken
 * @var string $workerHtml
 */
$panels = is_array($panels ?? null) ? $panels : [];
$activeTab = (string)($activeTab ?? 'storage');
$canWorkerToken = !empty($canWorkerToken);
$workerHtml = (string)($workerHtml ?? '');
$activePanel = null;
foreach ($panels as $p) {
    if ((string)($p['id'] ?? '') === $activeTab) {
        $activePanel = $p;
        break;
    }
}
if ($activePanel === null && $panels !== []) {
    $activePanel = $panels[0];
    $activeTab = (string)($activePanel['id'] ?? 'storage');
}
?>
<section class="page-head">
  <p class="lede"><?= t('audio.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if (count($panels) > 1): ?>
  <nav class="audio-settings-tabs" role="tablist" aria-label="<?= e(t('audio.settings.tabs_aria')) ?>">
    <?php foreach ($panels as $p): ?>
      <?php
        $id = (string)($p['id'] ?? '');
        $isActive = $id === $activeTab;
        $q = ['tab' => $id];
        if ($id === 'coverage') {
            $covPos = max(0, (int)($_GET['cov_position_id'] ?? 0));
            $covAdm = max(0, (int)($_GET['cov_admin_id'] ?? 0));
            if ($covPos > 0) {
                $q['cov_position_id'] = $covPos;
            }
            if ($covAdm > 0) {
                $q['cov_admin_id'] = $covAdm;
            }
        }
        $href = '/audio/settings?' . http_build_query($q);
      ?>
      <a class="audio-settings-tab<?= $isActive ? ' is-active' : '' ?>"
         role="tab"
         aria-selected="<?= $isActive ? 'true' : 'false' ?>"
         href="<?= e($href) ?>"><?= e((string)($p['title'] ?? $id)) ?></a>
    <?php endforeach; ?>
  </nav>
<?php endif; ?>

<?php if ($activeTab === 'worker'): ?>
  <?php if ($activePanel !== null): ?>
    <div class="audio-settings-panel is-active">
      <?= (string)($activePanel['html'] ?? '') ?>
    </div>
  <?php endif; ?>
  <?php if ($canWorkerToken && $workerHtml !== ''): ?>
    <div class="audio-settings-worker-extra is-active">
      <?= $workerHtml ?>
    </div>
  <?php endif; ?>
<?php else: ?>
<form method="post" class="form-grid audio-settings-form">
  <input type="hidden" name="audio_settings_tab" value="<?= e($activeTab) ?>">
  <?php if ($activePanel !== null): ?>
    <div class="audio-settings-panel is-active" data-audio-settings-panel="<?= e((string)$activePanel['id']) ?>">
      <?= (string)($activePanel['html'] ?? '') ?>
    </div>
  <?php endif; ?>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
  </div>
</form>
<?php endif; ?>
