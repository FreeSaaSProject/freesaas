<?php
declare(strict_types=1);

/**
 * Copy legacy base_system name/title/keywords/tel → cfg/modules/sites.ini [sites].
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_system')) {
        return;
    }
    $row = $pdo->query('SELECT * FROM base_system ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return;
    }
    foreach (['name' => 'seo_name', 'title' => 'seo_title', 'keywords' => 'seo_keywords', 'tel' => 'tel'] as $col => $key) {
        if (array_key_exists($col, $row)) {
            Config::set('sites', $key, trim((string)$row[$col]));
        }
    }
};
