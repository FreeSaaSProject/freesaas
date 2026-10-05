<?php
declare(strict_types=1);

final class RecordsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'dt' => 'DT_RECORDED',
            'admin' => 'ID_ADMIN',
            'badge' => 'ID_DICTOPHONE',
            'playtime' => 'PLAYTIME',
            'score' => 'SCORE',
            'quality' => 'QUALITY',
            'disabled' => 'DISABLED',
        ];
    }

    /**
     * @param array{
     *   q?:string,search?:string,admin?:int,badge?:int,
     *   from?:int,to?:int,year?:int,month?:int,day?:int,
     *   disabled?:int,score?:int,score_lt?:int,
     *   quality?:int,errors_only?:int,unparsed?:int,
     *   order?:string,limit?:int,offset?:int
     * } $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_records')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        [$where, $params] = self::buildWhere($opts);
        $sqlWhere = implode(' AND ', $where);

        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_records WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? '')) ?: 'DT_RECORDED DESC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, ID_ADMIN AS id_admin, ID_CLIENT AS id_client,
                       ID_DICTOPHONE AS id_dictophone, DT_RECORDED AS dt_recorded,
                       DT_TRANSCRIBED AS dt_transcribed, DT_PARSED AS dt_parsed,
                       FILENAME AS filename, PLAYTIME AS playtime, SCORE AS score,
                       DISABLED AS disabled, QUALITY AS quality, ERROR_TRANS AS error_trans,
                       KEY_TYPES AS key_types, STRINGS AS strings, STRINGS_NOK AS strings_nok,
                       VOLUME AS volume, OFFERS AS offers";
        if (!empty($opts['with_transcription'])) {
            $sql .= ', TRANSCRIPTION AS transcription';
        }
        $sql .= " FROM base_records WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /**
     * @param array<string,mixed> $opts
     * @return array{0:list<string>,1:list<mixed>}
     */
    private static function buildWhere(array $opts): array
    {
        $where = ['1=1'];
        $params = [];

        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(FILENAME LIKE ? OR CAST(ID AS CHAR) = ? OR CAST(ID_DICTOPHONE AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $q;
            $params[] = $q;
        }

        $search = trim((string)($opts['search'] ?? ''));
        if ($search !== '') {
            $where[] = 'TRANSCRIPTION LIKE ?';
            $params[] = '%' . $search . '%';
        }

        $admin = (int)($opts['admin'] ?? 0);
        if ($admin > 0) {
            $where[] = 'ID_ADMIN = ?';
            $params[] = $admin;
        }

        $badge = (int)($opts['badge'] ?? 0);
        if ($badge > 0) {
            $where[] = 'ID_DICTOPHONE = ?';
            $params[] = $badge;
        }

        $from = (int)($opts['from'] ?? 0);
        $to = (int)($opts['to'] ?? 0);
        $year = (int)($opts['year'] ?? 0);
        $month = (int)($opts['month'] ?? 0);
        $day = (int)($opts['day'] ?? 0);
        $rangeFrom = (int)($opts['range_from'] ?? 0);
        $rangeTo = (int)($opts['range_to'] ?? 0);

        if ($from < 1 && $year > 0) {
            if ($month > 0 && $day > 0) {
                $from = mktime(0, 0, 0, $month, $day, $year);
                $to = mktime(23, 59, 59, $month, $day, $year);
            } elseif ($month > 0) {
                $from = mktime(0, 0, 0, $month, 1, $year);
                $to = mktime(23, 59, 59, $month, (int)date('t', $from), $year);
            } else {
                $from = mktime(0, 0, 0, 1, 1, $year);
                $to = mktime(23, 59, 59, 12, 31, $year);
            }
        }

        // Explicit from↔to markers (oper DTSTART/DTEND) refine the window.
        if ($rangeFrom > 0) {
            $from = $from > 0 ? max($from, $rangeFrom) : $rangeFrom;
        }
        if ($rangeTo > 0) {
            $to = $to > 0 ? min($to, $rangeTo) : $rangeTo;
        }

        if ($from > 0) {
            $where[] = 'DT_RECORDED >= ?';
            $params[] = $from;
        }
        if ($to > 0) {
            $where[] = 'DT_RECORDED <= ?';
            $params[] = $to;
        }

        $disabled = (int)($opts['disabled'] ?? 0); // 0=all, 1=active only, 2=disabled only
        if ($disabled === 1) {
            $where[] = 'DISABLED = 0';
        } elseif ($disabled === 2) {
            $where[] = 'DISABLED = 1';
        }

        $score = (int)($opts['score'] ?? -1);
        if ($score >= 0) {
            $where[] = 'SCORE = ?';
            $params[] = $score;
        }
        $scoreLt = (int)($opts['score_lt'] ?? 0);
        if ($scoreLt > 0) {
            $where[] = 'SCORE < ?';
            $params[] = $scoreLt;
        }

        $quality = (int)($opts['quality'] ?? -1);
        if ($quality >= 0) {
            $where[] = 'QUALITY = ?';
            $params[] = $quality;
        }

        if (!empty($opts['errors_only'])) {
            $where[] = '(ERROR_TRANS = 1 OR (QUALITY < 2 AND PLAYTIME < 1))';
        }
        if (!empty($opts['unparsed'])) {
            $where[] = '(DT_PARSED < 1 OR DT_PARSED IS NULL)';
        }

        return [$where, $params];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_records')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_ADMIN AS id_admin, ID_CLIENT AS id_client,
                    ID_DICTOPHONE AS id_dictophone, DT_CREATE AS dt_create,
                    DT_RECORDED AS dt_recorded, DT_TRANSCRIBED AS dt_transcribed,
                    DT_PARSED AS dt_parsed, FILENAME AS filename,
                    TRANSCRIPTION AS transcription, DESCRIPTION AS description,
                    PLAYTIME AS playtime, OFFERS AS offers, VOLUME AS volume,
                    QUALITY AS quality, DISABLED AS disabled, KEY_TYPES AS key_types,
                    SCORE AS score, STRINGS AS strings, STRINGS_NOK AS strings_nok,
                    ERROR_TRANS AS error_trans, INTERSECTIONS AS intersections
             FROM base_records WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param list<int> $ids
     * @return array<int,array<string,mixed>> id => row (with transcription)
     */
    public static function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if (!$ids || !Database::tableExists('base_records')) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, ID_ADMIN AS id_admin, ID_CLIENT AS id_client,
                    ID_DICTOPHONE AS id_dictophone, DT_RECORDED AS dt_recorded,
                    DT_PARSED AS dt_parsed, FILENAME AS filename,
                    TRANSCRIPTION AS transcription, PLAYTIME AS playtime,
                    KEY_TYPES AS key_types, SCORE AS score, DISABLED AS disabled,
                    ERROR_TRANS AS error_trans
             FROM base_records WHERE ID IN ($in)"
        );
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int)$row['id']] = $row;
        }
        return $out;
    }

    /**
     * Instances for one or many records, joined with key/type colors.
     *
     * @param list<int> $recordIds
     * @return list<array<string,mixed>>
     */
    public static function instancesForRecords(array $recordIds): array
    {
        $recordIds = array_values(array_filter(array_map('intval', $recordIds), static fn ($i) => $i > 0));
        if (!$recordIds || !Database::tableExists('base_records_instances')) {
            return [];
        }
        $in = implode(',', array_fill(0, count($recordIds), '?'));
        $hasKeys = Database::tableExists('base_records_keys');
        $hasTypes = Database::tableExists('base_records_types');

        if ($hasKeys && $hasTypes) {
            $sql = "SELECT i.ID AS id, i.ID_RECORD AS id_record, i.STRING_NUM AS string_num,
                           i.STRING_POSITION AS string_position, i.ID_KEY AS id_key, i.LENGTH AS length,
                           i.DT_START AS dt_start, i.DT_END AS dt_end,
                           k.NAME AS key_name, k.ID_TYPE AS id_type, k.ID_DICTONARY AS id_dictonary,
                           t.NAME AS type_name, t.COLOR AS type_color, t.COLOR_BACKGROUND AS type_bg
                    FROM base_records_instances i
                    LEFT JOIN base_records_keys k ON k.ID = i.ID_KEY
                    LEFT JOIN base_records_types t ON t.ID = k.ID_TYPE
                    WHERE i.ID_RECORD IN ($in)
                    ORDER BY i.ID_RECORD ASC, i.STRING_NUM ASC, i.STRING_POSITION ASC";
        } else {
            $sql = "SELECT i.ID AS id, i.ID_RECORD AS id_record, i.STRING_NUM AS string_num,
                           i.STRING_POSITION AS string_position, i.ID_KEY AS id_key, i.LENGTH AS length,
                           i.DT_START AS dt_start, i.DT_END AS dt_end,
                           '' AS key_name, 0 AS id_type, 0 AS id_dictonary,
                           '' AS type_name, '#000' AS type_color, '#ff0' AS type_bg
                    FROM base_records_instances i
                    WHERE i.ID_RECORD IN ($in)
                    ORDER BY i.ID_RECORD ASC, i.STRING_NUM ASC, i.STRING_POSITION ASC";
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute($recordIds);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<string> */
    public static function trashPhrases(): array
    {
        if (!Database::tableExists('base_records_trashes')) {
            return [];
        }
        $rows = Database::pdo()->query(
            'SELECT NAME FROM base_records_trashes WHERE ENABLED = 1'
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $out = [];
        foreach ($rows as $name) {
            $name = trim((string)$name);
            if ($name !== '') {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * @return array<int,array{name:string,color:string,bg:string}>
     */
    public static function typeMetaMap(): array
    {
        if (!Database::tableExists('base_records_types')) {
            return [];
        }
        $out = [];
        foreach (Database::pdo()->query(
            'SELECT ID, NAME, COLOR, COLOR_BACKGROUND FROM base_records_types'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['ID']] = [
                'name' => (string)$r['NAME'],
                'color' => (string)($r['COLOR'] ?: '#000'),
                'bg' => (string)($r['COLOR_BACKGROUND'] ?: '#ff0'),
            ];
        }
        return $out;
    }

    public static function setDisabled(int $id, bool $disabled): void
    {
        $st = Database::pdo()->prepare('UPDATE base_records SET DISABLED = ? WHERE ID = ?');
        $st->execute([$disabled ? 1 : 0, $id]);
    }

    /** @param list<int> $ids */
    public static function setDisabledByIds(array $ids, bool $disabled): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0));
        if (!$ids) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$disabled ? 1 : 0], $ids);
        $st = Database::pdo()->prepare("UPDATE base_records SET DISABLED = ? WHERE ID IN ($in)");
        $st->execute($params);
        return $st->rowCount();
    }

    /** @param list<int> $ids */
    public static function setAdminByIds(array $ids, int $adminId): int
    {
        $ids = self::normalizeIds($ids);
        if (!$ids || $adminId < 1) {
            return 0;
        }
        self::deleteInstancesForIds($ids);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$adminId], $ids);
        $st = Database::pdo()->prepare("UPDATE base_records SET ID_ADMIN = ? WHERE ID IN ($in)");
        $st->execute($params);
        return $st->rowCount();
    }

    /** @param list<int> $ids */
    public static function resetAdminByIds(array $ids): int
    {
        $ids = self::normalizeIds($ids);
        if (!$ids) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("UPDATE base_records SET ID_ADMIN = 0 WHERE ID IN ($in)");
        $st->execute($ids);
        return $st->rowCount();
    }

    /** @param list<int> $ids */
    public static function clearTranscriptionByIds(array $ids, bool $keepReplicate = false): int
    {
        $ids = self::normalizeIds($ids);
        if (!$ids) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        if ($keepReplicate) {
            $sql = "UPDATE base_records SET TRANSCRIPTION = '', DT_TRANSCRIBED = 0, QUALITY = 0 WHERE ID IN ($in)";
        } else {
            $sql = "UPDATE base_records SET TRANSCRIPTION = '', DT_TRANSCRIBED = 0, QUALITY = 0,
                    REPLICATECOM_ID = '', ERROR_TRANS = 0 WHERE ID IN ($in)";
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute($ids);
        return $st->rowCount();
    }

    /** @param list<int> $ids */
    public static function resetKeysByIds(array $ids): int
    {
        $ids = self::normalizeIds($ids);
        if (!$ids) {
            return 0;
        }
        self::deleteInstancesForIds($ids);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            "UPDATE base_records SET DESCRIPTION = '', KEY_TYPES = '', DT_PARSED = 0,
             STRINGS = 0, STRINGS_NOK = 0 WHERE ID IN ($in)"
        );
        $st->execute($ids);
        return $st->rowCount();
    }

    /** @param list<int> $ids */
    public static function resetOffersByIds(array $ids): int
    {
        $ids = self::normalizeIds($ids);
        if (!$ids) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("UPDATE base_records SET OFFERS = 0 WHERE ID IN ($in)");
        $st->execute($ids);
        return $st->rowCount();
    }

    /** @param list<int> $ids */
    public static function resetStringsByIds(array $ids): int
    {
        $ids = self::normalizeIds($ids);
        if (!$ids) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("UPDATE base_records SET STRINGS = 0, STRINGS_NOK = 0 WHERE ID IN ($in)");
        $st->execute($ids);
        return $st->rowCount();
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids, bool $deleteFiles = false): int
    {
        $ids = self::normalizeIds($ids);
        if (!$ids) {
            return 0;
        }
        if ($deleteFiles) {
            self::unlinkFilesForIds($ids);
        }
        self::deleteInstancesForIds($ids);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_records WHERE ID IN ($in)");
        $st->execute($ids);
        return $st->rowCount();
    }

    /**
     * Aggregate stats for the current list page (same idea as oper footer).
     *
     * @param list<array<string,mixed>> $items
     * @return array{
     *   page_count:int,playtime:int,errors:int,ok:int,strings:int,strings_nok:int,
     *   score_avg:float,offers:int,
     *   by_admin:array<int,array{count:int,playtime:int,start:int,end:int}>,
     *   by_volume:array<int,int>,by_score:array<int,int>
     * }
     */
    public static function pageStats(array $items): array
    {
        $playtime = 0;
        $errors = 0;
        $strings = 0;
        $stringsNok = 0;
        $scoreSum = 0;
        $offers = 0;
        $byAdmin = [];
        $byVolume = [];
        $byScore = [];

        foreach ($items as $r) {
            $pt = (int)($r['playtime'] ?? 0);
            $playtime += $pt;
            $score = (int)($r['score'] ?? 0);
            $scoreSum += $score;
            $strings += (int)($r['strings'] ?? 0);
            $stringsNok += (int)($r['strings_nok'] ?? 0);
            $offers += (int)($r['offers'] ?? 0);

            $isErr = !empty($r['error_trans'])
                || (((int)($r['quality'] ?? 0) < 2) && $pt < 1);
            if ($isErr) {
                $errors++;
            }

            $aid = (int)($r['id_admin'] ?? 0);
            if ($aid > 0) {
                if (!isset($byAdmin[$aid])) {
                    $byAdmin[$aid] = ['count' => 0, 'playtime' => 0, 'start' => 0, 'end' => 0];
                }
                $byAdmin[$aid]['count']++;
                $byAdmin[$aid]['playtime'] += $pt;
                $dt = (int)($r['dt_recorded'] ?? 0);
                if ($dt > 0) {
                    if ($byAdmin[$aid]['start'] < 1 || $dt < $byAdmin[$aid]['start']) {
                        $byAdmin[$aid]['start'] = $dt;
                    }
                    if ($dt > $byAdmin[$aid]['end']) {
                        $byAdmin[$aid]['end'] = $dt;
                    }
                }
            }

            $vol = (float)($r['volume'] ?? 0);
            $bucket = $vol <= 0.0 ? 0 : (int)round($vol / 10);
            if (!isset($byVolume[$bucket])) {
                $byVolume[$bucket] = 0;
            }
            $byVolume[$bucket] += $pt;

            if (!isset($byScore[$score])) {
                $byScore[$score] = 0;
            }
            $byScore[$score] += $pt;
        }

        ksort($byVolume, SORT_NUMERIC);
        ksort($byScore, SORT_NUMERIC);
        $n = count($items);

        return [
            'page_count' => $n,
            'playtime' => $playtime,
            'errors' => $errors,
            'ok' => max(0, $n - $errors),
            'strings' => $strings,
            'strings_nok' => $stringsNok,
            'score_avg' => $n > 0 ? round($scoreSum / $n, 2) : 0.0,
            'offers' => $offers,
            'by_admin' => $byAdmin,
            'by_volume' => $byVolume,
            'by_score' => $byScore,
        ];
    }

    public static function formatHms(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return sprintf(
            '%02d:%02d:%02d',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60
        );
    }

    /** @param list<int> $ids @return list<int> */
    private static function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
    }

    /** @param list<int> $ids */
    private static function unlinkFilesForIds(array $ids): void
    {
        if (!$ids || !Database::tableExists('base_records')) {
            return;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("SELECT ID AS id, FILENAME AS filename FROM base_records WHERE ID IN ($in)");
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            return;
        }
        $storage = FileStorage::fromSettings();
        $root = $storage->documentRoot();
        foreach ($rows as $row) {
            $rel = self::playbackRelativePath($row);
            if ($rel === '') {
                continue;
            }
            $full = $root . $rel;
            if ($storage->exists($full)) {
                $storage->unlink($full);
            }
        }
    }

    /** @param list<int> $ids */
    private static function deleteInstancesForIds(array $ids): void
    {
        if (!$ids || !Database::tableExists('base_records_instances')) {
            return;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_records_instances WHERE ID_RECORD IN ($in)");
        $st->execute($ids);
    }

    /** @param list<int> $ids @return array<int,string> id => transcription */
    public static function transcriptionsForIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("SELECT ID, TRANSCRIPTION FROM base_records WHERE ID IN ($in)");
        $st->execute($ids);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[(int)$row['ID']] = (string)($row['TRANSCRIPTION'] ?? '');
        }
        return $out;
    }

    /** Playback path relative to storage root for a filename under parsed tree. */
    public static function playbackRelativePath(array $row): string
    {
        $fn = ltrim((string)($row['filename'] ?? $row['FILENAME'] ?? ''), '/');
        if ($fn === '') {
            return '';
        }
        if ($fn[0] === '/' || strpos($fn, 'audio') === 0) {
            return '/' . ltrim($fn, '/');
        }
        $cfg = AudioSettingsRepository::storageConfig();
        return '/' . trim($cfg['audio_parsed'], '/') . '/' . $fn;
    }
}
