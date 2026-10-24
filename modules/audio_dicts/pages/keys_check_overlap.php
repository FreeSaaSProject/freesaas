<?php
declare(strict_types=1);

/**
 * Live overlap probe for the keys edit form (same rules as audio list add-key).
 */
header('Content-Type: application/json; charset=utf-8');

$name = trim((string)($_GET['name'] ?? $_POST['name'] ?? ''));
$excludeId = max(0, (int)($_GET['exclude'] ?? $_POST['exclude'] ?? 0));
if ($name === '') {
    AgentHttp::emit(['ok' => true, 'overlaps' => []]);
}

$labels = [
    'exact' => t('audio_dicts.keys.overlap_exact'),
    'contains' => t('audio_dicts.keys.overlap_contains'),
    'contained' => t('audio_dicts.keys.overlap_contained'),
];
$hits = RecordsKeysRepository::findOverlaps($name, $excludeId, 12);
$out = [];
foreach ($hits as $h) {
    $rel = (string)($h['relation'] ?? '');
    $out[] = [
        'id' => (int)($h['id'] ?? 0),
        'name' => (string)($h['name'] ?? ''),
        'id_dictonary' => (int)($h['id_dictonary'] ?? 0),
        'dict_name' => (string)($h['dict_name'] ?? ''),
        'relation' => $rel,
        'relation_label' => $labels[$rel] ?? $rel,
        'edit_url' => '/audio/keys/' . (int)($h['id'] ?? 0) . '/edit',
    ];
}

AgentHttp::emit([
    'ok' => true,
    'name' => $name,
    'overlaps' => $out,
    'has_exact' => (bool)array_filter($out, static fn ($r) => ($r['relation'] ?? '') === 'exact'),
]);
