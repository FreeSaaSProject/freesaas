<?php
declare(strict_types=1);

/**
 * Public enqueue helper + EventBus listener for mail.send.
 *
 * Payload keys: to (or email), to_name, subject, text, html, from, reply_to,
 * dedupe_key, client_id, id_operator, dtsend, meta.
 * Listener sets: queued_id, handled, error.
 */
final class MailSend
{
    /**
     * @param mixed $payload
     * @return array<string,mixed>
     */
    public static function onSend($payload): array
    {
        if (!is_array($payload)) {
            return ['handled' => false, 'error' => 'invalid payload'];
        }
        if (!empty($payload['handled']) && (int)($payload['queued_id'] ?? 0) > 0) {
            return $payload;
        }
        try {
            $id = MailSendsRepository::enqueue(self::normalize($payload));
            $payload['queued_id'] = $id;
            $payload['handled'] = true;
            $payload['error'] = '';
        } catch (Throwable $e) {
            $payload['handled'] = false;
            $payload['error'] = $e->getMessage();
            $payload['queued_id'] = 0;
        }
        return $payload;
    }

    /**
     * Convenience for domain modules (goes through mail.send so other listeners can intercept).
     *
     * @param array<string,mixed> $opts
     */
    public static function enqueue(array $opts): int
    {
        $res = EventBus::emit('mail.send', $opts);
        if (is_array($res) && (int)($res['queued_id'] ?? 0) > 0) {
            return (int)$res['queued_id'];
        }
        return MailSendsRepository::enqueue(self::normalize($opts));
    }

    /**
     * Synchronous test message: enqueue, send immediately, report the outcome.
     *
     * @return array{ok:bool,id:int,error:string,message_id:string}
     */
    public static function sendTest(string $to, string $subject = '', string $text = ''): array
    {
        $subject = trim($subject) !== '' ? $subject : t('mail.test.default_subject');
        $text = trim($text) !== '' ? $text : t('mail.test.default_text') . ' ' . date('Y-m-d H:i:s');
        try {
            $id = MailSendsRepository::enqueue([
                'to' => $to,
                'subject' => $subject,
                'text' => $text,
                'id_operator' => self::operatorId(),
                'meta' => ['source' => 'test'],
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'id' => 0, 'error' => $e->getMessage(), 'message_id' => ''];
        }
        $r = MailDrain::runOne($id);
        $ok = ($r['status'] ?? '') === 'sent';
        return [
            'ok' => $ok,
            'id' => $id,
            'error' => $ok ? '' : (string)($r['reason'] ?? ''),
            'message_id' => (string)($r['message_id'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private static function normalize(array $p): array
    {
        $to = (string)($p['to'] ?? '');
        if ($to === '') {
            $to = (string)($p['email'] ?? '');
        }
        return [
            'to' => $to,
            'to_name' => (string)($p['to_name'] ?? $p['name'] ?? ''),
            'subject' => (string)($p['subject'] ?? ''),
            'text' => (string)($p['text'] ?? ''),
            'html' => (string)($p['html'] ?? ''),
            'from' => (string)($p['from'] ?? ''),
            'reply_to' => (string)($p['reply_to'] ?? ''),
            'dedupe_key' => (string)($p['dedupe_key'] ?? ''),
            'client_id' => (int)($p['client_id'] ?? 0),
            'dtsend' => (int)($p['dtsend'] ?? 0),
            'id_operator' => (int)($p['id_operator'] ?? self::operatorId()),
            'meta' => is_array($p['meta'] ?? null) ? $p['meta'] : null,
        ];
    }

    private static function operatorId(): int
    {
        return class_exists('Auth', false) ? (int)Auth::userId() : 0;
    }
}
