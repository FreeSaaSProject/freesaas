<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$canEdit = Auth::can('sites.edit') && Auth::capability('SUPERADMIN_ACCESS');
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = [];
$message = '';
$error = '';

if (!$isNew) {
    $found = SiteHostsRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('sites.hosts.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('sites.hosts.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canEdit) {
    try {
        $data = SiteHostsRepository::dataFromPost($_POST);
        $save = EventBus::emit('sites.host_edit.save', [
            'data' => $data,
            'post' => $_POST,
            'id' => $id,
            'is_new' => $isNew,
        ]);
        if (is_array($save) && is_array($save['data'] ?? null)) {
            $data = $save['data'];
        }
        if ($isNew) {
            $newId = SiteHostsRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $newId,
                    'host' => SiteHostsRepository::find($newId),
                ]);
                return;
            }
            flash_set('ok', t('core.common.saved'));
            redirect('/sites/hosts/' . $newId . '/edit');
            return;
        }
        SiteHostsRepository::update($id, $data);
        $row = SiteHostsRepository::find($id) ?: array_merge($row, $data);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'host' => $row]);
            return;
        }
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
        $row = array_merge($row, $_POST);
        $row['catalog_root_ids'] = SiteHostsRepository::parseCatalogRootIds(
            $_POST['catalog_root_ids'] ?? []
        );
        $row['catalog_root_id'] = $row['catalog_root_ids'][0] ?? 0;
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $wantsJson) {
    AgentHttp::error('forbidden', 403);
    return;
}

$promoOptions = [0 => '—'];
if (class_exists(PromopagesRepository::class) && Database::tableExists('base_promopages')) {
    $promos = PromopagesRepository::list(['enabled' => '1', 'limit' => 500, 'order' => 'CODE ASC'])['items'];
    foreach ($promos as $p) {
        $promoOptions[(int)$p['id']] = (string)$p['code'] . ' — ' . (string)$p['descr'];
    }
}

$catOptions = [];
if (class_exists(CategoriesRepository::class)) {
    foreach (CategoriesRepository::names() as $cid => $cname) {
        $catOptions[(int)$cid] = '#' . $cid . ' ' . $cname;
    }
}

$sections = EventBus::emit('sites.host_edit.sections', [
    'html' => '',
    'row' => $row,
    'can_edit' => $canEdit,
    'is_new' => $isNew,
]);
$extraHtml = is_array($sections) ? (string)($sections['html'] ?? '') : '';

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'host' => $row,
        'themes' => SiteTheme::listIds(),
        'promo_options' => $promoOptions,
        'catalog_options' => $catOptions,
        'can_edit' => $canEdit,
        'extra_fields' => is_array($sections) ? ($sections['fields'] ?? null) : null,
    ]);
    return;
}

View::render('host_edit', [
    'title' => $isNew
        ? t('sites.hosts.edit.title_new')
        : t('sites.hosts.edit.title', ['id' => $id]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
    'canEdit' => $canEdit,
    'themes' => SiteTheme::listIds(),
    'promoOptions' => $promoOptions,
    'catOptions' => $catOptions,
    'extraHtml' => $extraHtml,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('sites.menu.hosts'), 'href' => '/sites/hosts'],
        [
            'label' => $isNew
                ? t('sites.hosts.edit.title_new')
                : (string)($row['host'] ?? ('#' . $id)),
            'current' => true,
        ],
    ]),
], 'layout', 'sites');
