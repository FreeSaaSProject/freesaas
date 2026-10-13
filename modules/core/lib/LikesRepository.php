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

    /**
     * Unviewed likes on objects authored by $authorId (newest first).
     *
     * @return list<array{ID:int,DT:int,ID_USER:int,ID_OBJ:int,TYPE_OBJ:string}>
     */
    public static function listUnviewedForAuthor(int $authorId, int $limit = 30): array
    {
        if ($authorId < 1 || !Database::tableExists('base_likes')) {
            return [];
        }
        if ($limit < 1) {
            $limit = 30;
        }
        if ($limit > 100) {
            $limit = 100;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, DT, ID_USER, ID_OBJ, TYPE_OBJ
             FROM base_likes
             WHERE ID_USER_AUTHOR = ? AND LIKE_VIEWED = 0
             ORDER BY DT DESC
             LIMIT ' . (int)$limit
        );
        $st->execute([$authorId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $type = (string)($r['TYPE_OBJ'] ?? '');
            if ($type !== 'base_news' && $type !== 'base_comments') {
                continue;
            }
            $out[] = [
                'ID' => (int)$r['ID'],
                'DT' => (int)$r['DT'],
                'ID_USER' => (int)$r['ID_USER'],
                'ID_OBJ' => (int)$r['ID_OBJ'],
                'TYPE_OBJ' => $type,
            ];
        }
        return $out;
    }

    /** Mark author's unviewed likes with DT < $beforeTs as viewed. Returns rows updated. */
    public static function markViewedForAuthor(int $authorId, int $beforeTs): int
    {
        if ($authorId < 1 || $beforeTs < 1 || !Database::tableExists('base_likes')) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_likes SET LIKE_VIEWED = 1
             WHERE ID_USER_AUTHOR = ? AND LIKE_VIEWED = 0 AND DT < ?'
        );
        $st->execute([$authorId, $beforeTs]);
        return (int)$st->rowCount();
    }

    /**
     * Group unviewed likes for home widget (same object → one line + liker badges).
     *
     * @return list<array{type:string,obj_id:int,href:string,news_type:int,title:string,snippet:string,likers:list<int>}>
     */
    public static function groupedUnviewedForAuthor(int $authorId, int $limit = 30): array
    {
        $rows = self::listUnviewedForAuthor($authorId, $limit);
        if ($rows === []) {
            return [];
        }

        $newsIds = [];
        $commentIds = [];
        foreach ($rows as $r) {
            if ($r['TYPE_OBJ'] === 'base_news') {
                $newsIds[$r['ID_OBJ']] = true;
            } else {
                $commentIds[$r['ID_OBJ']] = true;
            }
        }

        $newsById = [];
        if ($newsIds !== [] && Database::tableExists('base_news')) {
            $in = implode(',', array_map('intval', array_keys($newsIds)));
            $q = Database::pdo()->query(
                "SELECT ID, NAME, TYPE, DISABLED FROM base_news WHERE ID IN ($in) AND ID_USER = " . (int)$authorId
            );
            foreach ($q->fetchAll() as $n) {
                if ((int)($n['DISABLED'] ?? 0) !== 0) {
                    continue;
                }
                $newsById[(int)$n['ID']] = $n;
            }
        }

        $commentsById = [];
        if ($commentIds !== [] && Database::tableExists('base_comments')) {
            $in = implode(',', array_map('intval', array_keys($commentIds)));
            $q = Database::pdo()->query(
                "SELECT ID, TEXT, ID_OBJECT, DISABLED FROM base_comments
                 WHERE ID IN ($in) AND ID_USER = " . (int)$authorId
            );
            foreach ($q->fetchAll() as $c) {
                if ((int)($c['DISABLED'] ?? 0) !== 0) {
                    continue;
                }
                $commentsById[(int)$c['ID']] = $c;
            }
        }

        $groups = [];
        $indexByKey = [];
        foreach ($rows as $r) {
            $type = $r['TYPE_OBJ'];
            $objId = $r['ID_OBJ'];
            $key = $type . ':' . $objId;
            if (isset($indexByKey[$key])) {
                $groups[$indexByKey[$key]]['likers'][] = $r['ID_USER'];
                continue;
            }

            if ($type === 'base_news') {
                $n = $newsById[$objId] ?? null;
                if (!$n) {
                    continue;
                }
                $title = trim(strip_tags((string)($n['NAME'] ?? '')));
                if (mb_strlen($title, 'UTF-8') > 120) {
                    $title = mb_substr($title, 0, 120, 'UTF-8');
                }
                $groups[] = [
                    'type' => 'base_news',
                    'obj_id' => $objId,
                    'href' => '/news/' . $objId,
                    'news_type' => (int)($n['TYPE'] ?? 0),
                    'title' => $title,
                    'snippet' => '',
                    'likers' => [$r['ID_USER']],
                ];
                $indexByKey[$key] = count($groups) - 1;
                continue;
            }

            $c = $commentsById[$objId] ?? null;
            if (!$c) {
                continue;
            }
            $newsId = (int)($c['ID_OBJECT'] ?? 0);
            if ($newsId < 1) {
                continue;
            }
            if (Database::tableExists('base_news')) {
                $st = Database::pdo()->prepare(
                    'SELECT ID FROM base_news WHERE ID = ? AND DISABLED = 0 LIMIT 1'
                );
                $st->execute([$newsId]);
                if (!$st->fetchColumn()) {
                    continue;
                }
            }
            $snippet = self::commentSnippet((string)($c['TEXT'] ?? ''));
            $groups[] = [
                'type' => 'base_comments',
                'obj_id' => $objId,
                'href' => '/news/' . $newsId . '#c' . $objId,
                'news_type' => 0,
                'title' => '',
                'snippet' => $snippet,
                'likers' => [$r['ID_USER']],
            ];
            $indexByKey[$key] = count($groups) - 1;
        }

        return $groups;
    }

    private static function commentSnippet(string $raw): string
    {
        $text = strip_tags($raw);
        // Drop leading [TAG] markers (habits / status prefixes).
        while (preg_match('/^\s*\[[^\]]*\]\s*/u', $text)) {
            $text = (string)preg_replace('/^\s*\[[^\]]*\]\s*/u', '', $text, 1);
        }
        $text = trim($text);
        if (mb_strlen($text, 'UTF-8') > 160) {
            $text = mb_substr($text, 0, 160, 'UTF-8') . '…';
        }
        return $text;
    }
}
