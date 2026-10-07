<?php
declare(strict_types=1);

return static function (PDO $pdo, array $spec): array {
    $result = DataMigrator::replaceTable($pdo, 'phpshop_users', 'base_users');

    // replaceTable copies only same-named columns; status FKs were renamed on base_users.
    $sets = [];
    if (Database::columnExists('base_users', 'ID_STATUS')
        && Database::columnExists('phpshop_users', 'name_enabled')
    ) {
        $sets[] = 'u.ID_STATUS = p.name_enabled';
    }
    if (Database::columnExists('base_users', 'ID_STATUS2')
        && Database::columnExists('phpshop_users', 'STATUS2')
    ) {
        $sets[] = 'u.ID_STATUS2 = p.STATUS2';
    }
    if ($sets) {
        $pdo->exec(
            'UPDATE base_users u
             INNER JOIN phpshop_users p ON p.id = u.id
             SET ' . implode(', ', $sets)
        );
    }

    return $result;
};
