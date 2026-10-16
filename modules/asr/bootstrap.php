<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/AsrWorkerAuth.php';
require_once __DIR__ . '/lib/VoiceTranscribeQueue.php';
require_once __DIR__ . '/lib/VoiceTranscribeBotPlugin.php';

/**
 * Standalone speech recognition: voice queue + shared worker token.
 * Cabin analytics (ingest/stem/collect, base_records ASR) stays in audio_pipeline.
 */

$voiceRoutes = [
    '/asr/voice-transcribe/worker' => 'voice_transcribe_worker.php',
    '/asr/voice-transcribe/file' => 'voice_transcribe_file.php',
    // BC aliases (oper / FreeSaaS workers may still hit /audio/…)
    '/audio/voice-transcribe/worker' => 'voice_transcribe_worker.php',
    '/audio/voice-transcribe/file' => 'voice_transcribe_file.php',
];

foreach ($voiceRoutes as $path => $file) {
    ModuleManager::registerRoute($path, [
        'module' => 'asr',
        'methods' => ['GET', 'POST'],
        'public' => true,
        'handler' => static function () use ($file): void {
            require base_path('modules/asr/pages/' . $file);
        },
    ]);
}

Health::register('asr.worker_token', 'asr', static function (): array {
    if (AsrWorkerAuth::configuredToken() === '') {
        return [
            'status' => 'skipped',
            'error_code' => 'asr_worker_token_unconfigured',
            'message' => 'asr.worker_token (or legacy audio.worker_token) missing / CHANGE_ME — voice workers optional',
            'meta' => ['hint' => 'Open /audio/settings to generate, or set cfg/modules/asr.ini [asr] worker_token'],
        ];
    }
    return ['status' => 'ok', 'message' => 'worker_token configured'];
}, 'ASR worker token');

Health::register('asr.voice_queue', 'asr', static function (): array {
    $dir = base_path('var/voice_transcribe');
    if (!is_dir($dir)) {
        VoiceTranscribeQueue::baseDir();
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return ['status' => 'fail', 'message' => 'var/voice_transcribe not writable'];
    }
    return ['status' => 'ok', 'message' => 'voice queue dir writable'];
}, 'Voice transcribe queue');

$registerVoiceBot = static function (): void {
    VoiceTranscribeBotPlugin::register();
};
if (ModuleManager::isEnabled('bots')) {
    $registerVoiceBot();
}
EventBus::on('bots.ready', static function ($payload) use ($registerVoiceBot) {
    $registerVoiceBot();
    return is_array($payload) ? $payload : [];
});
