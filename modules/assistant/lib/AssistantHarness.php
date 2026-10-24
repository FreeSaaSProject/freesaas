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

        $groupMode = !empty($opts['group_mode']);
        $system = self::buildSystemPrompt($ops, $groupMode);
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
        $confirmMode = strtolower(trim((string)($opts['confirm_mode'] ?? ($groupMode ? 'capability' : 'self'))));
        if ($confirmMode !== 'capability') {
            $confirmMode = 'self';
        }
        $groupChatId = trim((string)($opts['group_chat_id'] ?? ''));
        $groupPlatform = trim((string)($opts['group_platform'] ?? 'max'));
        $useProvidedOps = isset($opts['ops']) && is_array($opts['ops']);
        $readUnrestricted = !empty($opts['read_unrestricted']);
        $invokeRead = static function (string $readOpId, array $readArgs) use ($useProvidedOps, &$ops, $readUnrestricted): array {
            $call = static function () use ($useProvidedOps, &$ops, $readOpId, $readArgs): array {
                return $useProvidedOps
                    ? AssistantTools::invokeFromOps($readOpId, $readArgs, $ops)
                    : AssistantTools::invoke($readOpId, $readArgs);
            };
            return $readUnrestricted ? AgentHttp::withUnrestrictedRead($call) : $call();
        };
        // Every write queued in this turn is one step of the same plan (single Confirm).
        $planId = bin2hex(random_bytes(8));
        $planStep = 0;
        $followupRounds = 0;
        $followupActive = false;
        $followupReadRounds = 0;
        $relatedSeen = [];

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
            $needsFollowup = false;
            $pendingAtRoundStart = count($pending);

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
                    $missingPath = AssistantTools::missingPathArgs($op, $args);
                    if ($missingPath !== []) {
                        $payload = [
                            'ok' => false,
                            'error' => 'Missing path argument: ' . implode(', ', $missingPath)
                                . '. Resolve via a read tool first (e.g. calendar.day → events[].id), then CALL again with those args.',
                        ];
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
                            'error' => (string)$payload['error'],
                        ]);
                        $norm[] = [
                            'role' => 'tool',
                            'tool_call_id' => $tcId,
                            'content' => self::encodeToolContent($payload),
                        ];
                        continue;
                    }
                    $preview = self::writePreview($opId, $args, $desc);
                    $catalogIds = [];
                    foreach ($ops as $o) {
                        $catalogIds[(string)($o['id'] ?? '')] = true;
                    }
                    $related = AssistantRelations::inspect($opId, $args, $invokeRead, $catalogIds, $relatedSeen);
                    foreach (AssistantRelations::previewLines($related) as $line) {
                        $preview['lines'][] = $line;
                        $preview['summary'] .= "\n" . $line;
                    }
                    $reqPerm = self::requirementForOp($op);
                    $row = PendingActionsRepository::create($userId, $opId, $args, $preview['summary'], [
                        'confirm_mode' => $confirmMode,
                        'required_permission' => $reqPerm,
                        'group_chat_id' => $groupChatId,
                        'group_platform' => $groupPlatform,
                        'plan_id' => $planId,
                        'plan_step' => $planStep + 1,
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
                        $planStep++;
                        $pendingItem['required_permission'] = $reqPerm;
                        $pendingItem['confirm_mode'] = $confirmMode;
                        $pendingItem['plan_id'] = (string)($row['plan_id'] ?? '');
                        $pendingItem['plan_step'] = $planStep;
                        $pending[] = $pendingItem;
                        $payload = [
                            'ok' => true,
                            'pending' => true,
                            'token' => $row['token'],
                            'plan_step' => $planStep,
                            'plan_ref_id' => '{plan.' . $planStep . '.id}',
                            'message' => 'Write queued as plan step ' . $planStep
                                . '. UI shows one Confirm/Cancel for the whole plan. Reply with ONE short sentence; do not invent Confirm text; do not claim it ran.'
                                . ' Dependent writes may use id={plan.' . $planStep . '.id} (resolved on confirm).',
                            'summary' => $preview['summary'],
                            'headline' => $preview['headline'],
                            'lines' => $preview['lines'],
                        ];
                        if ($related !== []) {
                            $payload['related'] = $related;
                            if (AssistantRelations::needsFollowup($related)) {
                                $needsFollowup = true;
                                $payload['message'] = 'Write queued as plan step ' . $planStep
                                    . '. `related` lists affected rows in other modules. CALL the suggested write tools now'
                                    . ' for each affected row (they join the same plan, one Confirm). Do not queue this step again.';
                            }
                        }
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

                $inv = $invokeRead($opId, $args);
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

            // Related rows found — one more round so the model can add plan steps.
            if ($needsFollowup && $followupRounds < 2) {
                $followupRounds++;
                $followupActive = true;
                continue;
            }
            // While completing a plan the model may still be reading (who is on shift, event basket).
            if ($followupActive && count($pending) === $pendingAtRoundStart && $followupReadRounds < 3) {
                $followupReadRounds++;
                continue;
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

    /**
     * Localized compact title: «Счета · поиск».
     * Place labels live in the owning module:
     *   {mod}.assistant.area.{area} → {mod}.assistant.tool_title → {mod}.module.name
     * Exact override (rare): {mod}.assistant.op.{fullOpId} or assistant.tool.op.{fullOpId}
     * Action verbs (list/get/…) stay in assistant.tool.act.*
     */
    public static function humanOpTitle(string $opId): string
    {
        $opId = trim($opId);
        if ($opId === '') {
            return '';
        }
        $parts = explode('.', $opId);
        $mod = (string)($parts[0] ?? '');

        foreach ([
            $mod !== '' ? $mod . '.assistant.op.' . $opId : '',
            'assistant.tool.op.' . $opId,
        ] as $exactKey) {
            if ($exactKey === '') {
                continue;
            }
            $exact = t($exactKey);
            if ($exact !== $exactKey) {
                return $exact;
            }
        }

        $action = (string)(end($parts) ?: '');
        $area = count($parts) >= 3 ? (string)$parts[1] : '';

        $place = '';
        if ($mod !== '' && $area !== '') {
            $areaKey = $mod . '.assistant.area.' . $area;
            $place = t($areaKey);
            if ($place === $areaKey) {
                $place = '';
            }
        }
        if ($place === '' && $mod !== '') {
            $shortKey = $mod . '.assistant.tool_title';
            $place = t($shortKey);
            if ($place === $shortKey) {
                $modNameKey = $mod . '.module.name';
                $place = t($modNameKey);
                if ($place === $modNameKey) {
                    $place = $mod;
                }
            }
        }
        if ($place === '') {
            $place = $opId;
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
        // RU: подтвердить (perf) vs подтверждаю (imperf) — stem is «подтвер», not «подтверд».
        if (preg_match(
            '/^(confirm|ok|ок|готово|делай|оформляй|записывай|согласен'
            . '|подтвер(дить|ди|ждаю|ждает|жаем|жаете|дите))$/iu',
            $lower
        )) {
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
            $sa = (int)($a['PLAN_STEP'] ?? 0);
            $sb = (int)($b['PLAN_STEP'] ?? 0);
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            return ((int)($a['ID'] ?? 0)) <=> ((int)($b['ID'] ?? 0));
        });
        $live = array_values(array_filter($rows, static function (array $r): bool {
            return strtolower((string)($r['STATUS'] ?? '')) === 'pending';
        }));
        if ($live === [] && $rows !== []) {
            // Only stale tokens — surface the precise reason (already done / expired).
            $one = self::resolvePending((string)($rows[0]['TOKEN'] ?? ''), $action, $userId);
            return array_merge($one, ['done' => 0, 'failed' => 1, 'skipped' => 0, 'results' => [$one]]);
        }

        $done = 0;
        $failed = 0;
        $skipped = 0;
        $results = [];
        $lastOk = null;
        $lastError = '';
        $forbidden = null;
        $stoppedPlans = [];
        foreach ($live as $row) {
            $token = (string)($row['TOKEN'] ?? '');
            if ($token === '') {
                continue;
            }
            $rowPlan = trim((string)($row['PLAN_ID'] ?? ''));
            if ($rowPlan !== '' && isset($stoppedPlans[$rowPlan])) {
                // An earlier step of this plan failed to execute — later steps may depend on it.
                if (self::canCancelRow($row, $userId)
                    && PendingActionsRepository::mark($token, 'cancelled', ['skipped' => true])
                ) {
                    $skipped++;
                }
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
                if (!empty($one['forbidden_capability']) && $forbidden === null) {
                    $forbidden = (string)($one['required_permission'] ?? '');
                }
                // Execution failure or an aborted predecessor stops the rest of its plan
                // (not forbidden / already done / predecessor still pending elsewhere).
                if ($action === 'confirm' && $rowPlan !== ''
                    && (!empty($one['execution_failed']) || !empty($one['plan_broken']))
                ) {
                    $stoppedPlans[$rowPlan] = true;
                    // This step can never run now; reported once as the failure above.
                    if (!empty($one['plan_broken']) && self::canCancelRow($row, $userId)) {
                        PendingActionsRepository::mark($token, 'cancelled', ['skipped' => true]);
                    }
                }
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
                'skipped' => $skipped,
                'results' => $results,
            ];
        }

        $out = [
            'ok' => $done > 0,
            'action' => $action,
            'result' => $lastOk,
            'error' => $done > 0 ? '' : $lastError,
            'last_error' => $lastError,
            'done' => $done,
            'failed' => $failed,
            'skipped' => $skipped,
            'results' => $results,
        ];
        if ($done < 1 && $forbidden !== null) {
            $out['required_permission'] = $forbidden;
            $out['forbidden_capability'] = true;
        }
        return $out;
    }

    /**
     * Confirm/cancel the whole plan the token belongs to (falls back to a single pending).
     * Capability mode: the confirmer must hold every step's permission before anything runs.
     *
     * @return array<string,mixed>
     */
    public static function resolvePlan(string $token, string $action, int $userId): array
    {
        $row = PendingActionsRepository::findByToken($token);
        $planId = $row !== null ? trim((string)($row['PLAN_ID'] ?? '')) : '';
        if ($row === null || $planId === '') {
            return self::resolvePending($token, $action, $userId);
        }
        $running = [
            'ok' => false,
            'action' => strtolower(trim($action)),
            'result' => null,
            'error' => t('assistant.confirm.plan_running'),
            'done' => 0,
            'failed' => 0,
            'skipped' => 0,
            'results' => [],
        ];
        $locked = self::withPlanLock($planId, static function () use ($planId, $token, $action, $userId, $running): array {
            return self::resolvePlanLocked($planId, $token, $action, $userId, $running);
        });
        return $locked ?? $running;
    }

    /**
     * @param array<string,mixed> $running reply when a step is still executing
     * @return array<string,mixed>
     */
    private static function resolvePlanLocked(string $planId, string $token, string $action, int $userId, array $running): array
    {
        $rows = PendingActionsRepository::listByPlan($planId);
        foreach ($rows as $r) {
            if (strtolower((string)($r['STATUS'] ?? '')) === 'executing') {
                return $running;
            }
        }
        $live = array_values(array_filter($rows, static function (array $r): bool {
            return strtolower((string)($r['STATUS'] ?? '')) === 'pending';
        }));
        if ($live === []) {
            return self::resolvePending($token, $action, $userId);
        }
        if (strtolower(trim($action)) === 'confirm') {
            $missing = [];
            foreach ($live as $r) {
                if (strtolower((string)($r['CONFIRM_MODE'] ?? 'self')) !== 'capability') {
                    continue;
                }
                $perm = self::permissionForPendingRow($r);
                if ($perm !== '' && !self::staffMayConfirm($perm)) {
                    $missing[self::requirementLabel($perm)] = true;
                }
            }
            if ($missing !== []) {
                $perms = implode(', ', array_keys($missing));
                return [
                    'ok' => false,
                    'action' => 'confirm',
                    'result' => null,
                    'error' => t('assistant_groups.bot.no_permission', ['perm' => $perms]),
                    'required_permission' => $perms,
                    'forbidden_capability' => true,
                    'done' => 0,
                    'failed' => 0,
                    'skipped' => 0,
                    'results' => [],
                ];
            }
        }
        $tokens = [];
        foreach ($live as $r) {
            $tokens[] = (string)($r['TOKEN'] ?? '');
        }
        $batch = self::resolvePendingBatch($tokens, $action, $userId);
        $batch['plan_id'] = $planId;
        $batch['plan_steps'] = count($live);
        return $batch;
    }

    /**
     * Distinct permissions needed to confirm a set of pending payloads (plan preview).
     *
     * @param list<array<string,mixed>> $pending pending_actions from run()
     * @return list<string>
     */
    public static function planPermissions(array $pending): array
    {
        $perms = [];
        foreach ($pending as $p) {
            if (!is_array($p)) {
                continue;
            }
            $perm = trim((string)($p['required_permission'] ?? ''));
            if ($perm !== '') {
                $perms[$perm] = true;
            }
        }
        return array_keys($perms);
    }

    /** Same cancel rule as resolvePending: self → only the owner; capability → any linked staff. */
    private static function canCancelRow(array $row, int $userId): bool
    {
        if (strtolower(trim((string)($row['CONFIRM_MODE'] ?? 'self'))) === 'capability') {
            return $userId > 0;
        }
        return $userId > 0 && (int)($row['ID_USER'] ?? 0) === $userId;
    }

    /**
     * One confirmer per plan at a time (MySQL named lock, non-blocking).
     *
     * @param callable():array<string,mixed> $fn
     * @return array<string,mixed>|null null when another confirmer holds the plan
     */
    private static function withPlanLock(string $planId, callable $fn): ?array
    {
        $name = 'assistant_plan_' . $planId;
        $pdo = Database::pdo();
        $got = false;
        try {
            $st = $pdo->prepare('SELECT GET_LOCK(?, 0)');
            $st->execute([$name]);
            $got = (int)$st->fetchColumn() === 1;
            if (!$got) {
                return null;
            }
            return $fn();
        } finally {
            if ($got) {
                try {
                    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
                } catch (Throwable $e) {
                    // ignore unlock errors
                }
            }
        }
    }

    /** @param array<string,mixed> $row */
    private static function permissionForPendingRow(array $row): string
    {
        $perm = trim((string)($row['REQUIRED_PERMISSION'] ?? ''));
        if ($perm !== '') {
            return $perm;
        }
        $opId = (string)($row['OP_ID'] ?? '');
        foreach (AgentHttp::allOps() as $op) {
            if ((string)($op['id'] ?? '') === $opId) {
                return self::requirementForOp($op);
            }
        }
        return '';
    }

    /**
     * Confirm gate for a write op: capability wins over permission when both are set.
     * Encoded as "cap:FLAG" in REQUIRED_PERMISSION (no schema change).
     *
     * @param array<string,mixed> $op
     */
    public static function requirementForOp(array $op): string
    {
        $cap = trim((string)($op['capability'] ?? ''));
        if ($cap !== '') {
            return 'cap:' . $cap;
        }
        return trim((string)($op['permission'] ?? ''));
    }

    public static function staffMayConfirm(string $requirement): bool
    {
        $requirement = trim($requirement);
        if ($requirement === '') {
            return true;
        }
        if (stripos($requirement, 'cap:') === 0) {
            $cap = trim(substr($requirement, 4));
            return $cap === '' || Auth::capability($cap);
        }
        return Auth::can($requirement);
    }

    public static function requirementLabel(string $requirement): string
    {
        $requirement = trim($requirement);
        if (stripos($requirement, 'cap:') === 0) {
            $cap = trim(substr($requirement, 4));
            if ($cap === '') {
                return $requirement;
            }
            $key = 'assistant_groups.bot.cap.' . $cap;
            if (function_exists('t')) {
                $tr = t($key);
                if ($tr !== '' && $tr !== $key) {
                    return $tr;
                }
            }
            return $cap;
        }
        return $requirement;
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
            $err = trim((string)($batch['last_error'] ?? $batch['error'] ?? ''));
            if ($err !== '') {
                $text .= ': ' . $err;
            }
        }
        $skipped = (int)($batch['skipped'] ?? 0);
        if ($skipped > 0) {
            $text .= "\n" . t('assistant.confirm.skipped_n', ['n' => (string)$skipped]);
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
            // capability: any linked staff may cancel; confirm requires permission or cap:FLAG.
            if ($action === 'confirm') {
                if ($reqPerm === '') {
                    // Fall back to op permission/capability from catalog.
                    foreach (AgentHttp::allOps() as $op) {
                        if ((string)($op['id'] ?? '') === (string)($row['OP_ID'] ?? '')) {
                            $reqPerm = self::requirementForOp($op);
                            break;
                        }
                    }
                }
                if ($reqPerm !== '' && !self::staffMayConfirm($reqPerm)) {
                    $label = self::requirementLabel($reqPerm);
                    return [
                        'ok' => false,
                        'action' => $action,
                        'result' => null,
                        'error' => t('assistant_groups.bot.no_permission', ['perm' => $label]),
                        'required_permission' => $label,
                        'forbidden_capability' => true,
                    ];
                }
            }
        }
        $status = strtolower(trim((string)($row['STATUS'] ?? '')));
        if ($status !== 'pending') {
            if (in_array($status, ['confirmed', 'executing'], true)) {
                return [
                    'ok' => false,
                    'action' => $action,
                    'result' => null,
                    'error' => t('assistant.confirm.already_done'),
                ];
            }
            return ['ok' => false, 'action' => $action, 'result' => null, 'error' => t('assistant.confirm.not_found')];
        }
        if ((int)($row['EXPIRES_AT'] ?? 0) < time()) {
            PendingActionsRepository::mark($token, 'expired');
            return ['ok' => false, 'action' => $action, 'result' => null, 'error' => t('assistant.confirm.expired')];
        }

        if ($action === 'cancel') {
            if (!PendingActionsRepository::mark($token, 'cancelled')) {
                return [
                    'ok' => false,
                    'action' => 'cancel',
                    'result' => null,
                    'error' => t('assistant.confirm.already_done'),
                ];
            }
            return [
                'ok' => true,
                'action' => 'cancel',
                'result' => ['ok' => true, 'cancelled' => true],
                'error' => '',
            ];
        }

        $planId = trim((string)($row['PLAN_ID'] ?? ''));
        $planStep = (int)($row['PLAN_STEP'] ?? 0);
        if ($planId !== '') {
            $prev = PendingActionsRepository::earlierStepsState($planId, $planStep);
            if ($prev['broken'] > 0 || $prev['open'] > 0) {
                return [
                    'ok' => false,
                    'action' => 'confirm',
                    'result' => null,
                    'error' => t($prev['broken'] > 0 ? 'assistant.confirm.plan_broken' : 'assistant.confirm.plan_order'),
                    'plan_blocked' => true,
                    'plan_broken' => $prev['broken'] > 0,
                ];
            }
        }

        // Claim before invoke so a second confirmer cannot run the same write.
        $claimed = PendingActionsRepository::claimForExecution($token, $userId);
        if ($claimed === null) {
            $again = PendingActionsRepository::findByToken($token);
            $st2 = strtolower(trim((string)($again['STATUS'] ?? '')));
            if (in_array($st2, ['confirmed', 'executing', 'cancelled'], true)) {
                return [
                    'ok' => false,
                    'action' => 'confirm',
                    'result' => null,
                    'error' => t('assistant.confirm.already_done'),
                ];
            }
            if ($again !== null && (int)($again['EXPIRES_AT'] ?? 0) < time()) {
                PendingActionsRepository::mark($token, 'expired');
                return [
                    'ok' => false,
                    'action' => 'confirm',
                    'result' => null,
                    'error' => t('assistant.confirm.expired'),
                ];
            }
            return [
                'ok' => false,
                'action' => 'confirm',
                'result' => null,
                'error' => t('assistant.confirm.not_found'),
            ];
        }

        $opId = (string)($claimed['OP_ID'] ?? '');
        $args = PendingActionsRepository::decodeArgs($claimed);
        if ($planId !== '' && $planStep > 1) {
            $args = PendingActionsRepository::rewritePlanPlaceholders($args, $planId, $planStep);
        }
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
            PendingActionsRepository::finalizeExecution($token, 'failed', ['error' => $inv['error']]);
            return [
                'ok' => false,
                'action' => 'confirm',
                'result' => null,
                'error' => $inv['error'],
                'execution_failed' => true,
            ];
        }
        PendingActionsRepository::finalizeExecution($token, 'confirmed', $inv['data']);
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

    /** @param list<mixed> $list */
    private static function looksLikeRowList(array $list): bool
    {
        $n = 0;
        foreach ($list as $row) {
            if (!is_array($row)) {
                return false;
            }
            $n++;
            if ($n >= 3) {
                return true;
            }
        }
        return $n > 0;
    }

    /**
     * @param list<array<string,mixed>> $list
     * @return list<array<string,mixed>>
     */
    private static function slimRowsForTool(array $list, int $max): array
    {
        $out = [];
        foreach (array_slice($list, 0, max(1, $max)) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = self::slimRowForTool($row);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function slimRowForTool(array $row): array
    {
        $prefer = [
            'id', 'uuid', 'token', 'date', 'dt', 'dtstart', 'dtend', 'dtstart_iso', 'dtend_iso', 'dt_iso',
            'id_operator', 'operator_name', 'operator_acronym', 'acronym', 'id_user', 'client_name',
            'is_block', 'name', 'phone',
            'title', 'status', 'price_col', 'shift_label', 'shift_start_hm', 'shift_end_hm',
            'sum', 'summ', 'amount', 'content_brief', 'product_name', 'type_name', 'id_event_parent',
            'enabled', 'weekday', 'is_today',
        ];
        $slim = [];
        foreach ($prefer as $k) {
            if (!array_key_exists($k, $row)) {
                continue;
            }
            $v = $row[$k];
            if (is_scalar($v) || $v === null) {
                $slim[$k] = $v;
            }
        }
        if ($slim !== []) {
            return $slim;
        }
        $n = 0;
        foreach ($row as $k => $v) {
            if ($n >= 8) {
                break;
            }
            if (is_scalar($v) || $v === null) {
                $slim[(string)$k] = $v;
                $n++;
            }
        }
        return $slim;
    }

    /**
     * @param list<array<string,mixed>> $days
     * @return list<array<string,mixed>>
     */
    private static function slimDaysForTool(array $days): array
    {
        $out = [];
        foreach (array_slice($days, 0, 7) as $day) {
            if (!is_array($day)) {
                continue;
            }
            $one = self::slimRowForTool($day);
            foreach (['events', 'procedures', 'orders', 'shifts'] as $k) {
                if (!isset($day[$k]) || !is_array($day[$k])) {
                    continue;
                }
                $rows = array_values($day[$k]);
                $max = $k === 'events' ? 40 : 20;
                $one[$k . '_count'] = count($rows);
                if (self::looksLikeRowList($rows)) {
                    $one[$k] = self::slimRowsForTool($rows, $max);
                }
            }
            $out[] = $one;
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
            $skipListKey = [
                'ok' => true, 'error' => true, 'total' => true, 'totals' => true,
                'months' => true, 'filters' => true, 'dps_by_payment' => true,
            ];
            $listKeys = ['items', 'rows', 'list', 'data', 'events', 'procedures', 'orders', 'shifts', 'days'];
            foreach ($payload as $k => $v) {
                if (!is_string($k) || !is_array($v) || $v === [] || isset($skipListKey[$k])) {
                    continue;
                }
                if (self::looksLikeRowList($v) && !in_array($k, $listKeys, true)) {
                    $listKeys[] = $k;
                }
            }
            foreach ($listKeys as $key) {
                if (!isset($payload[$key]) || !is_array($payload[$key])) {
                    continue;
                }
                $list = array_values($payload[$key]);
                if ($key === 'days' && $list !== []) {
                    $compact['days_count'] = count($list);
                    $compact['days'] = self::slimDaysForTool($list);
                    $skipListKey[$key] = true;
                    continue;
                }
                if ($list === [] || !self::looksLikeRowList($list)) {
                    continue;
                }
                $compact[$key . '_count'] = count($list);
                $sums = self::sumNumericColumns($list);
                if ($sums !== []) {
                    $compact['sum_' . $key] = $sums;
                }
                // Keep id-bearing slim rows (not a 3-row sample) so calendar delete can resolve.
                $maxRows = ($key === 'events' || $key === 'shifts') ? 80 : 40;
                $compact[$key] = self::slimRowsForTool($list, $maxRows);
                if (count($list) > $maxRows) {
                    $compact[$key . '_omitted'] = count($list) - $maxRows;
                }
                $skipListKey[$key] = true;
            }
            $meta = [];
            $n = 0;
            foreach ($payload as $k => $v) {
                if ($n >= 16) {
                    break;
                }
                if (isset($skipListKey[$k])) {
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
            $compact['hint'] = 'Large result compacted to id/time/name fields. Use listed ids; narrow with staff=/date if omitted>0.';
        }

        $out = json_encode($compact, $flags);
        if ($out !== false && strlen($out) <= $maxBytes) {
            return $out;
        }
        // Still too big: peel lists down to id/time essentials, keep events if present.
        if (is_array($payload)) {
            $minimal = [
                'ok' => !isset($payload['ok']) || !empty($payload['ok']),
                '_truncated' => true,
                'bytes' => strlen($encoded),
                'hint' => 'Result too large; showing id/time essentials. Narrow with staff= or date.',
            ];
            foreach (['date', 'monday', 'anchor_date', 'staff_id', 'can_delete', 'can_edit', 'can_create'] as $k) {
                if (isset($payload[$k]) && (is_scalar($payload[$k]) || $payload[$k] === null)) {
                    $minimal[$k] = $payload[$k];
                }
            }
            foreach (['events', 'shifts', 'items', 'rows'] as $key) {
                if (!isset($payload[$key]) || !is_array($payload[$key])) {
                    continue;
                }
                $list = array_values($payload[$key]);
                $minimal[$key . '_count'] = count($list);
                $minimal[$key] = self::slimRowsForTool($list, $key === 'events' ? 60 : 30);
            }
            if (isset($payload['days']) && is_array($payload['days'])) {
                $minimal['days_count'] = count($payload['days']);
                $minimal['days'] = self::slimDaysForTool(array_values($payload['days']));
            }
            $out2 = json_encode($minimal, $flags);
            if ($out2 !== false && strlen($out2) <= $maxBytes) {
                return $out2;
            }
            // Last resort: shrink events until they fit (id/time/name only).
            if (isset($payload['events']) && is_array($payload['events'])) {
                $all = [];
                foreach ($payload['events'] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $all[] = [
                        'id' => (int)($row['id'] ?? 0),
                        'is_block' => (int)($row['is_block'] ?? 0),
                        'operator_name' => (string)($row['operator_name'] ?? ''),
                        'dtstart_iso' => (string)($row['dtstart_iso'] ?? ''),
                        'dtend_iso' => (string)($row['dtend_iso'] ?? ''),
                    ];
                }
                $total = count($payload['events']);
                $keep = min(100, count($all));
                while ($keep >= 1) {
                    $bare = [
                        'ok' => true,
                        '_truncated' => true,
                        'bytes' => strlen($encoded),
                        'date' => $payload['date'] ?? null,
                        'events_count' => $total,
                        'events' => array_slice($all, 0, $keep),
                        'events_omitted' => max(0, $total - $keep),
                        'hint' => 'Only event id/time kept. Narrow staff= if omitted>0.',
                    ];
                    $out3 = json_encode($bare, $flags);
                    if ($out3 !== false && strlen($out3) <= $maxBytes) {
                        return $out3;
                    }
                    $keep = $keep > 1 ? max(1, (int)floor($keep / 2)) : 0;
                }
            }
        }
        return '{"ok":true,"_truncated":true,"bytes":' . strlen($encoded)
            . ',"hint":"Result too large; narrow filters (staff=/date)."}';
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
    public static function buildSystemPrompt(array $ops, bool $groupMode = false): string
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
        $system .= "\nCall several read tools in one step when useful."
            . "\nMulti-step tasks: first read everything you need, then queue EVERY write the task requires in the same turn — each becomes a numbered step of one plan under a single Confirm."
            . " If a queued write returns `related`, add the suggested steps for the affected rows. Once the plan is complete, stop — do not keep searching.";
        $system .= "\n" . t('assistant.system_prompt.totals');
        $system .= self::scopedHintsForOps($ops, $groupMode);
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
     * Collect module-owned prompt hints via EventBus `assistant.system.hints`.
     * Owning modules append lines when their tools are in the catalog (or absent-guards).
     * Public for AssistantEval deterministic checks.
     *
     * @param list<array<string,mixed>> $ops
     * @param bool $groupMode group channel: modules should omit «product absent» leaks
     */
    public static function scopedHintsForOps(array $ops, bool $groupMode = false): string
    {
        $ids = [];
        foreach ($ops as $op) {
            $id = (string)($op['id'] ?? '');
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        $payload = EventBus::emit('assistant.system.hints', [
            'op_ids' => $ids,
            'ops' => $ops,
            'group_mode' => $groupMode,
            'hints' => [],
        ]);
        $raw = is_array($payload) && is_array($payload['hints'] ?? null) ? $payload['hints'] : [];
        $lines = [];
        foreach ($raw as $line) {
            $s = trim((string)$line);
            if ($s !== '') {
                $lines[] = $s;
            }
        }
        foreach (AssistantRelations::promptHints($ids) as $line) {
            $lines[] = $line;
        }
        if ($lines === []) {
            return '';
        }
        return "\n" . implode("\n", $lines);
    }
}
