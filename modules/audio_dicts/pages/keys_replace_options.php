<?php
declare(strict_types=1);

/**
 * Review popup data: alternative canonical keys for a key with a stored suggestion.
 * GET id=<key id> [q=<substring>] — same-dictionary canonicals, already-used canonicals first.
 */
$id = max(0, (int)($_GET['id'] ?? 0));
$key = RecordsKeysRepository::find($id);
if ($key === null) {
    AgentHttp::error(t('audio_dicts.err.not_found', ['id' => $id]), 404);
}
$q = trim((string)($_GET['q'] ?? ''));
$items = KeyReplaceSuggest::alternatives($id, $q, 40);
$current = (string)($key['replace_suggest'] ?? '');

AgentHttp::emit([
    'ok' => true,
    'id' => $id,
    'name' => (string)$key['name'],
    'suggest' => $current,
    'items' => array_map(static fn (array $r): array => [
        'id' => $r['id'],
        'name' => $r['name'],
        'variants' => $r['variants'],
        'score' => $r['score'],
        'current' => $current !== '' && $r['name'] === $current,
    ], $items),
]);
