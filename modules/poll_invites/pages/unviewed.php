<?php
declare(strict_types=1);

// Legacy URL: unviewed feedback lives on the main list with progress=unviewed.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $wantsJson = AgentHttp::wantsJson();
    if (class_exists('Csrf', false) && !$wantsJson && !Csrf::verify()) {
        Csrf::deny('csrf');
        return;
    }
    $uid = Auth::userId();
    $ids = [];
    if (!empty($_POST['mark_all'])) {
        $all = PollInvitesRepository::listUnviewedForUser($uid, ['limit' => 500, 'offset' => 0]);
        foreach ($all['items'] as $row) {
            $ids[] = (int)($row['id'] ?? 0);
        }
    } else {
        $raw = $_POST['ids'] ?? $_POST['id'] ?? [];
        if (!is_array($raw)) {
            $raw = [$raw];
        }
        foreach ($raw as $v) {
            $id = (int)$v;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }
    try {
        $done = PollInvitesRepository::markViewedByUser($ids, $uid);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'marked' => $done]);
            return;
        }
        flash_set('ok', t('poll_invites.unviewed.marked', ['n' => $done]));
    } catch (Throwable $e) {
        AgentHttp::rethrowIfCapture($e);
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        flash_set('error', $e->getMessage());
    }
    redirect('/poll-invites?progress=unviewed');
    return;
}

if (AgentHttp::wantsJson()) {
    $uid = Auth::userId();
    $result = PollInvitesRepository::listUnviewedForUser($uid, ['limit' => 100, 'offset' => 0]);
    AgentHttp::emit([
        'ok' => true,
        'items' => $result['items'],
        'total' => $result['total'],
        'redirect' => '/poll-invites?progress=unviewed',
    ]);
    return;
}

redirect('/poll-invites?progress=unviewed');
