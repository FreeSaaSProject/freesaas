<?php
declare(strict_types=1);

$canEdit = Auth::can('sites.edit') && Auth::capability('SUPERADMIN_ACCESS');
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = [];
$message = '';
$error = '';

if (!$isNew) {
    $found = SiteHostsRepository::find($id);
    if (!$found) {
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
        if ($isNew) {
            $newId = SiteHostsRepository::create($data);
            flash_set('ok', t('core.common.saved'));
            redirect('/sites/hosts/' . $newId . '/edit');
            return;
        }
        SiteHostsRepository::update($id, $data);
        $message = t('core.common.saved');
        $row = SiteHostsRepository::find($id) ?: array_merge($row, $data);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, $_POST);
    }
}

$promoOptions = [0 => '—'];
if (class_exists(PromopagesRepository::class) && Database::tableExists('base_promopages')) {
    $promos = PromopagesRepository::list(['enabled' => '1', 'limit' => 500, 'order' => 'CODE ASC'])['items'];
    foreach ($promos as $p) {
        $promoOptions[(int)$p['id']] = (string)$p['code'] . ' — ' . (string)$p['descr'];
    }
}

$catOptions = [0 => t('sites.hosts.catalog_all')];
if (class_exists(CategoriesRepository::class)) {
    foreach (CategoriesRepository::names() as $cid => $cname) {
        $catOptions[(int)$cid] = '#' . $cid . ' ' . $cname;
    }
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
