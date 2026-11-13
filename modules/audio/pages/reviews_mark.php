<?php
declare(strict_types=1);

/**
 * Mark pending review-queue records as reviewed (same effect as list bulk «Mark reviewed»).
 * Selectors: ids[] / ids CSV, or date (Y-m-d) / year+month+day with optional admin.
 */

$maxIds = 1000;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    AgentHttp::error(t('audio.reviews_mark.err.method'), 405);
    return;
}

$uid = Auth::userId();
if ($uid < 1) {
    AgentHttp::error(t('audio.reviews_mark.err.auth'), 401);
    return;
}

$rawIds = $_POST['ids'] ?? [];
if (is_string($rawIds)) {
    $rawIds = preg_split('/[\s,;]+/', $rawIds) ?: [];
}
$ids = is_array($rawIds) ? array_values(array_filter(array_map('intval', $rawIds), static fn (int $v): bool => $v > 0)) : [];

$adminId = max(0, (int)($_POST['admin'] ?? $_POST['admin_id'] ?? 0));
$year = (int)($_POST['year'] ?? 0);
$month = (int)($_POST['month'] ?? 0);
$day = (int)($_POST['day'] ?? 0);
$date = trim((string)($_POST['date'] ?? ''));
if ($date !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
    $year = (int)$m[1];
    $month = (int)$m[2];
    $day = (int)$m[3];
}
$byDay = $year > 0 && $month > 0 && $day > 0;

if ($ids === [] && !$byDay) {
    AgentHttp::error(t('audio.reviews_mark.err.selector'), 400);
    return;
}

$requested = count($ids);
if ($byDay) {
    $dayIds = RecordsReviewsRepository::pendingIdsForDay($year, $month, $day, $adminId);
    // ids + day → intersection (agent passes the list it analysed for that day).
    $ids = $ids === [] ? $dayIds : array_values(array_intersect($ids, $dayIds));
} else {
    $ids = RecordsReviewsRepository::filterPending($ids);
}

if (count($ids) > $maxIds) {
    AgentHttp::error(t('audio.reviews_mark.err.too_many', ['n' => (string)count($ids), 'max' => (string)$maxIds]), 400);
    return;
}

$done = $ids === [] ? 0 : RecordsReviewsRepository::markReviewed($ids, $uid);

AgentHttp::emit([
    'ok' => true,
    'done' => $done,
    'matched' => count($ids),
    'requested' => $requested,
    'record_ids' => $ids,
    'date' => $byDay ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null,
    'admin' => $adminId > 0 ? $adminId : null,
    'message' => t('audio.bot.mod.confirmed', ['n' => $done]),
]);
