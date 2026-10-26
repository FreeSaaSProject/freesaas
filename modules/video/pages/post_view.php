<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$row = VideoPostsRepository::find($id);
if ($row === null) {
    http_response_code(404);
    echo t('core.common.not_found');
    return;
}

$card = VideoPostsRepository::card($row);
$wantsJson = AgentHttp::wantsJson();
if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'post' => $card]);
    return;
}

View::render('post_view', [
    'title' => (string)$row['NAME'],
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video.menu.posts'), 'href' => '/video/posts'],
        ['label' => (string)$row['NAME'], 'current' => true],
    ]),
    'card' => $card,
], 'layout', 'video');
