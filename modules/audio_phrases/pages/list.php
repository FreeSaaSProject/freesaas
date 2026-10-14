<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$canEdit = Auth::can('audio_phrases.edit');
$path = '/audio/phrases';
$listId = 'audio_phrases';

$status = trim((string)($_GET['status'] ?? 'new'));
if ($status !== '' && !in_array($status, ['new', 'junk', 'skip', 'key', 'all'], true)) {
    $status = 'new';
}
$dict = max(0, (int)($_GET['dict'] ?? 0));
$admin = max(0, (int)($_GET['admin'] ?? 0));
$minDays = max(0, (int)($_GET['min_days'] ?? 0));
$minAdmins = max(0, (int)($_GET['min_admins'] ?? 0));
$q = trim((string)($_GET['q'] ?? ''));

$lq = ListQuery::resolve([
    'list_id' => $listId,
    'allowed' => [
        'count' => 'p.TOTAL_COUNT',
        'id' => 'p.ID',
        'days' => 'p.DAYS_SEEN',
        'admins' => 'p.ADMINS_SEEN',
    ],
    'default' => 'count',
    'default_dir' => 'desc',
    'per_page' => 50,
]);

$filters = array_filter([
    'status' => $status === 'all' ? '' : $status,
    'dict' => $dict ?: null,
    'admin' => $admin ?: null,
    'min_days' => $minDays ?: null,
    'min_admins' => $minAdmins ?: null,
    'q' => $q !== '' ? $q : null,
], static fn ($v) => $v !== null && $v !== '');
$filters = array_merge($filters, $lq['query']);
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

BulkActions::tryHandle($listId, $path, $filters);

$result = PhrasesRepository::list([
    'status' => $status === 'all' ? '' : $status,
    'dict' => $dict,
    'admin' => $admin,
    'min_days' => $minDays,
    'min_admins' => $minAdmins,
    'q' => $q,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
]);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'items' => $result['items'],
        'total' => $result['total'],
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
        'show_all' => $lq['show_all'],
        'can_edit' => $canEdit,
    ]);
    return;
}

$dictOptions = class_exists('RecordsDictsRepository', false)
    ? ($admin > 0
        ? RecordsDictsRepository::optionsForAdmin($admin, false)
        : RecordsDictsRepository::options(false))
    : [];

$staffOptions = [];
foreach (UsersRepository::listEnabledStaff() as $u) {
    $uid = (int)$u['id'];
    $label = trim((string)$u['name']);
    if ($label === '') {
        $label = (string)$u['login'];
    }
    $staffOptions[$uid] = $label;
}

$pending = PhrasesRepository::pendingQueue([
    'admin' => $admin,
    'limit' => 40,
]);

View::render('phrases_list', [
    'title' => t('audio_phrases.list_title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.group'), 'href' => '/audio'],
        ['label' => t('audio_phrases.menu.list'), 'current' => true],
    ]),
    'path' => $path,
    'listId' => $listId,
    'items' => $result['items'],
    'total' => $result['total'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'status' => $status,
    'dict' => $dict,
    'admin' => $admin,
    'minDays' => $minDays,
    'minAdmins' => $minAdmins,
    'q' => $q,
    'canEdit' => $canEdit,
    'dictOptions' => $dictOptions,
    'staffOptions' => $staffOptions,
    'pending' => $pending,
    'tablesReady' => PhrasesRepository::tablesReady(),
    'bulkFlash' => BulkActions::consumeFlash(),
], 'layout', 'audio_phrases');
