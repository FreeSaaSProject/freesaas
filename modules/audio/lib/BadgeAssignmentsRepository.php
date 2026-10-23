<?php
declare(strict_types=1);

/**
 * Dictaphone badge assignment for staff with AUDIO_ANALYTICS_ENABLED (oper badge_logic).
 */
final class BadgeAssignmentsRepository
{
    private const EXCLUDE_CODES = ['222222', '11111111'];
    private const SESSION_BADGE = 'audio_current_badge';
    private const SESSION_OFF = 'audio_not_on_shift_until';

    public static function analyticsEnabled(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }
        $u = UsersRepository::find($userId);
        return $u !== null && !empty($u['AUDIO_ANALYTICS_ENABLED']);
    }

    public static function onShiftToday(int $userId, ?int $now = null): bool
    {
        if ($userId < 1 || !class_exists('WorkweeksRepository', false)) {
            return false;
        }
        $now = $now ?? time();
        $dayStart = mktime(0, 0, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
        return WorkweeksRepository::findByDayOperator((int)$dayStart, $userId) !== null;
    }

    public static function withinWorkHours(?int $now = null): bool
    {
        $now = $now ?? time();
        // Salon hours from calendar settings (cfg/modules/calendar.ini); absent → whole day.
        $start = (int)Config::get('calendar', 'hour_start', 0);
        $end = (int)Config::get('calendar', 'hour_end', 24);
        if ($end <= 0) {
            $end = 24;
        }
        $hour = (int)date('H', $now);
        return $hour >= $start && $hour < $end;
    }

    /** Active ID_BADGE string, or null if none. */
    public static function activeBadge(int $userId, ?int $at = null): ?string
    {
        if ($userId < 1 || !Database::tableExists('base_badge_assignments')) {
            return null;
        }
        $at = $at ?? time();
        $st = Database::pdo()->prepare(
            'SELECT ID_BADGE FROM base_badge_assignments
             WHERE ID_OPERATOR = ? AND DT_START <= ? AND DT_END > ?
             ORDER BY DT_START DESC LIMIT 1'
        );
        $st->execute([$userId, $at, $at]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $badge = trim((string)$row['ID_BADGE']);
        return $badge !== '' ? $badge : null;
    }

    /**
     * Forced prompt: analytics on, on shift today, within work hours,
     * not session-marked off-shift, no active assignment.
     */
    public static function needSelection(int $userId, ?int $now = null): bool
    {
        $now = $now ?? time();
        if (!self::analyticsEnabled($userId)) {
            return false;
        }
        if (!self::onShiftToday($userId, $now)) {
            return false;
        }
        $offUntil = (int)($_SESSION[self::SESSION_OFF] ?? 0);
        if ($offUntil >= $now) {
            return false;
        }
        if (!self::withinWorkHours($now)) {
            return false;
        }
        $active = self::activeBadge($userId, $now);
        return $active === null;
    }

    /** @return list<string> badge codes */
    public static function selectableCodes(?int $at = null): array
    {
        $at = $at ?? time();
        $codes = [];
        if (Database::tableExists('base_records_badges')) {
            foreach (BadgesRepository::listAll(true) as $b) {
                $code = trim((string)($b['CODE'] ?? ''));
                if ($code !== '' && $code !== '0' && !in_array($code, self::EXCLUDE_CODES, true)) {
                    $codes[$code] = $code;
                }
            }
        }
        if ($codes === [] && Database::tableExists('base_records')) {
            $cutoff = $at - 3600 * 24 * 30;
            $st = Database::pdo()->prepare(
                'SELECT DISTINCT ID_DICTOPHONE AS code FROM base_records
                 WHERE DT_RECORDED > ? AND ID_DICTOPHONE > 0
                 ORDER BY ID_DICTOPHONE ASC LIMIT 40'
            );
            $st->execute([$cutoff]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $code = trim((string)$row['code']);
                if ($code !== '' && !in_array($code, self::EXCLUDE_CODES, true)) {
                    $codes[$code] = $code;
                }
            }
        }
        $list = array_values($codes);
        natcasesort($list);
        return array_values($list);
    }

    /** @return array<string,int> badge_code => operator_id */
    public static function occupiedMap(?int $at = null): array
    {
        if (!Database::tableExists('base_badge_assignments')) {
            return [];
        }
        $at = $at ?? time();
        $st = Database::pdo()->prepare(
            'SELECT ID_BADGE, ID_OPERATOR FROM base_badge_assignments
             WHERE DT_START <= ? AND DT_END > ?'
        );
        $st->execute([$at, $at]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $bid = trim((string)$row['ID_BADGE']);
            if ($bid !== '' && $bid !== '0') {
                $out[$bid] = (int)$row['ID_OPERATOR'];
            }
        }
        return $out;
    }

    /**
     * Options for the select: value => label.
     * Always includes not_on_shift first.
     *
     * @return array<string,string>
     */
    public static function selectOptions(?int $at = null, ?int $selfId = null): array
    {
        $at = $at ?? time();
        $occupied = self::occupiedMap($at);
        $names = [];
        foreach (BadgesRepository::listAll(false) as $b) {
            $code = trim((string)($b['CODE'] ?? ''));
            $name = trim((string)($b['NAME'] ?? ''));
            if ($code !== '' && $name !== '' && $name !== $code) {
                $names[$code] = $name;
            }
        }
        $out = [
            'not_on_shift' => t('audio.badge.opt.not_on_shift'),
        ];
        foreach (self::selectableCodes($at) as $code) {
            $label = $names[$code] ?? $code;
            if ($label !== $code) {
                $label = $code . ' — ' . $label;
            }
            if (isset($occupied[$code]) && (int)$occupied[$code] !== (int)$selfId) {
                $label .= ' [' . t('audio.badge.opt.held_by', ['name' => self::operatorShortName((int)$occupied[$code])]) . ']';
            }
            $out[$code] = $label;
        }
        return $out;
    }

    /**
     * @return array{success:bool,message:string,badge_id:?string}
     */
    public static function save(int $userId, string $badgeId, ?int $now = null): array
    {
        $userId = (int)$userId;
        if ($userId < 1) {
            return ['success' => false, 'message' => t('audio.badge.err.auth'), 'badge_id' => null];
        }
        if (!Database::tableExists('base_badge_assignments')) {
            return ['success' => false, 'message' => t('audio.badge.err.table'), 'badge_id' => null];
        }
        $badgeId = trim($badgeId);
        $now = $now ?? time();
        $todayStart = (int)mktime(0, 0, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
        $todayEnd = (int)mktime(23, 59, 59, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
        $pdo = Database::pdo();

        if ($badgeId === 'not_on_shift' || $badgeId === '0') {
            self::closeOpenAssignments($userId, $now);
            $st = $pdo->prepare(
                'INSERT INTO base_badge_assignments
                 (ID_OPERATOR, ID_BADGE, DT_START, DT_END, DT_CREATE, ID_CREATOR)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $st->execute([$userId, '0', $now, $todayEnd, $now, $userId]);
            $_SESSION[self::SESSION_OFF] = $todayEnd;
            unset($_SESSION[self::SESSION_BADGE]);
            return ['success' => true, 'message' => t('audio.badge.msg.not_on_shift'), 'badge_id' => 'not_on_shift'];
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $badgeId) || in_array($badgeId, self::EXCLUDE_CODES, true)) {
            return ['success' => false, 'message' => t('audio.badge.err.invalid'), 'badge_id' => null];
        }

        $stHas = $pdo->prepare(
            'SELECT 1 FROM base_badge_assignments
             WHERE ID_OPERATOR = ? AND ID_BADGE != \'0\' AND DT_END >= ? AND DT_START <= ?
             LIMIT 1'
        );
        $stHas->execute([$userId, $todayStart, $todayEnd]);
        $assignStart = $stHas->fetchColumn() ? $now : $todayStart;

        // Free this badge from others for the overlapping window.
        $pdo->prepare(
            'UPDATE base_badge_assignments SET DT_END = ?
             WHERE ID_BADGE = ? AND DT_START < ? AND DT_END > ?'
        )->execute([$assignStart, $badgeId, $assignStart, $assignStart]);
        $pdo->prepare(
            'UPDATE base_badge_assignments SET DT_END = DT_START
             WHERE ID_BADGE = ? AND DT_START >= ? AND DT_START <= ? AND DT_END > DT_START'
        )->execute([$badgeId, $assignStart, $todayEnd]);

        // Close operator's other open windows.
        $pdo->prepare(
            'UPDATE base_badge_assignments SET DT_END = ?
             WHERE ID_OPERATOR = ? AND DT_START < ? AND DT_END > ?'
        )->execute([$assignStart, $userId, $assignStart, $assignStart]);
        $pdo->prepare(
            'UPDATE base_badge_assignments SET DT_END = DT_START
             WHERE ID_OPERATOR = ? AND DT_START >= ? AND DT_START <= ? AND DT_END > DT_START'
        )->execute([$userId, $assignStart, $todayEnd]);

        $st = $pdo->prepare(
            'INSERT INTO base_badge_assignments
             (ID_OPERATOR, ID_BADGE, DT_START, DT_END, DT_CREATE, ID_CREATOR)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$userId, $badgeId, $assignStart, $todayEnd, $now, $userId]);
        unset($_SESSION[self::SESSION_OFF]);
        $_SESSION[self::SESSION_BADGE] = $badgeId;
        BadgesRepository::upsertFromCode($badgeId, $now);

        return ['success' => true, 'message' => t('audio.badge.msg.saved'), 'badge_id' => $badgeId];
    }

    /**
     * Badge covering each calendar day in [rangeStart, rangeEnd) — one SELECT.
     * Keyed by Y-m-d then operator id → badge code (may be "0" for not-on-shift).
     *
     * @return array<string,array<int,string>>
     */
    public static function mapForDayRange(int $rangeStart, int $rangeEnd): array
    {
        $out = [];
        if ($rangeStart <= 0 || $rangeEnd <= $rangeStart || !Database::tableExists('base_badge_assignments')) {
            return $out;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID_OPERATOR AS id_operator, ID_BADGE AS id_badge,
                    DT_START AS dt_start, DT_END AS dt_end
             FROM base_badge_assignments
             WHERE DT_END > ? AND DT_START < ?
             ORDER BY DT_START ASC, ID ASC'
        );
        $st->execute([$rangeStart, $rangeEnd]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $oid = (int)$row['id_operator'];
            $badge = trim((string)$row['id_badge']);
            if ($oid < 1 || $badge === '') {
                continue;
            }
            $aStart = (int)$row['dt_start'];
            $aEnd = (int)$row['dt_end'];
            for ($ts = $rangeStart; $ts < $rangeEnd; $ts += 86400) {
                $dayEnd = $ts + 86400;
                if ($aStart < $dayEnd && $aEnd > $ts) {
                    $d = date('Y-m-d', $ts);
                    $out[$d][$oid] = $badge;
                }
            }
        }
        return $out;
    }

    /**
     * Admin/nazcab: assign or clear badge for a specific calendar day (oper nazcabsave).
     *
     * @return bool true when a row was written or cleared
     */
    public static function saveForDay(int $operatorId, int $dayStart, string $badgeId, ?int $actorId = null): bool
    {
        $operatorId = (int)$operatorId;
        if ($operatorId < 1 || $dayStart < 1 || !Database::tableExists('base_badge_assignments')) {
            return false;
        }
        $badgeId = trim($badgeId);
        $dayEnd = $dayStart + 86400 - 1;
        $now = time();
        $actorId = $actorId ?? Auth::userId();
        if ($actorId < 1) {
            $actorId = $operatorId;
        }
        $pdo = Database::pdo();
        $todayStart = (int)mktime(0, 0, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));

        if ($badgeId === '' || $badgeId === '0' || $badgeId === 'not_on_shift') {
            $pdo->prepare(
                'UPDATE base_badge_assignments SET DT_END = ?
                 WHERE ID_OPERATOR = ? AND DT_START < ? AND DT_END > ?'
            )->execute([$dayStart, $operatorId, $dayStart, $dayStart]);
            $st = $pdo->prepare(
                'UPDATE base_badge_assignments SET DT_END = DT_START
                 WHERE ID_OPERATOR = ? AND DT_START >= ? AND DT_START <= ? AND DT_END > DT_START'
            );
            $st->execute([$operatorId, $dayStart, $dayEnd]);
            return $st->rowCount() > 0;
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $badgeId) || in_array($badgeId, self::EXCLUDE_CODES, true)) {
            return false;
        }

        $isToday = $dayStart === $todayStart;
        $assignStart = $dayStart;
        if ($isToday) {
            $stHas = $pdo->prepare(
                'SELECT 1 FROM base_badge_assignments
                 WHERE ID_OPERATOR = ? AND ID_BADGE != \'0\' AND DT_END >= ? AND DT_START <= ?
                 LIMIT 1'
            );
            $stHas->execute([$operatorId, $dayStart, $dayEnd]);
            if ($stHas->fetchColumn()) {
                $assignStart = $now;
            }
        }

        $pdo->prepare(
            'UPDATE base_badge_assignments SET DT_END = ?
             WHERE ID_OPERATOR = ? AND DT_START < ? AND DT_END > ?'
        )->execute([$assignStart, $operatorId, $assignStart, $assignStart]);
        $pdo->prepare(
            'UPDATE base_badge_assignments SET DT_END = DT_START
             WHERE ID_OPERATOR = ? AND DT_START >= ? AND DT_START <= ? AND DT_END > DT_START'
        )->execute([$operatorId, $assignStart, $dayEnd]);

        $pdo->prepare(
            'UPDATE base_badge_assignments SET DT_END = ?
             WHERE ID_BADGE = ? AND DT_START < ? AND DT_END > ?'
        )->execute([$assignStart, $badgeId, $assignStart, $assignStart]);
        $pdo->prepare(
            'UPDATE base_badge_assignments SET DT_END = DT_START
             WHERE ID_BADGE = ? AND DT_START >= ? AND DT_START <= ? AND DT_END > DT_START'
        )->execute([$badgeId, $assignStart, $dayEnd]);

        $pdo->prepare(
            'INSERT INTO base_badge_assignments
             (ID_OPERATOR, ID_BADGE, DT_START, DT_END, DT_CREATE, ID_CREATOR)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$operatorId, $badgeId, $assignStart, $dayEnd, $now, $actorId]);
        if (class_exists('BadgesRepository', false)) {
            BadgesRepository::upsertFromCode($badgeId, $now);
        }
        return true;
    }

    /** Close all currently open assignments for operator (logout / reset). */
    public static function closeOpenAssignments(int $userId, ?int $now = null): void
    {
        if ($userId < 1 || !Database::tableExists('base_badge_assignments')) {
            return;
        }
        $now = $now ?? time();
        Database::pdo()->prepare(
            'UPDATE base_badge_assignments SET DT_END = ? WHERE ID_OPERATOR = ? AND DT_END > ?'
        )->execute([$now, $userId, $now]);
        unset($_SESSION[self::SESSION_BADGE], $_SESSION[self::SESSION_OFF]);
    }

    public static function syncSessionFromDb(int $userId): void
    {
        $active = self::activeBadge($userId);
        if ($active !== null && $active !== '0') {
            $_SESSION[self::SESSION_BADGE] = $active;
            unset($_SESSION[self::SESSION_OFF]);
            return;
        }
        if ($active === '0') {
            $todayEnd = (int)mktime(23, 59, 59);
            $_SESSION[self::SESSION_OFF] = max((int)($_SESSION[self::SESSION_OFF] ?? 0), $todayEnd);
            unset($_SESSION[self::SESSION_BADGE]);
            return;
        }
        unset($_SESSION[self::SESSION_BADGE]);
    }

    /** @return array{mode:string,label:string,code:string} mode: badge|off|none */
    public static function chipState(int $userId): array
    {
        if (!self::analyticsEnabled($userId)) {
            return ['mode' => 'none', 'label' => '', 'code' => ''];
        }
        $now = time();
        $offUntil = (int)($_SESSION[self::SESSION_OFF] ?? 0);
        if ($offUntil >= $now) {
            return ['mode' => 'off', 'label' => t('audio.badge.chip.off'), 'code' => ''];
        }
        $code = (string)($_SESSION[self::SESSION_BADGE] ?? '');
        if ($code === '') {
            $active = self::activeBadge($userId, $now);
            if ($active !== null && $active !== '0') {
                $code = $active;
                $_SESSION[self::SESSION_BADGE] = $code;
            } elseif ($active === '0') {
                return ['mode' => 'off', 'label' => t('audio.badge.chip.off'), 'code' => ''];
            }
        }
        if ($code !== '') {
            return ['mode' => 'badge', 'label' => t('audio.badge.chip.code', ['code' => $code]), 'code' => $code];
        }
        return ['mode' => 'pick', 'label' => t('audio.badge.chip.pick'), 'code' => ''];
    }

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'a.ID',
            'operator' => 'a.ID_OPERATOR',
            'badge' => 'a.ID_BADGE',
            'start' => 'a.DT_START',
            'end' => 'a.DT_END',
            'create' => 'a.DT_CREATE',
        ];
    }

    /**
     * Admin list of assignment rows.
     *
     * @param array{
     *   q?:string,operator_id?:int,badge?:string,active?:bool,
     *   order?:string,limit?:int,offset?:int
     * } $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_badge_assignments')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];

        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(a.ID_BADGE LIKE ? OR CAST(a.ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }
        $opId = (int)($opts['operator_id'] ?? 0);
        if ($opId > 0) {
            $where[] = 'a.ID_OPERATOR = ?';
            $params[] = $opId;
        }
        $badge = trim((string)($opts['badge'] ?? ''));
        if ($badge !== '') {
            $where[] = 'a.ID_BADGE = ?';
            $params[] = $badge;
        }
        if (!empty($opts['active'])) {
            $now = time();
            $where[] = 'a.DT_START <= ? AND a.DT_END > ?';
            $params[] = $now;
            $params[] = $now;
        }

        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_badge_assignments a WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'a.DT_START DESC, a.ID DESC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT a.ID AS id, a.ID_OPERATOR AS operator_id, a.ID_BADGE AS badge,
                       a.DT_START AS dt_start, a.DT_END AS dt_end,
                       a.DT_CREATE AS dt_create, a.ID_CREATOR AS creator_id
                FROM base_badge_assignments a
                WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = self::normalizeAdminRow($row);
        }
        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_badge_assignments')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_OPERATOR AS operator_id, ID_BADGE AS badge,
                    DT_START AS dt_start, DT_END AS dt_end,
                    DT_CREATE AS dt_create, ID_CREATOR AS creator_id
             FROM base_badge_assignments WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalizeAdminRow($row) : null;
    }

    /**
     * @param array<string,mixed> $post
     * @return array{operator_id:int,badge:string,dt_start:int,dt_end:int}
     */
    public static function dataFromPost(array $post): array
    {
        $operatorId = (int)($post['operator_id'] ?? 0);
        if ($operatorId < 1) {
            throw new InvalidArgumentException(t('audio.assignments.err.operator'));
        }
        $badge = trim((string)($post['badge'] ?? ''));
        if ($badge === 'not_on_shift') {
            $badge = '0';
        }
        if ($badge !== '0' && (!preg_match('/^[a-zA-Z0-9_-]+$/', $badge) || in_array($badge, self::EXCLUDE_CODES, true))) {
            throw new InvalidArgumentException(t('audio.badge.err.invalid'));
        }
        $dtStart = self::parseDateTimeLocal((string)($post['dt_start'] ?? ''));
        $dtEnd = self::parseDateTimeLocal((string)($post['dt_end'] ?? ''));
        if ($dtStart < 1 || $dtEnd < 1) {
            throw new InvalidArgumentException(t('audio.assignments.err.dates'));
        }
        if ($dtEnd <= $dtStart) {
            throw new InvalidArgumentException(t('audio.assignments.err.range'));
        }
        return [
            'operator_id' => $operatorId,
            'badge' => $badge,
            'dt_start' => $dtStart,
            'dt_end' => $dtEnd,
        ];
    }

    /**
     * @param array{operator_id:int,badge:string,dt_start:int,dt_end:int} $data
     */
    public static function create(array $data, int $creatorId): int
    {
        if (!Database::tableExists('base_badge_assignments')) {
            throw new RuntimeException(t('audio.badge.err.table'));
        }
        $now = time();
        $st = Database::pdo()->prepare(
            'INSERT INTO base_badge_assignments
             (ID_OPERATOR, ID_BADGE, DT_START, DT_END, DT_CREATE, ID_CREATOR)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            (int)$data['operator_id'],
            (string)$data['badge'],
            (int)$data['dt_start'],
            (int)$data['dt_end'],
            $now,
            max(0, $creatorId),
        ]);
        $id = (int)Database::pdo()->lastInsertId();
        $badge = (string)$data['badge'];
        if ($badge !== '' && $badge !== '0') {
            BadgesRepository::upsertFromCode($badge, $now);
        }
        self::maybeSyncSession((int)$data['operator_id']);
        return $id;
    }

    /**
     * @param array{operator_id:int,badge:string,dt_start:int,dt_end:int} $data
     */
    public static function update(int $id, array $data): void
    {
        if ($id < 1 || !Database::tableExists('base_badge_assignments')) {
            throw new RuntimeException(t('audio.badge.err.table'));
        }
        $prev = self::find($id);
        if ($prev === null) {
            throw new RuntimeException(t('audio.assignments.err.not_found', ['id' => $id]));
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_badge_assignments
             SET ID_OPERATOR = ?, ID_BADGE = ?, DT_START = ?, DT_END = ?
             WHERE ID = ?'
        );
        $st->execute([
            (int)$data['operator_id'],
            (string)$data['badge'],
            (int)$data['dt_start'],
            (int)$data['dt_end'],
            $id,
        ]);
        $badge = (string)$data['badge'];
        if ($badge !== '' && $badge !== '0') {
            BadgesRepository::upsertFromCode($badge, time());
        }
        self::maybeSyncSession((int)$prev['operator_id']);
        self::maybeSyncSession((int)$data['operator_id']);
    }

    public static function delete(int $id): void
    {
        if ($id < 1 || !Database::tableExists('base_badge_assignments')) {
            return;
        }
        $prev = self::find($id);
        Database::pdo()->prepare('DELETE FROM base_badge_assignments WHERE ID = ?')->execute([$id]);
        if ($prev !== null) {
            self::maybeSyncSession((int)$prev['operator_id']);
        }
    }

    /** datetime-local → unix; also accepts unix timestamp string. */
    public static function parseDateTimeLocal(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        if (ctype_digit($raw)) {
            return (int)$raw;
        }
        $raw = str_replace('T', ' ', $raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) {
            $raw .= ':00';
        }
        $ts = strtotime($raw);
        return $ts !== false ? (int)$ts : 0;
    }

    public static function formatDateTimeLocal(int $ts): string
    {
        return $ts > 0 ? date('Y-m-d\TH:i', $ts) : '';
    }

    /** @param array<string,mixed> $row */
    private static function normalizeAdminRow(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'operator_id' => (int)($row['operator_id'] ?? 0),
            'badge' => (string)($row['badge'] ?? ''),
            'dt_start' => (int)($row['dt_start'] ?? 0),
            'dt_end' => (int)($row['dt_end'] ?? 0),
            'dt_create' => (int)($row['dt_create'] ?? 0),
            'creator_id' => (int)($row['creator_id'] ?? 0),
        ];
    }

    private static function maybeSyncSession(int $operatorId): void
    {
        if ($operatorId > 0 && $operatorId === Auth::userId()) {
            self::syncSessionFromDb($operatorId);
        }
    }

    private static function operatorShortName(int $userId): string
    {
        $u = UsersRepository::find($userId);
        if (!$u) {
            return '#' . $userId;
        }
        $name = trim((string)($u['name'] ?? ''));
        if ($name === '') {
            return (string)($u['login'] ?? ('#' . $userId));
        }
        $parts = preg_split('/\s+/u', $name) ?: [];
        return (string)($parts[0] ?? $name);
    }
}
