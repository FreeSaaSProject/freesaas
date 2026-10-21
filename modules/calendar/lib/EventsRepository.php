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
                    e.ID_USER AS id_user,
                    e.DTCREATE AS dtcreate, e.DTSTART AS dtstart, e.DTEND AS dtend, e.DTUPDATE AS dtupdate,
                    e.DESCR AS descr, e.PHONE AS phone, e.NAME AS name,
                    e.IS_BLOCK AS is_block, e.ENABLED AS enabled,
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
                    ID_USER AS id_user,
                    DTCREATE AS dtcreate, DTSTART AS dtstart, DTEND AS dtend, DTUPDATE AS dtupdate,
                    DESCR AS descr, PHONE AS phone, NAME AS name,
                    IS_BLOCK AS is_block, ENABLED AS enabled
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
        // datetime-local: YYYY-MM-DDTHH:MM — parse as server local wall time (no UTC shift).
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/', $raw, $m)) {
            $ts = mktime(
                (int)$m[4],
                (int)$m[5],
                isset($m[6]) ? (int)$m[6] : 0,
                (int)$m[2],
                (int)$m[3],
                (int)$m[1]
            );
            return $ts !== false ? (int)$ts : 0;
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

        $blocksOnly = CalendarConfig::isBlocksMode();
        $isBlock = $blocksOnly ? 1 : (!empty($post['is_block']) ? 1 : 0);

        $items = [];
        if (!$isBlock) {
            $items = EventItemsRepository::itemsFromPost($post);
            if ($items === []) {
                throw new InvalidArgumentException(t('calendar.event.err.content_required'));
            }
            self::assertOperatorCanPerform($idOperator, $items, $dtStart);
            // Basket drives visit length when client left auto duration on (default).
            $autoDur = !isset($post['auto_duration']) || (string)$post['auto_duration'] !== '0';
            if ($autoDur) {
                $dur = EventItemsRepository::durationSec($items);
                if ($dur > 0) {
                    $dtEnd = $dtStart + $dur;
                }
            }
        }

        $phone = '';
        $name = '';
        $idUser = 0;
        if (!$isBlock) {
            $phone = self::normalizeEventPhone((string)($post['phone'] ?? ''));
            $name = mb_substr(trim((string)($post['name'] ?? '')), 0, 255);
            $idUser = max(0, (int)($post['id_user'] ?? 0));
            // Bind existing client by phone so the visit is not orphaned.
            if (class_exists('ClientsRepository', false)) {
                if ($idUser > 0) {
                    $client = ClientsRepository::find($idUser);
                    if (!$client) {
                        $idUser = 0;
                    } else {
                        if ($phone === '') {
                            $phone = self::normalizeEventPhone((string)($client['tel'] ?? ''));
                        }
                        if ($name === '') {
                            $name = mb_substr(trim((string)($client['name'] ?? '')), 0, 255);
                        }
                    }
                }
                if ($idUser < 1 && $phone !== '') {
                    $byPhone = ClientsRepository::findByPhoneDigits($phone);
                    if ($byPhone) {
                        $idUser = (int)($byPhone['id'] ?? 0);
                        if ($name === '') {
                            $name = mb_substr(trim((string)($byPhone['name'] ?? '')), 0, 255);
                        }
                    }
                }
            }
        }

        $contentIds = [];
        $priceMap = [];
        $qtyMap = [];
        foreach ($items as $it) {
            $gid = (int)$it['id_good'];
            $contentIds[] = $gid;
            $priceMap[$gid] = (float)$it['price'];
            $qtyMap[$gid] = (int)$it['qty'];
        }

        return [
            'id_operator' => $idOperator,
            'id_user' => $idUser,
            'dtstart' => $dtStart,
            'dtend' => $dtEnd,
            'items' => $items,
            'content_ids' => $contentIds,
            'content_price_map' => $priceMap,
            'content_qty_map' => $qtyMap,
            'descr' => mb_substr(trim((string)($post['descr'] ?? '')), 0, 2000),
            'phone' => $phone,
            'name' => $name,
            'is_block' => $isBlock,
        ];
    }

    /**
     * @param list<array{id_good:int,qty?:int}> $items
     */
    private static function assertOperatorCanPerform(int $operatorId, array $items, int $dayTs): void
    {
        if ($operatorId < 1 || $items === [] || !class_exists('BookingOperatorGate', false)) {
            return;
        }
        $dayStart = (int)strtotime(date('Y-m-d 00:00:00', $dayTs > 0 ? $dayTs : time()));
        $probe = [];
        foreach ($items as $it) {
            $gid = (int)($it['id_good'] ?? 0);
            if ($gid < 1) {
                continue;
            }
            $typeId = 0;
            if (class_exists('ProductsRepository', false)) {
                $p = ProductsRepository::find($gid);
                $typeId = $p ? max(0, (int)($p['type_id'] ?? 0)) : 0;
            }
            $probe[] = ['id' => $gid, 'type_id' => $typeId];
        }
        if ($probe === []) {
            return;
        }
        $exp = BookingOperatorGate::explain($operatorId, $probe, $dayStart, false);
        if (empty($exp['ok'])) {
            $detail = $exp['blockers'] !== [] ? (' [' . implode(', ', $exp['blockers']) . ']') : '';
            throw new InvalidArgumentException(t('calendar.event.err.staff_goods') . $detail);
        }
    }

    /** Normalize to 11-digit 7XXXXXXXXXX (same as storefront) or empty. */
    public static function normalizeEventPhone(string $raw): string
    {
        if (class_exists('SitesGuest', false)) {
            return SitesGuest::normalizePhone($raw);
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }
        if (strlen($digits) !== 11 || $digits[0] !== '7') {
            return '';
        }
        return $digits;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create(array $data): int
    {
        self::assertModeAllowsWrite($data);

        self::assertTimelineFree(
            (int)$data['id_operator'],
            (int)$data['dtstart'],
            (int)$data['dtend'],
            0
        );

        $now = time();
        $isBlock = !empty($data['is_block']) ? 1 : 0;
        $st = Database::pdo()->prepare(
            'INSERT INTO base_events
             (ID_OPERATOR, ID_OPERATOR_CREATE, ID_USER, DTCREATE, DTSTART, DTEND, DTUPDATE,
              DESCR, PHONE, NAME, IS_BLOCK, ENABLED)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $data['id_operator'],
            Auth::userId(),
            $data['id_user'] ?? 0,
            $now,
            $data['dtstart'],
            $data['dtend'],
            $now,
            $data['descr'] ?? '',
            $data['phone'] ?? '',
            $data['name'] ?? '',
            $isBlock,
            $isBlock ? 0 : 1,
        ]);
        $id = (int)Database::pdo()->lastInsertId();

        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if ($isBlock) {
            EventItemsRepository::replaceItems($id, []);
        } else {
            EventItemsRepository::replaceItems($id, $items);
        }
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

        self::assertModeAllowsWrite($data, $old);

        self::assertTimelineFree(
            (int)$data['id_operator'],
            (int)$data['dtstart'],
            (int)$data['dtend'],
            $id
        );

        $oldStart = (int)($old['dtstart'] ?? 0);
        $newStart = (int)$data['dtstart'];
        $deltaSec = ($oldStart > 0 && $newStart > 0) ? ($newStart - $oldStart) : 0;
        $newOp = (int)$data['id_operator'];
        $oldOp = (int)($old['id_operator'] ?? 0);
        $newUser = (int)($data['id_user'] ?? 0);
        $oldUser = (int)($old['id_user'] ?? 0);

        $now = time();
        $isBlock = !empty($data['is_block']) ? 1 : 0;
        $st = Database::pdo()->prepare(
            'UPDATE base_events SET
             ID_OPERATOR = ?, ID_USER = ?, DTSTART = ?, DTEND = ?, DTUPDATE = ?,
             DESCR = ?, PHONE = ?, NAME = ?, IS_BLOCK = ?, ENABLED = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['id_operator'],
            $data['id_user'] ?? 0,
            $data['dtstart'],
            $data['dtend'],
            $now,
            $data['descr'] ?? '',
            $data['phone'] ?? '',
            $data['name'] ?? '',
            $isBlock,
            $isBlock ? 0 : 1,
            $id,
        ]);

        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        EventItemsRepository::replaceItems($id, $isBlock ? [] : $items);

        // Child journal rows travel with the visit (do not block the landing zone).
        if (!$isBlock && class_exists('ProceduresRepository', false)) {
            ProceduresRepository::syncAfterEventMove(
                $id,
                $deltaSec,
                $newOp > 0 && $newOp !== $oldOp ? $newOp : null,
                $newUser > 0 && $newUser !== $oldUser ? $newUser : null
            );
        }
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
        EventItemsRepository::deleteByEvent($id);
        if (class_exists('EventBonusRepository', false)) {
            EventBonusRepository::deleteByEvent($id);
        }
        Database::pdo()->prepare('DELETE FROM base_events WHERE ID = ?')->execute([$id]);
    }

    public static function touch(int $id): void
    {
        if ($id < 1 || !Database::tableExists('base_events')) {
            return;
        }
        Database::pdo()->prepare('UPDATE base_events SET DTUPDATE = ? WHERE ID = ?')->execute([time(), $id]);
    }

    /**
     * Reject save when operator timeline is busy (events + procedures + advance orders).
     * Soft-depends on booking occupancy when that module is enabled.
     */
    private static function assertTimelineFree(int $operatorId, int $dtStart, int $dtEnd, int $excludeEventId = 0): void
    {
        if ($operatorId < 1 || $dtStart <= 0 || $dtEnd <= $dtStart) {
            return;
        }

        if (class_exists('BookingOccupancy', false)) {
            $dayStart = (int)strtotime(date('Y-m-d 00:00:00', $dtStart));
            $dayEnd = (int)strtotime(date('Y-m-d 23:59:59', $dtStart));
            $scanEnd = max($dayEnd, $dtEnd) + 1;
            // Own journal rows (ID_EVENT_PARENT) must not block moving/resaving the visit.
            $excludeProcIds = null;
            if ($excludeEventId > 0 && class_exists('ProceduresRepository', false)) {
                $excludeProcIds = ProceduresRepository::idsByEventParent($excludeEventId);
                if ($excludeProcIds === []) {
                    $excludeProcIds = null;
                }
            }
            $used = BookingOccupancy::build(
                $dayStart,
                $scanEnd,
                null,
                $excludeProcIds,
                $excludeEventId > 0 ? $excludeEventId : null
            );
            $busy = $used[$operatorId] ?? [];
            foreach ($busy as $key => $ev) {
                $evStart = (int)($ev['DTSTART'] ?? 0);
                $evEnd = (int)($ev['DTEND'] ?? 0);
                if ($evStart < 1 || $evEnd <= $evStart) {
                    continue;
                }
                if ($evStart < $dtEnd && $evEnd > $dtStart) {
                    if (class_exists('BookingValidate', false)) {
                        throw new InvalidArgumentException(
                            BookingValidate::describeBusyConflict((string)$key, $ev)
                        );
                    }
                    throw new InvalidArgumentException(t('calendar.event.err.overlap', [
                        'list' => (string)$key . ' (' . self::formatRange($evStart, $evEnd) . ')',
                    ]));
                }
            }
            return;
        }

        $overlaps = self::findOverlaps($operatorId, $dtStart, $dtEnd, $excludeEventId);
        if ($overlaps !== []) {
            throw new InvalidArgumentException(self::overlapMessage($overlaps));
        }
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
     * @param array<string,mixed> $data
     * @param array<string,mixed>|null $existing
     */
    private static function assertModeAllowsWrite(array $data, ?array $existing = null): void
    {
        if (!CalendarConfig::isBlocksMode()) {
            return;
        }
        if ($existing !== null && empty($existing['is_block'])) {
            throw new InvalidArgumentException(t('calendar.event.err.visit_readonly_blocks'));
        }
        if (empty($data['is_block'])) {
            throw new InvalidArgumentException(t('calendar.event.err.visits_disabled'));
        }
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
        $row['is_block'] = (int)($row['is_block'] ?? 0);
        $row['enabled'] = (int)($row['enabled'] ?? 0);

        $eventId = $row['id'];
        $items = $eventId > 0 ? EventItemsRepository::listByEvent($eventId) : [];
        $row['items'] = $items;
        $contentIds = [];
        $priceMap = [];
        $qtyMap = [];
        $realSumm = 0.0;
        foreach ($items as $it) {
            $contentIds[] = $it['id_good'];
            $priceMap[$it['id_good']] = $it['price'];
            $qtyMap[$it['id_good']] = $it['qty'];
            $realSumm += $it['price'] * $it['qty'];
        }
        $row['content_ids'] = $contentIds;
        $row['content_price_map'] = $priceMap;
        $row['content_qty_map'] = $qtyMap;
        $row['real_summ'] = $realSumm;
        return $row;
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
        $dtstart = (int)($row['dtstart'] ?? 0);
        $dtend = (int)($row['dtend'] ?? 0);
        $items = is_array($row['items'] ?? null) ? $row['items'] : [];
        $briefParts = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $gid = (int)($it['id_good'] ?? 0);
            if ($gid < 1) {
                continue;
            }
            $qty = max(1, (int)($it['qty'] ?? 1));
            $briefParts[] = '#' . $gid . ($qty > 1 ? ('×' . $qty) : '');
            if (count($briefParts) >= 5) {
                break;
            }
        }
        $descr = trim((string)($row['descr'] ?? ''));
        if ($descr !== '' && function_exists('mb_strlen') && mb_strlen($descr, 'UTF-8') > 120) {
            $descr = mb_substr($descr, 0, 117, 'UTF-8') . '…';
        } elseif (strlen($descr) > 120) {
            $descr = substr($descr, 0, 117) . '…';
        }
        $opName = trim((string)($row['operator_name'] ?? ''));
        $opAcronym = '';
        if ($opName !== '') {
            $opAcronym = class_exists('CalendarTimeline', false)
                ? CalendarTimeline::acronym($opName)
                : (class_exists('UsersRepository', false) ? UsersRepository::shortName($opName) : '');
            if ($opAcronym === '?') {
                $opAcronym = '';
            }
        }
        $agentItems = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $agentItems[] = [
                'good_id' => (int)($it['id_good'] ?? 0),
                'price' => (float)($it['price'] ?? 0),
                'qty' => max(1, (int)($it['qty'] ?? 1)),
                'pos' => (int)($it['pos'] ?? 0),
            ];
        }
        return [
            'id' => (int)($row['id'] ?? 0),
            'id_operator' => (int)($row['id_operator'] ?? 0),
            'operator_name' => $opName,
            'operator_acronym' => $opAcronym,
            'id_user' => (int)($row['id_user'] ?? 0),
            'client_name' => trim((string)($row['client_name'] ?? '')),
            'name' => trim((string)($row['name'] ?? '')),
            'phone' => trim((string)($row['phone'] ?? '')),
            'descr' => $descr,
            'dtstart' => $dtstart,
            'dtend' => $dtend,
            'dtstart_iso' => $dtstart > 0 ? self::tsToLocal($dtstart) : '',
            'dtend_iso' => $dtend > 0 ? self::tsToLocal($dtend) : '',
            'is_block' => (int)($row['is_block'] ?? 0),
            'enabled' => (int)($row['enabled'] ?? 1),
            'content_brief' => implode(', ', $briefParts),
            'items' => $agentItems,
            'real_summ' => isset($row['real_summ']) ? (float)$row['real_summ'] : EventItemsRepository::totalSum((int)($row['id'] ?? 0)),
            'event_mode' => CalendarConfig::eventMode(),
        ];
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

    /** Create anything allowed by event_mode (block and/or visit). */
    public static function canCreate(): bool
    {
        return self::canCreateBlock() || self::canCreateVisit();
    }

    public static function canCreateBlock(): bool
    {
        return Auth::can('calendar.edit') && Auth::capability('EVT_CREATE_NEW');
    }

    public static function canCreateVisit(): bool
    {
        if (!CalendarConfig::isVisitsMode()) {
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
        if (!Auth::can('calendar.delete') || !Auth::capability('EVT_DELETE')) {
            return false;
        }
        if ($event && !empty(Auth::statusFlags()['EVT_MY_ONLY'])) {
            return (int)($event['id_operator'] ?? 0) === Auth::userId();
        }
        return true;
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids): int
    {
        $done = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id < 1) {
                continue;
            }
            $row = self::find($id);
            if ($row === null || !self::canDelete($row)) {
                continue;
            }
            self::delete($id);
            $done++;
        }
        return $done;
    }
}
