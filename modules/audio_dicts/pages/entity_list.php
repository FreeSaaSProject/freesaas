<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$entity = (string)($GLOBALS['audio_dicts_entity'] ?? '');
$map = [
    'dicts' => ['repo' => RecordsDictsRepository::class, 'path' => '/audio/dicts', 'title' => 'audio_dicts.menu.dicts', 'list_id' => 'audio_dicts', 'view' => 'dicts_list'],
    'keys' => ['repo' => RecordsKeysRepository::class, 'path' => '/audio/keys', 'title' => 'audio_dicts.menu.keys', 'list_id' => 'audio_keys', 'view' => 'keys_list'],
    'tags' => ['repo' => RecordsTagsRepository::class, 'path' => '/audio/tags', 'title' => 'audio_dicts.menu.tags', 'list_id' => 'audio_tags', 'view' => 'tags_list'],
    'trashes' => ['repo' => RecordsTrashesRepository::class, 'path' => '/audio/trashes', 'title' => 'audio_dicts.menu.trashes', 'list_id' => 'audio_trashes', 'view' => 'trashes_list'],
];
if (!isset($map[$entity])) {
    http_response_code(404);
    echo 'Unknown entity';
    return;
}
$cfg = $map[$entity];
/** @var class-string $repo */
$repo = $cfg['repo'];
$path = $cfg['path'];
$listId = $cfg['list_id'];

$q = trim((string)($_GET['q'] ?? ''));
$dict = (int)($_GET['dict'] ?? 0);

$lq = ListQuery::resolve([
    'list_id' => $listId,
    'allowed' => $repo::listSortMap(),
    'default' => 'id',
    'default_dir' => $entity === 'keys' ? 'desc' : 'asc',
    'per_page' => 50,
]);

$filters = array_merge(['q' => $q], $lq['query']);
if ($entity === 'keys' && $dict > 0) {
    $filters['dict'] = (string)$dict;
}
if (!empty($lq['show_all'])) {
    $filters['page'] = 'all';
}

if ($entity === 'keys'
    && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && Auth::can('audio_dicts.edit')
) {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'replace_accept' || $action === 'replace_reject') {
        // Per-row ✅ / ❌ on a stored suggestion; links are created only by this explicit click.
        $id = (int)($_POST['id'] ?? 0);
        $r = $action === 'replace_accept'
            ? RecordsKeysRepository::acceptSuggestionsByIds([$id])
            : RecordsKeysRepository::rejectSuggestionsByIds([$id]);
        if ((int)$r['done'] < 1) {
            flash_set('warn', (string)($r['skipped'][0]['reason'] ?? t('audio_dicts.replace.skip.no_suggest')));
        } else {
            flash_set('ok', t($action === 'replace_accept' ? 'audio_dicts.replace.row_accepted' : 'audio_dicts.replace.row_rejected'));
        }
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        redirect($path . ($qs !== '' ? '?' . $qs : ''));
        return;
    }
    if ($action === 'replace_pick' || $action === 'replace_keep') {
        // Popup choice: link to another canonical key (id or typed name) or keep the key as is.
        $id = (int)($_POST['id'] ?? 0);
        if ($action === 'replace_keep') {
            $r = RecordsKeysRepository::setReplaceStateByIds([$id], 'keep');
            $ok = (int)$r['done'] > 0;
            $msg = $ok ? t('audio_dicts.replace.row_kept') : (string)($r['skipped'][0]['reason'] ?? '');
        } else {
            try {
                RecordsKeysRepository::setReplacement(
                    $id,
                    (int)($_POST['replace_id'] ?? 0),
                    trim((string)($_POST['replace_text'] ?? ''))
                );
                $ok = true;
                $msg = t('audio_dicts.replace.row_accepted');
            } catch (InvalidArgumentException $e) {
                $ok = false;
                $msg = $e->getMessage();
            }
        }
        flash_set($ok ? 'ok' : 'warn', $msg);
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        redirect($path . ($qs !== '' ? '?' . $qs : ''));
        return;
    }
    if ($action === 'toggle_disabled') {
        $id = (int)($_POST['id'] ?? 0);
        // checked switch = enabled → DISABLED=0
        $disabled = empty($_POST['enabled']);
        try {
            RecordsKeysRepository::setDisabled($id, $disabled);
        } catch (InvalidArgumentException $e) {
            // Canonical with variants: the switch cannot ask for a policy — open the key form.
            flash_set('warn', $e->getMessage());
        }
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        redirect($path . ($qs !== '' ? '?' . $qs : ''));
        return;
    }
}

BulkActions::tryHandle($listId, $path, $filters);

$listOpts = [
    'q' => $q,
    'limit' => ListQuery::sqlLimit($lq),
    'offset' => $lq['offset'],
    'order' => $lq['order_sql'],
];
if ($entity === 'keys') {
    $listOpts['dict'] = $dict;
    $listOpts['replace'] = (string)($_GET['replace'] ?? '');
    $listOpts['replace_of'] = (int)($_GET['replace_of'] ?? 0);
    if ($listOpts['replace'] !== '') {
        $filters['replace'] = $listOpts['replace'];
    }
    if ($listOpts['replace_of'] > 0) {
        $filters['replace_of'] = (string)$listOpts['replace_of'];
    }
    // Qualify order columns for joined query
    $orderSql = (string)$lq['order_sql'];
    $orderSql = preg_replace('/\bID\b/', 'k.ID', $orderSql) ?? $orderSql;
    $orderSql = preg_replace('/\bNAME\b/', 'k.NAME', $orderSql) ?? $orderSql;
    $orderSql = preg_replace('/\bDISABLED\b/', 'k.DISABLED', $orderSql) ?? $orderSql;
    $orderSql = preg_replace('/\bID_DICTONARY\b/', 'k.ID_DICTONARY', $orderSql) ?? $orderSql;
    $listOpts['order'] = $orderSql;
}

$result = $repo::list($listOpts);
$bulkFlash = BulkActions::consumeFlash();

$viewData = [
    'title' => t($cfg['title']),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.group'), 'href' => '/audio'],
        ['label' => t($cfg['title']), 'current' => true],
    ]),
    'items' => $result['items'],
    'total' => $result['total'],
    'q' => $q,
    'canEdit' => Auth::can('audio_dicts.edit'),
    'sort' => $lq['sort'],
    'dir' => $lq['dir'],
    'page' => $lq['page'],
    'perPage' => $lq['per_page'],
    'showAll' => $lq['show_all'],
    'filters' => $filters,
    'bulkFlash' => $bulkFlash,
    'path' => $path,
    'listId' => $listId,
];
if ($entity === 'dicts') {
    $viewData['positionOptions'] = class_exists('RecordsScriptsRepository', false)
        ? RecordsScriptsRepository::positionOptions()
        : [];
}
if ($entity === 'keys') {
    $viewData['flash'] = flash_take();
    $viewData['dict'] = $dict;
    $viewData['dictOptions'] = RecordsDictsRepository::options(true);
}

if ($wantsJson) {
    $payload = [
        'ok' => true,
        'entity' => $entity,
        'total' => $result['total'],
        'items' => $result['items'],
        'filters' => ['q' => $q],
        'page' => $lq['page'],
        'per_page' => $lq['per_page'],
        'show_all' => $lq['show_all'],
        'can_edit' => Auth::can('audio_dicts.edit'),
    ];
    if ($entity === 'keys') {
        $payload['filters']['dict'] = $dict > 0 ? $dict : null;
        $payload['dict_options'] = RecordsDictsRepository::options(true);
    }
    AgentHttp::emit($payload);
    return;
}

View::render($cfg['view'], $viewData, 'layout', 'audio_dicts');
