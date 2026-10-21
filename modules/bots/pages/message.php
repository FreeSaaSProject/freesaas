<?php
declare(strict_types=1);

/**
 * Assistant / staff: enqueue messenger text to linked staff/client or a known group chat.
 * POST body: text, user_id= | client_id= | chat_id= + platform=, optional name, dry_run=1
 */
$wantsJson = AgentHttp::wantsJson();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    AgentHttp::error('POST required', 405);
    return;
}

$text = trim((string)($_POST['text'] ?? ''));
$userId = (int)($_POST['user_id'] ?? 0);
$clientId = (int)($_POST['client_id'] ?? 0);
$chatId = trim((string)($_POST['chat_id'] ?? ''));
$platform = strtolower(trim((string)($_POST['platform'] ?? 'max')));
$name = trim((string)($_POST['name'] ?? ''));
$dryRun = !empty($_POST['dry_run']) || (string)($_POST['dry_run'] ?? '') === '1';

if ($text === '') {
    AgentHttp::error(t('bots.message.err.empty'), 400);
    return;
}
if (function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') > 3500 : strlen($text) > 3500) {
    AgentHttp::error(t('bots.message.err.too_long'), 400);
    return;
}

$targetLabel = '';
$mode = '';
$audience = 'staff';

if ($userId > 0) {
    if (!BotRecipients::staffReachable($userId)) {
        AgentHttp::error(t('bots.message.err.staff_unreachable'), 400);
        return;
    }
    $mode = 'staff';
    $audience = 'staff';
    $targetLabel = class_exists('UsersRepository', false)
        ? UsersRepository::nameById($userId)
        : ('#' . $userId);
    if ($name === '') {
        $name = 'assistant_staff_' . $userId;
    }
} elseif ($clientId > 0) {
    if (!BotRecipients::clientReachable($clientId)) {
        AgentHttp::error(t('bots.message.err.client_unreachable'), 400);
        return;
    }
    $mode = 'client';
    $audience = 'client';
    $targetLabel = '#' . $clientId;
    if (class_exists('ClientsRepository', false)) {
        $c = ClientsRepository::find($clientId);
        if (is_array($c)) {
            $n = trim((string)($c['name'] ?? ''));
            $tel = trim((string)($c['tel'] ?? ''));
            $targetLabel = $n !== '' ? $n : ($tel !== '' ? $tel : $targetLabel);
        }
    }
    if ($name === '') {
        $name = 'assistant_client_' . $clientId;
    }
} elseif ($chatId !== '') {
    if ($platform === '' || $platform === 'both') {
        $platform = 'max';
    }
    if (!in_array($platform, ['max', 'telegram'], true)) {
        AgentHttp::error(t('bots.message.err.platform'), 400);
        return;
    }
    if (!BotRecipients::chatAllowed($chatId, $platform)) {
        AgentHttp::error(t('bots.message.err.chat_unknown'), 400);
        return;
    }
    $mode = 'chat';
    $audience = 'staff';
    $targetLabel = $chatId;
    foreach (BotRecipients::listChats('', 200) as $c) {
        if ((string)($c['chat_id'] ?? '') === $chatId) {
            $title = trim((string)($c['title'] ?? ''));
            $descr = trim((string)($c['description'] ?? ''));
            if ($title !== '' && $descr !== '') {
                $targetLabel = $title . ' — ' . $descr . ' (' . $chatId . ')';
            } elseif ($title !== '') {
                $targetLabel = $title . ' (' . $chatId . ')';
            } elseif ($descr !== '') {
                $targetLabel = $descr . ' (' . $chatId . ')';
            }
            break;
        }
    }
    if ($name === '') {
        $name = 'assistant_chat_' . preg_replace('/\D+/', '', $chatId);
    }
} else {
    AgentHttp::error(t('bots.message.err.target'), 400);
    return;
}

$preview = t('bots.message.preview', [
    'to' => $targetLabel,
    'text' => function_exists('mb_substr')
        ? mb_substr($text, 0, 200, 'UTF-8')
        : substr($text, 0, 200),
]);

if ($dryRun) {
    AgentHttp::emit([
        'ok' => true,
        'changed' => true,
        'dry_run' => true,
        'mode' => $mode,
        'audience' => $audience,
        'user_id' => $userId,
        'client_id' => $clientId,
        'chat_id' => $chatId,
        'platform' => $platform,
        'message' => $preview,
        'text' => $text,
    ]);
    return;
}

try {
    $opts = [
        'audience' => $audience,
        'text' => $text,
        'name' => $name,
        'type' => 11,
        'id_operator' => Auth::userId(),
    ];
    if ($mode === 'staff') {
        $opts['user_id'] = $userId;
    } elseif ($mode === 'client') {
        $opts['client_id'] = $clientId;
    } else {
        $opts['chat_id'] = $chatId;
        $opts['platform'] = $platform;
        $opts['user_id'] = 0;
    }
    $queuedId = BotSend::enqueue($opts);
} catch (Throwable $e) {
    AgentHttp::error($e->getMessage(), 400);
    return;
}

AgentHttp::emit([
    'ok' => true,
    'changed' => true,
    'dry_run' => false,
    'mode' => $mode,
    'audience' => $audience,
    'user_id' => $userId,
    'client_id' => $clientId,
    'chat_id' => $chatId,
    'platform' => $platform,
    'queued_id' => $queuedId,
    'message' => t('bots.message.queued', [
        'to' => $targetLabel,
        'id' => (string)$queuedId,
    ]),
]);
