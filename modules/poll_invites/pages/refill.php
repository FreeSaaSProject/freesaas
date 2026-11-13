<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
try {
    $old = PollInvitesRepository::find($id);
    PollInvitesRepository::assertCanOpen($old);
    $newId = PollPublic::refill($id, (int)($_POST['expire_days'] ?? 14));
    $new = PollInvitesRepository::find($newId);
    $url = PollPublic::url((string)($new['code'] ?? ''));
    if ($wantsJson) {
        AgentHttp::emit(['ok' => true, 'invite_id' => $newId, 'from_invite_id' => $id, 'url' => $url]);
        return;
    }
    flash_set('ok', t('poll_invites.refill.done', ['url' => $url]));
    redirect('/poll-invites?day=all');
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
        return;
    }
    flash_set('warn', $e->getMessage());
    redirect('/poll-invites?day=all');
}
