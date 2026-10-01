<?php
declare(strict_types=1);

final class LikesRepository
{
    public static function counts(string $typeObj, array $ids): array
    {
        $out = [];
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids || !Database::tableExists('base_likes')) {
            return $out;
        }
        $in = implode(',', $ids);
        $type = $typeObj === 'base_news' ? 'base_news' : 'base_comments';
        $rows = Database::pdo()->query(
            "SELECT ID_OBJ, COUNT(*) AS c FROM base_likes
             WHERE TYPE_OBJ = " . Database::pdo()->quote($type) . " AND ID_OBJ IN ($in)
             GROUP BY ID_OBJ"
        )->fetchAll();
        foreach ($rows as $r) {
            $out[(int)$r['ID_OBJ']] = (int)$r['c'];
        }
        return $out;
    }

    /** @return array<int,bool> objId => liked by user */
    public static function likedByUser(string $typeObj, array $ids, int $userId): array
    {
        $out = [];
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids || $userId < 1 || !Database::tableExists('base_likes')) {
            return $out;
        }
        $type = $typeObj === 'base_news' ? 'base_news' : 'base_comments';
        $in = implode(',', $ids);
        $st = Database::pdo()->prepare(
            "SELECT ID_OBJ FROM base_likes WHERE TYPE_OBJ = ? AND ID_USER = ? AND ID_OBJ IN ($in)"
        );
        $st->execute([$type, $userId]);
        foreach ($st->fetchAll() as $r) {
            $out[(int)$r['ID_OBJ']] = true;
        }
        return $out;
    }

    /** Toggle like. Returns new liked state. Cannot like own object. */
    public static function toggle(string $typeObj, int $objId, int $userId, int $authorId): bool
    {
        if ($userId < 1 || $objId < 1 || $userId === $authorId || !Database::tableExists('base_likes')) {
            return false;
        }
        $type = $typeObj === 'base_news' ? 'base_news' : 'base_comments';
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT ID FROM base_likes WHERE ID_USER = ? AND TYPE_OBJ = ? AND ID_OBJ = ? LIMIT 1'
        );
        $st->execute([$userId, $type, $objId]);
        $existing = $st->fetchColumn();
        if ($existing) {
            $pdo->prepare('DELETE FROM base_likes WHERE ID = ?')->execute([(int)$existing]);
            return false;
        }
        $ins = $pdo->prepare(
            'INSERT INTO base_likes SET ID_USER=?, TYPE_OBJ=?, ID_OBJ=?, DT=?, ID_USER_AUTHOR=?, LIKE_VIEWED=0'
        );
        $ins->execute([$userId, $type, $objId, time(), $authorId]);
        return true;
    }
}
