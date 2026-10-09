<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/SystemRepository.php');
require_once base_path('modules/core/lib/SystemLogo.php');

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';
$row = SystemRepository::get();
$agentTokenPlain = '';
$agentTokenJustGenerated = false;
$action = '';

$updateCheckRows = [];
$updateCanApply = Auth::capability('SUPERADMIN_ACCESS');

// LLM/assistant may POST JSON body; merge into $_POST for action handlers.
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
    $action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
    try {
        if ($action === 'agent_token_generate') {
            if ($wantsJson) {
                AgentHttp::error('agent_token_generate is UI-only', 403);
            }
            $token = bin2hex(random_bytes(32));
            Config::set('app', 'agent_token', $token);
            $agentTokenPlain = $token;
            $agentTokenJustGenerated = true;
            $message = t('core.settings.agent_token.generated');
        } elseif ($action === 'update_check') {
            $target = trim((string)($_POST['target'] ?? $_GET['target'] ?? ''));
            $report = AgentOps::updateCheck($target !== '' ? $target : null);
            if (empty($report['ok'])) {
                throw new RuntimeException((string)($report['error'] ?? t('core.settings.updates.apply_fail')));
            }
            $updateCheckRows = is_array($report['targets'] ?? null) ? $report['targets'] : [];
            $message = t('core.settings.updates.check_ok');
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'action' => 'update_check',
                    'message' => $message,
                    'targets' => $updateCheckRows,
                    'http' => (int)($report['http'] ?? 200),
                ]);
            }
        } elseif ($action === 'update_apply') {
            if (!$updateCanApply) {
                throw new RuntimeException(t('core.settings.updates.err_superadmin'));
            }
            $target = trim((string)($_POST['target'] ?? $_GET['target'] ?? 'all'));
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
                throw new RuntimeException($err !== '' ? $err : t('core.settings.updates.apply_fail'));
            }
            $message = t('core.settings.updates.apply_ok');
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
                    'http' => (int)($report['http'] ?? 200),
                ]);
            }
        } elseif ($action === 'update_status') {
            // POST alias for agents that only POST tools
            $st = AgentOps::updateStatus();
            if ($wantsJson) {
                AgentHttp::emit(array_merge($st, ['action' => 'update_status']));
            }
        } else {
            if ($wantsJson && $action !== '' && $action !== 'save') {
                AgentHttp::error('Unknown action', 400);
            }
            SystemRepository::updateBasics([
                'company' => (string)($_POST['company'] ?? ''),
            ]);

            if (!empty($_POST['logo_remove'])) {
                SystemLogo::remove();
            }

            $upload = $_FILES['logo'] ?? null;
            if (is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                SystemLogo::saveFromUpload($upload);
            }

            EventBus::emit('core.settings.save', [
                'post' => $_POST,
                'files' => $_FILES,
                'row' => SystemRepository::get(),
            ]);

            $message = t('core.common.saved');
            $row = SystemRepository::get();
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'action' => 'save',
                    'message' => $message,
                    'company' => (string)($row['company'] ?? ''),
                ]);
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $agentTokenJustGenerated = false;
        $row = SystemRepository::get();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
        }
    }
}

$updateStatus = AgentOps::updateStatus();
$updateSources = is_array($updateStatus['sources'] ?? null) ? $updateStatus['sources'] : [];
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
    $getAction = (string)($_GET['action'] ?? '');
    if ($getAction === 'update_check') {
        $target = trim((string)($_GET['target'] ?? ''));
        $report = AgentOps::updateCheck($target !== '' ? $target : null);
        if (empty($report['ok'])) {
            AgentHttp::error((string)($report['error'] ?? 'update check failed'), (int)($report['http'] ?? 400));
        }
        AgentHttp::emit(array_merge($report, ['action' => 'update_check']));
    }
    // Default GET: settings snapshot + update status (no secrets)
    AgentHttp::emit([
        'ok' => true,
        'action' => 'status',
        'company' => (string)($row['company'] ?? ''),
        'has_logo' => SystemLogo::has(),
        'agent_token_configured' => AgentAuth::isConfigured(),
        'update' => $updateStatus,
        'update_can_apply' => $updateCanApply,
    ]);
}

$sections = EventBus::emit('core.settings.sections', [
    'html' => '',
    'row' => $row,
]);
$extraHtml = is_array($sections) ? (string)($sections['html'] ?? '') : '';

$agentConfigured = AgentAuth::isConfigured();
if ($agentTokenPlain === '' && $agentConfigured) {
    $agentTokenPlain = AgentAuth::configuredToken();
}
$agentTokenMasked = '';
if ($agentConfigured && $agentTokenPlain !== '') {
    $len = strlen($agentTokenPlain);
    $agentTokenMasked = $len <= 12
        ? str_repeat('•', $len)
        : (substr($agentTokenPlain, 0, 4) . '…' . substr($agentTokenPlain, -4));
}

View::render('settings', [
    'title' => t('core.menu.settings'),
    'row' => $row,
    'message' => $message,
    'error' => $error,
    'logoUrl' => SystemLogo::url(SystemLogo::VARIANT_AUTH),
    'hasLogo' => SystemLogo::has(),
    'extraHtml' => $extraHtml,
    'agentTokenConfigured' => $agentConfigured,
    'agentTokenMasked' => $agentTokenMasked,
    'agentTokenPlain' => $agentTokenPlain,
    'agentTokenJustGenerated' => $agentTokenJustGenerated,
    'updateConfigured' => !empty($updateStatus['configured']),
    'updateSources' => $updateSources,
    'updateRemoteById' => $updateRemoteById,
    'updateCanApply' => $updateCanApply,
]);
