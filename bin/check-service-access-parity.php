#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * One-shot parity: old service skill gates vs new rates-based skill.
 *
 * Old (booking/online): RESTRICT_* + rates content[type]>0
 * Old (/t terminal): same + category.executors allow-list
 * New: rates content[type]>0 + ban_booking / ban_online (no RESTRICT_*, no category executors)
 *
 * Run after migrate rates 003 (ban columns). Before RESTRICT→rates migrator, expect
 * mismatches where users had RESTRICT_* or categories had executors.
 *
 * Usage:
 *   php bin/check-service-access-parity.php
 *   php bin/check-service-access-parity.php --format=json
 *   php bin/check-service-access-parity.php --operator=58 --limit-products=100
 *   php bin/check-service-access-parity.php --channels=booking,online,terminal
 *
 * Exit: 0 when no mismatches, 1 when mismatches or hard fail, 0+SKIP when modules off
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--format=json' || $arg === '--json') {
        $opts['format'] = 'json';
        continue;
    }
    if (preg_match('/^--([a-z_]+)=(.+)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    }
}
$asJson = ($opts['format'] ?? '') === 'json';

foreach (['rates', 'booking', 'procedures', 'catalog'] as $mid) {
    if (!ModuleManager::isEnabled($mid)) {
        if ($asJson) {
            echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'module_off', 'module' => $mid], JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            echo "SKIP module {$mid} not enabled\nOK\n";
        }
        exit(0);
    }
}

require_once base_path('modules/rates/lib/ServiceAccessParity.php');

$channelsRaw = trim((string)($opts['channels'] ?? 'booking,online,terminal'));
$channels = [];
foreach (explode(',', $channelsRaw) as $c) {
    $c = trim($c);
    if ($c !== '') {
        $channels[] = $c;
    }
}

$report = ServiceAccessParity::compare([
    'operator_id' => (int)($opts['operator'] ?? $opts['operator_id'] ?? 0),
    'limit_staff' => (int)($opts['limit_staff'] ?? 200),
    'limit_products' => (int)($opts['limit_products'] ?? 500),
    'channels' => $channels,
]);

if ($asJson) {
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(!empty($report['ok']) ? 0 : 1);
}

echo "Service access parity (old RESTRICT+executors vs new rates bans)\n";
echo 'ban_columns=' . (!empty($report['ban_columns']) ? 'yes' : 'NO — run migrate rates') . "\n";
echo 'staff=' . (int)$report['staff']
    . ' products=' . (int)$report['products']
    . ' checked=' . (int)$report['checked']
    . "\n";
$s = $report['summary'];
echo 'both_ok=' . (int)$s['both_ok']
    . ' both_deny=' . (int)$s['both_deny']
    . ' old_only=' . (int)$s['old_only']
    . ' new_only=' . (int)$s['new_only']
    . "\n";

$mismatches = $report['mismatches'];
if ($mismatches === []) {
    echo "OK  no mismatches\n";
    exit(0);
}

echo 'FAIL ' . count($mismatches) . " mismatch(es):\n";
$maxShow = max(1, min(200, (int)($opts['show'] ?? 80)));
$i = 0;
foreach ($mismatches as $m) {
    $i++;
    if ($i > $maxShow) {
        echo '… +' . (count($mismatches) - $maxShow) . " more (use --format=json for full list)\n";
        break;
    }
    echo sprintf(
        "  [%s] %s op=#%d %s  good=#%d %s type=%d cat=%d\n    old_blockers=%s\n    new_blockers=%s\n",
        (string)$m['kind'],
        (string)$m['channel'],
        (int)$m['operator_id'],
        (string)$m['operator_name'],
        (int)$m['product_id'],
        (string)$m['product_name'],
        (int)$m['type_id'],
        (int)$m['category_id'],
        $m['old_blockers'] === [] ? '—' : implode(',', $m['old_blockers']),
        $m['new_blockers'] === [] ? '—' : implode(',', $m['new_blockers'])
    );
}

// Hint groups
$byKind = [];
foreach ($mismatches as $m) {
    $k = (string)$m['kind'] . '/' . (string)$m['channel'];
    $byKind[$k] = ($byKind[$k] ?? 0) + 1;
}
echo "By kind/channel:\n";
foreach ($byKind as $k => $n) {
    echo "  {$k}: {$n}\n";
}
echo "Hint: old_allows_new_denies → migrate RESTRICT into ban_* or set %; "
    . "new_allows_old_denies → category executors / RESTRICT still blocking old path\n";

exit(1);
