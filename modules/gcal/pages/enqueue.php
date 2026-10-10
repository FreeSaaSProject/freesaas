<?php
declare(strict_types=1);

$newsId = (int)($_POST['news_id'] ?? $_POST['id'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? 'manual'));
if ($reason === '') {
    $reason = 'manual';
}

if ($newsId <= 0) {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error('news_id required', 400);
    }
    flash_set('error', 'news_id');
    redirect('/');
}

$row = NewsRepository::find($newsId);
if ($row === null || (int)$row['TYPE'] !== 2) {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error('not a TYPE=2 task', 400);
    }
    flash_set('error', t('gcal.err.disabled'));
    redirect('/news/' . $newsId);
}

if (!GcalConfig::enabled()) {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error(t('gcal.err.disabled'), 400);
    }
    flash_set('error', t('gcal.err.disabled'));
    redirect('/news/' . $newsId);
}

GcalSyncService::maybeEnqueue($newsId, $reason, (int)Auth::userId(), true);

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'news_id' => $newsId,
        'queued' => true,
        'pending' => GcalSyncRepository::pendingCount(),
    ]);
}

flash_set('ok', t('gcal.chip.sync'));
redirect('/news/' . $newsId);
