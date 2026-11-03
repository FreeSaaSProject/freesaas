<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var array<string,string> $cfg */
/** @var bool $bots_enabled */
/** @var list<array{chat_id:string,title:string}> $known_chats */
$c = static function (string $k, string $d = '') use ($cfg): string {
    return (string)($cfg[$k] ?? $d);
};
$on = static function (string $k) use ($cfg): bool {
    return ($cfg[$k] ?? '0') === '1';
};
$chats = is_array($known_chats ?? null) ? $known_chats : [];
?>
<section class="page-head">
  <p class="lede"><?= te('vacations.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<?php if (empty($bots_enabled)): ?>
  <div class="flash flash-warn"><?= te('vacations.settings.bots_off') ?></div>
<?php endif; ?>

<form method="post" class="form-grid">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <fieldset>
    <legend><?= te('vacations.settings.sec.remind') ?></legend>
    <p class="muted"><?= te('vacations.settings.remind_hint') ?></p>
    <label class="check">
      <input type="checkbox" name="remind_enabled" value="1"<?= $on('remind_enabled') ? ' checked' : '' ?>>
      <?= te('vacations.settings.remind_enabled') ?>
    </label>
    <label><?= te('vacations.settings.remind_days_before') ?>
      <input type="text" name="remind_days_before" value="<?= e($c('remind_days_before', '5')) ?>"
             autocomplete="off" placeholder="5">
    </label>
    <label><?= te('vacations.settings.remind_daily_at') ?>
      <input type="text" name="remind_daily_at" value="<?= e($c('remind_daily_at', '09:00')) ?>"
             autocomplete="off" placeholder="09:00" pattern="\d{1,2}:\d{2}">
    </label>
    <label><?= te('vacations.settings.remind_chat_id') ?>
      <input type="text" name="remind_chat_id" value="<?= e($c('remind_chat_id')) ?>"
             autocomplete="off" placeholder="-7168…" list="vacations-known-chats">
    </label>
    <?php if ($chats !== []): ?>
      <datalist id="vacations-known-chats">
        <?php foreach ($chats as $ch): ?>
          <option value="<?= e($ch['chat_id']) ?>"><?= e($ch['title']) ?></option>
        <?php endforeach; ?>
      </datalist>
      <p class="muted"><?= te('vacations.settings.known_chats_hint') ?></p>
    <?php endif; ?>
    <label><?= te('vacations.settings.remind_platform') ?>
      <select name="remind_platform">
        <option value="max"<?= $c('remind_platform') === 'max' ? ' selected' : '' ?>>Max</option>
        <option value="telegram"<?= $c('remind_platform') === 'telegram' ? ' selected' : '' ?>>Telegram</option>
      </select>
    </label>
    <label><?= te('vacations.settings.remind_types') ?>
      <input type="text" name="remind_types" value="<?= e($c('remind_types', '1,2')) ?>"
             autocomplete="off" placeholder="1,2">
    </label>
    <p class="muted"><?= te('vacations.settings.remind_types_hint') ?></p>
    <label class="check">
      <input type="checkbox" name="remind_show_user_only" value="1"<?= $on('remind_show_user_only') ? ' checked' : '' ?>>
      <?= te('vacations.settings.remind_show_user_only') ?>
    </label>
    <label><?= te('vacations.settings.remind_message') ?>
      <textarea name="remind_message" rows="3" placeholder="<?= e(t('vacations.remind.message')) ?>"><?= e($c('remind_message')) ?></textarea>
    </label>
    <p class="muted"><?= te('vacations.settings.remind_message_hint') ?></p>
  </fieldset>
  <div class="page-actions">
    <button class="btn-primary" type="submit" name="action" value="save"><?= te('core.common.save') ?></button>
    <button class="btn" type="submit" name="action" value="test_remind"><?= te('vacations.settings.test_remind') ?></button>
    <a class="btn" href="/vacations"><?= te('core.common.cancel') ?></a>
  </div>
</form>
