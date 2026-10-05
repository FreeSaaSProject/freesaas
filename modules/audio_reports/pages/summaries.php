<?php
declare(strict_types=1);

require_once base_path('modules/audio_reports/lib/SummariesRepository.php');
require_once base_path('modules/audio_reports/lib/SummaryRunner.php');
require_once base_path('modules/audio_reports/lib/CalendarHooks.php');

$path = (string)($_SERVER['REQUEST_URI'] ?? '');
$pathOnly = parse_url($path, PHP_URL_PATH) ?: '';
$isNew = $pathOnly === '/audio/summaries/new'
    || substr($pathOnly, -strlen('/summaries/new')) === '/summaries/new';
$idParam = Router::param('id');
$id = $idParam !== null && $idParam !== '' ? (int)$idParam : 0;

$flash = '';
$error = '';
$canEdit = Auth::can('audio_reports.edit');
$creatorId = Auth::userId();

// POST handlers for edit/create/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'delete' && $id > 0) {
        SummariesRepository::delete($id);
        redirect('/audio/summaries?flash=deleted');
    }

    $configRaw = (string)($_POST['config_json'] ?? '{}');
    $labelsRaw = (string)($_POST['labels_json'] ?? '{}');
    $configDecoded = json_decode($configRaw, true);
    $labelsDecoded = json_decode($labelsRaw, true);
    if (!is_array($configDecoded) || !is_array($labelsDecoded)) {
        $error = t('audio_reports.summaries.invalid_json');
    } else {
        $payload = [
            'NAME' => (string)($_POST['name'] ?? ''),
            'ID_POSITION' => (int)($_POST['id_position'] ?? 0),
            'CONFIG_JSON' => $configDecoded,
            'LABELS_JSON' => $labelsDecoded,
            'IS_DEFAULT' => !empty($_POST['is_default']) ? 1 : 0,
            'ENABLED' => !empty($_POST['enabled']) ? 1 : 0,
            'ID_CREATOR' => $creatorId,
        ];
        if ($isNew || $id < 1) {
            $newId = SummariesRepository::create($payload);
            redirect('/audio/summaries/' . $newId . '?flash=saved');
        }
        SummariesRepository::update($id, $payload);
        redirect('/audio/summaries/' . $id . '?flash=saved');
    }
}

if ((string)($_GET['flash'] ?? '') === 'saved') {
    $flash = t('audio_reports.summaries.saved');
} elseif ((string)($_GET['flash'] ?? '') === 'deleted') {
    $flash = t('audio_reports.summaries.deleted');
}

// List mode
if (!$isNew && $id < 1) {
    $profiles = SummariesRepository::list(false);
    $default = SummariesRepository::findDefault();
    $runId = (int)($_GET['profile'] ?? ($default['ID'] ?? 0));
    $year = (int)($_GET['year'] ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));
    $dateFrom = trim((string)($_GET['date_from'] ?? ''));
    $dateTo = trim((string)($_GET['date_to'] ?? ''));
    $report = null;
    $runProfile = $runId > 0 ? SummariesRepository::find($runId) : $default;
    if ($runProfile && !empty($_GET['run'])) {
        $report = SummaryRunner::run($runProfile, $year, $month, $dateFrom, $dateTo);
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
    ], 'layout', 'audio_reports');
    return;
}

// Edit / new
if (!$canEdit && $isNew) {
    http_response_code(403);
    echo '403';
    return;
}

$profile = null;
if (!$isNew) {
    $profile = SummariesRepository::find($id);
    if (!$profile) {
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

$configJson = json_encode(
    $profile['config'] ?? SummariesRepository::defaultConfig(),
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
$labelsJson = json_encode(
    $profile['labels'] ?? SummariesRepository::defaultLabels(),
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
if ($error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $profile = [
        'ID' => $id,
        'NAME' => (string)($_POST['name'] ?? ($profile['NAME'] ?? '')),
        'ID_POSITION' => (int)($_POST['id_position'] ?? ($profile['ID_POSITION'] ?? 0)),
        'IS_DEFAULT' => !empty($_POST['is_default']) ? 1 : 0,
        'ENABLED' => !empty($_POST['enabled']) ? 1 : 0,
    ];
    $configJson = (string)($_POST['config_json'] ?? $configJson);
    $labelsJson = (string)($_POST['labels_json'] ?? $labelsJson);
}

View::render('summaries_edit', [
    'title' => $isNew ? t('audio_reports.summaries.new') : t('audio_reports.summaries.edit'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.group'), 'href' => '/audio'],
        ['label' => t('audio_reports.menu.summaries'), 'href' => '/audio/summaries'],
        ['label' => $isNew ? t('audio_reports.summaries.new') : (string)($profile['NAME'] ?? ''), 'current' => true],
    ]),
    'isNew' => $isNew,
    'profile' => $profile,
    'configJson' => $configJson !== false ? $configJson : '{}',
    'labelsJson' => $labelsJson !== false ? $labelsJson : '{}',
    'canEdit' => $canEdit,
    'flash' => $flash,
    'error' => $error,
], 'layout', 'audio_reports');
