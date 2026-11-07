<?php
declare(strict_types=1);

/**
 * Pull Clientics Services and map to catalog products or procedure types by name when available.
 * Unmatched services stay unmapped (appointment importer puts names into event descr).
 */
final class ClienticsServicesImporter
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
            ClienticsSyncStateRepository::mark('import_services', false, 'pull disabled', $stats);
            return $stats;
        }
        if (!ClienticsConfig::apiReady() || !ClienticsIdMapRepository::tableReady()) {
            $stats['error'] = 'api/schema not ready';
            ClienticsSyncStateRepository::mark('import_services', false, (string)$stats['error'], $stats);
            return $stats;
        }

        $accountId = ClienticsConfig::accountId();
        $nameIndex = self::buildLocalNameIndex();
        $offset = 0;
        $limit = ClienticsConfig::pageLimit();
        try {
            while (true) {
                $res = ClienticsApiClient::listServices($offset, $limit);
                if (!$res['ok']) {
                    $stats['error'] = (string)($res['error'] ?? 'fetch failed');
                    ClienticsSyncStateRepository::mark('import_services', false, (string)$stats['error'], $stats);
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
                        $r = self::linkOne($accountId, $row, $nameIndex, $dryRun);
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
            ClienticsSyncStateRepository::mark('import_services', true, '', $stats);
            return $stats;
        } catch (Throwable $e) {
            $stats['error'] = $e->getMessage();
            ClienticsSyncStateRepository::mark('import_services', false, $e->getMessage(), $stats);
            return $stats;
        }
    }

    /**
     * @return array<string,array{entity:string,id:int}>
     */
    private static function buildLocalNameIndex(): array
    {
        $out = [];
        if (class_exists('ModuleManager', false) && ModuleManager::isEnabled('procedures')
            && Database::tableExists('base_procedure_types')) {
            $st = Database::pdo()->query('SELECT ID AS id, TYPE AS name FROM base_procedure_types LIMIT 2000');
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $key = mb_strtolower(trim((string)($r['name'] ?? '')));
                if ($key !== '' && !isset($out[$key])) {
                    $out[$key] = ['entity' => 'procedure_type', 'id' => (int)$r['id']];
                }
            }
        }
        if (class_exists('ModuleManager', false) && ModuleManager::isEnabled('catalog')
            && Database::tableExists('base_products')) {
            $idCol = Database::columnExists('base_products', 'ID') ? 'ID' : 'id';
            $nameCol = Database::columnExists('base_products', 'NAME') ? 'NAME' : 'name';
            $st = Database::pdo()->query(
                "SELECT {$idCol} AS id, {$nameCol} AS name FROM base_products LIMIT 5000"
            );
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $key = mb_strtolower(trim((string)($r['name'] ?? '')));
                if ($key !== '' && !isset($out[$key])) {
                    $out[$key] = ['entity' => 'product', 'id' => (int)$r['id']];
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,array{entity:string,id:int}> $nameIndex
     * @return 'linked'|'skipped'
     */
    private static function linkOne(string $accountId, array $row, array $nameIndex, bool $dryRun): string
    {
        $extId = (string)($row['id'] ?? '');
        if ($extId === '' || $extId === '0' || !empty($row['archived'])) {
            return 'skipped';
        }
        if (ClienticsIdMapRepository::findLocalId($accountId, 'service', $extId) !== null) {
            return 'linked';
        }
        $name = mb_strtolower(trim((string)($row['name'] ?? '')));
        if ($name === '' || !isset($nameIndex[$name])) {
            return 'skipped';
        }
        $localId = (int)$nameIndex[$name]['id'];
        if ($localId < 1) {
            return 'skipped';
        }
        if (!$dryRun) {
            ClienticsIdMapRepository::upsert($accountId, 'service', $extId, $localId);
        }
        return 'linked';
    }
}
