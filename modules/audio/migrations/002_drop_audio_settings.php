<?php
declare(strict_types=1);

/**
 * Retire base_audio_settings: copy any live KV rows into cfg/modules/audio.ini, then DROP.
 * Settings belong in ini (Config::get/set), same as other modules.
 */
return static function (PDO $pdo): void {
    if (!Database::tableExists('base_audio_settings')) {
        return;
    }

    /** @var array<string,array{0:string,1:string}> $map db_key => [section, ini_key] */
    $map = [
        'storage.mode' => ['storage', 'mode'],
        'storage.audio_inbox' => ['storage', 'audio_inbox'],
        'storage.audio_parsed' => ['storage', 'audio_parsed'],
        'storage.audio_chunks' => ['storage', 'audio_chunks'],
        'storage.ftp_host' => ['storage', 'ftp_host'],
        'storage.ftp_port' => ['storage', 'ftp_port'],
        'storage.ftp_user' => ['storage', 'ftp_user'],
        'storage.ftp_pass' => ['storage', 'ftp_pass'],
        'storage.ftp_base_path' => ['storage', 'ftp_base_path'],
        'storage.ftp_http_url' => ['storage', 'ftp_http_url'],
        'stem_cache_dir' => ['audio', 'stem_cache_dir'],
    ];

    try {
        $rows = $pdo->query('SELECT setting_key, setting_value FROM base_audio_settings')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $dbKey = (string)($row['setting_key'] ?? '');
            if (!isset($map[$dbKey])) {
                continue;
            }
            [$section, $iniKey] = $map[$dbKey];
            // Always copy when the DB row exists (empty ftp_pass may be intentional clear).
            Config::set($section, $iniKey, (string)($row['setting_value'] ?? ''));
        }
    } catch (Throwable $e) {
        // Still DROP the obsolete table; operator can re-enter settings in UI if ini write failed.
    }

    $pdo->exec('DROP TABLE IF EXISTS base_audio_settings');
};
