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

if ($failed > 0) {
    echo "FAILED ({$failed})\n";
    exit(1);
}
echo "OK\n";
exit(0);
