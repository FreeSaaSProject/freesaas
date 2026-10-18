<?php
declare(strict_types=1);

/**
 * LLM tool-calling loop: read tools run immediately; write tools become pending confirmations.
 */
final class AssistantHarness
{
    /** LLM rounds; ledger create often needs several list lookups + one write. */
    public const MAX_ITERATIONS = 12;

    /** Extra LLM turns when the final reply invents admin URLs outside the tool catalog. */
    public const MAX_LINK_REPAIRS = 1;

    /**
     * @param list<array{role:string,content?:string,tool_calls?:mixed,tool_call_id?:string,name?:string}> $messages
     * @param array{
     *   profile?:string,timeout?:int,temperature?:float,max_tokens?:int,user_id?:int,
     *   ops?:list<array<string,mixed>>,
     *   full_ops?:list<array<string,mixed>>,
     *   page_context?:array<string,mixed>,
     *   tool_stubs?:array<string,callable|array>,
     *   on_progress?:callable,
     *   group_mode?:bool,
     *   group_chat_id?:string,
     *   group_platform?:string,
     *   confirm_mode?:string,
     *   system_extra?:string
     * } $opts on_progress receives {phase,op_id,description,side_effect,args,status?};
     *          ops = prebuilt tool catalog (eval personas / page-chat); does not touch AssistantDialog.
     *          full_ops = full user catalog for meta unlock (page-chat); enables assistant.tools.* meta.
     *          page_context = compact screen JSON appended to the system prompt.
     *          tool_stubs = op_id → payload array or (opId, args) => payload (eval fixtures).
     *          group_mode + confirm_mode=capability → pending confirmable by any staff with op permission.
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

        $ops = isset($opts['ops']) && is_array($opts['ops'])
            ? array_values($opts['ops'])
            : AssistantTools::availableOps();
        if ($ops === []) {
            return self::fail(t('assistant.err.tools'));
        }
        $fullOps = isset($opts['full_ops']) && is_array($opts['full_ops'])
            ? array_values($opts['full_ops'])
            : [];
        $productOpsCount = static function (array $list): int {
            $n = 0;
            foreach ($list as $op) {
                if (is_array($op) && empty($op['meta'])) {
                    $n++;
                }
            }
            return $n;
        };
        $catalogEnabled = $fullOps !== []
            && $productOpsCount($ops) < $productOpsCount($fullOps);
        if ($catalogEnabled) {
            $ops = AssistantTools::withMetaCatalog($ops);
        }
        $byFn = AssistantTools::indexByFunctionName($ops);
        $tools = AssistantTools::toLlmTools($ops);
        $onProgress = isset($opts['on_progress']) && is_callable($opts['on_progress'])
            ? $opts['on_progress']
            : null;
        $toolStubs = isset($opts['tool_stubs']) && is_array($opts['tool_stubs'])
            ? $opts['tool_stubs']
            : [];

        $system = self::buildSystemPrompt($ops);
        if (isset($opts['page_context']) && is_array($opts['page_context']) && $opts['page_context'] !== []
            && class_exists('AssistantPageContext', false)
        ) {
            $system .= AssistantPageContext::promptBlock($opts['page_context']);
        }
        if ($catalogEnabled) {
            $hint = t('assistant.system_prompt.tool_catalog');
            if ($hint !== '' && $hint !== 'assistant.system_prompt.tool_catalog') {
                $system .= "\n" . $hint;
            }
        }
        $systemExtra = trim((string)($opts['system_extra'] ?? ''));
        if ($systemExtra !== '') {
            $system .= "\n" . $systemExtra;
        }
        $groupMode = !empty($opts['group_mode']);
        $confirmMode = strtolower(trim((string)($opts['confirm_mode'] ?? ($groupMode ? 'capability' : 'self'))));
        if ($confirmMode !== 'capability') {
            $confirmMode = 'self';
        }
        $groupChatId = trim((string)($opts['group_chat_id'] ?? ''));
        $groupPlatform = trim((string)($opts['group_platform'] ?? 'max'));
        $useProvidedOps = isset($opts['ops']) && is_array($opts['ops']);

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
        $linkRepairs = 0;
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
                    'error' => $res['error'] !== '' ? $res['error'] : AssistantConfig::t('assistant.err.config'),
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
                if ($text !== ''
                    && $linkRepairs < self::MAX_LINK_REPAIRS
                    && class_exists('AssistantLinks', false)
                ) {
                    $bad = AssistantLinks::findUnauthorizedHrefs($text, $ops);
                    if ($bad !== []) {
                        $allowed = AssistantLinks::allowedLinkHints($ops, $norm);
                        $norm[] = ['role' => 'assistant', 'content' => $text];
                        $norm[] = [
                            'role' => 'user',
                            'content' => t('assistant.harness.link_repair', [
                                'bad' => implode(', ', $bad),
                                'allowed' => $allowed !== []
                                    ? implode("\n", $allowed)
                                    : t('assistant.harness.link_repair_none'),
                            ]),
                        ];
                        $trace[] = [
                            'op_id' => '_link_repair',
                            'label' => 'link_repair',
                            'status' => 'ok',
                            'args' => ['bad' => $bad],
                        ];
                        self::emitProgress($onProgress, [
                            'phase' => 'link_repair',
                            'op_id' => '_link_repair',
                            'description' => 'link_repair',
                            'side_effect' => 'read',
                            'args' => ['bad' => $bad],
                            'status' => 'ok',
                        ]);
                        $linkRepairs++;
                        continue;
                    }
                }
                return self::okResult($text, $norm, $pending, $trace, $profile, $ops);
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
                        'content' => self::encodeToolContent($payload),
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

                if (AssistantTools::isMetaOpId($opId) && $catalogEnabled) {
                    $meta = AssistantTools::invokeMeta($opId, $args, $ops, $fullOps);
                    $payload = is_array($meta)
                        ? [
                            'ok' => !empty($meta['ok']),
                            'error' => (string)($meta['error'] ?? ''),
                            'data' => $meta['data'] ?? null,
                        ]
                        : ['ok' => false, 'error' => 'Meta tool failed'];
                    if (!empty($meta['ok']) && $opId === 'assistant.tools.unlock') {
                        $byFn = AssistantTools::indexByFunctionName($ops);
                        $tools = AssistantTools::toLlmTools($ops);
                    }
                    $trace[] = [
                        'op_id' => $opId,
                        'label' => self::humanOpTitle($opId),
                        'status' => !empty($payload['ok']) ? 'ok' : 'error',
                        'args' => $args,
                        'meta' => true,
                    ];
                    self::emitProgress($onProgress, [
                        'phase' => 'done',
                        'op_id' => $opId,
                        'description' => $desc,
                        'side_effect' => 'read',
                        'args' => $args,
                        'status' => !empty($payload['ok']) ? 'ok' : 'error',
                    ]);
                    $norm[] = [
                        'role' => 'tool',
                        'tool_call_id' => $tcId,
                        'content' => self::encodeToolContent($payload),
                    ];
                    continue;
                }

                $stubPayload = self::resolveToolStub($toolStubs, $opId, $args);
                if ($stubPayload !== null) {
                    if ($side === 'write' && !empty($stubPayload['pending'])) {
                        $token = (string)($stubPayload['token'] ?? ('eval-stub-' . substr(sha1($opId), 0, 8)));
                        $preview = self::writePreview($opId, $args, $desc);
                        if (isset($stubPayload['summary']) && is_string($stubPayload['summary']) && trim($stubPayload['summary']) !== '') {
                            $preview['summary'] = trim($stubPayload['summary']);
                        }
                        $pending[] = self::pendingActionPayload($token, $opId, $preview, time() + 900);
                        $stubPayload['token'] = $token;
                        $stubPayload['summary'] = $preview['summary'];
                        $stubPayload['headline'] = $preview['headline'];
                        $stubPayload['lines'] = $preview['lines'];
                        $trace[] = [
                            'op_id' => $opId,
                            'label' => self::humanOpTitle($opId),
                            'status' => 'pending',
                            'args' => $args,
                            'token' => $token,
                            'fixture' => true,
                        ];
                        self::emitProgress($onProgress, [
                            'phase' => 'done',
                            'op_id' => $opId,
                            'description' => $desc,
                            'side_effect' => $side,
                            'args' => $args,
                            'status' => 'pending',
                            'summary' => $preview['summary'],
                        ]);
                    } else {
                        $ok = !isset($stubPayload['ok']) || !empty($stubPayload['ok']);
                        $trace[] = [
                            'op_id' => $opId,
                            'label' => self::humanOpTitle($opId),
                            'status' => $ok ? 'ok' : 'error',
                            'args' => $args,
                            'fixture' => true,
                        ];
                        self::emitProgress($onProgress, [
                            'phase' => 'done',
                            'op_id' => $opId,
                            'description' => $desc,
                            'side_effect' => $side,
                            'args' => $args,
                            'status' => $ok ? 'ok' : 'error',
                            'error' => $ok ? '' : (string)($stubPayload['error'] ?? 'stub error'),
                        ]);
                    }
                    $norm[] = [
                        'role' => 'tool',
                        'tool_call_id' => $tcId,
                        'content' => self::encodeToolContent($stubPayload),
                    ];
                    continue;
                }

                if ($side === 'write') {
                    $preview = self::writePreview($opId, $args, $desc);
                    $reqPerm = (string)($op['permission'] ?? '');
                    $row = PendingActionsRepository::create($userId, $opId, $args, $preview['summary'], [
                        'confirm_mode' => $confirmMode,
                        'required_permission' => $reqPerm,
                        'group_chat_id' => $groupChatId,
                        'group_platform' => $groupPlatform,
                    ]);
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
                        $pendingItem = self::pendingActionPayload(
                            (string)$row['token'],
                            $opId,
                            $preview,
                            (int)$row['expires_at']
                        );
                        $pendingItem['required_permission'] = $reqPerm;
                        $pendingItem['confirm_mode'] = $confirmMode;
                        $pending[] = $pendingItem;
                        $payload = [
                            'ok' => true,
                            'pending' => true,
                            'token' => $row['token'],
                            'message' => 'Write queued. UI shows Confirm/Cancel buttons. Reply with ONE short sentence; do not invent Confirm text; do not claim it ran.',
                            'summary' => $preview['summary'],
                            'headline' => $preview['headline'],
                            'lines' => $preview['lines'],
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
                            'summary' => $preview['summary'],
                        ]);
                    }
                    $norm[] = [
                        'role' => 'tool',
                        'tool_call_id' => $tcId,
                        'content' => self::encodeToolContent($payload),
                    ];
                    continue;
                }

                $inv = $useProvidedOps
                    ? AssistantTools::invokeFromOps($opId, $args, $ops)
                    : AssistantTools::invoke($opId, $args);
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
                $norm[] = [
                    'role' => 'tool',
                    'tool_call_id' => $tcId,
                    'content' => self::encodeToolContent($payload),
                ];
            }

            // Write already queued — show Confirm buttons now; skip extra LLM rounds.
            if ($pending !== []) {
                return self::okResult(
                    $text !== '' ? $text : t('assistant.confirm.press_buttons'),
                    $norm,
                    $pending,
                    $trace,
                    $profile,
                    $ops
                );
            }
        }

        if ($pending !== []) {
            return self::okResult(t('assistant.confirm.press_buttons'), $norm, $pending, $trace, $profile, $ops);
        }

        return [
            'ok' => false,
            'text' => '',
            'messages' => $norm,
            'pending_actions' => $pending,
            'tool_trace' => $trace,
            'error' => AssistantConfig::t('assistant.err.loop'),
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
     * Compact args for progress traces — no module field names; picks universal keys then any scalars.
     *
     * @param array<string,mixed> $args
     */
    public static function formatArgsBrief(array $args): string
    {
        $bits = [];
        foreach (self::pickScalarArgs($args, 5) as $pair) {
            $v = $pair['value'];
            if ((function_exists('mb_strlen') ? mb_strlen($v) : strlen($v)) > 48) {
                $v = (function_exists('mb_substr') ? mb_substr($v, 0, 48) : substr($v, 0, 48)) . '…';
            }
            $bits[] = self::formatArgBit($pair['key'], $v, true);
        }
        return $bits !== [] ? implode(' · ', $bits) : '';
    }

    /**
     * @param array{summary:string,headline:string,lines:list<string>,args:array<string,mixed>} $preview
     * @return array<string,mixed>
     */
    private static function pendingActionPayload(string $token, string $opId, array $preview, int $expiresAt): array
    {
        return [
            'token' => $token,
            'op_id' => $opId,
            'summary' => (string)($preview['summary'] ?? ''),
            'headline' => (string)($preview['headline'] ?? ''),
            'lines' => is_array($preview['lines'] ?? null) ? array_values($preview['lines']) : [],
            'args' => is_array($preview['args'] ?? null) ? $preview['args'] : [],
            'expires_at' => $expiresAt,
        ];
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
                'error' => AssistantConfig::t('assistant.confirm.none'),
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
                'error' => AssistantConfig::t('assistant.confirm.none'),
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
    /**
     * @return array{
     *   ok:bool,action:string,result:?array,error:string,
     *   required_permission?:string,forbidden_capability?:bool
     * }
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
        $confirmMode = strtolower(trim((string)($row['CONFIRM_MODE'] ?? 'self')));
        if ($confirmMode !== 'capability') {
            $confirmMode = 'self';
        }
        $reqPerm = trim((string)($row['REQUIRED_PERMISSION'] ?? ''));
        if ($confirmMode === 'self') {
            if ((int)($row['ID_USER'] ?? 0) !== $userId) {
                return ['ok' => false, 'action' => $action, 'result' => null, 'error' => t('assistant.confirm.forbidden')];
            }
        } else {
            // capability: any linked staff may cancel; confirm requires Auth::can(required_permission).
            if ($action === 'confirm') {
                if ($reqPerm === '') {
                    // Fall back to op permission from catalog.
                    foreach (AgentHttp::allOps() as $op) {
                        if ((string)($op['id'] ?? '') === (string)($row['OP_ID'] ?? '')) {
                            $reqPerm = (string)($op['permission'] ?? '');
                            break;
                        }
                    }
                }
                if ($reqPerm !== '' && !Auth::can($reqPerm)) {
                    return [
                        'ok' => false,
                        'action' => $action,
                        'result' => null,
                        'error' => t('assistant_groups.bot.no_permission', ['perm' => $reqPerm]),
                        'required_permission' => $reqPerm,
                        'forbidden_capability' => true,
                    ];
                }
            }
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
        // Execute as the confirmer (already Auth::runAs in bot/UI callers).
        $inv = AssistantTools::invoke($opId, $args);
        if (!$inv['ok'] && $confirmMode === 'capability') {
            // Op may be missing from confirmer catalog edge-case: dispatch raw meta from AgentHttp.
            $opMeta = null;
            foreach (AgentHttp::allOps() as $op) {
                if ((string)($op['id'] ?? '') === $opId && !empty($op['assistant'])) {
                    $side = (string)($op['side_effect'] ?? 'write');
                    $opMeta = [
                        'id' => $opId,
                        'method' => (string)$op['method'],
                        'path' => (string)$op['path'],
                        'permission' => (string)($op['permission'] ?? ''),
                        'description' => (string)($op['description'] ?? $opId),
                        'side_effect' => $side,
                        'parameters' => [],
                        'function_name' => AssistantTools::opIdToFunctionName($opId),
                        'module' => (string)($op['module'] ?? ''),
                    ];
                    break;
                }
            }
            if ($opMeta !== null) {
                $inv = AssistantTools::invokeOpMeta($opMeta, $args);
            }
        }
        if (!$inv['ok']) {
            PendingActionsRepository::mark($token, 'failed', ['error' => $inv['error']]);
            return ['ok' => false, 'action' => 'confirm', 'result' => null, 'error' => $inv['error']];
        }
        PendingActionsRepository::markConfirmedBy($token, $userId, $inv['data']);
        return [
            'ok' => true,
            'action' => 'confirm',
            'result' => $inv['data'],
            'error' => '',
        ];
    }

    /**
     * Eval fixture stub: op_id → array payload or callable(opId, args): array.
     *
     * @param array<string,callable|array<string,mixed>> $stubs
     * @param array<string,mixed> $args
     * @return array<string,mixed>|null
     */
    private static function resolveToolStub(array $stubs, string $opId, array $args): ?array
    {
        if ($opId === '' || !array_key_exists($opId, $stubs)) {
            return null;
        }
        $stub = $stubs[$opId];
        if (is_callable($stub)) {
            $out = $stub($opId, $args);
            return is_array($out) ? $out : null;
        }
        return is_array($stub) ? $stub : null;
    }

    /**
     * Human draft for Confirm UI / bot: title + who/what will change (not just #id).
     * Domain detail comes from EventBus `assistant.write.preview` (owning modules append lines).
     *
     * @param array<string,mixed> $args
     * @return array{summary:string,headline:string,lines:list<string>,args:array<string,mixed>}
     */
    public static function writePreview(string $opId, array $args, string $description = ''): array
    {
        $title = self::humanOpTitle($opId);
        $headline = $title;
        $id = (int)($args['id'] ?? 0);
        if ($id > 0) {
            $headline .= ' · #' . $id;
        }

        $payload = [
            'op_id' => $opId,
            'args' => $args,
            'description' => $description,
            'headline' => $headline,
            'lines' => [],
            'handled' => false,
        ];
        if (class_exists('EventBus', false)) {
            $payload = EventBus::emit('assistant.write.preview', $payload);
            if (!is_array($payload)) {
                $payload = [];
            }
        }

        $headline = trim((string)($payload['headline'] ?? $headline));
        if ($headline === '') {
            $headline = $title !== '' ? $title : $opId;
        }

        $lines = [];
        if (is_array($payload['lines'] ?? null)) {
            foreach ($payload['lines'] as $line) {
                $line = trim((string)$line);
                if ($line !== '' && $line !== $headline) {
                    $lines[] = $line;
                }
            }
        }

        if ($lines === [] && empty($payload['handled'])) {
            $lines = self::previewGenericArgs($args);
            if ($lines === []) {
                $brief = self::formatArgsBrief($args);
                if ($brief !== '') {
                    $lines[] = $brief;
                } elseif (trim($description) !== '') {
                    $short = preg_replace('/\s*\[CALL NOW.*$/u', '', $description) ?? $description;
                    $lines[] = function_exists('mb_substr') ? mb_substr(trim($short), 0, 160) : substr(trim($short), 0, 160);
                }
            }
        }

        $parts = [$headline];
        foreach ($lines as $line) {
            $parts[] = $line;
        }
        $summary = implode("\n", $parts);
        if (function_exists('mb_substr')) {
            $summary = mb_substr($summary, 0, 4000);
        } else {
            $summary = substr($summary, 0, 4000);
        }
        return [
            'summary' => $summary,
            'headline' => $headline,
            'lines' => $lines,
            'args' => self::publicArgs($args),
        ];
    }

    /**
     * @param array<string,mixed> $args
     */
    private static function summarizeWrite(string $opId, array $args, string $description): string
    {
        return self::writePreview($opId, $args, $description)['summary'];
    }

    /**
     * Fallback when no module handled assistant.write.preview — raw scalar args only.
     *
     * @param array<string,mixed> $args
     * @return list<string>
     */
    private static function previewGenericArgs(array $args): array
    {
        $lines = [];
        foreach (self::pickScalarArgs($args, 8) as $pair) {
            $lines[] = self::formatArgBit($pair['key'], $pair['value'], true);
        }
        return $lines;
    }

    /**
     * Universal keys first, then remaining scalar args in input order (no product-field allowlists).
     *
     * @param array<string,mixed> $args
     * @return list<array{key:string,value:string}>
     */
    private static function pickScalarArgs(array $args, int $limit): array
    {
        if ($args === [] || $limit < 1) {
            return [];
        }
        $priority = [
            'id', 'q', 'name', 'date', 'dt', 'day', 'date_from', 'date_to',
            'year', 'month', 'descr', 'comment', 'status', 'action',
        ];
        $skip = [
            'format' => true, '_csrf' => true, 'csrf' => true,
            'token' => true, 'password' => true, 'passwd' => true,
        ];
        $dateKeys = [
            'date' => true, 'dt' => true, 'day' => true,
            'date_from' => true, 'date_to' => true,
        ];
        $out = [];
        $seen = [];
        $push = static function (string $k, $raw) use (&$out, &$seen, $limit, $dateKeys): void {
            if (isset($seen[$k]) || count($out) >= $limit) {
                return;
            }
            if ($raw === '' || $raw === null || is_array($raw) || is_object($raw)) {
                return;
            }
            if (!is_scalar($raw)) {
                return;
            }
            $v = (string)$raw;
            if (isset($dateKeys[$k])) {
                $v = self::formatDayValue($v);
            }
            if ($v === '') {
                return;
            }
            $seen[$k] = true;
            $out[] = ['key' => $k, 'value' => $v];
        };

        foreach ($priority as $k) {
            if (array_key_exists($k, $args)) {
                $push($k, $args[$k]);
            }
        }
        foreach ($args as $k => $raw) {
            $k = (string)$k;
            if ($k === '' || isset($skip[$k])) {
                continue;
            }
            $push($k, $raw);
        }
        return $out;
    }

    private static function formatArgBit(string $key, string $value, bool $withKeyFallback): string
    {
        $argKey = 'assistant.tool.arg.' . $key;
        $tpl = t($argKey, ['v' => $value]);
        if ($tpl !== $argKey) {
            return $tpl;
        }
        return $withKeyFallback ? ($key . '=' . $value) : $value;
    }

    /** @param mixed $raw */
    private static function formatDayValue($raw): string
    {
        if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
            $ts = (int)$raw;
            if ($ts > 100000) {
                return date('Y-m-d', $ts);
            }
        }
        $s = trim((string)$raw);
        if ($s === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s, $m)) {
            return $m[0];
        }
        $ts = strtotime($s);
        return $ts ? date('Y-m-d', $ts) : $s;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function publicArgs(array $args): array
    {
        $out = [];
        foreach ($args as $k => $v) {
            $key = (string)$k;
            if ($key === '' || $key === 'format' || $key === '_csrf' || $key === 'csrf') {
                continue;
            }
            if (is_scalar($v) || $v === null) {
                $out[$key] = $v;
            } elseif (is_array($v)) {
                $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($json) && strlen($json) <= 400) {
                    $out[$key] = $v;
                }
            }
            if (count($out) >= 24) {
                break;
            }
        }
        return $out;
    }

    /**
     * Aggregate common money/qty fields across list rows (for truncated tool payloads).
     *
     * @param list<mixed> $list
     * @return array<string,float|array<string,float>>
     */
    private static function sumNumericColumns(array $list): array
    {
        $moneyKeys = ['sum' => true, 'summ' => true, 'amount' => true, 'total' => true, 'price' => true, 'value' => true];
        $sums = [];
        $byDirection = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $k => $v) {
                if (!is_numeric($v)) {
                    continue;
                }
                $key = strtolower((string)$k);
                if (!isset($moneyKeys[$key])) {
                    continue;
                }
                if (!isset($sums[$key])) {
                    $sums[$key] = 0.0;
                }
                $sums[$key] += (float)$v;
            }
            if (isset($row['sum']) && is_numeric($row['sum']) && array_key_exists('direction', $row)) {
                $dir = (string)$row['direction'];
                if (!isset($byDirection[$dir])) {
                    $byDirection[$dir] = 0.0;
                }
                $byDirection[$dir] += (float)$row['sum'];
            }
        }
        foreach ($sums as $k => $v) {
            $sums[$k] = round((float)$v, 2);
        }
        if ($byDirection !== []) {
            foreach ($byDirection as $k => $v) {
                $byDirection[$k] = round((float)$v, 2);
            }
            // payments: direction 1 = accrued (начислено), else issued (выплачено)
            $sums['by_direction'] = $byDirection;
            if (isset($byDirection['1'])) {
                $sums['accrued'] = $byDirection['1'];
            }
            $issued = 0.0;
            foreach ($byDirection as $dir => $v) {
                if ((string)$dir === '1') {
                    continue;
                }
                $issued += (float)$v;
            }
            if ($issued > 0 || isset($byDirection['-1']) || isset($byDirection['0'])) {
                $sums['issued'] = round($issued, 2);
            }
        }
        return $sums;
    }

    /**
     * Encode tool result for the next LLM round. Never byte-chop JSON mid-string:
     * that breaks UTF-8 (Cyrillic) and the following LlmClient::chat fails with
     * «JSON encode failed».
     *
     * @param mixed $payload
     */
    private static function encodeToolContent($payload, int $maxBytes = 12000): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $encoded = json_encode($payload, $flags);
        if ($encoded === false) {
            return '{"ok":false,"error":"encode failed"}';
        }
        if (strlen($encoded) <= $maxBytes) {
            return $encoded;
        }

        $compact = [
            'ok' => true,
            '_truncated' => true,
            'bytes' => strlen($encoded),
            'hint' => 'Large result compacted. Answer from totals/months/sum_* — do not say you cannot sum; ask to narrow only for a per-row breakdown.',
        ];
        if (is_array($payload)) {
            if (array_key_exists('ok', $payload)) {
                $compact['ok'] = !empty($payload['ok']);
            }
            if (isset($payload['error'])) {
                $compact['ok'] = false;
                $compact['error'] = is_scalar($payload['error'])
                    ? (string)$payload['error']
                    : 'error';
            }
            if (isset($payload['total'])) {
                $compact['total'] = $payload['total'];
            }
            if (isset($payload['totals']) && is_array($payload['totals'])) {
                $compact['totals'] = $payload['totals'];
            }
            if (isset($payload['filters']) && is_array($payload['filters'])) {
                $compact['filters'] = $payload['filters'];
            }
            if (isset($payload['months']) && is_array($payload['months'])) {
                $monthSummaries = [];
                foreach ($payload['months'] as $month) {
                    if (!is_array($month)) {
                        continue;
                    }
                    $monthSummaries[] = [
                        'key' => $month['key'] ?? null,
                        'total_in' => $month['total_in'] ?? null,
                        'total_out' => $month['total_out'] ?? null,
                        'internal_out' => $month['internal_out'] ?? null,
                        'debt_start' => $month['debt_start'] ?? null,
                        'debt_end' => $month['debt_end'] ?? null,
                        'accrued_count' => isset($month['accrued']) && is_array($month['accrued'])
                            ? count($month['accrued']) : null,
                        'issued_count' => isset($month['issued']) && is_array($month['issued'])
                            ? count($month['issued']) : null,
                    ];
                }
                if ($monthSummaries !== []) {
                    $compact['months'] = $monthSummaries;
                }
            }
            foreach (['items', 'rows', 'list', 'data'] as $key) {
                if (!isset($payload[$key]) || !is_array($payload[$key])) {
                    continue;
                }
                $list = array_values($payload[$key]);
                $compact[$key . '_count'] = count($list);
                $sums = self::sumNumericColumns($list);
                if ($sums !== []) {
                    $compact['sum_' . $key] = $sums;
                }
                // Tiny sample only — totals above are the source of truth.
                $compact[$key . '_sample'] = array_slice($list, 0, 3);
                break;
            }
            $meta = [];
            $n = 0;
            $skip = [
                'ok' => true, 'error' => true, 'total' => true, 'totals' => true,
                'items' => true, 'rows' => true, 'list' => true, 'data' => true,
                'months' => true, 'filters' => true, 'dps_by_payment' => true,
            ];
            foreach ($payload as $k => $v) {
                if ($n >= 16) {
                    break;
                }
                if (isset($skip[$k])) {
                    continue;
                }
                if (is_scalar($v) || $v === null) {
                    $meta[$k] = $v;
                    $n++;
                }
            }
            if ($meta !== []) {
                $compact['meta'] = $meta;
            }
        }

        $out = json_encode($compact, $flags);
        if ($out === false || strlen($out) > $maxBytes) {
            return '{"ok":true,"_truncated":true,"bytes":' . strlen($encoded)
                . ',"hint":"Result too large; narrow filters."}';
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $messages
     * @param list<array<string,mixed>> $pending
     * @param list<array<string,mixed>> $trace
     * @return array{ok:bool,text:string,messages:list,pending_actions:list,tool_trace:list,error:string,profile:?string}
     */
    /**
     * @param list<array<string,mixed>> $ops
     */
    private static function okResult(
        string $text,
        array $messages,
        array $pending,
        array $trace,
        ?string $profile,
        array $ops = []
    ): array {
        if ($text !== '' && class_exists('AssistantLinks', false)) {
            $text = AssistantLinks::absolutize($text);
            $text = AssistantLinks::filterUnauthorized($text, $ops);
        }
        return [
            'ok' => true,
            'text' => $text,
            'messages' => $messages,
            'pending_actions' => $pending,
            'tool_trace' => $trace,
            'error' => '',
            'profile' => $profile,
        ];
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

    /**
     * Full system message for a tool catalog (locale via t()). Public for eval UI.
     *
     * @param list<array<string,mixed>> $ops
     */
    public static function buildSystemPrompt(array $ops): string
    {
        $company = class_exists('SystemRepository', false)
            ? trim(SystemRepository::companyName())
            : '';
        $companyPart = $company !== ''
            ? t('assistant.system_prompt.company_part', ['company' => $company])
            : '';
        $name = class_exists('AssistantConfig', false)
            ? AssistantConfig::displayName()
            : 'Макс';
        $system = t('assistant.system_prompt', [
            'name' => $name,
            'company_part' => $companyPart,
        ]);
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day') ?: time());
        $tomorrow = date('Y-m-d', strtotime('+1 day') ?: time());
        $system .= "\nToday is {$today}. Yesterday is {$yesterday}. Tomorrow is {$tomorrow}.";
        $system .= "\nCall several read tools in one step when useful. After a write is queued for confirmation, stop — do not keep searching.";
        $system .= "\n" . t('assistant.system_prompt.totals');
        $system .= self::scopedHintsForOps($ops);
        if (class_exists('AssistantLinks', false)) {
            $base = AssistantLinks::base();
            if ($base !== '') {
                $hints = AssistantLinks::botCabinetHints();
                $botMenu = $hints !== []
                    ? t('assistant.system_prompt.bot_menu', ['items' => implode('; ', $hints)])
                    : t('assistant.system_prompt.bot_menu_none');
                $system .= "\n" . t('assistant.system_prompt.links', [
                    'base' => $base,
                    'bot_menu' => $botMenu,
                ]);
            }
        }
        return $system;
    }

    /**
     * Module-specific prompt lines only when the matching tool is in THIS catalog.
     * Keeps inaccessible products (finance, BI, …) out of the system message.
     * Public for AssistantEval deterministic checks.
     *
     * @param list<array<string,mixed>> $ops
     */
    public static function scopedHintsForOps(array $ops): string
    {
        $ids = [];
        foreach ($ops as $op) {
            $id = (string)($op['id'] ?? '');
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        $hasPrefix = static function (string $prefix) use ($ids): bool {
            foreach ($ids as $id => $_true) {
                if ($id === $prefix || strpos($id, $prefix . '.') === 0) {
                    return true;
                }
            }
            return false;
        };

        $lines = [];
        if ($hasPrefix('payments')) {
            $lines[] = t('assistant.system_prompt.hint.fot');
        }
        if (isset($ids['finance.balance'])) {
            $lines[] = t('assistant.system_prompt.hint.finance');
        } else {
            // No path literals here — det checks forbid /finance in hints without the tool.
            $lines[] = t('assistant.system_prompt.hint.no_finance');
        }
        if (isset($ids['bi.families.list']) || isset($ids['bi.report.run'])) {
            $lines[] = t('assistant.system_prompt.hint.bi');
            if (!$hasPrefix('bi.share')) {
                $lines[] = t('assistant.system_prompt.hint.bi_no_shares');
            }
        }
        if ($hasPrefix('wellness')) {
            $lines[] = t('assistant.system_prompt.hint.wellness');
        }
        if (isset($ids['workweeks.day']) || isset($ids['workweeks.list'])) {
            $lines[] = t('assistant.system_prompt.hint.workweeks');
        }
        if ($lines === []) {
            return '';
        }
        return "\n" . implode("\n", $lines);
    }
}
