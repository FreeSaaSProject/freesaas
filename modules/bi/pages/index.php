<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$catalog = ReportRegistry::catalog();

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'families' => $catalog,
        'hint' => [
            'legend' => 'GET /bi/legend?format=json',
            'get' => 'GET /bi/families/{id}?format=json',
            'run' => 'POST /bi/work?format=json action=run&family=&year=&month=',
            'validate' => 'POST /bi/families/new?format=json action=validate',
            'save' => 'POST /bi/families/new?format=json action=save',
            'docs' => 'docs/agents/bi.md',
        ],
    ]);
    return;
}

$flash = '';
if (isset($_GET['flash'])) {
    if ($_GET['flash'] === 'family_deleted') {
        $flash = t('bi.family.deleted');
    }
}

View::render('index', [
    'title' => t('bi.menu.list'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('bi.menu.group'), 'href' => '/bi'],
        ['label' => t('bi.menu.list'), 'current' => true],
    ]),
    'families' => $catalog,
    'flash' => $flash,
], 'layout', 'bi');
