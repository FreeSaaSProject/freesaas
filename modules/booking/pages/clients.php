<?php
declare(strict_types=1);

/**
 * Phone digit search for booking UI / agents.
 * GET /booking/clients?q=7906
 */
$q = preg_replace('/\D+/', '', (string)($_GET['q'] ?? '')) ?? '';
$limit = max(1, min(20, (int)($_GET['limit'] ?? 8)));

if (strlen($q) < 3) {
    AgentHttp::emit(['ok' => true, 'q' => $q, 'items' => [], 'hint' => 'min_digits']);
}

if (!class_exists(ClientsRepository::class, false) || !Database::tableExists('base_clients')) {
    AgentHttp::emit(['ok' => true, 'q' => $q, 'items' => []]);
}

$result = ClientsRepository::list([
    'q' => $q,
    'enabled' => '1',
    'limit' => $limit,
    'offset' => 0,
    'order' => 'c.id DESC',
]);

$items = [];
foreach ($result['items'] as $row) {
    $items[] = [
        'id' => (int)($row['id'] ?? 0),
        'name' => (string)($row['name'] ?? ''),
        'tel' => (string)($row['tel'] ?? ''),
        'mail' => (string)($row['mail'] ?? ''),
    ];
}

AgentHttp::emit([
    'ok' => true,
    'q' => $q,
    'items' => $items,
    'total' => (int)($result['total'] ?? count($items)),
]);
