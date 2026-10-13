<?php
declare(strict_types=1);

/**
 * Drop unused oper sert / client_payments tables. Data not preserved (tables empty / unused).
 *
 * @return array{ok:bool,dropped:list<string>,message:string}
 */
return static function (PDO $pdo, array $spec): array {
    $tableExists = static function (PDO $pdo, string $table): bool {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    };

    $legacy = [
        'base_sertificates',
        'base_sert_movements',
        'base_client_payments',
    ];
    $dropped = [];
    foreach ($legacy as $table) {
        if (!$tableExists($pdo, $table)) {
            continue;
        }
        $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
        $dropped[] = $table;
    }

    return [
        'ok' => true,
        'dropped' => $dropped,
        'message' => $dropped === []
            ? 'No legacy sert tables present'
            : ('Dropped: ' . implode(', ', $dropped)),
    ];
};
