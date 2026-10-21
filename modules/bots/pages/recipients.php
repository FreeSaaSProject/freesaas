<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$q = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 80);
$payload = BotRecipients::list($q, $limit);

if ($wantsJson) {
    AgentHttp::emit($payload);
    return;
}

View::render('error', [
    'title' => t('bots.recipients.title'),
    'message' => t('bots.module.summary'),
], 'layout', 'bots');
