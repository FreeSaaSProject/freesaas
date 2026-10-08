<?php
declare(strict_types=1);

/**
 * Minimal pull-side handling of Clientics eventHandler payloads.
 * Upserts a client when phone (+ optional id) is present; appointments deferred.
 */
final class ClienticsWebhookIngest
{
    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,action?:string,local_id?:int,ext_id?:string,error?:string}
     */
    public static function ingest(array $payload): array
    {
        if (!ClienticsConfig::pullEnabled()) {
            return ['ok' => false, 'error' => 'pull disabled'];
        }
        if (!ClienticsIdMapRepository::tableReady() || !Database::tableExists('base_clients')) {
            return ['ok' => false, 'error' => 'schema not ready'];
        }

        $phoneRaw = (string)($payload['client_phone'] ?? $payload['phone'] ?? '');
        $tel = ClienticsPhone::normalize($phoneRaw);
        if ($tel === null) {
            return ['ok' => true, 'action' => 'ignored', 'error' => 'no phone'];
        }

        $extId = (string)($payload['client_id'] ?? $payload['id'] ?? '');
        $accountId = ClienticsConfig::accountId();
        if ($accountId === '') {
            return ['ok' => false, 'error' => 'account_id not configured'];
        }

        $first = self::nullable($payload['client_first_name'] ?? $payload['first_name'] ?? null);
        $second = self::nullable($payload['client_second_name'] ?? $payload['second_name'] ?? null);
        $patron = self::nullable($payload['client_patron_name'] ?? $payload['patron_name'] ?? null);
        $name = trim((string)($payload['client_name'] ?? $payload['name'] ?? ''));
        if ($name === '') {
            $parts = array_filter([$second, $first, $patron]);
            $name = $parts !== [] ? implode(' ', $parts) : $tel;
        }
        $mail = trim((string)($payload['client_email'] ?? $payload['email'] ?? ''));

        $mappedLocal = $extId !== ''
            ? ClienticsIdMapRepository::findLocalId($accountId, 'client', $extId)
            : null;
        $existing = $mappedLocal !== null ? ClientsRepository::find($mappedLocal) : null;
        if ($existing === null) {
            $existing = ClientsRepository::findByTel($tel);
        }

        $data = [
            'name' => $name,
            'tel' => $tel,
            'mail' => $mail,
            'birthday' => null,
            'gender' => isset($existing['gender']) && is_numeric($existing['gender']) ? (int)$existing['gender'] : null,
            'enabled' => empty($payload['client_deleted']) && empty($payload['deleted']) ? 1 : 0,
            'status_id' => (int)($existing['status_id'] ?? 0),
            'staff_user_id' => isset($existing['staff_user_id']) && (int)$existing['staff_user_id'] > 0
                ? (int)$existing['staff_user_id']
                : null,
            'surname' => $second ?? ($existing['surname'] ?? null),
            'pname' => $first ?? ($existing['pname'] ?? null),
            'patronymic' => $patron ?? ($existing['patronymic'] ?? null),
            'passport_serie' => $existing['passport_serie'] ?? null,
            'passport_number' => $existing['passport_number'] ?? null,
            'passport_issued_by' => $existing['passport_issued_by'] ?? null,
            'passport_issued_at' => $existing['passport_issued_at'] ?? null,
            'passport_birthday' => $existing['passport_birthday'] ?? null,
            'address_passport' => $existing['address_passport'] ?? null,
            'address_fact' => $existing['address_fact'] ?? null,
            'subscribe' => (int)($existing['subscribe'] ?? 1),
            'notes' => $existing['notes'] ?? null,
        ];

        if ($existing !== null) {
            $localId = (int)$existing['id'];
            ClientsRepository::update($localId, $data);
            if ($extId !== '') {
                ClienticsIdMapRepository::upsert($accountId, 'client', $extId, $localId);
            }
            return ['ok' => true, 'action' => 'updated', 'local_id' => $localId, 'ext_id' => $extId];
        }

        $localId = ClientsRepository::create($data);
        if ($extId !== '') {
            ClienticsIdMapRepository::upsert($accountId, 'client', $extId, $localId);
        }
        return ['ok' => true, 'action' => 'created', 'local_id' => $localId, 'ext_id' => $extId];
    }

    private static function nullable($v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }
}
