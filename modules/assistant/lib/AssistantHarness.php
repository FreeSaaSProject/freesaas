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
     * @param array{profile?:string,timeout?:int,temperature?:float,max_tokens?:int,user_id?:int} $opts
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
                    $trace[] = ['op_id' => $opId, 'status' => 'forbidden', 'args' => $args];
                    $norm[] = [
                        'role' => 'tool',
                        'tool_call_id' => $tcId,
                        'content' => (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];
                    continue;
                }

                $opId = (string)$op['id'];
                $side = (string)$op['side_effect'];

                if ($side === 'write') {
                    $summary = self::summarizeWrite($opId, $args, (string)$op['description']);
                    $row = PendingActionsRepository::create($userId, $opId, $args, $summary);
                    if ($row === null) {
                        $payload = ['ok' => false, 'error' => 'Could not create pending confirmation'];
                        $trace[] = ['op_id' => $opId, 'status' => 'error', 'args' => $args];
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
                        $trace[] = ['op_id' => $opId, 'status' => 'pending', 'args' => $args, 'token' => $row['token']];
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
                $trace[] = [
                    'op_id' => $opId,
                    'status' => $inv['ok'] ? 'ok' : 'error',
                    'args' => $args,
                ];
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
     * If the user typed Confirm/Cancel and has a pending write, resolve the latest one.
     *
     * @return array{ok:bool,action:string,result:?array,error:string,handled:bool}|null
     */
    public static function tryResolveConfirmText(string $text, int $userId): ?array
    {
        $intent = self::detectConfirmIntent($text);
        if ($intent === null || $userId < 1) {
            return null;
        }
        $rows = PendingActionsRepository::listPendingForUser($userId, 1);
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
        $token = (string)($rows[0]['TOKEN'] ?? '');
        if ($token === '') {
            return [
                'ok' => false,
                'action' => $intent,
                'result' => null,
                'error' => t('assistant.confirm.none'),
                'handled' => true,
            ];
        }
        $resolved = self::resolvePending($token, $intent, $userId);
        $resolved['handled'] = true;
        return $resolved;
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
        $bits = [$opId];
        $keys = ['day', 'year', 'month', 'emp', 'id', 'profile', 'account_id', 'type_id', 'summ', 'supplier_id', 'descr', 'direction'];
        if (strpos($opId, 'ledger.moves') === 0) {
            $keys = ['account_id', 'type_id', 'summ', 'supplier_id', 'descr', 'direction', 'dt'];
        } elseif ($description !== '') {
            $bits[] = $description;
        }
        foreach ($keys as $k) {
            if (!isset($args[$k]) || $args[$k] === '' || $args[$k] === null) {
                continue;
            }
            $v = is_scalar($args[$k]) ? (string)$args[$k] : (string)json_encode($args[$k], JSON_UNESCAPED_UNICODE);
            if ($k === 'descr' && (function_exists('mb_strlen') ? mb_strlen($v) : strlen($v)) > 80) {
                $v = (function_exists('mb_substr') ? mb_substr($v, 0, 80) : substr($v, 0, 80)) . '…';
            }
            $bits[] = $k . '=' . $v;
        }
        if (count($bits) === 1 && $description !== '') {
            $bits[] = $description;
        }
        $joined = implode(' · ', $bits);
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
