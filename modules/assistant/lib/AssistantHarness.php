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
                        'content' => AssistantToolPayload::encodeToolContent($payload),
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
                        'content' => AssistantToolPayload::encodeToolContent($payload),
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
                        'content' => AssistantToolPayload::encodeToolContent($stubPayload),
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
                            'content' => AssistantToolPayload::encodeToolContent($payload),
                        ];
                        continue;
                    }
                    $preview = self::writePreview($opId, $args, $desc);
                    $catalogIds = [];
                    foreach ($ops as $o) {
                        $catalogIds[(string)($o['id'] ?? '')] = true;
                    }
                    $preflight = class_exists('AssistantWritePreflight', false)
                        ? AssistantWritePreflight::run($opId, $args, $catalogIds)
                        : ['blockers' => [], 'suggest' => [], 'noop' => false];
                    foreach (AssistantWritePreflight::previewLines($preflight) as $line) {
                        $preview['lines'][] = $line;
                        $preview['summary'] .= "\n" . $line;
                    }
                    $preflightBlocked = class_exists('AssistantWritePreflight', false)
                        && AssistantWritePreflight::hasBlockers($preflight);
                    $preflightSuggestOps = class_exists('AssistantWritePreflight', false)
                        ? AssistantWritePreflight::suggestOpIds($preflight)
                        : [];
                    if (!empty($preflight['noop'])) {
                        $payload = [
                            'ok' => false,
                            'error' => t('assistant.preflight.noop'),
                            'preflight' => $preflight,
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
                            'content' => AssistantToolPayload::encodeToolContent($payload),
                        ];
                        continue;
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
                        $pendingItem['blocked'] = $preflightBlocked;
                        $pendingItem['suggest_ops'] = $preflightSuggestOps;
                        $preflightChoices = is_array($preflight['choices'] ?? null) ? $preflight['choices'] : [];
                        $pendingItem['choices'] = $preflightChoices;
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
                            'blocked' => $preflightBlocked,
                            'choices' => $preflightChoices,
                        ];
                        if ($preflight['blockers'] !== [] || $preflight['suggest'] !== [] || $preflightChoices !== []) {
                            $payload['preflight'] = $preflight;
                            if (AssistantWritePreflight::needsFollowup($preflight)) {
                                $needsFollowup = true;
                                $payload['message'] = 'Write queued as plan step ' . $planStep
                                    . '. `preflight` has blockers and suggested writes. CALL each suggested op now'
                                    . ' (same plan, one Confirm) so the draft becomes executable. Do not claim success yet.';
                            } elseif ($preflightBlocked && $preflightChoices !== []) {
                                // Validated choices are on buttons — do not invent more options (Cancel is separate).
                                $payload['message'] = 'Write queued as plan step ' . $planStep
                                    . ' but BLOCKED. Confirm hidden. `choices` are validated alternatives on UI buttons.'
                                    . ' Reply with ONE short sentence naming the conflict; do not invent extra options;'
                                    . ' do not offer Cancel as a numbered choice. User picks a button or Cancel/Problem.';
                            } elseif ($preflightBlocked) {
                                $needsFollowup = true;
                                $payload['message'] = 'Write queued as plan step ' . $planStep
                                    . ' but BLOCKED (Confirm button hidden). Do not ask the user to press Confirm.'
                                    . ' In your reply propose 2 numbered alternatives that clear the ⚠ blockers'
                                    . ' (do not list Cancel / leave-as-is — Cancel is a separate button),'
                                    . ' then wait for the user pick OR CALL the same write tool again with a fixed draft.';
                            }
                        }
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
                        'content' => AssistantToolPayload::encodeToolContent($payload),
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
                    'content' => AssistantToolPayload::encodeToolContent($payload),
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
            // Write already queued — show Confirm (or Cancel/Problem only if blocked); skip extra LLM rounds.
            if ($pending !== []) {
                $fallback = self::pendingReplyFallback($pending);
                return self::okResult(
                    $text !== '' ? $text : $fallback,
                    $norm,
                    $pending,
                    $trace,
                    $profile,
                    $ops
                );
            }
        }

        if ($pending !== []) {
            return self::okResult(self::pendingReplyFallback($pending), $norm, $pending, $trace, $profile, $ops);
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
        return AssistantPending::formatConfirmDone($result);
    }

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

    public static function normalizeConfirmPhrase(string $text): string
    {
        return AssistantPending::normalizeConfirmPhrase($text);
    }

    public static function detectConfirmIntent(string $text): ?string
    {
        return AssistantPending::detectConfirmIntent($text);
    }

    /** @return array{ok:bool,action:string,result:?array,error:string,handled:bool,done?:int,failed?:int}|null */
    public static function tryResolveConfirmText(string $text, int $userId): ?array
    {
        return AssistantPending::tryResolveConfirmText($text, $userId);
    }

    /** @param list<string> $tokens @return array{ok:bool,action:string,result:?array,error:string,done:int,failed:int,results:list} */
    public static function resolvePendingBatch(array $tokens, string $action, int $userId): array
    {
        return AssistantPending::resolvePendingBatch($tokens, $action, $userId);
    }

    /** @return array<string,mixed> */
    public static function resolvePlan(string $token, string $action, int $userId): array
    {
        return AssistantPending::resolvePlan($token, $action, $userId);
    }

    /** @param list<array<string,mixed>> $pending @return list<string> */
    public static function planPermissions(array $pending): array
    {
        return AssistantPending::planPermissions($pending);
    }

    /** @param list<array<string,mixed>> $pending */
    public static function pendingConfirmable(array $pending): bool
    {
        return AssistantPending::pendingConfirmable($pending);
    }

    /** @param list<array<string,mixed>> $rows */
    public static function dbRowsConfirmable(array $rows): bool
    {
        return AssistantPending::dbRowsConfirmable($rows);
    }

    /** @param list<array<string,mixed>> $pending */
    public static function pendingReplyFallback(array $pending): string
    {
        return AssistantPending::pendingReplyFallback($pending);
    }

    /** @param list<array<string,mixed>> $pending @return list<array{op_id:string,args:array<string,mixed>,label:string}> */
    public static function pendingChoices(array $pending): array
    {
        return AssistantPending::pendingChoices($pending);
    }

    /** @param array<string,mixed> $op */
    public static function requirementForOp(array $op): string
    {
        return AssistantPending::requirementForOp($op);
    }

    public static function staffMayConfirm(string $requirement): bool
    {
        return AssistantPending::staffMayConfirm($requirement);
    }

    public static function requirementLabel(string $requirement): string
    {
        return AssistantPending::requirementLabel($requirement);
    }

    public static function formatConfirmBatchDone(array $batch): string
    {
        return AssistantPending::formatConfirmBatchDone($batch);
    }

    /**
     * @return array{
     *   ok:bool,action:string,result:?array,error:string,
     *   required_permission?:string,forbidden_capability?:bool
     * }
     */
    public static function resolvePending(string $token, string $action, int $userId): array
    {
        return AssistantPending::resolvePending($token, $action, $userId);
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
            $lines = AssistantToolPayload::previewGenericArgs($args);
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
            'args' => AssistantToolPayload::publicArgs($args),
        ];
    }

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
