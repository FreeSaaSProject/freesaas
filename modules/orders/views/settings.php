<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var array<string,string> $cfg */
/** @var bool $bots_enabled */
$c = static function (string $k, string $d = '') use ($cfg): string {
    return (string)($cfg[$k] ?? $d);
};
$on = static function (string $k) use ($cfg): bool {
    return ($cfg[$k] ?? '0') === '1';
};
?>
<section class="page-head">
  <p class="lede"><?= te('orders.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<?php if (empty($bots_enabled)): ?>
  <div class="flash flash-warn"><?= te('orders.settings.bots_off') ?></div>
<?php endif; ?>

<form method="post" class="form-grid">
  <fieldset>
    <legend><?= te('orders.settings.sec.max_notify') ?></legend>
    <p class="muted"><?= te('orders.settings.max_notify_hint') ?></p>
    <label class="check">
      <input type="checkbox" name="max_notify_enabled" value="1"<?= $on('max_notify_enabled') ? ' checked' : '' ?>>
      <?= te('orders.settings.max_notify_enabled') ?>
    </label>
    <label><?= te('orders.settings.max_notify_chat_id') ?>
      <input type="text" name="max_notify_chat_id" value="<?= e($c('max_notify_chat_id')) ?>"
             autocomplete="off" placeholder="-7168…">
    </label>
    <label><?= te('orders.settings.max_notify_title') ?>
      <input type="text" name="max_notify_title" value="<?= e($c('max_notify_title')) ?>"
             autocomplete="off" placeholder="<?= e(t('orders.max_notify.title_new')) ?>">
    </label>
    <label><?= te('orders.settings.max_notify_title_cancel') ?>
      <input type="text" name="max_notify_title_cancel" value="<?= e($c('max_notify_title_cancel')) ?>"
             autocomplete="off" placeholder="<?= e(t('orders.max_notify.title_cancel')) ?>">
    </label>
  </fieldset>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn" href="/orders"><?= te('core.common.cancel') ?></a>
  </div>
</form>
