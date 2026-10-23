#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Assistant pending confirm: wrong user denied; owner cancel ok.
 * Exit: 0 ok, 1 fail; SKIP if assistant / table missing.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$failed = 0;
$assert = static function (bool $ok, string $msg) use (&$failed): void {
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $failed++;
    }
};

if (!ModuleManager::isEnabled('assistant')
    || !class_exists('PendingActionsRepository', false)
    || !class_exists('AssistantHarness', false)
    || !PendingActionsRepository::tableReady()
) {
    echo "SKIP assistant pending unavailable\n";
    exit(0);
}

$ownerId = 900001;
$otherId = 900002;
$created = PendingActionsRepository::create(
    $ownerId,
    'test.noop',
    ['ping' => 1],
    'check-assistant-confirm',
    ['confirm_mode' => 'self']
);
$assert(is_array($created) && ($created['token'] ?? '') !== '', 'pending created');
$token = (string)($created['token'] ?? '');

$deny = AssistantHarness::resolvePending($token, 'cancel', $otherId);
$assert(empty($deny['ok']), 'wrong user denied');
$assert((string)($deny['error'] ?? '') !== '', 'deny has error message');

$ok = AssistantHarness::resolvePending($token, 'cancel', $ownerId);
$assert(!empty($ok['ok']), 'owner cancel ok');
$row = PendingActionsRepository::findByToken($token);
$assert(strtolower((string)($row['STATUS'] ?? '')) === 'cancelled', 'status cancelled');

if (Database::columnExists('base_assistant_pending', 'PLAN_ID')) {
    $mkPlan = static function (int $n, array $meta, int $owner = 0) use ($ownerId): array {
        $owner = $owner > 0 ? $owner : $ownerId;
        $planId = bin2hex(random_bytes(8));
        $tokens = [];
        for ($i = 1; $i <= $n; $i++) {
            $r = PendingActionsRepository::create($owner, 'test.noop', ['step' => $i], 'plan step ' . $i,
                array_merge($meta, ['plan_id' => $planId, 'plan_step' => $i]));
            $tokens[] = (string)($r['token'] ?? '');
        }
        return $tokens;
    };
    $status = static function (string $tok): string {
        $r = PendingActionsRepository::findByToken($tok);
        return strtolower((string)($r['STATUS'] ?? ''));
    };

    // Unknown op fails on step 1 → later steps are not run (cancelled as skipped).
    $plan = $mkPlan(3, ['confirm_mode' => 'self']);
    $res = AssistantHarness::resolvePlan($plan[1], 'confirm', $ownerId);
    $assert((int)($res['failed'] ?? 0) === 1 && (int)($res['skipped'] ?? 0) === 2, 'plan stops on first failure');
    $assert($status($plan[0]) === 'failed' && $status($plan[2]) === 'cancelled', 'plan statuses failed/cancelled');

    $plan = $mkPlan(2, ['confirm_mode' => 'self']);
    $res = AssistantHarness::resolvePlan($plan[0], 'cancel', $ownerId);
    $assert(!empty($res['ok']) && (int)($res['done'] ?? 0) === 2, 'plan cancel resolves all steps');

    // Capability plan: confirmer lacking any step permission runs nothing.
    $plan = $mkPlan(2, ['confirm_mode' => 'capability', 'required_permission' => 'check.nonexistent.perm']);
    $res = AssistantHarness::resolvePlan($plan[0], 'confirm', $otherId);
    $assert(!empty($res['forbidden_capability']), 'plan preflight denies missing permission');
    $assert($status($plan[0]) === 'pending' && $status($plan[1]) === 'pending', 'plan untouched after deny');
    AssistantHarness::resolvePlan($plan[0], 'cancel', $otherId);

    // Order: while step 1 is executing, a second confirm must not start step 2.
    $plan = $mkPlan(2, ['confirm_mode' => 'self']);
    PendingActionsRepository::claimForExecution($plan[0], $ownerId);
    $res = AssistantHarness::resolvePlan($plan[1], 'confirm', $ownerId);
    $assert(empty($res['ok']) && $status($plan[1]) === 'pending', 'second confirm while step 1 executing does not run step 2');
    $res = AssistantHarness::resolvePending($plan[1], 'confirm', $ownerId);
    $assert(!empty($res['plan_blocked']) && $status($plan[1]) === 'pending', 'direct confirm of step 2 blocked while step 1 executing');
    PendingActionsRepository::finalizeExecution($plan[0], 'failed', ['error' => 'test']);
    $res = AssistantHarness::resolvePending($plan[1], 'confirm', $ownerId);
    $assert(!empty($res['plan_blocked']) && $status($plan[1]) === 'pending', 'step 2 blocked after step 1 failed');
    PendingActionsRepository::mark($plan[1], 'cancelled');

    // Predecessors must succeed: cancelled / expired step 1 aborts the plan, step 2 never runs.
    foreach (['cancelled', 'expired_status', 'expired_ttl'] as $how) {
        $plan = $mkPlan(2, ['confirm_mode' => 'self']);
        if ($how === 'cancelled') {
            PendingActionsRepository::mark($plan[0], 'cancelled');
        } elseif ($how === 'expired_status') {
            PendingActionsRepository::mark($plan[0], 'expired');
        } else {
            Database::pdo()->prepare('UPDATE base_assistant_pending SET EXPIRES_AT = ? WHERE TOKEN = ?')
                ->execute([time() - 5, $plan[0]]);
        }
        $res = AssistantHarness::resolvePending($plan[1], 'confirm', $ownerId);
        $assert(!empty($res['plan_broken']) && $status($plan[1]) === 'pending', "step 1 {$how} → direct step 2 refused (plan_broken)");
        $res = AssistantHarness::resolvePlan($plan[1], 'confirm', $ownerId);
        $assert(empty($res['ok']) && $status($plan[1]) === 'cancelled', "step 1 {$how} → plan confirm cancels step 2, nothing runs");
    }

    // Successful predecessor lets the next step run (test.noop then fails on invoke → status failed).
    $plan = $mkPlan(2, ['confirm_mode' => 'self']);
    PendingActionsRepository::claimForExecution($plan[0], $ownerId);
    PendingActionsRepository::finalizeExecution($plan[0], 'confirmed', ['ok' => true]);
    AssistantHarness::resolvePending($plan[1], 'confirm', $ownerId);
    $assert($status($plan[1]) === 'failed', 'step 1 confirmed → step 2 is executed');

    // Ownership: a failed batch must not cancel someone else's self pendings.
    $victim = $mkPlan(2, ['confirm_mode' => 'self'], $ownerId);
    AssistantHarness::resolvePendingBatch($victim, 'confirm', $otherId);
    $assert($status($victim[0]) === 'pending' && $status($victim[1]) === 'pending', 'foreign tokens in batch: not run, not cancelled');
    $own = $mkPlan(1, ['confirm_mode' => 'self'], $otherId);
    AssistantHarness::resolvePendingBatch([$own[0], $victim[0]], 'confirm', $otherId);
    $assert($status($own[0]) === 'failed' && $status($victim[0]) === 'pending', 'own step failure does not cancel a foreign plan');
    AssistantHarness::resolvePendingBatch($victim, 'cancel', $ownerId);
}

if ($failed > 0) {
    echo "FAILED ({$failed})\n";
    exit(1);
}
echo "OK\n";
exit(0);
