<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (class_exists('Csrf', false) && !$wantsJson && !Csrf::verify()) {
        Csrf::deny('csrf');
        return;
    }
    try {
        $post = $_POST;
        if ($wantsJson) {
            $base = MedcardAudioConfig::formValues();
            $post = array_merge($base, $_POST);
            if (!array_key_exists('bot_enabled', $_POST)) {
                $post['bot_enabled'] = $base['bot_enabled'];
            }
        }
        MedcardAudioConfig::saveFromPost($post);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'saved' => true, 'cfg' => MedcardAudioConfig::formValues()]);
            return;
        }
        flash_set('ok', t('medcard_audio.settings.saved'));
        redirect('/medcard-audio/settings');
    } catch (Throwable $e) {
        AgentHttp::rethrowIfCapture($e);
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        flash_set('error', $e->getMessage());
        redirect('/medcard-audio/settings');
    }
}

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'cfg' => MedcardAudioConfig::formValues()]);
    return;
}

View::render('settings', [
    'title' => t('medcard_audio.settings.title'),
    'cfg' => MedcardAudioConfig::formValues(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('docs.menu.group'), 'href' => '/documents'],
        ['label' => t('medcard_audio.menu.settings'), 'current' => true],
    ]),
], 'layout', 'medcard_audio');
