<?php
declare(strict_types=1);
/** @var array $entity */
/** @var string $secTitle */
/** @var string $secHint */
/** @var bool $showNoSend */
$showNoSend = !empty($showNoSend);
?>
<h2 class="form-section-title"><?= e($secTitle) ?></h2>
<p class="form-hint"><?= e($secHint) ?></p>
<label><?= te('bots.user_edit.telegram_chat_id') ?>
  <input name="telegram_chat_id" value="<?= e((string)($entity['telegram_chat_id'] ?? '')) ?>">
</label>
<label><?= te('bots.user_edit.max_user_id') ?>
  <input name="max_user_id" value="<?= e((string)($entity['max_user_id'] ?? '')) ?>">
</label>
<?php if ($showNoSend): ?>
<label class="check">
  <input type="checkbox" name="BOT_NO_SEND" value="1"<?= !empty($entity['BOT_NO_SEND']) ? ' checked' : '' ?>>
  <?= te('bots.user_edit.bot_no_send') ?>
</label>
<?php endif; ?>
