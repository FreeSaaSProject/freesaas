<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    if (!Database::tableExists('base_media_requirements')) {
        return;
    }
    $st = $pdo->query(
        "SELECT CHARACTER_MAXIMUM_LENGTH AS len
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'base_media_requirements'
           AND COLUMN_NAME = 'LABEL'
         LIMIT 1"
    );
    $len = (int)($st ? $st->fetchColumn() : 0);
    if ($len > 0 && $len < 512) {
        $pdo->exec(
            'ALTER TABLE `base_media_requirements`
             MODIFY COLUMN `LABEL` VARCHAR(512) NOT NULL DEFAULT \'\''
        );
    }
};
