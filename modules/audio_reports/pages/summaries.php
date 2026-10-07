<?php
declare(strict_types=1);

require_once base_path('modules/audio_reports/lib/SummariesRepository.php');
require_once base_path('modules/audio_reports/lib/SummaryRunner.php');
require_once base_path('modules/audio_reports/lib/SummaryCatalog.php');
require_once base_path('modules/audio_reports/lib/SummaryRenderer.php');
require_once base_path('modules/audio_reports/lib/SummaryProgress.php');

$path = (string)($_SERVER['REQUEST_URI'] ?? '');
$pathOnly = (string)(parse_url($path, PHP_URL_PATH) ?: '');
$isNew = $pathOnly === '/audio/summaries/new'
    || substr($pathOnly, -strlen('/summaries/new')) === '/summaries/new';
$idParam = Router::param('id');
$id = $idParam !== null && $idParam !== '' ? (int)$idParam : 0;

$flash = '';
$error = '';
$canEdit = Auth::can('audio_reports.edit');
$creatorId = Auth::userId();
$wantsJson = AgentHttp::wantsJson();

/**
 * @param array<string,mixed> $req
 * @return array{NAME:string,ID_POSITION:int,CONFIG_JSON:array,LABELS_JSON:array,IS_DEFAULT:int,ENABLED:int,ID_CREATOR:int}
 */
$buildPayload = static function (array $req) use ($creatorId): array {
    // Prefer structured form fields; raw JSON is a power-user fallback only.
    $hasStructured = isset($req['cards']) || isset($req['criteria_type_ids'])
        || isset($req['script_ids']) || isset($req['external_sources'])
        || isset($req['computed_metrics']) || isset($req['score_pass_threshold']);
    $hasLabelFields = false;
    foreach ($req as $rk => $_rv) {
        if (is_string($rk) && strpos($rk, 'label_') === 0) {
            $hasLabelFields = true;
            break;
        }
    }
    if ($hasStructured) {
        $config = SummariesRepository::parseConfigFromRequest($req);
    } elseif (isset($req['config_json']) && is_string($req['config_json']) && trim($req['config_json']) !== '') {
        $decoded = json_decode((string)$req['config_json'], true);
        $config = is_array($decoded)
            ? SummariesRepository::normalizeConfig($decoded)
            : SummariesRepository::defaultConfig();
    } else {
        $config = SummariesRepository::parseConfigFromRequest($req);
    }
    if ($hasLabelFields || isset($req['labels'])) {
        $labels = SummariesRepository::parseLabelsFromRequest($req);
    } elseif (isset($req['labels_json']) && is_string($req['labels_json']) && trim($req['labels_json']) !== '') {
        $decodedL = json_decode((string)$req['labels_json'], true);
        $labels = is_array($decodedL)
            ? SummariesRepository::normalizeLabels($decodedL)
            : SummariesRepository::defaultLabels();
    } else {
        $labels = SummariesRepository::parseLabelsFromRequest($req);
    }
    return [
        'NAME' => trim((string)($req['name'] ?? '')),
        'ID_POSITION' => (int)($req['id_position'] ?? 0),
        'CONFIG_JSON' => $config,
        'LABELS_JSON' => $labels,
        'IS_DEFAULT' => !empty($req['is_default']) ? 1 : 0,
        'ENABLED' => array_key_exists('enabled', $req) ? (!empty($req['enabled']) ? 1 : 0) : 1,
        'ID_CREATOR' => $creatorId,
    ];
};

// ——— POST: save / delete ———
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        if ($wantsJson) {
            AgentHttp::error(t('audio_reports.summaries.err.forbidden'), 403);
        }
        http_response_code(403);
        echo '403';
        return;
    }
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'delete' && $id > 0) {
        $existing = SummariesRepository::find($id);
        if (!$existing || !SummariesRepository::profileAllowedForViewer($existing)) {
            if ($wantsJson) {
                AgentHttp::error(t('audio_reports.summaries.not_found'), 404);
            }
            redirect('/audio/summaries?flash=deleted');
        }
        SummariesRepository::delete($id);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'action' => 'delete', 'id' => $id]);
        }
        redirect('/audio/summaries?flash=deleted');
    }

    $payload = $buildPayload($_POST);
    if ($payload['NAME'] === '' || $payload['ID_POSITION'] <= 0) {
        $error = t('audio_reports.summaries.err.name_position');
        if ($wantsJson) {
            AgentHttp::error($error, 400);
        }
    } else {
        try {
            if ($isNew || $id < 1) {
                $newId = SummariesRepository::create($payload);
                if ($wantsJson) {
                    AgentHttp::emit([
                        'ok' => true,
                        'action' => 'save',
                        'id' => $newId,
                        'created' => true,
                        'profile' => SummariesRepository::find($newId),
                    ]);
                }
                redirect('/audio/summaries/' . $newId . '?flash=saved');
            }
            $existing = SummariesRepository::find($id);
            if (!$existing || !SummariesRepository::profileAllowedForViewer($existing)) {
                if ($wantsJson) {
                    AgentHttp::error(t('audio_reports.summaries.not_found'), 404);
                }
                $error = t('audio_reports.summaries.not_found');
            } else {
                SummariesRepository::update($id, $payload);
                if ($wantsJson) {
                    AgentHttp::emit([
                        'ok' => true,
                        'action' => 'save',
                        'id' => $id,
                        'created' => false,
                        'profile' => SummariesRepository::find($id),
                    ]);
                }
                redirect('/audio/summaries/' . $id . '?flash=saved');
            }
        } catch (Throwable $e) {
            $error = t('audio_reports.summaries.err.save');
            if ($wantsJson) {
                AgentHttp::error($error, 500, ['detail' => $e->getMessage()]);
            }
        }
    }
}

if ((string)($_GET['flash'] ?? '') === 'saved') {
    $flash = t('audio_reports.summaries.saved');
} elseif ((string)($_GET['flash'] ?? '') === 'deleted') {
    $flash = t('audio_reports.summaries.deleted');
}

// ——— List / run ———
if (!$isNew && $id < 1) {
    $profiles = SummariesRepository::filterProfilesForViewer(SummariesRepository::list(false));
    $default = null;
    foreach ($profiles as $p) {
        if (!empty($p['IS_DEFAULT']) && !empty($p['ENABLED'])) {
            $default = $p;
            break;
        }
    }
    if ($default === null) {
        foreach ($profiles as $p) {
            if (!empty($p['ENABLED'])) {
                $default = $p;
                break;
            }
        }
    }
    $runId = (int)($_GET['profile'] ?? ($default['ID'] ?? 0));
    $year = (int)($_GET['year'] ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));
    $dateFrom = trim((string)($_GET['date_from'] ?? ''));
    $dateTo = trim((string)($_GET['date_to'] ?? ''));
    $report = null;
    $runProfile = null;
    if ($runId > 0) {
        foreach ($profiles as $p) {
            if ((int)$p['ID'] === $runId) {
                $runProfile = $p;
                break;
            }
        }
    }
    if ($runProfile === null) {
        $runProfile = $default;
    }
    // HTML: always build when a profile is available. JSON: only when run=1 (keeps list cheap for agents).
    $shouldRun = $runProfile !== null && (
        !$wantsJson || (string)($_GET['run'] ?? '') === '1'
    );
    if ($shouldRun) {
        $report = SummaryRunner::run($runProfile, $year, $month, $dateFrom, $dateTo);
    }

    if ($wantsJson) {
        $payload = [
            'ok' => true,
            'profiles' => $profiles,
            'default_id' => $default ? (int)$default['ID'] : 0,
        ];
        if ($report !== null) {
            $payload['report'] = $report;
            $payload['counts'] = SummaryRenderer::countTargets($report);
        }
        AgentHttp::emit($payload);
    }

    $vm = null;
    if (is_array($report)) {
        $vm = SummaryRenderer::build($report, [
            'profiles' => $profiles,
            'can_edit' => $canEdit,
        ]);
    }

    View::render('summaries_list', [
        'title' => t('audio_reports.menu.summaries'),
        'breadcrumbs' => Breadcrumbs::withHome([
            ['label' => t('audio.menu.group'), 'href' => '/audio'],
            ['label' => t('audio_reports.menu.summaries'), 'current' => true],
        ]),
        'profiles' => $profiles,
        'canEdit' => $canEdit,
        'flash' => $flash,
        'runProfile' => $runProfile,
        'year' => $year,
        'month' => $month,
        'dateFrom' => $dateFrom,
        'dateTo' => $dateTo,
        'report' => $report,
        'vm' => $vm,
    ], 'layout', 'audio_reports');
    return;
}

// ——— Edit / get ———
if (!$canEdit && $isNew) {
    if ($wantsJson) {
        AgentHttp::error(t('audio_reports.summaries.err.forbidden'), 403);
    }
    http_response_code(403);
    echo '403';
    return;
}

$profile = null;
if (!$isNew) {
    $profile = SummariesRepository::find($id);
    if (!$profile || !SummariesRepository::profileAllowedForViewer($profile)) {
        if ($wantsJson) {
            AgentHttp::error(t('audio_reports.summaries.not_found'), 404);
        }
        View::render('stub', [
            'title' => t('audio_reports.menu.summaries'),
            'breadcrumbs' => Breadcrumbs::withHome([
                ['label' => t('audio.menu.group'), 'href' => '/audio'],
                ['label' => t('audio_reports.menu.summaries'), 'href' => '/audio/summaries'],
                ['label' => t('audio_reports.summaries.not_found'), 'current' => true],
            ]),
            'message' => t('audio_reports.summaries.not_found'),
        ], 'layout', 'audio_reports');
        return;
    }
}

if ($wantsJson && !$isNew && $_SERVER['REQUEST_METHOD'] === 'GET') {
    AgentHttp::emit(['ok' => true, 'profile' => $profile]);
}

$config = $profile['config'] ?? SummariesRepository::defaultConfig();
$labels = $profile['labels'] ?? SummariesRepository::defaultLabels();
if ($error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $profile = [
        'ID' => $id,
        'NAME' => (string)($_POST['name'] ?? ($profile['NAME'] ?? '')),
        'ID_POSITION' => (int)($_POST['id_position'] ?? ($profile['ID_POSITION'] ?? 0)),
        'IS_DEFAULT' => !empty($_POST['is_default']) ? 1 : 0,
        'ENABLED' => !empty($_POST['enabled']) ? 1 : 0,
        'config' => SummariesRepository::parseConfigFromRequest($_POST),
        'labels' => SummariesRepository::parseLabelsFromRequest($_POST),
    ];
    $config = $profile['config'];
    $labels = $profile['labels'];
}

$positionId = (int)($profile['ID_POSITION'] ?? 0);
$year = (int)($_GET['year'] ?? $_POST['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? $_POST['month'] ?? date('n'));
$catalog = null;
if ($positionId > 0) {
    SummaryProgress::beginCollect();
    try {
        $catalog = SummaryCatalog::discover($positionId, $year, $month);
    } catch (Throwable $e) {
        $catalog = null;
    }
    SummaryProgress::reset();
}

$positions = StatusesRepository::listAll(['order' => 'NAME ASC']);
$fieldOptions = SummaryRenderer::fieldOptions($config);
$configJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$labelsJson = json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

View::render('summaries_edit', [
    'title' => $isNew ? t('audio_reports.summaries.new') : t('audio_reports.summaries.edit'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.group'), 'href' => '/audio'],
        ['label' => t('audio_reports.menu.summaries'), 'href' => '/audio/summaries'],
        [
            'label' => $isNew ? t('audio_reports.summaries.new') : (string)($profile['NAME'] ?? ''),
            'current' => true,
        ],
    ]),
    'isNew' => $isNew,
    'profile' => $profile,
    'config' => $config,
    'labels' => $labels,
    'configJson' => $configJson !== false ? $configJson : '{}',
    'labelsJson' => $labelsJson !== false ? $labelsJson : '{}',
    'catalog' => $catalog,
    'positions' => $positions,
    'fieldOptions' => $fieldOptions,
    'year' => $year,
    'month' => $month,
    'canEdit' => $canEdit,
    'flash' => $flash,
    'error' => $error,
], 'layout', 'audio_reports');
