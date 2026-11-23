<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$q = trim((string)($_GET['q'] ?? ''));
$enabled = (string)($_GET['enabled'] ?? '');

$data = PromoSourcesRepository::list([
    'q' => $q,
    'enabled' => $enabled,
]);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'total' => $data['total'],
        'items' => $data['items'],
        'filters' => ['q' => $q, 'enabled' => $enabled],
    ]);
    return;
}

View::render('sources_list', [
    'title' => t('acquisition.sources.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('acquisition.sources.title'), 'current' => true],
    ]),
    'items' => $data['items'],
    'total' => $data['total'],
    'q' => $q,
    'enabled' => $enabled,
    'canEdit' => Auth::can('acquisition.edit'),
], 'layout', 'acquisition');
