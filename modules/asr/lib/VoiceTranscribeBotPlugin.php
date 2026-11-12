<?php
declare(strict_types=1);

/** Staff bot: accept voice/audio → queue → Mac/Whisper worker → reply or EventBus consumer. */
final class VoiceTranscribeBotPlugin
{
    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }

        BotPlugins::register('voice_transcribe', [
            'priority' => 80,
            'audiences' => ['staff'],
            'label' => 'bots.catalog.staff.voice_transcribe',
            'module' => 'asr',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return [];
                }
                return [[[
                    'text' => t('bots.catalog.staff.voice_transcribe'),
                    'callback_data' => 'voice_transcribe',
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return null;
                }
                if ((string)($ctx['callback_data'] ?? '') !== 'voice_transcribe') {
                    return null;
                }
                self::reply($ctx, t('asr.bot.voice.help'), [
                    'inline_keyboard' => [[[
                        'text' => t('bots.cabinet.refresh'),
                        'callback_data' => 'cabinet',
                    ]]],
                ]);
                return true;
            },
            'handle_message' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1) {
                    return null;
                }
                $raw = is_array($ctx['raw'] ?? null) ? $ctx['raw'] : [];
                $extracted = self::extractAudioResult($ctx, $raw);
                if ($extracted === null) {
                    return null;
                }
                if (!$extracted['ok']) {
                    self::reply($ctx, $extracted['error'] === 'too_large'
                        ? t('asr.bot.voice.too_large')
                        : t('asr.bot.voice.download_fail'));
                    return true;
                }
                $res = VoiceTranscribeQueue::enqueue(
                    (string)($ctx['platform'] ?? 'telegram'),
                    (string)($ctx['chat_id'] ?? ''),
                    $uid,
                    $extracted['path'],
                    $extracted['ext'],
                    self::metaExtra($extracted)
                );
                if (!$res['ok']) {
                    self::reply($ctx, t('asr.bot.voice.enqueue_fail'));
                    return true;
                }
                self::reply($ctx, t('asr.bot.voice.accepted', ['id' => $res['job_id']]));
                return true;
            },
        ]);
    }

    /**
     * Public enqueue for other plugins (e.g. fit_food_entry).
     *
     * @param array<string,mixed> $extra
     * @return array{ok:bool,job_id:string,error:string}
     */
    public static function enqueueFromPath(
        string $platform,
        string $chatId,
        int $userId,
        string $tmpPath,
        string $ext,
        array $extra = []
    ): array {
        return VoiceTranscribeQueue::enqueue($platform, $chatId, $userId, $tmpPath, $ext, $extra);
    }

    /**
     * BC wrapper for consumer plugins: null when no audio or download failed.
     *
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $raw
     * @return array{path:string,ext:string,message_date:int,duration:int}|null
     */
    public static function extractAudio(array $ctx, array $raw): ?array
    {
        $res = self::extractAudioResult($ctx, $raw);
        if ($res === null || !$res['ok']) {
            return null;
        }
        return [
            'path' => $res['path'],
            'ext' => $res['ext'],
            'message_date' => $res['message_date'],
            'duration' => $res['duration'],
        ];
    }

    /**
     * null = message has no audio attachment; ok=false = audio present but not downloadable
     * (error: too_large | download_failed).
     *
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $raw
     * @return array{ok:bool,path:string,ext:string,error:string,message_date:int,duration:int}|null
     */
    public static function extractAudioResult(array $ctx, array $raw): ?array
    {
        $platform = (string)($ctx['platform'] ?? 'telegram');
        $token = (string)($ctx['token'] ?? '');
        if ($platform === 'telegram') {
            $voice = is_array($raw['voice'] ?? null) ? $raw['voice'] : null;
            $audio = is_array($raw['audio'] ?? null) ? $raw['audio'] : null;
            $document = is_array($raw['document'] ?? null) ? $raw['document'] : null;
            $att = $voice ?? $audio ?? $document;
            if ($att === null) {
                return null;
            }
            $fileId = (string)($att['file_id'] ?? '');
            $mime = (string)($att['mime_type'] ?? '');
            if ($document !== null && $voice === null && $audio === null
                && preg_match('#^(image/|text/|application/pdf)#', $mime) === 1) {
                return null;
            }
            if ($fileId === '' || $token === '') {
                return null;
            }
            $meta = [
                'message_date' => (int)($raw['date'] ?? 0),
                'duration' => (int)($att['duration'] ?? 0),
            ];
            $prefer = $mime !== '' && strpos($mime, '/') !== false
                ? substr($mime, strpos($mime, '/') + 1)
                : 'ogg';
            $dl = BotClient::downloadTelegramFile($token, $fileId, $prefer);
            if (!$dl['ok']) {
                return self::failed(self::failReason((string)($dl['error'] ?? '')), $meta);
            }
            return ['ok' => true, 'path' => $dl['path'], 'ext' => $dl['ext'], 'error' => ''] + $meta;
        }

        // Max
        $body = is_array($raw['body'] ?? null) ? $raw['body'] : [];
        $attachments = is_array($body['attachments'] ?? null) ? $body['attachments'] : [];
        if ($attachments === [] && isset($raw['link']['message']['attachments']) && is_array($raw['link']['message']['attachments'])) {
            $attachments = $raw['link']['message']['attachments'];
        }
        $ts = (int)($raw['timestamp'] ?? $body['timestamp'] ?? 0);
        if ($ts > 100000000000) {
            $ts = intdiv($ts, 1000);
        }
        foreach ($attachments as $att) {
            if (!is_array($att)) {
                continue;
            }
            $type = strtolower((string)($att['type'] ?? ''));
            $payload = is_array($att['payload'] ?? null) ? $att['payload'] : [];
            $maybeAudio = in_array($type, ['audio', 'voice'], true) || strpos($type, 'audio') !== false || $type === 'file';
            if (!$maybeAudio) {
                continue;
            }
            $url = '';
            foreach (['url', 'download_url', 'file_url', 'link', 'href'] as $k) {
                if (!empty($payload[$k]) && is_string($payload[$k])) {
                    $url = trim($payload[$k]);
                    break;
                }
            }
            if ($url === '') {
                continue;
            }
            $meta = [
                'message_date' => $ts,
                'duration' => (int)($payload['duration'] ?? $att['duration'] ?? 0),
            ];
            $ext = 'ogg';
            $mime = (string)($payload['mime_type'] ?? $payload['content_type'] ?? '');
            if ($mime !== '' && strpos($mime, '/') !== false) {
                $ext = substr($mime, strpos($mime, '/') + 1);
            }
            $dl = BotClient::downloadUrlToTemp($url, $ext);
            if (!$dl['ok']) {
                return self::failed(self::failReason((string)($dl['error'] ?? '')), $meta);
            }
            return ['ok' => true, 'path' => $dl['path'], 'ext' => $dl['ext'], 'error' => ''] + $meta;
        }
        return null;
    }

    /**
     * Job extra from extractAudioResult (message time + duration for later import into base_records).
     *
     * @param array<string,mixed> $extracted
     * @return array<string,int>
     */
    public static function metaExtra(array $extracted): array
    {
        $out = [];
        foreach (['message_date', 'duration'] as $k) {
            if ((int)($extracted[$k] ?? 0) > 0) {
                $out[$k] = (int)$extracted[$k];
            }
        }
        return $out;
    }

    private static function failReason(string $error): string
    {
        $e = strtolower($error);
        foreach (['too big', 'too large', 'size', 'exceed', 'limit'] as $needle) {
            if (strpos($e, $needle) !== false) {
                return 'too_large';
            }
        }
        return 'download_failed';
    }

    /**
     * @param array{message_date:int,duration:int} $meta
     * @return array{ok:bool,path:string,ext:string,error:string,message_date:int,duration:int}
     */
    private static function failed(string $reason, array $meta): array
    {
        return ['ok' => false, 'path' => '', 'ext' => '', 'error' => $reason] + $meta;
    }

    /**
     * Called after the job is done — consumers via EventBus, else transcript reply in the chat.
     * Consumers may set handled (no default reply) and audio_taken (they moved audio_path away).
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed> final event payload
     */
    public static function deliverResult(
        array $job,
        string $text,
        string $error,
        string $textRaw = '',
        ?int $playtime = null
    ): array {
        $audioPath = VoiceTranscribeQueue::audioPath($job);
        $payload = EventBus::emit('bots.voice.transcription_result', [
            'job' => $job,
            'text' => $text,
            'text_raw' => $textRaw !== '' ? $textRaw : $text,
            'playtime' => $playtime,
            'audio_path' => ($audioPath !== '' && is_file($audioPath)) ? $audioPath : '',
            'error' => $error,
            'handled' => false,
            'audio_taken' => false,
        ]);
        if (!is_array($payload)) {
            $payload = [];
        }
        if (!empty($payload['handled']) || !class_exists('BotsConfig', false) || !class_exists('BotClient', false)) {
            return $payload;
        }

        $platform = (string)($job['platform'] ?? 'telegram');
        $chatId = (string)($job['chat_id'] ?? '');
        $jobId = (string)($job['job_id'] ?? '');
        $cfg = BotsConfig::channel('staff', $platform === 'max' ? 'max' : 'telegram');
        $token = is_array($cfg) ? (string)($cfg['token'] ?? '') : '';
        if ($token === '' || $chatId === '') {
            return $payload;
        }
        if ($error !== '') {
            BotClient::sendMessage($platform, $token, $chatId, t('asr.bot.voice.err', [
                'id' => $jobId,
                'error' => $error,
            ]));
            return $payload;
        }
        if ($text === '') {
            BotClient::sendMessage($platform, $token, $chatId, t('asr.bot.voice.empty', ['id' => $jobId]));
            return $payload;
        }
        $body = t('asr.bot.voice.result', ['id' => $jobId]) . "\n\n" . $text;
        BotClient::sendMessage($platform, $token, $chatId, $body);
        return $payload;
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed>|null $markup
     */
    private static function reply(array $ctx, string $text, ?array $markup = null): void
    {
        if ((string)($ctx['token'] ?? '') === '' || (string)($ctx['chat_id'] ?? '') === '') {
            return;
        }
        BotClient::replyUi($ctx, $text, $markup);
    }
}
