<?php
declare(strict_types=1);

/** Pull Clientics Users (executors) and map to base_users by phone/name. */
final class ClienticsUsersImporter
{
    /**
     * @return array{
     *   ok:bool,dry_run:bool,fetched:int,linked:int,skipped:int,error?:string,sample_errors?:list<string>
     * }
     */
    public static function run(bool $dryRun = false): array
    {
        $stats = [
            'ok' => false,
            'dry_run' => $dryRun,
            'fetched' => 0,
            'linked' => 0,
            'skipped' => 0,
            'sample_errors' => [],
        ];
        if (!ClienticsConfig::pullEnabled()) {
            $stats['error'] = 'pull disabled';
            ClienticsSyncStateRepository::mark('import_users', false, 'pull disabled', $stats);
            return $stats;
        }
        if (!ClienticsConfig::apiReady() || !ClienticsIdMapRepository::tableReady()) {
            $stats['error'] = 'api/schema not ready';
            ClienticsSyncStateRepository::mark('import_users', false, (string)$stats['error'], $stats);
            return $stats;
        }
        if (!class_exists('UsersRepository', false) || !Database::tableExists('base_users')) {
            $stats['error'] = 'base_users missing';
            ClienticsSyncStateRepository::mark('import_users', false, (string)$stats['error'], $stats);
            return $stats;
        }

        $accountId = ClienticsConfig::accountId();
        $offset = 0;
        $limit = ClienticsConfig::pageLimit();
        try {
            while (true) {
                $res = ClienticsApiClient::listUsers($offset, $limit);
                if (!$res['ok']) {
                    $stats['error'] = (string)($res['error'] ?? 'fetch failed');
                    ClienticsSyncStateRepository::mark('import_users', false, (string)$stats['error'], $stats);
                    return $stats;
                }
                $items = $res['items'] ?? [];
                if ($items === []) {
                    break;
                }
                foreach ($items as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $stats['fetched']++;
                    try {
                        $r = self::linkOne($accountId, $row, $dryRun);
                        $stats[$r]++;
                    } catch (Throwable $e) {
                        $stats['skipped']++;
                        if (count($stats['sample_errors']) < 5) {
                            $stats['sample_errors'][] = $e->getMessage();
                        }
                    }
                }
                if (count($items) < $limit) {
                    break;
                }
                $offset += $limit;
                if ($offset > 100000) {
                    break;
                }
            }
            $stats['ok'] = true;
            ClienticsSyncStateRepository::mark('import_users', true, '', $stats);
            return $stats;
        } catch (Throwable $e) {
            $stats['error'] = $e->getMessage();
            ClienticsSyncStateRepository::mark('import_users', false, $e->getMessage(), $stats);
            return $stats;
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return 'linked'|'skipped'
     */
    private static function linkOne(string $accountId, array $row, bool $dryRun): string
    {
        $extId = (string)($row['id'] ?? '');
        if ($extId === '' || $extId === '0' || !empty($row['archived'])) {
            return 'skipped';
        }
        $existingMap = ClienticsIdMapRepository::findLocalId($accountId, 'user', $extId);
        if ($existingMap !== null && UsersRepository::find($existingMap) !== null) {
            return 'linked';
        }

        $localId = null;
        $phone = ClienticsPhone::normalize((string)($row['phone'] ?? ''));
        if ($phone !== null) {
            foreach (UsersRepository::listEnabledStaff() as $u) {
                $login = ClienticsPhone::normalize((string)($u['login'] ?? ''));
                if ($login !== null && $login === $phone) {
                    $localId = (int)$u['id'];
                    break;
                }
            }
        }
        if ($localId === null) {
            $name = mb_strtolower(trim((string)($row['name'] ?? '')));
            if ($name !== '') {
                foreach (UsersRepository::listEnabledStaff() as $u) {
                    if (mb_strtolower(trim((string)($u['name'] ?? ''))) === $name) {
                        $localId = (int)$u['id'];
                        break;
                    }
                }
            }
        }
        if ($localId === null || $localId < 1) {
            return 'skipped';
        }
        if (!$dryRun) {
            ClienticsIdMapRepository::upsert($accountId, 'user', $extId, $localId);
        }
        return 'linked';
    }
}
