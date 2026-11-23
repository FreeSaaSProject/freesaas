<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = $isNew ? [
    'id' => 0,
    'name' => '',
    'contacts' => '',
    'description' => '',
    'result' => '',
    'dt_start' => 0,
    'dt_end' => 0,
    'enabled' => 1,
    'src_enabled' => 1,
] : PromoSourcesRepository::find($id);

if (!$isNew && $row === null) {
    if ($wantsJson) {
        AgentHttp::error(t('acquisition.sources.err.name'), 404);
        return;
    }
    flash_set('error', t('acquisition.err.no_table'));
    redirect('/acquisition/sources');
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'delete' && !$isNew) {
        PromoSourcesRepository::delete($id);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'deleted' => $id]);
            return;
        }
        flash_set('ok', t('acquisition.sources.deleted'));
        redirect('/acquisition/sources');
    }
    $post = [
        'name' => (string)($_POST['name'] ?? ''),
        'contacts' => (string)($_POST['contacts'] ?? ''),
        'description' => (string)($_POST['description'] ?? ''),
        'result' => (string)($_POST['result'] ?? ''),
        'dt_start' => (string)($_POST['dt_start'] ?? ''),
        'dt_end' => (string)($_POST['dt_end'] ?? ''),
        'enabled' => !empty($_POST['enabled']) ? 1 : 0,
        'src_enabled' => !empty($_POST['src_enabled']) ? 1 : 0,
    ];
    try {
        $id = PromoSourcesRepository::save($isNew ? null : $id, $post);
        $isNew = false;
        $row = PromoSourcesRepository::find($id);
        $message = t('acquisition.sources.saved');
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'item' => $row]);
            return;
        }
        redirect('/acquisition/sources/' . $id . '/edit');
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge(is_array($row) ? $row : [], $post);
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    }
}

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'is_new' => $isNew, 'item' => $row]);
    return;
}

View::render('sources_edit', [
    'title' => $isNew ? t('acquisition.sources.new') : (string)($row['name'] ?? ''),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('acquisition.sources.title'), 'href' => '/acquisition/sources'],
        ['label' => $isNew ? t('acquisition.sources.new') : (string)($row['name'] ?? ''), 'current' => true],
    ]),
    'isNew' => $isNew,
    'row' => is_array($row) ? $row : [],
    'message' => $message,
    'error' => $error,
], 'layout', 'acquisition');
