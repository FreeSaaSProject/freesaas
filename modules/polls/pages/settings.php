<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        EventBus::emit('polls.settings.save', [
            'post' => $_POST,
        ]);
        $message = t('core.common.saved');
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'saved' => true,
            ]);
            return;
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$sections = EventBus::emit('polls.settings.sections', [
    'html' => '',
]);
$extraHtml = is_array($sections) ? (string)($sections['html'] ?? '') : '';

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'has_extra' => $extraHtml !== '',
    ]);
    return;
}

View::render('settings', [
    'title' => t('polls.menu.settings'),
    'message' => $message,
    'error' => $error,
    'extraHtml' => $extraHtml,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('polls.menu.list'), 'href' => '/polls'],
        ['label' => t('polls.menu.settings'), 'current' => true],
    ]),
], 'layout', 'polls');
