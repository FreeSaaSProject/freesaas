<?php
declare(strict_types=1);

/**
 * Script collection builder from base_records_instances (simplified port of recordCollector).
 * Rebuilds collections for admins that have newer instances than existing collections.
 */
final class CollectRunner
{
    /**
     * @return array{ok:bool,processed:int,skipped:int,message:string,extra?:array}
     */
    public static function run(bool $fullRebuild = false): array
    {
        JobRunner::applyTimeLimit();
        $deadline = JobRunner::deadline();

        if (!Database::tableExists('base_records_instances')
            || !Database::tableExists('base_records_collections')
            || !Database::tableExists('base_records_scripts')
        ) {
            return ['ok' => false, 'processed' => 0, 'skipped' => 0, 'message' => 'tables_missing'];
        }

        $pdo = Database::pdo();
        // Orphans
        $pdo->exec(
            'DELETE FROM base_records_instances
             WHERE ID_RECORD > 0 AND ID_RECORD NOT IN (SELECT ID FROM base_records)'
        );

        if ($fullRebuild) {
            $pdo->exec('TRUNCATE TABLE base_records_collections_datas');
            $pdo->exec('TRUNCATE TABLE base_records_collections');
        }

        $scripts = self::loadScripts();
        if ($scripts['by_id'] === []) {
            return ['ok' => true, 'processed' => 0, 'skipped' => 0, 'message' => 'no_scripts'];
        }

        $adminIds = [];
        if ($fullRebuild) {
            $st = $pdo->query('SELECT DISTINCT ID_ADMIN FROM base_records_instances WHERE ID_ADMIN > 0');
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $aid) {
                $adminIds[] = (int)$aid;
            }
        } else {
            // Admins where max instance DT > max collection DT
            $sql = 'SELECT i.ID_ADMIN AS id_admin,
                           MAX(i.DT_START) AS max_i,
                           COALESCE(MAX(c.DT_START), 0) AS max_c
                    FROM base_records_instances i
                    LEFT JOIN base_records_collections c ON c.ID_ADMIN = i.ID_ADMIN
                    WHERE i.ID_ADMIN > 0
                    GROUP BY i.ID_ADMIN
                    HAVING max_i > max_c
                    LIMIT 50';
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $adminIds[] = (int)$row['id_admin'];
            }
        }

        $processed = 0;
        $collections = 0;
        foreach ($adminIds as $adminId) {
            if (JobRunner::timedOut($deadline)) {
                break;
            }
            if (!$fullRebuild) {
                // Clear this admin's collections then rebuild
                $ids = $pdo->prepare('SELECT ID FROM base_records_collections WHERE ID_ADMIN = ?');
                $ids->execute([$adminId]);
                $colIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN) ?: []);
                if ($colIds !== []) {
                    $in = implode(',', $colIds);
                    $pdo->exec("DELETE FROM base_records_collections_datas WHERE ID_COLLECTION IN ($in)");
                    $pdo->prepare('DELETE FROM base_records_collections WHERE ID_ADMIN = ?')->execute([$adminId]);
                }
            }
            $collections += self::rebuildAdmin($adminId, $scripts);
            $processed++;
        }

        return [
            'ok' => true,
            'processed' => $processed,
            'skipped' => 0,
            'message' => 'collect_done',
            'extra' => ['collections' => $collections, 'admins' => count($adminIds)],
        ];
    }

    /**
     * @return array{by_id:array<int,array>,key_to_parts:array<int,list<array{script:int,part:int}>>}
     */
    private static function loadScripts(): array
    {
        $byId = [];
        $keyToParts = [];
        $rows = Database::pdo()->query(
            'SELECT ID, PID, NAME, DISABLED, IS_REQ, FINISH, SCORE, `BREAK`, TIMEOUT,
                    DIVERSITY, INSTANCES_MIN, COLLECTION_KILLER, IDS_DICTONARYS
             FROM base_records_scripts WHERE DISABLED < 1'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $id = (int)$row['ID'];
            $byId[$id] = [
                'id' => $id,
                'pid' => (int)$row['PID'],
                'is_req' => (int)$row['IS_REQ'],
                'finish' => (int)$row['FINISH'],
                'score' => (int)$row['SCORE'],
                'break' => (int)$row['BREAK'],
                'timeout' => (int)$row['TIMEOUT'],
                'diversity' => (int)$row['DIVERSITY'],
                'instances_min' => (int)$row['INSTANCES_MIN'],
                'killer' => (int)$row['COLLECTION_KILLER'],
            ];
        }

        // Map keys → script parts via dictionary membership on keys.ID_DICTONARY ∈ script.IDS_DICTONARYS
        // Fallback: keys whose NAME appears — too heavy; use dict lists on each script part
        if (Database::tableExists('base_records_keys')) {
            $keys = Database::pdo()->query(
                'SELECT ID, ID_DICTONARY FROM base_records_keys WHERE DISABLED < 1'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $keysByDict = [];
            foreach ($keys as $k) {
                $did = (int)$k['ID_DICTONARY'];
                if ($did > 0) {
                    $keysByDict[$did][] = (int)$k['ID'];
                }
            }
            foreach ($rows as $row) {
                $partId = (int)$row['ID'];
                $scriptId = (int)$row['PID'] > 0 ? (int)$row['PID'] : $partId;
                // Root scripts have PID=0; parts have PID=script root
                if ((int)$row['PID'] === 0) {
                    continue; // root container
                }
                $dicts = self::parseIds((string)($row['IDS_DICTONARYS'] ?? ''));
                foreach ($dicts as $did) {
                    foreach ($keysByDict[$did] ?? [] as $keyId) {
                        $keyToParts[$keyId][] = ['script' => (int)$row['PID'], 'part' => $partId];
                    }
                }
            }
        }

        return ['by_id' => $byId, 'key_to_parts' => $keyToParts];
    }

    /** @return list<int> */
    private static function parseIds(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[#,\s]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $n = (int)$p;
            if ($n > 0) {
                $out[] = $n;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param array{by_id:array,key_to_parts:array} $scripts
     */
    private static function rebuildAdmin(int $adminId, array $scripts): int
    {
        $st = Database::pdo()->prepare(
            'SELECT ID, DT_START, DT_END, ID_KEY, ID_RECORD
             FROM base_records_instances
             WHERE ID_ADMIN = ?
             ORDER BY DT_START ASC, ID ASC'
        );
        $st->execute([$adminId]);
        $instances = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($instances === []) {
            return 0;
        }

        /** @var array<int, array{save:int,finish:int,timeout:int,last:int,parts:array<int,list<array>>}> $open */
        $open = [];
        $saved = 0;

        foreach ($instances as $inst) {
            $keyId = (int)$inst['ID_KEY'];
            $dtStart = (int)$inst['DT_START'];
            $dtEnd = (int)$inst['DT_END'] > 0 ? (int)$inst['DT_END'] : $dtStart;
            $matches = $scripts['key_to_parts'][$keyId] ?? [];
            if ($matches === []) {
                continue;
            }

            foreach ($matches as $m) {
                $scriptId = (int)$m['script'];
                $partId = (int)$m['part'];
                $partMeta = $scripts['by_id'][$partId] ?? null;
                if ($partMeta === null) {
                    continue;
                }

                if (!isset($open[$scriptId])) {
                    $open[$scriptId] = [
                        'save' => 0,
                        'finish' => 0,
                        'timeout' => max(0, (int)$partMeta['timeout']),
                        'last' => $dtEnd,
                        'parts' => [],
                    ];
                } else {
                    // Timeout flush
                    $sinse = $dtStart - $open[$scriptId]['last'];
                    $limit = $open[$scriptId]['timeout'];
                    if ($limit > 0 && $sinse > $limit) {
                        $saved += self::flushOpen($adminId, $scriptId, $open[$scriptId], $scripts['by_id']);
                        unset($open[$scriptId]);
                        $open[$scriptId] = [
                            'save' => 0,
                            'finish' => 0,
                            'timeout' => max(0, (int)$partMeta['timeout']),
                            'last' => $dtEnd,
                            'parts' => [],
                        ];
                    }
                    if (!empty($partMeta['break']) && isset($open[$scriptId]['parts'][$partId])) {
                        $saved += self::flushOpen($adminId, $scriptId, $open[$scriptId], $scripts['by_id']);
                        unset($open[$scriptId]);
                        $open[$scriptId] = [
                            'save' => 0,
                            'finish' => 0,
                            'timeout' => max(0, (int)$partMeta['timeout']),
                            'last' => $dtEnd,
                            'parts' => [],
                        ];
                    }
                }

                if (!empty($partMeta['killer'])) {
                    unset($open[$scriptId]);
                    continue;
                }

                $open[$scriptId]['parts'][$partId][] = [
                    'id' => (int)$inst['ID'],
                    'dt_start' => $dtStart,
                    'id_key' => $keyId,
                    'score' => (int)$partMeta['score'],
                ];
                $open[$scriptId]['timeout'] = max(0, (int)$partMeta['timeout']);
                $open[$scriptId]['last'] = $dtEnd;
                if (!empty($partMeta['is_req'])) {
                    $open[$scriptId]['save'] = 1;
                }
                if (!empty($partMeta['finish'])) {
                    $open[$scriptId]['finish'] = 1;
                    $open[$scriptId]['save'] = 1;
                }
            }
        }

        foreach ($open as $scriptId => $state) {
            $saved += self::flushOpen($adminId, $scriptId, $state, $scripts['by_id']);
        }
        return $saved;
    }

    /**
     * @param array{save:int,finish:int,timeout:int,last:int,parts:array} $state
     * @param array<int,array> $byId
     */
    private static function flushOpen(int $adminId, int $scriptId, array $state, array $byId): int
    {
        if (empty($state['save']) || empty($state['parts'])) {
            return 0;
        }
        $score = 0;
        $dtStart = 0;
        $dtEnd = 0;
        $datas = [];
        foreach ($state['parts'] as $partId => $instances) {
            $partScore = 0;
            foreach ($instances as $inst) {
                $partScore = max($partScore, (int)$inst['score']);
                if ($dtStart === 0 || $inst['dt_start'] < $dtStart) {
                    $dtStart = (int)$inst['dt_start'];
                }
                if ($inst['dt_start'] > $dtEnd) {
                    $dtEnd = (int)$inst['dt_start'];
                }
                $datas[] = [
                    'part' => (int)$partId,
                    'instance' => (int)$inst['id'],
                    'key' => (int)$inst['id_key'],
                    'dt' => (int)$inst['dt_start'],
                ];
            }
            $score += $partScore;
        }
        if ($dtStart < 1) {
            return 0;
        }
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO base_records_collections (DT_START, DT_END, ID_ADMIN, ID_SCRIPT, SCORE)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$dtStart, $dtEnd, $adminId, $scriptId, $score]);
        $colId = (int)$pdo->lastInsertId();
        if ($colId < 1) {
            return 0;
        }
        $ins = $pdo->prepare(
            'INSERT INTO base_records_collections_datas
             (DT_START, ID_COLLECTION, ID_PART, ID_INSTANCE, ID_KEY) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($datas as $d) {
            $ins->execute([$d['dt'], $colId, $d['part'], $d['instance'], $d['key']]);
        }
        return 1;
    }
}
