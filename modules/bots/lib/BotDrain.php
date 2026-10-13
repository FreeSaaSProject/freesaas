<?php
declare(strict_types=1);

/** Drain pending base_telegram_sends to Telegram and/or Max. */
final class BotDrain
{
    /**
     * @return array{processed:int,sent:int,skipped:int,errors:int,items:list<array<string,mixed>>}
     */
    public static function run(int $limit = 20, ?string $audience = null): array
    {
        $out = [
            'processed' => 0,
            'sent' => 0,
            'skipped' => 0,
            'errors' => 0,
            'items' => [],
        ];
        $rows = BotSendsRepository::claimPending($limit, $audience);
        foreach ($rows as $row) {
            $out['processed']++;
            $result = self::processRow($row);
            $out['items'][] = $result;
            if (($result['status'] ?? '') === 'sent') {
                $out['sent']++;
            } elseif (($result['status'] ?? '') === 'skipped') {
                $out['skipped']++;
            } else {
                $out['errors']++;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function processRow(array $row): array
    {
        $id = (int)($row['ID'] ?? 0);
        $userId = (int)($row['ID_USER'] ?? 0);
        $source = (int)($row['BOT_SOURCE'] ?? BotSendsRepository::SOURCE_STAFF);
        $audience = BotSendsRepository::sourceToAudience($source);
        $name = (string)($row['NAME'] ?? '');
        $now = time();

        $deadline = isset($row['DT_SEND_DEADLINE']) ? (int)$row['DT_SEND_DEADLINE'] : 0;
        if ($deadline > 0 && $now > $deadline) {
            BotSendsRepository::mark($id, BotSendsRepository::STATUS_SKIPPED, t('bots.send.skipped_deadline'));
            return ['id' => $id, 'name' => $name, 'status' => 'skipped', 'reason' => 'deadline'];
        }

        [$text, $markup] = BotSendsRepository::extractButtons((string)($row['TEXT'] ?? ''));
        if (trim($text) === '') {
            $text = t('bots.send.default_text');
        }

        $fixedChat = trim((string)($row['CHAT_ID'] ?? ''));
        $platform = BotSendsRepository::normalizePlatform((string)($row['PLATFORM'] ?? ''));
        // Direct mode: CHAT_ID + PLATFORM set (group / fixed recipient). Skip bot_links.
        if ($fixedChat !== '' && $platform !== '') {
            return self::sendDirect($id, $name, $audience, $platform, $fixedChat, $text, $markup);
        }

        $targets = $audience === 'client'
            ? BotIdentity::clientTargets($userId)
            : BotIdentity::staffTargets($userId);

        if (!empty($targets['bot_no_send'])) {
            BotSendsRepository::mark($id, BotSendsRepository::STATUS_SKIPPED, t('bots.send.skipped_no_send'));
            return ['id' => $id, 'name' => $name, 'status' => 'skipped', 'reason' => 'BOT_NO_SEND'];
        }

        $tgChat = $targets['telegram'];
        $maxChat = $targets['max'];
        if ($tgChat === '' && $maxChat === '') {
            BotSendsRepository::mark($id, BotSendsRepository::STATUS_ERROR, t('bots.send.err_no_chat'), 'no CHAT_ID');
            return ['id' => $id, 'name' => $name, 'status' => 'error', 'reason' => 'no_chat'];
        }
        if ($fixedChat === '') {
            BotSendsRepository::setChatId($id, $tgChat !== '' ? $tgChat : $maxChat);
        }

        $okTg = false;
        $okMax = false;
        $errTg = '';
        $errMax = '';

        if ($tgChat !== '') {
            $ch = BotsConfig::channel($audience, 'telegram');
            if ($ch && $ch['token'] !== '') {
                $res = BotClient::sendMessage('telegram', $ch['token'], $tgChat, $text, $markup);
                $okTg = !empty($res['ok']);
                if (!$okTg) {
                    $errTg = (string)($res['error'] ?? 'tg error');
                }
            }
        }
        if ($maxChat !== '') {
            $ch = BotsConfig::channel($audience, 'max');
            if ($ch && $ch['token'] !== '') {
                $res = BotClient::sendMessage('max', $ch['token'], $maxChat, $text, $markup);
                $okMax = !empty($res['ok']);
                if (!$okMax) {
                    $errMax = (string)($res['error'] ?? 'max error');
                }
            }
        }

        if ($okTg || $okMax) {
            $where = ($okTg && $okMax) ? 'Telegram+Max' : ($okTg ? 'Telegram' : 'Max');
            BotSendsRepository::mark($id, BotSendsRepository::STATUS_SENT, t('bots.send.sent', ['where' => $where]));
            return ['id' => $id, 'name' => $name, 'status' => 'sent', 'via' => $where];
        }
        $err = $errTg !== '' ? $errTg : $errMax;
        if ($err === '') {
            $err = t('bots.send.err_no_token');
        }
        BotSendsRepository::mark($id, BotSendsRepository::STATUS_ERROR, t('bots.send.err_send'), $err);
        return ['id' => $id, 'name' => $name, 'status' => 'error', 'reason' => $err];
    }

    /**
     * @param array<string,mixed>|null $markup
     * @return array<string,mixed>
     */
    private static function sendDirect(
        int $id,
        string $name,
        string $audience,
        string $platform,
        string $chatId,
        string $text,
        ?array $markup
    ): array {
        $wantTg = $platform === 'telegram' || $platform === 'both';
        $wantMax = $platform === 'max' || $platform === 'both';
        $okTg = false;
        $okMax = false;
        $errTg = '';
        $errMax = '';

        if ($wantTg) {
            $ch = BotsConfig::channel($audience, 'telegram');
            if ($ch && $ch['token'] !== '') {
                $res = BotClient::sendMessage('telegram', $ch['token'], $chatId, $text, $markup);
                $okTg = !empty($res['ok']);
                if (!$okTg) {
                    $errTg = (string)($res['error'] ?? 'tg error');
                }
            } else {
                $errTg = t('bots.send.err_no_token');
            }
        }
        if ($wantMax) {
            $ch = BotsConfig::channel($audience, 'max');
            if ($ch && $ch['token'] !== '') {
                $res = BotClient::sendMessage('max', $ch['token'], $chatId, $text, $markup);
                $okMax = !empty($res['ok']);
                if (!$okMax) {
                    $errMax = (string)($res['error'] ?? 'max error');
                }
            } else {
                $errMax = t('bots.send.err_no_token');
            }
        }

        if ($okTg || $okMax) {
            $where = ($okTg && $okMax) ? 'Telegram+Max' : ($okTg ? 'Telegram' : 'Max');
            BotSendsRepository::mark($id, BotSendsRepository::STATUS_SENT, t('bots.send.sent', ['where' => $where]));
            return ['id' => $id, 'name' => $name, 'status' => 'sent', 'via' => $where, 'direct' => true];
        }
        $err = $errTg !== '' ? $errTg : $errMax;
        if ($err === '') {
            $err = t('bots.send.err_no_token');
        }
        BotSendsRepository::mark($id, BotSendsRepository::STATUS_ERROR, t('bots.send.err_send'), $err);
        return ['id' => $id, 'name' => $name, 'status' => 'error', 'reason' => $err, 'direct' => true];
    }
}
