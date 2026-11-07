<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$pipelines = CrmPipelinesRepository::listPipelines(false);
$withStages = [];
foreach ($pipelines as $p) {
    $p['stages'] = CrmPipelinesRepository::listStages((int)$p['id'], false);
    $withStages[] = $p;
}

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'items' => $withStages]);
    return;
}

View::render('pipelines_list', [
    'title' => t('crm.pipelines.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('crm.pipelines.title'), 'current' => true],
    ]),
    'items' => $withStages,
    'canEdit' => Auth::can('crm.pipelines'),
], 'layout', 'crm');
