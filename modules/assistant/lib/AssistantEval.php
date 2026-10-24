<?php
declare(strict_types=1);

/**
 * Hybrid assistant eval runner: deterministic guards + live harness personas (tool allowlists).
 */
final class AssistantEval
{
    /**
     * Case catalog for UI (no execution). Prompts resolved via current locale.
     *
     * @return list<array{id:string,layer:string,title:string,explain:string,prompt:string}>
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (AssistantEvalCases::all() as $case) {
            if (!is_array($case)) {
                continue;
            }
            $id = (string)($case['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $titleKey = (string)($case['title_key'] ?? $id);
            $explainKey = (string)($case['explain_key'] ?? '');
            $promptKey = trim((string)($case['prompt_key'] ?? ''));
            $out[] = [
                'id' => $id,
                'layer' => (string)($case['layer'] ?? 'det'),
                'title' => t($titleKey),
                'explain' => $explainKey !== '' ? t($explainKey) : '',
                'prompt' => $promptKey !== '' ? t($promptKey) : '',
            ];
        }
        return $out;
    }

    /**
     * @param array{live?:bool,timeout?:int,profile?:string,user_id?:int} $opts
     * @return array{
     *   ok:bool,rows:list<array<string,mixed>>,summary:array{pass:int,fail:int,skip:int,ms:int},
     *   error:string,profile:?string
     * }
     */
    public static function run(array $opts = []): array
    {
        $live = !isset($opts['live']) || !empty($opts['live']);
        $t0 = (int)(microtime(true) * 1000);
        $userId = self::resolveUserId($opts);
        if ($userId < 1) {
            return [
                'ok' => false,
                'rows' => [],
                'summary' => ['pass' => 0, 'fail' => 0, 'skip' => 0, 'ms' => 0],
                'error' => t('assistant.eval.err.no_user'),
                'profile' => null,
            ];
        }

        $run = static function () use ($opts, $live, $t0, $userId): array {
            $rows = [];
            $profile = null;
            foreach (self::catalog() as $meta) {
                $one = self::runCaseAsUser((string)$meta['id'], $opts, $live, $userId);
                if (!empty($one['profile'])) {
                    $profile = (string)$one['profile'];
                }
                $rows[] = $one['row'];
            }
            return self::packResult($rows, $t0, $profile, '');
        };

        if (Auth::userId() === $userId) {
            return $run();
        }
        return Auth::runAs($userId, $run);
    }

    /**
     * Run a single case (for stepped UI).
     *
     * @param array{live?:bool,timeout?:int,profile?:string,user_id?:int} $opts
     * @return array{ok:bool,row:array<string,mixed>,profile:?string,system_prompt:string,error:string}
     */
    public static function runCase(string $caseId, array $opts = []): array
    {
        $live = !isset($opts['live']) || !empty($opts['live']);
        $userId = self::resolveUserId($opts);
        if ($userId < 1) {
            return [
                'ok' => false,
                'row' => self::emptyRow($caseId, 'fail', t('assistant.eval.err.no_user')),
                'profile' => null,
                'system_prompt' => '',
                'error' => t('assistant.eval.err.no_user'),
            ];
        }

        $run = static function () use ($caseId, $opts, $live, $userId): array {
            $one = self::runCaseAsUser($caseId, $opts, $live, $userId);
            return [
                'ok' => ($one['row']['status'] ?? '') !== 'fail',
                'row' => $one['row'],
                'profile' => $one['profile'] ?? null,
                'system_prompt' => (string)($one['system_prompt'] ?? ''),
                'error' => '',
            ];
        };

        if (Auth::userId() === $userId) {
            return $run();
        }
        return Auth::runAs($userId, $run);
    }

    /**
     * @param array{live?:bool,timeout?:int,profile?:string} $opts
     * @return array{row:array<string,mixed>,profile:?string,system_prompt:string}
     */
    private static function runCaseAsUser(string $caseId, array $opts, bool $live, int $userId): array
    {
        $case = null;
        foreach (AssistantEvalCases::all() as $c) {
            if (is_array($c) && (string)($c['id'] ?? '') === $caseId) {
                $case = $c;
                break;
            }
        }
        if ($case === null) {
            return [
                'row' => self::emptyRow($caseId, 'fail', t('assistant.eval.err.unknown_case')),
                'profile' => null,
                'system_prompt' => '',
            ];
        }

        $layer = (string)($case['layer'] ?? 'det');
        $titleKey = (string)($case['title_key'] ?? $caseId);
        $explainKey = (string)($case['explain_key'] ?? '');
        $promptKey = trim((string)($case['prompt_key'] ?? ''));
        $prompt = $promptKey !== '' ? t($promptKey) : '';
        $explain = $explainKey !== '' ? t($explainKey) : '';
        $row = [
            'id' => $caseId,
            'layer' => $layer,
            'title' => t($titleKey),
            'explain' => $explain,
            'question' => $layer === 'live' ? $prompt : $explain,
            'answer' => '',
            'status' => 'skip',
            'detail' => '',
            'ms' => 0,
            'excerpt' => '',
            'repaired' => false,
        ];
        $systemPrompt = '';

        if ($layer === 'live' && !$live) {
            $row['detail'] = t('assistant.eval.skip.live_off');
            $row['answer'] = $row['detail'];
            return ['row' => $row, 'profile' => null, 'system_prompt' => ''];
        }

        $need = $case['need_modules'] ?? [];
        if (is_array($need)) {
            $missing = [];
            foreach ($need as $mid) {
                $mid = trim((string)$mid);
                if ($mid !== '' && !ModuleManager::isEnabled($mid)) {
                    $missing[] = $mid;
                }
            }
            if ($missing !== []) {
                $row['detail'] = t('assistant.eval.skip.modules', [
                    'modules' => implode(', ', $missing),
                ]);
                $row['answer'] = $row['detail'];
                return ['row' => $row, 'profile' => null, 'system_prompt' => ''];
            }
        }

        $c0 = (int)(microtime(true) * 1000);
        $profile = null;
        try {
            if ($layer === 'det') {
                $grade = $case['grade'] ?? null;
                if (!is_callable($grade)) {
                    $row['status'] = 'skip';
                    $row['detail'] = 'no grader';
                    $row['answer'] = $row['detail'];
                } else {
                    $g = $grade();
                    $row = self::applyGrade($row, is_array($g) ? $g : []);
                    if ($row['answer'] === '') {
                        $row['answer'] = (string)$row['detail'];
                    }
                }
                // Det cases do not call the LLM — show base prompt for transparency.
                $systemPrompt = class_exists('AssistantHarness', false)
                    ? AssistantHarness::buildSystemPrompt([])
                    : '';
            } else {
                $allOps = class_exists('AssistantTools', false)
                    ? AssistantTools::availableOps()
                    : [];
                $allow = $case['tool_allow'] ?? null;
                $ops = self::filterOps($allOps, is_array($allow) ? $allow : null);
                $systemPrompt = class_exists('AssistantHarness', false)
                    ? AssistantHarness::buildSystemPrompt($ops)
                    : '';
                if ($ops === []) {
                    $row['detail'] = t('assistant.eval.skip.no_tools');
                    $row['answer'] = $row['detail'];
                } elseif ($prompt === '') {
                    $row['detail'] = 'empty prompt';
                    $row['answer'] = $row['detail'];
                } else {
                    $harnessOpts = [
                        'user_id' => $userId,
                        'ops' => $ops,
                        'timeout' => (int)($opts['timeout'] ?? 90),
                        'temperature' => 0.1,
                    ];
                    if (isset($opts['profile'])) {
                        $harnessOpts['profile'] = (string)$opts['profile'];
                    }
                    $extra = $case['harness_opts'] ?? null;
                    if (is_array($extra)) {
                        foreach (['temperature', 'max_tokens', 'timeout', 'profile'] as $hk) {
                            if (array_key_exists($hk, $extra)) {
                                $harnessOpts[$hk] = $extra[$hk];
                            }
                        }
                    }
                    $fixtureId = trim((string)($case['fixture'] ?? ''));
                    if ($fixtureId !== '' && class_exists('AssistantEvalFixtures', false)) {
                        $stubs = AssistantEvalFixtures::toolStubs($fixtureId);
                        if ($stubs === null) {
                            $row['status'] = 'fail';
                            $row['detail'] = t('assistant.eval.err.fixture', ['id' => $fixtureId]);
                            $row['answer'] = $row['detail'];
                            $row['ms'] = (int)(microtime(true) * 1000) - $c0;
                            return ['row' => $row, 'profile' => null, 'system_prompt' => $systemPrompt];
                        }
                        $harnessOpts['tool_stubs'] = $stubs;
                    }
                    $result = AssistantHarness::run([
                        ['role' => 'user', 'content' => $prompt],
                    ], $harnessOpts);
                    if (!empty($result['profile'])) {
                        $profile = (string)$result['profile'];
                    }
                    $text = (string)($result['text'] ?? '');
                    $row['answer'] = $text;
                    $row['excerpt'] = self::clip($text, 400);
                    $row['repaired'] = self::traceHasOp($result, '_link_repair');
                    if (empty($result['ok'])) {
                        $err = trim((string)($result['error'] ?? ''));
                        if ($err !== '' && (stripos($err, 'config') !== false || stripos($err, 'token') !== false)) {
                            $row['status'] = 'skip';
                            $row['detail'] = $err;
                            if ($row['answer'] === '') {
                                $row['answer'] = $err;
                            }
                        } else {
                            $row['status'] = 'fail';
                            $row['detail'] = $err !== '' ? $err : 'harness failed';
                        }
                    } else {
                        $grade = $case['grade'] ?? null;
                        if (!is_callable($grade)) {
                            $row['status'] = 'skip';
                            $row['detail'] = 'no grader';
                        } else {
                            $g = $grade($result);
                            $row = self::applyGrade($row, is_array($g) ? $g : []);
                            if ($row['answer'] === '') {
                                $row['answer'] = $text;
                            }
                            // Keep repaired flag after grade (pass still counts as pass).
                            $row['repaired'] = self::traceHasOp($result, '_link_repair');
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $row['status'] = 'fail';
            $row['detail'] = $e->getMessage();
            if ($row['answer'] === '') {
                $row['answer'] = $row['detail'];
            }
        }
        $row['ms'] = (int)(microtime(true) * 1000) - $c0;
        return ['row' => $row, 'profile' => $profile, 'system_prompt' => $systemPrompt];
    }

    /**
     * @param list<array<string,mixed>> $ops
     * @param list<string>|null $allow exact ids, or "prefix.*" / "prefix" for prefix match
     * @return list<array<string,mixed>>
     */
    public static function filterOps(array $ops, ?array $allow): array
    {
        if ($allow === null) {
            return $ops;
        }
        if ($allow === []) {
            return [];
        }
        $out = [];
        foreach ($ops as $op) {
            if (!is_array($op)) {
                continue;
            }
            $id = (string)($op['id'] ?? '');
            if ($id === '') {
                continue;
            }
            foreach ($allow as $rule) {
                $rule = trim((string)$rule);
                if ($rule === '') {
                    continue;
                }
                if ($rule === $id) {
                    $out[] = $op;
                    break;
                }
                if (substr($rule, -2) === '.*') {
                    $prefix = substr($rule, 0, -2);
                    if ($prefix !== '' && strpos($id, $prefix . '.') === 0) {
                        $out[] = $op;
                        break;
                    }
                } elseif (strpos($id, $rule . '.') === 0) {
                    $out[] = $op;
                    break;
                }
            }
        }
        return $out;
    }

    public static function textHasPath(string $text, string $path): bool
    {
        $path = '/' . trim($path, '/');
        if ($path === '/' || $text === '') {
            return false;
        }
        $pos = 0;
        $len = strlen($path);
        while (($p = strpos($text, $path, $pos)) !== false) {
            $next = $p + $len;
            $ch = $next < strlen($text) ? $text[$next] : '';
            if ($ch === '' || strpos("/?#)]'\".,;! \t\n\r", $ch) !== false) {
                return true;
            }
            $pos = $p + 1;
        }
        return false;
    }

    /** True if text has /finance that is not /finance/balance (or deeper). */
    /** @param array<string,mixed> $result harness result */
    public static function traceHasOp(array $result, string $opId): bool
    {
        $trace = is_array($result['tool_trace'] ?? null) ? $result['tool_trace'] : [];
        foreach ($trace as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string)($row['op_id'] ?? $row['id'] ?? '') === $opId) {
                return true;
            }
        }
        return false;
    }

    public static function textHasBareFinanceRoot(string $text): bool
    {
        if (!preg_match_all('#/finance(?:/[a-zA-Z0-9_-]+)?#', $text, $m)) {
            return false;
        }
        foreach ($m[0] as $hit) {
            if ($hit === '/finance') {
                return true;
            }
        }
        return false;
    }

    /**
     * Model asks for surname / id / clarification instead of resolving via tools.
     * Used by live.workweeks_resolve_names (and similar) graders.
     */
    public static function textAsksStaffClarify(string $text): bool
    {
        $t = trim($text);
        if ($t === '') {
            return false;
        }
        return (bool)preg_match(
            '/фамили|уточн|не\s+получается\s+определ|однозначно\s+определ|их\s+ID|их\s+id'
            . '|surname|last\s*name|clarif|cannot\s+uniquely|not\s+uniquely|their\s+ID|staff\s+id/iu',
            $t
        );
    }

    /**
     * Shared grader: inbound receipt — resolve stock/supplier/product, queue receipts.create
     * (prefer with lines + non-zero price) or act+ins.
     *
     * @param array<string,mixed> $result harness result
     * @return array{status:string,detail:string,excerpt?:string}
     */
    public static function gradeStickersReceiptInbound(array $result): array
    {
        $text = (string)($result['text'] ?? '');
        $calledStock = self::traceHasOp($result, 'stocks.list');
        $calledSupplier = self::traceHasOp($result, 'suppliers.list');
        $calledCatalog = self::traceHasOp($result, 'catalog.browse')
            || self::traceHasOp($result, 'catalog.browse.folder');
        $queuedReceipt = false;
        $queuedIns = false;
        $hasLines = false;
        $priceOk = false;
        $trace = is_array($result['tool_trace'] ?? null) ? $result['tool_trace'] : [];
        foreach ($trace as $row) {
            if (!is_array($row)) {
                continue;
            }
            $op = (string)($row['op_id'] ?? $row['id'] ?? '');
            $args = is_array($row['args'] ?? null) ? $row['args'] : [];
            if ($op === 'stickers.receipts.create') {
                $queuedReceipt = true;
                $lines = $args['lines'] ?? null;
                if (is_string($lines) && $lines !== '') {
                    $decoded = json_decode($lines, true);
                    $lines = is_array($decoded) ? $decoded : null;
                }
                if (is_array($lines) && $lines !== []) {
                    $hasLines = true;
                    foreach ($lines as $line) {
                        if (!is_array($line)) {
                            continue;
                        }
                        $price = (float)str_replace(',', '.', (string)($line['price'] ?? '0'));
                        $qty = max(1, (int)($line['qty'] ?? 1));
                        if ($price > 0 && abs(($price * $qty) - 6576.0) < 1.0) {
                            $priceOk = true;
                        } elseif ($price > 0) {
                            $priceOk = true;
                        }
                    }
                }
            }
            if ($op === 'stickers.ins.create') {
                $queuedIns = true;
                $price = (float)str_replace(',', '.', (string)($args['price'] ?? '0'));
                if ($price > 0) {
                    $priceOk = true;
                }
            }
        }
        if (!$calledStock && !$calledSupplier && !$calledCatalog && !$queuedReceipt) {
            return [
                'status' => 'fail',
                'detail' => t('assistant.eval.grade.live_stickers_no_tools'),
                'excerpt' => $text,
            ];
        }
        if (!$queuedReceipt && !$queuedIns) {
            return [
                'status' => 'fail',
                'detail' => t('assistant.eval.grade.live_stickers_no_write'),
                'excerpt' => $text,
            ];
        }
        if ($queuedReceipt && !$hasLines && !$queuedIns) {
            return [
                'status' => 'fail',
                'detail' => t('assistant.eval.grade.live_stickers_header_only'),
                'excerpt' => $text,
            ];
        }
        if (!$priceOk) {
            return [
                'status' => 'fail',
                'detail' => t('assistant.eval.grade.live_stickers_price_zero'),
                'excerpt' => $text,
            ];
        }
        $parts = [t('assistant.eval.grade.live_stickers_ok')];
        if ($hasLines) {
            $parts[] = t('assistant.eval.grade.live_stickers_with_lines');
        } elseif ($queuedIns) {
            $parts[] = t('assistant.eval.grade.live_stickers_act_plus_ins');
        }
        return ['status' => 'pass', 'detail' => implode(' · ', $parts)];
    }

    /**
     * Shared grader: must CALL workweeks.day|list before asking surname/ID.
     *
     * @param array<string,mixed> $result harness result
     * @return array{status:string,detail:string,excerpt?:string}
     */
    public static function gradeWorkweeksResolveNames(array $result): array
    {
        $text = (string)($result['text'] ?? '');
        $calledDay = self::traceHasOp($result, 'workweeks.day')
            || self::traceHasOp($result, 'workweeks.list');
        $asksClarify = self::textAsksStaffClarify($text);
        if (!$calledDay && $asksClarify) {
            return [
                'status' => 'fail',
                'detail' => t('assistant.eval.grade.live_ww_clarify_no_tool'),
                'excerpt' => $text,
            ];
        }
        if (!$calledDay) {
            return [
                'status' => 'fail',
                'detail' => t('assistant.eval.grade.live_ww_no_day'),
                'excerpt' => $text,
            ];
        }
        $pending = is_array($result['pending_actions'] ?? null) ? $result['pending_actions'] : [];
        $detail = t('assistant.eval.grade.live_ww_called_day');
        if ($pending !== []) {
            $detail .= ' · ' . t('assistant.eval.grade.live_ww_pending');
        } elseif ($asksClarify) {
            $detail .= ' · ' . t('assistant.eval.grade.live_ww_clarify_after');
        }
        return ['status' => 'pass', 'detail' => $detail];
    }

    /** First enabled staff user id (for CLI when no session). */
    public static function findStaffUserId(): int
    {
        if (!Database::tableExists('base_users')) {
            return 0;
        }
        $pdo = Database::pdo();
        if (Database::tableExists('base_admin_statuses')
            && Database::columnExists('base_admin_statuses', 'SUPERADMIN_ACCESS')
        ) {
            $st = $pdo->query(
                "SELECT u.id FROM base_users u
                 INNER JOIN base_admin_statuses s ON s.ID = u.ID_STATUS
                 WHERE u.enabled = '1' AND s.SUPERADMIN_ACCESS = 1
                 ORDER BY u.id ASC LIMIT 1"
            );
            if ($st) {
                $id = (int)$st->fetchColumn();
                if ($id > 0) {
                    return $id;
                }
            }
        }
        $st = $pdo->query("SELECT id FROM base_users WHERE enabled = '1' ORDER BY id ASC LIMIT 1");
        return $st ? (int)$st->fetchColumn() : 0;
    }

    /**
     * @param array{user_id?:int} $opts
     */
    private static function resolveUserId(array $opts): int
    {
        $userId = isset($opts['user_id']) ? (int)$opts['user_id'] : Auth::userId();
        if ($userId < 1) {
            $userId = self::findStaffUserId();
        }
        return $userId;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{
     *   ok:bool,rows:list<array<string,mixed>>,summary:array{pass:int,fail:int,skip:int,ms:int},
     *   error:string,profile:?string
     * }
     */
    private static function packResult(array $rows, int $t0, ?string $profile, string $error): array
    {
        $summary = ['pass' => 0, 'fail' => 0, 'skip' => 0, 'ms' => (int)(microtime(true) * 1000) - $t0];
        foreach ($rows as $r) {
            $st = (string)($r['status'] ?? 'skip');
            if (isset($summary[$st])) {
                $summary[$st]++;
            }
        }
        return [
            'ok' => $summary['fail'] === 0 && $error === '',
            'rows' => $rows,
            'summary' => $summary,
            'error' => $error,
            'profile' => $profile,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function emptyRow(string $id, string $status, string $detail): array
    {
        return [
            'id' => $id,
            'layer' => '',
            'title' => $id,
            'question' => $id,
            'answer' => $detail,
            'status' => $status,
            'detail' => $detail,
            'ms' => 0,
            'excerpt' => '',
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $g
     * @return array<string,mixed>
     */
    private static function applyGrade(array $row, array $g): array
    {
        $st = (string)($g['status'] ?? 'fail');
        if (!in_array($st, ['pass', 'fail', 'skip'], true)) {
            $st = 'fail';
        }
        $row['status'] = $st;
        $row['detail'] = (string)($g['detail'] ?? '');
        if (isset($g['excerpt'])) {
            $row['excerpt'] = self::clip((string)$g['excerpt'], 400);
        }
        if (isset($g['answer'])) {
            $row['answer'] = (string)$g['answer'];
        }
        return $row;
    }

    private static function clip(string $text, int $max): string
    {
        $text = trim($text);
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $max);
        }
        return substr($text, 0, $max);
    }
}
