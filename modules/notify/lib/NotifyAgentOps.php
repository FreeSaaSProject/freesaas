<?php
declare(strict_types=1);

/**
 * Surface A (/agent/notify/*) — agent_token twin of the staff UI. No secrets live in notify.
 */
final class NotifyAgentOps
{
    /** @var array<string,array{0:list<string>,1:string,2:string,3:string,4:list<string>}> path => [methods, op, handler, description, params] */
    private const OPS = [
        '/agent/notify/status' => [['GET'], 'notify.status', 'status', 'Health: tables, rule/task counts, 24h delivery counts, transports', []],
        '/agent/notify/catalog' => [['GET'], 'notify.catalog', 'catalog', 'Event catalog from enabled modules (fields, vars, subjects, outcomes)', []],
        '/agent/notify/rules' => [['GET'], 'notify.rules.list', 'rulesList', 'List rules; optional event filter', ['event']],
        '/agent/notify/rules/get' => [['GET'], 'notify.rules.get', 'rulesGet', 'One rule by id', ['id']],
        '/agent/notify/rules/save' => [['POST'], 'notify.rules.save', 'rulesSave', 'Create (no id) or update a rule; conditions/targets/stages as JSON arrays', ['id', 'name', 'event', 'enabled', 'sort', 'stop_on_match', 'kind', 'conditions', 'targets', 'template', 'stages', 'quiet_from', 'quiet_to']],
        '/agent/notify/rules/delete' => [['POST'], 'notify.rules.delete', 'rulesDelete', 'Delete a rule by id', ['id']],
        '/agent/notify/rules/test' => [['POST'], 'notify.rules.test', 'rulesTest', 'Dry run: render + expand recipients without sending', ['id', 'ctx', 'client_id', 'user_id']],
        '/agent/notify/settings' => [['GET', 'POST'], 'notify.settings', 'settings', 'Read or save cfg/modules/notify.ini', ['quiet_from', 'quiet_to', 'journal_keep_days', 'tick_limit', 'default_platform']],
        '/agent/notify/chats' => [['GET'], 'notify.chats', 'chats', 'Known bot group chats usable as targets (from bots)', ['q']],
        '/agent/notify/journal' => [['GET'], 'notify.journal', 'journal', 'Delivery journal; filters client_id, scope, rule_id, event, status, entity, q, limit, page', ['client_id', 'scope', 'rule_id', 'event', 'status', 'entity', 'q', 'limit', 'page']],
        '/agent/notify/tasks' => [['GET'], 'notify.tasks', 'tasks', 'Confirmation tasks; filters status, client_id, entity, entity_id, limit, page', ['status', 'client_id', 'entity', 'entity_id', 'limit', 'page']],
        '/agent/notify/tasks/resolve' => [['POST'], 'notify.tasks.resolve', 'tasksResolve', 'Resolve a pending task: task_id, outcome, comment', ['task_id', 'outcome', 'comment']],
        '/agent/notify/send' => [['POST'], 'notify.send', 'send', 'One-shot message: client_id|user_id|chat_id(+platform), text, channels', ['client_id', 'user_id', 'chat_id', 'platform', 'text', 'subject', 'channels']],
        '/agent/notify/presets' => [['GET'], 'notify.presets.list', 'presetsList', 'List ready-made rule packs for quick deploy', []],
        '/agent/notify/presets/apply' => [['POST'], 'notify.presets.apply', 'presetsApply', 'Apply pack id (or pack=all_satisfied); body: pack, chat_id?, bot_chat_id?, platform?, staff_ids?, enabled?', ['pack', 'chat_id', 'bot_chat_id', 'platform', 'staff_ids', 'enabled']],
    ];

    public static function register(): void
    {
        foreach (self::OPS as $path => [$methods, $opId, $handler, $descr, $params]) {
            foreach ($methods as $method) {
                $meta = ['description' => $descr];
                $meta[$method === 'GET' ? 'query' : 'body'] = $params;
                AgentOps::registerModuleOp($method, $path, $opId . ($method === 'POST' && count($methods) > 1 ? '.save' : ''), [self::class, $handler], $meta);
            }
            ModuleManager::registerRoute($path, [
                'module' => 'notify',
                'public' => true,
                'csrf' => false,
                'methods' => $methods,
                'handler' => static function (): void {
                    require_once base_path('app/agent_http.php');
                },
            ]);
        }
    }

    /** @return array<string,mixed> */
    public static function status(): array
    {
        $ready = NotifyRulesRepository::tableReady() && NotifyDeliveriesRepository::tableReady() && NotifyTasksRepository::tableReady();
        $rules = NotifyRulesRepository::listAll();
        return [
            'ok' => true,
            'tables_ready' => $ready,
            'rules' => count($rules),
            'rules_enabled' => count(array_filter($rules, static fn ($r) => !empty($r['enabled']))),
            'tasks_pending' => NotifyTasksRepository::countPending(),
            'tasks_asked' => NotifyTasksRepository::countPending(true),
            'deliveries_24h' => NotifyDeliveriesRepository::statusCounts(),
            'catalog_events' => count(NotifyCatalog::collect()),
            'bots' => ModuleManager::isEnabled('bots'),
            'mail' => ModuleManager::isEnabled('mail'),
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function catalog(): array
    {
        $events = [];
        foreach (NotifyCatalog::collect() as $def) {
            $def['label_text'] = t($def['label']);
            foreach ($def['fields'] as $i => $f) {
                $def['fields'][$i]['options_map'] = $f['type'] === 'enum' ? NotifyCatalog::fieldOptions($f) : [];
            }
            $events[] = $def;
        }
        return ['ok' => true, 'events' => $events, 'http' => 200];
    }

    /** @return array<string,mixed> */
    public static function rulesList(): array
    {
        $in = AgentOps::requestInput();
        $event = trim((string)($in['event'] ?? ''));
        $rules = array_values(array_filter(
            NotifyRulesRepository::listAll(),
            static fn ($r) => $event === '' || $r['event'] === $event
        ));
        return ['ok' => true, 'items' => $rules, 'total' => count($rules), 'http' => 200];
    }

    /** @return array<string,mixed> */
    public static function rulesGet(): array
    {
        $in = AgentOps::requestInput();
        $rule = NotifyRulesRepository::find((int)($in['id'] ?? 0));
        return $rule === null
            ? ['ok' => false, 'error' => 'rule not found', 'http' => 404]
            : ['ok' => true, 'rule' => $rule, 'http' => 200];
    }

    /** @return array<string,mixed> */
    public static function rulesSave(): array
    {
        $in = AgentOps::requestInput();
        try {
            $id = NotifyRulesRepository::save((int)($in['id'] ?? 0), $in);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
        return ['ok' => true, 'id' => $id, 'rule' => NotifyRulesRepository::find($id), 'http' => 200];
    }

    /** @return array<string,mixed> */
    public static function rulesDelete(): array
    {
        $in = AgentOps::requestInput();
        $ok = NotifyRulesRepository::delete((int)($in['id'] ?? 0));
        return ['ok' => $ok, 'error' => $ok ? '' : 'rule not found', 'http' => $ok ? 200 : 404];
    }

    /** @return array<string,mixed> */
    public static function rulesTest(): array
    {
        $in = AgentOps::requestInput();
        $rule = NotifyRulesRepository::find((int)($in['id'] ?? 0));
        if ($rule === null) {
            return ['ok' => false, 'error' => 'rule not found', 'http' => 404];
        }
        $ctx = $in['ctx'] ?? [];
        if (is_string($ctx)) {
            $ctx = json_decode($ctx, true);
        }
        $def = NotifyCatalog::get($rule['event']) ?? [];
        $ctx = is_array($ctx) && $ctx !== [] ? $ctx : NotifyEngine::sampleCtx($def);
        $subjects = [];
        if ((int)($in['client_id'] ?? 0) > 0) {
            $subjects['client'] = ['client_id' => (int)$in['client_id']];
        }
        if ((int)($in['user_id'] ?? 0) > 0) {
            $subjects['operator'] = ['user_id' => (int)$in['user_id']];
        }
        $res = NotifyEngine::testRule($rule, ['ctx' => $ctx, 'subjects' => $subjects, 'entity_id' => 0]);
        return ['ok' => true, 'dry_run' => true] + $res + ['http' => 200];
    }

    /** @return array<string,mixed> */
    public static function settings(): array
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            try {
                NotifyConfig::saveFromPost(AgentOps::requestInput());
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
            }
        }
        return ['ok' => true, 'settings' => NotifyConfig::formValues(), 'http' => 200];
    }

    /** @return array<string,mixed> */
    public static function chats(): array
    {
        if (!class_exists('BotChatsRepository', false)) {
            return ['ok' => true, 'items' => [], 'total' => 0, 'note' => 'bots disabled', 'http' => 200];
        }
        $in = AgentOps::requestInput();
        $r = BotChatsRepository::list(['q' => (string)($in['q'] ?? ''), 'enabled' => 1, 'limit' => 200]);
        return ['ok' => true, 'items' => $r['items'], 'total' => $r['total'], 'http' => 200];
    }

    /** @return array<string,mixed> */
    public static function journal(): array
    {
        $in = AgentOps::requestInput();
        $limit = min(200, max(1, (int)($in['limit'] ?? 50)));
        $page = max(1, (int)($in['page'] ?? 1));
        $r = NotifyDeliveriesRepository::list([
            'client_id' => (int)($in['client_id'] ?? 0),
            'scope' => (string)($in['scope'] ?? ''),
            'rule_id' => (int)($in['rule_id'] ?? 0),
            'event' => (string)($in['event'] ?? ''),
            'status' => (string)($in['status'] ?? ''),
            'entity' => (string)($in['entity'] ?? ''),
            'q' => (string)($in['q'] ?? ''),
            'limit' => $limit,
            'offset' => ($page - 1) * $limit,
        ]);
        return ['ok' => true, 'total' => $r['total'], 'page' => $page, 'per_page' => $limit, 'items' => $r['items'], 'http' => 200];
    }

    /** @return array<string,mixed> */
    public static function tasks(): array
    {
        $in = AgentOps::requestInput();
        $limit = min(200, max(1, (int)($in['limit'] ?? 50)));
        $page = max(1, (int)($in['page'] ?? 1));
        $r = NotifyTasksRepository::list([
            'status' => (string)($in['status'] ?? ''),
            'client_id' => (int)($in['client_id'] ?? 0),
            'entity' => (string)($in['entity'] ?? ''),
            'entity_id' => (int)($in['entity_id'] ?? 0),
            'limit' => $limit,
            'offset' => ($page - 1) * $limit,
        ]);
        return ['ok' => true, 'total' => $r['total'], 'page' => $page, 'per_page' => $limit, 'items' => $r['items'], 'http' => 200];
    }

    /** @return array<string,mixed> */
    public static function tasksResolve(): array
    {
        $in = AgentOps::requestInput();
        $res = NotifyEngine::resolveTask((int)($in['task_id'] ?? 0), (string)($in['outcome'] ?? ''), 0, 'agent:' . trim((string)($in['comment'] ?? '')));
        if ($res['ok'] && $res['task'] !== null) {
            NotifyInbound::closeTaskMessage($res['task'], (string)$in['outcome']);
        }
        return ['ok' => $res['ok'], 'error' => $res['error'], 'task' => $res['task'], 'http' => $res['ok'] ? 200 : 400];
    }

    /** @return array<string,mixed> */
    public static function send(): array
    {
        $res = NotifySend::send(AgentOps::requestInput());
        return $res + ['http' => $res['ok'] ? 200 : 400];
    }

    /** @return array<string,mixed> */
    public static function presetsList(): array
    {
        return ['ok' => true, 'items' => NotifyPresets::catalog(), 'http' => 200];
    }

    /** @return array<string,mixed> */
    public static function presetsApply(): array
    {
        $in = AgentOps::requestInput();
        $pack = trim((string)($in['pack'] ?? ''));
        $opts = [
            'chat_id' => trim((string)($in['chat_id'] ?? '')),
            'platform' => trim((string)($in['platform'] ?? '')),
            'staff_ids' => $in['staff_ids'] ?? '',
            'bot_chat_id' => (int)($in['bot_chat_id'] ?? 0),
            'enabled' => !isset($in['enabled']) || !empty($in['enabled']),
        ];
        if ($pack === 'all_satisfied') {
            $r = NotifyPresets::applySatisfied($opts);
            return $r + ['http' => $r['ok'] ? 200 : 400];
        }
        $r = NotifyPresets::apply($pack, $opts);
        return $r + ['http' => $r['ok'] ? 200 : 400];
    }
}
