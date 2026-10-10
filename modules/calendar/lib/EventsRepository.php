<?php
declare(strict_types=1);

final class EventsRepository
{
    /**
     * Events whose DTSTART falls in half-open [scanStart, scanEnd).
     *
     * @return list<array<string,mixed>>
     */
    public static function listForDayRange(int $scanStart, int $scanEnd, ?int $staffId = null): array
    {
        if (!Database::tableExists('base_events') || $scanEnd <= $scanStart) {
            return [];
        }
        $pdo = Database::pdo();
        $where = 'e.DTSTART >= ? AND e.DTSTART < ?';
        $params = [$scanStart, $scanEnd];
        if ($staffId !== null && $staffId > 0) {
            $where .= ' AND e.ID_OPERATOR = ?';
            $params[] = $staffId;
        }

        $joins = '';
        $extra = 'NULL AS client_name, NULL AS operator_name';
        if (Database::tableExists('base_clients')) {
            $joins .= ' LEFT JOIN base_clients c ON c.id = e.ID_USER';
            $extra = 'c.name AS client_name';
        }
        if (Database::tableExists('base_users')) {
            $joins .= ' LEFT JOIN base_users u ON u.id = e.ID_OPERATOR';
            $extra .= ', COALESCE(NULLIF(u.name, \'\'), u.login) AS operator_name';
        } else {
            $extra .= ', NULL AS operator_name';
        }

        $st = $pdo->prepare(
            "SELECT e.ID AS id, e.ID_OPERATOR AS id_operator, e.ID_OPERATOR_CREATE AS id_operator_create,
                    e.ID_USER AS id_user, e.ID_PROMO_ACT AS id_promo_act,
                    e.DTCREATE AS dtcreate, e.DTSTART AS dtstart, e.DTEND AS dtend, e.DTUPDATE AS dtupdate,
                    e.FIX_MOVE AS fix_move, e.CONTENT AS content, e.CONTENT_PRICE AS content_price,
                    e.DESCR AS descr, e.PHONE AS phone, e.NAME AS name,
                    e.BONUS_DISCOUNT AS bonus_discount, e.BONUS_USE AS bonus_use, e.PAY AS pay,
                    e.IS_BLOCK AS is_block, e.REAL_SUMM AS real_summ, e.ENABLED AS enabled,
                    e.EVT_COSM_ACCEPT AS evt_cosm_accept,
                    $extra
             FROM base_events e
             $joins
             WHERE $where
             ORDER BY e.DTSTART ASC, e.ID ASC"
        );
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = self::normalizeRow($row);
        }
        return $items;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_events')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_OPERATOR AS id_operator, ID_OPERATOR_CREATE AS id_operator_create,
                    ID_USER AS id_user, ID_PROMO_ACT AS id_promo_act,
                    DTCREATE AS dtcreate, DTSTART AS dtstart, DTEND AS dtend, DTUPDATE AS dtupdate,
                    FIX_MOVE AS fix_move, CONTENT AS content, CONTENT_PRICE AS content_price,
                    SMS AS sms, DESCR AS descr, NODEFTIMEXPL AS nodeftimexpl,
                    PHONE AS phone, NAME AS name, BONUS_DISCOUNT AS bonus_discount,
                    BONUS_USE AS bonus_use, PAY AS pay, PPP_LOG AS ppp_log,
                    IS_BLOCK AS is_block, REAL_SUMM AS real_summ, ENABLED AS enabled,
                    EVT_TIME_RECOM AS evt_time_recom, EVT_TIME_REAL AS evt_time_real,
                    EVT_COSM_ACCEPT AS evt_cosm_accept, EVT_DECLINE_REASON AS evt_decline_reason,
                    EVT_DT_COSM_MOD AS evt_dt_cosm_mod
             FROM base_events WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalizeRow($row) : null;
    }

    public static function tsToLocal(int $ts): string
    {
        if ($ts <= 0) {
            return '';
        }
        return date('Y-m-d\TH:i', $ts);
    }

    public static function localToTs(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        $ts = strtotime($raw);
        return $ts !== false ? (int)$ts : 0;
    }

    /** Safe return URL (same-origin); fallback when missing/invalid. */
    public static function sanitizeReturnUrl(?string $raw): string
    {
        $safe = NavContext::sanitize($raw);
        if ($safe === '') {
            return '/calendar';
        }
        NavContext::setReturnUrl($safe);
        return $safe;
    }

    public static function dayUrl(int $ts, int $staffId = 0): string
    {
        $date = $ts > 0 ? date('Y-m-d', $ts) : date('Y-m-d');
        $url = '/calendar/day/' . $date;
        if ($staffId > 0) {
            $url .= '?staff=' . $staffId;
        }
        return $url;
    }

    public static function weekUrl(int $ts, int $staffId = 0): string
    {
        $date = $ts > 0 ? date('Y-m-d', $ts) : date('Y-m-d');
        $url = '/calendar/week/' . $date;
        if ($staffId > 0) {
            $url .= '?staff=' . $staffId;
        }
        return $url;
    }

    /**
     * Overlapping events for the same operator (legacy saveEvent rules, simplified).
     *
     * @return list<array{id:int,dtstart:int,dtend:int}>
     */
    public static function findOverlaps(int $operatorId, int $dtStart, int $dtEnd, int $excludeId = 0): array
    {
        if ($operatorId < 1 || $dtStart <= 0 || $dtEnd <= $dtStart || !Database::tableExists('base_events')) {
            return [];
        }
        $sql = 'SELECT ID AS id, DTSTART AS dtstart, DTEND AS dtend
                FROM base_events
                WHERE ID_OPERATOR = ?
                  AND (
                    (DTSTART <= ? AND DTEND > ?)
                    OR (DTSTART < ? AND DTEND >= ?)
                    OR (DTSTART > ? AND DTEND < ?)
                    OR (DTSTART = ?)
                  )';
        $params = [$operatorId, $dtStart, $dtStart, $dtEnd, $dtEnd, $dtStart, $dtEnd, $dtStart];
        if ($excludeId > 0) {
            $sql .= ' AND ID <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' ORDER BY DTSTART ASC LIMIT 20';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'id' => (int)$row['id'],
                'dtstart' => (int)$row['dtstart'],
                'dtend' => (int)$row['dtend'],
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $dtStart = self::localToTs((string)($post['dtstart'] ?? ''));
        $dtEnd = self::localToTs((string)($post['dtend'] ?? ''));
        if ($dtStart <= 0) {
            throw new InvalidArgumentException(t('calendar.event.err.dtstart_required'));
        }
        if ($dtEnd <= 0) {
            $dtEnd = $dtStart + 3600;
        }
        if ($dtEnd <= $dtStart) {
            throw new InvalidArgumentException(t('calendar.event.err.range'));
        }

        $idOperator = (int)($post['id_operator'] ?? 0);
        if ($idOperator < 1) {
            throw new InvalidArgumentException(t('calendar.event.err.operator_required'));
        }

        $isBlock = !empty($post['is_block']) ? 1 : 0;
        $contentIds = [];
        $rawContent = $post['content'] ?? [];
        if (is_array($rawContent)) {
            foreach ($rawContent as $cid) {
                $cid = (int)$cid;
                if ($cid > 0) {
                    $contentIds[] = $cid;
                }
            }
        }
        $contentIds = array_values(array_unique($contentIds));
        if (!$isBlock && $contentIds === []) {
            throw new InvalidArgumentException(t('calendar.event.err.content_required'));
        }

        $priceMap = [];
        $rawPrices = $post['content_price'] ?? [];
        if (!is_array($rawPrices)) {
            $rawPrices = [];
        }
        $realSumm = 0.0;
        $priceParts = [];
        foreach ($contentIds as $cid) {
            $price = null;
            if (isset($rawPrices[$cid]) && $rawPrices[$cid] !== '') {
                $price = (float)$rawPrices[$cid];
            } elseif (isset($rawPrices[(string)$cid]) && $rawPrices[(string)$cid] !== '') {
                $price = (float)$rawPrices[(string)$cid];
            } else {
                $prod = ProductsRepository::find($cid);
                $price = $prod ? (float)($prod['price'] ?? 0) : 0.0;
            }
            if ($price < 0) {
                $price = 0.0;
            }
            $priceMap[$cid] = $price;
            $realSumm += $price;
            $priceParts[] = 'p' . $cid . ':' . (string)(int)round($price);
        }

        $phone = preg_replace('/\D+/', '', (string)($post['phone'] ?? '')) ?? '';
        if (mb_strlen($phone) > 11) {
            $phone = mb_substr($phone, 0, 11);
        }
        if (!$isBlock && $phone === '' && (int)($post['id_user'] ?? 0) < 1) {
            // walk-in without phone is allowed if name given; soft check only for empty both later
        }

        $postedSumm = trim((string)($post['real_summ'] ?? ''));
        if ($postedSumm !== '') {
            $realSumm = max(0.0, (float)$postedSumm);
        }

        return [
            'id_operator' => $idOperator,
            'id_user' => max(0, (int)($post['id_user'] ?? 0)),
            'id_promo_act' => max(0, (int)($post['id_promo_act'] ?? 0)),
            'dtstart' => $dtStart,
            'dtend' => $dtEnd,
            'fix_move' => !empty($post['fix_move']) ? 1 : 0,
            'content' => implode(',', $contentIds),
            'content_ids' => $contentIds,
            'content_price' => implode(',', $priceParts),
            'content_price_map' => $priceMap,
            'descr' => mb_substr(trim((string)($post['descr'] ?? '')), 0, 2000),
            'phone' => $phone,
            'name' => mb_substr(trim((string)($post['name'] ?? '')), 0, 255),
            'bonus_discount' => max(0.0, (float)($post['bonus_discount'] ?? 0)),
            'bonus_use' => !empty($post['bonus_use']) ? 1 : 0,
            'pay' => max(0, min(255, (int)($post['pay'] ?? 0))),
            'is_block' => $isBlock,
            'real_summ' => $realSumm,
            'sms' => serialize(['2d' => 0, '2h' => 0, 'os' => 0, 'w' => 0]),
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create(array $data): int
    {
        $overlaps = self::findOverlaps((int)$data['id_operator'], (int)$data['dtstart'], (int)$data['dtend']);
        if ($overlaps !== []) {
            throw new InvalidArgumentException(self::overlapMessage($overlaps));
        }

        $now = time();
        $creator = Auth::userId();
        // Legacy shared table: several NOT NULL columns have no DEFAULT in old dumps.
        $cols = [
            'ID_OPERATOR', 'ID_OPERATOR_CREATE', 'ID_USER', 'ID_PROMO_ACT',
            'DTCREATE', 'DTSTART', 'DTEND', 'DTUPDATE',
            'FIX_MOVE', 'CONTENT', 'CONTENT_PRICE', 'SMS', 'DESCR', 'NODEFTIMEXPL',
            'PHONE', 'NAME', 'BONUS_DISCOUNT', 'BONUS_USE', 'PAY', 'PPP_LOG',
            'IS_BLOCK', 'REAL_SUMM', 'ENABLED',
            'EVT_TIME_RECOM', 'EVT_TIME_REAL', 'EVT_COSM_ACCEPT', 'EVT_DECLINE_REASON', 'EVT_DT_COSM_MOD',
        ];
        $vals = [
            $data['id_operator'],
            $creator,
            $data['id_user'],
            $data['id_promo_act'],
            $now,
            $data['dtstart'],
            $data['dtend'],
            $now,
            $data['fix_move'],
            $data['content'],
            $data['content_price'],
            $data['sms'],
            $data['descr'],
            '',
            $data['phone'],
            $data['name'],
            $data['bonus_discount'],
            $data['bonus_use'],
            $data['pay'],
            '',
            $data['is_block'],
            $data['real_summ'],
            // Active client events ENABLED=1; legacy blocks commonly use 0 (still occupy via BookingOccupancy).
            !empty($data['is_block']) ? 0 : 1,
            0,
            0,
            0,
            '',
            0,
        ];
        // Optional legacy columns (present on oper dumps, often without DEFAULT).
        $optional = [
            'DTENABLE' => 0,
            'OPERATOR_ENABLED' => 0,
            'UPSALE_DATA' => '',
            'CONTENT_DATA' => '',
            'NONEWEXPL' => '',
        ];
        foreach ($optional as $col => $default) {
            if (Database::columnExists('base_events', $col)) {
                $cols[] = $col;
                $vals[] = $default;
            }
        }
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $st = Database::pdo()->prepare(
            'INSERT INTO base_events (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')'
        );
        $st->execute($vals);
        $id = (int)Database::pdo()->lastInsertId();
        self::logChange($id, 'create', '', self::snapshotText($data), '');
        return $id;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid event id');
        }
        $old = self::find($id);
        if (!$old) {
            throw new InvalidArgumentException(t('calendar.event.err.not_found', ['id' => $id]));
        }

        $overlaps = self::findOverlaps((int)$data['id_operator'], (int)$data['dtstart'], (int)$data['dtend'], $id);
        if ($overlaps !== []) {
            throw new InvalidArgumentException(self::overlapMessage($overlaps));
        }

        $now = time();
        $st = Database::pdo()->prepare(
            'UPDATE base_events SET
             ID_OPERATOR = ?, ID_USER = ?, ID_PROMO_ACT = ?, DTSTART = ?, DTEND = ?, DTUPDATE = ?,
             FIX_MOVE = ?, CONTENT = ?, CONTENT_PRICE = ?, DESCR = ?, PHONE = ?, NAME = ?,
             BONUS_DISCOUNT = ?, BONUS_USE = ?, PAY = ?, IS_BLOCK = ?, REAL_SUMM = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['id_operator'],
            $data['id_user'],
            $data['id_promo_act'],
            $data['dtstart'],
            $data['dtend'],
            $now,
            $data['fix_move'],
            $data['content'],
            $data['content_price'],
            $data['descr'],
            $data['phone'],
            $data['name'],
            $data['bonus_discount'],
            $data['bonus_use'],
            $data['pay'],
            $data['is_block'],
            $data['real_summ'],
            $id,
        ]);
        self::logChange($id, 'update', self::snapshotText($old), self::snapshotText($data), '');
    }

    public static function delete(int $id): void
    {
        if ($id < 1) {
            return;
        }
        $old = self::find($id);
        if (!$old) {
            return;
        }
        $undo = 'INSERT INTO base_events SET ID="' . $id . '", ' . self::snapshotText($old);
        Database::pdo()->prepare('DELETE FROM base_events WHERE ID = ?')->execute([$id]);
        self::logChange($id, 'delete', self::snapshotText($old), '', $undo);
    }

    /**
     * @param list<array{id:int,dtstart:int,dtend:int}> $overlaps
     */
    private static function overlapMessage(array $overlaps): string
    {
        $bits = [];
        foreach ($overlaps as $o) {
            $bits[] = '#' . $o['id'] . ' (' . self::formatRange($o['dtstart'], $o['dtend']) . ')';
        }
        return t('calendar.event.err.overlap', ['list' => implode(', ', $bits)]);
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function snapshotText(array $row): string
    {
        $keys = [
            'id_operator', 'id_user', 'dtstart', 'dtend', 'content', 'content_price',
            'descr', 'phone', 'name', 'real_summ', 'is_block', 'fix_move', 'pay',
        ];
        $parts = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $row)) {
                $parts[] = strtoupper($k) . '="' . str_replace('"', '\\"', (string)$row[$k]) . '"';
            }
        }
        return implode(",\n", $parts);
    }

    private static function logChange(int $eventId, string $type, string $old, string $new, string $undo): void
    {
        if (!Database::tableExists('base_events_changes')) {
            return;
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO base_events_changes
             (ID_EVENT, ID_OPERATOR, ID_USER, TYPE, DT, TEXT_OLD, TEXT_NEW, UNDO_SQL, FIX_MOVE)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $st->execute([
            $eventId,
            Auth::userId(),
            0,
            $type,
            time(),
            $old,
            $new,
            $undo,
        ]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['id_operator'] = (int)($row['id_operator'] ?? 0);
        $row['id_operator_create'] = (int)($row['id_operator_create'] ?? 0);
        $row['id_user'] = (int)($row['id_user'] ?? 0);
        $row['dtstart'] = (int)($row['dtstart'] ?? 0);
        $row['dtend'] = (int)($row['dtend'] ?? 0);
        $row['real_summ'] = (float)($row['real_summ'] ?? 0);
        $row['is_block'] = (int)($row['is_block'] ?? 0);
        $row['enabled'] = (int)($row['enabled'] ?? 0);
        $row['fix_move'] = (int)($row['fix_move'] ?? 0);
        $row['content_ids'] = self::parseContentIds((string)($row['content'] ?? ''));
        $row['content_price_map'] = self::parseContentPrices((string)($row['content_price'] ?? ''));
        return $row;
    }

    /** @return list<int> */
    public static function parseContentIds(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $ids = [];
        foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $part) {
            $id = (int)$part;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /** @return array<int,float> productId => price */
    public static function parseContentPrices(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $part) {
            if (preg_match('/^p(\d+):([\d.]+)$/', $part, $m)) {
                $out[(int)$m[1]] = (float)$m[2];
            }
        }
        return $out;
    }

    public static function formatTime(int $ts): string
    {
        if ($ts <= 0) {
            return '—';
        }
        return date('H:i', $ts);
    }

    public static function formatRange(int $start, int $end): string
    {
        if ($start <= 0) {
            return '—';
        }
        $out = self::formatTime($start);
        if ($end > $start) {
            $out .= '–' . self::formatTime($end);
        }
        return $out;
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>|null
     */
    public static function forAgent(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        unset($row['ppp_log'], $row['sms']);
        if (isset($row['dtstart'])) {
            $row['dtstart_iso'] = self::tsToLocal((int)$row['dtstart']);
        }
        if (isset($row['dtend'])) {
            $row['dtend_iso'] = self::tsToLocal((int)$row['dtend']);
        }
        return $row;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function listForAgent(array $items): array
    {
        $out = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized = self::forAgent($row);
            if ($normalized !== null) {
                $out[] = $normalized;
            }
        }
        return $out;
    }

    /**
     * Operator <select> for event create/edit (oper getOperators).
     * Day shifts with CAN_BE_ASSIGNED (procedures), excl. ASSIST_TEAM;
     * always keeps $keepOperatorId even if off-shift / disabled.
     *
     * @return array<int,string> id => label
     */
    public static function staffChoicesForDay(int $dayStart, int $keepOperatorId = 0): array
    {
        if ($dayStart <= 0) {
            $dayStart = time();
        }
        $dayStart = (int)strtotime(date('Y-m-d 00:00:00', $dayStart));
        $myOnly = !empty(Auth::statusFlags()['EVT_MY_ONLY']);
        $selfId = Auth::userId();
        $out = [];

        $candidateIds = [];
        if (class_exists('WorkweeksRepository', false) && Database::tableExists('base_workweeks')) {
            foreach (WorkweeksRepository::listForDay($dayStart) as $sh) {
                $oid = (int)($sh['id_operator'] ?? 0);
                if ($oid < 1) {
                    continue;
                }
                if ($myOnly && $oid !== $selfId) {
                    continue;
                }
                if ((int)($sh['assist_team'] ?? 0) > 0) {
                    continue;
                }
                $candidateIds[$oid] = trim((string)($sh['operator_name'] ?? ''));
            }
        }

        $canProc = self::usersCanTakeProcedures(array_keys($candidateIds));
        foreach ($candidateIds as $oid => $label) {
            if (empty($canProc[$oid])) {
                continue;
            }
            if ($label === '') {
                $label = '#' . $oid;
            }
            $out[$oid] = $label;
        }

        // No workweeks / empty day: still offer assignable procedure staff (not assistants).
        if ($out === [] && !$myOnly && class_exists('WorkweeksRepository', false)) {
            foreach (WorkweeksRepository::listAssignableStaff() as $oid => $card) {
                $oid = (int)$oid;
                if ($oid < 1 || (int)($card['group'] ?? 0) === 3) {
                    continue;
                }
                if (empty(self::usersCanTakeProcedures([$oid])[$oid])) {
                    continue;
                }
                $out[$oid] = (string)($card['label'] ?? ('#' . $oid));
            }
        } elseif ($out === [] && $myOnly && $selfId > 0) {
            $u = class_exists('UsersRepository', false) ? UsersRepository::find($selfId) : null;
            $label = $u ? trim((string)(($u['name'] ?? '') !== '' ? $u['name'] : ($u['login'] ?? ''))) : '';
            $out[$selfId] = $label !== '' ? $label : ('#' . $selfId);
        }

        if ($keepOperatorId > 0 && !isset($out[$keepOperatorId])) {
            if (!$myOnly || $keepOperatorId === $selfId) {
                $u = class_exists('UsersRepository', false) ? UsersRepository::find($keepOperatorId) : null;
                $label = $u ? trim((string)(($u['name'] ?? '') !== '' ? $u['name'] : ($u['login'] ?? ''))) : '';
                if ($label === '') {
                    $label = '#' . $keepOperatorId;
                }
                if ($u && (string)($u['enabled'] ?? '1') === '0') {
                    $label .= ' (' . t('core.users.filter_disabled') . ')';
                }
                $label .= ' — ' . t('calendar.event.operator_not_on_shift');
                $out[$keepOperatorId] = $label;
            }
        }

        return $out;
    }

    /**
     * @param list<int> $userIds
     * @return array<int,bool>
     */
    private static function usersCanTakeProcedures(array $userIds): array
    {
        $out = [];
        $ids = [];
        foreach ($userIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = true;
                $out[$id] = false;
            }
        }
        if ($ids === [] || !Database::tableExists('base_users')) {
            return $out;
        }
        if (!Database::tableExists('base_admin_statuses')
            || !Database::columnExists('base_admin_statuses', 'CAN_BE_ASSIGNED')
        ) {
            foreach (array_keys($ids) as $id) {
                $out[$id] = true;
            }
            return $out;
        }
        $statusCol = Database::columnExists('base_users', 'ID_STATUS') ? 'ID_STATUS' : 'name_enabled';
        $in = implode(',', array_map('intval', array_keys($ids)));
        $rows = Database::pdo()->query(
            "SELECT u.id, COALESCE(st.CAN_BE_ASSIGNED, 0) AS can_be_assigned
             FROM base_users u
             LEFT JOIN base_admin_statuses st ON st.ID = u.`$statusCol`
             WHERE u.id IN ($in)"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $out[(int)$row['id']] = (int)$row['can_be_assigned'] > 0;
        }
        return $out;
    }

    /** Whether current user may create events (module perm + status flag). */
    public static function canCreate(): bool
    {
        $manual = strtolower(trim((string)Config::get('calendar', 'manual_create', '1')));
        if (in_array($manual, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }
        return Auth::can('calendar.edit') && Auth::capability('EVT_CREATE_NEW');
    }

    public static function canModify(?array $event = null): bool
    {
        if (!Auth::can('calendar.edit') || !Auth::capability('EVT_MODIFY')) {
            return false;
        }
        if ($event && !empty(Auth::statusFlags()['EVT_MY_ONLY'])) {
            return (int)($event['id_operator'] ?? 0) === Auth::userId();
        }
        return true;
    }

    public static function canDelete(?array $event = null): bool
    {
        if (!Auth::can('calendar.edit') || !Auth::capability('EVT_DELETE')) {
            return false;
        }
        if ($event && !empty(Auth::statusFlags()['EVT_MY_ONLY'])) {
            return (int)($event['id_operator'] ?? 0) === Auth::userId();
        }
        return true;
    }
}
