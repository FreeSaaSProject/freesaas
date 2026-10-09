<?php
declare(strict_types=1);

/**
 * Session/product JSON twin of platform /agent/update* (core.update.* agent.ops).
 * HTML UI stays on /settings; this page is JSON-first for agents / assistant / MCP.
 */
$wantsJson = AgentHttp::wantsJson();
$path = request_path();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($wantsJson && $method === 'POST') {
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

$targetRaw = trim((string)($_GET['target'] ?? $_POST['target'] ?? ''));
$target = $targetRaw !== '' ? $targetRaw : null;

try {
    if ($path === '/settings/update' && $method === 'GET') {
        $st = AgentOps::updateStatus();
        $st['update_can_apply'] = Auth::capability('SUPERADMIN_ACCESS');
        if ($wantsJson) {
            AgentHttp::emit($st);
        }
        redirect('/settings');
    }

    if ($path === '/settings/update/check' && ($method === 'GET' || $method === 'POST')) {
        $report = AgentOps::updateCheck($target);
        if ($wantsJson) {
            if (empty($report['ok'])) {
                AgentHttp::error((string)($report['error'] ?? 'update check failed'), (int)($report['http'] ?? 400));
            }
            AgentHttp::emit($report);
        }
        redirect('/settings');
    }

    if ($path === '/settings/update/apply' && $method === 'POST') {
        if (!Auth::capability('SUPERADMIN_ACCESS')) {
            if ($wantsJson) {
                AgentHttp::error(t('core.settings.updates.err_superadmin'), 403);
            }
            flash_set('error', t('core.settings.updates.err_superadmin'));
            redirect('/settings');
        }
        $migrate = true;
        if (isset($_POST['migrate']) || isset($_GET['migrate'])) {
            $m = (string)($_POST['migrate'] ?? $_GET['migrate'] ?? '1');
            $migrate = !in_array(strtolower($m), ['0', 'false', 'no'], true);
        }
        $applyTarget = ($target === null || strtolower($target) === 'all') ? null : $target;
        $report = AgentOps::updateApply($applyTarget, $migrate);
        if ($wantsJson) {
            if (empty($report['ok'])) {
                $err = (string)($report['error'] ?? '');
                if ($err === '' && !empty($report['results'][0]['error'])) {
                    $err = (string)$report['results'][0]['error'];
                }
                AgentHttp::error($err !== '' ? $err : t('core.settings.updates.apply_fail'), (int)($report['http'] ?? 500));
            }
            AgentHttp::emit($report);
        }
        redirect('/settings');
    }

    if ($wantsJson) {
        AgentHttp::error('unknown update endpoint', 404);
    }
    redirect('/settings');
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
    }
    flash_set('error', $e->getMessage());
    redirect('/settings');
}
