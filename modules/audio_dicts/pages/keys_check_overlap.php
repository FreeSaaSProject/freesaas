<?php
declare(strict_types=1);

/**
 * Live overlap + stem-conflict probe for the keys edit form.
 */
header('Content-Type: application/json; charset=utf-8');

$name = trim((string)($_GET['name'] ?? $_POST['name'] ?? ''));
$excludeId = max(0, (int)($_GET['exclude'] ?? $_POST['exclude'] ?? 0));
$dictId = max(0, (int)($_GET['dict'] ?? $_POST['dict'] ?? $_GET['id_dictonary'] ?? $_POST['id_dictonary'] ?? 0));
if ($name === '') {
    AgentHttp::emit(['ok' => true, 'overlaps' => [], 'stem_conflicts' => []]);
}

$labels = [
    'exact' => t('audio_dicts.keys.overlap_exact'),
    'contains' => t('audio_dicts.keys.overlap_contains'),
    'contained' => t('audio_dicts.keys.overlap_contained'),
    'stem_prefix_eats' => t('audio_dicts.keys.stem_prefix_eats'),
    'stem_prefix_eaten' => t('audio_dicts.keys.stem_prefix_eaten'),
    'stem_join_eats' => t('audio_dicts.keys.stem_join_eats'),
    'stem_join_eaten' => t('audio_dicts.keys.stem_join_eaten'),
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
        'kind' => 'overlap',
    ];
}

$stem = [];
if ($dictId > 0 && class_exists('KeyStemConflict', false)) {
    foreach (KeyStemConflict::find($name, $dictId, $excludeId, 12) as $h) {
        $rel = (string)($h['relation'] ?? '');
        $stem[] = [
            'id' => (int)($h['id'] ?? 0),
            'name' => (string)($h['name'] ?? ''),
            'id_dictonary' => (int)($h['id_dictonary'] ?? 0),
            'dict_name' => (string)($h['dict_name'] ?? ''),
            'relation' => $rel,
            'relation_label' => $labels[$rel] ?? $rel,
            'shared_lemmas' => is_array($h['shared_lemmas'] ?? null) ? $h['shared_lemmas'] : [],
            'edit_url' => '/audio/keys/' . (int)($h['id'] ?? 0) . '/edit',
            'kind' => 'stem',
        ];
    }
}

$merged = array_merge($out, $stem);
$softOnly = RecordsKeysRepository::isSoftContainsOnly($hits) && $stem === [];
$hasExact = (bool)array_filter($out, static fn ($r) => ($r['relation'] ?? '') === 'exact');
$forceNeeded = ($hits !== [] && !$softOnly && !$hasExact) || $stem !== [];

AgentHttp::emit([
    'ok' => true,
    'name' => $name,
    'dict_id' => $dictId,
    'overlaps' => $merged,
    'stem_conflicts' => $stem,
    'has_exact' => $hasExact,
    'force_needed' => $forceNeeded,
    'soft_only' => $softOnly && $stem === [],
]);
