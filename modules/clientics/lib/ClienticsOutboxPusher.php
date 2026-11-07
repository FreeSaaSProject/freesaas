<?php
declare(strict_types=1);

final class ClienticsOutboxPusher
{
    /**
     * @return array{ok:bool,processed:int,errors:int,message?:string}
     */
    public static function flush(int $limit = 20): array
    {
        if (!ClienticsConfig::pushEnabled()) {
            return ['ok' => true, 'processed' => 0, 'errors' => 0, 'message' => 'push disabled'];
        }
        if (!ClienticsConfig::apiReady()) {
            return ['ok' => false, 'processed' => 0, 'errors' => 0, 'message' => 'api config incomplete'];
        }
        $accountId = ClienticsConfig::accountId();
        $processed = 0;
        $errors = 0;
        foreach (ClienticsOutboxRepository::claimPending($limit) as $row) {
            $id = (int)$row['id'];
            $entity = (string)$row['entity'];
            $localId = (int)$row['local_id'];
            $op = (string)$row['op'];
            $payload = json_decode((string)($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                $payload = [];
            }
            try {
                self::pushOne($accountId, $entity, $localId, $op, $payload);
                ClienticsOutboxRepository::markDone($id);
                $processed++;
            } catch (Throwable $e) {
                ClienticsOutboxRepository::markError($id, $e->getMessage());
                $errors++;
            }
        }
        ClienticsSyncStateRepository::mark('outbox_flush', $errors === 0, '', [
            'processed' => $processed,
            'errors' => $errors,
        ]);
        return ['ok' => $errors === 0, 'processed' => $processed, 'errors' => $errors];
    }

    /** @param array<string,mixed> $payload */
    private static function pushOne(string $accountId, string $entity, int $localId, string $op, array $payload): void
    {
        if ($entity === 'client') {
            self::pushClient($accountId, $localId, $payload);
            return;
        }
        if ($entity === 'appointment') {
            if (!ClienticsConfig::appointmentSotIsFreesaas()) {
                return;
            }
            self::pushAppointment($accountId, $localId, $payload);
            return;
        }
    }

    /** @param array<string,mixed> $payload */
    private static function pushClient(string $accountId, int $localId, array $payload): void
    {
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        $phone = trim((string)($fields['phone'] ?? ''));
        if ($phone === '') {
            throw new RuntimeException('client phone required for Clientics push');
        }
        $first = trim((string)($fields['first_name'] ?? ''));
        if ($first === '') {
            $first = $phone;
        }
        $body = [
            'phone' => $phone,
            'first_name' => $first,
        ];
        foreach (['second_name', 'patron_name', 'email', 'lead_source'] as $k) {
            $v = trim((string)($fields[$k] ?? ''));
            if ($v !== '') {
                $body[$k] = $v;
            }
        }

        $ext = ClienticsIdMapRepository::findExtId($accountId, 'client', $localId);
        if ($ext !== null) {
            $body['id'] = $ext;
            $res = ClienticsApiClient::editModule('Clients/', $body);
            if (!$res['ok']) {
                throw new RuntimeException((string)($res['error'] ?? 'client edit failed'));
            }
            return;
        }
        $res = ClienticsApiClient::addModule('Clients/', $body);
        if (!$res['ok']) {
            throw new RuntimeException((string)($res['error'] ?? 'client add failed'));
        }
        $newId = (string)($res['id'] ?? '');
        if ($newId === '' && is_array($res['object'] ?? null)) {
            $newId = (string)($res['object']['id'] ?? '');
        }
        if ($newId !== '') {
            ClienticsIdMapRepository::upsert($accountId, 'client', $newId, $localId);
        }
    }

    /** @param array<string,mixed> $payload */
    private static function pushAppointment(string $accountId, int $localId, array $payload): void
    {
        $clientExt = (string)($payload['client_ext_id'] ?? '');
        $executorExt = (string)($payload['executor_ext_id'] ?? '');
        if ($clientExt === '') {
            throw new RuntimeException('appointment push needs mapped client');
        }
        if ($executorExt === '') {
            throw new RuntimeException('appointment push needs mapped executor (user)');
        }
        $start = (int)($payload['dtstart'] ?? 0);
        $end = (int)($payload['dtend'] ?? 0);
        if ($start < 1 || $end <= $start) {
            throw new RuntimeException('invalid appointment datetime');
        }

        $body = [
            'client_id' => $clientExt,
            'executor_id' => $executorExt,
            'status' => (string)($payload['status'] ?? 'confirmed'),
            'start_datetime' => date('Y-m-d', $start),
            'start_time' => date('H:i:s', $start),
            'finish_datetime' => date('Y-m-d', $end),
            'finish_time' => date('H:i:s', $end),
        ];

        $ext = ClienticsIdMapRepository::findExtId($accountId, 'appointment', $localId);
        if ($ext !== null) {
            $body['id'] = $ext;
            // edit API uses combined datetime strings in go-clientix
            $body['start_datetime'] = date('Y-m-d H:i:s', $start);
            $body['finish_datetime'] = date('Y-m-d H:i:s', $end);
            unset($body['start_time'], $body['finish_time']);
            $res = ClienticsApiClient::editModule('Appointments/', $body);
            if (!$res['ok']) {
                throw new RuntimeException((string)($res['error'] ?? 'appointment edit failed'));
            }
            return;
        }
        $res = ClienticsApiClient::addModule('Appointments/', $body);
        if (!$res['ok']) {
            throw new RuntimeException((string)($res['error'] ?? 'appointment add failed'));
        }
        $newId = (string)($res['id'] ?? '');
        if ($newId === '' && is_array($res['object'] ?? null)) {
            $newId = (string)($res['object']['id'] ?? '');
        }
        if ($newId !== '') {
            ClienticsIdMapRepository::upsert($accountId, 'appointment', $newId, $localId);
        }
    }
}
