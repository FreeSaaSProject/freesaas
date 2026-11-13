<?php
declare(strict_types=1);

/**
 * Unified activity feed of a deal (notes + stage changes); other modules append via
 * EventBus `crm.deal.timeline` ({deal_id, deal, items[]}) — items: {type, at, author_id, ...}.
 */
final class CrmTimeline
{
    /**
     * @param array<string,mixed> $deal
     * @return list<array<string,mixed>> newest first
     */
    public static function forDeal(array $deal, int $limit = 200): array
    {
        $dealId = (int)($deal['id'] ?? 0);
        if ($dealId < 1) {
            return [];
        }
        $items = [];
        foreach (CrmNotesRepository::listFor('deal', $dealId, $limit) as $n) {
            $items[] = [
                'type' => 'note',
                'id' => (int)$n['id'],
                'at' => (int)$n['created_at'],
                'author_id' => (int)$n['author_user_id'],
                'body' => (string)$n['body'],
            ];
        }
        foreach (CrmDealsRepository::stageHistory($dealId, $limit) as $h) {
            $items[] = [
                'type' => 'stage',
                'id' => (int)$h['id'],
                'at' => (int)$h['changed_at'],
                'author_id' => (int)$h['changed_by'],
                'from_stage_id' => (int)$h['from_stage_id'],
                'to_stage_id' => (int)$h['to_stage_id'],
                'from_name' => (string)($h['from_name'] ?? ''),
                'to_name' => (string)($h['to_name'] ?? ''),
            ];
        }
        $payload = EventBus::emit('crm.deal.timeline', ['deal_id' => $dealId, 'deal' => $deal, 'items' => $items]);
        if (is_array($payload) && is_array($payload['items'] ?? null)) {
            $items = $payload['items'];
        }
        $names = CrmUi::userNames(array_column($items, 'author_id'));
        foreach ($items as &$it) {
            $aid = (int)($it['author_id'] ?? 0);
            $it['author_name'] = $aid > 0 ? ($names[$aid] ?? '#' . $aid) : '';
        }
        unset($it);
        usort($items, static function (array $a, array $b): int {
            return [(int)($b['at'] ?? 0), (string)($b['type'] ?? ''), (int)($b['id'] ?? 0)]
                <=> [(int)($a['at'] ?? 0), (string)($a['type'] ?? ''), (int)($a['id'] ?? 0)];
        });
        return array_slice($items, 0, $limit);
    }
}
