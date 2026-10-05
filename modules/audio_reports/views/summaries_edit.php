<?php
/**
 * @var bool $isNew
 * @var ?array $profile
 * @var string $configJson
 * @var string $labelsJson
 * @var bool $canEdit
 * @var string $flash
 * @var string $error
 */
$actionUrl = $isNew ? '/audio/summaries/new' : ('/audio/summaries/' . (int)($profile['ID'] ?? 0));
?>
<?php if ($flash !== ''): ?>
  <p class="flash-ok"><?= e($flash) ?></p>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <p class="flash-err"><?= e($error) ?></p>
<?php endif; ?>

<?php if (!$canEdit): ?>
  <p class="muted"><?= e((string)($profile['NAME'] ?? '')) ?> · pos <?= (int)($profile['ID_POSITION'] ?? 0) ?></p>
  <pre><?= e($configJson) ?></pre>
<?php else: ?>
  <form class="ar-sum-form" method="post" action="<?= e($actionUrl) ?>">
    <?= NavContext::hiddenField() ?>
    <label><?= te('audio_reports.summaries.field_name') ?>
      <input name="name" required value="<?= e((string)($profile['NAME'] ?? '')) ?>">
    </label>
    <label><?= te('audio_reports.summaries.field_position') ?>
      <input type="number" name="id_position" min="0" value="<?= (int)($profile['ID_POSITION'] ?? 0) ?>">
    </label>
    <label>
      <input type="checkbox" name="is_default" value="1"<?= !empty($profile['IS_DEFAULT']) ? ' checked' : '' ?>>
      <?= te('audio_reports.summaries.field_default') ?>
    </label>
    <label>
      <input type="checkbox" name="enabled" value="1"<?= ($isNew || !empty($profile['ENABLED'])) ? ' checked' : '' ?>>
      <?= te('audio_reports.summaries.field_enabled') ?>
    </label>
    <p class="muted"><?= te('audio_reports.summaries.config_hint') ?></p>
    <label><?= te('audio_reports.summaries.field_config') ?>
      <textarea name="config_json"><?= e($configJson) ?></textarea>
    </label>
    <label><?= te('audio_reports.summaries.field_labels') ?>
      <textarea name="labels_json"><?= e($labelsJson) ?></textarea>
    </label>
    <button type="submit" name="action" value="save"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/audio/summaries"><?= te('core.common.cancel') ?></a>
    <?php if (!$isNew): ?>
      <button class="btn-secondary" type="submit" name="action" value="delete"
              onclick="return confirm(<?= tj('audio_reports.summaries.confirm_delete') ?>)"><?= te('core.common.delete') ?></button>
    <?php endif; ?>
  </form>
<?php endif; ?>
