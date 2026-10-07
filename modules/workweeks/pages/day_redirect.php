<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$dateParam = (string)(Router::param('date') ?? '');
$dateParam = trim($dateParam);
if ($dateParam === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    if ($wantsJson) {
        AgentHttp::error('Invalid date; use YYYY-MM-DD.', 400);
        return;
    }
    redirect('/workweeks');
    return;
}

if ($wantsJson) {
    $dayStart = strtotime($dateParam . ' 00:00:00');
    if ($dayStart === false) {
        AgentHttp::error('Invalid date.', 400);
        return;
    }
    AgentHttp::emit([
        'ok' => true,
        'date' => $dateParam,
        'shifts' => WorkweeksRepository::listForAgent(WorkweeksRepository::listForDay((int)$dayStart)),
    ]);
    return;
}

redirect('/workweeks?date_from=' . rawurlencode($dateParam) . '&date_to=' . rawurlencode($dateParam));
