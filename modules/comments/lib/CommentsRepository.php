<?php
declare(strict_types=1);

final class CommentsRepository
{
    /**
     * Flat comments for a news object (includes hidden for author/superadmin filtering in UI).
     * @return list<array>
     */
    public static function forNews(int $newsId, bool $includeHidden = true): array
    {
        if ($newsId < 1 || !Database::tableExists('base_comments')) {
            return [];
        }
        $sql = 'SELECT * FROM base_comments WHERE ID_OBJECT = ?';
        if (!$includeHidden) {
            $sql .= ' AND DISABLED = 0';
        }
        $sql .= ' ORDER BY DT ASC, ID ASC';
        $st = Database::pdo()->prepare($sql);
        $st->execute([$newsId]);
        return $st->fetchAll() ?: [];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_comments')) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_comments WHERE ID = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** Adjacency list → nested forest. Each node gets `_children`. */
    public static function buildTree(array $rows): array
    {
        $byId = [];
        foreach ($rows as $r) {
            $id = (int)$r['ID'];
            $r['_children'] = [];
            $byId[$id] = $r;
        }
        $roots = [];
        foreach ($byId as $id => $node) {
            $pid = (int)$node['PID'];
            if ($pid > 0 && isset($byId[$pid])) {
                $byId[$pid]['_children'][] = $id;
            } else {
                $roots[] = $id;
            }
        }
        $hydrate = static function (int $id) use (&$hydrate, &$byId): array {
            $node = $byId[$id];
            $kids = [];
            foreach ($node['_children'] as $childId) {
                $kids[] = $hydrate((int)$childId);
            }
            $node['_children'] = $kids;
            return $node;
        };
        $out = [];
        foreach ($roots as $rid) {
            $out[] = $hydrate((int)$rid);
        }
        return $out;
    }

    public static function hasChildren(int $commentId): bool
    {
        if ($commentId < 1) {
            return false;
        }
        $st = Database::pdo()->prepare('SELECT 1 FROM base_comments WHERE PID = ? LIMIT 1');
        $st->execute([$commentId]);
        return (bool)$st->fetchColumn();
    }

    public static function add(int $newsId, int $userId, string $text, array $extra = []): int
    {
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException(t('comments.err.empty'));
        }
        $statusUpdate = !empty($extra['STATUS_UPDATE']) ? 1 : 0;
        $st = Database::pdo()->prepare(
            'INSERT INTO base_comments SET
                ID_OBJECT=?, DT=?, ID_USER=?, TEXT=?, TABLE_NAME=?, DISABLED=0, PID=?,
                COLOR=?, STATUS_UPDATE=?, PRICE_OFFER=?, DT_OFFER_ACCEPT=0, DT_WORK_ACCEPT=0,
                ID_ACCEPTOR=0, BUDGET_OFFER=?, DT_DEADLINE=?, ID_PAYMENT=0, DT_FAIL=0'
        );
        $st->execute([
            $newsId,
            time(),
            $userId,
            $text,
            '',
            (int)($extra['PID'] ?? 0),
            (string)($extra['COLOR'] ?? '0'),
            $statusUpdate,
            (int)($extra['PRICE_OFFER'] ?? 0),
            (int)($extra['BUDGET_OFFER'] ?? 0),
            (int)($extra['DT_DEADLINE'] ?? 0),
        ]);
        if (Database::tableExists('base_views')) {
            $del = Database::pdo()->prepare(
                'DELETE FROM base_views WHERE ID_OBJ = ? AND TYPE_OBJ = ?'
            );
            $del->execute([$newsId, 'base_news']);
        }
        return (int)Database::pdo()->lastInsertId();
    }

    public static function setDisabled(int $id, bool $disabled): void
    {
        $st = Database::pdo()->prepare('UPDATE base_comments SET DISABLED = ? WHERE ID = ?');
        $st->execute([$disabled ? 1 : 0, $id]);
    }

    public static function cycleColor(int $id): int
    {
        $row = self::find($id);
        if (!$row) {
            return 0;
        }
        $cur = (int)$row['COLOR'];
        $next = ($cur + 1) % 3;
        Database::pdo()->prepare('UPDATE base_comments SET COLOR = ? WHERE ID = ?')->execute([(string)$next, $id]);
        return $next;
    }

    public static function updateText(int $id, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException(t('comments.err.empty'));
        }
        Database::pdo()->prepare('UPDATE base_comments SET TEXT = ? WHERE ID = ?')->execute([$text, $id]);
    }

    public static function deleteIfAllowed(int $id, int $userId): bool
    {
        $row = self::find($id);
        if (!$row) {
            return false;
        }
        if ((int)$row['ID_USER'] !== $userId) {
            return false;
        }
        if ((int)$row['STATUS_UPDATE'] === 1) {
            return false;
        }
        if (self::hasChildren($id)) {
            return false;
        }
        // 4 hours window (legacy)
        if ((int)$row['DT'] < time() - 4 * 3600) {
            return false;
        }
        Database::pdo()->prepare('DELETE FROM base_comments WHERE ID = ?')->execute([$id]);
        if (Database::tableExists('base_likes')) {
            Database::pdo()->prepare(
                'DELETE FROM base_likes WHERE ID_OBJ = ? AND TYPE_OBJ = ?'
            )->execute([$id, 'base_comments']);
        }
        return true;
    }

    public static function canModerate(array $comment, int $userId): bool
    {
        return Auth::isSuperAdmin() || ((int)$comment['ID_USER'] === $userId);
    }

    /** @param list<int> $newsIds @return array<int,int> newsId => visible comment count */
    public static function countsForNews(array $newsIds): array
    {
        $out = [];
        $ids = array_values(array_filter(array_map('intval', $newsIds)));
        if (!$ids || !Database::tableExists('base_comments')) {
            return $out;
        }
        $in = implode(',', $ids);
        $rows = Database::pdo()->query(
            "SELECT ID_OBJECT, COUNT(*) AS c FROM base_comments
             WHERE DISABLED = 0 AND ID_OBJECT IN ($in) GROUP BY ID_OBJECT"
        )->fetchAll();
        foreach ($rows as $r) {
            $out[(int)$r['ID_OBJECT']] = (int)$r['c'];
        }
        return $out;
    }
}
