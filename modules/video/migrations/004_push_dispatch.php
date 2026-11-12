<?php
declare(strict_types=1);

/**
 * Push dispatch: which channel holds a claimed job and on which edge host (base_edge_hosts.ID).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_video_jobs')) {
        return;
    }
    $cols = [
        'CHANNEL' => "VARCHAR(8) NOT NULL DEFAULT 'pull'",
        'EDGE_HOST_ID' => 'INT UNSIGNED NOT NULL DEFAULT 0',
    ];
    foreach ($cols as $name => $definition) {
        if (!Database::columnExists('base_video_jobs', $name)) {
            $pdo->exec('ALTER TABLE `base_video_jobs` ADD COLUMN `' . $name . '` ' . $definition);
            Database::clearSchemaCache();
        }
    }
    $idx = $pdo->query("SHOW INDEX FROM `base_video_jobs` WHERE Key_name = 'idx_video_jobs_edge'")->fetchAll();
    if ($idx === []) {
        $pdo->exec('ALTER TABLE `base_video_jobs` ADD KEY `idx_video_jobs_edge` (`CHANNEL`, `EDGE_HOST_ID`, `STATUS`)');
    }
};
