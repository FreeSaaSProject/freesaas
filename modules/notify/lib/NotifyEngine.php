<?php
declare(strict_types=1);

/**
 * Rule engine. Producers emit `notify.event`:
 *   event, ctx (vars + condition fields), subjects (client|operator|author → ids), entity, entity_id,
 *   client_id, anchor_ts, closes_tasks, dedupe_suffix.
 * Listener result keys: handled, matched (rule ids), results (delivery rows), error.
 * Producers that offer confirm timers also answer `notify.scan` (see tick()).
 */
final class NotifyEngine
{
    private const MAX_HORIZON = 1209600; // 14 days

    /**
     * EventBus listener for notify.event. Never throws into the host action.
     *
     * @param mixed $payload
     * @return array<string,mixed>
     */
    public static function onEvent($payload): array
    {
        if (!is_array($payload)) {
            return ['handled' => false, 'error' => 'invalid payload'];
        }
        try {
            return self::process($payload, false);
        } catch (Throwable $e) {
            error_log('[notify.event] ' . $e->getMessage());
            $payload['handled'] = false;
            $payload['error'] = $e->getMessage();
            return $payload;
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed> payload + handled/matched/results (+ tasks when planning confirm rules)
     */
    public static function process(array $payload, bool $dry = false): array
    {
        $p = self::normalize($payload);
        $payload['handled'] = false;
        $payload['matched'] = [];
        $payload['results'] = [];
        $payload['tasks'] = [];
        if ($p['event'] === '' || !NotifyRulesRepository::tableReady()) {
            return $payload;
        }
        if (!$dry && !empty($payload['closes_tasks']) && $p['entity_id'] > 0) {
            NotifyTasksRepository::cancelByEntity($p['entity'], $p['entity_id'], 'closed');
        }
        $def = NotifyCatalog::get($p['event']) ?? ['default_template' => '', 'outcomes' => [], 'anchor' => ''];
        $queued = false;
        foreach (NotifyRulesRepository::listEnabledByEvent($p['event']) as $rule) {
            if (!self::conditionsMatch($rule['conditions'], $p['ctx'])) {
                continue;
            }
            $payload['matched'][] = $rule['id'];
            if ($rule['kind'] === 'confirm') {
                foreach (self::ensureTasks($rule, $p, $def, $dry) as $t) {
                    $payload['tasks'][] = $t['task'];
                    $payload['results'] = array_merge($payload['results'], $t['results']);
                }
            } else {
                $payload['results'] = array_merge($payload['results'], self::runNotify($rule, $p, $def, $dry));
            }
            if (!empty($rule['stop_on_match'])) {
                break;
            }
        }
        foreach ($payload['results'] as $r) {
            if (($r['status'] ?? '') === 'queued') {
                $queued = true;
            }
        }
        if ($queued && !$dry) {
            NotifyChannels::flush();
        }
        $payload['handled'] = $payload['matched'] !== [];
        return $payload;
    }

    /**
     * Dry run of ONE rule (enabled or not): nothing is written or sent.
     *
     * @param array<string,mixed> $rule hydrated rule
     * @param array<string,mixed> $payload event, ctx, subjects, entity, entity_id, client_id, anchor_ts
     * @return array{matched:bool,text:string,results:list<array<string,mixed>>,tasks:list<array<string,mixed>>}
     */
    public static function testRule(array $rule, array $payload): array
    {
        $payload['event'] = $rule['event'];
        $p = self::normalize($payload);
        $def = NotifyCatalog::get($p['event']) ?? ['default_template' => '', 'outcomes' => []];
        $out = ['matched' => self::conditionsMatch($rule['conditions'], $p['ctx']), 'text' => '', 'results' => [], 'tasks' => []];
        $tpl = (string)$rule['template'];
        if ($rule['kind'] === 'confirm' && $rule['stages'] !== [] && trim((string)$rule['stages'][0]['template']) !== '') {
            $tpl = (string)$rule['stages'][0]['template'];
        }
        $out['text'] = NotifyTemplate::render($tpl, $p['ctx'], (string)$def['default_template']);
        if (!$out['matched']) {
            return $out;
        }
        if ($rule['kind'] === 'confirm') {
            foreach (self::ensureTasks($rule, $p, $def, true) as $t) {
                $out['tasks'][] = $t['task'];
            }
        }
        $out['results'] = self::sendToTargets($rule, $rule['targets'], $out['text'], $p, ['dry' => true]);
        return $out;
    }

    /**
     * Placeholder values for every var of an event (rule test form).
     *
     * @param array<string,mixed> $def
     * @return array<string,string>
     */
    public static function sampleCtx(array $def): array
    {
        $out = [];
        foreach ((array)($def['vars'] ?? []) as $v) {
            $out[(string)$v] = '[' . $v . ']';
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $ctx
     * @param list<array{field:string,op:string,value:mixed}> $conditions
     */
    public static function conditionsMatch(array $conditions, array $ctx): bool
    {
        foreach ($conditions as $c) {
            if (!self::evaluateCondition((string)($c['field'] ?? ''), (string)($c['op'] ?? 'eq'), $c['value'] ?? '', $ctx)) {
                return false;
            }
        }
        return true;
    }

    /**
     * List context values (e.g. product_categories) match when ANY element matches (eq/in/contains)
     * and for neq/nin when NONE matches.
     *
     * @param mixed $value
     * @param array<string,mixed> $ctx
     */
    public static function evaluateCondition(string $field, string $op, $value, array $ctx): bool
    {
        $have = $ctx[$field] ?? '';
        $haveList = [];
        foreach (is_array($have) ? $have : [$have] as $h) {
            $haveList[] = is_bool($h) ? ($h ? '1' : '0') : (string)(is_scalar($h) ? $h : '');
        }
        $want = is_array($value) ? array_map('strval', $value) : [(string)$value];
        $eq = static function (string $a, string $b): bool {
            return is_numeric($a) && is_numeric($b) ? (float)$a === (float)$b : strcasecmp($a, $b) === 0;
        };
        $anyIn = static function (array $haves, array $wants) use ($eq): bool {
            foreach ($haves as $h) {
                foreach ($wants as $w) {
                    if ($eq($h, $w)) {
                        return true;
                    }
                }
            }
            return false;
        };
        switch ($op) {
            case 'eq':
            case 'in':
                return $anyIn($haveList, $want);
            case 'neq':
            case 'nin':
                return !$anyIn($haveList, $want);
            case 'contains':
                foreach ($haveList as $h) {
                    if ($want[0] !== '' && mb_stripos($h, $want[0]) !== false) {
                        return true;
                    }
                }
                return false;
            case 'gt':
            case 'gte':
            case 'lt':
            case 'lte':
                $h = $haveList[0] ?? '';
                if (!is_numeric($h) || !is_numeric($want[0])) {
                    return false;
                }
                $a = (float)$h;
                $b = (float)$want[0];
                return $op === 'gt' ? $a > $b : ($op === 'gte' ? $a >= $b : ($op === 'lt' ? $a < $b : $a <= $b));
        }
        return false;
    }

    // ---- notify kind -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $p
     * @param array<string,mixed> $def
     * @return list<array<string,mixed>>
     */
    private static function runNotify(array $rule, array $p, array $def, bool $dry): array
    {
        $text = NotifyTemplate::render((string)$rule['template'], $p['ctx'], (string)($def['default_template'] ?? ''));
        if (trim($text) === '') {
            return [];
        }
        return self::sendToTargets($rule, $rule['targets'], $text, $p, [
            'dedupe' => 'n' . $rule['id'] . ':' . $p['event'] . ':' . $p['entity_id'] . ':' . $p['dedupe_suffix'],
            'dry' => $dry,
        ]);
    }

    /**
     * @param array<string,mixed> $rule
     * @param list<array<string,mixed>> $targets
     * @param array<string,mixed> $p
     * @param array<string,mixed> $extra dedupe, dry, buttons, task_id
     * @return list<array<string,mixed>>
     */
    private static function sendToTargets(array $rule, array $targets, string $text, array $p, array $extra): array
    {
        $out = [];
        $ruleQuiet = $rule['quiet_from'] !== '' && $rule['quiet_to'] !== '';
        foreach (NotifyTargets::expand($targets, $p['subjects']) as $rcpt) {
            $dtsend = 0;
            if ($ruleQuiet) {
                $dtsend = NotifyConfig::quietShift(time(), $rule['quiet_from'], $rule['quiet_to']);
            } elseif ($rcpt['rcpt_kind'] === 'client') {
                $dtsend = NotifyConfig::quietShift(time(), NotifyConfig::get('quiet_from'), NotifyConfig::get('quiet_to'));
            }
            $opts = array_merge($extra, [
                'rule_id' => (int)$rule['id'],
                'event' => $p['event'],
                'entity' => $p['entity'],
                'entity_id' => $p['entity_id'],
                'client_id' => $p['client_id'],
                'dtsend' => $dtsend > time() ? $dtsend : 0,
            ]);
            foreach (NotifyChannels::deliver($rcpt, $text, $opts) as $r) {
                $out[] = $r;
            }
        }
        return $out;
    }

    // ---- confirm kind ----------------------------------------------------------------

    /**
     * Create (idempotently) one task per stage; immediate stages fire right away.
     *
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $p
     * @param array<string,mixed> $def
     * @return list<array{task:array<string,mixed>,results:list<array<string,mixed>>}>
     */
    public static function ensureTasks(array $rule, array $p, array $def, bool $dry = false): array
    {
        $out = [];
        $now = time();
        foreach ($rule['stages'] as $stage) {
            $immediate = $stage['anchor'] === 'now' && $stage['offset_sec'] <= 0;
            $anchor = $stage['anchor'] === 'now' ? $now : ($p['anchor_ts'] > 0 ? $p['anchor_ts'] : $now);
            $due = $immediate ? $now : $anchor + $stage['offset_sec'];
            if (!$immediate && $due < $now - 60) {
                continue; // stage window already passed for this entity
            }
            $row = [
                'rule_id' => $rule['id'],
                'event' => $p['event'],
                'entity' => $p['entity'],
                'entity_id' => $p['entity_id'],
                'client_id' => $p['client_id'],
                'stage_key' => $stage['key'],
                'anchor_ts' => $anchor,
                'due_ts' => $due,
                'dedupe_key' => 't:' . $rule['id'] . ':' . $p['entity'] . ':' . $p['entity_id'] . ':' . $stage['key'],
            ];
            if ($dry) {
                $out[] = ['task' => $row + ['status' => 'planned'], 'results' => []];
                continue;
            }
            $task = NotifyTasksRepository::create($row);
            if ($task === null) {
                continue;
            }
            $results = [];
            if ($task['STATUS'] === 'pending' && (int)$task['ATTEMPTS'] === 0) {
                // Entity moved (e.g. appointment rescheduled): follow the event anchor until first send.
                if ($stage['anchor'] === 'event' && (int)$task['DUE_TS'] !== $due) {
                    NotifyTasksRepository::touch((int)$task['ID'], 0, $due, 0);
                }
                if ($immediate) {
                    $results = self::fireTask($task, $rule, $stage, $p, $def);
                }
            }
            $out[] = ['task' => $task, 'results' => $results];
        }
        return $out;
    }

    /**
     * Send the stage message with outcome buttons; schedule retry or expiry.
     *
     * @param array<string,mixed> $task
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $stage
     * @param array<string,mixed> $p
     * @param array<string,mixed> $def
     * @return list<array<string,mixed>>
     */
    private static function fireTask(array $task, array $rule, array $stage, array $p, array $def, bool $escalate = false): array
    {
        $tpl = trim((string)$stage['template']) !== '' ? (string)$stage['template'] : (string)$rule['template'];
        $text = NotifyTemplate::render($tpl, $p['ctx'] + ['task_id' => (int)$task['ID']], (string)($def['default_template'] ?? ''));
        if ($escalate) {
            $text = t('notify.escalation.prefix') . "\n" . $text;
        }
        $attempt = (int)$task['ATTEMPTS'] + 1;
        $results = self::sendToTargets(
            $rule,
            $escalate && $stage['escalate'] !== [] ? $stage['escalate'] : $rule['targets'],
            $text,
            $p,
            [
                'dedupe' => 'task' . $task['ID'] . ':' . ($escalate ? 'esc' : 'a' . $attempt),
                'buttons' => self::buttons((int)$task['ID'], self::stageOutcomes($stage, $def)),
                'task_id' => (int)$task['ID'],
            ]
        );
        $lastId = 0;
        foreach ($results as $r) {
            if (!empty($r['delivery_id'])) {
                $lastId = (int)$r['delivery_id'];
            }
        }
        $now = time();
        $effMax = $stage['retry_sec'] > 0 ? $stage['max_attempts'] : 1;
        $next = $attempt < $effMax ? $now + $stage['retry_sec'] : $now + $stage['expire_sec'];
        NotifyTasksRepository::touch((int)$task['ID'], $attempt, $next, $lastId, $escalate ? 'escalated' : '');
        return $results;
    }

    /**
     * @param array<string,mixed> $stage
     * @param array<string,mixed> $def
     * @return list<string>
     */
    public static function stageOutcomes(array $stage, array $def): array
    {
        $o = $stage['outcomes'] !== [] ? $stage['outcomes'] : (array)($def['outcomes'] ?? []);
        return array_values(array_filter(array_map('strval', $o), static fn ($x) => preg_match('/^[a-z][a-z0-9_]{0,31}$/', $x) === 1));
    }

    /**
     * Inline keyboard; callback_data = nt:<taskId>:<outcome>.
     *
     * @param list<string> $outcomes
     * @return array{inline_keyboard:list<list<array<string,string>>>}
     */
    public static function buttons(int $taskId, array $outcomes): array
    {
        $rows = [];
        foreach ($outcomes as $o) {
            $rows[] = [['text' => self::outcomeLabel($o), 'callback_data' => 'nt:' . $taskId . ':' . $o]];
        }
        return ['inline_keyboard' => $rows];
    }

    public static function outcomeLabel(string $outcome): string
    {
        $key = 'notify.outcome.' . $outcome;
        $label = t($key);
        return $label !== $key ? $label : ucfirst(str_replace('_', ' ', $outcome));
    }

    /**
     * Resolve a pending task exactly once and announce it (`notify.task.resolved`).
     *
     * @return array{ok:bool,error:string,task:?array<string,mixed>}
     */
    public static function resolveTask(int $taskId, string $outcome, int $by = 0, string $comment = ''): array
    {
        $task = NotifyTasksRepository::find($taskId);
        if ($task === null) {
            return ['ok' => false, 'error' => t('notify.err.task_not_found'), 'task' => null];
        }
        $rule = NotifyRulesRepository::find((int)$task['RULE_ID']);
        $def = NotifyCatalog::get((string)$task['EVENT']) ?? [];
        $allowed = (array)($def['outcomes'] ?? []);
        foreach ((array)($rule['stages'] ?? []) as $st) {
            if ($st['key'] === $task['STAGE_KEY'] && $st['outcomes'] !== []) {
                $allowed = $st['outcomes'];
            }
        }
        if ($allowed !== [] && !in_array($outcome, $allowed, true)) {
            return ['ok' => false, 'error' => t('notify.err.outcome'), 'task' => $task];
        }
        if (!NotifyTasksRepository::resolve($taskId, $outcome, $by, $comment)) {
            return ['ok' => false, 'error' => t('notify.err.task_closed'), 'task' => $task];
        }
        $task = NotifyTasksRepository::find($taskId) ?? $task;
        EventBus::emit('notify.task.resolved', [
            'task_id' => $taskId,
            'task' => $task,
            'outcome' => $outcome,
            'event' => (string)$task['EVENT'],
            'entity' => (string)$task['ENTITY'],
            'entity_id' => (int)$task['ENTITY_ID'],
            'client_id' => (int)$task['CLIENT_ID'],
            'resolved_by' => $by,
            'comment' => $comment,
        ]);
        return ['ok' => true, 'error' => '', 'task' => $task];
    }

    // ---- cron ------------------------------------------------------------------------

    /**
     * Scan providers, ensure timed tasks, fire due stages / retries / escalations, expire.
     *
     * @return array<string,mixed>
     */
    public static function tick(): array
    {
        $stats = ['synced' => 0, 'scanned' => 0, 'tasks_new' => 0, 'fired' => 0, 'escalated' => 0, 'expired' => 0, 'cancelled' => 0];
        if (!NotifyRulesRepository::tableReady()) {
            return $stats;
        }
        $stats['synced'] = NotifyChannels::syncQueued(200);
        $limit = NotifyConfig::int('tick_limit', 5, 500);

        // 1) timed stages for entities that already exist
        $rules = NotifyRulesRepository::listEnabledConfirm();
        $events = [];
        $horizon = 3600;
        foreach ($rules as $rule) {
            $events[$rule['event']] = true;
            foreach ($rule['stages'] as $st) {
                $horizon = max($horizon, min(self::MAX_HORIZON, -$st['offset_sec'] + 3600));
            }
        }
        if ($events !== []) {
            $now = time();
            foreach (self::collectScan(array_keys($events), $now - 3600, $now + $horizon, null) as $item) {
                $stats['scanned']++;
                $def = NotifyCatalog::get($item['event']) ?? [];
                foreach ($rules as $rule) {
                    if ($rule['event'] === $item['event'] && self::conditionsMatch($rule['conditions'], $item['ctx'])) {
                        $stats['tasks_new'] += count(self::ensureTasks($rule, $item, $def));
                    }
                }
            }
        }

        // 2) due tasks
        foreach (NotifyTasksRepository::listDue($limit) as $task) {
            self::tickTask($task, $stats);
        }
        NotifyChannels::flush();

        // 3) housekeeping (once around 03:00)
        if ((int)date('G') === 3 && (int)date('i') < 5) {
            NotifyDeliveriesRepository::purgeOlderThan(NotifyConfig::int('journal_keep_days', 0, 3650));
        }
        return $stats;
    }

    /** @param array<string,mixed> $task @param array<string,int> $stats */
    private static function tickTask(array $task, array &$stats): void
    {
        $id = (int)$task['ID'];
        $rule = NotifyRulesRepository::find((int)$task['RULE_ID']);
        if ($rule === null || !$rule['enabled']) {
            $stats['cancelled'] += NotifyTasksRepository::setStatus($id, 'cancelled', 'rule_off') ? 1 : 0;
            return;
        }
        $stage = null;
        foreach ($rule['stages'] as $st) {
            if ($st['key'] === $task['STAGE_KEY']) {
                $stage = $st;
            }
        }
        $item = self::resolveItem((string)$task['EVENT'], (int)$task['ENTITY_ID']);
        if ($stage === null || $item === null || !self::conditionsMatch($rule['conditions'], $item['ctx'])) {
            $stats['cancelled'] += NotifyTasksRepository::setStatus($id, 'cancelled', 'gone') ? 1 : 0;
            return;
        }
        $def = NotifyCatalog::get($item['event']) ?? [];
        $effMax = $stage['retry_sec'] > 0 ? $stage['max_attempts'] : 1;
        $attempts = (int)$task['ATTEMPTS'];
        if ($attempts < $effMax) {
            self::fireTask($task, $rule, $stage, $item, $def);
            $stats['fired']++;
            return;
        }
        if ($stage['escalate'] !== [] && (string)$task['OUTCOME'] !== 'escalated') {
            self::fireTask($task, $rule, $stage, $item, $def, true);
            $stats['escalated']++;
            return;
        }
        $stats['expired'] += NotifyTasksRepository::setStatus($id, 'expired', 'no_answer') ? 1 : 0;
    }

    /**
     * Current producer view of one entity (null when it no longer awaits confirmation).
     *
     * @return array<string,mixed>|null
     */
    public static function resolveItem(string $event, int $entityId): ?array
    {
        foreach (self::collectScan([$event], 0, PHP_INT_MAX, ['event' => $event, 'entity_id' => $entityId]) as $item) {
            if ($item['event'] === $event && $item['entity_id'] === $entityId) {
                return $item;
            }
        }
        return null;
    }

    /**
     * @param list<string> $events
     * @param array{event:string,entity_id:int}|null $only
     * @return list<array<string,mixed>> normalized notify.event payloads
     */
    private static function collectScan(array $events, int $from, int $to, ?array $only): array
    {
        $res = EventBus::emit('notify.scan', [
            'events' => $events,
            'from' => $from,
            'to' => $to,
            'only' => $only,
            'items' => [],
        ]);
        $out = [];
        foreach ((array)($res['items'] ?? []) as $item) {
            if (is_array($item)) {
                $n = self::normalize($item);
                if ($n['event'] !== '' && in_array($n['event'], $events, true)) {
                    $out[] = $n;
                }
            }
        }
        return $out;
    }

    // ---- helpers ---------------------------------------------------------------------

    /**
     * @param array<string,mixed> $in
     * @return array{event:string,ctx:array<string,mixed>,subjects:array<string,mixed>,entity:string,entity_id:int,client_id:int,anchor_ts:int,dedupe_suffix:string}
     */
    public static function normalize(array $in): array
    {
        $ctx = is_array($in['ctx'] ?? null) ? $in['ctx'] : [];
        $subjects = is_array($in['subjects'] ?? null) ? $in['subjects'] : [];
        $clientId = (int)($in['client_id'] ?? ($subjects['client']['client_id'] ?? 0));
        if ($clientId > 0 && !isset($subjects['client'])) {
            $subjects['client'] = ['client_id' => $clientId];
        }
        $event = trim((string)($in['event'] ?? ''));
        $anchor = (int)($in['anchor_ts'] ?? 0);
        if ($anchor < 1) {
            $def = NotifyCatalog::get($event);
            $key = (string)($def['anchor'] ?? '');
            $anchor = $key !== '' ? (int)($ctx[$key] ?? 0) : 0;
        }
        return [
            'event' => $event,
            'ctx' => $ctx,
            'subjects' => $subjects,
            'entity' => (string)($in['entity'] ?? ''),
            'entity_id' => (int)($in['entity_id'] ?? 0),
            'client_id' => $clientId,
            'anchor_ts' => $anchor,
            'dedupe_suffix' => (string)($in['dedupe_suffix'] ?? ''),
        ];
    }
}
