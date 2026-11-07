<?php
declare(strict_types=1);

final class BitrixWebhookIngest
{
    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,action?:string,error?:string}
     */
    public static function ingest(array $payload): array
    {
        if (!BitrixConfig::pullEnabled()) {
            return ['ok' => false, 'error' => 'pull disabled'];
        }
        $event = (string)($payload['event'] ?? $payload['EVENT'] ?? '');
        $fields = $payload['data']['FIELDS'] ?? $payload['data']['fields'] ?? $payload['FIELDS'] ?? [];
        if (!is_array($fields)) {
            $fields = [];
        }
        $extId = (string)($fields['ID'] ?? $fields['id'] ?? '');
        $eventKey = $event . ':' . $extId . ':' . md5(json_encode($payload) ?: '');
        if (!BitrixWebhookEventsRepository::claim($eventKey)) {
            return ['ok' => true, 'action' => 'duplicate'];
        }
        if ($extId === '') {
            return ['ok' => true, 'action' => 'ignored'];
        }

        $entity = '';
        $method = '';
        $ev = strtolower($event);
        if (str_contains($ev, 'contact')) {
            $entity = 'contact';
            $method = 'crm.contact.get';
        } elseif (str_contains($ev, 'company')) {
            $entity = 'company';
            $method = 'crm.company.get';
        } elseif (str_contains($ev, 'deal')) {
            $entity = 'deal';
            $method = 'crm.deal.get';
        } elseif (str_contains($ev, 'lead')) {
            $entity = 'lead';
            $method = 'crm.lead.get';
        }
        if ($entity === '') {
            return ['ok' => true, 'action' => 'ignored_event'];
        }
        if (str_contains($ev, 'delete')) {
            return ['ok' => true, 'action' => 'delete_ignored'];
        }

        $res = BitrixApiClient::call($method, ['id' => $extId]);
        if (!$res['ok'] || !is_array($res['result'] ?? null)) {
            return ['ok' => false, 'error' => (string)($res['error'] ?? 'get failed')];
        }
        $portal = BitrixConfig::portal() !== '' ? BitrixConfig::portal() : 'default';
        CrmSyncContext::begin('bitrix');
        try {
            // Reuse importer private path via one-row dry=false helpers by temporary public run is heavy;
            // call entity-specific upsert through a thin public wrapper.
            $action = BitrixWebhookApply::apply($portal, $entity, $res['result']);
        } finally {
            CrmSyncContext::end();
        }
        BitrixSyncStateRepository::mark('webhook', true, $extId, '', ['event' => $event, 'action' => $action]);
        return ['ok' => true, 'action' => $action];
    }
}
