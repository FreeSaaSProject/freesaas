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

$day = trim((string)($_GET['day'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    $day = date('Y-m-d');
}
$series = TermloadsRepository::forDay($day);

if ($wantsJson) {
    AgentHttp::emit(['ok' => true] + $series);
    return;
}

// Flatten keys: View::capture extract($data, EXTR_SKIP) skips a nested "data" key.
View::render('termloads', [
    'title' => t('terminals.termloads.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('terminals.menu.termloads'), 'current' => true],
    ]),
    'day' => (string)$series['day'],
    'items' => $series['items'],
    'max' => (int)$series['max'],
    'total_samples' => (int)$series['total_samples'],
], 'layout', 'terminals');
