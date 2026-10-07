<?php
declare(strict_types=1);

return static function (PDO $pdo, array $spec): array {
    return DataMigrator::replaceTable($pdo, 'phpshop_page_categories', 'base_page_categories');
};
