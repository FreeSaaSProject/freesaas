<?php
declare(strict_types=1);

/**
 * Staff bot: pick today’s calendar visit → send voice → ASR → MedcardAudioFill → notify with edit link.
 * Pending state: BotChatState module_id=medcard_audio.
 */
final class MedcardAudioBotPlugin
{
    private const MODULE = 'medcard_audio';
    private const TTL = 3600;

    /** @var bool */
    private static $voiceHooked = false;

    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }

        BotPlugins::register(self::MODULE, [
            'priority' => 42,
            'audiences' => ['staff'],
            'label' => 'bots.catalog.staff.medcard_audio',
            'module' => 'medcard_audio',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'staff' || !MedcardAudioConfig::botEnabled()) {
                    return [];
                }
                return [[[
                    'text' => t('bots.catalog.staff.medcard_audio'),
                    'callback_data' => self::MODULE,
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff' || !MedcardAudioConfig::botEnabled()) {
                    return null;
                }
                $data = (string)($ctx['callback_data'] ?? '');
                if ($data === '' || (strpos($data, self::MODULE) !== 0)) {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1) {
                    return true;
                }
                return Auth::runAs($uid, static function () use ($ctx, $data, $uid) {
                    return self::onCallback($ctx, $data, $uid);
                });
            },
            'handle_message' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff' || !MedcardAudioConfig::botEnabled()) {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1) {
                    return null;
                }
                $state = self::state($ctx);
                if ($state === null || !BotChatState::isFresh($state, self::TTL)) {
                    return null;
                }
                if (($state['module_id'] ?? '') !== self::MODULE || ($state['mode'] ?? '') !== 'await_voice') {
                    return null;
                }
                if (!class_exists('VoiceTranscribeBotPlugin', false)) {
                    return null;
                }
                $raw = is_array($ctx['raw'] ?? null) ? $ctx['raw'] : [];
                $extracted = VoiceTranscribeBotPlugin::extractAudioResult($ctx, $raw);
                if ($extracted === null) {
                    return null;
                }
                $eventId = (int)(($state['data']['event_id'] ?? 0));
                if ($eventId < 1) {
                    self::reply($ctx, t('medcard_audio.bot.event_missing'), self::kbCancel());
                    return true;
                }
                if (!$extracted['ok']) {
                    self::reply(
                        $ctx,
                        $extracted['error'] === 'too_large'
                            ? t('medcard_audio.bot.too_large')
                            : t('medcard_audio.bot.download_fail'),
                        self::kbCancel()
                    );
                    return true;
                }
                $res = VoiceTranscribeBotPlugin::enqueueFromPath(
                    (string)($ctx['platform'] ?? 'telegram'),
                    (string)($ctx['chat_id'] ?? ''),
                    $uid,
                    $extracted['path'],
                    $extracted['ext'],
                    [
                        'consumer_module' => self::MODULE,
                        'event_id' => $eventId,
                    ]
                );
                if (!$res['ok']) {
                    self::reply($ctx, t('medcard_audio.bot.enqueue_fail'), self::kbCancel());
                    return true;
                }
                self::setState($ctx, 'await_transcription', [
                    'job_id' => $res['job_id'],
                    'user_id' => $uid,
                    'event_id' => $eventId,
                ]);
                self::reply($ctx, t('medcard_audio.bot.queued', ['id' => $res['job_id']]));
                return true;
            },
        ]);

        if (self::$voiceHooked) {
            return;
        }
        self::$voiceHooked = true;
        EventBus::on('bots.voice.transcription_result', static function ($payload) {
            if (!is_array($payload)) {
                return $payload;
            }
            $job = is_array($payload['job'] ?? null) ? $payload['job'] : [];
            if ((string)($job['consumer_module'] ?? '') !== self::MODULE) {
                return $payload;
            }
            if (!MedcardAudioConfig::botEnabled()) {
                return $payload;
            }
            $payload['handled'] = true;
            $platform = (string)($job['platform'] ?? 'telegram');
            $chatId = (string)($job['chat_id'] ?? '');
            $uid = (int)($job['user_id'] ?? 0);
            $eventId = (int)($job['event_id'] ?? 0);
            $error = trim((string)($payload['error'] ?? ''));
            $text = trim((string)($payload['text'] ?? ''));
            $cfg = class_exists('BotsConfig', false)
                ? BotsConfig::channel('staff', $platform === 'max' ? 'max' : 'telegram')
                : null;
            $token = is_array($cfg) ? (string)($cfg['token'] ?? '') : '';
            $ctx = [
                'audience' => 'staff',
                'platform' => $platform,
                'chat_id' => $chatId,
                'token' => $token,
                'user' => ['id' => $uid],
            ];
            if (class_exists('BotChatState', false) && class_exists('BotLinksRepository', false)) {
                BotChatState::clear(BotLinksRepository::SOURCE_STAFF, $platform, $chatId);
            }
            if ($error !== '') {
                self::reply($ctx, t('medcard_audio.bot.asr_err', ['error' => $error]));
                return $payload;
            }
            if ($text === '') {
                self::reply($ctx, t('medcard_audio.bot.empty_transcript'));
                return $payload;
            }
            if ($eventId < 1 || $uid < 1) {
                self::reply($ctx, t('medcard_audio.bot.event_missing'));
                return $payload;
            }
            self::reply($ctx, t('medcard_audio.bot.filling'));
            self::deferFill($ctx, $uid, $eventId, $text);
            return $payload;
        });
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private static function deferFill(array $ctx, int $uid, int $eventId, string $text): void
    {
        $platform = (string)($ctx['platform'] ?? 'telegram');
        $chatId = (string)($ctx['chat_id'] ?? '');
        $token = (string)($ctx['token'] ?? '');
        register_shutdown_function(static function () use ($platform, $chatId, $token, $uid, $eventId, $text): void {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            $notifyCtx = [
                'platform' => $platform,
                'chat_id' => $chatId,
                'token' => $token,
            ];
            try {
                $ev = class_exists('EventsRepository', false) ? EventsRepository::find($eventId) : null;
                if ($ev === null) {
                    MedcardAudioFill::notify($notifyCtx, [], [
                        'ok' => false,
                        'instance_id' => 0,
                        'filled' => 0,
                        'error' => t('medcard_audio.bot.event_missing'),
                        'url' => '',
                    ]);
                    return;
                }
                if (class_exists('ClientsRepository', false) && (int)($ev['id_user'] ?? 0) > 0
                    && trim((string)($ev['client_name'] ?? '')) === ''
                ) {
                    $c = ClientsRepository::find((int)$ev['id_user']);
                    if (is_array($c)) {
                        $ev['client_name'] = (string)($c['name'] ?? '');
                    }
                }
                $result = Auth::runAs($uid, static function () use ($eventId, $uid, $text) {
                    return MedcardAudioFill::fromTranscript([
                        'event_id' => $eventId,
                        'user_id' => $uid,
                        'transcript' => $text,
                    ]);
                });
                MedcardAudioFill::notify($notifyCtx, $ev, $result);
            } catch (Throwable $e) {
                MedcardAudioFill::notify($notifyCtx, [], [
                    'ok' => false,
                    'instance_id' => 0,
                    'filled' => 0,
                    'error' => $e->getMessage() !== '' ? $e->getMessage() : get_class($e),
                    'url' => '',
                ]);
            }
        });
    }

    /** @param array<string,mixed> $ctx */
    private static function onCallback(array $ctx, string $data, int $uid): bool
    {
        if ($data === self::MODULE || $data === self::MODULE . '_cancel') {
            if ($data === self::MODULE . '_cancel') {
                self::clearState($ctx);
            }
            self::showToday($ctx, $uid);
            return true;
        }
        if (preg_match('/^' . preg_quote(self::MODULE, '/') . '_ev_(\d+)$/', $data, $m)) {
            $eventId = (int)$m[1];
            $ev = self::findOwnEvent($eventId, $uid);
            if ($ev === null) {
                self::reply($ctx, t('medcard_audio.bot.event_missing'), self::kbCancel());
                return true;
            }
            self::setState($ctx, 'await_voice', ['event_id' => $eventId, 'user_id' => $uid]);
            $label = self::eventLabel($ev);
            self::reply($ctx, $label . "\n\n" . t('medcard_audio.bot.ask_voice'), self::kbCancel());
            return true;
        }
        return true;
    }

    /** @param array<string,mixed> $ctx */
    private static function showToday(array $ctx, int $uid): void
    {
        if (!class_exists('EventsRepository', false)) {
            self::reply($ctx, t('medcard_audio.bot.pick_empty'), self::kbCabinet());
            return;
        }
        $day0 = (int)mktime(0, 0, 0);
        $day1 = $day0 + 86400;
        $events = EventsRepository::listForDayRange($day0, $day1, $uid);
        $rows = [];
        foreach ($events as $ev) {
            if (!empty($ev['is_block']) || empty($ev['enabled'])) {
                continue;
            }
            if ((int)($ev['id_user'] ?? 0) < 1 && trim((string)($ev['name'] ?? '')) === '') {
                continue;
            }
            $id = (int)$ev['id'];
            $rows[] = [[
                'text' => self::eventLabel($ev),
                'callback_data' => self::MODULE . '_ev_' . $id,
            ]];
        }
        if ($rows === []) {
            self::reply($ctx, t('medcard_audio.bot.pick_empty'), self::kbCabinet());
            return;
        }
        $rows[] = [['text' => t('medcard_audio.bot.cancel'), 'callback_data' => self::MODULE . '_cancel']];
        $rows[] = [['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet']];
        self::setState($ctx, 'pick_event', ['user_id' => $uid]);
        self::reply($ctx, t('medcard_audio.bot.pick_title'), ['inline_keyboard' => $rows]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function findOwnEvent(int $eventId, int $uid): ?array
    {
        if ($eventId < 1 || !class_exists('EventsRepository', false)) {
            return null;
        }
        $ev = EventsRepository::find($eventId);
        if ($ev === null || !empty($ev['is_block'])) {
            return null;
        }
        if ((int)($ev['id_operator'] ?? 0) !== $uid) {
            return null;
        }
        return $ev;
    }

    /** @param array<string,mixed> $ev */
    private static function eventLabel(array $ev): string
    {
        $time = (int)($ev['dtstart'] ?? 0) > 0 ? date('H:i', (int)$ev['dtstart']) : '—';
        $name = trim((string)($ev['client_name'] ?? $ev['name'] ?? ''));
        if ($name === '') {
            $name = '#' . (int)($ev['id_user'] ?? $ev['id'] ?? 0);
        }
        if (function_exists('mb_strlen') && mb_strlen($name) > 40) {
            $name = mb_substr($name, 0, 39) . '…';
        } elseif (strlen($name) > 40) {
            $name = substr($name, 0, 39) . '…';
        }
        return $time . ' · ' . $name;
    }

    /** @param array<string,mixed> $ctx */
    private static function state(array $ctx): ?array
    {
        if (!class_exists('BotChatState', false) || !class_exists('BotLinksRepository', false)) {
            return null;
        }
        return BotChatState::get(
            BotLinksRepository::SOURCE_STAFF,
            (string)($ctx['platform'] ?? 'telegram'),
            (string)($ctx['chat_id'] ?? '')
        );
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $data
     */
    private static function setState(array $ctx, string $mode, array $data): void
    {
        if (!class_exists('BotChatState', false) || !class_exists('BotLinksRepository', false)) {
            return;
        }
        BotChatState::set(
            BotLinksRepository::SOURCE_STAFF,
            (string)($ctx['platform'] ?? 'telegram'),
            (string)($ctx['chat_id'] ?? ''),
            self::MODULE,
            $mode,
            $data
        );
    }

    /** @param array<string,mixed> $ctx */
    private static function clearState(array $ctx): void
    {
        if (!class_exists('BotChatState', false) || !class_exists('BotLinksRepository', false)) {
            return;
        }
        BotChatState::clear(
            BotLinksRepository::SOURCE_STAFF,
            (string)($ctx['platform'] ?? 'telegram'),
            (string)($ctx['chat_id'] ?? '')
        );
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    private static function kbCancel(): array
    {
        return ['inline_keyboard' => [
            [['text' => t('medcard_audio.bot.cancel'), 'callback_data' => self::MODULE . '_cancel']],
        ]];
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    private static function kbCabinet(): array
    {
        return ['inline_keyboard' => [
            [['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet']],
        ]];
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
        if (class_exists('BotClient', false) && method_exists('BotClient', 'replyUi')) {
            BotClient::replyUi($ctx, $text, $markup);
            return;
        }
        BotClient::sendMessage(
            (string)$ctx['platform'],
            (string)$ctx['token'],
            (string)$ctx['chat_id'],
            $text,
            $markup
        );
    }
}
