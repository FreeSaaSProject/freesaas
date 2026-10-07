<?php
declare(strict_types=1);

$status = isset($_GET['status']) && $_GET['status'] !== '' ? (int)$_GET['status'] : -1;
$audience = trim((string)($_GET['audience'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$result = BotSendsRepository::list([
    'status' => $status,
    'audience' => $audience,
    'q' => $q,
    'limit' => $limit,
    'offset' => $offset,
]);

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'page' => $page,
        'limit' => $limit,
        'pending' => BotSendsRepository::countPending($audience !== '' ? $audience : null),
        'items' => $result['items'],
    ]);
}

View::render('sends', [
    'title' => t('bots.sends.title'),
    'items' => $result['items'],
    'total' => $result['total'],
    'status' => $status,
    'audience' => $audience,
    'q' => $q,
    'page' => $page,
    'limit' => $limit,
    'pending' => BotSendsRepository::countPending(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('bots.menu.settings'), 'href' => '/bots/settings'],
        ['label' => t('bots.menu.sends'), 'current' => true],
    ]),
], 'layout', 'bots');
