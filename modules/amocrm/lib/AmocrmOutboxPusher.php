<?php
declare(strict_types=1);

final class AmocrmOutboxPusher
{
    /** @return array{ok:bool,processed:int,errors:int,message?:string} */
    public static function flush(int $limit = 20): array
    {
        if (!AmocrmConfig::pushEnabled()) {
            return ['ok' => true, 'processed' => 0, 'errors' => 0, 'message' => 'push disabled'];
        }
        if (!AmocrmConfig::apiReady()) {
            return ['ok' => false, 'processed' => 0, 'errors' => 0, 'message' => 'api not configured'];
        }
        $account = AmocrmConfig::accountKey();
        $processed = $errors = 0;
        foreach (AmocrmOutboxRepository::claimPending($limit) as $row) {
            $id = (int)$row['id'];
            try {
                self::pushOne($account, (string)$row['entity'], (int)$row['local_id'], (string)$row['op'], json_decode((string)($row['payload_json'] ?? ''), true) ?: []);
                AmocrmOutboxRepository::markDone($id);
                $processed++;
            } catch (Throwable $e) {
                AmocrmOutboxRepository::markError($id, $e->getMessage());
                $errors++;
            }
        }
        AmocrmSyncStateRepository::mark('outbox_flush', $errors === 0, '', $errors ? "errors=$errors" : '', [
            'processed' => $processed,
            'errors' => $errors,
        ]);
        return ['ok' => $errors === 0, 'processed' => $processed, 'errors' => $errors];
    }

    /** @param array<string,mixed> $payload */
    private static function pushOne(string $account, string $entity, int $localId, string $op, array $payload): void
    {
        $path = match ($entity) {
            'contact' => 'contacts',
            'company' => 'companies',
            'lead' => 'leads',
            default => '',
        };
        if ($path === '') {
            return;
        }
        $ext = AmocrmIdMapRepository::findExtId($account, $entity, $localId);
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        if ($op === 'delete') {
            if ($ext === null) {
                return;
            }
            $res = AmocrmApiClient::request('DELETE', $path, [['id' => (int)$ext]]);
            if (!$res['ok']) {
                throw new RuntimeException((string)($res['error'] ?? 'delete failed'));
            }
            return;
        }
        if ($ext !== null) {
            $fields['id'] = (int)$ext;
            $res = AmocrmApiClient::request('PATCH', $path, [$fields]);
            if (!$res['ok']) {
                throw new RuntimeException((string)($res['error'] ?? 'patch failed'));
            }
            return;
        }
        $res = AmocrmApiClient::request('POST', $path, [$fields]);
        if (!$res['ok']) {
            throw new RuntimeException((string)($res['error'] ?? 'post failed'));
        }
        $embedded = $res['result']['_embedded'][$path][0]['id'] ?? null;
        if ($embedded !== null) {
            AmocrmIdMapRepository::upsert($account, $entity, (string)$embedded, $localId);
        }
    }
}
