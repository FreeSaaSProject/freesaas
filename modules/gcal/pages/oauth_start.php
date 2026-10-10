<?php
declare(strict_types=1);

$uid = (int)Auth::userId();
$forUser = (int)($_GET['user_id'] ?? $uid);
if ($forUser !== $uid && !Auth::isSuperAdmin()) {
    $forUser = $uid;
}
$token = GcalSyncRepository::getToken($forUser);
$connected = $token && trim((string)$token['REFRESH_TOKEN']) !== '';

if (AgentHttp::wantsJson() && empty($_GET['redirect'])) {
    AgentHttp::emit([
        'ok' => true,
        'user_id' => $forUser,
        'connected' => $connected,
        'active_calendar_id' => $token ? (string)$token['ACTIVE_CALENDAR_ID'] : '',
        'done_calendar_id' => $token ? (string)$token['DONE_CALENDAR_ID'] : '',
        'auth_url' => GcalConfig::appConfigured() ? GcalOAuth::authUrl($forUser) : null,
    ]);
}

if (!GcalConfig::appConfigured()) {
    flash_set('error', t('gcal.err.app'));
    redirect(Auth::isSuperAdmin() ? '/gcal/settings' : '/');
}

redirect(GcalOAuth::authUrl($forUser));
