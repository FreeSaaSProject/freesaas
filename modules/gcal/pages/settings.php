<?php
declare(strict_types=1);

$message = '';
$error = '';
$action = trim((string)($_POST['action'] ?? 'save'));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if ($action === 'run_worker') {
            $limit = min(100, max(1, (int)($_POST['limit'] ?? 20)));
            $res = GcalSyncService::drain($limit, true);
            $message = t('gcal.settings.worker_ok', ['n' => (string)$res['processed']]);
        } elseif ($action === 'save_cals') {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0 || !GcalSyncRepository::getToken($userId)) {
                throw new RuntimeException(t('gcal.oauth.fail'));
            }
            GcalSyncRepository::setCalendars(
                $userId,
                trim((string)($_POST['active_calendar_id'] ?? '')),
                trim((string)($_POST['done_calendar_id'] ?? ''))
            );
            $message = t('gcal.settings.saved');
        } else {
            Config::set('gcal', 'enabled', !empty($_POST['enabled']) ? '1' : '0');
            Config::set('gcal', 'client_id', trim((string)($_POST['client_id'] ?? '')));
            $secret = trim((string)($_POST['client_secret'] ?? ''));
            if ($secret !== '') {
                Config::set('gcal', 'client_secret', $secret);
            }
            Config::set('gcal', 'redirect_uri', trim((string)($_POST['redirect_uri'] ?? '')));
            Config::set('gcal', 'workday_start', trim((string)($_POST['workday_start'] ?? '09:00')));
            Config::set('gcal', 'workday_end', trim((string)($_POST['workday_end'] ?? '18:00')));
            $message = t('gcal.settings.saved');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$staff = GcalSyncRepository::staffOverview();
$calendarsByUser = [];
foreach ($staff as $s) {
    if (!empty($s['connected'])) {
        $calendarsByUser[(int)$s['ID']] = GcalApi::listCalendars((int)$s['ID']);
    }
}
$pending = GcalSyncRepository::pendingCount();
$defaultRedirect = GcalConfig::redirectUri();

if (AgentHttp::wantsJson()) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $error !== '') {
        AgentHttp::error($error, 400);
    }
    AgentHttp::emit([
        'ok' => true,
        'message' => $message,
        'enabled' => GcalConfig::enabled(),
        'client_id' => GcalConfig::clientId(),
        'client_secret_set' => GcalConfig::clientSecret() !== '',
        'redirect_uri' => GcalConfig::redirectUri(),
        'workday_start' => GcalConfig::workdayStart(),
        'workday_end' => GcalConfig::workdayEnd(),
        'queue_pending' => $pending,
        'staff' => $staff,
    ]);
}

View::render('settings', [
    'title' => t('gcal.settings.title'),
    'message' => $message,
    'error' => $error,
    'enabled' => GcalConfig::enabled(),
    'clientId' => GcalConfig::clientId(),
    'clientSecretSet' => GcalConfig::clientSecret() !== '',
    'redirectUri' => (string)Config::get('gcal', 'redirect_uri', ''),
    'defaultRedirect' => $defaultRedirect,
    'workdayStart' => GcalConfig::workdayStart(),
    'workdayEnd' => GcalConfig::workdayEnd(),
    'staff' => $staff,
    'calendarsByUser' => $calendarsByUser,
    'pending' => $pending,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('gcal.menu.settings'), 'current' => true],
    ]),
], 'layout', 'gcal');
