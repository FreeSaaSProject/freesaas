#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Certificate concurrency + abon undo regressions (needs DB, certificates tables).
 *
 * Exit 0 ok / skip, 1 fail.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$fail = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$fail): void {
    echo ($ok ? 'OK  ' : 'FAIL') . ' ' . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
};

if (!ModuleManager::isEnabled('certificates')
    || !class_exists('CertificatesRepository', false)
    || !CertificatesRepository::tableReady()
    || !CertificateLedgerRepository::tableReady()
) {
    echo "SKIP certificates module/tables not ready\n";
    exit(0);
}

$pdo = Database::pdo();
$code = 'T' . strtoupper(bin2hex(random_bytes(5)));
$face = 100.0;
$clientId = 1;
$now = time();

$certId = CertificatesRepository::create([
    'code' => $code,
    'id_operator' => 0,
    'id_buyer' => $clientId,
    'id_holder' => $clientId,
    'id_event_create' => 0,
    'kind' => CertificatesRepository::KIND_MONEY,
    'sale_amount' => $face,
    'face_value' => $face,
    'dt_create' => $now,
    'dt_activation_expire' => $now + 86400 * 180,
    'dt_use_expire' => $now + 86400 * 360,
    'expired' => 0,
    'partial_forbidden' => 0,
    'bonus_forbidden' => 0,
    'use_limit_pct' => 0,
    'type_ids' => [],
    'product_ids' => [],
    'content' => [],
    'descr' => 'check-certificates concurrency',
]);
CertificateProcessor::issue($certId, 0);

$orderA = 900000000 + random_int(1, 99999);
$orderB = $orderA + 1;

// Two child CLI processes (separate connections) racing applyOrder on one cert.
$tmpDir = sys_get_temp_dir() . '/freesaas_cert_' . getmypid();
@mkdir($tmpDir, 0700, true);
$script = $tmpDir . '/worker.php';
file_put_contents($script, <<<'PHP'
<?php
declare(strict_types=1);
require_once $argv[1];
$orderId = (int)$argv[2];
$code = (string)$argv[3];
$clientId = (int)$argv[4];
$summ = (float)$argv[5];
$outFile = (string)$argv[6];
$r = CertificateProcessor::applyOrder($orderId, $code, $clientId, [
    ['id' => 1, 'price' => $summ],
], $summ, 0);
file_put_contents($outFile, json_encode($r, JSON_UNESCAPED_UNICODE));
PHP
);

$bootstrap = dirname(__DIR__) . '/app/bootstrap.php';
$outA = $tmpDir . '/a.json';
$outB = $tmpDir . '/b.json';
$cmdA = [
    PHP_BINARY, $script, $bootstrap, (string)$orderA, $code, (string)$clientId, (string)$face, $outA,
];
$cmdB = [
    PHP_BINARY, $script, $bootstrap, (string)$orderB, $code, (string)$clientId, (string)$face, $outB,
];

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', $tmpDir . '/stdout.log', 'a'],
    2 => ['file', $tmpDir . '/stderr.log', 'a'],
];
$pA = proc_open($cmdA, $descriptors, $pipesA, dirname(__DIR__));
$pB = proc_open($cmdB, $descriptors, $pipesB, dirname(__DIR__));
if (!is_resource($pA) || !is_resource($pB)) {
    $check('spawn_workers', false, 'proc_open failed');
} else {
    foreach ([$pipesA[0] ?? null, $pipesB[0] ?? null] as $in) {
        if (is_resource($in)) {
            fclose($in);
        }
    }
    $codeA = proc_close($pA);
    $codeB = proc_close($pB);
    $ra = is_file($outA) ? json_decode((string)file_get_contents($outA), true) : null;
    $rb = is_file($outB) ? json_decode((string)file_get_contents($outB), true) : null;
    $check('worker_exit_a', $codeA === 0, 'exit=' . $codeA);
    $check('worker_exit_b', $codeB === 0, 'exit=' . $codeB);
    $okA = is_array($ra) && !empty($ra['ok']);
    $okB = is_array($rb) && !empty($rb['ok']);
    $spendA = is_array($ra) ? (float)($ra['spend'] ?? 0) : 0.0;
    $spendB = is_array($rb) ? (float)($rb['spend'] ?? 0) : 0.0;
    $successes = ($okA ? 1 : 0) + ($okB ? 1 : 0);
    $totalSpend = round($spendA + $spendB, 2);
    $bal = CertificateLedgerRepository::balance($certId);
    $check(
        'two_orders_one_cert_no_overspend',
        $totalSpend <= $face + 0.009 && $bal >= -0.009 && $successes >= 1,
        sprintf('okA=%s spendA=%.2f okB=%s spendB=%.2f total=%.2f bal=%.2f',
            $okA ? '1' : '0', $spendA, $okB ? '1' : '0', $spendB, $totalSpend, $bal)
    );
    // With face=100 and each wanting 100, at most one full success (or both partial — we use full).
    $check(
        'at_most_one_full_spend',
        $totalSpend <= $face + 0.009,
        'total_spend=' . $totalSpend
    );
}

// Abon: undo earlier spend must restore that usage entry, not array_pop last.
$abonCode = 'A' . strtoupper(bin2hex(random_bytes(5)));
$abonId = CertificatesRepository::create([
    'code' => $abonCode,
    'id_operator' => 0,
    'id_buyer' => $clientId,
    'id_holder' => $clientId,
    'id_event_create' => 0,
    'kind' => CertificatesRepository::KIND_ABON,
    'sale_amount' => 200.0,
    'face_value' => 200.0,
    'dt_create' => $now,
    'dt_activation_expire' => $now + 86400 * 180,
    'dt_use_expire' => $now + 86400 * 360,
    'expired' => 0,
    'partial_forbidden' => 0,
    'bonus_forbidden' => 0,
    'use_limit_pct' => 0,
    'type_ids' => [],
    'product_ids' => [],
    'content' => [
        ['product_id' => 101, 'qty' => 2, 'price' => 100],
        ['product_id' => 202, 'qty' => 2, 'price' => 100],
    ],
    'descr' => 'check-certificates abon undo',
]);
CertificateProcessor::issue($abonId, 0);

$spend1 = CertificateLedgerRepository::insert([
    'id_cert' => $abonId,
    'id_operator' => 0,
    'id_client' => $clientId,
    'id_event' => 1,
    'direction' => CertificateLedgerRepository::DIR_DEBIT,
    'amount' => 100.0,
    'reason' => CertificateLedgerRepository::REASON_SPEND,
    'descr' => 'first',
]);
$spend2 = CertificateLedgerRepository::insert([
    'id_cert' => $abonId,
    'id_operator' => 0,
    'id_client' => $clientId,
    'id_event' => 2,
    'direction' => CertificateLedgerRepository::DIR_DEBIT,
    'amount' => 100.0,
    'reason' => CertificateLedgerRepository::REASON_SPEND,
    'descr' => 'second',
]);
CertificatesRepository::updateUsage($abonId, 200.0, 0, [
    ['ts' => $now, 'admin_id' => 0, 'goods' => [101 => 1], 'ledger_id' => $spend1],
    ['ts' => $now + 1, 'admin_id' => 0, 'goods' => [202 => 1], 'ledger_id' => $spend2],
]);

$undo = CertificateProcessor::undoSpend($spend1, 0);
$check('undo_first_spend_ok', !empty($undo['ok']), (string)($undo['error'] ?? ''));
$abon = CertificatesRepository::find($abonId);
$used = is_array($abon['content_used'] ?? null) ? $abon['content_used'] : [];
$idsLeft = [];
foreach ($used as $e) {
    $idsLeft[] = (int)($e['ledger_id'] ?? 0);
}
$check(
    'abon_undo_keeps_later_usage',
    count($used) === 1 && in_array($spend2, $idsLeft, true) && !in_array($spend1, $idsLeft, true),
    'left=' . json_encode($idsLeft)
);
$remain = null;
$m = new ReflectionMethod(CertificateProcessor::class, 'abonRemaining');
$m->setAccessible(true);
if (is_array($abon)) {
    $remain = $m->invoke(null, $abon);
}
$check(
    'abon_remaining_restored_101',
    is_array($remain) && (int)($remain[101] ?? 0) === 2 && (int)($remain[202] ?? 0) === 1,
    is_array($remain) ? json_encode($remain) : 'null'
);

// Cleanup disposable rows.
try {
    $pdo->prepare('DELETE FROM base_certificate_ledger WHERE ID_CERT IN (?,?)')->execute([$certId, $abonId]);
    $pdo->prepare('DELETE FROM base_certificates WHERE ID IN (?,?)')->execute([$certId, $abonId]);
} catch (Throwable $e) {
    echo 'WARN cleanup: ' . $e->getMessage() . PHP_EOL;
}
foreach ([$script, $outA, $outB, $tmpDir . '/stdout.log', $tmpDir . '/stderr.log'] as $f) {
    if (is_file($f)) {
        @unlink($f);
    }
}
@rmdir($tmpDir);

exit($fail > 0 ? 1 : 0);
