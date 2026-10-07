<?php
declare(strict_types=1);

/**
 * Streaming / JSON work endpoint for summary constructor (propose, preview, save, month).
 * HTML progress mode targets iframe; JSON mode uses AgentHttp + stage list.
 */

require_once base_path('modules/audio_reports/lib/SummariesRepository.php');
require_once base_path('modules/audio_reports/lib/SummaryRunner.php');
require_once base_path('modules/audio_reports/lib/SummaryCatalog.php');
require_once base_path('modules/audio_reports/lib/SummaryRenderer.php');
require_once base_path('modules/audio_reports/lib/SummaryProgress.php');

$req = array_merge($_GET, $_POST);
$action = trim((string)($req['action'] ?? ''));
$wantsJson = AgentHttp::wantsJson();
$canEdit = Auth::can('audio_reports.edit');
$canView = Auth::can('audio_reports.view');

if (!$canView) {
    if ($wantsJson) {
        AgentHttp::error(t('audio_reports.summaries.err.forbidden'), 403);
    }
    http_response_code(403);
    echo '<h2>403</h2>';
    return;
}

if (in_array($action, ['save', 'propose', 'preview'], true) && !$canEdit) {
    if ($wantsJson || $action === 'save') {
        AgentHttp::error(t('audio_reports.summaries.err.forbidden'), 403);
    }
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<h2>' . e(t('audio_reports.summaries.err.forbidden')) . '</h2>';
    return;
}

$positionId = (int)($req['id_position'] ?? 0);
$year = (int)($req['year'] ?? date('Y'));
$month = (int)($req['month'] ?? date('n'));
$dateFrom = trim((string)($req['date_from'] ?? ''));
$dateTo = trim((string)($req['date_to'] ?? ''));
$period = SummaryRunner::resolvePeriod($year, $month, $dateFrom, $dateTo);
$year = (int)$period['year'];
$month = (int)$period['month'];
if ($period['mode'] === 'range') {
    $dateFrom = $period['date_from'];
    $dateTo = $period['date_to'];
} else {
    $dateFrom = '';
    $dateTo = '';
}

if ($action === 'save') {
    $profileId = (int)($req['id_records_summary'] ?? $req['id'] ?? 0);
    $name = trim((string)($req['name'] ?? ''));
    $positionId = (int)($req['id_position'] ?? 0);
    if ($name === '' || $positionId <= 0) {
        AgentHttp::error(t('audio_reports.summaries.err.name_position'), 400);
    }
    $config = SummariesRepository::parseConfigFromRequest($req);
    $hasStructured = isset($req['cards']) || isset($req['criteria_type_ids'])
        || isset($req['script_ids']) || isset($req['external_sources'])
        || isset($req['computed_metrics']) || isset($req['score_pass_threshold']);
    if (!$hasStructured && isset($req['config_json']) && is_string($req['config_json']) && trim($req['config_json']) !== '') {
        $decoded = json_decode((string)$req['config_json'], true);
        if (is_array($decoded)) {
            $config = SummariesRepository::normalizeConfig($decoded);
        }
    }
    $labels = SummariesRepository::parseLabelsFromRequest($req);
    $hasLabelFields = false;
    foreach ($req as $rk => $_rv) {
        if (is_string($rk) && strpos($rk, 'label_') === 0) {
            $hasLabelFields = true;
            break;
        }
    }
    if (!$hasLabelFields && isset($req['labels_json']) && is_string($req['labels_json']) && trim($req['labels_json']) !== '') {
        $decodedL = json_decode((string)$req['labels_json'], true);
        if (is_array($decodedL)) {
            $labels = SummariesRepository::normalizeLabels($decodedL);
        }
    }
    $payload = [
        'NAME' => $name,
        'ID_POSITION' => $positionId,
        'CONFIG_JSON' => $config,
        'LABELS_JSON' => $labels,
        'IS_DEFAULT' => !empty($req['is_default']) ? 1 : 0,
        'ENABLED' => 1,
        'ID_CREATOR' => Auth::userId(),
    ];
    try {
        if ($profileId > 0) {
            $existing = SummariesRepository::find($profileId);
            if (!$existing || !SummariesRepository::profileAllowedForViewer($existing)) {
                AgentHttp::error(t('audio_reports.summaries.not_found'), 404);
            }
            SummariesRepository::update($profileId, $payload);
            $newId = $profileId;
            $created = false;
        } else {
            $newId = SummariesRepository::create($payload);
            $created = true;
        }
    } catch (Throwable $e) {
        AgentHttp::error(t('audio_reports.summaries.err.save'), 500, ['detail' => $e->getMessage()]);
    }
    AgentHttp::emit([
        'ok' => true,
        'action' => 'save',
        'id' => $newId,
        'created' => $created ? 1 : 0,
    ]);
}

if ($action === 'month') {
    $profileId = (int)($req['id_records_summary'] ?? $req['profile'] ?? $req['id'] ?? 0);
    $profile = $profileId > 0 ? SummariesRepository::find($profileId) : null;
    if (!$profile || empty($profile['ENABLED']) || !SummariesRepository::profileAllowedForViewer($profile)) {
        AgentHttp::error(t('audio_reports.summaries.err.profile_access'), 404);
    }
    $summary = SummaryRunner::run($profile, $year, $month, $dateFrom, $dateTo);
    AgentHttp::emit([
        'ok' => true,
        'action' => 'month',
        'year' => $year,
        'month' => $month,
        'period_mode' => (string)($summary['period_mode'] ?? 'month'),
        'date_from' => (string)($summary['date_from'] ?? ''),
        'date_to' => (string)($summary['date_to'] ?? ''),
        'month_label' => (string)($summary['period_label'] ?? ''),
        'counts' => SummaryRenderer::countTargets($summary),
    ]);
}

if ($positionId <= 0 && in_array($action, ['propose', 'preview'], true)) {
    if ($wantsJson) {
        AgentHttp::error(t('audio_reports.summaries.err.position'), 400);
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo '<h2>' . e(t('audio_reports.summaries.err.position')) . '</h2>';
    return;
}

if ($action === 'propose') {
    if ($wantsJson) {
        SummaryProgress::beginCollect();
    } else {
        SummaryProgress::start(t('audio_reports.summaries.progress.propose'));
    }
    SummaryProgress::note(t('audio_reports.summaries.progress.propose_pos', [
        'id' => (string)$positionId,
        'period' => sprintf('%02d.%d', $month, $year),
    ]));
    $catalog = SummaryCatalog::discover($positionId, $year, $month);
    SummaryProgress::note(t('audio_reports.summaries.progress.propose_build'));
    $prop = SummaryCatalog::propose($catalog);
    SummaryProgress::mark(t('audio_reports.summaries.progress.propose_done'));
    $result = [
        'ok' => 1,
        'action' => 'propose',
        'name' => $prop['NAME'],
        'config' => $prop['config'],
        'labels' => $prop['labels'],
        'catalog' => [
            'position_name' => $catalog['position_name'],
            'types' => $catalog['types'],
            'scripts' => $catalog['scripts'],
            'dicts' => $catalog['dicts'],
            'employees_count' => $catalog['employees_count'],
            'volume' => $catalog['volume'],
        ],
    ];
    if ($wantsJson) {
        $result['stages'] = SummaryProgress::collectStages();
        SummaryProgress::reset();
        AgentHttp::emit($result);
    }
    SummaryProgress::done($result);
    return;
}

if ($action === 'preview') {
    if ($wantsJson) {
        SummaryProgress::beginCollect();
    } else {
        SummaryProgress::start(t('audio_reports.summaries.progress.preview'));
    }
    $config = SummariesRepository::parseConfigFromRequest($req);
    $labels = SummariesRepository::parseLabelsFromRequest($req);
    $name = trim((string)($req['name'] ?? ''));
    if ($name === '') {
        $name = t('audio_reports.summaries.preview_name');
    }
    $profile = [
        'ID' => 0,
        'NAME' => $name,
        'ID_POSITION' => $positionId,
        'IS_DEFAULT' => !empty($req['is_default']) ? 1 : 0,
        'config' => $config,
        'labels' => $labels,
    ];
    $summary = SummaryRunner::run($profile, $year, $month, $dateFrom, $dateTo);
    SummaryProgress::note(t('audio_reports.summaries.progress.preview_render'));
    $vm = SummaryRenderer::build($summary, ['preview' => true]);
    $html = View::capture('_summaries_report', [
        'vm' => $vm,
        'report' => $summary,
        'preview' => true,
    ], 'audio_reports');
    SummaryProgress::mark(t('audio_reports.summaries.progress.preview_done'));
    $result = [
        'ok' => 1,
        'action' => 'preview',
        'html' => $html,
        'report' => $wantsJson ? $summary : null,
        'counts' => SummaryRenderer::countTargets($summary),
    ];
    if (!$wantsJson) {
        unset($result['report']);
    }
    if ($wantsJson) {
        $result['stages'] = SummaryProgress::collectStages();
        SummaryProgress::reset();
        AgentHttp::emit($result);
    }
    SummaryProgress::done($result);
    return;
}

if ($wantsJson) {
    AgentHttp::error(t('audio_reports.summaries.err.unknown_action'), 400);
}
header('Content-Type: text/html; charset=UTF-8');
echo '<h2>' . e(t('audio_reports.summaries.err.unknown_action')) . '</h2>';
