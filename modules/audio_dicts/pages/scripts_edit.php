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
$editingVersionId = max(0, (int)($_GET['editing'] ?? $_POST['editing_version_id'] ?? 0));

if (EntityDelete::tryHandle('audio_scripts', $id, $path, [
    'stay_path' => $path . '/' . $id,
])) {
    return;
}

// Enter a published version into the working tree for editing.
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && $canEdit
    && !empty($_GET['open_version'])
    && class_exists('ScriptVersionsRepository', false)
    && ScriptVersionsRepository::tableReady()
) {
    try {
        $opened = ScriptVersionsRepository::openForEdit($id, (int)$_GET['open_version']);
        redirect($path . '/' . $id . '?editing=' . (int)$opened['version_id'] . '&opened=1');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $action = trim((string)($_POST['action'] ?? 'save'));
    if ($action === 'set_valid_from'
        && class_exists('ScriptVersionsRepository', false)
        && ScriptVersionsRepository::tableReady()
    ) {
        try {
            $versionId = (int)($_POST['version_id'] ?? 0);
            $date = trim((string)($_POST['valid_from_date'] ?? ''));
            $validFrom = 0;
            if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $tz = date_default_timezone_get() ?: 'UTC';
                $dt = DateTimeImmutable::createFromFormat(
                    'Y-m-d H:i:s',
                    $date . ' 00:00:00',
                    new DateTimeZone($tz)
                );
                if ($dt instanceof DateTimeImmutable) {
                    $validFrom = $dt->getTimestamp();
                }
            }
            $result = ScriptVersionsRepository::updateValidFrom($id, $versionId, $validFrom);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'id' => $id, 'valid_from' => $result]);
                return;
            }
            $editQ = $editingVersionId > 0 ? '&editing=' . $editingVersionId : '';
            redirect($path . '/' . $id . '?valid_from_saved=1&v=' . (int)$result['version'] . $editQ);
        } catch (Throwable $e) {
            if ($wantsJson) {
                AgentHttp::error($e->getMessage(), 400);
                return;
            }
            $error = $e->getMessage();
        }
    } elseif ($action === 'publish'
        && class_exists('ScriptVersionsRepository', false)
        && ScriptVersionsRepository::tableReady()
    ) {
        try {
            $mode = trim((string)($_POST['publish_mode'] ?? ScriptVersionsRepository::MODE_FROM_DATE));
            if ($mode === ScriptVersionsRepository::MODE_REWRITE
                && empty($_POST['publish_rewrite_confirm'])
            ) {
                throw new InvalidArgumentException(t('audio_dicts.scripts.versions.err.rewrite_confirm'));
            }
            $parts = RecordsScriptsRepository::partsFromPost($_POST);
            if ($parts !== []) {
                $meta = $wantsJson
                    ? RecordsScriptsRepository::metaFromPostOverRoot($_POST, $tree['root'])
                    : RecordsScriptsRepository::metaFromPost($_POST);
                RecordsScriptsRepository::update($id, $meta, $parts);
            }
            $result = ScriptVersionsRepository::publish($id, [
                'mode' => $mode,
                'valid_from_date' => trim((string)($_POST['valid_from_date'] ?? '')),
            ]);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $id,
                    'published' => $result,
                    'tree' => RecordsScriptsRepository::findWithParts($id),
                ]);
                return;
            }
            $q = !empty($result['unchanged']) ? 'published_unchanged=1' : 'published=1';
            redirect($path . '/' . $id . '?' . $q . '&v=' . (int)$result['version']);
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
                'parts' => RecordsScriptsRepository::partsFromPost($_POST) ?: ($tree['parts'] ?? []),
            ];
            $posRaw = $_POST['positions'] ?? [];
            if (is_array($posRaw)) {
                $tree['root']['position_ids'] = array_values(array_filter(array_map('intval', $posRaw)));
            }
        }
    } elseif ($action === 'restore'
        && class_exists('ScriptVersionsRepository', false)
        && ScriptVersionsRepository::tableReady()
    ) {
        try {
            $versionId = (int)($_POST['version_id'] ?? 0);
            if ($versionId < 1) {
                throw new InvalidArgumentException(t('audio_dicts.scripts.versions.err.version_required'));
            }
            if (empty($_POST['restore_confirm'])) {
                throw new InvalidArgumentException(t('audio_dicts.scripts.versions.err.restore_confirm'));
            }
            $draftOnly = !empty($_POST['restore_draft_only']);
            $result = ScriptVersionsRepository::restore($id, $versionId, [
                'publish' => !$draftOnly,
                'mode' => ScriptVersionsRepository::MODE_REWRITE,
            ]);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $id,
                    'restored' => $result,
                    'tree' => RecordsScriptsRepository::findWithParts($id),
                ]);
                return;
            }
            $q = $draftOnly ? 'restored_draft=1' : 'restored=1';
            $v = (int)($result['published']['version'] ?? $result['restored_from'] ?? 0);
            redirect($path . '/' . $id . '?' . $q . '&v=' . $v . '&from=' . (int)$result['restored_from']);
        } catch (Throwable $e) {
            if ($wantsJson) {
                AgentHttp::error($e->getMessage(), 400);
                return;
            }
            $error = $e->getMessage();
        }
    } else {
        // Default save — with versioning, target comes from dialog.
        try {
            $meta = $wantsJson
                ? RecordsScriptsRepository::metaFromPostOverRoot($_POST, $tree['root'])
                : RecordsScriptsRepository::metaFromPost($_POST);
            $parts = RecordsScriptsRepository::partsFromPost($_POST);
            if ($parts === []) {
                throw new InvalidArgumentException(t('audio_dicts.scripts.err.parts_required'));
            }
            $hasVersions = class_exists('ScriptVersionsRepository', false)
                && ScriptVersionsRepository::tableReady();
            if ($hasVersions) {
                $target = trim((string)($_POST['save_target'] ?? ''));
                if ($target === '') {
                    throw new InvalidArgumentException(t('audio_dicts.scripts.versions.err.save_target'));
                }
                $result = ScriptVersionsRepository::saveFromEditor($id, $meta, $parts, [
                    'target' => $target === 'existing' ? 'existing' : 'new',
                    'version_id' => (int)($_POST['save_version_id'] ?? 0),
                    'valid_from_date' => trim((string)($_POST['save_valid_from_date'] ?? '')),
                    'mode' => ScriptVersionsRepository::MODE_FROM_DATE,
                    'editing_version_id' => $editingVersionId,
                ]);
                if ($wantsJson) {
                    AgentHttp::emit([
                        'ok' => true,
                        'id' => $id,
                        'saved' => $result,
                        'tree' => RecordsScriptsRepository::findWithParts($id),
                    ]);
                    return;
                }
                $vid = (int)($result['saved']['id'] ?? 0);
                $vnum = (int)($result['saved']['version'] ?? 0);
                if (($result['target'] ?? '') === 'existing') {
                    redirect($path . '/' . $id . '?saved_into=1&v=' . $vnum . '&editing=' . $vid);
                }
                redirect($path . '/' . $id . '?saved_new=1&v=' . $vnum . '&editing=' . $vid);
            }
            RecordsScriptsRepository::update($id, $meta, $parts);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $id,
                    'tree' => RecordsScriptsRepository::findWithParts($id),
                ]);
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
}

// Refresh tree after open/save errors may have mutated DB.
$fresh = RecordsScriptsRepository::findWithParts($id);
if ($fresh !== null) {
    $tree = $fresh;
}

if (!empty($_GET['saved'])) {
    $flash = t('audio_dicts.scripts.saved');
}
if (!empty($_GET['saved_new'])) {
    $flash = t('audio_dicts.scripts.versions.saved_new', [
        'v' => (string)max(0, (int)($_GET['v'] ?? 0)),
    ]);
}
if (!empty($_GET['saved_into'])) {
    $flash = t('audio_dicts.scripts.versions.saved_into', [
        'v' => (string)max(0, (int)($_GET['v'] ?? 0)),
    ]);
}
if (!empty($_GET['valid_from_saved'])) {
    $flash = t('audio_dicts.scripts.versions.valid_from_saved', [
        'v' => (string)max(0, (int)($_GET['v'] ?? 0)),
    ]);
}
if (!empty($_GET['opened'])) {
    $flash = t('audio_dicts.scripts.versions.opened', [
        'v' => '',
    ]);
}
if (!empty($_GET['published'])) {
    $flash = t('audio_dicts.scripts.versions.published', [
        'v' => (string)max(0, (int)($_GET['v'] ?? 0)),
    ]);
}
if (!empty($_GET['published_unchanged'])) {
    $flash = t('audio_dicts.scripts.versions.published_unchanged');
}
if (!empty($_GET['restored'])) {
    $flash = t('audio_dicts.scripts.versions.restored', [
        'from' => (string)max(0, (int)($_GET['from'] ?? 0)),
        'v' => (string)max(0, (int)($_GET['v'] ?? 0)),
    ]);
}
if (!empty($_GET['restored_draft'])) {
    $flash = t('audio_dicts.scripts.versions.restored_draft', [
        'from' => (string)max(0, (int)($_GET['from'] ?? 0)),
    ]);
}
$importWarnings = [];
if (!empty($_SESSION['audio_dicts_script_import_warnings']) && is_array($_SESSION['audio_dicts_script_import_warnings'])) {
    $importWarnings = array_values(array_map('strval', $_SESSION['audio_dicts_script_import_warnings']));
    unset($_SESSION['audio_dicts_script_import_warnings']);
    if ($flash === '' && $importWarnings !== []) {
        $flash = t('audio_dicts.scripts.pack.imported');
    }
}

$versioning = [
    'ready' => false,
    'dirty' => false,
    'current' => null,
    'versions' => [],
    'editing_version_id' => 0,
    'editing_version' => null,
];
if (class_exists('ScriptVersionsRepository', false) && ScriptVersionsRepository::tableReady()) {
    ScriptVersionsRepository::ensureBackfilled($id);
    $cur = ScriptVersionsRepository::current($id);
    $versions = ScriptVersionsRepository::listForScript($id);
    $editingMeta = null;
    if ($editingVersionId > 0) {
        $ev = ScriptVersionsRepository::find($editingVersionId);
        if ($ev !== null && (int)$ev['id_script'] === $id) {
            $editingMeta = [
                'id' => (int)$ev['id'],
                'version' => (int)$ev['version'],
                'valid_from' => (int)$ev['valid_from'],
                'status' => (string)$ev['status'],
            ];
        } else {
            $editingVersionId = 0;
        }
    }
    if (!empty($_GET['opened']) && $editingMeta !== null) {
        $flash = t('audio_dicts.scripts.versions.opened', [
            'v' => (string)(int)$editingMeta['version'],
        ]);
    }
    $dirtyBaselineId = $editingVersionId > 0
        ? $editingVersionId
        : ($cur !== null ? (int)$cur['id'] : null);
    $versioning = [
        'ready' => true,
        'dirty' => ScriptVersionsRepository::isDirtyAgainst($id, $dirtyBaselineId),
        'current' => $cur === null ? null : [
            'id' => (int)$cur['id'],
            'version' => (int)$cur['version'],
            'valid_from' => (int)$cur['valid_from'],
            'status' => (string)$cur['status'],
        ],
        'versions' => $versions,
        'editing_version_id' => $editingVersionId,
        'editing_version' => $editingMeta,
    ];
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'id' => $id,
        'tree' => $tree,
        'can_edit' => $canEdit,
        'parts_url' => $path . '/' . $id . '/parts',
        'versioning' => $versioning,
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
        ['id' => 'proc_overlap', 'label' => t('audio_dicts.scripts.metric.proc_overlap'), 'unit' => '%'],
    ];
}

$titleName = (string)$tree['root']['name'];
if (!empty($versioning['editing_version']['version'])) {
    $titleName .= ' · v' . (int)$versioning['editing_version']['version'];
}

View::render('scripts_canvas', [
    'title' => t('audio_dicts.scripts.edit_title', ['name' => $titleName]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.group'), 'href' => '/audio'],
        ['label' => t('audio_dicts.menu.scripts'), 'href' => $path],
        ['label' => $titleName, 'current' => true],
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
        'closer' => t('audio_dicts.scripts.role.closer'),
        'breaker' => t('audio_dicts.scripts.role.breaker'),
        'killer' => t('audio_dicts.scripts.role.killer'),
    ],
    'roleHints' => [
        'ordinary' => t('audio_dicts.scripts.role.ordinary_hint'),
        'trigger' => t('audio_dicts.scripts.role.trigger_hint'),
        'conversion' => t('audio_dicts.scripts.role.conversion_hint'),
        'closer' => t('audio_dicts.scripts.role.closer_hint'),
        'breaker' => t('audio_dicts.scripts.role.breaker_hint'),
        'killer' => t('audio_dicts.scripts.role.killer_hint'),
    ],
    'partsUrl' => $path . '/' . $id . '/parts',
    'versioning' => $versioning,
], 'layout', 'audio_dicts');
