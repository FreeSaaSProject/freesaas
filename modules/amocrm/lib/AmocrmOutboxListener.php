<?php
declare(strict_types=1);

final class AmocrmOutboxListener
{
    /** @param array<string,mixed> $payload */
    public static function onClientsChanged(array $payload): array
    {
        if (!AmocrmConfig::pushEnabled() || (string)($payload['sync_origin'] ?? '') === 'amocrm') {
            return $payload;
        }
        $id = (int)($payload['id'] ?? 0);
        $row = $id > 0 ? ClientsRepository::find($id) : null;
        if ($row === null) {
            return $payload;
        }
        AmocrmOutboxRepository::enqueue('contact', $id, 'upsert', [
            'fields' => [
                'name' => (string)$row['name'],
                'custom_fields_values' => [
                    [
                        'field_code' => 'PHONE',
                        'values' => [['value' => (string)$row['tel']]],
                    ],
                ],
            ],
        ]);
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    public static function onCompanyChanged(array $payload): array
    {
        if (!AmocrmConfig::pushEnabled() || (string)($payload['sync_origin'] ?? '') === 'amocrm') {
            return $payload;
        }
        $id = (int)($payload['id'] ?? 0);
        $row = is_array($payload['row'] ?? null) ? $payload['row'] : null;
        if ($id < 1 || $row === null) {
            return $payload;
        }
        AmocrmOutboxRepository::enqueue('company', $id, 'upsert', [
            'fields' => ['name' => (string)($row['name'] ?? '')],
        ]);
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    public static function onDealChanged(array $payload): array
    {
        if (!AmocrmConfig::pushEnabled() || (string)($payload['sync_origin'] ?? '') === 'amocrm') {
            return $payload;
        }
        $id = (int)($payload['id'] ?? 0);
        $row = is_array($payload['row'] ?? null) ? $payload['row'] : null;
        if ($id < 1 || $row === null) {
            return $payload;
        }
        // Only push sale-kind deals as Amo leads
        if ((string)($row['pipeline_kind'] ?? 'sale') === 'lead') {
            return $payload;
        }
        AmocrmOutboxRepository::enqueue('lead', $id, 'upsert', [
            'fields' => [
                'name' => (string)($row['title'] ?? ''),
                'price' => (int)round((float)($row['amount'] ?? 0)),
            ],
        ]);
        return $payload;
    }
}
