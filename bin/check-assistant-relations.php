#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Assistant agent.relations: template engine, conditions, notes for skipped/unavailable checks,
 * per-turn dedupe, validator typo detection. No DB writes.
 * Exit: 0 ok, 1 fail; SKIP if assistant is off.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$failed = 0;
$assert = static function (bool $ok, string $msg) use (&$failed): void {
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $failed++;
    }
};

if (!ModuleManager::isEnabled('assistant') || !class_exists('AssistantRelations', false)) {
    echo "SKIP assistant relations unavailable\n";
    exit(0);
}

// Conditions
$assert(AssistantRelations::conditionMet(['enabled' => '0'], ['enabled' => 0], null) === true, 'if literal 0 (int)');
$assert(AssistantRelations::conditionMet(['enabled' => '0'], ['enabled' => false], null) === true, 'if literal 0 (bool false)');
$assert(AssistantRelations::conditionMet(['enabled' => '0'], ['enabled' => '1'], null) === false, 'if literal mismatch');
$assert(AssistantRelations::conditionMet(['enabled' => '0'], [], null) === false, 'if literal absent');
$assert(AssistantRelations::conditionMet(['id_operator' => 'changed'], ['id_operator' => 5], ['id_operator' => 5]) === false, 'changed: same value');
$assert(AssistantRelations::conditionMet(['id_operator' => 'changed'], ['id_operator' => 7], ['id_operator' => 5]) === true, 'changed: new value');
$assert(AssistantRelations::conditionMet(['id_operator' => 'changed'], ['id_operator' => 7], null) === null, 'changed: undecidable without from');
$assert(AssistantRelations::conditionMet(['date' => 'present'], ['date' => '2026-01-01'], null) === true, 'present');

// Templates
$vars = AssistantRelations::vars(['id' => 3, 'id_operator' => 9], ['id_operator' => 5, 'date' => '2026-11-03', 'dt' => 1793750400]);
$r = AssistantRelations::renderArgs(
    ['staff' => '{from.id_operator}', 'new' => '{id_operator}', 'd' => '{from.dt|date}', 't' => '{today}', 'page' => 'all'],
    $vars
);
$assert($r['missing'] === [], 'render: no missing');
$assert(($r['args']['staff'] ?? '') === '5' && ($r['args']['new'] ?? '') === '9', 'render: from.* keeps old, plain uses new');
$assert(($r['args']['d'] ?? '') === date('Y-m-d', 1793750400), 'render: |date filter');
$assert(($r['args']['t'] ?? '') === date('Y-m-d') && ($r['args']['page'] ?? '') === 'all', 'render: {today} + literal');
$r = AssistantRelations::renderArgs(['staff' => '{user_id}'], AssistantRelations::vars([], null));
$assert($r['missing'] === ['user_id'], 'render: missing placeholder reported');

// inspect() against a real relation (needs workweeks + vacations ops declared).
$rel = null;
foreach (AssistantRelations::all() as $row) {
    if ($row['id'] === 'workweeks.vacation_shifts') {
        $rel = $row;
    }
}
if ($rel === null) {
    echo "SKIP inspect (workweeks.vacation_shifts not enabled)\n";
} else {
    $calls = [];
    $invoke = static function (string $op, array $args) use (&$calls): array {
        $calls[] = $op;
        if ($op === 'vacations.get') {
            return ['ok' => true, 'data' => ['item' => ['id' => 1, 'user_id' => 53, 'dt_start' => 1793750400, 'dt_end' => 1794355200]], 'error' => ''];
        }
        if ($op === 'workweeks.list') {
            return ['ok' => true, 'data' => ['total' => 2, 'items' => [
                ['id' => 101, 'date' => '2026-11-03', 'operator_name' => 'X'],
                ['id' => 102, 'date' => '2026-11-05', 'operator_name' => 'X'],
            ]], 'error' => ''];
        }
        return ['ok' => true, 'data' => ['items' => []], 'error' => ''];
    };
    $catalog = ['vacations.get' => true, 'workweeks.list' => true, 'workweeks.delete' => true];
    $seen = [];
    $out = AssistantRelations::inspect('vacations.update', ['id' => 1, 'dt_start' => '2026-11-03'], $invoke, $catalog, $seen);
    $hit = null;
    foreach ($out as $e) {
        if ($e['relation'] === 'workweeks.vacation_shifts') {
            $hit = $e;
        }
    }
    $assert($hit !== null && (int)($hit['count'] ?? 0) === 2, 'inspect: rows found via from row (user_id)');
    $assert(($hit['check_args']['user_id'] ?? '') === '53', 'inspect: user_id taken from vacations.get');
    $assert(($hit['suggest'] ?? []) === ['workweeks.delete'], 'inspect: suggest available');
    $assert(trim((string)($hit['notice'] ?? '')) !== '', 'inspect: notice line');
    $assert(AssistantRelations::previewLines($out) !== [], 'inspect: preview lines');

    $again = AssistantRelations::inspect('vacations.update', ['id' => 1], $invoke, $catalog, $seen);
    $dup = false;
    foreach ($again as $e) {
        if ($e['relation'] === 'workweeks.vacation_shifts') {
            $dup = true;
        }
    }
    $assert(!$dup, 'inspect: same rows not reported twice in one turn');

    $seen = [];
    $out = AssistantRelations::inspect('vacations.update', ['id' => 1], $invoke, ['vacations.get' => true], $seen);
    $unavail = false;
    foreach ($out as $e) {
        if ($e['relation'] === 'workweeks.vacation_shifts' && !empty($e['check_unavailable'])) {
            $unavail = true;
        }
    }
    $assert($unavail, 'inspect: check op outside catalog → check_unavailable note');

    $seen = [];
    $out = AssistantRelations::inspect('vacations.update', ['id' => 1], $invoke, ['workweeks.list' => true], $seen);
    $skipped = false;
    foreach ($out as $e) {
        if ($e['relation'] === 'workweeks.vacation_shifts' && !empty($e['check_skipped'])) {
            $skipped = true;
        }
    }
    $assert($skipped, 'inspect: from unavailable + missing user_id → check_skipped note (not silent)');
}

// Validator flags a typo but not a merely disabled module.
$m = new ReflectionMethod('ModuleValidator', 'validateRelations');
$m->setAccessible(true);
$errors = [];
$warnings = [];
$m->invokeArgs(null, [[
    ['when' => ['vacations.update'], 'check' => 'workweeks.lst', 'args' => ['x' => '{from.y}']],
], &$errors, &$warnings]);
$joined = implode("\n", $warnings);
$assert(strpos($joined, 'unknown op: workweeks.lst') !== false, 'validator: typo op warned');
$assert(strpos($joined, 'unknown op: vacations.update') === false, 'validator: known op not warned');
$assert(strpos($joined, 'without from') !== false, 'validator: {from.*} without from warned');

if ($failed > 0) {
    echo "FAILED ({$failed})\n";
    exit(1);
}
echo "OK\n";
exit(0);
