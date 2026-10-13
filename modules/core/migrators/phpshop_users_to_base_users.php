<?php
declare(strict_types=1);

/**
 * TEMP (phpshop cutoff): staff copy + status FK + link_gp→num + permission remap.
 * Delete with _OperPermissionsRemap.php / remap_oper_user_permissions after cutover.
 */
return static function (PDO $pdo, array $spec): array {
    $result = DataMigrator::replaceTable($pdo, 'phpshop_users', 'base_users');

    // replaceTable copies only same-named columns; status FKs were renamed on base_users.
    // Oper link_gp is not brought over — FreeSaaS order is base_users.num only.
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

    // Oper list order lived in link_gp; FreeSaaS uses num (schema drops link_gp on clone).
    if (Database::columnExists('base_users', 'num')
        && Database::columnExists('phpshop_users', 'link_gp')
    ) {
        $pdo->exec(
            'UPDATE base_users u
             INNER JOIN phpshop_users p ON p.id = u.id
             SET u.num = p.link_gp
             WHERE (u.num IS NULL OR u.num = 0)
               AND p.link_gp IS NOT NULL
               AND p.link_gp <> 0'
        );
    }

    if (!class_exists(OperPermissionsRemap::class, false)) {
        require_once __DIR__ . '/_OperPermissionsRemap.php';
    }
    $remap = OperPermissionsRemap::remapAllUsers($pdo);
    $result['permissions_scanned'] = $remap['scanned'] ?? 0;
    $result['permissions_updated'] = $remap['updated'] ?? 0;
    if (!empty($remap['message'])) {
        $result['permissions_message'] = $remap['message'];
    }

    return $result;
};
