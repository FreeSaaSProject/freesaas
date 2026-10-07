<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$quote = BonusProcessor::quote($id);

if (!$wantsJson) {
    // HTML twin: redirect to event edit with quote context not needed — JSON primary.
    if (class_exists('EventsRepository', false) && EventsRepository::find($id)) {
        redirect('/calendar/events/' . $id . '/edit');
        return;
    }
    http_response_code(404);
    View::render('error', [
        'title' => t('core.common.not_found'),
        'message' => t('bonuses.err.event_not_found', ['id' => $id]),
    ]);
    return;
}

if (empty($quote['ok'])) {
    AgentHttp::error((string)($quote['error'] ?? t('bonuses.err.event_not_found', ['id' => $id])), 404);
    return;
}

AgentHttp::emit(array_merge(['ok' => true], $quote));
