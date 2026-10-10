<?php
declare(strict_types=1);

final class NewsChecklistRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_news_checklist');
    }

    /** @return list<array<string,mixed>> */
    public static function listFor(int $newsId): array
    {
        if ($newsId < 1 || !self::tableReady()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, ID_NEWS, TITLE, DONE, SORT, DT_CREATE, ID_USER
             FROM base_news_checklist WHERE ID_NEWS = ? ORDER BY SORT ASC, ID ASC'
        );
        $st->execute([$newsId]);
        return $st->fetchAll() ?: [];
    }

    /** @return array{done:int,total:int,pct:int} */
    public static function progress(int $newsId): array
    {
        $items = self::listFor($newsId);
        $total = count($items);
        $done = 0;
        foreach ($items as $it) {
            if (!empty($it['DONE'])) {
                $done++;
            }
        }
        return [
            'done' => $done,
            'total' => $total,
            'pct' => $total > 0 ? (int)round(100 * $done / $total) : 0,
        ];
    }

    public static function add(int $newsId, string $title, int $userId): int
    {
        $title = trim($title);
        if ($newsId < 1 || $title === '' || !self::tableReady()) {
            throw new InvalidArgumentException(t('core.checklist.err.title_required'));
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT COALESCE(MAX(SORT), 0) FROM base_news_checklist WHERE ID_NEWS = ?');
        $st->execute([$newsId]);
        $sort = (int)$st->fetchColumn() + 10;
        $ins = $pdo->prepare(
            'INSERT INTO base_news_checklist SET ID_NEWS=?, TITLE=?, DONE=0, SORT=?, DT_CREATE=?, ID_USER=?'
        );
        $ins->execute([$newsId, mb_substr($title, 0, 500), $sort, time(), $userId]);
        return (int)$pdo->lastInsertId();
    }

    public static function toggle(int $itemId, int $newsId): ?array
    {
        $row = self::find($itemId);
        if (!$row || (int)$row['ID_NEWS'] !== $newsId) {
            return null;
        }
        $done = empty($row['DONE']) ? 1 : 0;
        Database::pdo()->prepare('UPDATE base_news_checklist SET DONE = ? WHERE ID = ?')->execute([$done, $itemId]);
        $row['DONE'] = $done;
        return $row;
    }

    public static function rename(int $itemId, int $newsId, string $title): ?array
    {
        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException(t('core.checklist.err.title_required'));
        }
        $row = self::find($itemId);
        if (!$row || (int)$row['ID_NEWS'] !== $newsId) {
            return null;
        }
        Database::pdo()->prepare('UPDATE base_news_checklist SET TITLE = ? WHERE ID = ?')
            ->execute([mb_substr($title, 0, 500), $itemId]);
        $row['TITLE'] = mb_substr($title, 0, 500);
        return $row;
    }

    public static function delete(int $itemId, int $newsId): bool
    {
        $row = self::find($itemId);
        if (!$row || (int)$row['ID_NEWS'] !== $newsId) {
            return false;
        }
        Database::pdo()->prepare('DELETE FROM base_news_checklist WHERE ID = ?')->execute([$itemId]);
        return true;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_news_checklist WHERE ID = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function listForAgent(array $items): array
    {
        $out = [];
        foreach ($items as $row) {
            $out[] = [
                'id' => (int)($row['ID'] ?? 0),
                'news_id' => (int)($row['ID_NEWS'] ?? 0),
                'title' => (string)($row['TITLE'] ?? ''),
                'done' => !empty($row['DONE']) ? 1 : 0,
                'sort' => (int)($row['SORT'] ?? 0),
            ];
        }
        return $out;
    }
}
