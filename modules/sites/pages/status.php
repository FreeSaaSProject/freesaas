<?php
declare(strict_types=1);

$report = SitesDiagnose::report();

if (AgentHttp::wantsJson()) {
    AgentHttp::emit($report);
    return;
}

View::render('status', [
    'title' => t('sites.status.title'),
    'report' => $report,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('sites.status.title'), 'current' => true],
    ]),
], 'layout', 'sites');
