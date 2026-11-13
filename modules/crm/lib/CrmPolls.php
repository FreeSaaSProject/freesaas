<?php
declare(strict_types=1);

/**
 * Deal ↔ questionnaires (poll_invites, soft): send a personal public link to a deal contact,
 * follow-up re-fill, answers summary, auto stage QUESTIONNAIRE_SENT / QUESTIONNAIRE_DONE.
 */
final class CrmPolls
{
    public const TARGET = 'poll_invite';
    public const STAGE_SENT = 'QUESTIONNAIRE_SENT';
    public const STAGE_DONE = 'QUESTIONNAIRE_DONE';

    public static function available(): bool
    {
        return ModuleManager::isEnabled('poll_invites') && class_exists('PollPublic') && class_exists('PollsRepository');
    }

    /** @return array<int,string> root poll id => name */
    public static function pollOptions(): array
    {
        return self::available() ? PollsRepository::rootNames(true) : [];
    }

    /**
     * @param array<string,mixed> $deal
     * @param array<string,mixed> $in poll_id, client_id (default primary contact), text, expire_days, skip_qids
     * @return array{invite_id:int,url:string}
     */
    public static function send(array $deal, array $in): array
    {
        self::assertReady();
        $dealId = (int)$deal['id'];
        $pollId = (int)($in['poll_id'] ?? 0);
        if (!isset(self::pollOptions()[$pollId])) {
            throw new InvalidArgumentException(t('crm.err.poll_required'));
        }
        $clientId = (int)($in['client_id'] ?? 0);
        $contacts = CrmDealsRepository::contactLinks($dealId);
        if ($clientId < 1) {
            foreach ($contacts as $c) {
                if (!empty($c['is_primary'])) {
                    $clientId = (int)$c['client_id'];
                }
            }
        }
        if ($clientId < 1 || !in_array($clientId, array_map('intval', array_column($contacts, 'client_id')), true)) {
            throw new InvalidArgumentException(t('crm.err.poll_contact'));
        }
        $resp = array_values(array_unique(array_filter([(int)Auth::userId(), (int)($deal['responsible_user_id'] ?? 0)])));
        $inviteId = PollPublic::create([
            'poll_id' => $pollId,
            'client_id' => $clientId,
            'text' => (string)($in['text'] ?? ''),
            'expire_days' => (int)($in['expire_days'] ?? 14),
            'resp_user_ids' => $resp,
            'skip_qids' => self::ids($in['skip_qids'] ?? []),
            'source' => 'crm',
        ]);
        CrmLinksRepository::add('deal', $dealId, self::TARGET, $inviteId);
        self::advanceStage($dealId, self::STAGE_SENT);
        return ['invite_id' => $inviteId, 'url' => self::urlFor($inviteId)];
    }

    /** @return array{invite_id:int,url:string} */
    public static function refill(int $dealId, int $inviteId, int $expireDays = 14): array
    {
        self::assertReady();
        if (!in_array($inviteId, array_column(CrmLinksRepository::targets('deal', $dealId, self::TARGET), 'target_id'), true)) {
            throw new InvalidArgumentException(t('crm.err.poll_not_linked'));
        }
        $newId = PollPublic::refill($inviteId, $expireDays);
        return ['invite_id' => $newId, 'url' => self::urlFor($newId)];
    }

    /** @return list<array<string,mixed>> newest first */
    public static function forDeal(int $dealId): array
    {
        if (!self::available()) {
            return [];
        }
        $polls = self::pollOptions() + PollsRepository::rootNames(false);
        $out = [];
        foreach (CrmLinksRepository::targets('deal', $dealId, self::TARGET) as $l) {
            $inv = PollInvitesRepository::find($l['target_id']);
            if ($inv === null) {
                continue;
            }
            $client = class_exists('ClientsRepository') ? ClientsRepository::find((int)$inv['id_user']) : null;
            $out[] = [
                'id' => (int)$inv['id'],
                'poll_id' => (int)$inv['id_poll'],
                'poll_name' => (string)($polls[(int)$inv['id_poll']] ?? ('#' . (int)$inv['id_poll'])),
                'client_id' => (int)$inv['id_user'],
                'client_name' => (string)($client['name'] ?? ''),
                'state' => PollPublic::state($inv),
                'url' => strlen((string)$inv['code']) >= PollPublic::MIN_PUBLIC_CODE_LEN ? PollPublic::url((string)$inv['code']) : '',
                'created_at' => (int)$inv['dt_create'],
                'started_at' => (int)$inv['dt_started'],
                'completed_at' => (int)$inv['dt_completed'],
                'recompleted_at' => (int)$inv['dt_recompleted'],
                'expire_at' => (int)$inv['dt_expire'],
                'linked_by' => $l['created_by'],
                'answers' => PollPublic::answersSummary($inv),
            ];
        }
        return $out;
    }

    /** poll_invites.completed → move linked deals to "questionnaire received". */
    public static function onCompleted(array $payload): array
    {
        $inviteId = (int)($payload['invite_id'] ?? 0);
        if ($inviteId < 1) {
            return $payload;
        }
        foreach (CrmLinksRepository::entitiesFor(self::TARGET, $inviteId) as $l) {
            if ($l['entity'] === 'deal') {
                self::advanceStage($l['entity_id'], self::STAGE_DONE);
            }
        }
        return $payload;
    }

    /** poll_invites.refilled → the follow-up invite belongs to the same deals. */
    public static function onRefilled(array $payload): array
    {
        $from = (int)($payload['from_invite_id'] ?? 0);
        $to = (int)($payload['invite_id'] ?? 0);
        foreach (CrmLinksRepository::entitiesFor(self::TARGET, $from) as $l) {
            CrmLinksRepository::add($l['entity'], $l['entity_id'], self::TARGET, $to);
        }
        return $payload;
    }

    /** poll_invites.public.context → {company.name}, {deal.title}, {manager.name} for linked deals. */
    public static function onPublicContext(array $payload): array
    {
        $inviteId = (int)($payload['invite']['id'] ?? 0);
        foreach (CrmLinksRepository::entitiesFor(self::TARGET, $inviteId) as $l) {
            if ($l['entity'] !== 'deal') {
                continue;
            }
            $deal = CrmDealsRepository::find($l['entity_id']);
            if ($deal === null) {
                continue;
            }
            $payload['vars']['deal.title'] = (string)$deal['title'];
            $payload['vars']['company.name'] = (string)($deal['company_name'] ?? '');
            $rid = (int)$deal['responsible_user_id'];
            if ($rid > 0) {
                $payload['vars']['manager.name'] = CrmUi::userNames([$rid])[$rid] ?? '';
            }
            break;
        }
        return $payload;
    }

    /** Moves forward only (never back, never out of a closed S/F stage); no-op when the pipeline has no such stage. */
    public static function advanceStage(int $dealId, string $code): bool
    {
        $deal = CrmDealsRepository::find($dealId);
        if ($deal === null || in_array((string)($deal['stage_semantic'] ?? 'P'), ['S', 'F'], true)) {
            return false;
        }
        $target = null;
        $current = null;
        foreach (CrmPipelinesRepository::listStages((int)$deal['pipeline_id'], true) as $s) {
            if ((string)$s['code'] === $code) {
                $target = $s;
            }
            if ((int)$s['id'] === (int)$deal['stage_id']) {
                $current = $s;
            }
        }
        if ($target === null || ($current !== null && (int)$current['sort_order'] >= (int)$target['sort_order'])) {
            return false;
        }
        CrmDealsRepository::moveStage($dealId, (int)$target['id']);
        return true;
    }

    private static function urlFor(int $inviteId): string
    {
        $inv = PollInvitesRepository::find($inviteId);
        return $inv !== null ? PollPublic::url((string)$inv['code']) : '';
    }

    private static function assertReady(): void
    {
        if (!self::available()) {
            throw new RuntimeException(t('crm.err.polls_unavailable'));
        }
        if (!Auth::can('poll_invites.edit')) {
            throw new RuntimeException(t('core.common.forbidden'));
        }
    }

    /** @return list<int> */
    private static function ids($raw): array
    {
        $parts = is_array($raw) ? $raw : (preg_split('/[\s,;]+/', (string)$raw) ?: []);
        return array_values(array_unique(array_filter(array_map('intval', $parts))));
    }
}
