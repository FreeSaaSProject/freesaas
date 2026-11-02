<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';
$result = null;

if (!Auth::capability('SUPERADMIN_ACCESS')) {
    if ($wantsJson) {
        AgentHttp::error(t('backups.err.superadmin'), 403);
        return;
    }
    http_response_code(403);
    echo t('backups.err.superadmin');
    return;
}

$avail = BackupRestore::availability();
$sets = [];
try {
    if (trim(BackupConfig::ftpConfig()['host']) !== '') {
        $t = BackupFtpTarget::fromConfig();
        try {
            $sets = array_values(array_filter(
                $t->listSets(BackupConfig::instance()),
                static function (array $s): bool {
                    return !empty($s['has_manifest']);
                }
            ));
        } finally {
            $t->close();
        }
    }
} catch (Throwable $e) {
    if ($error === '') {
        $error = $e->getMessage();
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (class_exists('Csrf', false) && !Csrf::verify()) {
            Csrf::deny('csrf');
            return;
        }
        if (empty($avail['available'])) {
            throw new RuntimeException(t('backups.err.db_not_empty'));
        }
        $step = (string)($_POST['step'] ?? 'deploy');
        $passphrase = (string)($_POST['passphrase'] ?? '');
        $url = trim((string)($_POST['url'] ?? ''));
        $remotePath = trim((string)($_POST['remote_path'] ?? ''));
        $localFile = trim((string)($_POST['local_file'] ?? ''));

        if ($step === 'deploy' || $step === 'run') {
            $opts = ['passphrase' => $passphrase, 'migrate' => true];
            if ($url !== '') {
                $opts['url'] = $url;
            } elseif ($remotePath !== '') {
                $opts['remote_path'] = $remotePath;
            } elseif ($localFile !== '') {
                $opts['file'] = $localFile;
            } else {
                throw new InvalidArgumentException(t('backups.restore.need_source'));
            }
            $result = BackupRestore::deploy($opts);
            if (empty($result['ok'])) {
                throw new RuntimeException((string)($result['error'] ?? 'deploy failed'));
            }
            $message = t('backups.restore.done');
        } else {
            throw new InvalidArgumentException('unknown step');
        }

        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'step' => $step,
                'message' => $message,
                'result' => $result,
                'available' => $avail['available'],
            ]);
            return;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, stripos($error, 'empty') !== false || stripos($error, 'пуст') !== false ? 409 : 400);
            return;
        }
    }
}

if ($wantsJson && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    AgentHttp::emit([
        'ok' => true,
        'sets' => $sets,
        'available' => $avail['available'],
        'empty' => $avail['empty'],
    ]);
    return;
}

View::render('restore', [
    'title' => t('backups.restore.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('backups.menu.list'), 'href' => '/backups'],
        ['label' => t('backups.menu.restore'), 'current' => true],
    ]),
    'message' => $message,
    'error' => $error,
    'sets' => $sets,
    'result' => $result,
    'available' => $avail['available'],
    'empty' => $avail['empty'],
], 'layout', 'backups');
