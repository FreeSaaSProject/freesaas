<?php
declare(strict_types=1);

final class BitrixOutboxPusher
{
    /**
     * @return array{ok:bool,processed:int,errors:int,message?:string}
     */
    public static function flush(int $limit = 20): array
    {
        if (!BitrixConfig::pushEnabled()) {
            return ['ok' => true, 'processed' => 0, 'errors' => 0, 'message' => 'push disabled'];
        }
        if (!BitrixConfig::apiReady()) {
            return ['ok' => false, 'processed' => 0, 'errors' => 0, 'message' => 'rest_url not configured'];
        }
        $portal = BitrixConfig::portal() !== '' ? BitrixConfig::portal() : 'default';
        $processed = 0;
        $errors = 0;
        foreach (BitrixOutboxRepository::claimPending($limit) as $row) {
            $id = (int)$row['id'];
            $entity = (string)$row['entity'];
            $localId = (int)$row['local_id'];
            $op = (string)$row['op'];
            $payload = json_decode((string)($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                $payload = [];
            }
            try {
                self::pushOne($portal, $entity, $localId, $op, $payload);
                BitrixOutboxRepository::markDone($id);
                $processed++;
            } catch (Throwable $e) {
                BitrixOutboxRepository::markError($id, $e->getMessage());
                $errors++;
            }
        }
        BitrixSyncStateRepository::mark('outbox_flush', $errors === 0, '', $errors ? "errors=$errors" : '', [
            'processed' => $processed,
            'errors' => $errors,
        ]);
        return ['ok' => $errors === 0, 'processed' => $processed, 'errors' => $errors];
    }

    /** @param array<string,mixed> $payload */
    private static function pushOne(string $portal, string $entity, int $localId, string $op, array $payload): void
    {
        if ($op === 'delete') {
            $ext = BitrixIdMapRepository::findExtId($portal, $entity, $localId);
            if ($ext === null) {
                return;
            }
            $method = match ($entity) {
                'contact' => 'crm.contact.delete',
                'company' => 'crm.company.delete',
                'deal' => 'crm.deal.delete',
                'lead' => 'crm.lead.delete',
                default => '',
            };
            if ($method === '') {
                return;
            }
            $res = BitrixApiClient::call($method, ['id' => $ext]);
            if (!$res['ok']) {
                throw new RuntimeException((string)($res['error'] ?? 'delete failed'));
            }
            return;
        }

        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        $ext = BitrixIdMapRepository::findExtId($portal, $entity, $localId);
        if ($ext !== null) {
            $method = match ($entity) {
                'contact' => 'crm.contact.update',
                'company' => 'crm.company.update',
                'deal' => 'crm.deal.update',
                'lead' => 'crm.lead.update',
                default => '',
            };
            if ($method === '') {
                return;
            }
            $res = BitrixApiClient::call($method, ['id' => $ext, 'fields' => $fields]);
            if (!$res['ok']) {
                throw new RuntimeException((string)($res['error'] ?? 'update failed'));
            }
            return;
        }
        $method = match ($entity) {
            'contact' => 'crm.contact.add',
            'company' => 'crm.company.add',
            'deal' => 'crm.deal.add',
            'lead' => 'crm.lead.add',
            default => '',
        };
        if ($method === '') {
            return;
        }
        $res = BitrixApiClient::call($method, ['fields' => $fields]);
        if (!$res['ok']) {
            throw new RuntimeException((string)($res['error'] ?? 'add failed'));
        }
        $newId = (string)($res['result'] ?? '');
        if ($newId !== '') {
            BitrixIdMapRepository::upsert($portal, $entity, $newId, $localId);
        }
    }
}
