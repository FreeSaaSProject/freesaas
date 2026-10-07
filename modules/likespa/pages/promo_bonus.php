<?php
declare(strict_types=1);

$adminId = max(0, (int)($_REQUEST['admin'] ?? 0));
$date = trim((string)($_REQUEST['date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = '';
}

$ret = NavContext::returnUrl();
if ($ret === '') {
    $parts = explode('-', $date);
    if (count($parts) === 3) {
        $ret = '/audio/calendar?year=' . (int)$parts[0] . '&month=' . (int)$parts[1];
    } else {
        $ret = '/audio/calendar';
    }
}

$go = static function (string $flag, array $extra = []) use ($ret): void {
    $q = array_merge(['likespa_bonus' => $flag], $extra);
    $sep = strpos($ret, '?') !== false ? '&' : '?';
    redirect($ret . $sep . http_build_query($q));
};

if (!Auth::can('likespa.promo') && !Auth::capability('SUPERADMIN_ACCESS')) {
    $go('denied');
}

if ($adminId < 1 || $date === '' || !LikespaConfig::promoEnabled()) {
    $go('failed');
}

if (PromoterBonus::hasBonusRecord($adminId, $date)) {
    $go('exists');
}

$dayStart = strtotime($date . ' 00:00:00');
$dayEnd = $dayStart !== false ? $dayStart + 86399 : 0;
$aggregates = ['playtime' => 0];
if ($dayStart !== false && class_exists('CalendarAggregates', false)) {
    $agg = CalendarAggregates::forRange((int)$dayStart, (int)$dayEnd, ['admin' => $adminId]);
    $cell = $agg['by_admin_day'][$adminId][$date] ?? null;
    if (is_array($cell)) {
        $aggregates = $cell;
    }
} elseif ($dayStart !== false && Database::tableExists('base_records')) {
    $sql = 'SELECT COALESCE(SUM(PLAYTIME),0) AS playtime
            FROM base_records
            WHERE ID_ADMIN = ? AND DT_RECORDED >= ? AND DT_RECORDED <= ?
              AND (DISABLED IS NULL OR DISABLED < 1)';
    $params = [$adminId, $dayStart, $dayEnd];
    if (Database::columnExists('base_records', 'DESCRIPTION')) {
        $sql .= ' AND (DESCRIPTION IS NULL OR DESCRIPTION <> ?)';
        $params[] = PromoterBonus::MARKER;
    }
    $st = Database::pdo()->prepare($sql);
    $st->execute($params);
    $aggregates['playtime'] = (int)$st->fetchColumn();
}

if (!PromoterBonus::isPromoterOnDay($adminId, $date)) {
    $go('not_eligible');
}
$metrics = PromoterBonus::metrics($adminId, $date, $aggregates);
if (empty($metrics['eligible'])) {
    $go('not_eligible');
}

$id = PromoterBonus::createBonus($adminId, $date);
if ($id > 0) {
    $go('ok', ['id' => $id]);
}
$go('failed');
