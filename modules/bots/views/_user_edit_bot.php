<?php
declare(strict_types=1);
/** @var array $user */
?>
<h2 class="form-section-title"><?= te('bots.user_edit.sec') ?></h2>
<p class="form-hint"><?= te('bots.user_edit.hint') ?></p>
<label><?= te('bots.user_edit.telegram_chat_id') ?>
  <input name="telegram_chat_id" value="<?= e((string)($user['telegram_chat_id'] ?? '')) ?>">
</label>
<label><?= te('bots.user_edit.max_user_id') ?>
  <input name="max_user_id" value="<?= e((string)($user['max_user_id'] ?? '')) ?>">
</label>
<label class="check">
  <input type="checkbox" name="BOT_NO_SEND" value="1"<?= !empty($user['BOT_NO_SEND']) ? ' checked' : '' ?>>
  <?= te('bots.user_edit.bot_no_send') ?>
</label>
