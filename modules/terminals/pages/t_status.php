<?php
declare(strict_types=1);

$terminalId = (int)($_GET['terminalid'] ?? $_GET['id'] ?? 0);
$payload = TerminalFloor::diagnose($terminalId > 0 ? $terminalId : null);

if (AgentHttp::wantsJson()) {
    AgentHttp::emit(['ok' => true, 'terminals_t' => $payload]);
}

View::render('t_status', [
    'title' => t('terminals.t.status_title'),
    'payload' => $payload,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('terminals.menu.list'), 'href' => '/terminals'],
        ['label' => t('terminals.t.status_title'), 'current' => true],
    ]),
], 'layout', 'terminals');
