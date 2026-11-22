<?php
declare(strict_types=1);

/**
 * One-shot manual message (UI POST /notify/send and agent twin POST /agent/notify/send).
 * Journaled like rule deliveries (EVENT = notify.send, RULE_ID = 0).
 */
final class NotifySend
{
    /**
     * @param array<string,mixed> $in client_id|user_id|chat_id[+platform], text, subject?, channels (list or CSV)
     * @return array{ok:bool,error:string,results:list<array<string,mixed>>}
     */
    public static function send(array $in): array
    {
        $text = trim((string)($in['text'] ?? ''));
        if ($text === '') {
            return ['ok' => false, 'error' => t('notify.err.text'), 'results' => []];
        }
        $channels = $in['channels'] ?? ['bot'];
        if (!is_array($channels)) {
            $channels = preg_split('/[\s,;|]+/', trim((string)$channels), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $channels = array_values(array_intersect(NotifyRulesRepository::CHANNELS, array_map('strval', $channels)));
        if ($channels === []) {
            $channels = ['bot'];
        }

        $subject = [];
        if ((int)($in['client_id'] ?? 0) > 0) {
            $subject = ['client_id' => (int)$in['client_id']];
        } elseif ((int)($in['user_id'] ?? 0) > 0) {
            $subject = ['user_id' => (int)$in['user_id']];
        } elseif (trim((string)($in['chat_id'] ?? '')) !== '') {
            $subject = ['chat_id' => trim((string)$in['chat_id']), 'platform' => trim((string)($in['platform'] ?? ''))];
        }
        $rcpts = NotifyTargets::expand(
            [['kind' => 'subject', 'subject' => 'to', 'channels' => $channels, 'strategy' => 'all']],
            ['to' => $subject]
        );
        if ($rcpts === []) {
            return ['ok' => false, 'error' => t('notify.err.recipient'), 'results' => []];
        }

        $results = [];
        foreach ($rcpts as $rcpt) {
            $results = array_merge($results, NotifyChannels::deliver($rcpt, htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), [
                'event' => 'notify.send',
                'entity' => 'manual',
                'client_id' => (int)($rcpt['client_id'] ?? 0),
                'subject' => trim((string)($in['subject'] ?? '')),
                'meta' => ['sender' => class_exists('Auth', false) ? (int)Auth::userId() : 0],
            ]));
        }
        NotifyChannels::flush();
        $ok = false;
        foreach ($results as $r) {
            if (in_array($r['status'], ['queued', 'sent'], true)) {
                $ok = true;
            }
        }
        return ['ok' => $ok, 'error' => $ok ? '' : t('notify.err.undeliverable'), 'results' => $results];
    }
}
