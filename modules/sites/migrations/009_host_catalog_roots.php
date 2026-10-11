<?php
declare(strict_types=1);

/**
 * Multi catalog folders per host: catalog_root_ids (JSON int list).
 * Empty / [] = entire catalog. Keeps catalog_root_id as first id for BC.
 *
 * @return callable(PDO):void
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_site_hosts')) {
        return;
    }
    if (!Database::columnExists('base_site_hosts', 'catalog_root_ids')) {
        // VARCHAR (not TEXT): older MySQL rejects DEFAULT on TEXT/BLOB.
        $pdo->exec(
            'ALTER TABLE base_site_hosts
             ADD COLUMN catalog_root_ids VARCHAR(512) NOT NULL DEFAULT \'\'
             AFTER catalog_root_id'
        );
    }
    // Backfill from legacy single root.
    $st = $pdo->query(
        'SELECT id, catalog_root_id, catalog_root_ids FROM base_site_hosts'
    );
    if (!$st) {
        return;
    }
    $upd = $pdo->prepare('UPDATE base_site_hosts SET catalog_root_ids = ? WHERE id = ?');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $existing = trim((string)($row['catalog_root_ids'] ?? ''));
        if ($existing !== '' && $existing !== '[]') {
            continue;
        }
        $root = (int)($row['catalog_root_id'] ?? 0);
        $json = $root > 0 ? json_encode([$root], JSON_UNESCAPED_UNICODE) : '';
        $upd->execute([$json === false ? '' : $json, (int)$row['id']]);
    }
};
