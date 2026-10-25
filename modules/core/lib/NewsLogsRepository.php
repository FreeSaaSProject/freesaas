<?php
declare(strict_types=1);

/**
 * Body version history for base_news (oper base_news_logs).
 * Call only when BIG is actually changing. Then: snapshot previous body if
 * another user edits or the same user edits after SNAPSHOT_WINDOW.
 */
final class NewsLogsRepository
{
    /** Same-user edits within this window do not create a new log row (oper). */
    public const SNAPSHOT_WINDOW = 14400; // 4 hours

    /**
     * Store previous BIG before a real body change.
     * Skips identical CONTENT to the latest log (defensive against callers that
     * rewrite the same HTML).
     */
    public static function maybeSnapshot(array $oldRow, int $editorId): void
    {
        if (!Database::tableExists('base_news_logs')) {
            return;
        }
        $newsId = (int)($oldRow['ID'] ?? 0);
        if ($newsId < 1 || $editorId < 1) {
            return;
        }
        $content = (string)($oldRow['BIG'] ?? '');
        $prevUser = (int)($oldRow['ID_USER_UPDATE'] ?? 0);
        if ($prevUser < 1) {
            $prevUser = (int)($oldRow['ID_USER'] ?? 0);
        }
        $prevDt = (int)($oldRow['DT_UPDATE'] ?? 0);
        if ($prevDt < 1) {
            $prevDt = (int)($oldRow['DT'] ?? 0);
        }
        $limit = time() - self::SNAPSHOT_WINDOW;
        if ($prevUser === $editorId && $prevDt >= $limit) {
            return;
        }
        if (self::latestContentEquals($newsId, $content)) {
            return;
        }
        self::insert($newsId, $prevUser, $prevDt, $content);
    }

    /** Always store current body (e.g. before restore). */
    public static function forceSnapshot(array $oldRow): void
    {
        if (!Database::tableExists('base_news_logs')) {
            return;
        }
        $newsId = (int)($oldRow['ID'] ?? 0);
        if ($newsId < 1) {
            return;
        }
        $prevUser = (int)($oldRow['ID_USER_UPDATE'] ?? 0);
        if ($prevUser < 1) {
            $prevUser = (int)($oldRow['ID_USER'] ?? 0);
        }
        $prevDt = (int)($oldRow['DT_UPDATE'] ?? 0);
        if ($prevDt < 1) {
            $prevDt = (int)($oldRow['DT'] ?? time());
        }
        self::insert($newsId, $prevUser, $prevDt, (string)($oldRow['BIG'] ?? ''));
    }

    /**
     * @return list<array{ID:int,ID_OBJ:int,ID_USER:int,DT_CREATE:int,DT_UPDATE:int,CONTENT:string}>
     */
    public static function listFor(int $newsId): array
    {
        if ($newsId < 1 || !Database::tableExists('base_news_logs')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, ID_OBJ, ID_USER, DT_CREATE, DT_UPDATE, CONTENT
             FROM base_news_logs WHERE ID_OBJ = ? ORDER BY DT_UPDATE DESC, ID DESC'
        );
        $st->execute([$newsId]);
        $out = [];
        foreach ($st->fetchAll() ?: [] as $r) {
            $out[] = [
                'ID' => (int)$r['ID'],
                'ID_OBJ' => (int)$r['ID_OBJ'],
                'ID_USER' => (int)$r['ID_USER'],
                'DT_CREATE' => (int)$r['DT_CREATE'],
                'DT_UPDATE' => (int)$r['DT_UPDATE'],
                'CONTENT' => (string)$r['CONTENT'],
            ];
        }
        return $out;
    }

    /** @return ?array{ID:int,ID_OBJ:int,ID_USER:int,DT_CREATE:int,DT_UPDATE:int,CONTENT:string} */
    public static function find(int $logId): ?array
    {
        if ($logId < 1 || !Database::tableExists('base_news_logs')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, ID_OBJ, ID_USER, DT_CREATE, DT_UPDATE, CONTENT
             FROM base_news_logs WHERE ID = ? LIMIT 1'
        );
        $st->execute([$logId]);
        $r = $st->fetch();
        if (!$r) {
            return null;
        }
        return [
            'ID' => (int)$r['ID'],
            'ID_OBJ' => (int)$r['ID_OBJ'],
            'ID_USER' => (int)$r['ID_USER'],
            'DT_CREATE' => (int)$r['DT_CREATE'],
            'DT_UPDATE' => (int)$r['DT_UPDATE'],
            'CONTENT' => (string)$r['CONTENT'],
        ];
    }

    /**
     * Restore body from a log row. Snapshots the live BIG first, then writes CONTENT back.
     */
    public static function restore(int $newsId, int $logId, int $editorId): void
    {
        if ($newsId < 1 || $logId < 1 || $editorId < 1) {
            throw new InvalidArgumentException(t('core.news_item.versions.err.bad_request'));
        }
        $log = self::find($logId);
        if (!$log || (int)$log['ID_OBJ'] !== $newsId) {
            throw new RuntimeException(t('core.news_item.versions.err.not_found'));
        }
        $row = NewsRepository::find($newsId);
        if (!$row) {
            throw new RuntimeException(t('core.news.err.unavailable', ['id' => $newsId]));
        }
        self::forceSnapshot($row);
        $st = Database::pdo()->prepare(
            'UPDATE base_news SET BIG = ?, ID_USER_UPDATE = ?, DT_UPDATE = ? WHERE ID = ?'
        );
        $st->execute([(string)$log['CONTENT'], $editorId, time(), $newsId]);
    }

    private static function latestContentEquals(int $newsId, string $content): bool
    {
        $st = Database::pdo()->prepare(
            'SELECT CONTENT FROM base_news_logs WHERE ID_OBJ = ? ORDER BY DT_UPDATE DESC, ID DESC LIMIT 1'
        );
        $st->execute([$newsId]);
        $prev = $st->fetchColumn();
        if ($prev === false) {
            return false;
        }

        return (string)$prev === $content;
    }

    private static function insert(int $newsId, int $userId, int $dtUpdate, string $content): void
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_news_logs SET ID_OBJ = ?, ID_USER = ?, DT_CREATE = ?, DT_UPDATE = ?, CONTENT = ?'
        );
        $st->execute([$newsId, max(0, $userId), time(), max(0, $dtUpdate), $content]);
    }
}
