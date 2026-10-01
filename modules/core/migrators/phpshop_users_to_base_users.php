<?php
declare(strict_types=1);

return static function (PDO $pdo, array $spec): array {
    return DataMigrator::replaceTable($pdo, 'phpshop_users', 'base_users');
};
