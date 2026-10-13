<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $decoded = null;
    if ($action === '' && $wantsJson) {
        $raw = function_exists('request_raw_body') ? request_raw_body() : '';
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $action = trim((string)($decoded['action'] ?? ''));
                foreach (['live', 'case_id', 'case', 'profile', 'run'] as $k) {
                    if (array_key_exists($k, $decoded) && !isset($_POST[$k])) {
                        $_POST[$k] = $decoded[$k];
                    }
                }
            }
        }
    }
    if ($action === '') {
        $action = 'run_one';
    }

    $live = true;
    if (isset($_POST['live'])) {
        $v = $_POST['live'];
        if (is_array($v)) {
            $v = end($v);
        }
        $live = (string)$v !== '0' && (string)$v !== 'false';
    }

    $profileOpt = trim((string)($_POST['profile'] ?? ''));

    // Echo CSRF so the stepped UI can keep the meta/form token in sync across long runs.
    $csrfOut = '';
    if (class_exists('Csrf', false) && session_status() === PHP_SESSION_ACTIVE) {
        $csrfOut = Csrf::token();
    }

    if ($action === 'catalog') {
        AgentHttp::emit([
            'ok' => true,
            'action' => 'catalog',
            'cases' => AssistantEval::catalog(),
            'last_run' => AssistantEvalStore::loadLatest(),
            'profile' => AssistantEvalStore::defaultProfileId(),
            'csrf' => $csrfOut,
        ]);
        return;
    }

    if ($action === 'save') {
        $run = $_POST['run'] ?? null;
        if (!is_array($run) && is_array($decoded) && isset($decoded['run']) && is_array($decoded['run'])) {
            $run = $decoded['run'];
        }
        if (!is_array($run)) {
            AgentHttp::error(t('assistant.eval.err.bad_run'), 400, ['csrf' => $csrfOut]);
        }
        if ($profileOpt !== '' && trim((string)($run['profile'] ?? '')) === '') {
            $run['profile'] = $profileOpt;
        }
        $saved = AssistantEvalStore::save($run);
        AgentHttp::emit([
            'ok' => !empty($saved['ok']),
            'action' => 'save',
            'run' => $saved['run'] ?? null,
            'error' => (string)($saved['error'] ?? ''),
            'csrf' => $csrfOut,
        ]);
        return;
    }

    if ($action === 'run_one') {
        $caseId = trim((string)($_POST['case_id'] ?? $_POST['case'] ?? ''));
        if ($caseId === '') {
            AgentHttp::error(t('assistant.eval.err.no_case'), 400);
        }
        $userId = Auth::userId();
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }
        @ini_set('max_execution_time', '180');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        try {
            $opts = [
                'live' => $live,
                'user_id' => $userId,
                'timeout' => 55,
            ];
            if ($profileOpt !== '') {
                $opts['profile'] = $profileOpt;
            }
            $one = AssistantEval::runCase($caseId, $opts);
            $usedProfile = trim((string)($one['profile'] ?? ''));
            if ($usedProfile === '') {
                $usedProfile = $profileOpt !== '' ? $profileOpt : AssistantEvalStore::defaultProfileId();
            }
            AgentHttp::emit([
                'ok' => !empty($one['ok']),
                'action' => 'run_one',
                'row' => $one['row'] ?? null,
                'profile' => $usedProfile,
                'system_prompt' => (string)($one['system_prompt'] ?? ''),
                'error' => (string)($one['error'] ?? ''),
                'csrf' => $csrfOut,
            ]);
        } catch (Throwable $e) {
            AgentHttp::error($e->getMessage(), 500, ['csrf' => $csrfOut]);
        }
        return;
    }

    if ($action === 'run_all') {
        $userId = Auth::userId();
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $opts = [
            'live' => $live,
            'user_id' => $userId,
            'timeout' => 55,
        ];
        if ($profileOpt !== '') {
            $opts['profile'] = $profileOpt;
        }
        $result = AssistantEval::run($opts);
        $usedProfile = trim((string)($result['profile'] ?? ''));
        if ($usedProfile === '') {
            $usedProfile = $profileOpt !== '' ? $profileOpt : AssistantEvalStore::defaultProfileId();
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        AssistantEvalStore::save([
            'profile' => $usedProfile,
            'live' => $live,
            'rows' => $rows,
            'summary' => $summary,
            'ms' => (int)($summary['ms'] ?? 0),
            'finished' => true,
        ]);
        AgentHttp::emit([
            'ok' => !empty($result['ok']),
            'summary' => $summary,
            'rows' => $rows,
            'profile' => $usedProfile,
            'error' => (string)($result['error'] ?? ''),
            'csrf' => $csrfOut,
        ]);
        return;
    }

    AgentHttp::error('Unknown action', 400);
}

Assets::need('assistant');

$lastRun = AssistantEvalStore::loadLatest();
$profileId = is_array($lastRun) && trim((string)($lastRun['profile'] ?? '')) !== ''
    ? (string)$lastRun['profile']
    : AssistantEvalStore::defaultProfileId();

View::render('eval', [
    'title' => t('assistant.eval.title'),
    'cases' => AssistantEval::catalog(),
    'live_default' => true,
    'last_run' => $lastRun,
    'profile_id' => $profileId,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => AssistantConfig::displayName(), 'href' => '/assistant'],
        ['label' => t('assistant.menu.eval'), 'current' => true],
    ]),
], 'layout', 'assistant');
