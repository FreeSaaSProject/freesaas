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
        if (in_array($action, ['job_start', 'replica_job_start'], true)) {
            $op = strtolower(trim((string)($_POST['op'] ?? 'check')));
            if ($op === 'check') {
                $job = LongJob::start(BackupReplicaLongJob::TYPE_CHECK, [], ['context' => 'ui']);
                AgentHttp::emit([
                    'ok' => true,
                    'action' => 'job_start',
                    'job' => LongJob::public($job),
                ]);
                return;
            }
            if ($op === 'pull') {
                $confirmWipe = !empty($_POST['confirm_wipe']);
                // Domain SoT — LongJob::start would dual-write; mirror via adapter for UI DTO.
                $pub = BackupReplica::jobStart($confirmWipe);
                if (($pub['status'] ?? '') === 'error' && empty($pub['ok']) && empty($pub['resumable'])) {
                    AgentHttp::emit([
                        'ok' => false,
                        'action' => 'job_start',
                        'error' => (string)($pub['error'] ?? 'pull failed'),
                        'job' => BackupReplicaLongJob::publicFromDomain($pub),
                    ], 400);
                    return;
                }
                $token = (string)($pub['tick_token'] ?? '');
                $job = BackupReplicaLongJob::publicFromDomain($pub, $token);
                AgentHttp::emit([
                    'ok' => true,
                    'action' => 'job_start',
                    'job' => $job,
                ]);
                return;
            }
            AgentHttp::error('Unknown job op', 400);
            return;
        }
        if (in_array($action, ['job_tick', 'replica_job_tick'], true)) {
            $id = (string)($_POST['job_id'] ?? '');
            $op = strtolower(trim((string)($_POST['op'] ?? 'check')));
            if ($op === 'pull' || $id === 'replica') {
                AgentHttp::error(t('backups.replica.err.use_tick_endpoint'), 400);
                return;
            }
            $job = LongJob::tick($id, BackupReplicaLongJob::TYPE_CHECK);
            AgentHttp::emit([
                'ok' => true,
                'action' => 'job_tick',
                'job' => LongJob::public($job),
            ]);
            return;
        }
        if (in_array($action, ['job_cancel', 'replica_job_cancel'], true)) {
            // Pull has no cancel (cron continues); check may cancel a finished job no-op.
            $id = (string)($_POST['job_id'] ?? '');
            try {
                $job = LongJob::cancel($id, BackupReplicaLongJob::TYPE_CHECK);
                AgentHttp::emit(['ok' => true, 'action' => 'job_cancel', 'job' => LongJob::public($job)]);
                return;
            } catch (Throwable $e) {
                AgentHttp::emit(['ok' => true, 'action' => 'job_cancel', 'job' => [
                    'id' => $id,
                    'status' => 'cancelled',
                    'message' => t('core.longjob.cancelled'),
                ]]);
                return;
            }
        }
        if ($action === 'pull' || $action === 'pull_start') {
            $confirmWipe = !empty($_POST['confirm_wipe']);
            $pullResult = BackupReplica::jobStart($confirmWipe);
            if ($wantsJson || $action === 'pull_start') {
                $token = (string)($pullResult['tick_token'] ?? '');
                AgentHttp::emit(array_merge(
                    $pullResult,
                    ['job' => BackupReplicaLongJob::publicFromDomain($pullResult, $token)]
                ));
                return;
            }
            if (($pullResult['status'] ?? '') === 'done' || !empty($pullResult['ok'])) {
                if (!empty($pullResult['relogin'])) {
                    $_SESSION = [];
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_destroy();
                    }
                    redirect('/login');
                    return;
                }
                $message = (string)($pullResult['message'] ?? t('backups.replica.check_ok'));
            } else {
                $error = (string)($pullResult['error'] ?? 'pull failed');
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
        } elseif ($action === 'check') {
            // Legacy sync check (agent.ops / noscript). UI uses LongJob job_start op=check.
            $check = BackupReplica::check(true);
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION[BackupReplicaLongJob::SESSION_CHECK] = $check;
            }
            if (!empty($check['ok'])) {
                $message = t('backups.replica.check_ok');
            } else {
                $error = (string)($check['error'] ?? 'check failed');
            }
            if ($wantsJson) {
                AgentHttp::emit($check);
                return;
            }
        } else {
            AgentHttp::error('Unknown action', 400);
            return;
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
if ($check === null && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[BackupReplicaLongJob::SESSION_CHECK])) {
    $sess = $_SESSION[BackupReplicaLongJob::SESSION_CHECK];
    if (is_array($sess)) {
        $check = $sess;
    }
}

if ($wantsJson) {
    $domainJob = BackupReplica::jobGet();
    $jobOut = null;
    if ($domainJob !== null) {
        $jobOut = BackupReplicaLongJob::publicFromDomain(
            BackupReplica::jobPublic($domainJob),
            (string)($domainJob['tick_token'] ?? '')
        );
    }
    AgentHttp::emit([
        'ok' => true,
        'enabled' => BackupConfig::replicaEnabled(),
        'configured' => $configured['ok'],
        'configured_error' => $configured['error'],
        'instance' => BackupConfig::replicaInstance(),
        'applied' => BackupConfig::replicaApplied(),
        'standby_mode' => StandbyMode::mode(),
        'check' => $check,
        'job' => $jobOut,
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
