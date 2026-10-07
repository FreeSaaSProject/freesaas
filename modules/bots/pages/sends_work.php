<?php
declare(strict_types=1);

$req = array_merge($_GET, $_POST);
$limit = min(100, max(1, (int)($req['limit'] ?? 20)));
$audience = trim((string)($req['audience'] ?? ''));
if ($audience !== '' && !in_array($audience, BotsConfig::AUDIENCES, true)) {
    $audience = '';
}

$result = BotDrain::run($limit, $audience !== '' ? $audience : null);

if (AgentHttp::wantsJson() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    AgentHttp::emit([
        'ok' => true,
        'processed' => $result['processed'],
        'sent' => $result['sent'],
        'skipped' => $result['skipped'],
        'errors' => $result['errors'],
        'items' => $result['items'],
        'pending' => BotSendsRepository::countPending($audience !== '' ? $audience : null),
    ]);
}

redirect('/bots/sends');
