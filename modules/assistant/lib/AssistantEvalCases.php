<?php
declare(strict_types=1);

/**
 * Declarative assistant eval cases (deterministic + live). Universal — no LikeSpa user/recipe ids.
 * Live user prompts use prompt_key → t() so they follow the staff locale.
 */
final class AssistantEvalCases
{
    /**
     * @return list<array{
     *   id:string,layer:string,title_key:string,explain_key:string,need_modules:list<string>,
     *   tool_allow:?list<string>,prompt_key:string,grade:callable
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'id' => 'links.finance_root',
                'layer' => 'det',
                'title_key' => 'assistant.eval.case.links_finance_root',
                'explain_key' => 'assistant.eval.case.links_finance_root.explain',
                'need_modules' => ['finance'],
                'tool_allow' => null,
                'prompt_key' => '',
                'grade' => static function (): array {
                    if (!class_exists('AssistantLinks', false)) {
                        return ['status' => 'skip', 'detail' => t('assistant.eval.grade.links_missing')];
                    }
                    $got = AssistantLinks::canonicalizePath('/finance');
                    if ($got !== '/finance/balance') {
                        return [
                            'status' => 'fail',
                            'detail' => t('assistant.eval.grade.finance_root_fail', ['got' => $got]),
                        ];
                    }
                    return ['status' => 'pass', 'detail' => t('assistant.eval.grade.finance_root_ok')];
                },
            ],
            [
                'id' => 'links.payments_root',
                'layer' => 'det',
                'title_key' => 'assistant.eval.case.links_payments_root',
                'explain_key' => 'assistant.eval.case.links_payments_root.explain',
                'need_modules' => ['payments'],
                'tool_allow' => null,
                'prompt_key' => '',
                'grade' => static function (): array {
                    if (!class_exists('AssistantLinks', false)) {
                        return ['status' => 'skip', 'detail' => t('assistant.eval.grade.links_missing')];
                    }
                    $got = AssistantLinks::canonicalizePath('/payments');
                    if ($got !== '/payments') {
                        return [
                            'status' => 'fail',
                            'detail' => t('assistant.eval.grade.payments_root_fail', ['got' => $got]),
                        ];
                    }
                    return ['status' => 'pass', 'detail' => t('assistant.eval.grade.payments_root_ok')];
                },
            ],
            [
                'id' => 'prompt.no_finance_leak',
                'layer' => 'det',
                'title_key' => 'assistant.eval.case.prompt_no_finance_leak',
                'explain_key' => 'assistant.eval.case.prompt_no_finance_leak.explain',
                'need_modules' => [],
                'tool_allow' => null,
                'prompt_key' => '',
                'grade' => static function (): array {
                    $ops = [
                        ['id' => 'payments.list'],
                        ['id' => 'bi.families.list'],
                    ];
                    $hints = AssistantHarness::scopedHintsForOps($ops);
                    // Positive path / finance.balance instruction must not appear; negative "no screen" hint is OK.
                    if (strpos($hints, '/finance') !== false || stripos($hints, 'finance.balance') !== false) {
                        return ['status' => 'fail', 'detail' => t('assistant.eval.grade.finance_leak_fail')];
                    }
                    return ['status' => 'pass', 'detail' => t('assistant.eval.grade.finance_leak_ok')];
                },
            ],
            [
                'id' => 'links.strip_finance_without_tool',
                'layer' => 'det',
                'title_key' => 'assistant.eval.case.links_strip_finance',
                'explain_key' => 'assistant.eval.case.links_strip_finance.explain',
                'need_modules' => [],
                'tool_allow' => null,
                'prompt_key' => '',
                'grade' => static function (): array {
                    if (!class_exists('AssistantLinks', false)) {
                        return ['status' => 'skip', 'detail' => t('assistant.eval.grade.links_missing')];
                    }
                    $ops = [
                        ['id' => 'bi.families.list', 'path' => '/bi'],
                        ['id' => 'bi.report.run', 'path' => '/bi/work'],
                        ['id' => 'payments.list', 'path' => '/payments'],
                    ];
                    $raw = 'See [Balance](/finance/balance) and [Shares](/bi/shares) and [Run](/bi/run?family=demo).';
                    $bad = AssistantLinks::findUnauthorizedHrefs($raw, $ops);
                    if ($bad === []) {
                        return [
                            'status' => 'fail',
                            'detail' => t('assistant.eval.grade.find_bad_fail'),
                            'answer' => '(none)',
                        ];
                    }
                    $hints = AssistantLinks::allowedLinkHints($ops, [
                        [
                            'role' => 'tool',
                            'content' => json_encode([
                                'families' => [
                                    ['id' => 'demo', 'open_url' => '/bi/run?family=demo'],
                                ],
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                    ]);
                    $hasOpen = false;
                    foreach ($hints as $h) {
                        if (strpos($h, '/bi/run') !== false) {
                            $hasOpen = true;
                            break;
                        }
                    }
                    if (!$hasOpen) {
                        return [
                            'status' => 'fail',
                            'detail' => t('assistant.eval.grade.link_hints_fail'),
                            'answer' => implode("\n", $hints),
                        ];
                    }
                    $out = AssistantLinks::filterUnauthorized(
                        AssistantLinks::absolutize($raw),
                        $ops
                    );
                    if (AssistantEval::textHasPath($out, '/finance') || AssistantEval::textHasPath($out, '/bi/shares')) {
                        return [
                            'status' => 'fail',
                            'detail' => t('assistant.eval.grade.strip_finance_fail'),
                            'answer' => $out,
                        ];
                    }
                    if (!AssistantEval::textHasPath($out, '/bi/run')) {
                        return [
                            'status' => 'fail',
                            'detail' => t('assistant.eval.grade.strip_finance_kept_fail'),
                            'answer' => $out,
                        ];
                    }
                    return [
                        'status' => 'pass',
                        'detail' => t('assistant.eval.grade.strip_finance_ok'),
                        'answer' => $out . "\n---\nbad: " . implode(', ', $bad),
                    ];
                },
            ],
            [
                'id' => 'prompt.bi_when_listed',
                'layer' => 'det',
                'title_key' => 'assistant.eval.case.prompt_bi_when_listed',
                'explain_key' => 'assistant.eval.case.prompt_bi_when_listed.explain',
                'need_modules' => ['bi'],
                'tool_allow' => null,
                'prompt_key' => '',
                'grade' => static function (): array {
                    $with = AssistantHarness::scopedHintsForOps([['id' => 'bi.families.list']]);
                    $without = AssistantHarness::scopedHintsForOps([['id' => 'payments.list']]);
                    if (stripos($with, 'open_url') === false && stripos($with, 'bi.') === false && stripos($with, 'BI') === false) {
                        return ['status' => 'fail', 'detail' => t('assistant.eval.grade.bi_hint_missing')];
                    }
                    if (stripos($without, 'open_url') !== false || preg_match('/\bbi\.families\b/i', $without)) {
                        return ['status' => 'fail', 'detail' => t('assistant.eval.grade.bi_hint_leaked')];
                    }
                    return ['status' => 'pass', 'detail' => t('assistant.eval.grade.bi_hint_ok')];
                },
            ],
            [
                'id' => 'prompt.workweeks_hint',
                'layer' => 'det',
                'title_key' => 'assistant.eval.case.prompt_workweeks_hint',
                'explain_key' => 'assistant.eval.case.prompt_workweeks_hint.explain',
                'need_modules' => ['workweeks'],
                'tool_allow' => null,
                'prompt_key' => '',
                'grade' => static function (): array {
                    $with = AssistantHarness::scopedHintsForOps([['id' => 'workweeks.day']]);
                    $without = AssistantHarness::scopedHintsForOps([['id' => 'payments.list']]);
                    if (stripos($with, 'workweeks.day') === false && stripos($with, 'operator_name') === false) {
                        return ['status' => 'fail', 'detail' => t('assistant.eval.grade.workweeks_hint_missing')];
                    }
                    if (stripos($without, 'workweeks.day') !== false || stripos($without, 'operator_name') !== false) {
                        return ['status' => 'fail', 'detail' => t('assistant.eval.grade.workweeks_hint_leaked')];
                    }
                    return ['status' => 'pass', 'detail' => t('assistant.eval.grade.workweeks_hint_ok')];
                },
            ],
            [
                'id' => 'live.finance_path',
                'layer' => 'live',
                'title_key' => 'assistant.eval.case.live_finance_path',
                'explain_key' => 'assistant.eval.case.live_finance_path.explain',
                'need_modules' => ['finance'],
                'tool_allow' => ['finance.balance', 'core.help.search', 'core.help.get'],
                'prompt_key' => 'assistant.eval.prompt.live_finance_path',
                'grade' => static function (array $result): array {
                    $text = (string)($result['text'] ?? '');
                    if (!AssistantEval::textHasPath($text, '/finance/balance')) {
                        return [
                            'status' => 'fail',
                            'detail' => t('assistant.eval.grade.live_finance_missing'),
                            'excerpt' => $text,
                        ];
                    }
                    if (AssistantEval::textHasBareFinanceRoot($text)) {
                        return [
                            'status' => 'fail',
                            'detail' => t('assistant.eval.grade.live_finance_bare'),
                            'excerpt' => $text,
                        ];
                    }
                    return ['status' => 'pass', 'detail' => t('assistant.eval.grade.live_finance_ok')];
                },
            ],
            [
                'id' => 'live.bi_not_shares',
                'layer' => 'live',
                'title_key' => 'assistant.eval.case.live_bi_not_shares',
                'explain_key' => 'assistant.eval.case.live_bi_not_shares.explain',
                'need_modules' => ['bi'],
                'tool_allow' => [
                    'bi.families.list',
                    'bi.report.run',
                    'bi.share.list',
                    'core.help.search',
                    'core.help.get',
                ],
                'prompt_key' => 'assistant.eval.prompt.live_bi_not_shares',
                'grade' => static function (array $result): array {
                    $text = (string)($result['text'] ?? '');
                    $hasRun = AssistantEval::textHasPath($text, '/bi/run');
                    $hasShares = AssistantEval::textHasPath($text, '/bi/shares');
                    if ($hasShares && !$hasRun) {
                        return [
                            'status' => 'fail',
                            'detail' => t('assistant.eval.grade.live_bi_shares_only'),
                            'excerpt' => $text,
                        ];
                    }
                    if (!$hasRun && !$hasShares) {
                        if (AssistantEval::textHasPath($text, '/bi')) {
                            return ['status' => 'pass', 'detail' => t('assistant.eval.grade.live_bi_catalog')];
                        }
                        return [
                            'status' => 'fail',
                            'detail' => t('assistant.eval.grade.live_bi_no_path'),
                            'excerpt' => $text,
                        ];
                    }
                    return ['status' => 'pass', 'detail' => t('assistant.eval.grade.live_bi_ok')];
                },
            ],
            [
                'id' => 'live.no_finance_without_tool',
                'layer' => 'live',
                'title_key' => 'assistant.eval.case.live_no_finance_without_tool',
                'explain_key' => 'assistant.eval.case.live_no_finance_without_tool.explain',
                'need_modules' => [],
                'tool_allow' => [
                    'payments.list',
                    'bi.families.list',
                    'bi.report.run',
                    'core.help.search',
                    'core.help.get',
                ],
                'prompt_key' => 'assistant.eval.prompt.live_no_finance_without_tool',
                'grade' => static function (array $result): array {
                    $text = (string)($result['text'] ?? '');
                    if (AssistantEval::textHasPath($text, '/finance')) {
                        return [
                            'status' => 'fail',
                            'detail' => t('assistant.eval.grade.live_no_finance_fail'),
                            'excerpt' => $text,
                        ];
                    }
                    $detail = t('assistant.eval.grade.live_no_finance_ok');
                    if (AssistantEval::traceHasOp($result, '_link_repair')) {
                        $detail .= ' · ' . t('assistant.eval.grade.link_repair_fired');
                    }
                    return ['status' => 'pass', 'detail' => $detail];
                },
            ],
            [
                'id' => 'live.staff_bi_shared',
                'layer' => 'live',
                'title_key' => 'assistant.eval.case.live_staff_bi_shared',
                'explain_key' => 'assistant.eval.case.live_staff_bi_shared.explain',
                'need_modules' => ['bi'],
                'tool_allow' => [
                    'bi.families.list',
                    'bi.report.run',
                    'core.help.search',
                    'core.help.get',
                ],
                'prompt_key' => 'assistant.eval.prompt.live_staff_bi_shared',
                'grade' => static function (array $result): array {
                    $text = (string)($result['text'] ?? '');
                    $trace = is_array($result['tool_trace'] ?? null) ? $result['tool_trace'] : [];
                    $calledList = false;
                    foreach ($trace as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $op = (string)($row['op_id'] ?? $row['id'] ?? '');
                        if ($op === 'bi.families.list') {
                            $calledList = true;
                        }
                    }
                    if (AssistantEval::textHasPath($text, '/finance/balance') || AssistantEval::textHasPath($text, '/finance')) {
                        return [
                            'status' => 'fail',
                            'detail' => t('assistant.eval.grade.live_staff_finance'),
                            'excerpt' => $text,
                        ];
                    }
                    $hasShares = AssistantEval::textHasPath($text, '/bi/shares');
                    $hasRun = AssistantEval::textHasPath($text, '/bi/run');
                    $hasBi = AssistantEval::textHasPath($text, '/bi');
                    if ($hasShares && !$hasRun && !$calledList) {
                        return [
                            'status' => 'fail',
                            'detail' => t('assistant.eval.grade.live_staff_shares'),
                            'excerpt' => $text,
                        ];
                    }
                    if (!$hasRun && !$hasBi && !$calledList) {
                        return [
                            'status' => 'fail',
                            'detail' => t('assistant.eval.grade.live_staff_no_bi'),
                            'excerpt' => $text,
                        ];
                    }
                    return [
                        'status' => 'pass',
                        'detail' => $calledList
                            ? t('assistant.eval.grade.live_staff_called_list')
                            : t('assistant.eval.grade.live_staff_bi_path'),
                    ];
                },
            ],
            [
                'id' => 'det.confirm_phrase_podtverzhdaju',
                'layer' => 'det',
                'title_key' => 'assistant.eval.case.confirm_phrase',
                'explain_key' => 'assistant.eval.case.confirm_phrase.explain',
                'need_modules' => [],
                'tool_allow' => null,
                'prompt_key' => '',
                'grade' => static function (): array {
                    if (AssistantHarness::detectConfirmIntent('подтверждаю') !== 'confirm') {
                        return ['status' => 'fail', 'detail' => t('assistant.eval.grade.confirm_phrase_fail')];
                    }
                    if (AssistantHarness::detectConfirmIntent('подтвердить') !== 'confirm') {
                        return ['status' => 'fail', 'detail' => t('assistant.eval.grade.confirm_phrase_fail')];
                    }
                    if (AssistantHarness::detectConfirmIntent('отмена') !== 'cancel') {
                        return ['status' => 'fail', 'detail' => t('assistant.eval.grade.confirm_phrase_cancel_fail')];
                    }
                    if (AssistantHarness::detectConfirmIntent('да подтверждаю') !== null) {
                        return ['status' => 'fail', 'detail' => t('assistant.eval.grade.confirm_phrase_loose')];
                    }
                    return ['status' => 'pass', 'detail' => t('assistant.eval.grade.confirm_phrase_ok')];
                },
            ],
            [
                'id' => 'prompt.stickers_receipt_hint',
                'layer' => 'det',
                'title_key' => 'assistant.eval.case.prompt_stickers_receipt_hint',
                'explain_key' => 'assistant.eval.case.prompt_stickers_receipt_hint.explain',
                'need_modules' => ['stickers'],
                'tool_allow' => null,
                'prompt_key' => '',
                'grade' => static function (): array {
                    $with = AssistantHarness::scopedHintsForOps([['id' => 'stickers.receipts.create']]);
                    $without = AssistantHarness::scopedHintsForOps([['id' => 'payments.list']]);
                    if (stripos($with, 'receipts.create') === false && stripos($with, 'lines') === false) {
                        return ['status' => 'fail', 'detail' => t('assistant.eval.grade.stickers_hint_missing')];
                    }
                    if (stripos($without, 'receipts.create') !== false || stripos($without, 'заприход') !== false) {
                        return ['status' => 'fail', 'detail' => t('assistant.eval.grade.stickers_hint_leaked')];
                    }
                    return ['status' => 'pass', 'detail' => t('assistant.eval.grade.stickers_hint_ok')];
                },
            ],
            [
                'id' => 'live.stickers_receipt_inbound',
                'layer' => 'live',
                'title_key' => 'assistant.eval.case.live_stickers_receipt_inbound',
                'explain_key' => 'assistant.eval.case.live_stickers_receipt_inbound.explain',
                'need_modules' => ['stickers', 'catalog', 'stocks', 'suppliers'],
                'fixture' => 'stickers_receipt_inbound',
                'tool_allow' => [
                    'stocks.list',
                    'suppliers.list',
                    'catalog.browse',
                    'stickers.receipts.create',
                    'stickers.ins.create',
                    'core.help.search',
                    'core.help.get',
                ],
                'prompt_key' => 'assistant.eval.prompt.live_stickers_receipt_inbound',
                'grade' => static function (array $result): array {
                    return AssistantEval::gradeStickersReceiptInbound($result);
                },
            ],
            [
                'id' => 'live.workweeks_resolve_names',
                'layer' => 'live',
                'title_key' => 'assistant.eval.case.live_workweeks_resolve_names',
                'explain_key' => 'assistant.eval.case.live_workweeks_resolve_names.explain',
                'need_modules' => ['workweeks'],
                'fixture' => 'workweeks_resolve_names',
                'tool_allow' => [
                    'workweeks.day',
                    'workweeks.list',
                    'workweeks.get',
                    'workweeks.update',
                    'workweeks.create',
                    'core.users.list',
                    'core.help.search',
                    'core.help.get',
                ],
                'prompt_key' => 'assistant.eval.prompt.live_workweeks_resolve_names',
                'grade' => static function (array $result): array {
                    return AssistantEval::gradeWorkweeksResolveNames($result);
                },
            ],
            [
                'id' => 'live.workweeks_resolve_names_two_tanyas',
                'layer' => 'live',
                'title_key' => 'assistant.eval.case.live_workweeks_two_tanyas',
                'explain_key' => 'assistant.eval.case.live_workweeks_two_tanyas.explain',
                'need_modules' => ['workweeks'],
                'fixture' => 'workweeks_resolve_names_two_tanyas',
                'tool_allow' => [
                    'workweeks.day',
                    'workweeks.list',
                    'workweeks.get',
                    'workweeks.update',
                    'workweeks.create',
                    'core.users.list',
                    'core.help.search',
                    'core.help.get',
                ],
                'prompt_key' => 'assistant.eval.prompt.live_workweeks_resolve_names',
                'grade' => static function (array $result): array {
                    return AssistantEval::gradeWorkweeksResolveNames($result);
                },
            ],
        ];
    }
}
