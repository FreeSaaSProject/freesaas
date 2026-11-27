<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Staff bot: today's absence alerts of the person on duty → appeal with frames;
 * deciders (video.appeals.decide) accept / reject with a comment. Catalog id `appeals`.
 * callback_data: va (home), va:o:<alert>, va:a:<alert>, va:l, va:p:<appeal>, va:y:<appeal>, va:n:<appeal>
 */
final class VideoAppealsBotPlugin
{
    private const MODULE = 'appeals';
    private const TTL = 3600;

    public static function register(): void
    {
        if (!class_exists('BotPlugins', false) || !ModuleManager::isEnabled('bots')) {
            return;
        }
        BotPlugins::register('appeals', [
            'priority' => 26,
            'audiences' => ['staff'],
            'label' => 'bots.catalog.staff.appeals',
            'module' => 'video',
            'cabinet_buttons' => static function (array $ctx): array {
                $uid = self::uid($ctx);
                if ($uid < 1) {
                    return [];
                }
                $rows = [];
                $mine = count(self::todayAlerts($uid));
                if ($mine > 0) {
                    $rows[] = [['text' => t('video.bot.btn.mine', ['n' => $mine]), 'callback_data' => 'va']];
                }
                if (self::canDecide($uid)) {
                    $n = VideoAppealsRepository::pendingCount();
                    $rows[] = [['text' => t('video.bot.btn.decide', ['n' => $n]), 'callback_data' => 'va:l']];
                }

                return $rows;
            },
            'handle_callback' => static function (array $ctx) {
                $data = (string)($ctx['callback_data'] ?? '');
                if ($data !== 'va' && strpos($data, 'va:') !== 0) {
                    return null;
                }
                $uid = self::uid($ctx);
                if ($uid < 1) {
                    return true;
                }

                return self::onCallback($ctx, $data, $uid);
            },
            'handle_text' => static function (array $ctx) {
                $uid = self::uid($ctx);
                $text = trim((string)($ctx['text'] ?? ''));
                if ($uid < 1 || $text === '') {
                    return null;
                }
                $state = self::state($ctx);
                if ($state === null || !BotChatState::isFresh($state, self::TTL) || ($state['module_id'] ?? '') !== self::MODULE) {
                    return null;
                }

                return self::onText($ctx, $state, $uid, $text);
            },
        ]);
    }

    /** Notify deciders about a new appeal (frames + accept/reject buttons). */
    public static function notifyDeciders(int $appealId): void
    {
        if (!class_exists('BotSend', false) || !ModuleManager::isEnabled('bots')) {
            return;
        }
        $ap = VideoAppealsRepository::find($appealId);
        if ($ap === null) {
            return;
        }
        $alert = VideoAlertsRepository::find((int)$ap['ALERT_ID']);
        $images = $alert !== null ? VideoAlertsRepository::framesAbs($alert) : [];
        $text = self::appealText($ap);
        $buttons = ['inline_keyboard' => [[
            ['text' => t('video.bot.btn.accept'), 'callback_data' => 'va:y:' . $appealId],
            ['text' => t('video.bot.btn.reject'), 'callback_data' => 'va:n:' . $appealId],
        ]]];
        foreach (VideoAppealsRepository::deciders() as $d) {
            try {
                BotSend::enqueue([
                    'audience' => 'staff',
                    'user_id' => $d['id'],
                    'text' => $text,
                    'name' => 'video_appeal',
                    'images' => $images,
                    'buttons' => $buttons,
                    'dedupe_key' => 'video_appeal:' . $appealId . ':' . $d['id'],
                    'id_operator' => 0,
                ]);
            } catch (Throwable $e) {
                error_log('[video.appeal] notify decider ' . $d['id'] . ': ' . $e->getMessage());
            }
        }
    }

    /** Tell the employee how the appeal was decided. */
    public static function notifyEmployee(int $appealId): void
    {
        if (!class_exists('BotSend', false) || !ModuleManager::isEnabled('bots')) {
            return;
        }
        $ap = VideoAppealsRepository::find($appealId);
        if ($ap === null || (int)$ap['STATUS'] === VideoAppealsRepository::PENDING) {
            return;
        }
        $vars = [
            'time' => date('H:i', (int)$ap['ALERT_DT']),
            'camera' => (string)($ap['CAMERA_NAME'] ?? ''),
            'comment' => (string)($ap['COMMENT'] ?? ''),
            'decider' => (string)($ap['DECIDER_NAME'] ?? ''),
        ];
        $text = (int)$ap['STATUS'] === VideoAppealsRepository::ACCEPTED
            ? t('video.bot.employee.accepted', $vars)
            : t('video.bot.employee.rejected', $vars);
        try {
            BotSend::enqueue([
                'audience' => 'staff',
                'user_id' => (int)$ap['STAFF_ID'],
                'text' => $text,
                'name' => 'video_appeal_decided',
                'dedupe_key' => 'video_appeal_decided:' . $appealId,
                'id_operator' => 0,
            ]);
        } catch (Throwable $e) {
            error_log('[video.appeal] notify employee: ' . $e->getMessage());
        }
    }

    // ---- callbacks ---------------------------------------------------------------

    /** @param array<string,mixed> $ctx */
    private static function onCallback(array $ctx, string $data, int $uid): bool
    {
        if ($data === 'va') {
            self::clearState($ctx);
            self::showMine($ctx, $uid);

            return true;
        }
        if ($data === 'va:l') {
            self::clearState($ctx);
            self::showPending($ctx, $uid);

            return true;
        }
        if (preg_match('/^va:o:(\d+)$/', $data, $m)) {
            self::showAlert($ctx, $uid, (int)$m[1]);

            return true;
        }
        if (preg_match('/^va:a:(\d+)$/', $data, $m)) {
            $alert = VideoAlertsRepository::find((int)$m[1]);
            $err = self::appealable($alert, $uid);
            if ($err !== '') {
                BotClient::replyUi($ctx, t('video.bot.err.' . $err), self::kbHome());

                return true;
            }
            self::setState($ctx, 'await_reason', ['alert_id' => (int)$m[1], 'user_id' => $uid]);
            BotClient::replyUi($ctx, t('video.bot.prompt_reason'), self::kbCancel());

            return true;
        }
        if (preg_match('/^va:p:(\d+)$/', $data, $m)) {
            self::showAppeal($ctx, $uid, (int)$m[1]);

            return true;
        }
        if (preg_match('/^va:y:(\d+)$/', $data, $m)) {
            if (!self::canDecide($uid)) {
                BotClient::replyUi($ctx, t('video.bot.err.denied'), self::kbHome());

                return true;
            }
            self::finish($ctx, (int)$m[1], VideoAppealsRepository::ACCEPTED, $uid, '');

            return true;
        }
        if (preg_match('/^va:n:(\d+)$/', $data, $m)) {
            if (!self::canDecide($uid)) {
                BotClient::replyUi($ctx, t('video.bot.err.denied'), self::kbHome());

                return true;
            }
            self::setState($ctx, 'await_comment', ['appeal_id' => (int)$m[1], 'user_id' => $uid]);
            BotClient::replyUi($ctx, t('video.bot.prompt_comment'), self::kbCancel());

            return true;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $state
     */
    private static function onText(array $ctx, array $state, int $uid, string $text): ?bool
    {
        $mode = (string)($state['mode'] ?? '');
        $data = is_array($state['data'] ?? null) ? $state['data'] : [];
        if ((int)($data['user_id'] ?? 0) !== $uid) {
            return false;
        }
        if ($mode === 'await_reason') {
            $res = VideoAppealsRepository::create((int)($data['alert_id'] ?? 0), $uid, $text);
            self::clearState($ctx);
            if (!$res['ok']) {
                BotClient::replyUi($ctx, t('video.bot.err.' . self::errKey($res['error'])), self::kbHome());

                return true;
            }
            BotClient::replyUi($ctx, t('video.bot.appeal_sent'), self::kbHome());

            return true;
        }
        if ($mode === 'await_comment') {
            if (!self::canDecide($uid)) {
                self::clearState($ctx);
                BotClient::replyUi($ctx, t('video.bot.err.denied'), self::kbHome());

                return true;
            }
            $comment = $text === '-' ? '' : $text;
            self::clearState($ctx);
            self::finish($ctx, (int)($data['appeal_id'] ?? 0), VideoAppealsRepository::REJECTED, $uid, $comment);

            return true;
        }

        return false;
    }

    // ---- screens -----------------------------------------------------------------

    /** @param array<string,mixed> $ctx */
    private static function showMine(array $ctx, int $uid): void
    {
        $alerts = self::todayAlerts($uid);
        if ($alerts === []) {
            BotClient::replyUi($ctx, t('video.bot.mine.empty'), self::kbHome());

            return;
        }
        $rows = [];
        foreach ($alerts as $a) {
            $ap = VideoAppealsRepository::findByAlert((int)$a['ID']);
            $rows[] = [[
                'text' => date('H:i', (int)$a['DT']) . ' ' . (string)($a['CAMERA_NAME'] ?? '') . self::statusMark($a, $ap),
                'callback_data' => 'va:o:' . (int)$a['ID'],
            ]];
        }
        $rows[] = [['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet']];
        BotClient::replyUi($ctx, t('video.bot.mine.title'), ['inline_keyboard' => $rows]);
    }

    /** @param array<string,mixed> $ctx */
    private static function showAlert(array $ctx, int $uid, int $alertId): void
    {
        $alert = VideoAlertsRepository::find($alertId);
        if ($alert === null || (int)($alert['STAFF_ID'] ?? 0) !== $uid) {
            BotClient::replyUi($ctx, t('video.bot.err.not_yours'), self::kbHome());

            return;
        }
        $ap = VideoAppealsRepository::findByAlert($alertId);
        $text = t('video.bot.alert.text', [
            'time' => date('H:i:s', (int)$alert['DT']),
            'camera' => (string)($alert['CAMERA_NAME'] ?? ''),
            'message' => (string)$alert['MESSAGE'],
            'status' => self::statusLine($alert, $ap),
        ]);
        $rows = [];
        if (self::appealable($alert, $uid) === '') {
            $rows[] = [['text' => t('video.bot.btn.appeal'), 'callback_data' => 'va:a:' . $alertId]];
        }
        $rows[] = [['text' => t('video.bot.btn.back'), 'callback_data' => 'va']];
        self::sendFrames($ctx, $text, ['inline_keyboard' => $rows], VideoAlertsRepository::framesAbs($alert));
    }

    /** @param array<string,mixed> $ctx */
    private static function showPending(array $ctx, int $uid): void
    {
        if (!self::canDecide($uid)) {
            BotClient::replyUi($ctx, t('video.bot.err.denied'), self::kbHome());

            return;
        }
        $list = VideoAppealsRepository::list(['status' => VideoAppealsRepository::PENDING], 20);
        if ($list === []) {
            BotClient::replyUi($ctx, t('video.bot.decide.empty'), self::kbHome());

            return;
        }
        $rows = [];
        foreach ($list as $ap) {
            $rows[] = [[
                'text' => (string)($ap['STAFF_NAME'] ?? '') . ' · ' . date('H:i', (int)$ap['ALERT_DT']) . ' ' . (string)($ap['CAMERA_NAME'] ?? ''),
                'callback_data' => 'va:p:' . (int)$ap['ID'],
            ]];
        }
        $rows[] = [['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet']];
        BotClient::replyUi($ctx, t('video.bot.decide.title'), ['inline_keyboard' => $rows]);
    }

    /** @param array<string,mixed> $ctx */
    private static function showAppeal(array $ctx, int $uid, int $appealId): void
    {
        if (!self::canDecide($uid)) {
            BotClient::replyUi($ctx, t('video.bot.err.denied'), self::kbHome());

            return;
        }
        $ap = VideoAppealsRepository::find($appealId);
        if ($ap === null) {
            BotClient::replyUi($ctx, t('video.bot.err.not_found'), self::kbHome());

            return;
        }
        $alert = VideoAlertsRepository::find((int)$ap['ALERT_ID']);
        $rows = [];
        if ((int)$ap['STATUS'] === VideoAppealsRepository::PENDING) {
            $rows[] = [
                ['text' => t('video.bot.btn.accept'), 'callback_data' => 'va:y:' . $appealId],
                ['text' => t('video.bot.btn.reject'), 'callback_data' => 'va:n:' . $appealId],
            ];
        }
        $rows[] = [['text' => t('video.bot.btn.back'), 'callback_data' => 'va:l']];
        self::sendFrames(
            $ctx,
            self::appealText($ap) . self::decisionSuffix($ap),
            ['inline_keyboard' => $rows],
            $alert !== null ? VideoAlertsRepository::framesAbs($alert) : []
        );
    }

    /** @param array<string,mixed> $ctx */
    private static function finish(array $ctx, int $appealId, int $status, int $uid, string $comment): void
    {
        $res = VideoAppealsRepository::decide($appealId, $status, $uid, $comment);
        if (!$res['ok']) {
            $key = $res['error'] === 'already_decided' ? 'already_decided' : 'not_found';
            BotClient::replyUi($ctx, t('video.bot.err.' . $key), self::kbDecideHome());

            return;
        }
        BotClient::replyUi(
            $ctx,
            $status === VideoAppealsRepository::ACCEPTED ? t('video.bot.decided_accepted') : t('video.bot.decided_rejected'),
            self::kbDecideHome()
        );
    }

    // ---- helpers -----------------------------------------------------------------

    /**
     * Frames first, then the text with buttons (BotClient::sendWithImages).
     *
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $markup
     * @param list<string> $images
     */
    private static function sendFrames(array $ctx, string $text, array $markup, array $images): void
    {
        $platform = (string)($ctx['platform'] ?? '');
        $token = (string)($ctx['token'] ?? '');
        $chatId = (string)($ctx['chat_id'] ?? '');
        $images = BotSendsRepository::sanitizeImagePaths($images);
        if ($images === [] || $platform === '' || $token === '' || $chatId === '') {
            BotClient::replyUi($ctx, $text, $markup);

            return;
        }
        BotClient::sendWithImages($platform, $token, $chatId, $text, $markup, $images);
    }

    /** @param array<string,mixed>|null $alert @return '' or an err key */
    private static function appealable(?array $alert, int $uid): string
    {
        if ($alert === null || (int)($alert['STAFF_ID'] ?? 0) !== $uid) {
            return 'not_yours';
        }
        if ((int)($alert['STATE'] ?? 0) !== 0) {
            return 'cancelled';
        }
        if (VideoAppealsRepository::findByAlert((int)$alert['ID']) !== null) {
            return 'already';
        }

        return '';
    }

    private static function errKey(string $code): string
    {
        $map = ['not_your_alert' => 'not_yours', 'alert_cancelled' => 'cancelled', 'already_appealed' => 'already'];

        return $map[$code] ?? 'not_found';
    }

    /** @param array<string,mixed>|null $ap */
    private static function statusMark(array $alert, ?array $ap): string
    {
        if ((int)($alert['STATE'] ?? 0) !== 0) {
            return ' ✅';
        }
        if ($ap === null) {
            return '';
        }

        return (int)$ap['STATUS'] === VideoAppealsRepository::REJECTED ? ' ❌' : ' ⏳';
    }

    /** @param array<string,mixed>|null $ap */
    private static function statusLine(array $alert, ?array $ap): string
    {
        if ((int)($alert['STATE'] ?? 0) !== 0) {
            return t('video.appeal.status.1');
        }
        if ($ap === null) {
            return '';
        }

        return t('video.appeal.status.' . (int)$ap['STATUS']);
    }

    /** @param array<string,mixed> $ap */
    private static function appealText(array $ap): string
    {
        return t('video.bot.appeal.text', [
            'id' => (int)$ap['ID'],
            'staff' => (string)($ap['STAFF_NAME'] ?? ''),
            'time' => date('H:i:s', (int)$ap['ALERT_DT']),
            'camera' => (string)($ap['CAMERA_NAME'] ?? ''),
            'message' => (string)($ap['MESSAGE'] ?? ''),
            'reason' => (string)($ap['REASON'] ?? '') !== '' ? (string)$ap['REASON'] : '—',
        ]);
    }

    /** @param array<string,mixed> $ap */
    private static function decisionSuffix(array $ap): string
    {
        if ((int)$ap['STATUS'] === VideoAppealsRepository::PENDING) {
            return '';
        }

        return "\n" . t('video.appeal.status.' . (int)$ap['STATUS'])
            . ((string)($ap['DECIDER_NAME'] ?? '') !== '' ? ' (' . (string)$ap['DECIDER_NAME'] . ')' : '')
            . ((string)($ap['COMMENT'] ?? '') !== '' ? ': ' . (string)$ap['COMMENT'] : '');
    }

    /** Today's absence alerts of the user (only the person the alert is attributed to sees them). @return list<array<string,mixed>> */
    private static function todayAlerts(int $uid): array
    {
        $from = (int)strtotime(date('Y-m-d') . ' 00:00:00');

        return VideoAlertsRepository::forStaff($uid, $from, $from + 86400, 50);
    }

    private static function canDecide(int $uid): bool
    {
        return (bool)Auth::runAs($uid, static fn (): bool => Auth::can(VideoAppealsRepository::PERM_DECIDE));
    }

    /** @param array<string,mixed> $ctx */
    private static function uid(array $ctx): int
    {
        if (($ctx['audience'] ?? '') !== 'staff') {
            return 0;
        }
        $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;

        return (int)($user['id'] ?? 0);
    }

    /** @param array<string,mixed> $ctx @return array{module_id:string,mode:string,data:array<string,mixed>,at:int}|null */
    private static function state(array $ctx): ?array
    {
        return BotChatState::get(
            BotLinksRepository::SOURCE_STAFF,
            (string)($ctx['platform'] ?? 'telegram'),
            (string)($ctx['chat_id'] ?? '')
        );
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $data */
    private static function setState(array $ctx, string $mode, array $data = []): void
    {
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
        $state = self::state($ctx);
        if ($state !== null && ($state['module_id'] ?? '') === self::MODULE) {
            BotChatState::clear(
                BotLinksRepository::SOURCE_STAFF,
                (string)($ctx['platform'] ?? 'telegram'),
                (string)($ctx['chat_id'] ?? '')
            );
        }
    }

    /** @return array<string,mixed> */
    private static function kbHome(): array
    {
        return ['inline_keyboard' => [
            [['text' => t('video.bot.btn.back'), 'callback_data' => 'va']],
            [['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet']],
        ]];
    }

    /** @return array<string,mixed> */
    private static function kbDecideHome(): array
    {
        return ['inline_keyboard' => [
            [['text' => t('video.bot.btn.back'), 'callback_data' => 'va:l']],
            [['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet']],
        ]];
    }

    /** @return array<string,mixed> */
    private static function kbCancel(): array
    {
        return ['inline_keyboard' => [
            [['text' => t('video.bot.btn.cancel'), 'callback_data' => 'va']],
        ]];
    }
}
