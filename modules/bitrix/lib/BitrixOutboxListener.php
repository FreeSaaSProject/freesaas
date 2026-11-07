<?php
declare(strict_types=1);

/** Enqueue FreeSaaS CRM/client changes for Bitrix push; skip echo when sync_origin=bitrix. */
final class BitrixOutboxListener
{
    /** @param array<string,mixed> $payload */
    public static function onClientsChanged(array $payload): array
    {
        if (!BitrixConfig::pushEnabled() || self::isEcho($payload)) {
            return $payload;
        }
        $id = (int)($payload['id'] ?? 0);
        if ($id < 1) {
            return $payload;
        }
        $row = ClientsRepository::find($id);
        if ($row === null) {
            return $payload;
        }
        BitrixOutboxRepository::enqueue('contact', $id, 'upsert', [
            'fields' => [
                'NAME' => (string)($row['pname'] ?? $row['name'] ?? ''),
                'LAST_NAME' => (string)($row['surname'] ?? ''),
                'SECOND_NAME' => (string)($row['patronymic'] ?? ''),
                'PHONE' => [['VALUE' => (string)$row['tel'], 'VALUE_TYPE' => 'WORK']],
                'EMAIL' => $row['mail'] !== '' ? [['VALUE' => (string)$row['mail'], 'VALUE_TYPE' => 'WORK']] : [],
            ],
        ]);
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    public static function onCompanyChanged(array $payload): array
    {
        if (!BitrixConfig::pushEnabled() || self::isEcho($payload)) {
            return $payload;
        }
        $id = (int)($payload['id'] ?? 0);
        $row = is_array($payload['row'] ?? null) ? $payload['row'] : ($id > 0 ? CrmCompaniesRepository::find($id) : null);
        if ($id < 1 || $row === null) {
            return $payload;
        }
        $op = str_contains((string)($payload['_event'] ?? ''), 'deleted') ? 'delete' : 'upsert';
        BitrixOutboxRepository::enqueue('company', $id, $op, [
            'fields' => [
                'TITLE' => (string)($row['name'] ?? ''),
                'PHONE' => $row['phone'] !== '' ? [['VALUE' => (string)$row['phone'], 'VALUE_TYPE' => 'WORK']] : [],
                'EMAIL' => $row['email'] !== '' ? [['VALUE' => (string)$row['email'], 'VALUE_TYPE' => 'WORK']] : [],
                'WEB' => $row['website'] !== '' ? [['VALUE' => (string)$row['website'], 'VALUE_TYPE' => 'WORK']] : [],
                'ADDRESS' => (string)($row['address'] ?? ''),
            ],
        ]);
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    public static function onDealChanged(array $payload): array
    {
        if (!BitrixConfig::pushEnabled() || self::isEcho($payload)) {
            return $payload;
        }
        $id = (int)($payload['id'] ?? 0);
        $row = is_array($payload['row'] ?? null) ? $payload['row'] : ($id > 0 ? CrmDealsRepository::find($id) : null);
        if ($id < 1 || $row === null) {
            return $payload;
        }
        $kind = (string)($row['pipeline_kind'] ?? 'sale');
        $entity = $kind === 'lead' ? 'lead' : 'deal';
        $stageCode = (string)($row['stage_code'] ?? 'NEW');
        BitrixOutboxRepository::enqueue($entity, $id, 'upsert', [
            'fields' => [
                'TITLE' => (string)($row['title'] ?? ''),
                'STAGE_ID' => $stageCode,
                'OPPORTUNITY' => (float)($row['amount'] ?? 0),
                'CURRENCY_ID' => (string)($row['currency'] ?? 'RUB'),
                'COMMENTS' => (string)($row['notes'] ?? ''),
            ],
        ]);
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private static function isEcho(array $payload): bool
    {
        return (string)($payload['sync_origin'] ?? '') === 'bitrix';
    }
}
