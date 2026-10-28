<?php
declare(strict_types=1);

/**
 * Confirm/cancel pending writes and related permission helpers.
 * Split from AssistantHarness so the chat loop stays readable.
 */
final class AssistantPending
{
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
        // RU stems: perfective vs imperfective "confirm" — regex below; avoid a false longer stem.
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

    /** Soft "do it" phrases — only confirm when a pending write already exists. */
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
            // Soft "do it" without a queued write must reach the LLM so it can call the write tool.
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
            if (!self::dbRowsConfirmable($live)) {
                return [
                    'ok' => false,
                    'action' => 'confirm',
                    'result' => null,
                    'error' => t('assistant.confirm.preflight_blocked'),
                    'preflight_blocked' => true,
                    'done' => 0,
                    'failed' => 0,
                    'skipped' => 0,
                    'results' => [],
                ];
            }
            // Run suggested fixes (e.g. workweeks.update) before the blocked write.
            $live = self::orderLiveByPreflightSuggest($live);
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

    /**
     * True when every blocked step has its suggested writes also queued in this plan.
     * Busy/overlap with no suggest → not confirmable (Confirm button hidden).
     *
     * @param list<array<string,mixed>> $pending
     */
    public static function pendingConfirmable(array $pending): bool
    {
        $opIds = [];
        foreach ($pending as $p) {
            if (!is_array($p)) {
                continue;
            }
            $id = trim((string)($p['op_id'] ?? ''));
            if ($id !== '') {
                $opIds[$id] = true;
            }
        }
        foreach ($pending as $p) {
            if (!is_array($p) || empty($p['blocked'])) {
                continue;
            }
            $suggestOps = is_array($p['suggest_ops'] ?? null) ? $p['suggest_ops'] : [];
            if ($suggestOps === []) {
                return false;
            }
            foreach ($suggestOps as $sid) {
                $sid = trim((string)$sid);
                if ($sid === '' || empty($opIds[$sid])) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Re-check live DB rows (resent draft) via preflight.
     *
     * @param list<array<string,mixed>> $rows base_assistant_pending rows
     */
    public static function dbRowsConfirmable(array $rows): bool
    {
        $pending = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (strtolower(trim((string)($row['STATUS'] ?? ''))) !== 'pending') {
                continue;
            }
            $opId = trim((string)($row['OP_ID'] ?? ''));
            if ($opId === '') {
                continue;
            }
            $args = class_exists('PendingActionsRepository', false)
                ? PendingActionsRepository::decodeArgs($row)
                : [];
            $pf = class_exists('AssistantWritePreflight', false)
                ? AssistantWritePreflight::run($opId, is_array($args) ? $args : [])
                : ['blockers' => [], 'suggest' => []];
            $pending[] = [
                'op_id' => $opId,
                'blocked' => AssistantWritePreflight::hasBlockers($pf),
                'suggest_ops' => AssistantWritePreflight::suggestOpIds($pf),
            ];
        }
        return self::pendingConfirmable($pending);
    }

    /** @param list<array<string,mixed>> $pending */
    public static function pendingReplyFallback(array $pending): string
    {
        if (self::pendingConfirmable($pending)) {
            return t('assistant.confirm.press_buttons');
        }
        foreach ($pending as $p) {
            if (is_array($p) && !empty($p['choices']) && is_array($p['choices'])) {
                return t('assistant.confirm.blocked_choices');
            }
        }
        return t('assistant.confirm.blocked_buttons');
    }

    /**
     * First pending step's validated choice list (for group buttons).
     *
     * @param list<array<string,mixed>> $pending
     * @return list<array{op_id:string,args:array<string,mixed>,label:string}>
     */
    public static function pendingChoices(array $pending): array
    {
        foreach ($pending as $p) {
            if (!is_array($p)) {
                continue;
            }
            $choices = is_array($p['choices'] ?? null) ? $p['choices'] : [];
            if ($choices !== []) {
                return array_values($choices);
            }
        }
        return [];
    }

    /**
     * Put suggested fix ops (from preflight) ahead of the writes that need them.
     *
     * @param list<array<string,mixed>> $live
     * @return list<array<string,mixed>>
     */
    private static function orderLiveByPreflightSuggest(array $live): array
    {
        if (count($live) < 2 || !class_exists('AssistantWritePreflight', false)) {
            return $live;
        }
        $byOp = [];
        foreach ($live as $i => $row) {
            $opId = trim((string)($row['OP_ID'] ?? ''));
            if ($opId !== '') {
                $byOp[$opId][] = $i;
            }
        }
        $preferEarlier = [];
        foreach ($live as $i => $row) {
            $opId = trim((string)($row['OP_ID'] ?? ''));
            $args = PendingActionsRepository::decodeArgs($row);
            $pf = AssistantWritePreflight::run($opId, is_array($args) ? $args : []);
            foreach (AssistantWritePreflight::suggestOpIds($pf) as $sid) {
                if (empty($byOp[$sid])) {
                    continue;
                }
                foreach ($byOp[$sid] as $j) {
                    // suggested op index $j should run before blocked $i
                    $preferEarlier[$j] = min((int)($preferEarlier[$j] ?? $j), $i - 1);
                }
            }
        }
        if ($preferEarlier === []) {
            return $live;
        }
        $indexed = [];
        foreach ($live as $i => $row) {
            $indexed[] = ['i' => $i, 'row' => $row, 'ord' => $preferEarlier[$i] ?? $i];
        }
        usort($indexed, static function (array $a, array $b): int {
            if ($a['ord'] !== $b['ord']) {
                return $a['ord'] <=> $b['ord'];
            }
            return $a['i'] <=> $b['i'];
        });
        $out = [];
        foreach ($indexed as $item) {
            $out[] = $item['row'];
        }
        return $out;
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

        // Refuse Confirm while preflight still blocks and the plan has no queued fix.
        if (class_exists('AssistantWritePreflight', false)) {
            $checkOp = trim((string)($row['OP_ID'] ?? ''));
            $checkArgs = PendingActionsRepository::decodeArgs($row);
            $pfNow = AssistantWritePreflight::run($checkOp, is_array($checkArgs) ? $checkArgs : []);
            if (AssistantWritePreflight::hasBlockers($pfNow)) {
                $planIdCheck = trim((string)($row['PLAN_ID'] ?? ''));
                $siblings = $planIdCheck !== ''
                    ? PendingActionsRepository::listByPlan($planIdCheck)
                    : [$row];
                $liveSiblings = array_values(array_filter($siblings, static function (array $r): bool {
                    return strtolower(trim((string)($r['STATUS'] ?? ''))) === 'pending';
                }));
                if ($liveSiblings === []) {
                    $liveSiblings = [$row];
                }
                if (!self::dbRowsConfirmable($liveSiblings)) {
                    return [
                        'ok' => false,
                        'action' => 'confirm',
                        'result' => null,
                        'error' => t('assistant.confirm.preflight_blocked'),
                        'preflight_blocked' => true,
                    ];
                }
                // Fix ops still pending → must run them first (resolvePlan reorders; else wait).
                $fixOps = AssistantWritePreflight::suggestOpIds($pfNow);
                foreach ($liveSiblings as $sib) {
                    $sibOp = trim((string)($sib['OP_ID'] ?? ''));
                    $sibTok = (string)($sib['TOKEN'] ?? '');
                    if ($sibTok === $token || $sibOp === '' || !in_array($sibOp, $fixOps, true)) {
                        continue;
                    }
                    return [
                        'ok' => false,
                        'action' => 'confirm',
                        'result' => null,
                        'error' => t('assistant.confirm.plan_order'),
                        'plan_blocked' => true,
                    ];
                }
            }
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

}
