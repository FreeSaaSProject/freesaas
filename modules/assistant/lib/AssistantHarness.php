<?php
declare(strict_types=1);

/**
 * LLM tool-calling loop: read tools run immediately; write tools become pending confirmations.
 */
final class AssistantHarness
{
    /** LLM rounds; ledger create often needs several list lookups + one write. */
    public const MAX_ITERATIONS = 12;

    /**
     * @param list<array{role:string,content?:string,tool_calls?:mixed,tool_call_id?:string,name?:string}> $messages
     * @param array{
     *   profile?:string,timeout?:int,temperature?:float,max_tokens?:int,user_id?:int,
     *   on_progress?:callable
     * } $opts on_progress receives {phase,op_id,description,side_effect,args,status?}
     * @return array{
     *   ok:bool,text:string,messages:list,pending_actions:list,
     *   tool_trace:list,error:string,profile:?string
     * }
     */
    public static function run(array $messages, array $opts = []): array
    {
        $userId = isset($opts['user_id']) ? (int)$opts['user_id'] : Auth::userId();
        if ($userId < 1) {
            return self::fail('Not authenticated');
        }

        $ops = AssistantTools::availableOps();
        if ($ops === []) {
            return self::fail(t('assistant.err.tools'));
        }
        $byFn = AssistantTools::indexByFunctionName($ops);
        $tools = AssistantTools::toLlmTools($ops);
        $onProgress = isset($opts['on_progress']) && is_callable($opts['on_progress'])
            ? $opts['on_progress']
            : null;

        $company = class_exists('SystemRepository', false)
            ? trim(SystemRepository::companyName())
            : '';
        $companyPart = $company !== ''
            ? t('assistant.system_prompt.company_part', ['company' => $company])
            : '';
        $system = t('assistant.system_prompt', ['company_part' => $companyPart]);
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day') ?: time());
        $system .= "\nToday is {$today}. Yesterday is {$yesterday}.";
        $system .= "\nCall several read tools in one step when useful. After a write is queued for confirmation, stop — do not keep searching.";

        $norm = [];
        $norm[] = ['role' => 'system', 'content' => $system];
        foreach ($messages as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = trim((string)($m['role'] ?? ''));
            if ($role === 'system') {
                continue;
            }
            if ($role === 'tool') {
                $norm[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string)($m['tool_call_id'] ?? ''),
                    'content' => (string)($m['content'] ?? ''),
                ];
                continue;
            }
            if ($role === 'assistant' && isset($m['tool_calls'])) {
                $norm[] = [
                    'role' => 'assistant',
                    'content' => (string)($m['content'] ?? ''),
                    'tool_calls' => $m['tool_calls'],
                ];
                continue;
            }
            if ($role === '' || $role === 'tool') {
                continue;
            }
            $content = (string)($m['content'] ?? '');
            if ($content === '' && $role === 'user') {
                continue;
            }
            $norm[] = ['role' => $role, 'content' => $content];
        }
        if (count($norm) < 2) {
            return self::fail(t('assistant.chat.empty'));
        }

        $pending = [];
        $trace = [];
        $profile = null;
        $llmOpts = [
            'timeout' => (int)($opts['timeout'] ?? 90),
            'profile' => isset($opts['profile']) ? (string)$opts['profile'] : null,
            'temperature' => isset($opts['temperature']) ? (float)$opts['temperature'] : 0.2,
        ];
        if (isset($opts['max_tokens'])) {
            $llmOpts['max_tokens'] = (int)$opts['max_tokens'];
        }

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $res = LlmClient::chatWithTools($norm, $tools, $llmOpts);
            $profile = $res['profile'];
            if (!$res['ok']) {
                return [
                    'ok' => false,
                    'text' => '',
                    'messages' => $norm,
                    'pending_actions' => $pending,
                    'tool_trace' => $trace,
                    'error' => $res['error'] !== '' ? $res['error'] : t('assistant.err.config'),
                    'profile' => $profile,
                ];
            }

            $msg = $res['message'];
            $toolCalls = is_array($msg['tool_calls'] ?? null) ? $msg['tool_calls'] : [];
            $text = '';
            if (isset($msg['content']) && is_string($msg['content'])) {
                $text = trim($msg['content']);
            } elseif (isset($msg['content']) && is_array($msg['content'])) {
                $text = trim((string)json_encode($msg['content'], JSON_UNESCAPED_UNICODE));
            }

            if ($toolCalls === []) {
                return [
                    'ok' => true,
                    'text' => $text,
                    'messages' => $norm,
                    'pending_actions' => $pending,
                    'tool_trace' => $trace,
                    'error' => '',
                    'profile' => $profile,
                ];
            }

            $assistantMsg = [
                'role' => 'assistant',
                'content' => $msg['content'] ?? null,
                'tool_calls' => $toolCalls,
            ];
            $norm[] = $assistantMsg;

            foreach ($toolCalls as $tc) {
                if (!is_array($tc)) {
                    continue;
                }
                $tcId = (string)($tc['id'] ?? ('call_' . bin2hex(random_bytes(4))));
                $fn = is_array($tc['function'] ?? null) ? $tc['function'] : [];
                $fnName = (string)($fn['name'] ?? '');
                $argsRaw = (string)($fn['arguments'] ?? '{}');
                $args = json_decode($argsRaw, true);
                if (!is_array($args)) {
                    $args = [];
                }

                $op = $byFn[$fnName] ?? null;
                if ($op === null) {
                    $opId = AssistantTools::functionNameToOpId($fnName);
                    $payload = ['ok' => false, 'error' => 'Unknown or forbidden tool: ' . $fnName];
                    $trace[] = [
                        'op_id' => $opId,
                        'label' => self::humanOpTitle($opId),
                        'status' => 'forbidden',
                        'args' => $args,
                    ];
                    self::emitProgress($onProgress, [
                        'phase' => 'done',
                        'op_id' => $opId,
                        'description' => '',
                        'side_effect' => 'read',
                        'args' => $args,
                        'status' => 'forbidden',
                    ]);
                    $norm[] = [
                        'role' => 'tool',
                        'tool_call_id' => $tcId,
                        'content' => (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];
                    continue;
                }

                $opId = (string)$op['id'];
                $side = (string)$op['side_effect'];
                $desc = (string)$op['description'];
                $args = AssistantTools::normalizeArgs($opId, $args);
                self::emitProgress($onProgress, [
                    'phase' => 'start',
                    'op_id' => $opId,
                    'description' => $desc,
                    'side_effect' => $side,
                    'args' => $args,
                ]);

                if ($side === 'write') {
                    $summary = self::summarizeWrite($opId, $args, $desc);
                    $row = PendingActionsRepository::create($userId, $opId, $args, $summary);
                    if ($row === null) {
                        $payload = ['ok' => false, 'error' => 'Could not create pending confirmation'];
                        $trace[] = [
                            'op_id' => $opId,
                            'label' => self::humanOpTitle($opId),
                            'status' => 'error',
                            'args' => $args,
                        ];
                        self::emitProgress($onProgress, [
                            'phase' => 'done',
                            'op_id' => $opId,
                            'description' => $desc,
                            'side_effect' => $side,
                            'args' => $args,
                            'status' => 'error',
                        ]);
                    } else {
                        $pending[] = [
                            'token' => $row['token'],
                            'op_id' => $opId,
                            'summary' => $row['summary'],
                            'expires_at' => $row['expires_at'],
                        ];
                        $payload = [
                            'ok' => true,
                            'pending' => true,
                            'token' => $row['token'],
                            'message' => 'Write queued. UI shows Confirm/Cancel buttons. Reply with ONE short sentence; do not invent Confirm text; do not claim it ran.',
                            'summary' => $row['summary'],
                        ];
                        $trace[] = [
                            'op_id' => $opId,
                            'label' => self::humanOpTitle($opId),
                            'status' => 'pending',
                            'args' => $args,
                            'token' => $row['token'],
                        ];
                        self::emitProgress($onProgress, [
                            'phase' => 'done',
                            'op_id' => $opId,
                            'description' => $desc,
                            'side_effect' => $side,
                            'args' => $args,
                            'status' => 'pending',
                            'summary' => $row['summary'],
                        ]);
                    }
                    $norm[] = [
                        'role' => 'tool',
                        'tool_call_id' => $tcId,
                        'content' => (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];
                    continue;
                }

                $inv = AssistantTools::invoke($opId, $args);
                $payload = $inv['ok']
                    ? ($inv['data'] ?? ['ok' => true])
                    : ['ok' => false, 'error' => $inv['error']];
                $status = $inv['ok'] ? 'ok' : 'error';
                $trace[] = [
                    'op_id' => $opId,
                    'label' => self::humanOpTitle($opId),
                    'status' => $status,
                    'args' => $args,
                ];
                self::emitProgress($onProgress, [
                    'phase' => 'done',
                    'op_id' => $opId,
                    'description' => $desc,
                    'side_effect' => $side,
                    'args' => $args,
                    'status' => $status,
                    'error' => $inv['ok'] ? '' : (string)$inv['error'],
                ]);
                $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($encoded === false) {
                    $encoded = '{"ok":false,"error":"encode failed"}';
                }
                if (strlen($encoded) > 12000) {
                    $encoded = substr($encoded, 0, 11900) . '…","_truncated":true}';
                }
                $norm[] = [
                    'role' => 'tool',
                    'tool_call_id' => $tcId,
                    'content' => $encoded,
                ];
            }

            // Write already queued — show Confirm buttons now; skip extra LLM rounds.
            if ($pending !== []) {
                return [
                    'ok' => true,
                    'text' => $text !== '' ? $text : t('assistant.confirm.press_buttons'),
                    'messages' => $norm,
                    'pending_actions' => $pending,
                    'tool_trace' => $trace,
                    'error' => '',
                    'profile' => $profile,
                ];
            }
        }

        if ($pending !== []) {
            return [
                'ok' => true,
                'text' => t('assistant.confirm.press_buttons'),
                'messages' => $norm,
                'pending_actions' => $pending,
                'tool_trace' => $trace,
                'error' => '',
                'profile' => $profile,
            ];
        }

        return [
            'ok' => false,
            'text' => '',
            'messages' => $norm,
            'pending_actions' => $pending,
            'tool_trace' => $trace,
            'error' => t('assistant.err.loop'),
            'profile' => $profile,
        ];
    }

    /**
     * Human-readable harness progress line (bot / UI), localized & compact.
     *
     * @param array<string,mixed> $ev
     */
    public static function formatProgressEvent(array $ev): string
    {
        $phase = (string)($ev['phase'] ?? '');
        $opId = (string)($ev['op_id'] ?? '');
        if ($opId === '') {
            return '';
        }
        $title = self::humanOpTitle($opId);
        $argsBrief = self::formatArgsBrief(is_array($ev['args'] ?? null) ? $ev['args'] : []);
        $side = (string)($ev['side_effect'] ?? 'read');

        if ($phase === 'start') {
            $icon = $side === 'write' ? '📝' : '🔧';
            $line = $icon . ' ' . $title;
            if ($argsBrief !== '') {
                $line .= ' ' . $argsBrief;
            }
            return $line;
        }

        if ($phase !== 'done') {
            return '';
        }
        $status = (string)($ev['status'] ?? '');
        if ($status === 'pending') {
            $sum = trim((string)($ev['summary'] ?? ''));
            $line = '⏳ ' . $title . ' → ' . t('assistant.tool.pending');
            if ($sum !== '' && $sum !== $title) {
                $line .= ' · ' . $sum;
            }
            return $line;
        }
        if ($status === 'ok') {
            return '✅ ' . $title;
        }
        if ($status === 'forbidden') {
            return '🚫 ' . $title . ' — ' . t('assistant.tool.forbidden');
        }
        $err = trim((string)($ev['error'] ?? ''));
        return '❌ ' . $title . ($err !== '' ? ' — ' . $err : '');
    }

    /** Localized compact title: «Счета · поиск». */
    public static function humanOpTitle(string $opId): string
    {
        $opId = trim($opId);
        if ($opId === '') {
            return '';
        }
        $exactKey = 'assistant.tool.op.' . $opId;
        $exact = t($exactKey);
        if ($exact !== $exactKey) {
            return $exact;
        }
        $parts = explode('.', $opId);
        $mod = (string)($parts[0] ?? '');
        $action = (string)(end($parts) ?: '');
        $area = count($parts) >= 3 ? (string)$parts[1] : '';

        $areaKey = $area !== '' ? 'assistant.tool.area.' . $mod . '.' . $area : '';
        $modKey = 'assistant.tool.mod.' . $mod;
        $place = '';
        if ($areaKey !== '') {
            $place = t($areaKey);
            if ($place === $areaKey) {
                $place = '';
            }
        }
        if ($place === '') {
            $place = t($modKey);
            if ($place === $modKey) {
                $modNameKey = $mod . '.module.name';
                $place = t($modNameKey);
                if ($place === $modNameKey) {
                    $place = $mod !== '' ? $mod : $opId;
                }
            }
        }

        $actKey = 'assistant.tool.act.' . $action;
        $act = t($actKey);
        if ($act === $actKey) {
            $act = $action !== '' ? $action : '';
        }
        if ($place !== '' && $act !== '') {
            return $place . ' · ' . $act;
        }
        return $place !== '' ? $place : $opId;
    }

    /**
     * @param array<string,mixed> $args
     */
    public static function formatArgsBrief(array $args): string
    {
        if ($args === []) {
            return '';
        }
        $prefer = [
            'q', 'summ', 'account_id', 'type_id', 'supplier_id', 'descr',
            'food_id', 'weight', 'id', 'day', 'year', 'month', 'name', 'direction',
        ];
        $bits = [];
        foreach ($prefer as $k) {
            if (!array_key_exists($k, $args) || $args[$k] === '' || $args[$k] === null) {
                continue;
            }
            $v = is_scalar($args[$k]) ? (string)$args[$k] : (string)json_encode($args[$k], JSON_UNESCAPED_UNICODE);
            if ((function_exists('mb_strlen') ? mb_strlen($v) : strlen($v)) > 48) {
                $v = (function_exists('mb_substr') ? mb_substr($v, 0, 48) : substr($v, 0, 48)) . '…';
            }
            $argKey = 'assistant.tool.arg.' . $k;
            $tpl = t($argKey, ['v' => $v]);
            $bits[] = $tpl !== $argKey ? $tpl : $v;
            if (count($bits) >= 5) {
                break;
            }
        }
        return $bits !== [] ? implode(' · ', $bits) : '';
    }

    /** Short success after Confirm — green check, optional id. */
    public static function formatConfirmDone($result): string
    {
        $id = 0;
        if (is_array($result)) {
            $id = (int)($result['id'] ?? 0);
            if ($id < 1 && is_array($result['row'] ?? null)) {
                $id = (int)($result['row']['id'] ?? 0);
            }
        }
        if ($id > 0) {
            return t('assistant.confirm.done_id', ['id' => (string)$id]);
        }
        return t('assistant.confirm.done');
    }

    /** @param callable|null $onProgress @param array<string,mixed> $ev */
    private static function emitProgress($onProgress, array $ev): void
    {
        if ($onProgress === null) {
            return;
        }
        try {
            $onProgress($ev);
        } catch (Throwable $e) {
            error_log('[assistant.progress] ' . $e->getMessage());
        }
    }

    /**
     * Normalize short user text for Confirm/Cancel detection.
     */
    public static function normalizeConfirmPhrase(string $text): string
    {
        $t = trim($text);
        $t = preg_replace('/\*+/u', '', $t) ?? $t;
        $t = trim($t, " \t\n\r\0\x0B\"'«»");
        if ($t === '') {
            return '';
        }
        return function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
    }

    /**
     * Detect Confirm/Cancel typed as plain text (bot/web), not via buttons.
     * Returns null if the message is not a short confirm/cancel phrase.
     */
    public static function detectConfirmIntent(string $text): ?string
    {
        $lower = self::normalizeConfirmPhrase($text);
        if ($lower === '' || (function_exists('mb_strlen') ? mb_strlen($lower) : strlen($lower)) > 40) {
            return null;
        }
        if (preg_match('/^(confirm|подтверд(ить|аю|ждаю)?|ок|ok|готово|делай|оформляй|записывай|согласен)$/iu', $lower)) {
            return 'confirm';
        }
        if (preg_match('/^(cancel|отмена|отменить|отменяю|не надо)$/iu', $lower)) {
            return 'cancel';
        }
        return null;
    }

    /** Soft «do it» phrases — only confirm when a pending write already exists. */
    private static function isSoftDoConfirmPhrase(string $text): bool
    {
        $lower = self::normalizeConfirmPhrase($text);
        return (bool)preg_match('/^(делай|оформляй|записывай|готово|ок|ok|согласен)$/iu', $lower);
    }

    /**
     * If the user typed Confirm/Cancel and has pending writes, resolve the whole batch.
     *
     * @return array{ok:bool,action:string,result:?array,error:string,handled:bool,done?:int,failed?:int}|null
     */
    public static function tryResolveConfirmText(string $text, int $userId): ?array
    {
        $intent = self::detectConfirmIntent($text);
        if ($intent === null || $userId < 1) {
            return null;
        }
        $rows = PendingActionsRepository::listPendingForUser($userId, 20);
        if ($rows === []) {
            // «Делай» without a queued write must reach the LLM so it can call the write tool.
            if ($intent === 'confirm' && self::isSoftDoConfirmPhrase($text)) {
                return null;
            }
            return [
                'ok' => false,
                'action' => $intent,
                'result' => null,
                'error' => t('assistant.confirm.none'),
                'handled' => true,
            ];
        }
        $tokens = [];
        foreach ($rows as $row) {
            $tok = (string)($row['TOKEN'] ?? '');
            if ($tok !== '') {
                $tokens[] = $tok;
            }
        }
        if ($tokens === []) {
            return [
                'ok' => false,
                'action' => $intent,
                'result' => null,
                'error' => t('assistant.confirm.none'),
                'handled' => true,
            ];
        }
        $resolved = self::resolvePendingBatch($tokens, $intent, $userId);
        $resolved['handled'] = true;
        return $resolved;
    }

    /**
     * Confirm/cancel several pending writes in creation order (oldest first).
     *
     * @param list<string> $tokens
     * @return array{ok:bool,action:string,result:?array,error:string,done:int,failed:int,results:list}
     */
    public static function resolvePendingBatch(array $tokens, string $action, int $userId): array
    {
        $action = strtolower(trim($action));
        $tokens = array_values(array_unique(array_filter(array_map('strval', $tokens))));
        if ($tokens === [] || !in_array($action, ['confirm', 'cancel'], true)) {
            return [
                'ok' => false,
                'action' => $action,
                'result' => null,
                'error' => t('assistant.confirm.not_found'),
                'done' => 0,
                'failed' => 0,
                'results' => [],
            ];
        }

        $rows = [];
        foreach ($tokens as $token) {
            $row = PendingActionsRepository::findByToken($token);
            if ($row === null) {
                continue;
            }
            $rows[] = $row;
        }
        usort($rows, static function (array $a, array $b): int {
            $ca = (int)($a['CREATED_AT'] ?? 0);
            $cb = (int)($b['CREATED_AT'] ?? 0);
            if ($ca !== $cb) {
                return $ca <=> $cb;
            }
            return ((int)($a['ID'] ?? 0)) <=> ((int)($b['ID'] ?? 0));
        });

        $done = 0;
        $failed = 0;
        $results = [];
        $lastOk = null;
        $lastError = '';
        foreach ($rows as $row) {
            $token = (string)($row['TOKEN'] ?? '');
            if ($token === '') {
                continue;
            }
            $one = self::resolvePending($token, $action, $userId);
            $results[] = $one;
            if (!empty($one['ok'])) {
                $done++;
                $lastOk = $one['result'];
            } else {
                $failed++;
                $lastError = (string)($one['error'] ?? '');
            }
        }

        if ($done < 1 && $failed < 1) {
            return [
                'ok' => false,
                'action' => $action,
                'result' => null,
                'error' => t('assistant.confirm.not_found'),
                'done' => 0,
                'failed' => 0,
                'results' => $results,
            ];
        }

        return [
            'ok' => $done > 0,
            'action' => $action,
            'result' => $lastOk,
            'error' => $done > 0 ? '' : $lastError,
            'done' => $done,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /** Human reply after single or batch confirm. */
    public static function formatConfirmBatchDone(array $batch): string
    {
        $action = (string)($batch['action'] ?? 'confirm');
        $done = (int)($batch['done'] ?? 0);
        $failed = (int)($batch['failed'] ?? 0);
        if ($action === 'cancel') {
            return $done > 1
                ? t('assistant.confirm.cancelled_n', ['n' => (string)$done])
                : t('assistant.confirm.cancelled');
        }
        if ($done > 1) {
            $text = t('assistant.confirm.done_n', ['n' => (string)$done]);
        } elseif ($done === 1) {
            $text = self::formatConfirmDone($batch['result'] ?? null);
        } else {
            $text = t('assistant.chat.error');
        }
        if ($failed > 0) {
            $text .= "\n" . t('assistant.confirm.failed_n', ['n' => (string)$failed]);
            $err = trim((string)($batch['error'] ?? ''));
            if ($err !== '') {
                $text .= ': ' . $err;
            }
        }
        return $text;
    }

    /**
     * Confirm or cancel a pending write.
     *
     * @return array{ok:bool,action:string,result:?array,error:string}
     */
    public static function resolvePending(string $token, string $action, int $userId): array
    {
        $action = strtolower(trim($action));
        if (!in_array($action, ['confirm', 'cancel'], true)) {
            return ['ok' => false, 'action' => $action, 'result' => null, 'error' => 'action must be confirm|cancel'];
        }
        $row = PendingActionsRepository::findByToken($token);
        if ($row === null) {
            return ['ok' => false, 'action' => $action, 'result' => null, 'error' => t('assistant.confirm.not_found')];
        }
        if ((int)($row['ID_USER'] ?? 0) !== $userId) {
            return ['ok' => false, 'action' => $action, 'result' => null, 'error' => t('assistant.confirm.forbidden')];
        }
        if ((string)($row['STATUS'] ?? '') !== 'pending') {
            return ['ok' => false, 'action' => $action, 'result' => null, 'error' => t('assistant.confirm.not_found')];
        }
        if ((int)($row['EXPIRES_AT'] ?? 0) < time()) {
            PendingActionsRepository::mark($token, 'expired');
            return ['ok' => false, 'action' => $action, 'result' => null, 'error' => t('assistant.confirm.expired')];
        }

        if ($action === 'cancel') {
            PendingActionsRepository::mark($token, 'cancelled');
            return [
                'ok' => true,
                'action' => 'cancel',
                'result' => ['ok' => true, 'cancelled' => true],
                'error' => '',
            ];
        }

        $opId = (string)($row['OP_ID'] ?? '');
        $args = PendingActionsRepository::decodeArgs($row);
        $inv = AssistantTools::invoke($opId, $args);
        if (!$inv['ok']) {
            PendingActionsRepository::mark($token, 'failed', ['error' => $inv['error']]);
            return ['ok' => false, 'action' => 'confirm', 'result' => null, 'error' => $inv['error']];
        }
        PendingActionsRepository::mark($token, 'confirmed', $inv['data']);
        return [
            'ok' => true,
            'action' => 'confirm',
            'result' => $inv['data'],
            'error' => '',
        ];
    }

    /**
     * @param array<string,mixed> $args
     */
    private static function summarizeWrite(string $opId, array $args, string $description): string
    {
        $title = self::humanOpTitle($opId);
        $argsBrief = self::formatArgsBrief($args);
        $joined = $argsBrief !== '' ? $title . ' · ' . $argsBrief : $title;
        return function_exists('mb_substr') ? mb_substr($joined, 0, 500) : substr($joined, 0, 500);
    }

    /**
     * @return array{ok:bool,text:string,messages:list,pending_actions:list,tool_trace:list,error:string,profile:?string}
     */
    private static function fail(string $error): array
    {
        return [
            'ok' => false,
            'text' => '',
            'messages' => [],
            'pending_actions' => [],
            'tool_trace' => [],
            'error' => $error,
            'profile' => null,
        ];
    }
}
