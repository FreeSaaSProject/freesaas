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
                'poll_speech' => ModuleManager::isEnabled('poll_speech') && class_exists('PollSpeechConfig', false)
                    ? PollSpeechConfig::formValues()
                    : null,
                'poll_invites' => class_exists('PollInvitesConfig', false)
                    ? PollInvitesConfig::formValues()
                    : null,
            ]);
            return;
        }
    } catch (Throwable $e) {
        AgentHttp::rethrowIfCapture($e);
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
        'poll_speech_enabled' => ModuleManager::isEnabled('poll_speech'),
        'poll_speech' => ModuleManager::isEnabled('poll_speech') && class_exists('PollSpeechConfig', false)
            ? PollSpeechConfig::formValues()
            : null,
        'poll_invites' => class_exists('PollInvitesConfig', false)
            ? PollInvitesConfig::formValues()
            : null,
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
