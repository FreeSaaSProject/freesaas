<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (!TerminalsConfig::analyticsEnabled()) {
    if ($wantsJson) {
        AgentHttp::error(t('terminals.err.disabled'), 404);
        return;
    }
    http_response_code(404);
    View::render('error', ['title' => t('core.common.not_found'), 'message' => t('terminals.err.disabled')]);
    return;
}

$id = (int)(Router::param('id') ?? 0);
$row = FullstatsRepository::find($id);
if (!$row) {
    if ($wantsJson) {
        AgentHttp::error(t('terminals.err.fullstat_not_found', ['id' => $id]), 404);
        return;
    }
    http_response_code(404);
    View::render('error', [
        'title' => t('core.common.not_found'),
        'message' => t('terminals.err.fullstat_not_found', ['id' => $id]),
    ]);
    return;
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        FullstatsRepository::classify($id, $_POST);
        $row = FullstatsRepository::find($id) ?: $row;
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'item' => $row]);
            return;
        }
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'item' => $row]);
    return;
}

View::render('fullstats_edit', [
    'title' => t('terminals.fullstats.edit.title', ['type' => (string)($row['ticket_type'] ?? '')]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('terminals.menu.fullstats'), 'href' => '/terminals/fullstats'],
        ['label' => '#' . $id, 'current' => true],
    ]),
    'row' => $row,
    'message' => $message,
    'error' => $error,
], 'layout', 'terminals');
