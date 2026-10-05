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

        // Unread by type: totals − viewed (avoids DEPENDENT SUBQUERY on base_views)
        if (Database::tableExists('base_views')) {
            $labels = [1 => t('core.notice.unread_short.1'), 2 => t('core.notice.unread_short.2'), 3 => t('core.notice.unread_short.3')];
            $titles = [1 => t('core.notice.unread_title.1'), 2 => t('core.notice.unread_title.2'), 3 => t('core.notice.unread_title.3')];
            $totals = [];
            foreach ($pdo->query(
                'SELECT TYPE AS t, COUNT(*) AS c FROM base_news
                 WHERE DISABLED = 0 AND HAS_CHILDREN = 0 AND TYPE IN (1,2,3)
                 GROUP BY TYPE'
            )->fetchAll() ?: [] as $row) {
                $totals[(int)$row['t']] = (int)$row['c'];
            }
            $viewed = [];
            $st = $pdo->prepare(
                'SELECT n.TYPE AS t, COUNT(DISTINCT n.ID) AS c
                 FROM base_views v
                 INNER JOIN base_news n ON n.ID = v.ID_OBJ
                   AND n.DISABLED = 0 AND n.HAS_CHILDREN = 0 AND n.TYPE IN (1,2,3)
                 WHERE v.TYPE_OBJ = \'base_news\' AND v.ID_USER = ?
                 GROUP BY n.TYPE'
            );
            $st->execute([$userId]);
            foreach ($st->fetchAll() ?: [] as $row) {
                $viewed[(int)$row['t']] = (int)$row['c'];
            }
            foreach ($labels as $t => $label) {
                $c = max(0, (int)($totals[$t] ?? 0) - (int)($viewed[$t] ?? 0));
                if ($c < 1) {
                    continue;
                }
                $out[] = [
                    'short' => $label . ':' . $c,
                    'href' => '/news?type=' . $t . '&unread=1',
                    'title' => $titles[$t],
                    'tone' => 'warn',
                ];
            }
        }

        // Overdue open tickets
        $over = (int)$pdo->query(
            'SELECT COUNT(*) FROM base_news
             WHERE DISABLED=0 AND HAS_CHILDREN=0 AND TYPE=2 AND STATUS=1
               AND DT_DEADLINE > 0 AND DT_DEADLINE < ' . time()
        )->fetchColumn();
        if ($over > 0) {
            $out[] = [
                'short' => t('core.notice.overdue_short', ['n' => $over]),
                'href' => '/news?type=2&status=1',
                'title' => t('core.notice.overdue_title'),
                'tone' => 'danger',
            ];
        }

        // In work without curator or deadline
        $bad = (int)$pdo->query(
            'SELECT COUNT(*) FROM base_news
             WHERE DISABLED=0 AND HAS_CHILDREN=0 AND TYPE=2 AND STATUS=1
               AND (DT_DEADLINE = 0 OR ID_CURATOR = 0 OR ID_CURATOR IS NULL OR ID_CURATOR = \'\')'
        )->fetchColumn();
        if ($bad > 0) {
            $out[] = [
                'short' => t('core.notice.unset_short', ['n' => $bad]),
                'href' => '/news?type=2&status=1',
                'title' => t('core.notice.unset_title'),
                'tone' => 'warn',
            ];
        }

        return $out;
    }
}
