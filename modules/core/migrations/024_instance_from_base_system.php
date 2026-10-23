<?php
declare(strict_types=1);

/**
 * Copy legacy base_system.company → cfg/config.ini [app] instance (company label in chrome).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_system')) {
        return;
    }
    $row = $pdo->query('SELECT * FROM base_system ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return;
    }
    $company = trim((string)($row['company'] ?? ''));
    if ($company !== '') {
        Config::set('app', 'instance', $company);
    }
};
