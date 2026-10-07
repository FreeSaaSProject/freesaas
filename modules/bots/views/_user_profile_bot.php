<?php
declare(strict_types=1);
/** @var array $user */
?>
<dt><?= te('bots.user_edit.telegram_chat_id') ?></dt>
<dd><?= e((string)(($user['telegram_chat_id'] ?? '') !== '' && ($user['telegram_chat_id'] ?? '') !== null ? $user['telegram_chat_id'] : '—')) ?></dd>
<dt><?= te('bots.user_edit.max_user_id') ?></dt>
<dd><?= e((string)(($user['max_user_id'] ?? '') !== '' && ($user['max_user_id'] ?? '') !== null ? $user['max_user_id'] : '—')) ?></dd>
<dt><?= te('bots.user.bot_off') ?></dt>
<dd><?= !empty($user['BOT_NO_SEND']) ? te('core.common.yes') : te('core.common.no') ?></dd>
