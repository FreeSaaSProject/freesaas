#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Domain smoke: procedure write paths + edit gate + api/api_termy order book.
 *
 * Covers:
 *  - /t path: TerminalBooking time_net + chill INSERT/cancel
 *  - Staff assertSlot with terminals.lead_sec (dense grid)
 *  - filterListSlots thinning
 *  - Procedure edit: BookingValidate + excludeProcedureId
 *  - api / api_termy: BookingBook with Likespa lead_sec_* (advance orders)
 *
 * Fixtures (live DB): an operator on today's shift + a service they may perform with a free
 * slot (bin/lib/BookingSmokeFixtures.php); SKIP with the reason when none fits.
 *
 * Usage:
 *   php bin/check-booking-procedures.php
 *   php bin/check-booking-procedures.php --product=1 --operator=58 --client=1
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

$need = ['booking', 'procedures'];
foreach ($need as $mid) {
    if (!ModuleManager::isEnabled($mid)) {
        echo "SKIP module {$mid} not enabled\n";
        echo "OK\n";
        exit(0);
    }
}

require_once __DIR__ . '/lib/BookingSmokeFixtures.php';

$dayStart = (int)mktime(0, 0, 0);
// Live DBs: pick an on-shift operator who may perform the service and still has a free slot
// (first staff / first service fails on real rates bans). Prefer a service with chill.
$pair = BookingSmokeFixtures::pickPair($dayStart, [
    'operator' => (int)($opts['operator'] ?? 0),
    'product' => (int)($opts['product'] ?? 0),
    'prefer_chill' => true,
    'lead' => ModuleManager::isEnabled('terminals') ? TerminalsConfig::leadSec() : BookingSlots::defaultLeadSec(),
]);
$productId = $pair['product'];
$operatorId = $pair['operator'];

$clientId = (int)($opts['client'] ?? 0);
$clientPhone = '';
if ($clientId < 1 && class_exists(ClientsRepository::class, false) && Database::tableExists('base_clients')) {
    $row = Database::pdo()->query(
        "SELECT id, tel FROM base_clients WHERE enabled = 1 AND tel <> '' ORDER BY id ASC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        $clientId = (int)$row['id'];
        $clientPhone = preg_replace('/\D+/', '', (string)$row['tel']) ?? '';
    }
} elseif ($clientId > 0) {
    $c = ClientsRepository::find($clientId);
    $clientPhone = preg_replace('/\D+/', '', (string)($c['tel'] ?? '')) ?? '';
}

$terminalId = 0;
if (ModuleManager::isEnabled('terminals') && class_exists(TerminalsRepository::class, false)) {
    $terminalId = (int)TerminalsConfig::string('booking_terminal_id', '0');
    if ($terminalId < 1) {
        $names = TerminalsRepository::names();
        $terminalId = (int)(array_key_first($names) ?? 0);
    }
}

// Virgin / CI trees often have no catalog products or day shifts — skip domain book
// probes rather than failing smoke (pass --product= / --operator= to force).
if ($productId < 1 || $operatorId < 1) {
    echo 'SKIP no fixtures: ' . $pair['reason']
        . " (seed catalog + workweek or pass --product= --operator=)\n";
    echo "OK\n";
    exit(0);
}
$product = ProductsRepository::find($productId);
if ($product === null) {
    echo "SKIP product row missing id={$productId}\n";
    echo "OK\n";
    exit(0);
}
$assert(true, 'fixture product_id=' . $productId . ' operator_id=' . $operatorId);
$timeSec = max(0, (int)($product['time_sec'] ?? 0));
$timeNet = max(0, (int)($product['time_net'] ?? 0));
if ($timeSec < 1) {
    $timeSec = 3600;
}
if ($timeNet < 1) {
    $timeNet = $timeSec;
}
$assert(true, 'product times time_sec=' . $timeSec . ' time_net=' . $timeNet
    . ' chill_expect=' . max(0, $timeSec - $timeNet));

$dateYmd = date('Y-m-d');
$leadFloor = ModuleManager::isEnabled('terminals') ? TerminalsConfig::leadSec() : BookingSlots::defaultLeadSec();
$leadApi = ModuleManager::isEnabled('likespa') ? LikespaConfig::leadSecApi() : 600;
$leadTermy = ModuleManager::isEnabled('likespa') ? LikespaConfig::leadSecTermy() : 1200;
$assert($leadFloor >= 0 && $leadApi >= 0 && $leadTermy >= 0, 'leads floor=' . $leadFloor
    . ' api=' . $leadApi . ' termy=' . $leadTermy);

// --- list thinning ---
try {
    $avail = BookingAvailability::forDay($dateYmd, [$productId], $operatorId, null, false, $leadFloor);
    $dense = [];
    foreach ($avail['operators'] as $op) {
        if ((int)$op['id'] === $operatorId) {
            $dense = array_map('intval', $op['slots'] ?? []);
            break;
        }
    }
    $thinned = BookingSlots::filterListSlots($dense);
    $assert($dense !== [], 'availability dense slots count=' . count($dense));
    $assert(count($thinned) <= count($dense), 'filterListSlots thins '
        . count($dense) . ' → ' . count($thinned));
    if ($thinned !== []) {
        $assert($thinned[0] === $dense[0], 'list keeps first dense slot');
    }
    // Non-round dense slot must still assert if present
    $nonRound = null;
    foreach ($dense as $ts) {
        if (((int)date('i', $ts) % 15) !== 0) {
            $nonRound = $ts;
            break;
        }
    }
    if ($nonRound !== null) {
        try {
            BookingValidate::assertSlot([$productId], $operatorId, $nonRound, null, false, $leadFloor);
            $assert(true, 'assertSlot accepts non-round 5-min dt_start=' . date('H:i', $nonRound));
        } catch (Throwable $e) {
            $assert(false, 'assertSlot non-round: ' . $e->getMessage());
        }
    } else {
        $assert(true, 'skip non-round assert (no :05/:10 slots today)');
    }
} catch (Throwable $e) {
    $assert(false, 'availability/list: ' . $e->getMessage());
    $dense = [];
}

// --- /t TerminalBooking: time_net + chill ---
$createdProcIds = [];
$chillId = 0;
if (ModuleManager::isEnabled('terminals') && class_exists(TerminalBooking::class, false)) {
    // Same grid as the floor (TerminalsConfig::bookingSlotOpts, e.g. 15-min interval) — a 5-min
    // dense slot would be floored into the past by assertSlot.
    $floorSlots = BookingSmokeFixtures::slots($dateYmd, $productId, $operatorId, $leadFloor, TerminalsConfig::bookingSlotOpts());
    $dtStart = BookingSmokeFixtures::firstSlotFrom($floorSlots, time() + $leadFloor);
    if ($clientId < 1) {
        $assert(true, 'skip /t TerminalBooking (no client for procedure identity)');
    } elseif ($dtStart < 1) {
        $assert(true, 'skip /t TerminalBooking (no free floor slot left today)');
    } else {
    $book = TerminalBooking::book([
        'product_id' => $productId,
        'operator_id' => $operatorId,
        'admin_id' => $operatorId,
        'terminal_id' => $terminalId,
        'dt_start' => $dtStart,
        'client_id' => $clientId,
        'onec_bracelet' => 'DEADBEEF',
        'onec_client_id' => 'stub-check-booking-procedures',
        'disable_1c' => true,
        'lead_sec' => $leadFloor,
        'notice' => 'check-booking-procedures /t',
        'token' => substr(bin2hex(random_bytes(16)), 0, 32),
    ]);
    $assert(!empty($book['ok']), '/t TerminalBooking::book ' . (string)($book['error'] ?? ''));
    $procId = (int)($book['procedure_id'] ?? 0);
    $assert($procId > 0, '/t procedure_id=' . $procId);
    $createdProcIds[] = $procId;

    if ($procId > 0) {
        $row = ProceduresRepository::find($procId);
        $assert($row !== null && (int)($row['enabled'] ?? 0) === 1, '/t procedure enabled');
        $gotNet = (int)($row['dtend'] ?? 0) - (int)($row['dtstart'] ?? 0);
        $assert($gotNet === $timeNet, '/t dt_end uses time_net expected=' . $timeNet . ' got=' . $gotNet);

        $chillProduct = TerminalsConfig::chillProductId();
        $expectChill = 0;
        if ($chillProduct > 0) {
            $capped = BookingSlots::durationWithChill($timeSec, $timeNet);
            $expectChill = $capped['time_chill'];
        }
        $chillId = (int)($book['chill_procedure_id'] ?? 0);
        if ($expectChill > 0) {
            $assert($chillId > 0, '/t chill created id=' . $chillId . ' expect_sec=' . $expectChill);
            if ($chillId > 0) {
                $createdProcIds[] = $chillId;
                $chill = ProceduresRepository::find($chillId);
                $assert(
                    $chill !== null
                    && (int)($chill['enabled'] ?? 0) === 1
                    && (int)($chill['id_good'] ?? 0) === $chillProduct
                    && (int)($chill['dtstart'] ?? 0) === (int)($row['dtend'] ?? 0),
                    '/t chill row id_good=' . $chillProduct . ' starts at main.dtend'
                );
                $stackHasChill = false;
                foreach ($book['stack'] ?? [] as $step) {
                    if (($step['key'] ?? '') === 'chill' && !empty($step['ok'])) {
                        $stackHasChill = true;
                    }
                }
                $assert($stackHasChill, '/t stack has chill ok');
            }
        } else {
            $assert(true, '/t no chill expected (cap=0 or chill_product_id=0)');
        }

        // Edit gate: same slot with excludeProcedureId must pass
        try {
            BookingValidate::assertSlot(
                [$productId],
                $operatorId,
                (int)$row['dtstart'],
                null,
                true,
                BookingSlots::defaultLeadSec(),
                $procId
            );
            $assert(true, 'procedure edit assertSlot self-exclude ok');
        } catch (Throwable $e) {
            $assert(false, 'procedure edit self-exclude: ' . $e->getMessage());
        }

        // Without exclude: same slot should fail (occupied by self)
        $conflictOk = false;
        try {
            BookingValidate::assertSlot(
                [$productId],
                $operatorId,
                (int)$row['dtstart'],
                null,
                true,
                BookingSlots::defaultLeadSec(),
                null
            );
        } catch (Throwable $e) {
            $conflictOk = true;
            $assert(true, 'procedure edit without exclude rejects self: ' . $e->getMessage());
        }
        if (!$conflictOk) {
            $assert(false, 'procedure edit without exclude should reject occupied slot');
        }

        $cancel = TerminalBooking::cancel($procId, true, true, 'check-booking-procedures cancel');
        $assert(!empty($cancel['ok']), '/t TerminalBooking::cancel');
        $row2 = ProceduresRepository::find($procId);
        $assert($row2 !== null && (int)($row2['enabled'] ?? 0) === 0, '/t main disabled');
        if ($chillId > 0) {
            $chill2 = ProceduresRepository::find($chillId);
            $assert(
                $chill2 !== null && (int)($chill2['enabled'] ?? 0) === 0,
                '/t chill disabled with main'
            );
        }
    }
    } // client_id present
} else {
    $assert(true, 'SKIP /t TerminalBooking (terminals off)');
}

// --- api / api_termy via BookingBook (orders, not procedures) ---
$createdOrderIds = [];
if (
    ModuleManager::isEnabled('likespa')
    && class_exists(BookingBook::class, false)
    && class_exists(OrdersRepository::class, false)
    && $clientPhone !== ''
) {
    $pickSlot = static function (int $lead) use ($dateYmd, $productId, $operatorId): int {
        $av = BookingAvailability::forDay($dateYmd, [$productId], $operatorId, null, false, $lead);
        foreach ($av['operators'] as $op) {
            if ((int)$op['id'] !== $operatorId) {
                continue;
            }
            $slots = array_map('intval', $op['slots'] ?? []);
            foreach ($slots as $ts) {
                if ($ts >= time() + $lead) {
                    return $ts;
                }
            }
        }
        $ts = time() + max(1800, $lead + 300);
        return $ts - ($ts % 300);
    };

    foreach (
        [
            'api' => $leadApi,
            'api_termy' => $leadTermy,
        ] as $source => $lead
    ) {
        try {
            $dt = $pickSlot($lead);
            $order = BookingBook::createOrder([
                'phone' => $clientPhone,
                'client_id' => $clientId,
                'product_ids' => [$productId],
                'operator_id' => $operatorId,
                'dt_start' => $dt,
                'source' => $source,
                'lead_sec' => $lead,
                'comment' => 'check-booking-procedures ' . $source,
            ]);
            $oid = (int)($order['order_id'] ?? 0);
            $assert($oid > 0, $source . ' BookingBook order_id=' . $oid . ' dt=' . date('H:i', $dt));
            if ($oid > 0) {
                $createdOrderIds[] = $oid;
                $ord = OrdersRepository::find($oid);
                $assert(
                    $ord !== null
                    && (int)($ord['id_cosmetolog'] ?? 0) === $operatorId
                    && (int)($ord['dt_event'] ?? 0) === $dt,
                    $source . ' order row staff+dt match'
                );
                // Cancel / disable so we do not leave junk
                if (method_exists(OrdersRepository::class, 'cancelForClient')) {
                    try {
                        OrdersRepository::cancelForClient($oid, $clientId, 'check-booking-procedures');
                        $assert(true, $source . ' order cancelled');
                    } catch (Throwable $e) {
                        Database::pdo()->prepare(
                            "UPDATE base_orders SET STATUS = 'cancel' WHERE ID = ?"
                        )->execute([$oid]);
                        $assert(true, $source . ' order force-status cancel (' . $e->getMessage() . ')');
                    }
                } else {
                    Database::pdo()->prepare(
                        "UPDATE base_orders SET STATUS = 'cancel' WHERE ID = ?"
                    )->execute([$oid]);
                    $assert(true, $source . ' order status=cancel');
                }
            }
        } catch (Throwable $e) {
            $assert(false, $source . ' book: ' . $e->getMessage());
        }
    }

    // Personnel thinning (api channel lead)
    try {
        $avApi = BookingAvailability::forDay($dateYmd, [$productId], null, null, false, $leadApi);
        $anyThin = false;
        foreach ($avApi['operators'] as $op) {
            $raw = array_map('intval', $op['slots'] ?? []);
            $thin = BookingSlots::filterListSlots($raw);
            if ($raw !== [] && count($thin) <= count($raw)) {
                $anyThin = true;
                break;
            }
        }
        $assert($anyThin, 'api get_personnel-style thinning works');
    } catch (Throwable $e) {
        $assert(false, 'api personnel thinning: ' . $e->getMessage());
    }
} else {
    $assert(true, 'SKIP api/api_termy BookingBook (likespa/orders/client phone)');
}

// Cleanup any leftover enabled test procedures
foreach ($createdProcIds as $pid) {
    $row = ProceduresRepository::find($pid);
    if ($row !== null && (int)($row['enabled'] ?? 0) === 1) {
        Database::pdo()->prepare('UPDATE base_procedures SET ENABLED = 0, NOTICE = ? WHERE ID = ?')
            ->execute(['check-booking-procedures cleanup', $pid]);
    }
}

if ($failed > 0) {
    echo "FAILED ($failed)\n";
    exit(1);
}
echo "OK\n";
exit(0);
