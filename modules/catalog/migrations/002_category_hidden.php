<?php
declare(strict_types=1);

/**
 * Category visibility: legacy secure_groups checkbox «Скрыть каталог».
 */
return static function (PDO $pdo): void {
    if (Database::columnExists('base_categories', 'hidden')) {
        return;
    }
    $pdo->exec(
        'ALTER TABLE `base_categories`
         ADD COLUMN `hidden` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `num`,
         ADD KEY `idx_hidden` (`hidden`)'
    );
};
