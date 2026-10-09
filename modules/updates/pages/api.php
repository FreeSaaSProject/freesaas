<?php
declare(strict_types=1);

/**
 * Agent JSON endpoints: /updates/check, /updates/apply
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
    if ($path === '/updates/check' && ($method === 'GET' || $method === 'POST')) {
        $report = AgentOps::updateCheck($target);
        if ($wantsJson) {
            if (empty($report['ok'])) {
                AgentHttp::error((string)($report['error'] ?? t('updates.apply_fail')), (int)($report['http'] ?? 400));
            }
            AgentHttp::emit($report);
        }
        if (!empty($report['ok']) && is_array($report['targets'] ?? null)) {
            $_SESSION['freesaas_update_check'] = $report['targets'];
        }
        redirect('/updates');
    }

    if ($path === '/updates/apply' && $method === 'POST') {
        $canApply = Auth::can('updates.apply') && Auth::capability('SUPERADMIN_ACCESS');
        if (!$canApply) {
            if ($wantsJson) {
                AgentHttp::error(t('updates.err_superadmin'), 403);
            }
            flash_set('error', t('updates.err_superadmin'));
            redirect('/updates');
        }
        $migrate = true;
        if (isset($_POST['migrate']) || isset($_GET['migrate'])) {
            $m = (string)($_POST['migrate'] ?? $_GET['migrate'] ?? '1');
            $migrate = !in_array(strtolower($m), ['0', 'false', 'no'], true);
        }
        $applyTarget = ($target === null || strtolower((string)$target) === 'all') ? null : $target;
        $report = AgentOps::updateApply($applyTarget, $migrate);
        if ($wantsJson) {
            if (empty($report['ok'])) {
                $err = (string)($report['error'] ?? '');
                if ($err === '' && !empty($report['results'][0]['error'])) {
                    $err = (string)$report['results'][0]['error'];
                }
                AgentHttp::error($err !== '' ? $err : t('updates.apply_fail'), (int)($report['http'] ?? 500));
            }
            AgentHttp::emit($report);
        }
        if (!empty($report['ok'])) {
            flash_set('ok', t('updates.apply_ok'));
        } else {
            flash_set('error', t('updates.apply_fail'));
        }
        redirect('/updates');
    }

    if ($wantsJson) {
        AgentHttp::error('unknown updates endpoint', 404);
    }
    redirect('/updates');
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
    }
    flash_set('error', $e->getMessage());
    redirect('/updates');
}
