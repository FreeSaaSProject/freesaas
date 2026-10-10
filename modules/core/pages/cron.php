<?php
declare(strict_types=1);

if (!Auth::capability('SUPERADMIN_ACCESS')) {
    http_response_code(403);
    echo t('core.cron.forbidden');
    return;
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $jobId = trim((string)($_POST['job_id'] ?? ''));

    if ($action === 'run_all') {
        $r = Cron::tick(true);
        $n = count($r['ran'] ?? []);
        $message = t('core.cron.flash.tick', ['n' => (string)$n, 'id' => (string)($r['tick_id'] ?? '')]);
        if (empty($r['ok'])) {
            $error = (string)($r['message'] ?? t('core.cron.flash.tick_fail'));
        }
    } elseif ($action === 'run_one' && $jobId !== '') {
        $r = Cron::tick(false, $jobId);
        $ran = $r['ran'][0] ?? null;
        if (is_array($ran)) {
            $message = t('core.cron.flash.run_one', [
                'job' => $jobId,
                'status' => (string)($ran['status'] ?? ''),
                'ms' => (string)(int)($ran['ms'] ?? 0),
            ]);
            if (($ran['status'] ?? '') === Cron::STATUS_FAIL) {
                $error = (string)($ran['message'] ?? '');
            }
        } else {
            $error = t('core.cron.flash.run_miss', ['job' => $jobId]);
        }
    } elseif ($action === 'enable' || $action === 'disable') {
        if ($jobId !== '' && Cron::setEnabled($jobId, $action === 'enable')) {
            $message = t('core.cron.flash.enabled', [
                'job' => $jobId,
                'on' => $action === 'enable' ? t('core.common.on') : t('core.common.off'),
            ]);
        } else {
            $error = t('core.cron.flash.enable_fail');
        }
    }
}

$jobs = Cron::catalogWithState();
$runs = Cron::recentRuns(40);
$host = (string)($_SERVER['HTTP_HOST'] ?? 'example.com');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$tickUrl = $scheme . '://' . $host . '/cron/tick';
$tokenConfigured = AgentAuth::isConfigured();

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'jobs' => $jobs,
        'runs' => $runs,
        'tick_url' => $tickUrl,
        'agent_token_configured' => $tokenConfigured,
        'tables_ready' => Cron::tablesReady(),
    ]);
    return;
}

View::render('cron', [
    'title' => t('core.cron.title'),
    'message' => $message,
    'error' => $error,
    'jobs' => $jobs,
    'runs' => $runs,
    'tick_url' => $tickUrl,
    'agent_token_configured' => $tokenConfigured,
    'tables_ready' => Cron::tablesReady(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.menu.cron'), 'current' => true],
    ]),
], 'layout', 'core');
