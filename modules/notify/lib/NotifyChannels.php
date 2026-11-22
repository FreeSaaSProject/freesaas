<?php
declare(strict_types=1);

/**
 * Channel delivery: bot (BotSend), email (MailSend), inapp (journal only; chips use pending tasks).
 * Every attempt is journaled in base_notify_deliveries.
 */
final class NotifyChannels
{
    /**
     * Deliver one text to one recipient over its channels (respecting strategy).
     *
     * @param array<string,mixed> $rcpt from NotifyTargets
     * @param array<string,mixed> $o rule_id,event,entity,entity_id,client_id,dedupe,dtsend,buttons,subject,task_id,meta,dry
     * @return list<array<string,mixed>> one row per channel attempt
     */
    public static function deliver(array $rcpt, string $text, array $o): array
    {
        $channels = array_values(array_intersect(NotifyRulesRepository::CHANNELS, (array)($rcpt['channels'] ?? ['bot'])));
        if ($channels === []) {
            $channels = ['bot'];
        }
        $first = (string)($rcpt['strategy'] ?? 'all') === 'first_available';
        $results = [];
        foreach ($channels as $ch) {
            $addr = '';
            $why = self::unavailable($rcpt, $ch, $addr);
            $results[] = ['channel' => $ch, 'status' => $why === '' ? 'queued' : 'skipped', 'error' => $why, 'address' => $addr];
            if ($first && $why === '') {
                break;
            }
        }
        if ($first) {
            // Keep only the chosen channel, or a single skipped row when nothing was available.
            $ok = array_values(array_filter($results, static fn ($r) => $r['status'] === 'queued'));
            $results = $ok !== [] ? $ok : [reset($results)];
        }

        foreach ($results as $i => $r) {
            $results[$i] = self::dispatch($rcpt, $r, $text, $o);
        }
        return $results;
    }

    /** Immediate drain of the bot queue (same behaviour as the legacy orders/vacations notifiers). */
    public static function flush(): void
    {
        if (class_exists('BotDrain', false)) {
            try {
                BotDrain::run(10);
            } catch (Throwable $e) {
                error_log('[notify.flush] ' . $e->getMessage());
            }
        }
        self::syncQueued(100);
    }

    /**
     * Pull transport results (sent/error + PLATFORM_MSG_ID) into the journal and confirm tasks.
     *
     * @return int number of deliveries updated
     */
    public static function syncQueued(int $limit = 100): int
    {
        $n = 0;
        foreach (NotifyDeliveriesRepository::listQueued($limit) as $d) {
            $qid = (int)$d['QUEUE_ID'];
            $row = null;
            if ($d['QUEUE_KIND'] === 'bot' && Database::tableExists('base_telegram_sends')) {
                $cols = 'STATUS, ERROR, CHAT_ID, PLATFORM'
                    . (Database::columnExists('base_telegram_sends', 'PLATFORM_MSG_ID') ? ', PLATFORM_MSG_ID' : '');
                $st = Database::pdo()->prepare('SELECT ' . $cols . ' FROM base_telegram_sends WHERE ID = ?');
                $st->execute([$qid]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            } elseif ($d['QUEUE_KIND'] === 'mail' && Database::tableExists('base_mail_sends')) {
                $st = Database::pdo()->prepare('SELECT STATUS, ERROR FROM base_mail_sends WHERE ID = ?');
                $st->execute([$qid]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if ($row === null) {
                continue;
            }
            $map = [2 => 'sent', 3 => 'error', 100 => 'skipped'];
            $status = $map[(int)$row['STATUS']] ?? '';
            if ($status === '') {
                continue;
            }
            $msgId = (string)($row['PLATFORM_MSG_ID'] ?? '');
            NotifyDeliveriesRepository::mark((int)$d['ID'], $status, (string)($row['ERROR'] ?? ''), $msgId);
            if ($status === 'sent' && $msgId !== '' && (int)$d['TASK_ID'] > 0) {
                $chat = (string)($row['CHAT_ID'] ?? '');
                if ($chat === '' && $d['RCPT_KIND'] === 'chat') {
                    $chat = (string)$d['RCPT_ID'];
                }
                NotifyTasksRepository::setMessage((int)$d['TASK_ID'], (string)($row['PLATFORM'] ?? ''), $chat, $msgId);
            }
            $n++;
        }
        return $n;
    }

    /** Empty string when the channel can be used, else a short reason for the journal. */
    private static function unavailable(array $rcpt, string $channel, string &$address): string
    {
        $kind = (string)$rcpt['rcpt_kind'];
        if ($channel === 'bot') {
            $address = $kind === 'chat' ? (string)$rcpt['chat_id'] : '';
            if (!ModuleManager::isEnabled('bots') || !class_exists('BotSend', false)) {
                return 'bots disabled';
            }
            if ($kind === 'staff' && !BotRecipients::staffReachable((int)$rcpt['user_id'])) {
                return 'staff not linked to a messenger';
            }
            if ($kind === 'client' && !BotRecipients::clientReachable((int)$rcpt['client_id'])) {
                return 'client not linked to a messenger';
            }
            return '';
        }
        if ($channel === 'email') {
            $address = self::email($rcpt);
            if (!ModuleManager::isEnabled('mail') || !class_exists('MailSend', false)) {
                return 'mail disabled';
            }
            return $address === '' ? 'no email address' : '';
        }
        // inapp
        return $kind === 'staff' ? '' : 'in-app is staff only';
    }

    /** @param array<string,mixed> $rcpt */
    private static function email(array $rcpt): string
    {
        $e = trim((string)($rcpt['email'] ?? ''));
        if ($e === '') {
            if ($rcpt['rcpt_kind'] === 'staff' && Database::tableExists('base_users')) {
                $st = Database::pdo()->prepare('SELECT mail FROM base_users WHERE id = ?');
                $st->execute([(int)$rcpt['user_id']]);
                $e = trim((string)$st->fetchColumn());
            } elseif ($rcpt['rcpt_kind'] === 'client' && Database::tableExists('base_clients')) {
                $st = Database::pdo()->prepare('SELECT mail FROM base_clients WHERE id = ?');
                $st->execute([(int)$rcpt['client_id']]);
                $e = trim((string)$st->fetchColumn());
            }
        }
        return filter_var($e, FILTER_VALIDATE_EMAIL) !== false ? $e : '';
    }

    /**
     * @param array<string,mixed> $rcpt
     * @param array<string,mixed> $r planned row {channel,status,error,address}
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    private static function dispatch(array $rcpt, array $r, string $text, array $o): array
    {
        $ch = (string)$r['channel'];
        $dedupe = (string)($o['dedupe'] ?? '') !== ''
            ? substr((string)$o['dedupe'], 0, 120) . ':' . $rcpt['rcpt_kind'] . ':' . $rcpt['rcpt_id'] . ':' . $ch
            : '';
        $r['rcpt_kind'] = (string)$rcpt['rcpt_kind'];
        $r['rcpt_id'] = (string)$rcpt['rcpt_id'];
        $r['text'] = $text;
        $r['delivery_id'] = 0;
        if (!empty($o['dry'])) {
            return $r;
        }
        if ($dedupe !== '' && NotifyDeliveriesRepository::hasLive($dedupe)) {
            $r['status'] = 'duplicate';
            return $r;
        }

        $queueId = 0;
        $queueKind = '';
        if ($r['status'] === 'queued') {
            try {
                if ($ch === 'bot') {
                    $queueId = self::enqueueBot($rcpt, $text, $o);
                    $queueKind = 'bot';
                } elseif ($ch === 'email') {
                    $subject = (string)($o['subject'] ?? '') !== '' ? (string)$o['subject'] : NotifyTemplate::subject($text, t('notify.mail.subject_default'));
                    $queueId = MailSend::enqueue([
                        'to' => $r['address'],
                        'subject' => $subject,
                        'text' => NotifyTemplate::toPlain($text),
                        'html' => nl2br($text, false),
                        'client_id' => (int)($o['client_id'] ?? 0),
                        'dtsend' => (int)($o['dtsend'] ?? 0),
                        'dedupe_key' => $dedupe !== '' ? substr(md5($dedupe), 0, 32) : '',
                        'id_operator' => 0,
                        'meta' => ['source' => 'notify', 'event' => (string)($o['event'] ?? '')],
                    ]);
                    $queueKind = 'mail';
                } else {
                    $r['status'] = 'sent';
                }
            } catch (Throwable $e) {
                $r['status'] = 'error';
                $r['error'] = $e->getMessage();
            }
        }
        $r['delivery_id'] = NotifyDeliveriesRepository::insert([
            'rule_id' => (int)($o['rule_id'] ?? 0),
            'event' => (string)($o['event'] ?? ''),
            'entity' => (string)($o['entity'] ?? ''),
            'entity_id' => (int)($o['entity_id'] ?? 0),
            'client_id' => (int)($o['client_id'] ?? 0),
            'rcpt_kind' => $rcpt['rcpt_kind'],
            'rcpt_id' => $rcpt['rcpt_id'],
            'channel' => $ch,
            'address' => $r['address'],
            'subject' => (string)($o['subject'] ?? ''),
            'text' => $text,
            'status' => $r['status'],
            'queue_id' => $queueId,
            'queue_kind' => $queueKind,
            'error' => $r['error'],
            'dedupe_key' => $dedupe,
            'meta' => $o['meta'] ?? null,
            'task_id' => (int)($o['task_id'] ?? 0),
        ]);
        return $r;
    }

    /** @param array<string,mixed> $rcpt @param array<string,mixed> $o */
    private static function enqueueBot(array $rcpt, string $text, array $o): int
    {
        $opts = [
            'audience' => $rcpt['rcpt_kind'] === 'client' ? 'client' : 'staff',
            'text' => $text,
            'name' => 'notify_' . (string)($o['event'] ?? 'send'),
            'type' => 15,
            'id_event' => (int)($o['entity_id'] ?? 0),
            'id_operator' => 0,
            'dtsend' => (int)($o['dtsend'] ?? 0),
        ];
        if ((string)($o['dedupe'] ?? '') !== '') {
            $opts['dedupe_key'] = substr((string)$o['dedupe'], 0, 120) . ':' . $rcpt['rcpt_kind'] . ':' . $rcpt['rcpt_id'];
        }
        if (is_array($o['buttons'] ?? null) && $o['buttons'] !== []) {
            $opts['buttons'] = $o['buttons'];
        }
        if ($rcpt['rcpt_kind'] === 'chat') {
            $opts['chat_id'] = (string)$rcpt['chat_id'];
            $opts['platform'] = (string)$rcpt['platform'];
        } elseif ($rcpt['rcpt_kind'] === 'client') {
            $opts['user_id'] = (int)$rcpt['client_id'];
            $opts['client_id'] = (int)$rcpt['client_id'];
        } else {
            $opts['user_id'] = (int)$rcpt['user_id'];
        }
        return BotSend::enqueue($opts);
    }
}
