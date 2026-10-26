<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id <= 0;
$row = $isNew ? null : VideoPostsRepository::find($id);
if (!$isNew && $row === null) {
    http_response_code(404);
    echo t('core.common.not_found');
    return;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'delete' && $id > 0 && Auth::can('video.delete')) {
        VideoPostsRepository::deleteByIds([$id]);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'deleted' => true]);
            return;
        }
        flash_set('ok', t('video.flash.deleted'));
        redirect('/video/posts');
    }
    $settings = json_decode((string)(($row['SETTINGS_JSON'] ?? '') ?: ''), true) ?: [];
    $settings = VideoSchedule::mergeFromPost($_POST, is_array($settings) ? $settings : []);
    $savedId = VideoPostsRepository::save($id, [
        'name' => $_POST['name'] ?? '',
        'enabled' => !empty($_POST['enabled']),
        'model_id' => (int)($_POST['model_id'] ?? 0),
        'settings' => $settings,
    ]);
    if ($wantsJson) {
        AgentHttp::emit(['ok' => true, 'id' => $savedId]);
        return;
    }
    flash_set('ok', t('core.common.saved'));
    redirect('/video/posts/' . $savedId . '/edit');
}

$models = VideoModelsRepository::listAll();
$settings = json_decode((string)($row['SETTINGS_JSON'] ?? ''), true) ?: [];
$sched = $settings['schedule'] ?? ['mode' => 'always'];

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'post' => $row, 'models' => $models]);
    return;
}

View::render('post_edit', [
    'title' => $isNew ? t('video.posts.new') : t('video.posts.edit'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video.menu.posts'), 'href' => '/video/posts'],
        ['label' => $isNew ? t('video.posts.new') : (string)($row['NAME'] ?? ''), 'current' => true],
    ]),
    'row' => $row ?? ['NAME' => '', 'ENABLED' => 1, 'MODEL_ID' => 0],
    'models' => $models,
    'sched' => $sched,
    'isNew' => $isNew,
    'error' => $error,
], 'layout', 'video');
