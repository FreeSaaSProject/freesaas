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
    if ($action === 'replace_accept') {
        // Per-row ✅ on a stored suggestion; the link is created only by this explicit click.
        $id = (int)($_POST['id'] ?? 0);
        $r = RecordsKeysRepository::acceptSuggestionsByIds([$id]);
        if ((int)$r['done'] < 1) {
            flash_set('warn', (string)($r['skipped'][0]['reason'] ?? t('audio_dicts.replace.skip.no_suggest')));
        } else {
            flash_set('ok', t('audio_dicts.replace.row_accepted'));
        }
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        redirect($path . ($qs !== '' ? '?' . $qs : ''));
        return;
    }
    if ($action === 'replace_pick' || $action === 'replace_keep' || $action === 'replace_reject') {
        // ❌ in a row / popup choice: link to another canonical key (id or typed name), keep as is, or reject.
        // `also_csv` = ids the user ticked in the popup; only keys that carry the same stored suggestion count.
        $id = (int)($_POST['id'] ?? 0);
        $ids = [$id];
        $primary = RecordsKeysRepository::find($id);
        if ($primary !== null && (string)$primary['replace_state'] === 'suggested' && (string)$primary['replace_suggest'] !== '') {
            $want = array_filter(array_map('intval', explode(',', (string)($_POST['also_csv'] ?? ''))), static fn ($i) => $i > 0);
            $valid = array_column(RecordsKeysRepository::sameSuggestionKeys(
                (int)$primary['id_dictonary'],
                (string)$primary['replace_suggest'],
                $id,
                1000
            ), 'id');
            $ids = array_merge($ids, array_values(array_unique(array_intersect($want, $valid))));
        }
        $done = 0;
        $skipped = [];
        if ($action === 'replace_keep') {
            $r = RecordsKeysRepository::setReplaceStateByIds($ids, 'keep');
            $done = (int)$r['done'];
            $skipped = $r['skipped'];
        } elseif ($action === 'replace_reject') {
            $r = RecordsKeysRepository::rejectSuggestionsByIds($ids);
            $done = (int)$r['done'];
            $skipped = $r['skipped'];
        } else {
            foreach ($ids as $kid) {
                try {
                    RecordsKeysRepository::setReplacement(
                        $kid,
                        (int)($_POST['replace_id'] ?? 0),
                        trim((string)($_POST['replace_text'] ?? ''))
                    );
                    $done++;
                } catch (InvalidArgumentException $e) {
                    $skipped[] = ['id' => $kid, 'reason' => '#' . $kid . ': ' . $e->getMessage()];
                }
            }
        }
        if ($done < 1) {
            flash_set('warn', (string)($skipped[0]['reason'] ?? t('audio_dicts.replace.skip.no_suggest')));
        } elseif (count($ids) > 1) {
            $msg = t('audio_dicts.replace.row_many', ['done' => (string)$done, 'skip' => (string)count($skipped)]);
            if ($skipped !== []) {
                $msg .= ' ' . (string)$skipped[0]['reason'];
            }
            flash_set($skipped === [] ? 'ok' : 'warn', $msg);
        } else {
            flash_set('ok', t($action === 'replace_keep'
                ? 'audio_dicts.replace.row_kept'
                : ($action === 'replace_reject' ? 'audio_dicts.replace.row_rejected' : 'audio_dicts.replace.row_accepted')));
        }
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
