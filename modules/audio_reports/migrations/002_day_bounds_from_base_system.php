<?php
declare(strict_types=1);

/**
 * Copy legacy base_system rating / recording day bounds → cfg/modules/audio_reports.ini [audio_reports].
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_system')) {
        return;
    }
    $row = $pdo->query('SELECT * FROM base_system ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return;
    }
    require_once base_path('modules/audio_reports/lib/AudioDayBounds.php');
    foreach (AudioDayBounds::KEYS as $col => $key) {
        if (array_key_exists($col, $row) && $row[$col] !== null && $row[$col] !== '') {
            Config::set('audio_reports', $key, (string)(int)$row[$col]);
        }
    }
};
