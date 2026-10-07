<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $post = $_POST;
        $map = [
            AudioSettingsRepository::KEY_MODE => trim((string)($post['audio_storage_mode'] ?? 'local')),
            AudioSettingsRepository::KEY_INBOX => trim((string)($post['audio_inbox'] ?? 'audio')),
            AudioSettingsRepository::KEY_PARSED => trim((string)($post['audio_parsed'] ?? 'audioParsed')),
            AudioSettingsRepository::KEY_CHUNKS => trim((string)($post['audio_chunks'] ?? 'audiochunks')),
            AudioSettingsRepository::KEY_FTP_HOST => trim((string)($post['audio_ftp_host'] ?? '')),
            AudioSettingsRepository::KEY_FTP_PORT => trim((string)($post['audio_ftp_port'] ?? '21')),
            AudioSettingsRepository::KEY_FTP_USER => trim((string)($post['audio_ftp_user'] ?? '')),
            AudioSettingsRepository::KEY_FTP_BASE => trim((string)($post['audio_ftp_base'] ?? '/')),
            AudioSettingsRepository::KEY_FTP_HTTP => trim((string)($post['audio_ftp_http'] ?? '')),
            AudioSettingsRepository::KEY_STEM_CACHE => trim((string)($post['audio_stem_cache'] ?? 'var/audio_stem_cache')),
        ];
        if ($map[AudioSettingsRepository::KEY_MODE] !== 'ftp') {
            $map[AudioSettingsRepository::KEY_MODE] = 'local';
        }
        foreach ($map as $key => $value) {
            AudioSettingsRepository::set($key, $value);
        }

        EventBus::emit('audio.settings.save', [
            'post' => $post,
            'files' => $_FILES,
        ]);

        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'saved' => true,
                'cfg' => AudioSettingsRepository::storageConfigForAgent(),
            ]);
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

$cfg = AudioSettingsRepository::storageConfig();
$sections = EventBus::emit('audio.settings.sections', [
    'html' => '',
    'cfg' => $cfg,
]);
$extraHtml = is_array($sections) ? (string)($sections['html'] ?? '') : '';

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'cfg' => AudioSettingsRepository::storageConfigForAgent(),
    ]);
    return;
}

View::render('settings', [
    'title' => t('audio.menu.settings'),
    'message' => $message,
    'error' => $error,
    'cfg' => $cfg,
    'extraHtml' => $extraHtml,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.list'), 'href' => '/audio'],
        ['label' => t('audio.menu.settings'), 'current' => true],
    ]),
], 'layout', 'audio');
