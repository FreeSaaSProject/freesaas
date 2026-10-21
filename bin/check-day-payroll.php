#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * DayPayroll regressions: no unlocked SELECT fallback after findForUpdate failure.
 * Exit: 0 ok, 1 fail; SKIP if payments disabled.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$failed = 0;
$assert = static function (bool $ok, string $msg) use (&$failed): void {
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $failed++;
    }
};

if (!ModuleManager::isEnabled('payments') || !class_exists('DayPayroll', false)) {
    echo "SKIP payments disabled\n";
    exit(0);
}

$src = (string)file_get_contents(base_path('modules/payments/lib/DayPayroll.php'));
// After findForUpdate catch, must not fall back to PaymentsRepository::find for cancel/confirm.
$assert(
    strpos($src, "return ['ok' => false, 'msg' => t('payments.bot.day_pay.lock_failed')];") !== false,
    'lock_failed return present'
);
$assert(
    preg_match(
        '/findForUpdate\(\$paymentId\);\s*\}\s*catch\s*\(Throwable\s*\$e\)\s*\{[^}]*PaymentsRepository::find\(/s',
        $src
    ) !== 1,
    'no PaymentsRepository::find fallback after findForUpdate catch'
);

$assert(class_exists('PaymentsRepository', false), 'PaymentsRepository loaded');
$assert(method_exists('PaymentsRepository', 'findForUpdate'), 'findForUpdate exists');
$assert(te('payments.bot.day_pay.lock_failed') !== 'payments.bot.day_pay.lock_failed', 'lock_failed i18n');

if ($failed > 0) {
    echo "FAILED ({$failed})\n";
    exit(1);
}
echo "OK\n";
exit(0);
