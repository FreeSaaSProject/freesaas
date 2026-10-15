<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$defaultHost = SiteHostsRepository::findDefault();
$row = [
    'enabled' => 1,
    'url_old' => '',
    'url_new' => '',
    'dt_create' => 0,
    'site_host_id' => (int)($defaultHost['id'] ?? 0),
];
if (!$isNew) {
    $found = RedirectsRepository::find($id);
    if (!$found) {
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('sites.redirects.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';


if (!$isNew && EntityDelete::tryHandle('sites_redirects', $id, '/sites/redirects', [
    'stay_path' => '/sites/redirects/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = RedirectsRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = RedirectsRepository::create($data);
            redirect('/sites/redirects/' . $newId . '/edit?saved=1');
            return;
        }
        RedirectsRepository::update($id, $data);
        $message = t('core.common.saved');
        $row = RedirectsRepository::find($id) ?: $row;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, [
            'url_old' => (string)($_POST['url_old'] ?? ''),
            'url_new' => (string)($_POST['url_new'] ?? ''),
            'enabled' => !empty($_POST['enabled']) ? 1 : 0,
            'site_host_id' => (int)($_POST['site_host_id'] ?? 0),
        ]);
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

View::render('redirect_edit', [
    'title' => $isNew
        ? t('sites.redirects.edit.title_new')
        : t('sites.redirects.edit.title', ['id' => $id]),
    'isNew' => $isNew,
    'row' => $row,
    'message' => $message,
    'error' => $error,
    'hostOptions' => SiteHostsRepository::options(true),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('sites.menu.redirects'), 'href' => '/sites/redirects'],
        [
            'label' => $isNew
                ? t('sites.redirects.edit.title_new')
                : ('#' . $id),
            'current' => true,
        ],
    ]),
], 'layout', 'sites');
