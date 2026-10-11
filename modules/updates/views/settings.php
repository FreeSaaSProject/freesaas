<?php
declare(strict_types=1);
/** @var array<string,mixed> $cfg */
/** @var bool $canSave */
/** @var string $message */
/** @var string $error */
$cfg = is_array($cfg ?? null) ? $cfg : [];
$canSave = !empty($canSave);
$message = (string)($message ?? '');
$error = (string)($error ?? '');
$channel = (string)($cfg['channel'] ?? 'main');
?>
<section class="page-head">
  <p class="lede"><?= te('updates.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid" style="max-width:36rem">
  <label><?= te('updates.settings.repo') ?>
    <input type="text" name="repo" value="<?= e((string)($cfg['repo'] ?? '')) ?>"
           placeholder="owner/repo" <?= $canSave ? '' : 'readonly' ?> required>
  </label>
  <label><?= te('updates.settings.channel') ?>
    <select name="channel" <?= $canSave ? '' : 'disabled' ?>>
      <option value="main"<?= $channel === 'main' ? ' selected' : '' ?>><?= te('updates.settings.channel_main') ?></option>
      <option value="stable"<?= $channel === 'stable' ? ' selected' : '' ?>><?= te('updates.settings.channel_stable') ?></option>
      <option value="latest"<?= $channel === 'latest' ? ' selected' : '' ?>><?= te('updates.settings.channel_latest') ?></option>
    </select>
  </label>
  <p class="muted form-hint"><?= te('updates.settings.channel_help') ?></p>
  <label><?= te('updates.settings.ref') ?>
    <input type="text" name="ref" value="<?= e((string)($cfg['ref'] ?? '')) ?>"
           placeholder="<?= te('updates.settings.ref_ph') ?>" <?= $canSave ? '' : 'readonly' ?>>
  </label>
  <label><?= te('updates.settings.asset') ?>
    <input type="text" name="asset" value="<?= e((string)($cfg['asset'] ?? '')) ?>"
           placeholder="freesaas-0.3.3.zip" <?= $canSave ? '' : 'readonly' ?>>
  </label>
  <label><?= te('updates.settings.token') ?>
    <input type="password" name="token" value="" autocomplete="new-password"
           placeholder="<?= !empty($cfg['token_set']) ? te('updates.settings.token_kept') : te('updates.settings.token_ph') ?>"
           <?= $canSave ? '' : 'readonly' ?>>
  </label>
  <?php if ($canSave && !empty($cfg['token_set'])): ?>
  <label class="check">
    <input type="checkbox" name="clear_token" value="1">
    <?= te('updates.settings.clear_token') ?>
  </label>
  <?php endif; ?>
  <label class="check">
    <input type="checkbox" name="db_backup_before_migrate" value="1"
      <?= !empty($cfg['db_backup_before_migrate']) ? ' checked' : '' ?>
      <?= $canSave ? '' : 'disabled' ?>>
    <?= te('updates.settings.db_backup') ?>
  </label>
  <p class="muted form-hint"><?= te('updates.settings.db_backup_help') ?></p>
  <input type="hidden" name="provider" value="github">
  <div class="page-actions">
    <?php if ($canSave): ?>
      <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
    <?php endif; ?>
    <a class="btn-secondary" href="/updates"><?= te('updates.settings.back') ?></a>
  </div>
</form>
