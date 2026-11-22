<?php
declare(strict_types=1);
/** @var array<string,string> $cfg */
/** @var string $message */
/** @var string $error */
/** @var int $catalog_n */
?>
<section class="page-head">
  <p class="lede"><?= te('notify.settings.lede') ?></p>
  <p class="muted"><?= te('notify.settings.catalog_n', ['n' => (string)(int)$catalog_n]) ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <fieldset>
    <legend><?= te('notify.settings.sec.quiet') ?></legend>
    <label><?= te('notify.settings.quiet_from') ?>
      <input type="text" name="quiet_from" value="<?= e((string)($cfg['quiet_from'] ?? '')) ?>" placeholder="22:00" autocomplete="off">
    </label>
    <label><?= te('notify.settings.quiet_to') ?>
      <input type="text" name="quiet_to" value="<?= e((string)($cfg['quiet_to'] ?? '')) ?>" placeholder="08:00" autocomplete="off">
    </label>
  </fieldset>
  <fieldset>
    <legend><?= te('notify.settings.sec.engine') ?></legend>
    <label><?= te('notify.settings.journal_keep_days') ?>
      <input type="number" name="journal_keep_days" min="1" value="<?= e((string)($cfg['journal_keep_days'] ?? '90')) ?>">
    </label>
    <label><?= te('notify.settings.tick_limit') ?>
      <input type="number" name="tick_limit" min="1" value="<?= e((string)($cfg['tick_limit'] ?? '40')) ?>">
    </label>
    <label><?= te('notify.settings.default_platform') ?>
      <select name="default_platform">
        <?php foreach (['max', 'telegram'] as $p): ?>
          <option value="<?= e($p) ?>"<?= (($cfg['default_platform'] ?? '') === $p) ? ' selected' : '' ?>><?= e($p) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </fieldset>
  <div class="page-actions">
    <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
  </div>
</form>
