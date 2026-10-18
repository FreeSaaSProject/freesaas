<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var array<string,string> $cfg */
/** @var bool $bots_enabled */
/** @var list<string> $status_options */
$c = static function (string $k, string $d = '') use ($cfg): string {
    return (string)($cfg[$k] ?? $d);
};
$on = static function (string $k) use ($cfg): bool {
    return ($cfg[$k] ?? '0') === '1';
};
$statusOptions = is_array($status_options ?? null) ? $status_options : OrdersRepository::editableStatuses();
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
  <fieldset>
    <legend><?= te('orders.settings.sec.arrive') ?></legend>
    <p class="muted"><?= te('orders.settings.arrive_hint') ?></p>
    <label class="check">
      <input type="checkbox" name="arrive_transition_enabled" value="1"<?= $on('arrive_transition_enabled') ? ' checked' : '' ?>>
      <?= te('orders.settings.arrive_transition_enabled') ?>
    </label>
    <label><?= te('orders.settings.arrive_match_status') ?>
      <select name="arrive_match_status">
        <?php foreach ($statusOptions as $st): ?>
          <option value="<?= e((string)$st) ?>"<?= $c('arrive_match_status') === (string)$st ? ' selected' : '' ?>>
            <?= e(OrdersRepository::statusLabel((string)$st)) ?> (<?= e((string)$st) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('orders.settings.arrive_set_status') ?>
      <select name="arrive_set_status">
        <?php foreach ($statusOptions as $st): ?>
          <option value="<?= e((string)$st) ?>"<?= $c('arrive_set_status') === (string)$st ? ' selected' : '' ?>>
            <?= e(OrdersRepository::statusLabel((string)$st)) ?> (<?= e((string)$st) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('orders.settings.arrive_window_sec') ?>
      <input type="number" name="arrive_window_sec" min="3600" max="604800" step="3600"
             value="<?= e($c('arrive_window_sec', '10800')) ?>">
    </label>
  </fieldset>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn" href="/orders"><?= te('core.common.cancel') ?></a>
  </div>
</form>
