<?php
declare(strict_types=1);

final class AmocrmWebhookIngest
{
    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,action?:string,error?:string}
     */
    public static function ingest(array $payload): array
    {
        if (!AmocrmConfig::pullEnabled()) {
            return ['ok' => false, 'error' => 'pull disabled'];
        }
        $account = AmocrmConfig::accountKey();
        $actions = 0;
        $errors = 0;
        foreach (['contacts', 'companies', 'leads'] as $bucket) {
            $block = $payload[$bucket] ?? null;
            if (!is_array($block)) {
                continue;
            }
            // status / responsible are distinct amoCRM actions; treat like updates for upsert.
            foreach (['add', 'update', 'status', 'responsible'] as $op) {
                $list = $block[$op] ?? [];
                if (!is_array($list)) {
                    continue;
                }
                foreach ($list as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $extId = (string)($row['id'] ?? '');
                    if ($extId === '') {
                        continue;
                    }
                    // Per-delivery key: id alone would drop later updates of the same entity.
                    $key = self::eventKey($bucket, $op, $row);
                    if (!AmocrmWebhookEventsRepository::claim($key)) {
                        continue;
                    }
                    $entity = match ($bucket) {
                        'leads' => 'lead',
                        'companies' => 'company',
                        'contacts' => 'contact',
                        default => rtrim($bucket, 's'),
                    };
                    CrmSyncContext::begin('amocrm');
                    try {
                        if ($entity === 'contact') {
                            AmocrmImporter::upsertContact($account, $row);
                        } elseif ($entity === 'company') {
                            AmocrmImporter::upsertCompany($account, $row);
                        } else {
                            AmocrmImporter::upsertLead($account, $row);
                        }
                        $actions++;
                    } catch (Throwable $e) {
                        AmocrmWebhookEventsRepository::release($key);
                        $errors++;
                        AmocrmSyncStateRepository::mark(
                            'webhook',
                            false,
                            $extId,
                            $e->getMessage(),
                            ['bucket' => $bucket, 'op' => $op, 'key' => $key]
                        );
                    } finally {
                        CrmSyncContext::end();
                    }
                }
            }
        }
        AmocrmSyncStateRepository::mark('webhook', $errors === 0, '', '', [
            'actions' => $actions,
            'errors' => $errors,
        ]);
        return [
            'ok' => $errors === 0,
            'action' => 'processed:' . $actions,
            'error' => $errors > 0 ? ('apply_errors=' . $errors) : null,
        ];
    }

    /**
     * Idempotency key for one webhook delivery of one entity row.
     * Must distinguish successive updates of the same id (review MEDIUM).
     *
     * @param array<string,mixed> $row
     */
    public static function eventKey(string $bucket, string $op, array $row): string
    {
        $extId = (string)($row['id'] ?? '');
        $rev = (string)($row['updated_at'] ?? $row['last_modified'] ?? $row['modified_at'] ?? '');
        if ($rev === '' || $rev === '0') {
            // No revision field — fingerprint this payload so different updates differ,
            // while identical redeliveries still collide on UNIQUE claim.
            $rev = substr(hash('sha256', (string)json_encode(self::normalizeForHash($row))), 0, 32);
        }
        return $bucket . ':' . $op . ':' . $extId . ':' . $rev;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalizeForHash(array $row): array
    {
        ksort($row);
        foreach ($row as $k => $v) {
            if (is_array($v)) {
                $row[$k] = self::normalizeForHash($v);
            }
        }
        return $row;
    }
}
