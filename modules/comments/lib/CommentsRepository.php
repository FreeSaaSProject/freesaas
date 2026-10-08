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
        $disabled = !empty($extra['DISABLED']) ? 1 : 0;
        $offerAccept = array_key_exists('DT_OFFER_ACCEPT', $extra)
            ? (int)$extra['DT_OFFER_ACCEPT']
            : 0;
        $acceptor = (int)($extra['ID_ACCEPTOR'] ?? 0);
        $st = Database::pdo()->prepare(
            'INSERT INTO base_comments SET
                ID_OBJECT=?, DT=?, ID_USER=?, TEXT=?, TABLE_NAME=?, DISABLED=?, PID=?,
                COLOR=?, STATUS_UPDATE=?, PRICE_OFFER=?, DT_OFFER_ACCEPT=?, DT_WORK_ACCEPT=0,
                ID_ACCEPTOR=?, BUDGET_OFFER=?, DT_DEADLINE=?, ID_PAYMENT=0, DT_FAIL=0'
        );
        $st->execute([
            $newsId,
            time(),
            $userId,
            $text,
            '',
            $disabled,
            (int)($extra['PID'] ?? 0),
            (string)($extra['COLOR'] ?? '0'),
            $statusUpdate,
            (int)($extra['PRICE_OFFER'] ?? 0),
            $offerAccept,
            $acceptor,
            (int)($extra['BUDGET_OFFER'] ?? 0),
            (int)($extra['DT_DEADLINE'] ?? 0),
        ]);
        // Capture before other statements — DELETE can reset PDO lastInsertId (MyISAM).
        $newId = (int)Database::pdo()->lastInsertId();
        if (Database::tableExists('base_views')) {
            $del = Database::pdo()->prepare(
                'DELETE FROM base_views WHERE ID_OBJ = ? AND TYPE_OBJ = ?'
            );
            $del->execute([$newsId, 'base_news']);
        }
        return $newId;
    }

    /**
     * Update comment text and optional contract fields (PRICE_OFFER, deadlines, etc.).
     *
     * @param array<string,mixed> $extra
     */
    public static function updateFields(int $id, string $text, array $extra = []): bool
    {
        $text = trim($text);
        if ($id < 1 || $text === '' || !Database::tableExists('base_comments')) {
            return false;
        }
        $sets = ['TEXT = ?'];
        $params = [$text];
        foreach (['PID', 'PRICE_OFFER', 'BUDGET_OFFER', 'DT_DEADLINE', 'DT_OFFER_ACCEPT', 'ID_ACCEPTOR', 'DISABLED'] as $col) {
            if (array_key_exists($col, $extra)) {
                $sets[] = $col . ' = ?';
                $params[] = (int)$extra[$col];
            }
        }
        if (array_key_exists('COLOR', $extra)) {
            $sets[] = 'COLOR = ?';
            $params[] = (string)$extra['COLOR'];
        }
        $params[] = $id;
        $sql = 'UPDATE base_comments SET ' . implode(', ', $sets) . ' WHERE ID = ?';
        Database::pdo()->prepare($sql)->execute($params);
        return true;
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

    /**
     * Machine-readable comment for agent JSON.
     *
     * @param array<string,mixed>|null $row
     * @param array{excerpt?:int,child_count?:int} $opts
     * @return array<string,mixed>|null
     */
    public static function forAgent(?array $row, array $opts = []): ?array
    {
        if ($row === null) {
            return null;
        }
        $text = (string)($row['TEXT'] ?? '');
        $excerpt = isset($opts['excerpt']) ? (int)$opts['excerpt'] : 0;
        $truncated = false;
        if ($excerpt > 0 && mb_strlen($text) > $excerpt) {
            $text = mb_substr($text, 0, $excerpt);
            $truncated = true;
        }
        $out = [
            'id' => (int)($row['ID'] ?? 0),
            'news_id' => (int)($row['ID_OBJECT'] ?? 0),
            'pid' => (int)($row['PID'] ?? 0),
            'id_user' => (int)($row['ID_USER'] ?? 0),
            'dt' => (int)($row['DT'] ?? 0),
            'text' => $text,
            'text_truncated' => $truncated ? 1 : 0,
            'disabled' => !empty($row['DISABLED']) ? 1 : 0,
            'color' => (int)($row['COLOR'] ?? 0),
            'status_update' => !empty($row['STATUS_UPDATE']) ? 1 : 0,
        ];
        if (array_key_exists('child_count', $opts)) {
            $out['child_count'] = (int)$opts['child_count'];
        } elseif (array_key_exists('_child_count', $row)) {
            $out['child_count'] = (int)$row['_child_count'];
        }
        return $out;
    }

    /**
     * Paginated comments for agents (roots or direct children of pid).
     *
     * @param array{
     *   pid?:int,
     *   limit?:int,
     *   offset?:int,
     *   order?:string,
     *   excerpt?:int,
     *   include_hidden?:bool,
     *   id?:int
     * } $opts
     * @return array{
     *   items:list<array<string,mixed>>,
     *   total:int,
     *   returned:int,
     *   limit:int,
     *   offset:int,
     *   pid:int,
     *   order:string,
     *   excerpt:int,
     *   has_more:bool,
     *   next_offset:?int
     * }
     */
    public static function listForAgent(int $newsId, array $opts = []): array
    {
        $pid = max(0, (int)($opts['pid'] ?? 0));
        $limit = (int)($opts['limit'] ?? 20);
        if ($limit < 0) {
            $limit = 20;
        }
        $limit = min(100, $limit);
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $order = strtolower((string)($opts['order'] ?? 'desc'));
        if ($order !== 'asc' && $order !== 'desc') {
            $order = 'desc';
        }
        $excerpt = max(0, (int)($opts['excerpt'] ?? 500));
        $includeHidden = !empty($opts['include_hidden']);
        $singleId = max(0, (int)($opts['id'] ?? 0));

        $empty = [
            'items' => [],
            'total' => 0,
            'returned' => 0,
            'limit' => $limit,
            'offset' => $offset,
            'pid' => $pid,
            'order' => $order,
            'excerpt' => $excerpt,
            'has_more' => false,
            'next_offset' => null,
        ];
        if ($newsId < 1 || !Database::tableExists('base_comments')) {
            return $empty;
        }

        $pdo = Database::pdo();
        $where = ['ID_OBJECT = ?'];
        $params = [$newsId];
        if ($singleId > 0) {
            $where[] = 'ID = ?';
            $params[] = $singleId;
        } else {
            $where[] = 'PID = ?';
            $params[] = $pid;
        }
        if (!$includeHidden) {
            $where[] = 'DISABLED = 0';
        }
        $sqlWhere = implode(' AND ', $where);

        $stCount = $pdo->prepare("SELECT COUNT(*) FROM base_comments WHERE $sqlWhere");
        $stCount->execute($params);
        $total = (int)$stCount->fetchColumn();

        if ($limit === 0) {
            $empty['total'] = $total;
            return $empty;
        }

        $sql = "SELECT * FROM base_comments WHERE $sqlWhere ORDER BY DT $order, ID $order";
        if ($singleId < 1) {
            $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll() ?: [];

        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int)$r['ID'];
        }
        $childCounts = self::directChildCounts($ids, $includeHidden);

        $items = [];
        foreach ($rows as $r) {
            $cid = (int)$r['ID'];
            $item = self::forAgent($r, [
                'excerpt' => $excerpt,
                'child_count' => $childCounts[$cid] ?? 0,
            ]);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $returned = count($items);
        $hasMore = $singleId < 1 && ($offset + $returned) < $total;
        return [
            'items' => $items,
            'total' => $total,
            'returned' => $returned,
            'limit' => $limit,
            'offset' => $offset,
            'pid' => $pid,
            'order' => $order,
            'excerpt' => $excerpt,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + $returned : null,
        ];
    }

    /**
     * @param list<int> $parentIds
     * @return array<int,int>
     */
    public static function directChildCounts(array $parentIds, bool $includeHidden = false): array
    {
        $out = [];
        $ids = array_values(array_filter(array_map('intval', $parentIds)));
        if (!$ids || !Database::tableExists('base_comments')) {
            return $out;
        }
        $in = implode(',', $ids);
        $sql = "SELECT PID, COUNT(*) AS c FROM base_comments WHERE PID IN ($in)";
        if (!$includeHidden) {
            $sql .= ' AND DISABLED = 0';
        }
        $sql .= ' GROUP BY PID';
        foreach (Database::pdo()->query($sql)->fetchAll() ?: [] as $r) {
            $out[(int)$r['PID']] = (int)$r['c'];
        }
        return $out;
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
