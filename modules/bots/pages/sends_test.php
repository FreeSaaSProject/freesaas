<?php
declare(strict_types=1);

$req = array_merge($_GET, $_POST);
$userId = (int)($req['user_id'] ?? 0);
$clientId = (int)($req['client_id'] ?? 0);
$audience = strtolower(trim((string)($req['audience'] ?? 'staff')));
if (!in_array($audience, BotsConfig::AUDIENCES, true)) {
    $audience = 'staff';
}
$text = trim((string)($req['text'] ?? ''));
if ($text === '') {
    $text = t('bots.send.default_text') . ' ' . date('Y-m-d H:i:s');
}
$drain = !empty($req['drain']);

if ($audience === 'client') {
    if ($clientId < 1) {
        AgentHttp::error(t('bots.err.user_required'), 400);
    }
    $queuedId = BotSend::enqueue([
        'audience' => 'client',
        'client_id' => $clientId,
        'text' => $text,
        'name' => 'test_client_' . $clientId,
        'type' => 11,
    ]);
} else {
    if ($userId < 1) {
        AgentHttp::error(t('bots.err.user_required'), 400);
    }
    $queuedId = BotSend::enqueue([
        'audience' => 'staff',
        'user_id' => $userId,
        'text' => $text,
        'name' => 'test_staff_' . $userId,
        'type' => 11,
    ]);
}

$drainResult = null;
if ($drain) {
    $drainResult = BotDrain::run(5, $audience);
}

AgentHttp::emit([
    'ok' => true,
    'queued_id' => $queuedId,
    'audience' => $audience,
    'drain' => $drainResult,
]);
