<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$message = '';
$error = '';
$workerTokenPlain = '';
$workerTokenJustGenerated = false;

$asrAuthLib = base_path('modules/asr/lib/AsrWorkerAuth.php');
if (is_file($asrAuthLib)) {
    require_once $asrAuthLib;
}
$canWorkerToken = class_exists(AsrWorkerAuth::class, false);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'worker_token_generate') {
            if (!$canWorkerToken) {
                throw new RuntimeException(t('audio.settings.worker_token.asr_required'));
            }
            if ($wantsJson) {
                AgentHttp::error('worker_token_generate is UI-only', 403);
            }
            $workerTokenPlain = AsrWorkerAuth::generateAndPersist();
            $workerTokenJustGenerated = true;
            $message = t('audio.settings.worker_token.generated');
        } else {
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
            // Password field is always posted; empty clears it.
            AudioSettingsRepository::setFtpPass((string)($post['audio_ftp_pass'] ?? ''));

            EventBus::emit('audio.settings.save', [
                'post' => $post,
                'files' => $_FILES,
            ]);

            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'saved' => true,
                    'cfg' => AudioSettingsRepository::storageConfigForAgent(),
                    'worker_token_configured' => $canWorkerToken && AsrWorkerAuth::isConfigured(),
                ]);
                return;
            }
            $message = t('core.common.saved');
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $workerTokenJustGenerated = false;
    }
}

// First visit / missing token: create once so workers can be wired without editing ini by hand.
if (
    !$wantsJson
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && $canWorkerToken
    && !AsrWorkerAuth::isConfigured()
) {
    try {
        $workerTokenPlain = AsrWorkerAuth::ensureGenerated();
        if ($workerTokenPlain !== '') {
            $workerTokenJustGenerated = true;
            $message = t('audio.settings.worker_token.generated');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$cfg = AudioSettingsRepository::storageConfig();
$sections = EventBus::emit('audio.settings.sections', [
    'html' => '',
    'cfg' => $cfg,
]);
$extraHtml = is_array($sections) ? (string)($sections['html'] ?? '') : '';

$workerTokenConfigured = $canWorkerToken && AsrWorkerAuth::isConfigured();
$workerTokenMasked = $canWorkerToken ? AsrWorkerAuth::mask() : '';
// Superadmin settings: full token for copy-paste ingest URL (uploader has no header auth).
if ($canWorkerToken && $workerTokenPlain === '' && $workerTokenConfigured) {
    $workerTokenPlain = AsrWorkerAuth::configuredToken();
}
$ingestUrl = '';
if ($workerTokenConfigured && $workerTokenPlain !== '' && class_exists('AppRuntime', false)) {
    $ingestUrl = AppRuntime::adminUrl(
        '/audio/jobs/ingest?token=' . rawurlencode($workerTokenPlain)
    );
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'cfg' => AudioSettingsRepository::storageConfigForAgent(),
        'worker_token_configured' => $workerTokenConfigured,
        'ingest_url_configured' => $ingestUrl !== '',
    ]);
    return;
}

View::render('settings', [
    'title' => t('audio.menu.settings'),
    'message' => $message,
    'error' => $error,
    'cfg' => $cfg,
    'extraHtml' => $extraHtml,
    'canWorkerToken' => $canWorkerToken,
    'workerTokenConfigured' => $workerTokenConfigured,
    'workerTokenJustGenerated' => $workerTokenJustGenerated,
    'workerTokenPlain' => $workerTokenJustGenerated ? $workerTokenPlain : '',
    'workerTokenMasked' => $workerTokenMasked,
    'ingestUrl' => $ingestUrl,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.list'), 'href' => '/audio'],
        ['label' => t('audio.menu.settings'), 'current' => true],
    ]),
], 'layout', 'audio');
