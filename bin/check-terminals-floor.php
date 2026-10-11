#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Domain smoke for terminals floor (stub/1C-off path).
 *
 * Covers: claimStaffDay (one staff → one terminal), stub bracelet, procedure
 * book + cancel via TerminalBooking. Not a browser UI test (categories/buttons).
 *
 * Requires: modules terminals + procedures enabled; at least one terminal and
 * one staff user; optional product (first bookable or --product=ID).
 *
 * Usage:
 *   php bin/check-terminals-floor.php
 *   php bin/check-terminals-floor.php --product=9 --terminal=1 --admin=51
 *
 * Exit: 0 ok, 1 fail
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z_]+)=(.+)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    }
}

$failed = 0;
$assert = static function (bool $ok, string $msg) use (&$failed): void {
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $failed++;
    }
};

// Optional product module: skip cleanly when off (CI enables all; other instances often disable).
if (!ModuleManager::isEnabled('terminals')) {
    echo "SKIP terminals module not enabled\n";
    echo "OK\n";
    exit(0);
}
if (!class_exists(TerminalBooking::class, false) || !class_exists(ProceduresRepository::class, false)) {
    echo "SKIP TerminalBooking/procedures unavailable\n";
    echo "OK\n";
    exit(0);
}

$assert(true, '1c_enabled=' . (TerminalsConfig::onecEnabled() ? '1' : '0')
    . ' stub_mode=' . (TerminalsConfig::onecStubMode(false) ? '1' : '0')
    . ' (test forces stub via disable_1c)');

$stub = OnecClient::getBraceletInfo('DEADBEEF', true);
$assert(!empty($stub['ok']) && !empty($stub['stub']), 'OnecClient::getBraceletInfo stub');

$sale = OnecClient::sale('stub-client', 'stub-good', 100.0, true);
$assert(!empty($sale['ok']) && !empty($sale['stub']), 'OnecClient::sale stub');

$unsale = OnecClient::unsale('stub-doc', true);
$assert(!empty($unsale['ok']), 'OnecClient::unsale stub');

$terminalId = (int)($opts['terminal'] ?? TerminalsConfig::string('booking_terminal_id', '0'));
if ($terminalId < 1) {
    $names = TerminalsRepository::names();
    $terminalId = (int)(array_key_first($names) ?? 0);
}
$term = $terminalId > 0 ? TerminalsRepository::find($terminalId, false) : null;

$adminId = (int)($opts['admin'] ?? TerminalsConfig::string('booking_admin_id', '0'));
if ($adminId < 1 && class_exists(UsersRepository::class, false)) {
    $staff = UsersRepository::listEnabledStaff();
    $adminId = (int)(($staff[0]['id'] ?? 0));
}

$productId = (int)($opts['product'] ?? 0);
if ($productId < 1 && class_exists(BookingCatalog::class, false)) {
    $services = BookingCatalog::listServices(20);
    $productId = (int)(($services[0]['id'] ?? 0));
}
if ($productId < 1 && class_exists(ProductsRepository::class, false)) {
    $list = ProductsRepository::list(['enabled' => '1', 'is_material' => '0', 'limit' => 1, 'offset' => 0]);
    $productId = (int)(($list['items'][0]['id'] ?? 0));
}

if ($term === null || $adminId < 1 || $productId < 1) {
    echo "SKIP floor DB scenario (need terminal + staff + product; got terminal="
        . $terminalId . ' admin=' . $adminId . ' product=' . $productId . ")\n";
    echo "OK\n";
    exit(0);
}
$assert(true, 'fixtures terminal=' . $terminalId . ' admin=' . $adminId . ' product=' . $productId);

if ($term !== null && $adminId > 0) {
    $claim = TerminalsRepository::claimStaffDay($terminalId, $adminId);
    $assert(!empty($claim['ok']), 'claimStaffDay');
    $term2 = TerminalsRepository::find($terminalId, false);
    $assert(
        $term2 !== null
        && (int)($term2['id_admin'] ?? 0) === $adminId
        && TerminalsRepository::hasValidStaffClaim($term2),
        'claim visible on terminal'
    );

    // Second terminal (if any): same staff must be cleared there (unless IS_ADMIN_REG_IGNORE).
    $otherId = 0;
    foreach (TerminalsRepository::names() as $tid => $_name) {
        if ((int)$tid !== $terminalId) {
            $otherId = (int)$tid;
            break;
        }
    }
    if ($otherId > 0) {
        $other = TerminalsRepository::find($otherId, false);
        if ($other !== null && (int)($other['is_admin_reg_ignore'] ?? 0) < 1) {
            TerminalsRepository::claimStaffDay($otherId, $adminId);
            $termAfter = TerminalsRepository::find($terminalId, false);
            $assert(
                $termAfter !== null && (int)($termAfter['id_admin'] ?? 0) !== $adminId,
                'one staff → one terminal (cleared on #' . $terminalId . ' after claim #' . $otherId . ')'
            );
            // Restore claim on primary for book step
            TerminalsRepository::claimStaffDay($terminalId, $adminId);
        } else {
            $assert(true, 'skip one-staff-one-terminal (only one usable terminal)');
        }
    } else {
        $assert(true, 'skip one-staff-one-terminal (single terminal)');
    }
}

$dtStart = time() + 3600;
$dtStart -= $dtStart % 300;

$book = TerminalBooking::book([
    'product_id' => $productId,
    'operator_id' => $adminId,
    'admin_id' => $adminId,
    'terminal_id' => $terminalId,
    'dt_start' => $dtStart,
    'client_id' => 0,
    'onec_client_id' => 'stub-check-floor',
    'onec_bracelet' => 'DEADBEEF',
    'disable_1c' => true,
    'notice' => 'check-terminals-floor',
]);
$assert(!empty($book['ok']), 'TerminalBooking::book ' . (string)($book['error'] ?? ''));
$procId = (int)($book['procedure_id'] ?? 0);
$assert($procId > 0, 'procedure_id=' . $procId);

if ($procId > 0) {
    $row = ProceduresRepository::find($procId);
    $assert($row !== null && (int)($row['enabled'] ?? 0) === 1, 'procedure enabled in DB');
    $cancel = TerminalBooking::cancel($procId, true, true);
    $assert(!empty($cancel['ok']), 'TerminalBooking::cancel');
    $row2 = ProceduresRepository::find($procId);
    $assert($row2 !== null && (int)($row2['enabled'] ?? 0) === 0, 'procedure disabled after cancel');
}

$diag = TerminalFloor::diagnose($terminalId > 0 ? $terminalId : null);
$assert(isset($diag['onec']['enabled']), 'TerminalFloor::diagnose has onec.enabled');

echo 'steps: ' . (6 + ($failed === 0 ? 0 : 0)) . "\n";
if ($failed > 0) {
    echo "FAILED ($failed)\n";
    exit(1);
}
echo "OK\n";
exit(0);
