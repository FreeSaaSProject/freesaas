<?php
declare(strict_types=1);

require_once __DIR__ . '/ClinicDemoPlan.php';
require_once __DIR__ . '/ClinicDemoManifest.php';
require_once __DIR__ . '/ClinicDemoRng.php';

/**
 * Feed stage: home-page articles, staff tickets with discussion threads,
 * personal messages, likes and favorites. A re-run replaces its own rows.
 */
final class ClinicDemoFeed
{
    /** Pack status key => base_news_statuses.ID (home counters treat STATUS=1 as open). */
    private const STATUS_IDS = ['new' => 1, 'work' => 2, 'wait' => 3, 'done' => 99];

    private ClinicDemoManifest $m;
    private ClinicDemoRng $rng;
    private int $anchor;

    public function __construct(ClinicDemoManifest $manifest)
    {
        $this->m = $manifest;
        $this->rng = new ClinicDemoRng('feed', (int)$manifest->get('seed', ClinicDemoPlan::SEED));
        $this->anchor = ClinicDemoPlan::anchorTs();
    }

    /** @return array<string,int> */
    public function run(): array
    {
        $out = ['statuses' => 0, 'articles' => 0, 'tickets' => 0, 'messages' => 0, 'comments' => 0, 'likes' => 0];
        if (!class_exists('NewsRepository', false) || !Database::tableExists('base_news')) {
            return $out;
        }
        $this->purge();

        $staff = $this->staffMap();
        if (count($staff) < 3) {
            return $out;
        }
        $pack = ClinicDemoPlan::feedPack();
        $out['statuses'] = $this->ensureStatuses((array)($pack['statuses'] ?? []));
        $patients = $this->patientNames();
        $vars = $this->nameVars();

        $articleIds = [];
        foreach ((array)($pack['articles'] ?? []) as $a) {
            $author = (int)($staff[(string)($a['from'] ?? 'director')] ?? $staff['director']);
            $created = $this->at((int)($a['age'] ?? 3), $this->rng->int(9, 17));
            $id = NewsRepository::create([
                'NAME' => $this->fill((string)$a['title'], $vars),
                'SHORT' => $this->fill((string)($a['short'] ?? ''), $vars),
                'BIG' => $this->fill((string)($a['body'] ?? ''), $vars),
                'TYPE' => 1,
                'ACCESS_USER' => '',
            ], $author);
            $this->m->add('news', $id);
            $this->checklist($id, (array)($a['checklist'] ?? []), $author, $created);
            $last = $this->thread($id, (array)($a['comments'] ?? []), $staff, $created, $vars, $out);
            $this->stamp($id, $created, $last);
            $out['likes'] += $this->likes($id, $author, $staff, $created, $this->rng->int(2, 7));
            $articleIds[] = $id;
            $out['articles']++;
        }

        $openTickets = [];
        foreach ((array)($pack['tickets'] ?? []) as $t) {
            $rounds = !empty($t['repeat']) ? 2 : 1;
            for ($round = 0; $round < $rounds; $round++) {
                $patient = (string)($this->rng->pick($patients) ?? '');
                $tv = $vars + ['patient' => $patient];
                $statusKey = $round === 0 ? (string)($t['status'] ?? 'new') : 'done';
                $age = (int)($t['age'] ?? 1) + ($round === 0 ? 0 : $this->rng->int(12, 30));
                $created = $this->at($age, $this->rng->int(9, 18));
                $curator = (int)($staff[(string)($t['curator'] ?? 'manager')] ?? $staff['manager']);
                $resp = (int)($staff[(string)($t['resp'] ?? 'admin1')] ?? $staff['admin1']);
                $deadline = 0;
                if (isset($t['deadline'])) {
                    $deadline = $round === 0
                        ? $this->dayStart() + (int)$t['deadline'] * 86400 + 18 * 3600
                        : $created + $this->rng->int(1, 4) * 86400;
                }
                $id = NewsRepository::create([
                    'NAME' => $this->fill((string)$t['title'], $tv),
                    'SHORT' => $this->fill((string)($t['short'] ?? ''), $tv),
                    'BIG' => $this->fill((string)($t['body'] ?? ''), $tv),
                    'TYPE' => 2,
                    'STATUS' => self::STATUS_IDS[$statusKey] ?? 1,
                    'ID_CURATOR' => $curator,
                    'USERS_RESP' => NewsRepository::encodeIdList([$resp]),
                    'DT_DEADLINE' => $deadline,
                    'ACCESS_USER' => '',
                ], $curator);
                $this->m->add('news', $id);
                $this->checklist($id, (array)($t['checklist'] ?? []), $curator, $created, $statusKey === 'done');
                $comments = (array)($t['comments'] ?? []);
                if ($round > 0) {
                    $comments = array_slice($comments, 0, 1);
                    $comments[] = ['from' => (string)($t['resp'] ?? 'admin1'), 'text' => (string)($pack['done_reply'] ?? '')];
                }
                $last = $this->thread($id, $comments, $staff, $created, $tv, $out);
                $this->stamp($id, $created, $last);
                if ($statusKey !== 'done') {
                    $openTickets[] = $id;
                }
                $out['tickets']++;
            }
        }

        foreach ((array)($pack['messages'] ?? []) as $dm) {
            $from = (int)($staff[(string)($dm['from'] ?? 'director')] ?? 0);
            $to = (int)($staff[(string)($dm['to'] ?? 'admin1')] ?? 0);
            if ($from < 1 || $to < 1 || $from === $to) {
                continue;
            }
            $created = $this->at((int)($dm['age'] ?? 1), $this->rng->int(8, 19));
            $id = NewsRepository::create([
                'NAME' => $this->fill((string)$dm['subject'], $vars),
                'SHORT' => $this->fill((string)($dm['short'] ?? ''), $vars),
                'BIG' => $this->fill((string)($dm['body'] ?? ''), $vars),
                'TYPE' => 3,
                'ACCESS_USER' => NewsRepository::encodeIdList([$to]),
            ], $from);
            $this->m->add('news', $id);
            $last = $this->thread($id, (array)($dm['replies'] ?? []), $staff, $created, $vars, $out, 0.2, 3);
            $this->stamp($id, $created, $last);
            $out['messages']++;
        }

        $this->favorites($staff, array_merge(array_slice($articleIds, 0, 3), array_slice($openTickets, 0, 3)));
        $this->m->save();
        return $out;
    }

    /** Remove rows from a previous feed run (news + comments + side tables). */
    public function purge(): void
    {
        $news = $this->m->ids('news');
        $pdo = Database::pdo();
        $exec = static function (string $sql) use ($pdo): void {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                error_log('[clinic feed purge] ' . $e->getMessage());
            }
        };
        if ($news !== []) {
            $in = implode(',', array_map('intval', $news));
            if (Database::tableExists('base_comments')) {
                $commentIds = array_map('intval', $pdo->query(
                    "SELECT ID FROM base_comments WHERE TABLE_NAME = '' AND ID_OBJECT IN ($in)"
                )->fetchAll(PDO::FETCH_COLUMN) ?: []);
                if ($commentIds !== [] && Database::tableExists('base_likes')) {
                    $exec("DELETE FROM base_likes WHERE TYPE_OBJ = 'base_comments' AND ID_OBJ IN ("
                        . implode(',', $commentIds) . ')');
                }
                $exec("DELETE FROM base_comments WHERE TABLE_NAME = '' AND ID_OBJECT IN ($in)");
            }
            foreach (['base_likes', 'base_views'] as $t) {
                if (Database::tableExists($t)) {
                    $exec("DELETE FROM $t WHERE TYPE_OBJ = 'base_news' AND ID_OBJ IN ($in)");
                }
            }
            if (Database::tableExists('base_news_checklist')) {
                $exec("DELETE FROM base_news_checklist WHERE ID_NEWS IN ($in)");
            }
            if (Database::tableExists('base_news_logs')) {
                $exec("DELETE FROM base_news_logs WHERE ID_OBJ IN ($in)");
            }
            $exec("DELETE FROM base_news WHERE ID IN ($in)");
            $this->dropFavorites($news);
        }
        $this->m->forget('news');
    }

    /** Statuses created by this stage (removed only on full clean). */
    public function purgeStatuses(): void
    {
        $ids = $this->m->ids('news_statuses');
        if ($ids !== [] && Database::tableExists('base_news_statuses')) {
            Database::pdo()->exec('DELETE FROM base_news_statuses WHERE ID IN ('
                . implode(',', array_map('intval', $ids)) . ')');
        }
        $this->m->forget('news_statuses');
    }

    /** @return array<string,int> plan key / alias => user id */
    private function staffMap(): array
    {
        $map = [];
        foreach ((array)$this->m->get('index.staff', []) as $key => $id) {
            if ((int)$id > 0) {
                $map[(string)$key] = (int)$id;
            }
        }
        foreach (['admin', 'demo'] as $login) {
            $row = UsersRepository::findByLogin($login);
            if ($row !== null) {
                $map[$login] = (int)$row['id'];
            }
        }
        if (!isset($map['director']) || !isset($map['manager']) || !isset($map['admin1'])) {
            return [];
        }
        return $map;
    }

    /** @return array<string,string> {key} => short staff name for text placeholders */
    private function nameVars(): array
    {
        $vars = ['org' => ClinicDemoPlan::org()];
        foreach (ClinicDemoPlan::staff() as $s) {
            $parts = preg_split('/\s+/u', trim((string)$s['name'])) ?: [];
            $vars[(string)$s['key']] = count($parts) >= 3
                ? $parts[1] . ' ' . $parts[2]
                : (string)$s['name'];
        }
        return $vars;
    }

    /** @return list<string> */
    private function patientNames(): array
    {
        $ids = $this->m->ids('clients');
        if ($ids === []) {
            return [ClinicDemoPlan::copyStr('patient_fallback')];
        }
        $pick = array_slice($this->rng->shuffled($ids), 0, 40);
        $rows = Database::pdo()->query('SELECT name FROM base_clients WHERE id IN ('
            . implode(',', array_map('intval', $pick)) . ')')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $out = array_values(array_filter(array_map('strval', $rows)));
        return $out !== [] ? $out : [ClinicDemoPlan::copyStr('patient_fallback')];
    }

    /** @param array<string,array{0:string,1:string,2:string}> $defs key => [name, color, deadline policy] */
    private function ensureStatuses(array $defs): int
    {
        if (!Database::tableExists('base_news_statuses') || !class_exists('NewsStatusesRepository', false)) {
            return 0;
        }
        $n = 0;
        foreach (self::STATUS_IDS as $key => $id) {
            if (!isset($defs[$key]) || NewsStatusesRepository::find($id) !== null) {
                continue;
            }
            [$name, $color, $policy] = $defs[$key];
            NewsStatusesRepository::create([
                'ID' => $id, 'NAME' => $name, 'TYPE' => 2, 'COLOR' => $color, 'DEADLINE_POLICY' => $policy,
            ]);
            $this->m->add('news_statuses', $id);
            $n++;
        }
        return $n;
    }

    /**
     * Items prefixed with "+" are created already ticked.
     *
     * @param list<string> $items
     */
    private function checklist(int $newsId, array $items, int $author, int $created, bool $allDone = false): void
    {
        if ($items === [] || !class_exists('NewsChecklistRepository', false) || !NewsChecklistRepository::tableReady()) {
            return;
        }
        $titles = [];
        $done = [];
        foreach ($items as $i => $raw) {
            $raw = (string)$raw;
            $isDone = $allDone || strpos($raw, '+') === 0;
            $titles[] = ltrim($raw, '+ ');
            if ($isDone) {
                $done[] = $i;
            }
        }
        NewsChecklistRepository::addMany($newsId, $titles, $author);
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE base_news_checklist SET DT_CREATE = ? WHERE ID_NEWS = ?')->execute([$created, $newsId]);
        if ($done === []) {
            return;
        }
        $st = $pdo->prepare('SELECT ID FROM base_news_checklist WHERE ID_NEWS = ? ORDER BY SORT ASC, ID ASC');
        $st->execute([$newsId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $upd = $pdo->prepare('UPDATE base_news_checklist SET DONE = 1 WHERE ID = ?');
        foreach ($done as $i) {
            if (isset($ids[$i])) {
                $upd->execute([$ids[$i]]);
            }
        }
    }

    /**
     * @param list<array{from?:string,text?:string}> $comments
     * @param array<string,int> $staff
     * @param array<string,string> $vars
     * @param array<string,int> $out
     * @return int timestamp of the last comment (or $created)
     */
    private function thread(
        int $newsId,
        array $comments,
        array $staff,
        int $created,
        array $vars,
        array &$out,
        float $likeChance = 0.35,
        int $maxGapHours = 20
    ): int {
        if ($comments === [] || !ModuleManager::isEnabled('comments') || !Database::tableExists('base_comments')) {
            return $created;
        }
        if (!class_exists('CommentsRepository', false)) {
            require_once base_path('modules/comments/lib/CommentsRepository.php');
        }
        $pdo = Database::pdo();
        $setDt = $pdo->prepare('UPDATE base_comments SET DT = ? WHERE ID = ?');
        $ts = $created;
        foreach ($comments as $c) {
            $text = trim($this->fill((string)($c['text'] ?? ''), $vars));
            $uid = (int)($staff[(string)($c['from'] ?? 'admin1')] ?? 0);
            if ($text === '' || $uid < 1) {
                continue;
            }
            $ts = min($this->anchor - 60, $ts + $this->rng->int(15, max(1, $maxGapHours) * 60) * 60);
            try {
                $cid = CommentsRepository::add($newsId, $uid, $text);
            } catch (Throwable $e) {
                error_log('[clinic feed comment] ' . $e->getMessage());
                continue;
            }
            $setDt->execute([$ts, $cid]);
            $out['comments']++;
            if ($this->rng->chance($likeChance)) {
                $out['likes'] += $this->likes($cid, $uid, $staff, $ts, 1, 'base_comments');
            }
        }
        return $ts;
    }

    /** @param array<string,int> $staff */
    private function likes(int $objId, int $authorId, array $staff, int $after, int $count, string $type = 'base_news'): int
    {
        if (!class_exists('LikesRepository', false) || !Database::tableExists('base_likes')) {
            return 0;
        }
        $pool = array_values(array_filter(array_unique(array_values($staff)), static function (int $id) use ($authorId): bool {
            return $id !== $authorId;
        }));
        $n = 0;
        $setDt = Database::pdo()->prepare('UPDATE base_likes SET DT = ?, LIKE_VIEWED = 1 WHERE TYPE_OBJ = ? AND ID_OBJ = ? AND ID_USER = ?');
        foreach (array_slice($this->rng->shuffled($pool), 0, $count) as $uid) {
            if (LikesRepository::toggle($type, $objId, (int)$uid, $authorId)) {
                $setDt->execute([min($this->anchor - 30, $after + $this->rng->int(10, 600) * 60), $type, $objId, (int)$uid]);
                $n++;
            }
        }
        return $n;
    }

    /**
     * @param array<string,int> $staff
     * @param list<int> $newsIds
     */
    private function favorites(array $staff, array $newsIds): void
    {
        if ($newsIds === []) {
            return;
        }
        foreach (['director', 'manager', 'admin', 'demo'] as $key) {
            $uid = (int)($staff[$key] ?? 0);
            if ($uid > 0) {
                UsersRepository::setFavorites($uid, array_merge(UsersRepository::favoriteIds($uid), $newsIds));
            }
        }
    }

    /** @param list<int> $newsIds */
    private function dropFavorites(array $newsIds): void
    {
        if (!Database::columnExists('base_users', 'favorites')) {
            return;
        }
        $drop = array_flip($newsIds);
        $rows = Database::pdo()->query("SELECT id FROM base_users WHERE favorites <> ''")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($rows as $uid) {
            $uid = (int)$uid;
            $keep = array_values(array_filter(UsersRepository::favoriteIds($uid), static function (int $id) use ($drop): bool {
                return !isset($drop[$id]);
            }));
            UsersRepository::setFavorites($uid, $keep);
        }
    }

    private function stamp(int $newsId, int $created, int $updated): void
    {
        Database::pdo()->prepare('UPDATE base_news SET DT = ?, DT_UPDATE = ? WHERE ID = ?')
            ->execute([$created, max($created, $updated), $newsId]);
    }

    private function dayStart(): int
    {
        return (int)strtotime(date('Y-m-d 00:00:00', $this->anchor));
    }

    /** Timestamp $daysAgo days before the anchor at $hour local time, never in the future. */
    private function at(int $daysAgo, int $hour): int
    {
        $ts = $this->dayStart() - max(0, $daysAgo) * 86400 + $hour * 3600 + $this->rng->int(0, 59) * 60;
        return min($ts, $this->anchor - $this->rng->int(20, 180) * 60);
    }

    /** @param array<string,string> $vars */
    private function fill(string $tpl, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $tpl = str_replace('{' . $k . '}', $v, $tpl);
        }
        return $tpl;
    }
}
