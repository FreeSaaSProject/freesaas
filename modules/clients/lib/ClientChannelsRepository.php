<?php
declare(strict_types=1);

/**
 * Client communication channels (base_client_channels). base_clients.tel stays the login phone and
 * base_clients.mail the main email; channels hold every way to reach the person (incl. extra phones).
 * Messenger chat ids for bot delivery live in bots' base_bot_links — not here.
 */
final class ClientChannelsRepository
{
    public const TYPES = ['phone', 'telegram', 'max', 'whatsapp', 'email', 'vk', 'instagram', 'other'];

    public static function tableReady(): bool
    {
        return Database::tableExists('base_client_channels');
    }

    /** @return array<string,string> type => label */
    public static function typeLabels(): array
    {
        return [
            'phone' => t('clients.channel.phone'),
            'telegram' => t('clients.channel.telegram'),
            'max' => t('clients.channel.max'),
            'whatsapp' => t('clients.channel.whatsapp'),
            'email' => t('clients.channel.email'),
            'vk' => t('clients.channel.vk'),
            'instagram' => t('clients.channel.instagram'),
            'other' => t('clients.channel.other'),
        ];
    }

    public static function normalize(string $type, string $value): string
    {
        $v = trim($value);
        switch ($type) {
            case 'phone':
            case 'whatsapp':
                $d = preg_replace('/\D+/', '', $v) ?? '';
                if (strlen($d) === 11 && $d[0] === '8') {
                    $d = '7' . substr($d, 1);
                } elseif (strlen($d) === 10) {
                    $d = '7' . $d;
                }
                return $d;
            case 'telegram':
                $v = preg_replace('#^(https?://)?(www\.)?(t\.me|telegram\.me)/#i', '', $v) ?? $v;
                return strtolower(ltrim($v, '@'));
            case 'email':
                return strtolower($v);
            default:
                return mb_strtolower(preg_replace('#^https?://(www\.)?#i', '', $v) ?? $v);
        }
    }

    /**
     * Accepts list of {type, value, label?, is_primary?}, JSON string, or form rows channels[i][type|value|label].
     *
     * @param mixed $raw
     * @return list<array{type:string,value:string,value_norm:string,label:string,is_primary:int}>
     */
    public static function parse($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtolower(trim((string)($row['type'] ?? '')));
            $value = mb_substr(trim((string)($row['value'] ?? '')), 0, 255);
            if (!in_array($type, self::TYPES, true) || $value === '') {
                continue;
            }
            $norm = self::normalize($type, $value);
            if ($norm === '' || isset($seen[$type . '|' . $norm])) {
                continue;
            }
            $seen[$type . '|' . $norm] = true;
            $out[] = [
                'type' => $type,
                'value' => $value,
                'value_norm' => $norm,
                'label' => mb_substr(trim((string)($row['label'] ?? '')), 0, 64),
                'is_primary' => !empty($row['is_primary']) ? 1 : 0,
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function listFor(int $clientId): array
    {
        return self::listForIds([$clientId])[$clientId] ?? [];
    }

    /**
     * @param list<int> $clientIds
     * @return array<int,list<array<string,mixed>>>
     */
    public static function listForIds(array $clientIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $clientIds))));
        if ($ids === [] || !self::tableReady()) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            "SELECT id, client_id, type, value, label, is_primary FROM base_client_channels
             WHERE client_id IN ($ph) ORDER BY client_id, is_primary DESC, sort_order, id"
        );
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $r['href'] = self::href((string)$r['type'], (string)$r['value']);
            $out[(int)$r['client_id']][] = $r;
        }
        return $out;
    }

    /** @param list<array{type:string,value:string,value_norm:string,label:string,is_primary:int}> $rows */
    public static function replaceAll(int $clientId, array $rows): void
    {
        if ($clientId < 1 || !self::tableReady()) {
            return;
        }
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM base_client_channels WHERE client_id = ?')->execute([$clientId]);
        self::insertRows($clientId, $rows, 0);
    }

    /** Add channels that are not stored yet (by type + normalized value). */
    public static function merge(int $clientId, array $rows): void
    {
        if ($clientId < 1 || !self::tableReady() || $rows === []) {
            return;
        }
        $have = [];
        foreach (self::listFor($clientId) as $r) {
            $have[$r['type'] . '|' . self::normalize((string)$r['type'], (string)$r['value'])] = true;
        }
        $new = array_values(array_filter($rows, static fn(array $r): bool => !isset($have[$r['type'] . '|' . $r['value_norm']])));
        self::insertRows($clientId, $new, count($have));
    }

    public static function deleteForClients(array $clientIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $clientIds))));
        if ($ids === [] || !self::tableReady()) {
            return;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        Database::pdo()->prepare("DELETE FROM base_client_channels WHERE client_id IN ($ph)")->execute($ids);
    }

    /** First client that has any of these channels (type + normalized value). */
    public static function findClientId(array $rows): int
    {
        if ($rows === [] || !self::tableReady()) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT client_id FROM base_client_channels WHERE type = ? AND value_norm = ? ORDER BY client_id LIMIT 1'
        );
        foreach ($rows as $r) {
            $st->execute([$r['type'], $r['value_norm']]);
            $id = (int)($st->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }
        return 0;
    }

    public static function href(string $type, string $value): string
    {
        $norm = self::normalize($type, $value);
        switch ($type) {
            case 'phone':
                return $norm !== '' ? 'tel:+' . $norm : '';
            case 'whatsapp':
                return $norm !== '' ? 'https://wa.me/' . $norm : '';
            case 'telegram':
                return preg_match('/^[a-z0-9_]{4,64}$/', $norm) ? 'https://t.me/' . $norm : '';
            case 'email':
                return filter_var($norm, FILTER_VALIDATE_EMAIL) ? 'mailto:' . $norm : '';
            default:
                return preg_match('#^https?://#i', trim($value)) ? trim($value) : '';
        }
    }

    private static function insertRows(int $clientId, array $rows, int $sortFrom): void
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_client_channels (client_id, type, value, value_norm, label, is_primary, sort_order, created_at)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $i = $sortFrom;
        foreach ($rows as $r) {
            $st->execute([$clientId, $r['type'], $r['value'], $r['value_norm'], $r['label'], $r['is_primary'], $i++, time()]);
        }
    }
}
