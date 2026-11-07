<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Router CSRF already covers browser POSTs; assistant/MCP invokeHandler skips Router.
    if (class_exists('Csrf', false) && !$wantsJson && !Csrf::verify()) {
        Csrf::deny('csrf');
        return;
    }
    try {
        $post = $_POST;
        // Agent patches send only changed fields — keep unchecked checkboxes / omitted keys.
        if ($wantsJson) {
            $base = PollSpeechConfig::formValues();
            $post = array_merge($base, $_POST);
            if (!array_key_exists('oral_btn_enabled', $_POST)) {
                $post['oral_btn_enabled'] = $base['oral_btn_enabled'];
            }
        }
        PollSpeechConfig::saveFromPost($post);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'saved' => true, 'cfg' => PollSpeechConfig::formValues()]);
            return;
        }
        flash_set('ok', t('poll_speech.settings.saved'));
        redirect('/poll-speech/settings');
    } catch (Throwable $e) {
        AgentHttp::rethrowIfCapture($e);
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        flash_set('error', $e->getMessage());
        redirect('/poll-speech/settings');
    }
}

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'cfg' => PollSpeechConfig::formValues()]);
    return;
}

$pollOptions = class_exists('PollsRepository', false) ? PollsRepository::rootNames(true) : [];

View::render('settings', [
    'title' => t('poll_speech.settings.title'),
    'cfg' => PollSpeechConfig::formValues(),
    'pollOptions' => $pollOptions,
    'dictKeys' => PollSpeechConfig::dictKeysForUi(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('poll_speech.menu.list'), 'href' => '/poll-speech'],
        ['label' => t('poll_speech.menu.settings'), 'current' => true],
    ]),
], 'layout', 'poll_speech');
