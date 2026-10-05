<?php
declare(strict_types=1);

/**
 * Record review queue and favorites (oper base_records_reviews).
 */
final class RecordsReviewsRepository
{
    public static function tableExists(): bool
    {
        return Database::tableExists('base_records_reviews');
    }

    /**
     * @param list<int> $recordIds
     * @return array<int, array{dt_create:int,dt_review:int,dt_select:int,id_reviewer:int}>
     */
    public static function mapByRecordIds(array $recordIds): array
    {
        $recordIds = array_values(array_filter(array_map('intval', $recordIds)));
        if ($recordIds === [] || !self::tableExists()) {
            return [];
        }
        $in = implode(',', $recordIds);
        $st = Database::pdo()->query(
            "SELECT ID_RECORD AS id_record, DT_CREATE AS dt_create, DT_REVIEW AS dt_review,
                    DT_SELECT AS dt_select, ID_REVIEWER AS id_reviewer
             FROM base_records_reviews WHERE ID_RECORD IN ($in)"
        );
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rid = (int)$row['id_record'];
            $out[$rid] = [
                'dt_create' => (int)($row['dt_create'] ?? 0),
                'dt_review' => (int)($row['dt_review'] ?? 0),
                'dt_select' => (int)($row['dt_select'] ?? 0),
                'id_reviewer' => (int)($row['id_reviewer'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $recordIds
     */
    public static function markReviewed(array $recordIds, int $reviewerId): int
    {
        $recordIds = array_values(array_filter(array_map('intval', $recordIds)));
        if ($recordIds === [] || $reviewerId < 1 || !self::tableExists()) {
            return 0;
        }
        $now = time();
        $in = implode(',', $recordIds);
        $st = Database::pdo()->prepare(
            "UPDATE base_records_reviews SET ID_REVIEWER = ?, DT_REVIEW = ? WHERE ID_RECORD IN ($in)"
        );
        $st->execute([$reviewerId, $now]);

        return $st->rowCount();
    }

    /**
     * @param list<int> $recordIds
     */
    public static function addFavorite(array $recordIds, int $userId): int
    {
        $recordIds = array_values(array_filter(array_map('intval', $recordIds)));
        if ($recordIds === [] || $userId < 1 || !self::tableExists()) {
            return 0;
        }
        $pdo = Database::pdo();
        $now = time();
        $done = 0;
        $stExists = $pdo->prepare('SELECT ID FROM base_records_reviews WHERE ID_RECORD = ? LIMIT 1');
        $stUpdate = $pdo->prepare('UPDATE base_records_reviews SET DT_SELECT = ? WHERE ID_RECORD = ?');
        $stInsert = $pdo->prepare(
            'INSERT INTO base_records_reviews (ID_RECORD, ID_REVIEWER, DT_REVIEW, DT_CREATE, DT_SELECT)
             VALUES (?, ?, ?, 0, ?)'
        );
        foreach ($recordIds as $rid) {
            $stExists->execute([$rid]);
            if ($stExists->fetchColumn()) {
                $stUpdate->execute([$now, $rid]);
                if ($stUpdate->rowCount() > 0) {
                    $done++;
                }
            } else {
                $stInsert->execute([$rid, $userId, $now, $now]);
                $done++;
            }
        }

        return $done;
    }

    /**
     * @param list<int> $recordIds
     */
    public static function removeFavorite(array $recordIds): int
    {
        $recordIds = array_values(array_filter(array_map('intval', $recordIds)));
        if ($recordIds === [] || !self::tableExists()) {
            return 0;
        }
        $pdo = Database::pdo();
        $in = implode(',', $recordIds);
        $done = (int)$pdo->exec("UPDATE base_records_reviews SET DT_SELECT = 0 WHERE ID_RECORD IN ($in)");
        $pdo->exec('DELETE FROM base_records_reviews WHERE DT_SELECT = 0 AND DT_CREATE = 0');

        return $done;
    }

    public static function cleanOrphans(): int
    {
        if (!self::tableExists() || !Database::tableExists('base_records')) {
            return 0;
        }

        return (int)Database::pdo()->exec(
            'DELETE brr FROM base_records_reviews brr
             LEFT JOIN base_records br ON br.ID = brr.ID_RECORD
             WHERE br.ID IS NULL'
        );
    }

    public static function countPending(): int
    {
        if (!self::tableExists()) {
            return 0;
        }

        return (int)Database::pdo()->query(
            'SELECT COUNT(*) FROM base_records_reviews WHERE DT_CREATE > 0 AND DT_REVIEW < 1'
        )->fetchColumn();
    }

    public static function countPendingUnreviewed(): int
    {
        if (!self::tableExists()) {
            return 0;
        }

        return (int)Database::pdo()->query(
            'SELECT COUNT(*) FROM base_records_reviews WHERE DT_REVIEW < 1'
        )->fetchColumn();
    }

    public static function markAllReviewed(): int
    {
        if (!self::tableExists()) {
            return 0;
        }
        $now = time();

        return (int)Database::pdo()->exec(
            "UPDATE base_records_reviews SET DT_REVIEW = {$now} WHERE DT_REVIEW < 1"
        );
    }

    /**
     * Pending (DT_REVIEW&lt;1) and selected counts per admin/day in range.
     *
     * @param array{admin?:int,badge?:int,position?:int} $filters
     * @return array{
     *   by_admin_day: array<int, array<string, array{pending:int,selected:int}>>
     * }
     */
    public static function reviewsForRange(int $fromTs, int $toTs, array $filters = []): array
    {
        $empty = ['by_admin_day' => []];
        if ($fromTs < 1 || $toTs < $fromTs || !self::tableExists() || !Database::tableExists('base_records')) {
            return $empty;
        }

        $adminFilter = max(0, (int)($filters['admin'] ?? 0));
        $badgeFilter = max(0, (int)($filters['badge'] ?? 0));
        $positionFilter = max(0, (int)($filters['position'] ?? 0));

        $sql = 'SELECT r.ID AS id, r.ID_ADMIN AS id_admin, r.DT_RECORDED AS dt,
                       brr.DT_REVIEW AS dt_review, brr.DT_SELECT AS dt_select
                FROM base_records r
                INNER JOIN base_records_reviews brr ON brr.ID_RECORD = r.ID
                WHERE r.DT_RECORDED >= ? AND r.DT_RECORDED <= ?
                  AND (r.DISABLED IS NULL OR r.DISABLED < 1)';
        $params = [$fromTs, $toTs];
        if ($adminFilter > 0) {
            $sql .= ' AND r.ID_ADMIN = ?';
            $params[] = $adminFilter;
        }
        if ($badgeFilter > 0) {
            $sql .= ' AND r.ID_DICTOPHONE = ?';
            $params[] = $badgeFilter;
        }
        if ($positionFilter > 0 && Database::tableExists('base_users')) {
            $sql .= ' AND r.ID_ADMIN IN (SELECT id FROM base_users WHERE name_enabled = ?)';
            $params[] = $positionFilter;
        }

        $st = Database::pdo()->prepare($sql);
        $st->execute($params);

        $byAdminDay = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $dt = (int)($row['dt'] ?? 0);
            $aid = (int)($row['id_admin'] ?? 0);
            if ($dt < 1 || $aid < 1) {
                continue;
            }
            $dayKey = date('Y-m-d', $dt);
            if (!isset($byAdminDay[$aid][$dayKey])) {
                $byAdminDay[$aid][$dayKey] = ['pending' => 0, 'selected' => 0];
            }
            if ((int)($row['dt_review'] ?? 0) < 1) {
                $byAdminDay[$aid][$dayKey]['pending']++;
            } elseif ((int)($row['dt_select'] ?? 0) > 0) {
                $byAdminDay[$aid][$dayKey]['selected']++;
            }
        }

        return ['by_admin_day' => $byAdminDay];
    }

    /**
     * @return array{short:string,href:string,title:string,tone:string}|null
     */
    public static function noticeChip(): ?array
    {
        $n = self::countPending();
        if ($n < 1) {
            return null;
        }

        return [
            'short' => t('audio.notice.review_short', ['n' => $n]),
            'href' => '/audio?review=pending&year=0&month=0&day=0',
            'title' => t('audio.notice.review_title'),
            'tone' => 'danger',
        ];
    }
}
