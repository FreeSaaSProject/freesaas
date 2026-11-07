<?php
declare(strict_types=1);

/**
 * Read-only probe of Clientics Restapi list modules.
 * Stores result in sync_state job=api_inventory.
 */
final class ClienticsApiInventory
{
    /** Known + candidate module paths under /m/ */
    private const CANDIDATES = [
        'Services/',
        'Users/',
        'Appointments/',
        'Clients/',
        'Schedule/',
        'WaitingList/',
        'WaitingLists/',
        'MedicalCards/',
        'MedicalCard/',
        'Medcards/',
        'Documents/',
        'Invoices/',
        'Accounts/',
    ];

    /**
     * @return array{
     *   ok:bool,
     *   probed_at:int,
     *   modules:list<array{path:string,ok:bool,count:int,error?:string,http?:int}>,
     *   available:list<string>,
     *   medical_cards:bool,
     *   waiting_list:bool,
     *   error?:string
     * }
     */
    public static function probe(bool $persist = true): array
    {
        $out = [
            'ok' => false,
            'probed_at' => time(),
            'modules' => [],
            'available' => [],
            'medical_cards' => false,
            'waiting_list' => false,
        ];

        if (!ClienticsConfig::apiReady()) {
            $out['error'] = 'api config incomplete';
            if ($persist) {
                ClienticsSyncStateRepository::mark('api_inventory', false, (string)$out['error'], $out);
            }
            return $out;
        }

        foreach (self::CANDIDATES as $path) {
            $query = ['offset' => 0, 'limit' => 1];
            if ($path === 'Appointments/') {
                $query['date'] = date('Y-m-d');
            }
            $res = ClienticsApiClient::listModule($path, $query);
            $row = [
                'path' => $path,
                'ok' => !empty($res['ok']),
                'count' => !empty($res['ok']) ? count($res['items'] ?? []) : 0,
            ];
            if (empty($res['ok'])) {
                $row['error'] = (string)($res['error'] ?? 'failed');
                $row['http'] = (int)($res['http'] ?? 0);
            }
            $out['modules'][] = $row;
            if (!empty($res['ok'])) {
                $out['available'][] = rtrim($path, '/');
            }
        }

        $availLower = array_map('strtolower', $out['available']);
        $out['medical_cards'] = self::anyContains($availLower, ['medicalcard', 'medcard']);
        $out['waiting_list'] = self::anyContains($availLower, ['waitinglist']);
        $out['ok'] = true;

        if ($persist) {
            ClienticsSyncStateRepository::mark('api_inventory', true, '', $out);
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function last(): ?array
    {
        $row = ClienticsSyncStateRepository::get('api_inventory');
        if ($row === null) {
            return null;
        }
        $meta = json_decode((string)($row['meta_json'] ?? ''), true);
        return is_array($meta) ? $meta : null;
    }

    public static function waitingListAvailable(): bool
    {
        $last = self::last();
        if ($last !== null && array_key_exists('waiting_list', $last)) {
            return !empty($last['waiting_list']);
        }
        return false;
    }

    public static function medicalCardsAvailable(): bool
    {
        $last = self::last();
        if ($last !== null && array_key_exists('medical_cards', $last)) {
            return !empty($last['medical_cards']);
        }
        return false;
    }

    /**
     * @param list<string> $haystackLower
     * @param list<string> $needles
     */
    private static function anyContains(array $haystackLower, array $needles): bool
    {
        foreach ($haystackLower as $item) {
            foreach ($needles as $n) {
                if (str_contains($item, $n)) {
                    return true;
                }
            }
        }
        return false;
    }
}
