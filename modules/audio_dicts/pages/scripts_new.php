<?php
declare(strict_types=1);

$path = '/audio/scripts';
$error = '';
$meta = [
    'name' => '',
    'disabled' => 0,
    'position_ids' => [],
];
$parts = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $meta = RecordsScriptsRepository::metaFromPost($_POST);
        $meta['position_ids'] = RecordsScriptsRepository::parseIds((string)($meta['id_position'] ?? ''));
        $parts = RecordsScriptsRepository::partsFromPost($_POST);
        if ($parts === []) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.err.parts_required'));
        }
        $newId = RecordsScriptsRepository::create($meta, $parts);
        redirect($path . '/' . $newId);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $parts = RecordsScriptsRepository::partsFromPost($_POST);
        $meta = [
            'name' => trim((string)($_POST['name'] ?? '')),
            'disabled' => !empty($_POST['disabled']) ? 1 : 0,
            'position_ids' => [],
        ];
        $posRaw = $_POST['positions'] ?? $_POST['id_position'] ?? [];
        if (is_array($posRaw)) {
            $meta['position_ids'] = array_values(array_filter(array_map('intval', $posRaw)));
        } else {
            $meta['position_ids'] = RecordsScriptsRepository::parseIds((string)$posRaw);
        }
    }
}

View::render('scripts_wizard', [
    'title' => t('audio_dicts.scripts.wizard_title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.group'), 'href' => '/audio'],
        ['label' => t('audio_dicts.menu.scripts'), 'href' => $path],
        ['label' => t('audio_dicts.scripts.wizard_title'), 'current' => true],
    ]),
    'path' => $path,
    'error' => $error,
    'meta' => $meta,
    'parts' => $parts,
    'dictOptions' => RecordsDictsRepository::options(false),
    'positionOptions' => RecordsScriptsRepository::positionOptions(),
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
], 'layout', 'audio_dicts');
