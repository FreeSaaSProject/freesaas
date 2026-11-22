<?php
declare(strict_types=1);

$path = '/mail/sends';
$listId = 'mail_sends';

$status = isset($_GET['status']) && $_GET['status'] !== '' ? (int)$_GET['status'] : -1;
$q = trim((string)($_GET['q'] ?? ''));

$lq = ListQuery::resolve([
    'list_id' => $listId,
    'allowed' => MailSendsRepository::listSortMap(),
    'default' => 'id',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_filter([
    'status' => $status >= 0 ? $status : null,
    'q' => $q !== '' ? $q : null,
], static fn ($v) => $v !== null && $v !== '');
$filters = array_merge($filters, $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

$result = MailSendsRepository::list([
    'status' => $status,
    'q' => $q,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $result['total'],
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
        'show_all' => $lq['show_all'],
        'pending' => MailSendsRepository::countPending(),
        'items' => $result['items'],
    ]);
}

View::render('sends', [
    'title' => t('mail.sends.title'),
    'path' => $path,
    'items' => $result['items'],
    'total' => $result['total'],
    'status' => $status,
    'q' => $q,
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'pending' => MailSendsRepository::countPending(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('mail.menu.sends'), 'current' => true],
    ]),
], 'layout', 'mail');
