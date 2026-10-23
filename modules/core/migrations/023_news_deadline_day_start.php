<?php
declare(strict_types=1);

/**
 * Ticket deadlines saved from the web form as 23:59:59 → start of the same day,
 * so overdue notices fire on the deadline day (oper behaviour).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_news')) {
        return;
    }
    $rows = $pdo->query(
        'SELECT ID, DT_DEADLINE FROM base_news WHERE DT_DEADLINE > 0 AND HAS_CHILDREN = 0'
    )->fetchAll();
    $up = $pdo->prepare('UPDATE base_news SET DT_DEADLINE = ? WHERE ID = ? AND DT_DEADLINE = ?');
    foreach ($rows as $r) {
        $ts = (int)$r['DT_DEADLINE'];
        if (date('H:i:s', $ts) !== '23:59:59') {
            continue;
        }
        $start = mktime(0, 0, 0, (int)date('n', $ts), (int)date('j', $ts), (int)date('Y', $ts));
        if ($start === false) {
            continue;
        }
        $up->execute([(int)$start, (int)$r['ID'], $ts]);
    }
};
