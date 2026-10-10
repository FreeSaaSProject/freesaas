<?php
declare(strict_types=1);

/**
 * Copy likespa.{pages,promopages,redirects} permission flags → sites.* on staff status blobs.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_users') || !Database::columnExists('base_users', 'status')) {
        return;
    }
    $map = [
        'likespa.pages' => 'sites.pages',
        'likespa.promopages' => 'sites.promopages',
        'likespa.redirects' => 'sites.redirects',
    ];
    $st = $pdo->query('SELECT id, status FROM base_users');
    if (!$st) {
        return;
    }
    $upd = $pdo->prepare('UPDATE base_users SET status = ? WHERE id = ?');
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $raw = (string)($row['status'] ?? '');
        if ($raw === '') {
            continue;
        }
        $blob = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($blob) || !isset($blob['permissions']) || !is_array($blob['permissions'])) {
            continue;
        }
        $changed = false;
        foreach ($map as $from => $to) {
            if (!empty($blob['permissions'][$from]) && empty($blob['permissions'][$to])) {
                $blob['permissions'][$to] = 1;
                $changed = true;
            }
        }
        if ($changed) {
            $upd->execute([serialize($blob), (int)$row['id']]);
        }
    }
};
