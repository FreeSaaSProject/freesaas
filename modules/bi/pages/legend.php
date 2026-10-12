<?php
declare(strict_types=1);

$legend = BiDatasetCatalog::legend();
$wantsJson = AgentHttp::wantsJson();

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'legend' => $legend,
    ]);
    return;
}

View::render('legend', [
    'title' => t('bi.family.legend'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('bi.menu.group'), 'href' => '/bi'],
        ['label' => t('bi.menu.list'), 'href' => '/bi'],
        ['label' => t('bi.family.legend'), 'current' => true],
    ]),
    'legend' => $legend,
], 'layout', 'bi');
