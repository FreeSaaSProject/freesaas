<?php
declare(strict_types=1);

/**
 * Pull-side handling of Clientics eventHandler payloads.
 * Upserts clients; when appointment fields present, upserts calendar event.
 */
final class ClienticsWebhookIngest
{
    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,action?:string,local_id?:int,ext_id?:string,appointment?:array<string,mixed>,error?:string}
     */
    public static function ingest(array $payload): array
    {
        if (!ClienticsConfig::pullEnabled()) {
            return ['ok' => false, 'error' => 'pull disabled'];
        }
        if (!ClienticsIdMapRepository::tableReady() || !Database::tableExists('base_clients')) {
            return ['ok' => false, 'error' => 'schema not ready'];
        }

        $eventKey = self::eventKey($payload);
        if ($eventKey !== '' && !ClienticsWebhookEventsRepository::claim($eventKey)) {
            return ['ok' => true, 'action' => 'duplicate', 'ext_id' => (string)($payload['client_id'] ?? $payload['id'] ?? '')];
        }

        $clientResult = self::upsertClient($payload);
        if (empty($clientResult['ok'])) {
            return $clientResult;
        }

        $appointmentId = (string)($payload['appointment_id'] ?? $payload['appointment.id'] ?? '');
        if ($appointmentId === '' || $appointmentId === '0') {
            return $clientResult;
        }

        if (!class_exists('ModuleManager', false) || !ModuleManager::isEnabled('calendar')
            || !class_exists('ClienticsAppointmentsImporter', false)) {
            $clientResult['appointment'] = ['ok' => false, 'error' => 'calendar not enabled'];
            return $clientResult;
        }

        $accountId = ClienticsConfig::accountId();
        $row = [
            'id' => $appointmentId,
            'client_id' => (string)($payload['client_id'] ?? ''),
            'executor_id' => (string)($payload['executor_id'] ?? ''),
            'start_datetime' => (string)($payload['appointment_date'] ?? $payload['appointment.date'] ?? ''),
            'start_time' => (string)($payload['appointment_time'] ?? $payload['appointment.time'] ?? ''),
            'finish_datetime' => (string)($payload['appointment_date'] ?? $payload['appointment.date'] ?? ''),
            'finish_time' => (string)($payload['appointment_finish_time'] ?? ''),
            'status' => (string)($payload['appointment_status'] ?? $payload['status'] ?? ''),
            'services' => (string)($payload['services'] ?? ''),
        ];
        // If finish time missing, default +1h via importer.
        if ($row['finish_time'] === '' && $row['start_time'] !== '') {
            $ts = strtotime(trim($row['start_datetime'] . ' ' . $row['start_time']));
            if ($ts !== false) {
                $row['finish_datetime'] = date('Y-m-d', $ts + 3600);
                $row['finish_time'] = date('H:i:s', $ts + 3600);
            }
        }
        if (is_string($row['services']) && $row['services'] !== '') {
            $names = array_filter(array_map('trim', explode(',', $row['services'])));
            $row['appointed_services'] = array_map(static fn(string $n): array => ['name' => $n], $names);
        }

        try {
            $action = ClienticsAppointmentsImporter::upsertOne($accountId, $row, false);
            $clientResult['appointment'] = [
                'ok' => true,
                'action' => $action,
                'ext_id' => $appointmentId,
            ];
        } catch (Throwable $e) {
            $clientResult['appointment'] = [
                'ok' => false,
                'error' => $e->getMessage(),
                'ext_id' => $appointmentId,
            ];
        }

        return $clientResult;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,action?:string,local_id?:int,ext_id?:string,error?:string}
     */
    private static function upsertClient(array $payload): array
    {
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
        $birthday = self::birthday($payload['client_birth_date'] ?? $payload['birth_date'] ?? null);
        $notes = self::nullable($payload['client_memo'] ?? $payload['comment'] ?? null);

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
            'mail' => $mail !== '' ? $mail : (string)($existing['mail'] ?? ''),
            'birthday' => $birthday ?? ($existing['birthday'] ?? null),
            'gender' => isset($existing['gender']) && is_numeric($existing['gender']) ? (int)$existing['gender'] : null,
            'enabled' => empty($payload['client_deleted']) && empty($payload['deleted']) && empty($payload['blocked']) ? 1 : 0,
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
            'notes' => $notes !== null ? $notes : ($existing['notes'] ?? null),
        ];

        ClienticsSyncContext::begin('clientics');
        try {
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
        } finally {
            ClienticsSyncContext::end();
        }
    }

    /** @param array<string,mixed> $payload */
    private static function eventKey(array $payload): string
    {
        $bits = [
            (string)($payload['event'] ?? $payload['event_type'] ?? 'wh'),
            (string)($payload['client_id'] ?? $payload['id'] ?? ''),
            (string)($payload['appointment_id'] ?? ''),
            (string)($payload['modified'] ?? $payload['created'] ?? ''),
        ];
        $key = implode(':', array_filter($bits, static fn(string $s): bool => $s !== ''));
        return $key !== '' ? substr($key, 0, 190) : '';
    }

    private static function nullable($v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }

    private static function birthday($raw): ?string
    {
        if ($raw === null || $raw === '' || $raw === '0000-00-00' || $raw === false) {
            return null;
        }
        $s = trim((string)$raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s, $m)) {
            return substr($m[0], 0, 10);
        }
        $ts = strtotime($s);
        if ($ts === false || $ts <= 0) {
            return null;
        }
        return date('Y-m-d', $ts);
    }
}
