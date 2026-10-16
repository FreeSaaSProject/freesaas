<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
if ($wantsJson && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    redirect('/booking/availability?' . http_build_query($_GET));
}

$flash = '';
$error = '';
$ret = trim((string)($_POST['ret'] ?? $_GET['ret'] ?? ''));
if ($ret !== '' && ($ret[0] !== '/' || substr($ret, 0, 2) === '//')) {
    $ret = '';
}

$date = trim((string)($_POST['date'] ?? $_GET['date'] ?? ''));
if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
$hourHint = (int)($_GET['hour'] ?? $_POST['hour'] ?? -1);
$operatorId = (int)($_POST['operator_id'] ?? $_GET['operator_id'] ?? $_GET['staff'] ?? 0);
$phone = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? '')) ?? '';
$clientName = trim((string)($_POST['client_name'] ?? ''));
$clientId = (int)($_POST['client_id'] ?? 0);
$comment = trim((string)($_POST['comment'] ?? ''));
$dtStart = (int)($_POST['dt_start'] ?? 0);

$productIds = [];
$rawProducts = $_POST['product_ids'] ?? $_GET['product_ids'] ?? [];
if (is_string($rawProducts)) {
    foreach (preg_split('/[,\s]+/', $rawProducts) ?: [] as $part) {
        $id = (int)$part;
        if ($id > 0) {
            $productIds[] = $id;
        }
    }
} elseif (is_array($rawProducts)) {
    foreach ($rawProducts as $v) {
        $id = (int)$v;
        if ($id > 0) {
            $productIds[] = $id;
        }
    }
}
$productIds = array_values(array_unique($productIds));

$productOptions = BookingCatalog::listServices(500);
$productGroups = BookingCatalog::groupByCategory($productOptions);

$availability = null;
if ($productIds !== []) {
    try {
        $availability = BookingAvailability::forDay(
            $date,
            $productIds,
            $operatorId > 0 ? $operatorId : null
        );
        foreach ($availability['operators'] as $i => $op) {
            $slots = BookingSlots::filterListSlots(array_map('intval', $op['slots'] ?? []));
            $hhmm = [];
            foreach ($slots as $ts) {
                $hhmm[] = date('H:i', $ts);
            }
            $availability['operators'][$i]['slots'] = $slots;
            $availability['operators'][$i]['slots_hhmm'] = $hhmm;
            $availability['operators'][$i]['available'] = $slots !== [];
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['action'] ?? '') === 'book') {
    try {
        $result = BookingBook::createOrder([
            'phone' => $phone,
            'client_id' => $clientId,
            'client_name' => $clientName,
            'product_ids' => $productIds,
            'operator_id' => $operatorId,
            'dt_start' => $dtStart,
            'comment' => $comment,
            'source' => 'staff',
        ]);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'booking' => $result]);
        }
        $go = $ret !== '' ? $ret : ('/orders/' . (int)$result['order_id'] . '/edit');
        redirect($go);
        return;
    } catch (InvalidArgumentException | RuntimeException $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
        }
    }
}

$preferredSlot = 0;
if ($availability && $hourHint >= 0 && $hourHint <= 23) {
    $target = (int)mktime($hourHint, 0, 0, (int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4));
    $best = null;
    $bestDiff = PHP_INT_MAX;
    foreach ($availability['operators'] as $op) {
        if ($operatorId > 0 && (int)$op['id'] !== $operatorId) {
            continue;
        }
        foreach ($op['slots'] as $ts) {
            $diff = abs((int)$ts - $target);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = [(int)$op['id'], (int)$ts];
            }
        }
    }
    if ($best !== null) {
        if ($operatorId < 1) {
            $operatorId = $best[0];
        }
        $preferredSlot = $best[1];
    }
}

View::render('new', [
    'title' => t('booking.menu.new'),
    'date' => $date,
    'hourHint' => $hourHint,
    'operatorId' => $operatorId,
    'productIds' => $productIds,
    'productGroups' => $productGroups,
    'phone' => $phone,
    'clientName' => $clientName,
    'clientId' => $clientId,
    'comment' => $comment,
    'dtStart' => $dtStart > 0 ? $dtStart : $preferredSlot,
    'availability' => $availability,
    'flash' => $flash,
    'error' => $error,
    'ret' => $ret,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('calendar.menu.group'), 'href' => '/calendar'],
        ['label' => t('booking.menu.new'), 'current' => true],
    ]),
], 'layout', 'booking');
