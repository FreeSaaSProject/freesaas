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
                $extracted = self::extractAudio($ctx, $raw);
                if ($extracted === null) {
                    return null;
                }
                $res = VoiceTranscribeQueue::enqueue(
                    (string)($ctx['platform'] ?? 'telegram'),
                    (string)($ctx['chat_id'] ?? ''),
                    $uid,
                    $extracted['path'],
                    $extracted['ext'],
                    []
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
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $raw
     * @return array{path:string,ext:string}|null
     */
    public static function extractAudio(array $ctx, array $raw): ?array
    {
        $platform = (string)($ctx['platform'] ?? 'telegram');
        $token = (string)($ctx['token'] ?? '');
        if ($platform === 'telegram') {
            $voice = is_array($raw['voice'] ?? null) ? $raw['voice'] : null;
            $audio = is_array($raw['audio'] ?? null) ? $raw['audio'] : null;
            $document = is_array($raw['document'] ?? null) ? $raw['document'] : null;
            $fileId = '';
            $mime = '';
            if ($voice) {
                $fileId = (string)($voice['file_id'] ?? '');
                $mime = (string)($voice['mime_type'] ?? '');
            } elseif ($audio) {
                $fileId = (string)($audio['file_id'] ?? '');
                $mime = (string)($audio['mime_type'] ?? '');
            } elseif ($document) {
                $fileId = (string)($document['file_id'] ?? '');
                $mime = (string)($document['mime_type'] ?? '');
            }
            if ($fileId === '' || $token === '') {
                return null;
            }
            $prefer = $mime !== '' && strpos($mime, '/') !== false
                ? substr($mime, strpos($mime, '/') + 1)
                : 'ogg';
            $dl = BotClient::downloadTelegramFile($token, $fileId, $prefer);
            if (!$dl['ok']) {
                return null;
            }
            return ['path' => $dl['path'], 'ext' => $dl['ext']];
        }

        // Max
        $body = is_array($raw['body'] ?? null) ? $raw['body'] : [];
        $attachments = is_array($body['attachments'] ?? null) ? $body['attachments'] : [];
        if ($attachments === [] && isset($raw['link']['message']['attachments']) && is_array($raw['link']['message']['attachments'])) {
            $attachments = $raw['link']['message']['attachments'];
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
            $ext = 'ogg';
            $mime = (string)($payload['mime_type'] ?? $payload['content_type'] ?? '');
            if ($mime !== '' && strpos($mime, '/') !== false) {
                $ext = substr($mime, strpos($mime, '/') + 1);
            }
            $dl = BotClient::downloadUrlToTemp($url, $ext);
            if (!$dl['ok']) {
                return null;
            }
            return ['path' => $dl['path'], 'ext' => $dl['ext']];
        }
        return null;
    }

    /**
     * Called after worker submit — notify consumer or send transcript to chat.
     *
     * @param array<string,mixed> $job
     */
    public static function deliverResult(array $job, string $text, string $error): void
    {
        $payload = EventBus::emit('bots.voice.transcription_result', [
            'job' => $job,
            'text' => $text,
            'error' => $error,
            'handled' => false,
        ]);
        if (is_array($payload) && !empty($payload['handled'])) {
            return;
        }

        $platform = (string)($job['platform'] ?? 'telegram');
        $chatId = (string)($job['chat_id'] ?? '');
        $jobId = (string)($job['job_id'] ?? '');
        $cfg = BotsConfig::channel('staff', $platform === 'max' ? 'max' : 'telegram');
        $token = is_array($cfg) ? (string)($cfg['token'] ?? '') : '';
        if ($token === '' || $chatId === '') {
            return;
        }
        if ($error !== '') {
            BotClient::sendMessage($platform, $token, $chatId, t('asr.bot.voice.err', [
                'id' => $jobId,
                'error' => $error,
            ]));
            return;
        }
        $body = t('asr.bot.voice.result', ['id' => $jobId]) . "\n\n" . $text;
        BotClient::sendMessage($platform, $token, $chatId, $body);
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
