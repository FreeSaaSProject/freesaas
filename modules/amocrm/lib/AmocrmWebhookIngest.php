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
        foreach (['contacts', 'companies', 'leads'] as $bucket) {
            $block = $payload[$bucket] ?? null;
            if (!is_array($block)) {
                continue;
            }
            foreach (['add', 'update'] as $op) {
                $list = $block[$op] ?? [];
                if (!is_array($list)) {
                    continue;
                }
                foreach ($list as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $extId = (string)($row['id'] ?? '');
                    $key = $bucket . ':' . $op . ':' . $extId;
                    if (!AmocrmWebhookEventsRepository::claim($key)) {
                        continue;
                    }
                    $entity = rtrim($bucket, 's');
                    if ($bucket === 'leads') {
                        $entity = 'lead';
                    } elseif ($bucket === 'companies') {
                        $entity = 'company';
                    } elseif ($bucket === 'contacts') {
                        $entity = 'contact';
                    }
                    CrmSyncContext::begin('amocrm');
                    try {
                        if ($entity === 'contact') {
                            AmocrmImporter::upsertContact($account, $row);
                        } elseif ($entity === 'company') {
                            AmocrmImporter::upsertCompany($account, $row);
                        } else {
                            AmocrmImporter::upsertLead($account, $row);
                        }
                    } finally {
                        CrmSyncContext::end();
                    }
                    $actions++;
                }
            }
        }
        AmocrmSyncStateRepository::mark('webhook', true, '', '', ['actions' => $actions]);
        return ['ok' => true, 'action' => 'processed:' . $actions];
    }
}
