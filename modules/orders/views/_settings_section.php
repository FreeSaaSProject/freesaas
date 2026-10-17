<?php
declare(strict_types=1);
/**
 * Embeddable orders settings fieldset for /calendar/settings.
 *
 * @var array<string,string> $cfg
 * @var bool $bots_enabled
 */
$cfg = is_array($cfg ?? null) ? $cfg : [];
$bots_enabled = !empty($bots_enabled);
$c = static function (string $k, string $d = '') use ($cfg): string {
    return (string)($cfg[$k] ?? $d);
};
$on = static function (string $k) use ($cfg): bool {
    return ($cfg[$k] ?? '0') === '1';
};
?>
<input type="hidden" name="orders_settings_save" value="1">
<?php if (!$bots_enabled): ?>
  <div class="flash flash-warn"><?= te('orders.settings.bots_off') ?></div>
<?php endif; ?>
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
