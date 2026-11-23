<?php
declare(strict_types=1);

/**
 * PHOTO_REQ retired — photo requirements live in media module (base_media_requirements).
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_procedure_types')) {
        return;
    }
    if (!Database::columnExists('base_procedure_types', 'PHOTO_REQ')) {
        return;
    }
    $pdo->exec('ALTER TABLE `base_procedure_types` DROP COLUMN `PHOTO_REQ`');
};
