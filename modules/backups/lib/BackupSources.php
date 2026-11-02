<?php
declare(strict_types=1);

/** Collect backup sources via EventBus `backups.sources`. */
final class BackupSources
{
    /** Absolute path prefixes always skipped inside file roots. */
    public static function globalExcludes(): array
    {
        $base = rtrim(base_path(), '/');
        return [
            $base . '/var/db_dumps',
            $base . '/var/backups',
            $base . '/var/sessions',
            $base . '/var/cache',
            $base . '/var/db_jobs',
            $base . '/var/cron',
            $base . '/var/updates',
        ];
    }

    /**
     * @return list<array{id:string,label_key:string,kind:string,paths:list<string>,excludes:list<string>,default:bool}>
     */
    public static function catalog(): array
    {
        $base = rtrim(base_path(), '/');
        $sources = [
            [
                'id' => 'db',
                'label_key' => 'backups.source.db',
                'kind' => 'db',
                'paths' => [],
                'excludes' => [],
                'default' => true,
            ],
            [
                'id' => 'cfg',
                'label_key' => 'backups.source.cfg',
                'kind' => 'cfg',
                'paths' => [$base . '/cfg'],
                'excludes' => [],
                'default' => true,
            ],
            [
                'id' => 'userfiles',
                'label_key' => 'backups.source.userfiles',
                'kind' => 'files',
                'paths' => [$base . '/UserFiles'],
                'excludes' => [],
                'default' => true,
            ],
            [
                'id' => 'uploads',
                'label_key' => 'backups.source.uploads',
                'kind' => 'files',
                'paths' => [$base . '/assets/uploads'],
                'excludes' => [],
                'default' => true,
            ],
            [
                'id' => 'themes',
                'label_key' => 'backups.source.themes',
                'kind' => 'files',
                'paths' => [$base . '/themes'],
                'excludes' => [],
                'default' => true,
            ],
            [
                'id' => 'log',
                'label_key' => 'backups.source.log',
                'kind' => 'files',
                'paths' => [$base . '/log'],
                'excludes' => [],
                'default' => false,
            ],
        ];

        $payload = EventBus::emit('backups.sources', ['sources' => $sources]);
        if (!is_array($payload) || !is_array($payload['sources'] ?? null)) {
            return $sources;
        }
        $out = [];
        $seen = [];
        foreach ($payload['sources'] as $s) {
            if (!is_array($s)) {
                continue;
            }
            $id = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)($s['id'] ?? '')))) ?? '';
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $paths = [];
            foreach ((array)($s['paths'] ?? []) as $p) {
                $p = (string)$p;
                if ($p !== '') {
                    $paths[] = $p;
                }
            }
            $ex = [];
            foreach ((array)($s['excludes'] ?? []) as $p) {
                $p = (string)$p;
                if ($p !== '') {
                    $ex[] = $p;
                }
            }
            $out[] = [
                'id' => $id,
                'label_key' => (string)($s['label_key'] ?? ('backups.source.' . $id)),
                'kind' => (string)($s['kind'] ?? 'files'),
                'paths' => $paths,
                'excludes' => $ex,
                'default' => !empty($s['default']),
            ];
        }
        return $out;
    }

    /**
     * Enabled sources for current config.
     *
     * @return list<array{id:string,label_key:string,kind:string,paths:list<string>,excludes:list<string>,default:bool}>
     */
    public static function selected(): array
    {
        $want = BackupConfig::sourceIds();
        $wantMap = array_fill_keys($want, true);
        $out = [];
        foreach (self::catalog() as $s) {
            if (isset($wantMap[$s['id']])) {
                $out[] = $s;
            }
        }
        return $out;
    }
}
