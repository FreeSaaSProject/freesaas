<?php
declare(strict_types=1);

$code = trim((string)($_GET['code'] ?? ''));
$state = trim((string)($_GET['state'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));

$userId = $state !== '' ? GcalOAuth::parseState($state) : null;
if ($error !== '' || $code === '' || $userId === null || $userId <= 0) {
    flash_set('error', t('gcal.oauth.fail'));
    if (Auth::check()) {
        redirect(Auth::isSuperAdmin() ? '/gcal/settings' : '/');
    }
    redirect('/login');
}

if (!Auth::check() || (int)Auth::userId() !== $userId) {
    // Allow SA completing staff connect from settings? Require same session user.
    if (!Auth::check()) {
        flash_set('error', t('gcal.oauth.fail'));
        redirect('/login');
    }
    if ((int)Auth::userId() !== $userId && !Auth::isSuperAdmin()) {
        flash_set('error', t('gcal.oauth.fail'));
        redirect('/');
    }
}

$tok = GcalOAuth::exchangeCode($code);
if ($tok === null) {
    flash_set('error', t('gcal.oauth.fail'));
    redirect(Auth::isSuperAdmin() ? '/gcal/settings' : '/');
}

GcalSyncRepository::upsertToken($userId, $tok);
$cals = GcalApi::listCalendars($userId);
$primary = '';
foreach ($cals as $c) {
    if (!empty($c['primary'])) {
        $primary = $c['id'];
        break;
    }
}
if ($primary === '' && $cals !== []) {
    $primary = $cals[0]['id'];
}
$row = GcalSyncRepository::getToken($userId);
if ($row && trim((string)$row['ACTIVE_CALENDAR_ID']) === '' && $primary !== '') {
    GcalSyncRepository::setCalendars($userId, $primary, $primary);
}

flash_set('ok', t('gcal.oauth.ok'));
redirect(Auth::isSuperAdmin() ? '/gcal/settings' : '/');
