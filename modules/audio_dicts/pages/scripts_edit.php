<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$path = '/audio/scripts';
$id = (int)(Router::param('id') ?? 0);
$canEdit = Auth::can('audio_dicts.edit');
$tree = RecordsScriptsRepository::findWithParts($id);
if ($tree === null) {
    if ($wantsJson) {
        AgentHttp::error(t('audio_dicts.scripts.err.not_found', ['id' => $id]), 404);
        return;
    }
    http_response_code(404);
    View::render('error', [
        'title' => t('audio_dicts.scripts.err.not_found', ['id' => $id]),
        'message' => t('audio_dicts.scripts.err.not_found', ['id' => $id]),
    ], 'error', 'core');
    return;
}

$error = '';
$flash = '';

if (EntityDelete::tryHandle('audio_scripts', $id, $path, [
    'stay_path' => $path . '/' . $id,
])) {
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    try {
        $meta = RecordsScriptsRepository::metaFromPost($_POST);
        $parts = RecordsScriptsRepository::partsFromPost($_POST);
        if ($parts === []) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.err.parts_required'));
        }
        RecordsScriptsRepository::update($id, $meta, $parts);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'tree' => RecordsScriptsRepository::findWithParts($id)]);
            return;
        }
        redirect($path . '/' . $id . '?saved=1');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $tree = [
            'root' => array_merge($tree['root'], [
                'name' => trim((string)($_POST['name'] ?? $tree['root']['name'])),
                'disabled' => !empty($_POST['disabled']) ? 1 : 0,
                'position_ids' => [],
            ]),
            'parts' => RecordsScriptsRepository::partsFromPost($_POST),
        ];
        $posRaw = $_POST['positions'] ?? [];
        if (is_array($posRaw)) {
            $tree['root']['position_ids'] = array_values(array_filter(array_map('intval', $posRaw)));
        }
    }
}

if (!empty($_GET['saved'])) {
    $flash = t('audio_dicts.scripts.saved');
}
$importWarnings = [];
if (!empty($_SESSION['audio_dicts_script_import_warnings']) && is_array($_SESSION['audio_dicts_script_import_warnings'])) {
    $importWarnings = array_values(array_map('strval', $_SESSION['audio_dicts_script_import_warnings']));
    unset($_SESSION['audio_dicts_script_import_warnings']);
    if ($flash === '' && $importWarnings !== []) {
        $flash = t('audio_dicts.scripts.pack.imported');
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'id' => $id,
        'tree' => $tree,
        'can_edit' => $canEdit,
        'parts_url' => $path . '/' . $id . '/parts',
    ]);
    return;
}

$dayMetricCatalog = [];
if (class_exists('ModuleManager', false) && ModuleManager::isEnabled('audio_reports')) {
    if (!class_exists('DayQualityMetrics', false)) {
        $dqm = base_path('modules/audio_reports/lib/DayQualityMetrics.php');
        if (is_file($dqm)) {
            require_once $dqm;
        }
    }
    if (class_exists('DayQualityMetrics', false)) {
        $dayMetricCatalog = DayQualityMetrics::catalog();
    }
}
if ($dayMetricCatalog === []) {
    $dayMetricCatalog = [
        ['id' => 'audio_playtime', 'label' => t('audio_dicts.scripts.metric.audio_playtime'), 'unit' => 's'],
        ['id' => 'script_starts', 'label' => t('audio_dicts.scripts.metric.script_starts'), 'unit' => ''],
        ['id' => 'script_finishes', 'label' => t('audio_dicts.scripts.metric.script_finishes'), 'unit' => ''],
        ['id' => 'proc_ratio', 'label' => t('audio_dicts.scripts.metric.proc_ratio'), 'unit' => '%'],
    ];
}

View::render('scripts_canvas', [
    'title' => t('audio_dicts.scripts.edit_title', ['name' => (string)$tree['root']['name']]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.group'), 'href' => '/audio'],
        ['label' => t('audio_dicts.menu.scripts'), 'href' => $path],
        ['label' => (string)$tree['root']['name'], 'current' => true],
    ]),
    'path' => $path,
    'id' => $id,
    'root' => $tree['root'],
    'parts' => $tree['parts'],
    'canEdit' => $canEdit,
    'error' => $error,
    'flash' => $flash,
    'importWarnings' => $importWarnings,
    'dictOptions' => RecordsDictsRepository::options(true),
    'dictPositionMap' => RecordsDictsRepository::positionMap(true),
    'positionOptions' => RecordsScriptsRepository::positionOptions(),
    'dayMetricCatalog' => $dayMetricCatalog,
    'roleOptions' => [
        'ordinary' => t('audio_dicts.scripts.role.ordinary'),
        'trigger' => t('audio_dicts.scripts.role.trigger'),
        'conversion' => t('audio_dicts.scripts.role.conversion'),
        'breaker' => t('audio_dicts.scripts.role.breaker'),
        'killer' => t('audio_dicts.scripts.role.killer'),
    ],
    'roleHints' => [
        'ordinary' => t('audio_dicts.scripts.role.ordinary_hint'),
        'trigger' => t('audio_dicts.scripts.role.trigger_hint'),
        'conversion' => t('audio_dicts.scripts.role.conversion_hint'),
        'breaker' => t('audio_dicts.scripts.role.breaker_hint'),
        'killer' => t('audio_dicts.scripts.role.killer_hint'),
    ],
    'partsUrl' => $path . '/' . $id . '/parts',
], 'layout', 'audio_dicts');
