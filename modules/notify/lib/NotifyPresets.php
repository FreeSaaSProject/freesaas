<?php
declare(strict_types=1);

/**
 * Ready-made rule packs for quick deploy of known producers (orders, procedures, …).
 * Idempotent: a rule named "preset …" is skipped if it already exists.
 */
final class NotifyPresets
{
    /**
     * @return list<array{id:string,label:string,description:string,needs:list<string>,rules:int}>
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::packs() as $id => $pack) {
            $out[] = [
                'id' => $id,
                'label' => t((string)$pack['label']),
                'description' => t((string)$pack['description']),
                'needs' => array_values((array)($pack['needs'] ?? [])),
                'rules' => count((array)($pack['rules'] ?? [])),
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $opts chat_id?, platform?, staff_ids? (CSV or list), bot_chat_id?, enabled?
     * @return array{ok:bool,error:string,created:int,skipped:int,ids:list<int>,pack:string}
     */
    public static function apply(string $packId, array $opts = []): array
    {
        $packs = self::packs();
        if (!isset($packs[$packId])) {
            return ['ok' => false, 'error' => t('notify.presets.err.unknown'), 'created' => 0, 'skipped' => 0, 'ids' => [], 'pack' => $packId];
        }
        if (!NotifyRulesRepository::tableReady()) {
            return ['ok' => false, 'error' => t('notify.err.table'), 'created' => 0, 'skipped' => 0, 'ids' => [], 'pack' => $packId];
        }
        $pack = $packs[$packId];
        $targets = self::resolveTargets($pack, $opts);
        if ($targets === null) {
            return ['ok' => false, 'error' => t('notify.presets.err.targets'), 'created' => 0, 'skipped' => 0, 'ids' => [], 'pack' => $packId];
        }

        $existing = [];
        foreach (NotifyRulesRepository::listAll() as $r) {
            $existing[(string)$r['name']] = true;
        }
        $enabled = !isset($opts['enabled']) || !empty($opts['enabled']);
        $created = 0;
        $skipped = 0;
        $ids = [];
        foreach ((array)$pack['rules'] as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $name = 'preset ' . $packId . '.' . (string)($rule['event'] ?? 'x');
            if (!empty($rule['name_suffix'])) {
                $name .= '.' . (string)$rule['name_suffix'];
            }
            if (isset($existing[$name])) {
                $skipped++;
                continue;
            }
            $ruleTargets = $targets;
            if (is_array($rule['targets'] ?? null) && $rule['targets'] !== []) {
                $ruleTargets = self::materializeTargets((array)$rule['targets'], $opts);
                if ($ruleTargets === []) {
                    $skipped++;
                    continue;
                }
            }
            try {
                $id = NotifyRulesRepository::save(0, [
                    'name' => $name,
                    'event' => (string)$rule['event'],
                    'enabled' => $enabled ? 1 : 0,
                    'sort' => (int)($rule['sort'] ?? 60),
                    'stop_on_match' => !empty($rule['stop_on_match']) ? 1 : 0,
                    'kind' => (string)($rule['kind'] ?? 'notify'),
                    'conditions' => (array)($rule['conditions'] ?? []),
                    'targets' => $ruleTargets,
                    'template' => (string)($rule['template'] ?? ''),
                    'stages' => (array)($rule['stages'] ?? []),
                    'quiet_from' => (string)($rule['quiet_from'] ?? ''),
                    'quiet_to' => (string)($rule['quiet_to'] ?? ''),
                ]);
            } catch (Throwable $e) {
                return [
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'created' => $created,
                    'skipped' => $skipped,
                    'ids' => $ids,
                    'pack' => $packId,
                ];
            }
            $existing[$name] = true;
            $ids[] = $id;
            $created++;
        }
        return ['ok' => true, 'error' => '', 'created' => $created, 'skipped' => $skipped, 'ids' => $ids, 'pack' => $packId];
    }

    /**
     * Apply every pack whose needs are satisfied by $opts (and subject-only packs always).
     *
     * @param array<string,mixed> $opts
     * @return array{ok:bool,error:string,results:list<array<string,mixed>>}
     */
    public static function applySatisfied(array $opts = []): array
    {
        $results = [];
        foreach (self::packs() as $id => $pack) {
            $needs = (array)($pack['needs'] ?? []);
            $hasChat = trim((string)($opts['chat_id'] ?? '')) !== '' || (int)($opts['bot_chat_id'] ?? 0) > 0;
            $hasStaff = self::parseStaffIds($opts) !== [];
            if ($needs !== []) {
                $okNeed = false;
                if (in_array('chat', $needs, true) && $hasChat) {
                    $okNeed = true;
                }
                if (in_array('staff', $needs, true) && $hasStaff) {
                    $okNeed = true;
                }
                // Packs that list both chat+staff accept either; subject-only packs have needs=[].
                if (!$okNeed) {
                    continue;
                }
            }
            $results[] = self::apply($id, $opts);
        }
        $ok = true;
        $err = '';
        foreach ($results as $r) {
            if (empty($r['ok'])) {
                $ok = false;
                $err = (string)($r['error'] ?? '');
                break;
            }
        }
        return ['ok' => $ok, 'error' => $err, 'results' => $results];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function packs(): array
    {
        $today = [['field' => 'is_today', 'op' => 'eq', 'value' => '1']];
        $operator = [['kind' => 'subject', 'subject' => 'operator', 'channels' => ['bot'], 'strategy' => 'all']];
        $issuer = [['kind' => 'subject', 'subject' => 'issuer', 'channels' => ['bot'], 'strategy' => 'all']];
        $chat = [['kind' => 'chat', 'chat_id' => '{chat_id}', 'platform' => '{platform}', 'channels' => ['bot'], 'strategy' => 'all']];

        return [
            'orders_group' => [
                'label' => 'notify.presets.orders_group.label',
                'description' => 'notify.presets.orders_group.desc',
                'needs' => ['chat'],
                'rules' => [
                    ['event' => 'orders.created', 'kind' => 'notify', 'targets' => $chat],
                    [
                        'event' => 'orders.cancelled',
                        'kind' => 'notify',
                        'targets' => $chat,
                        'conditions' => [['field' => 'cancelled_by', 'op' => 'eq', 'value' => 'client']],
                    ],
                ],
            ],
            'orders_confirm' => [
                'label' => 'notify.presets.orders_confirm.label',
                'description' => 'notify.presets.orders_confirm.desc',
                'needs' => ['chat'],
                'rules' => [
                    [
                        'event' => 'orders.confirm',
                        'kind' => 'confirm',
                        'targets' => $chat,
                        'stages' => [[
                            'key' => 'call',
                            'anchor' => 'event',
                            'offset_sec' => -86400,
                            'retry_sec' => 3600,
                            'max_attempts' => 3,
                            'expire_sec' => 3600,
                            'outcomes' => ['confirmed', 'no_answer', 'client_cancelled'],
                            'template' => '',
                        ]],
                    ],
                ],
            ],
            'procedures_operator' => [
                'label' => 'notify.presets.procedures_operator.label',
                'description' => 'notify.presets.procedures_operator.desc',
                'needs' => [],
                'rules' => [
                    [
                        'event' => 'procedures.created',
                        'kind' => 'notify',
                        'targets' => $operator,
                        'conditions' => array_merge($today, [['field' => 'operator_hidden', 'op' => 'eq', 'value' => '0']]),
                    ],
                    [
                        'event' => 'procedures.updated',
                        'kind' => 'notify',
                        'targets' => $operator,
                        'conditions' => $today,
                    ],
                ],
            ],
            'vacations_group' => [
                'label' => 'notify.presets.vacations_group.label',
                'description' => 'notify.presets.vacations_group.desc',
                'needs' => ['chat'],
                'rules' => [
                    ['event' => 'vacations.remind', 'kind' => 'notify', 'targets' => $chat],
                ],
            ],
            'backups_ops' => [
                'label' => 'notify.presets.backups_ops.label',
                'description' => 'notify.presets.backups_ops.desc',
                'needs' => ['chat', 'staff'], // at least one resolved at apply time via merge
                'rules' => [
                    ['event' => 'backups.failed', 'kind' => 'notify'],
                    ['event' => 'backups.succeeded', 'kind' => 'notify', 'enabled_default' => 0],
                    ['event' => 'backups.stale', 'kind' => 'notify'],
                ],
            ],
            'edge_ops' => [
                'label' => 'notify.presets.edge_ops.label',
                'description' => 'notify.presets.edge_ops.desc',
                'needs' => ['chat', 'staff'],
                'rules' => [
                    ['event' => 'core.edge_down', 'kind' => 'notify'],
                    ['event' => 'core.edge_up', 'kind' => 'notify'],
                ],
            ],
            'payments_issuers' => [
                'label' => 'notify.presets.payments_issuers.label',
                'description' => 'notify.presets.payments_issuers.desc',
                'needs' => [],
                'rules' => [
                    ['event' => 'payments.day_payroll_requested', 'kind' => 'notify', 'targets' => $issuer],
                    ['event' => 'payments.day_payroll_cancelled', 'kind' => 'notify', 'targets' => $issuer],
                ],
            ],
            'video_alerts' => [
                'label' => 'notify.presets.video_alerts.label',
                'description' => 'notify.presets.video_alerts.desc',
                'needs' => ['chat'],
                'rules' => [
                    ['event' => 'video.alert', 'kind' => 'notify', 'targets' => $chat],
                ],
            ],
        ];
    }

    /**
     * Default pack-level targets from chat/staff opts (for packs without per-rule targets).
     *
     * @param array<string,mixed> $pack
     * @param array<string,mixed> $opts
     * @return list<array<string,mixed>>|null
     */
    private static function resolveTargets(array $pack, array $opts): ?array
    {
        $needs = (array)($pack['needs'] ?? []);
        // Subject-only packs: each rule carries its own targets.
        if ($needs === []) {
            return [];
        }
        $out = [];
        $chatId = trim((string)($opts['chat_id'] ?? ''));
        $botChatId = (int)($opts['bot_chat_id'] ?? 0);
        $platform = trim((string)($opts['platform'] ?? ''));
        if ($platform === '') {
            $platform = class_exists('NotifyConfig', false) ? NotifyConfig::get('default_platform') : 'max';
        }
        if (in_array('chat', $needs, true)) {
            if ($botChatId > 0) {
                $out[] = ['kind' => 'chat', 'bot_chat_id' => $botChatId, 'platform' => $platform, 'channels' => ['bot'], 'strategy' => 'all'];
            } elseif ($chatId !== '') {
                $out[] = ['kind' => 'chat', 'chat_id' => $chatId, 'platform' => $platform, 'channels' => ['bot'], 'strategy' => 'all'];
            }
        }
        $staff = self::parseStaffIds($opts);
        if (in_array('staff', $needs, true) && $staff !== []) {
            $out[] = ['kind' => 'staff', 'user_ids' => $staff, 'channels' => ['bot'], 'strategy' => 'all'];
        }
        // Packs that need chat OR staff (backups/edge): require at least one.
        if ($out === [] && (in_array('chat', $needs, true) || in_array('staff', $needs, true))) {
            // If every rule has explicit targets (subject), empty pack targets are OK —
            // but these packs rely on resolveTargets for the shared recipient.
            $hasOwn = false;
            foreach ((array)($pack['rules'] ?? []) as $r) {
                if (is_array($r) && !empty($r['targets'])) {
                    $hasOwn = true;
                    break;
                }
            }
            if (!$hasOwn) {
                return null;
            }
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $targets
     * @param array<string,mixed> $opts
     * @return list<array<string,mixed>>
     */
    private static function materializeTargets(array $targets, array $opts): array
    {
        $chatId = trim((string)($opts['chat_id'] ?? ''));
        $botChatId = (int)($opts['bot_chat_id'] ?? 0);
        $platform = trim((string)($opts['platform'] ?? ''));
        if ($platform === '') {
            $platform = class_exists('NotifyConfig', false) ? NotifyConfig::get('default_platform') : 'max';
        }
        $out = [];
        foreach ($targets as $t) {
            if (!is_array($t)) {
                continue;
            }
            if (($t['kind'] ?? '') === 'chat') {
                if ($botChatId > 0) {
                    $t['bot_chat_id'] = $botChatId;
                    unset($t['chat_id']);
                } elseif ($chatId !== '') {
                    $t['chat_id'] = $chatId;
                } else {
                    continue;
                }
                $t['platform'] = $platform;
                if (($t['platform'] ?? '') === '{platform}') {
                    $t['platform'] = $platform;
                }
            }
            if (($t['kind'] ?? '') === 'staff' && (string)($t['user_ids'] ?? '') === '{staff_ids}') {
                $ids = self::parseStaffIds($opts);
                if ($ids === []) {
                    continue;
                }
                $t['user_ids'] = $ids;
            }
            $out[] = $t;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $opts
     * @return list<int>
     */
    private static function parseStaffIds(array $opts): array
    {
        $raw = $opts['staff_ids'] ?? [];
        if (!is_array($raw)) {
            $raw = preg_split('/[\s,;|]+/', trim((string)$raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $out = [];
        foreach ($raw as $v) {
            $id = (int)$v;
            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }
}
