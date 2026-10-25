#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Domain smoke for terminals floor (stub/1C-off path).
 *
 * Covers: claimStaffDay (one staff → one terminal), stub bracelet, procedure
 * book + cancel via TerminalBooking. Not a browser UI test (categories/buttons).
 *
 * Requires: modules terminals + procedures enabled; at least one terminal. Staff/product:
 * someone on today's shift with a service they may perform and a free floor slot
 * (bin/lib/BookingSmokeFixtures.php), or --admin= / --product=; otherwise SKIP with the reason.
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

require_once __DIR__ . '/lib/BookingSmokeFixtures.php';

// Book as someone on today's shift who may perform the service (booking_admin_id may be off shift).
$floorLead = TerminalsConfig::leadSec();
$floorSlotOpts = TerminalsConfig::bookingSlotOpts();
$pair = BookingSmokeFixtures::pickPair((int)mktime(0, 0, 0), [
    'operator' => (int)($opts['admin'] ?? 0),
    'product' => (int)($opts['product'] ?? 0),
    'lead' => $floorLead,
    'slot_opts' => $floorSlotOpts,
]);
$adminId = $pair['operator'];
$productId = $pair['product'];

if ($term === null || $adminId < 1 || $productId < 1) {
    echo 'SKIP floor DB scenario (terminal=' . $terminalId . '; ' . $pair['reason'] . ")\n";
    echo "OK\n";
    exit(0);
}
$assert(true, 'fixtures terminal=' . $terminalId . ' admin=' . $adminId . ' product=' . $productId);
$floorSlots = BookingSmokeFixtures::slots(date('Y-m-d'), $productId, $adminId, $floorLead, $floorSlotOpts);

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

$dtStart = BookingSmokeFixtures::firstSlotFrom($floorSlots, time() + $floorLead);

$smokeClientId = 0;
if (class_exists(ClientsRepository::class, false) && Database::tableExists('base_clients')) {
    $crow = Database::pdo()->query('SELECT id FROM base_clients WHERE enabled = 1 ORDER BY id ASC LIMIT 1')
        ->fetch(PDO::FETCH_ASSOC);
    $smokeClientId = (int)($crow['id'] ?? 0);
}
$assert($smokeClientId > 0, 'smoke client_id for procedure book');

$book = TerminalBooking::book([
    'product_id' => $productId,
    'operator_id' => $adminId,
    'admin_id' => $adminId,
    'terminal_id' => $terminalId,
    'dt_start' => $dtStart,
    'client_id' => $smokeClientId,
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
$assert(isset($diag['discounts']) && is_array($diag['discounts']), 'TerminalFloor::diagnose has discounts');

if (class_exists(TerminalDiscount::class, false)) {
    $p1 = TerminalDiscount::bestPrice(1000.0, 10.0, 1, false, []);
    $assert((int)$p1['pct'] === 10 && abs($p1['price'] - 900.0) < 0.01, 'TerminalDiscount client 10%');
    $p2 = TerminalDiscount::bestPrice(1000.0, 10.0, 5, true, [5 => 50]);
    // promo not configured for type 5 unless SystemFlyerPromo has it — force via mock path:
    // when promoActive but discountForType returns 0, stays at client 10%
    $assert((int)$p2['pct'] === 10, 'TerminalDiscount promo inactive map stays client%');
    $p3 = TerminalDiscount::bestPrice(1000.0, 5.0, 9, true, [9 => 20]);
    // Without TermScansRepository promo map, promo pct is 0 → client wins
    $assert((int)$p3['pct'] === 5 && abs($p3['price'] - 950.0) < 0.01, 'TerminalDiscount flyer/client base');
    $cap = TerminalDiscount::capFlyerByTypes(40.0, [1, 2], [1 => 25, 2 => 30]);
    $assert(abs($cap - 25.0) < 0.01, 'TerminalDiscount::capFlyerByTypes min limit');

    // Rounding: default 0 (whole rubles, oper). 1234 * 0.85 = 1048.9 → 1049.
    $prevDecimals = class_exists(LikespaConfig::class, false)
        ? (string)LikespaConfig::terminalPriceDecimals()
        : '0';
    try {
        if (class_exists(Config::class, false)) {
            Config::set('likespa', 'terminal_price_decimals', '0');
        }
        $r0 = TerminalDiscount::bestPrice(1234.0, 15.0, 1, false, []);
        $assert(abs($r0['price'] - 1049.0) < 0.01, 'TerminalDiscount decimals=0 → 1049 (not 1048.9)');
        if (class_exists(Config::class, false)) {
            Config::set('likespa', 'terminal_price_decimals', '2');
        }
        $r2 = TerminalDiscount::bestPrice(1234.0, 15.0, 1, false, []);
        $assert(abs($r2['price'] - 1048.9) < 0.01, 'TerminalDiscount decimals=2 → 1048.9');
    } finally {
        if (class_exists(Config::class, false)) {
            Config::set('likespa', 'terminal_price_decimals', $prevDecimals);
        }
    }

    $bookDisc = TerminalBooking::book([
        'product_id' => $productId,
        'operator_id' => $adminId,
        'admin_id' => $adminId,
        'terminal_id' => $terminalId,
        'dt_start' => BookingSmokeFixtures::firstSlotFrom($floorSlots, $dtStart + 1) ?: $dtStart,
        'client_id' => $smokeClientId,
        'onec_client_id' => 'stub-check-floor-disc',
        'onec_bracelet' => 'DEADBEEF',
        'disable_1c' => true,
        'notice' => 'check-terminals-floor-disc',
        'price' => 800.0,
        'price_list' => 1000.0,
        'skip_chill' => true,
    ]);
    $assert(!empty($bookDisc['ok']), 'TerminalBooking::book with price+price_list');
    $discProc = (int)($bookDisc['procedure_id'] ?? 0);
    if ($discProc > 0) {
        $drow = ProceduresRepository::find($discProc);
        $assert(
            $drow !== null
            && abs((float)($drow['summ'] ?? 0) - 800.0) < 0.01
            && abs((float)($drow['summ_default'] ?? 0) - 1000.0) < 0.01,
            'procedure summ/summ_default discounted'
        );
        TerminalBooking::cancel($discProc, true, true);
    }
}

echo 'steps: ' . (6 + ($failed === 0 ? 0 : 0)) . "\n";
if ($failed > 0) {
    echo "FAILED ($failed)\n";
    exit(1);
}
echo "OK\n";
exit(0);
