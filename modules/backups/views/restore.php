<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var list<array<string,mixed>> $sets */
/** @var array<string,mixed>|null $result */
/** @var bool $available */
/** @var bool $empty */
?>
<section class="page-head">
  <p class="lede"><?= te('backups.restore.lede') ?></p>
  <div class="flash flash-warn"><?= te('backups.restore.alpha') ?></div>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if (empty($available)): ?>
  <div class="flash flash-warn"><?= te('backups.restore.locked') ?></div>
  <p class="muted"><?= te('backups.restore.locked_hint') ?></p>
  <div class="page-actions">
    <a class="btn" href="/backups"><?= te('core.common.cancel') ?></a>
  </div>
<?php else: ?>
<form method="post" class="form-grid" enctype="multipart/form-data">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <input type="hidden" name="step" value="deploy">

  <fieldset>
    <legend><?= te('backups.restore.source') ?></legend>
    <p class="muted"><?= te('backups.restore.source_hint') ?></p>
    <label><?= te('backups.restore.url') ?>
      <input type="url" name="url" value="" placeholder="https://example.com/instance/2026-01-01_03-30-00.tar.enc" autocomplete="off">
    </label>
    <label><?= te('backups.restore.pick') ?>
      <select name="remote_path">
        <option value="">—</option>
        <?php foreach ($sets as $set): ?>
          <option value="<?= e((string)$set['path']) ?>">
            <?= e((string)$set['path']) ?>
            <?php if (!empty($set['bytes'])): ?> (<?= e(BackupNotify::formatBytes((int)$set['bytes'])) ?>)<?php endif; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('backups.restore.local_file') ?>
      <input type="text" name="local_file" value="" placeholder="/path/on/server/stamp.tar.enc" autocomplete="off">
    </label>
    <label><?= te('backups.restore.passphrase') ?>
      <input type="password" name="passphrase" value="" autocomplete="new-password">
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('backups.restore.run') ?></button>
    <a class="btn" href="/backups"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php endif; ?>

<?php if (is_array($result)): ?>
  <pre class="mono"><?= e(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') ?></pre>
<?php endif; ?>
