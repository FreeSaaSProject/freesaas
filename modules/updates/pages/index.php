<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';
$updateCheckRows = [];
$canApply = Auth::can('updates.apply') && Auth::capability('SUPERADMIN_ACCESS');

if ($wantsJson && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $body = json_decode($raw, true);
        if (is_array($body)) {
            foreach ($body as $k => $v) {
                $key = (string)$k;
                if ($key === '' || array_key_exists($key, $_POST)) {
                    continue;
                }
                if (is_bool($v)) {
                    $_POST[$key] = $v ? '1' : '';
                } elseif (is_scalar($v) || $v === null) {
                    $_POST[$key] = (string)($v ?? '');
                }
            }
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'update_check') {
            $target = trim((string)($_POST['target'] ?? ''));
            $report = AgentOps::updateCheck($target !== '' ? $target : null);
            if (empty($report['ok'])) {
                throw new RuntimeException((string)($report['error'] ?? t('updates.apply_fail')));
            }
            $updateCheckRows = is_array($report['targets'] ?? null) ? $report['targets'] : [];
            $message = t('updates.check_ok');
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'action' => 'update_check',
                    'message' => $message,
                    'targets' => $updateCheckRows,
                ]);
            }
        } elseif ($action === 'update_apply') {
            if (!$canApply) {
                throw new RuntimeException(t('updates.err_superadmin'));
            }
            $target = trim((string)($_POST['target'] ?? 'all'));
            if ($target === '') {
                $target = 'all';
            }
            $migrate = true;
            if (isset($_POST['migrate'])) {
                $migrate = !in_array(strtolower((string)$_POST['migrate']), ['0', 'false', 'no'], true);
            }
            $report = AgentOps::updateApply($target === 'all' ? null : $target, $migrate);
            if (empty($report['ok'])) {
                $err = (string)($report['error'] ?? '');
                if ($err === '' && !empty($report['results'][0]['error'])) {
                    $err = (string)$report['results'][0]['error'];
                }
                throw new RuntimeException($err !== '' ? $err : t('updates.apply_fail'));
            }
            $message = t('updates.apply_ok');
            $check = AgentOps::updateCheck($target === 'all' ? null : $target);
            $updateCheckRows = is_array($check['targets'] ?? null) ? $check['targets'] : [];
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'action' => 'update_apply',
                    'message' => $message,
                    'target' => $target,
                    'results' => $report['results'] ?? [],
                    'targets' => $updateCheckRows,
                ]);
            }
        } elseif ($wantsJson) {
            AgentHttp::error('Unknown action', 400);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
        }
    }
}

$updateStatus = AgentOps::updateStatus();
$updateSources = is_array($updateStatus['sources'] ?? null) ? $updateStatus['sources'] : [];
$updateHints = is_array($updateStatus['hints'] ?? null) ? $updateStatus['hints'] : [];

if ($updateCheckRows === [] && !empty($_SESSION['freesaas_update_check']) && is_array($_SESSION['freesaas_update_check'])) {
    $updateCheckRows = $_SESSION['freesaas_update_check'];
}
if ($updateCheckRows !== []) {
    $_SESSION['freesaas_update_check'] = $updateCheckRows;
}
$updateRemoteById = [];
foreach ($updateCheckRows as $tr) {
    if (is_array($tr) && isset($tr['id'])) {
        $updateRemoteById[(string)$tr['id']] = $tr;
    }
}

if ($wantsJson && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    AgentHttp::emit(array_merge($updateStatus, [
        'update_can_apply' => $canApply,
        'check' => $updateCheckRows,
    ]));
}

View::render('index', [
    'title' => t('updates.menu.list'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('updates.menu.list'), 'current' => true],
    ]),
    'breadcrumbs_aria' => t('updates.folder_aria'),
    'message' => $message,
    'error' => $error,
    'updateConfigured' => !empty($updateStatus['configured']),
    'updateSources' => $updateSources,
    'updateHints' => $updateHints,
    'updateRemoteById' => $updateRemoteById,
    'updateCanApply' => $canApply,
    'iniPath' => (string)($updateStatus['ini'] ?? ''),
    'examplePath' => (string)($updateStatus['example'] ?? ''),
], 'layout', 'updates');
