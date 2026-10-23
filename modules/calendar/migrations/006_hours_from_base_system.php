<?php
declare(strict_types=1);

/**
 * Copy legacy base_system work_hour_* / work_minute_* → cfg/modules/calendar.ini [calendar].
 * base_system used to win over ini, so its values overwrite.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_system')) {
        return;
    }
    $row = $pdo->query('SELECT * FROM base_system ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return;
    }
    $map = [
        'work_hour_start' => 'hour_start',
        'work_hour_end' => 'hour_end',
        'work_minute_start' => 'minute_start',
        'work_minute_end' => 'minute_end',
    ];
    foreach ($map as $col => $key) {
        if (array_key_exists($col, $row) && $row[$col] !== null && $row[$col] !== '') {
            Config::set('calendar', $key, (string)(int)$row[$col]);
        }
    }
};
