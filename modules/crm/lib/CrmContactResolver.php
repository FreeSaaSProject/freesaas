<?php
declare(strict_types=1);

/**
 * Resolve a contact (base_clients) by id, phone, any channel (Telegram, Max, …) or email; create it when allowed.
 * A contact needs at least one way to reach them — a phone is not mandatory.
 */
final class CrmContactResolver
{
    /** Convenience single-value inputs mapped to channel types. */
    private const CHANNEL_FIELDS = ['telegram' => 'telegram', 'max' => 'max', 'whatsapp' => 'whatsapp'];

    public static function normalizePhone(string $tel): string
    {
        $digits = preg_replace('/\D+/', '', $tel) ?? '';
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }
        return $digits;
    }

    /**
     * @param array<string,mixed> $in client_id | tel | mail | telegram | max | whatsapp | channels[] (+ name)
     * @return list<array{type:string,value:string,value_norm:string,label:string,is_primary:int}>
     */
    public static function channelsFrom(array $in): array
    {
        $rows = $in['channels'] ?? [];
        if (is_string($rows)) {
            $rows = json_decode($rows, true) ?: [];
        }
        if (!is_array($rows)) {
            $rows = [];
        }
        foreach (self::CHANNEL_FIELDS as $field => $type) {
            $v = trim((string)($in[$field] ?? ''));
            if ($v !== '') {
                $rows[] = ['type' => $type, 'value' => $v];
            }
        }
        return ClientChannelsRepository::parse($rows);
    }

    public static function hasAnyChannel(array $in): bool
    {
        return (int)($in['client_id'] ?? 0) > 0
            || trim((string)($in['tel'] ?? '')) !== ''
            || trim((string)($in['mail'] ?? '')) !== ''
            || self::channelsFrom($in) !== [];
    }

    /**
     * @param array<string,mixed> $in
     * @return array{client_id:int,created:bool,matched:string}
     */
    public static function resolve(array $in): array
    {
        $clientId = (int)($in['client_id'] ?? 0);
        if ($clientId > 0) {
            if (ClientsRepository::find($clientId) === null) {
                throw new InvalidArgumentException(t('crm.err.contact_not_found'));
            }
            return ['client_id' => $clientId, 'created' => false, 'matched' => 'id'];
        }
        $telRaw = trim((string)($in['tel'] ?? ''));
        $mail = trim((string)($in['mail'] ?? ''));
        $channels = self::channelsFrom($in);
        if ($telRaw === '' && $mail === '' && $channels === []) {
            throw new InvalidArgumentException(t('crm.err.contact_channel_required'));
        }

        $found = 0;
        $matched = '';
        if ($telRaw !== '') {
            $row = ClientsRepository::findByPhoneDigits($telRaw);
            $found = $row !== null ? (int)$row['id'] : 0;
            $matched = $found > 0 ? 'tel' : '';
        }
        if ($found < 1 && $channels !== []) {
            $found = ClientChannelsRepository::findClientId($channels);
            $matched = $found > 0 ? 'channel' : '';
        }
        if ($found < 1 && $mail !== '') {
            $st = Database::pdo()->prepare('SELECT id FROM base_clients WHERE LOWER(mail) = LOWER(?) ORDER BY id LIMIT 1');
            $st->execute([$mail]);
            $found = (int)($st->fetchColumn() ?: 0);
            $matched = $found > 0 ? 'mail' : '';
        }
        if ($found > 0) {
            ClientChannelsRepository::merge($found, $channels);
            return ['client_id' => $found, 'created' => false, 'matched' => $matched];
        }

        if (!Auth::can('clients.edit')) {
            throw new RuntimeException(t('crm.err.contact_create_forbidden'));
        }
        $tel = '';
        if ($telRaw !== '') {
            $tel = self::normalizePhone($telRaw);
            if (strlen($tel) < 10) {
                throw new InvalidArgumentException(t('crm.err.contact_phone_invalid'));
            }
        }
        $data = ClientsRepository::dataFromPost([
            'name' => trim((string)($in['name'] ?? '')),
            'tel' => $tel,
            'mail' => $mail,
            'enabled' => '1',
            'channels' => $channels,
        ]);
        $id = ClientsRepository::create($data);
        ClientChannelsRepository::replaceAll($id, $channels);
        return ['client_id' => $id, 'created' => true, 'matched' => ''];
    }

    /**
     * Adds channels[] (with href) to contact link rows.
     *
     * @param list<array<string,mixed>> $contacts rows with client_id
     * @return list<array<string,mixed>>
     */
    public static function withChannels(array $contacts): array
    {
        $map = ClientChannelsRepository::listForIds(array_map('intval', array_column($contacts, 'client_id')));
        foreach ($contacts as &$c) {
            $c['channels'] = array_map(static fn(array $ch): array => [
                'type' => (string)$ch['type'],
                'value' => (string)$ch['value'],
                'label' => (string)$ch['label'],
                'href' => (string)$ch['href'],
            ], $map[(int)$c['client_id']] ?? []);
        }
        unset($c);
        return $contacts;
    }
}
