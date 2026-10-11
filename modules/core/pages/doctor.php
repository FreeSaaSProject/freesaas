<?php
declare(strict_types=1);

/**
 * Session/MCP twin of GET /agent/doctor (read-only diagnose).
 * Permission: core.modules. Platform lifecycle writes stay on agent_token.
 */
$report = AgentOps::doctor();
if (AgentHttp::wantsJson()) {
    AgentHttp::emit($report, !empty($report['ok']) ? 200 : 503);
    return;
}

View::render('doctor', [
    'title' => t('core.doctor.title'),
    'report' => $report,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.menu.modules'), 'href' => '/modules'],
        ['label' => t('core.doctor.title'), 'current' => true],
    ]),
], 'layout', 'core');
