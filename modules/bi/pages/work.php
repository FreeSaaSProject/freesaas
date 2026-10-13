<?php
declare(strict_types=1);

/**
 * Streaming / JSON work endpoint for KPI report runs (director or shared staff).
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && function_exists('request_raw_body')) {
    $rawBody = request_raw_body();
    if ($rawBody !== '') {
        $decodedBody = json_decode($rawBody, true);
        if (is_array($decodedBody)) {
            foreach ($decodedBody as $k => $v) {
                if (!array_key_exists((string)$k, $_POST)) {
                    $_POST[(string)$k] = $v;
                }
            }
        }
    }
}

$req = array_merge($_GET, $_POST);
$action = trim((string)($req['action'] ?? 'run'));
$wantsJson = AgentHttp::wantsJson();
$familyId = trim((string)($req['family'] ?? ''));
$canManage = Auth::can('bi.manage');
$shareToken = trim((string)($req['share'] ?? ''));
$share = null;
$runTicket = null;

if (!$canManage) {
    $share = BiSharesRepository::resolveStaffShareAccess($familyId, $shareToken);
    if ($share === null) {
        if ($wantsJson) {
            AgentHttp::error(t('bi.share.forbidden'), 403);
        }
        http_response_code(403);
        echo '<h2>403</h2>';
        return;
    }
}

if ($action !== 'run') {
    if ($wantsJson) {
        AgentHttp::error('unknown_action', 400);
    }
    http_response_code(400);
    echo 'unknown_action';
    return;
}

$family = ReportRegistry::get($familyId);
if ($family === null) {
    if ($wantsJson) {
        AgentHttp::error(t('bi.err.unknown_family'), 404);
    }
    http_response_code(404);
    echo e(t('bi.err.unknown_family'));
    return;
}

if ($share !== null) {
    $runTicket = BiSharesRepository::beginRun($share);
    if ($runTicket === null) {
        if ($wantsJson) {
            AgentHttp::error(t('bi.share.rate_limit'), 429);
        }
        ReportProgress::start((string)$family->titleKey());
        ReportProgress::note(t('bi.share.rate_limit'), '#fbbf24');
        ReportProgress::done(['ok' => false, 'error' => t('bi.share.rate_limit')]);
        return;
    }
    $params = ReportParams::mergeWithConstraints(
        $req,
        is_array($share['params'] ?? null) ? $share['params'] : []
    );
} else {
    $params = ReportParams::normalize($req);
}

try {
    if ($wantsJson) {
        ReportProgress::beginCollect();
        try {
            $report = ReportRegistry::run($familyId, $params);
            $sections = is_array($report['sections'] ?? null) ? $report['sections'] : [];
            $summary = [];
            foreach ($sections as $sec) {
                if (!is_array($sec)) {
                    continue;
                }
                $type = (string)($sec['type'] ?? '');
                $row = [
                    'type' => $type,
                    'title' => (string)($sec['title'] ?? ''),
                ];
                if ($type === 'table') {
                    $row['rows'] = count(is_array($sec['rows'] ?? null) ? $sec['rows'] : []);
                    $row['columns'] = count(is_array($sec['columns'] ?? null) ? $sec['columns'] : []);
                } elseif ($type === 'metrics') {
                    $row['metrics'] = count(is_array($sec['metrics'] ?? null) ? $sec['metrics'] : []);
                } elseif ($type === 'text') {
                    $row['body_len'] = mb_strlen((string)($sec['body'] ?? ''), 'UTF-8');
                }
                $summary[] = $row;
            }
            $payload = [
                'ok' => true,
                'action' => 'run',
                'family' => $familyId,
                'params' => $params,
                'report' => $report,
                'section_summary' => $summary,
                'html' => ReportRenderer::sectionsHtml($report),
                'stages' => ReportProgress::collectStages(),
            ];
            if ($share !== null) {
                $payload['share_id'] = (int)$share['ID'];
                $payload['expires_at'] = (int)$share['DT_EXPIRE'];
            }
            AgentHttp::emit($payload);
        } catch (Throwable $e) {
            $code = $e->getMessage() === 'family_not_ready' ? 422 : 500;
            AgentHttp::error(
                $e->getMessage() === 'family_not_ready' ? t('bi.err.not_ready') : t('bi.err.run'),
                $code,
                ['detail' => $e->getMessage(), 'family' => $familyId, 'stages' => ReportProgress::collectStages()]
            );
        }
        return;
    }

    ReportProgress::start((string)$family->titleKey());
    try {
        $report = ReportRegistry::run($familyId, $params);
        $html = ReportRenderer::sectionsHtml($report);
        ReportProgress::done([
            'ok' => true,
            'action' => 'run',
            'family' => $familyId,
            'title' => (string)($report['title'] ?? ''),
            'html' => $html,
            'report' => $report,
        ]);
    } catch (Throwable $e) {
        ReportProgress::note('Error: ' . $e->getMessage(), '#f87171');
        ReportProgress::done([
            'ok' => false,
            'error' => $e->getMessage(),
        ]);
    }
} finally {
    BiSharesRepository::endRun($runTicket);
}
