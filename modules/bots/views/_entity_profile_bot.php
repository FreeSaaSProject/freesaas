<?php
declare(strict_types=1);
/** @var array $entity */
/** @var bool $showNoSend */
$showNoSend = !empty($showNoSend);
$tg = (string)($entity['telegram_chat_id'] ?? '');
$max = (string)($entity['max_user_id'] ?? '');
?>
<dt><?= te('bots.user_edit.telegram_chat_id') ?></dt>
<dd><?= e($tg !== '' ? $tg : '—') ?></dd>
<dt><?= te('bots.user_edit.max_user_id') ?></dt>
<dd><?= e($max !== '' ? $max : '—') ?></dd>
<?php if ($showNoSend): ?>
<dt><?= te('bots.user_edit.bot_no_send') ?></dt>
<dd><?= !empty($entity['BOT_NO_SEND']) ? te('core.common.yes') : te('core.common.no') ?></dd>
<?php endif; ?>
