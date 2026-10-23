<?php
declare(strict_types=1);

/**
 * Public storefront hosts: theme, home promo, catalog root folder, phone.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS base_site_hosts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            host VARCHAR(255) NOT NULL DEFAULT '',
            enabled TINYINT UNSIGNED NOT NULL DEFAULT 1,
            name VARCHAR(255) NOT NULL DEFAULT '',
            theme VARCHAR(64) NOT NULL DEFAULT 'default',
            promo_id INT UNSIGNED NOT NULL DEFAULT 0,
            catalog_root_id INT UNSIGNED NOT NULL DEFAULT 0,
            tel VARCHAR(64) NOT NULL DEFAULT '',
            is_default TINYINT UNSIGNED NOT NULL DEFAULT 0,
            num INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_host (host),
            KEY idx_enabled (enabled),
            KEY idx_default (is_default)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $cnt = (int)$pdo->query('SELECT COUNT(*) FROM base_site_hosts')->fetchColumn();
    if ($cnt > 0) {
        return;
    }

    $normalizeHost = static function (string $raw): string {
        $h = trim($raw);
        $h = preg_replace('#^https?://#i', '', $h) ?? $h;
        $h = strtolower(rtrim($h, '/'));
        if (($p = strpos($h, '/')) !== false) {
            $h = substr($h, 0, $p);
        }
        if (($p = strpos($h, ':')) !== false) {
            $h = substr($h, 0, $p);
        }
        return $h;
    };

    $host = '';
    foreach (
        [
            (string)Config::get('app', 'public_host', ''),
            (string)Config::get('sites', 'public_host', ''),
            (string)Config::get('likespa', 'mothersite', ''),
        ] as $cand
    ) {
        $host = $normalizeHost($cand);
        if ($host !== '') {
            break;
        }
    }
    if ($host === '') {
        $host = 'localhost';
    }

    $theme = trim((string)Config::get('sites', 'theme', 'default'));
    if ($theme === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $theme)) {
        $theme = 'default';
    }

    $promoId = 0;
    if (Database::tableExists('base_promopages')) {
        $st = $pdo->query("SELECT ID FROM base_promopages WHERE CODE = 'index' ORDER BY ID ASC LIMIT 1");
        $promoId = (int)($st ? $st->fetchColumn() : 0);
    }

    // Prefer base_system (UTF-8 in DB). connect.sitename in ini is often legacy/broken encoding.
    // Greenfield core creates base_system without tel (oper/legacy only) — probe columns.
    $tel = '';
    $name = '';
    if (Database::tableExists('base_system')) {
        $sel = ['name'];
        if (Database::columnExists('base_system', 'tel')) {
            $sel[] = 'tel';
        }
        $sys = $pdo->query(
            'SELECT ' . implode(', ', $sel) . ' FROM base_system ORDER BY id ASC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (is_array($sys)) {
            if (trim((string)($sys['tel'] ?? '')) !== '') {
                $tel = mb_substr(trim((string)$sys['tel']), 0, 64, 'UTF-8');
            }
            if (trim((string)($sys['name'] ?? '')) !== '') {
                $name = mb_substr(trim((string)$sys['name']), 0, 255, 'UTF-8');
            }
        }
    }
    if ($tel === '') {
        $tel = mb_substr(trim((string)Config::get('sites', 'tel', '')), 0, 64, 'UTF-8');
    }
    if ($name === '') {
        $name = mb_substr(trim((string)Config::get('sites', 'seo_name', '')), 0, 255, 'UTF-8');
    }
    if ($name === '') {
        $name = mb_substr(trim((string)Config::get('connect', 'sitename', '')), 0, 255, 'UTF-8');
    }

    $st = $pdo->prepare(
        'INSERT INTO base_site_hosts
            (host, enabled, name, theme, promo_id, catalog_root_id, tel, is_default, num)
         VALUES (?, 1, ?, ?, ?, 0, ?, 1, 0)'
    );
    $st->execute([$host, $name, $theme, $promoId, $tel]);
};
