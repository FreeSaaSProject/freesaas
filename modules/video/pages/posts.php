<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$q = trim((string)($_GET['q'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete' && Auth::can('video.delete')) {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        if (isset($_POST['id'])) {
            $ids[] = (int)$_POST['id'];
        }
        VideoPostsRepository::deleteByIds($ids);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'deleted' => true]);
            return;
        }
        flash_set('ok', t('video.flash.deleted'));
        redirect('/video/posts');
    }
}

$rows = VideoPostsRepository::listAll($q !== '' ? $q : null);
$cards = array_map([VideoPostsRepository::class, 'card'], $rows);

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'posts' => $cards]);
    return;
}

View::render('posts', [
    'title' => t('video.posts.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video.menu.posts'), 'current' => true],
    ]),
    'cards' => $cards,
    'q' => $q,
], 'layout', 'video');
