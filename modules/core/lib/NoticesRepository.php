<?php
declare(strict_types=1);

/**
 * Top notice chips (legacy notices.php news block).
 * Modules can append via EventBus core.notices.collect.
 */
final class NoticesRepository
{
    /**
     * @return list<array{short:string,href:string,title:string,tone:string}>
     */
    public static function newsNotices(int $userId): array
    {
        if ($userId < 1 || !Database::tableExists('base_news')) {
            return [];
        }
        $out = [];
        $pdo = Database::pdo();
        $excl = NewsRepository::sqlExcludeArchiveChildren('n');
        $denied = NewsRepository::sqlExcludeInaccessibleFolders($userId, 'n');
        $andExcl = static function (string ...$conds): string {
            $out = '';
            foreach ($conds as $cond) {
                if ($cond !== '') {
                    $out .= ' AND ' . $cond;
                }
            }
            return $out;
        };

        // Favorites
        $favIds = UsersRepository::favoriteIds($userId);
        if ($favIds) {
            $in = implode(',', array_map('intval', $favIds));
            $n = (int)$pdo->query(
                "SELECT COUNT(*) FROM base_news WHERE DISABLED=0 AND ID IN ($in)"
            )->fetchColumn();
            if ($n > 0) {
                $out[] = [
                    'short' => t('core.notice.fav_short', ['n' => $n]),
                    'href' => '/news?type=4',
                    'title' => t('core.notice.fav_title'),
                    'tone' => 'info',
                ];
            }
        }

        // Unread by type (accessible leaves only; archive folders excluded)
        if (Database::tableExists('base_views')) {
            $labels = [1 => t('core.notice.unread_short.1'), 2 => t('core.notice.unread_short.2'), 3 => t('core.notice.unread_short.3')];
            $titles = [1 => t('core.notice.unread_title.1'), 2 => t('core.notice.unread_title.2'), 3 => t('core.notice.unread_title.3')];
            $acc = NewsRepository::accessWhereSql($userId, 'n');
            $accSql = $acc['sql'] !== '' ? ' AND (' . $acc['sql'] . ')' : '';
            // NOT IN (materialized id set) beats correlated NOT EXISTS when
            // base_views only has weak/legacy TYPE_OBJ-prefix indexes.
            $st = $pdo->prepare(
                'SELECT n.TYPE AS t, COUNT(*) AS c FROM base_news n
                 WHERE n.DISABLED = 0 AND n.HAS_CHILDREN = 0 AND n.TYPE IN (1,2,3)'
                . $andExcl($excl, $denied)
                . $accSql . '
                   AND n.ID NOT IN (
                     SELECT v.ID_OBJ FROM base_views v
                     WHERE v.TYPE_OBJ = \'base_news\' AND v.ID_USER = ?
                   )
                 GROUP BY n.TYPE'
            );
            $st->execute(array_merge($acc['params'], [$userId]));
            foreach ($st->fetchAll() ?: [] as $row) {
                $t = (int)$row['t'];
                $c = (int)$row['c'];
                if ($c < 1 || !isset($labels[$t])) {
                    continue;
                }
                $out[] = [
                    'short' => $labels[$t] . ':' . $c,
                    'href' => '/news?type=' . $t . '&unread=1',
                    'title' => $titles[$t],
                    'tone' => 'warn',
                ];
            }
        }

        // Overdue open tickets (not in archive folders); align count with list + access.
        $accOver = NewsRepository::accessWhereSql($userId, 'n');
        $accOverSql = $accOver['sql'] !== '' ? ' AND (' . $accOver['sql'] . ')' : '';
        $stOver = $pdo->prepare(
            'SELECT COUNT(*) FROM base_news n
             WHERE n.DISABLED=0 AND n.HAS_CHILDREN=0 AND n.TYPE=2 AND n.STATUS=1
               AND n.DT_DEADLINE > 0 AND n.DT_DEADLINE < ' . time()
            . $andExcl($excl, $denied)
            . $accOverSql
        );
        $stOver->execute($accOver['params']);
        $over = (int)$stOver->fetchColumn();
        if ($over > 0) {
            $out[] = [
                'short' => t('core.notice.overdue_short', ['n' => $over]),
                'href' => '/news?type=2&overdue=1',
                'title' => t('core.notice.overdue_title'),
                'tone' => 'danger',
            ];
        }

        // In work without curator or deadline
        $stBad = $pdo->prepare(
            'SELECT COUNT(*) FROM base_news n
             WHERE n.DISABLED=0 AND n.HAS_CHILDREN=0 AND n.TYPE=2 AND n.STATUS=1
               AND (n.DT_DEADLINE = 0 OR n.ID_CURATOR = 0 OR n.ID_CURATOR IS NULL OR n.ID_CURATOR = \'\')'
            . $andExcl($excl, $denied)
            . $accOverSql
        );
        $stBad->execute($accOver['params']);
        $bad = (int)$stBad->fetchColumn();
        if ($bad > 0) {
            $out[] = [
                'short' => t('core.notice.unset_short', ['n' => $bad]),
                'href' => '/news?type=2&status=1',
                'title' => t('core.notice.unset_title'),
                'tone' => 'warn',
            ];
        }

        // Needs signature / reply (oper ТР.О)
        if (Database::tableExists('base_views') && Database::columnExists('base_news', 'SIGN_REQ')) {
            $signLabels = [
                1 => t('core.notice.sign_short.1'),
                2 => t('core.notice.sign_short.2'),
                3 => t('core.notice.sign_short.3'),
            ];
            $signTitles = [
                1 => t('core.notice.sign_title.1'),
                2 => t('core.notice.sign_title.2'),
                3 => t('core.notice.sign_title.3'),
            ];
            $acc = NewsRepository::accessWhereSql($userId, 'n');
            $accSql = $acc['sql'] !== '' ? ' AND (' . $acc['sql'] . ')' : '';
            $st = $pdo->prepare(
                'SELECT n.TYPE AS t, COUNT(*) AS c FROM base_news n
                 WHERE n.DISABLED = 0 AND n.HAS_CHILDREN = 0 AND n.SIGN_REQ = 1 AND n.TYPE IN (1,2,3)'
                . $andExcl($excl, $denied)
                . $accSql . '
                   AND n.ID NOT IN (
                     SELECT v.ID_OBJ FROM base_views v
                     WHERE v.TYPE_OBJ = \'base_news_sign\' AND v.ID_USER = ?
                   )
                 GROUP BY n.TYPE'
            );
            $st->execute(array_merge($acc['params'], [$userId]));
            foreach ($st->fetchAll() ?: [] as $row) {
                $t = (int)$row['t'];
                $c = (int)$row['c'];
                if ($c < 1 || !isset($signLabels[$t])) {
                    continue;
                }
                $out[] = [
                    'short' => $signLabels[$t] . ':' . $c,
                    'href' => '/news?type=' . $t . '&unsigned=1',
                    'title' => $signTitles[$t],
                    'tone' => 'danger',
                ];
            }
        }

        return $out;
    }
}
