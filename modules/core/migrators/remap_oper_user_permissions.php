<?php
declare(strict_types=1);

/**
 * TEMP (phpshop cutoff): idempotent remap only — no TRUNCATE.
 * Delete with _OperPermissionsRemap.php + phpshop_users_to_base_users after cutover.
 */
return static function (PDO $pdo, array $spec): array {
    if (!class_exists(OperPermissionsRemap::class, false)) {
        require_once __DIR__ . '/_OperPermissionsRemap.php';
    }
    return OperPermissionsRemap::remapAllUsers($pdo);
};
