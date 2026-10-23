<?php
declare(strict_types=1);

/**
 * Copy legacy base_system.NEWS_SCHEDULE_ID → cfg/modules/workweeks.ini schedule_news_id (if not set yet).
 */
return static function (PDO $pdo): void {
    $current = Config::get('workweeks', 'schedule_news_id', null);
    if ($current !== null && $current !== '' && (int)$current > 0) {
        return;
    }
    if (!Database::tableExists('base_system') || !Database::columnExists('base_system', 'NEWS_SCHEDULE_ID')) {
        return;
    }
    $id = (int)$pdo->query('SELECT NEWS_SCHEDULE_ID FROM base_system ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($id > 0) {
        Config::set('workweeks', 'schedule_news_id', (string)$id);
    }
};
