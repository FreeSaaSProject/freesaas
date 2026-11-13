<?php
declare(strict_types=1);

/**
 * Deal ↔ core tickets (base_news TYPE=2): create from a deal, link existing, summaries for card/timeline.
 * Tickets stay owned by core (comments, assignees, gcal sync); CRM keeps only base_crm_links rows.
 */
final class CrmTickets
{
    public const TARGET = 'ticket';

    public static function available(): bool
    {
        return class_exists('NewsRepository') && Database::tableExists('base_news');
    }

    /**
     * @param array<string,mixed> $deal
     * @param array<string,mixed> $in name, deadline (Y-m-d), users_resp (ids array | csv), curator_id, body
     */
    public static function createForDeal(array $deal, array $in): int
    {
        if (!self::available()) {
            throw new RuntimeException(t('crm.err.tickets_unavailable'));
        }
        if (!Auth::can('core.news')) {
            throw new RuntimeException(t('core.common.forbidden'));
        }
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('crm.err.ticket_name'));
        }
        $deadline = NewsRepository::deadlineFromDate((string)($in['deadline'] ?? ''));
        if ($deadline < 1) {
            throw new InvalidArgumentException(t('crm.err.ticket_deadline'));
        }
        $resp = self::idsFrom($in['users_resp'] ?? []);
        if ($resp === [] && (int)($deal['responsible_user_id'] ?? 0) > 0) {
            $resp = [(int)$deal['responsible_user_id']];
        }
        $curator = (int)($in['curator_id'] ?? 0) ?: (int)Auth::userId();
        $body = trim((string)($in['body'] ?? ''));
        if ($body !== '' && html_ugc_reject_reason($body) !== null) {
            throw new InvalidArgumentException(t('core.news_edit.err.unsafe_html'));
        }
        $id = NewsRepository::create([
            'NAME' => $name,
            'BIG' => $body !== '' ? nl2br(e($body)) : '',
            'TYPE' => 2,
            'STATUS' => 1,
            'DT_DEADLINE' => $deadline,
            'USERS_RESP' => NewsRepository::encodeIdList($resp),
            'ID_CURATOR' => $curator,
        ], (int)Auth::userId());
        CrmLinksRepository::add('deal', (int)$deal['id'], self::TARGET, $id);
        return $id;
    }

    public static function link(int $dealId, int $ticketId): void
    {
        if (!Auth::can('core.news')) {
            throw new RuntimeException(t('core.common.forbidden'));
        }
        $row = self::available() ? NewsRepository::find($ticketId) : null;
        if ($row === null || (int)$row['TYPE'] !== 2 || (int)($row['HAS_CHILDREN'] ?? 0) === 1) {
            throw new InvalidArgumentException(t('crm.err.ticket_not_found'));
        }
        if (!NewsRepository::canView($row, (int)Auth::userId())) {
            throw new RuntimeException(t('core.common.forbidden'));
        }
        CrmLinksRepository::add('deal', $dealId, self::TARGET, $ticketId);
    }

    /** @return list<array<string,mixed>> newest link first; tickets the user may not view are skipped */
    public static function forDeal(int $dealId): array
    {
        if (!self::available() || !Auth::can('core.news')) {
            return [];
        }
        $links = CrmLinksRepository::targets('deal', $dealId, self::TARGET);
        if ($links === []) {
            return [];
        }
        $uid = (int)Auth::userId();
        $statuses = NewsRepository::statuses();
        $rows = [];
        $userIds = [];
        foreach ($links as $l) {
            $row = NewsRepository::find($l['target_id']);
            if ($row === null || (int)($row['DISABLED'] ?? 0) === 1 || !NewsRepository::canView($row, $uid)) {
                continue;
            }
            $resp = NewsRepository::parseUserList((string)($row['USERS_RESP'] ?? ''));
            $userIds = array_merge($userIds, $resp, [(int)$row['ID_CURATOR']]);
            $rows[] = [$row, $resp, $l];
        }
        $names = CrmUi::userNames($userIds);
        $counts = class_exists('CommentsRepository')
            ? CommentsRepository::countsForNews(array_map(static fn(array $r): int => (int)$r[0]['ID'], $rows))
            : [];
        $today = mktime(0, 0, 0);
        $out = [];
        foreach ($rows as [$row, $resp, $l]) {
            $tid = (int)$row['ID'];
            $sid = (int)$row['STATUS'];
            $st = $statuses[$sid] ?? null;
            $terminal = $st !== null && (string)$st['DEADLINE_POLICY'] === 'forbidden';
            $deadline = (int)$row['DT_DEADLINE'];
            $out[] = [
                'id' => $tid,
                'name' => (string)$row['NAME'],
                'status_id' => $sid,
                'status_name' => $st !== null ? (string)$st['NAME'] : '',
                'status_color' => $st !== null ? (string)$st['COLOR'] : '',
                'done' => $terminal,
                'deadline' => $deadline,
                'overdue' => !$terminal && $deadline > 0 && $deadline < $today,
                'users_resp' => $resp,
                'users_resp_names' => array_values(array_map(static fn(int $u): string => $names[$u] ?? '#' . $u, $resp)),
                'curator_id' => (int)$row['ID_CURATOR'],
                'curator_name' => $names[(int)$row['ID_CURATOR']] ?? '',
                'comment_count' => (int)($counts[$tid] ?? 0),
                'created_at' => (int)$row['DT'],
                'linked_at' => $l['created_at'],
                'linked_by' => $l['created_by'],
                'url' => '/news/' . $tid,
            ];
        }
        return $out;
    }

    /**
     * Deals linked to a ticket (for the ticket card block / ticket JSON).
     *
     * @return list<array<string,mixed>>
     */
    public static function dealsForTicket(int $ticketId): array
    {
        $out = [];
        foreach (CrmLinksRepository::entitiesFor(self::TARGET, $ticketId) as $l) {
            if ($l['entity'] !== 'deal') {
                continue;
            }
            $d = CrmDealsRepository::find($l['entity_id']);
            if ($d === null) {
                continue;
            }
            $out[] = [
                'id' => (int)$d['id'],
                'title' => (string)$d['title'],
                'stage_name' => (string)($d['stage_name'] ?? ''),
                'stage_semantic' => (string)($d['stage_semantic'] ?? 'P'),
                'company_id' => (int)$d['company_id'],
                'company_name' => (string)($d['company_name'] ?? ''),
                'url' => '/crm/deals/' . (int)$d['id'],
            ];
        }
        return $out;
    }

    /** @return list<int> */
    private static function idsFrom($raw): array
    {
        $parts = is_array($raw) ? $raw : (preg_split('/[\s,;]+/', (string)$raw) ?: []);
        $ids = [];
        foreach ($parts as $p) {
            $n = (int)$p;
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }
        return array_values($ids);
    }
}
