<?php
declare(strict_types=1);

/**
 * Public enqueue helper + EventBus listener for bots.send / notify.send.
 */
final class BotSend
{
    /**
     * @param array<string,mixed> $payload
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
            $id = BotSendsRepository::enqueue([
                'audience' => (string)($payload['audience'] ?? $payload['channel'] ?? 'staff'),
                'user_id' => (int)($payload['user_id'] ?? 0),
                'client_id' => (int)($payload['client_id'] ?? 0),
                'text' => (string)($payload['text'] ?? ''),
                'name' => (string)($payload['name'] ?? ''),
                'dtsend' => (int)($payload['dtsend'] ?? 0),
                'deadline' => (int)($payload['deadline'] ?? 0),
                'id_event' => (int)($payload['id_event'] ?? 0),
                'id_data' => (int)($payload['id_data'] ?? 0),
                'type' => (int)($payload['type'] ?? 11),
                'chat_id' => (string)($payload['chat_id'] ?? ''),
                'dedupe_key' => (string)($payload['dedupe_key'] ?? ''),
                'buttons' => is_array($payload['buttons'] ?? null) ? $payload['buttons'] : null,
                'id_operator' => (int)($payload['id_operator'] ?? Auth::userId()),
            ]);
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
     * Convenience for domain modules.
     *
     * @param array<string,mixed> $opts
     */
    public static function enqueue(array $opts): int
    {
        $res = EventBus::emit('bots.send', $opts);
        if (is_array($res) && (int)($res['queued_id'] ?? 0) > 0) {
            return (int)$res['queued_id'];
        }
        return BotSendsRepository::enqueue($opts);
    }
}
