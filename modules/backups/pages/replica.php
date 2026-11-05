<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';
$check = null;
$pullResult = null;

if (!Auth::capability('SUPERADMIN_ACCESS')) {
    if ($wantsJson) {
        AgentHttp::error(t('backups.err.superadmin'), 403);
        return;
    }
    http_response_code(403);
    echo t('backups.err.superadmin');
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (class_exists('Csrf', false) && !Csrf::verify()) {
        Csrf::deny('csrf');
        return;
    }
    $action = (string)($_POST['action'] ?? 'check');
    try {
        if ($action === 'pull' || $action === 'pull_start') {
            $confirmWipe = !empty($_POST['confirm_wipe']);
            if ($wantsJson || $action === 'pull_start') {
                $pullResult = BackupReplica::jobStart($confirmWipe);
                if ($wantsJson) {
                    AgentHttp::emit($pullResult);
                    return;
                }
            } else {
                $pullResult = BackupReplica::jobStart($confirmWipe);
                if (($pullResult['status'] ?? '') === 'done' || !empty($pullResult['ok'])) {
                    if (!empty($pullResult['relogin'])) {
                        $_SESSION = [];
                        if (session_status() === PHP_SESSION_ACTIVE) {
                            session_destroy();
                        }
                        redirect('/login');
                        return;
                    }
                    // noop / incr finished in one shot (CLI path uses pull() instead)
                    $message = (string)($pullResult['message'] ?? t('backups.replica.check_ok'));
                } else {
                    $error = (string)($pullResult['error'] ?? 'pull failed');
                }
            }
        } elseif ($action === 'pull_tick') {
            $wall = max(5, min(90, (int)($_POST['wall'] ?? BackupReplica::BUDGET_SEC)));
            $pullResult = BackupReplica::jobAdvance($wall);
            if ((string)($pullResult['status'] ?? '') === 'done' && !empty($pullResult['relogin'])) {
                $_SESSION = [];
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_destroy();
                }
            }
            if ($wantsJson) {
                AgentHttp::emit($pullResult);
                return;
            }
        } else {
            $check = BackupReplica::check(true);
            if (!empty($check['ok'])) {
                $message = t('backups.replica.check_ok');
            } else {
                $error = (string)($check['error'] ?? 'check failed');
            }
            if ($wantsJson) {
                AgentHttp::emit($check);
                return;
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    }
}

$configured = BackupReplica::configured();
if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'enabled' => BackupConfig::replicaEnabled(),
        'configured' => $configured['ok'],
        'configured_error' => $configured['error'],
        'instance' => BackupConfig::replicaInstance(),
        'applied' => BackupConfig::replicaApplied(),
        'standby_mode' => StandbyMode::mode(),
        'job' => BackupReplica::jobGet() !== null
            ? BackupReplica::progress(BackupReplica::jobGet())
            : null,
    ]);
    return;
}

View::render('replica', [
    'title' => t('backups.replica.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('backups.menu.list'), 'href' => '/backups'],
        ['label' => t('backups.replica.title'), 'current' => true],
    ]),
    'message' => $message,
    'error' => $error,
    'enabled' => BackupConfig::replicaEnabled(),
    'configured' => $configured,
    'instance' => BackupConfig::replicaInstance(),
    'ftp_host' => BackupConfig::replicaFtpConfig()['host'],
    'applied' => BackupConfig::replicaApplied(),
    'standby' => StandbyMode::isStandby(),
    'check' => $check,
    'pull' => $pullResult,
], 'layout', 'backups');
