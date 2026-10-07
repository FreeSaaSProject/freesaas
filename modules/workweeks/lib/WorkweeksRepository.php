<?php
declare(strict_types=1);

final class WorkweeksRepository
{
    /** When tables module is off / empty, massage-table id defaults to 1. */
    public static function tablesActive(): bool
    {
        return class_exists('TablesRepository', false) && TablesRepository::isActive();
    }

    /** @deprecated use tablesActive() */
    public static function cabinetsActive(): bool
    {
        return self::tablesActive();
    }

    public static function normalizeTableId(int $idTable): int
    {
        if (class_exists('TablesRepository', false)) {
            return TablesRepository::normalizeId($idTable);
        }
        return $idTable > 0 ? $idTable : 1;
    }

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        $op = Database::tableExists('base_users')
            ? "COALESCE(NULLIF(u.name, ''), u.login)"
            : 'w.ID_OPERATOR';
        return [
            'id' => 'w.ID',
            'dt' => 'w.DT',
            'operator' => $op,
            'price' => 'w.PRICE_COL',
            'mas_max' => 'w.MAS_MAX',
            'table' => 'w.ID_TABLE',
            'created' => 'w.DT_CREATE',
        ];
    }

    /**
     * CRUD list of shift rows.
     *
     * @param array{
     *   q?:string,user_id?:int,date_from?:string,date_to?:string,
     *   order?:string,limit?:int,offset?:int
     * } $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_workweeks')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        $userId = (int)($opts['user_id'] ?? 0);
        $dateFrom = trim((string)($opts['date_from'] ?? ''));
        $dateTo = trim((string)($opts['date_to'] ?? ''));

        $joins = '';
        $extra = 'NULL AS operator_name';
        if (Database::tableExists('base_users')) {
            $joins = ' LEFT JOIN base_users u ON u.id = w.ID_OPERATOR';
            $extra = "COALESCE(NULLIF(u.name, ''), u.login) AS operator_name";
        }

        if ($userId > 0) {
            $where[] = 'w.ID_OPERATOR = ?';
            $params[] = $userId;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $ts = strtotime($dateFrom . ' 00:00:00');
            if ($ts !== false) {
                $where[] = 'w.DT >= ?';
                $params[] = (int)$ts;
            }
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $ts = strtotime($dateTo . ' 00:00:00');
            if ($ts !== false) {
                $where[] = 'w.DT <= ?';
                $params[] = (int)$ts;
            }
        }
        if ($q !== '') {
            if (ctype_digit($q)) {
                $where[] = '(w.ID = ? OR w.ID_OPERATOR = ? OR w.ID_TABLE = ?)';
                $params[] = (int)$q;
                $params[] = (int)$q;
                $params[] = (int)$q;
            } elseif (Database::tableExists('base_users')) {
                $where[] = "(COALESCE(NULLIF(u.name, ''), u.login) LIKE ?)";
                $params[] = '%' . $q . '%';
            }
        }

        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare(
            "SELECT COUNT(*) FROM base_workweeks w $joins WHERE $sqlWhere"
        );
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? '')) ?: 'w.DT DESC, w.ID DESC';
        // operator_name is an alias — MySQL allows ORDER BY alias in SELECT.
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT w.ID AS id, w.ID_OPERATOR AS id_operator, w.ID_OPERATOR_CREATE AS id_operator_create,
                       w.DT_CREATE AS dt_create, w.DT AS dt, w.PRICE_COL AS price_col,
                       w.ID_TABLE AS id_table, w.MAS_MAX AS mas_max, $extra
                FROM base_workweeks w
                $joins
                WHERE $sqlWhere
                ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'id' => (int)$row['id'],
                'id_operator' => (int)$row['id_operator'],
                'id_operator_create' => (int)$row['id_operator_create'],
                'dt_create' => (int)$row['dt_create'],
                'dt' => (int)$row['dt'],
                'date' => date('Y-m-d', (int)$row['dt']),
                'date_label' => date('d.m.Y', (int)$row['dt']),
                'price_col' => (int)$row['price_col'],
                'id_table' => (int)$row['id_table'],
                'mas_max' => (int)$row['mas_max'],
                'operator_name' => (string)($row['operator_name'] ?? ''),
            ];
        }
        return ['items' => $items, 'total' => $total];
    }

    /** @param array<string,mixed> $post */
    public static function dataFromPost(array $post): array
    {
        $date = trim((string)($post['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException(t('workweeks.err.invalid'));
        }
        $ts = strtotime($date . ' 00:00:00');
        if ($ts === false) {
            throw new InvalidArgumentException(t('workweeks.err.invalid'));
        }
        $op = (int)($post['id_operator'] ?? 0);
        if ($op < 1) {
            throw new InvalidArgumentException(t('workweeks.err.invalid'));
        }
        return [
            'dt' => (int)$ts,
            'date' => $date,
            'id_operator' => $op,
            'price_col' => max(1, min(5, (int)($post['price_col'] ?? 1))),
            'mas_max' => max(1, min(9, (int)($post['mas_max'] ?? 1))),
            'id_table' => self::normalizeTableId((int)($post['id_table'] ?? 0)),
        ];
    }

    /**
     * @param array{dt:int,id_operator:int,price_col:int,mas_max:int,id_table:int} $data
     */
    public static function createFromForm(array $data): int
    {
        if (!self::canAssignOperator((int)$data['id_operator'])) {
            throw new RuntimeException(t('workweeks.err.no_assign'));
        }
        $existing = self::findByDayOperator((int)$data['dt'], (int)$data['id_operator']);
        if ($existing) {
            throw new RuntimeException(t('workweeks.err.duplicate'));
        }
        $id = self::assign(
            (int)$data['dt'],
            (int)$data['id_operator'],
            (int)$data['price_col'],
            (int)$data['mas_max'],
            (int)$data['id_table']
        );
        self::logChangeHtml(self::buildLogLine('add', [
            'id_operator' => (int)$data['id_operator'],
            'dt' => (int)$data['dt'],
            'price_col' => (int)$data['price_col'],
        ]));
        return $id;
    }

    /**
     * @param array{dt:int,id_operator:int,price_col:int,mas_max:int,id_table:int} $data
     */
    public static function updateFromForm(int $id, array $data): void
    {
        $row = self::find($id);
        if (!$row) {
            throw new RuntimeException(t('workweeks.err.not_found'));
        }
        if (!self::canAssignOperator((int)$row['id_operator'])
            || !self::canAssignOperator((int)$data['id_operator'])
        ) {
            throw new RuntimeException(t('workweeks.err.no_assign'));
        }
        $dup = self::findByDayOperator((int)$data['dt'], (int)$data['id_operator']);
        if ($dup && (int)$dup['id'] !== $id) {
            throw new RuntimeException(t('workweeks.err.duplicate'));
        }

        $changed = (int)$row['dt'] !== (int)$data['dt']
            || (int)$row['id_operator'] !== (int)$data['id_operator']
            || (int)$row['price_col'] !== (int)$data['price_col']
            || (int)$row['mas_max'] !== (int)$data['mas_max']
            || (int)$row['id_table'] !== (int)$data['id_table'];

        $st = Database::pdo()->prepare(
            'UPDATE base_workweeks
             SET ID_OPERATOR = ?, DT = ?, PRICE_COL = ?, MAS_MAX = ?, ID_TABLE = ?
             WHERE ID = ?'
        );
        $st->execute([
            (int)$data['id_operator'],
            (int)$data['dt'],
            (int)$data['price_col'],
            (int)$data['mas_max'],
            (int)$data['id_table'],
            $id,
        ]);

        if ($changed) {
            $bits = [];
            if ((int)$row['id_operator'] !== (int)$data['id_operator']
                || (int)$row['dt'] !== (int)$data['dt']
            ) {
                $bits[] = self::buildLogLine('remove', [
                    'id_operator' => (int)$row['id_operator'],
                    'dt' => (int)$row['dt'],
                ]);
                $bits[] = self::buildLogLine('add', [
                    'id_operator' => (int)$data['id_operator'],
                    'dt' => (int)$data['dt'],
                    'price_col' => (int)$data['price_col'],
                ]);
            } else {
                if ((int)$row['price_col'] !== (int)$data['price_col']) {
                    $bits[] = self::buildLogLine('price', [
                        'id_operator' => (int)$data['id_operator'],
                        'dt' => (int)$data['dt'],
                        'from' => (int)$row['price_col'],
                        'to' => (int)$data['price_col'],
                    ]);
                }
                if ((int)$row['mas_max'] !== (int)$data['mas_max']
                    || (int)$row['id_table'] !== (int)$data['id_table']
                ) {
                    $bits[] = self::buildLogLine('update', [
                        'id_operator' => (int)$data['id_operator'],
                        'dt' => (int)$data['dt'],
                        'price_col' => (int)$data['price_col'],
                        'mas_max' => (int)$data['mas_max'],
                        'id_table' => (int)$data['id_table'],
                    ]);
                }
            }
            if ($bits !== []) {
                self::logChangeHtml(implode('<br>', $bits));
            }
        }
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === [] || !Database::tableExists('base_workweeks')) {
            return 0;
        }
        $done = 0;
        $logBits = [];
        foreach ($ids as $id) {
            $row = self::find($id);
            if (!$row) {
                continue;
            }
            if (!self::canAssignOperator((int)$row['id_operator'])) {
                continue;
            }
            self::delete($id);
            $logBits[] = self::buildLogLine('remove', [
                'id_operator' => (int)$row['id_operator'],
                'dt' => (int)$row['dt'],
            ]);
            $done++;
        }
        if ($logBits !== []) {
            self::logChangeHtml(implode('<br>', $logBits));
        }
        return $done;
    }

    public static function commentsLoggingAvailable(): bool
    {
        return ModuleManager::isEnabled('comments')
            && class_exists('CommentsRepository', false)
            && Database::tableExists('base_comments');
    }

    /**
     * Target news/ticket for schedule-change comments.
     * Stored in cfg/modules/workweeks.ini; one-time seed from legacy base_system.NEWS_SCHEDULE_ID.
     */
    public static function scheduleNewsId(): int
    {
        if (!self::commentsLoggingAvailable()) {
            return 0;
        }
        $configured = Config::get('workweeks', 'schedule_news_id', null);
        if ($configured !== null && $configured !== '') {
            return max(0, (int)$configured);
        }
        // Legacy oper value — copy into module ini once so core stays free of product keys.
        $legacy = 0;
        if (Database::tableExists('base_system')
            && Database::columnExists('base_system', 'NEWS_SCHEDULE_ID')
        ) {
            $row = Database::pdo()->query(
                'SELECT NEWS_SCHEDULE_ID FROM base_system ORDER BY id ASC LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);
            $legacy = max(0, (int)($row['NEWS_SCHEDULE_ID'] ?? 0));
        }
        try {
            Config::set('workweeks', 'schedule_news_id', (string)$legacy);
        } catch (Throwable $e) {
            // Read-only env — still return legacy for this request.
        }
        return $legacy;
    }

    public static function setScheduleNewsId(int $newsId): void
    {
        Config::set('workweeks', 'schedule_news_id', (string)max(0, $newsId));
    }

    /**
     * @param array{id_operator?:int,dt?:int,price_col?:int,from?:int,to?:int,mas_max?:int,id_table?:int} $meta
     */
    private static function buildLogLine(string $kind, array $meta): string
    {
        $oid = (int)($meta['id_operator'] ?? 0);
        $badge = self::operatorLogBadge($oid);
        $date = !empty($meta['dt']) ? date('d.m.Y', (int)$meta['dt']) : '';
        if ($kind === 'add') {
            $pc = (int)($meta['price_col'] ?? 1);
            $extra = $pc > 1 ? (' (PC' . $pc . ')') : '';
            return t('workweeks.log.added', ['who' => $badge, 'date' => $date]) . $extra;
        }
        if ($kind === 'remove') {
            return t('workweeks.log.removed', ['who' => $badge, 'date' => $date]);
        }
        if ($kind === 'price') {
            return t('workweeks.log.price', [
                'who' => $badge,
                'date' => $date,
                'from' => (int)($meta['from'] ?? 0),
                'to' => (int)($meta['to'] ?? 0),
            ]);
        }
        return t('workweeks.log.updated', [
            'who' => $badge,
            'date' => $date,
            'price' => (int)($meta['price_col'] ?? 1),
            'mas_max' => (int)($meta['mas_max'] ?? 1),
            'table' => (int)($meta['id_table'] ?? 1),
        ]);
    }

    private static function operatorLogBadge(int $operatorId): string
    {
        $card = self::staffCard($operatorId);
        $name = $card['label'] ?? ('#' . $operatorId);
        $bg = e((string)($card['bg'] ?? 'var(--bg-input)'));
        $fg = e((string)($card['fg'] ?? 'var(--text)'));
        return '<b style="background:' . $bg . ';color:' . $fg . '">' . e($name) . '</b>';
    }

    private static function logChangeHtml(string $bodyHtml): void
    {
        $bodyHtml = trim($bodyHtml);
        if ($bodyHtml === '') {
            return;
        }
        $newsId = self::scheduleNewsId();
        if ($newsId < 1) {
            return;
        }
        $actor = Auth::userId();
        $actorCard = $actor > 0 ? self::staffCard($actor) : null;
        $actorName = $actorCard['label'] ?? (Auth::loginName() ?: ('#' . $actor));
        $header = t('workweeks.log.header', ['who' => e($actorName)]);
        $text = $header . '<br>' . $bodyHtml;
        try {
            CommentsRepository::add($newsId, $actor > 0 ? $actor : 1, $text);
            // Prune logs older than ~9 months (oper behaviour).
            $cutoff = time() - (3600 * 24 * 7 * 4 * 9);
            Database::pdo()->prepare(
                'DELETE FROM base_comments WHERE ID_OBJECT = ? AND DT < ? AND PID = 0'
            )->execute([$newsId, $cutoff]);
        } catch (Throwable $e) {
            // Logging must not break schedule saves.
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listForDay(int $dayStart): array
    {
        if ($dayStart <= 0 || !Database::tableExists('base_workweeks')) {
            return [];
        }
        $pdo = Database::pdo();
        $joins = '';
        $extra = 'NULL AS operator_name, 0 AS ID_STATUS, 0 AS assist_team, 0 AS is_hidden, NULL AS color, NULL AS color_text, 0 AS can_be_assigned_asany';
        $order = 'w.PRICE_COL ASC, w.ID ASC';
        if (Database::tableExists('base_users')) {
            $joins = ' LEFT JOIN base_users u ON u.id = w.ID_OPERATOR';
            $extra = "COALESCE(NULLIF(u.name, ''), u.login) AS operator_name,
                      COALESCE(u.ID_STATUS, 0) AS ID_STATUS,
                      COALESCE(u.ASSIST_TEAM, 0) AS assist_team,
                      COALESCE(u.IS_HIDDEN, 0) AS is_hidden,
                      u.color AS color,
                      u.color_text AS color_text,
                      0 AS can_be_assigned_asany";
            if (Database::tableExists('base_admin_statuses')
                && Database::columnExists('base_admin_statuses', 'CAN_BE_ASSIGNED_ASANY')
            ) {
                $joins .= ' LEFT JOIN base_admin_statuses st ON st.ID = u.ID_STATUS';
                $extra = "COALESCE(NULLIF(u.name, ''), u.login) AS operator_name,
                          COALESCE(u.ID_STATUS, 0) AS ID_STATUS,
                          COALESCE(u.ASSIST_TEAM, 0) AS assist_team,
                          COALESCE(u.IS_HIDDEN, 0) AS is_hidden,
                          u.color AS color,
                          u.color_text AS color_text,
                          COALESCE(st.CAN_BE_ASSIGNED_ASANY, 0) AS can_be_assigned_asany";
            }
            $asanyExpr = (
                Database::tableExists('base_admin_statuses')
                && Database::columnExists('base_admin_statuses', 'CAN_BE_ASSIGNED_ASANY')
            ) ? 'st.CAN_BE_ASSIGNED_ASANY' : '0';
            $order = "CASE
                        WHEN COALESCE(u.IS_HIDDEN, 0) > 0 THEN 4
                        WHEN COALESCE(u.ASSIST_TEAM, 0) > 0 THEN 3
                        WHEN COALESCE($asanyExpr, 0) > 0 OR COALESCE(u.ID_STATUS, 0) = 2 THEN 2
                        WHEN COALESCE(u.ID_STATUS, 0) = 18 THEN 1
                        ELSE 0
                      END ASC,
                      w.PRICE_COL ASC,
                      COALESCE(NULLIF(u.name, ''), u.login) ASC,
                      w.ID ASC";
        }
        $st = $pdo->prepare(
            "SELECT w.ID AS id, w.ID_OPERATOR AS id_operator, w.ID_OPERATOR_CREATE AS id_operator_create,
                    w.DT_CREATE AS dt_create, w.DT AS dt, w.PRICE_COL AS price_col,
                    w.ID_TABLE AS id_table, w.MAS_MAX AS mas_max,
                    $extra
             FROM base_workweeks w
             $joins
             WHERE w.DT = ?
             ORDER BY $order"
        );
        $st->execute([$dayStart]);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = self::normalizeShiftRow($row);
        }
        return $items;
    }

    /**
     * Staff who can be put on a shift (status CAN_BE_ASSIGNED / ASANY), sorted like oper.
     *
     * @return array<int,array{id:int,label:string,acronym:string,bg:string,fg:string,group:int}>
     */
    public static function listAssignableStaff(): array
    {
        if (!Database::tableExists('base_users')) {
            return [];
        }
        $pdo = Database::pdo();
        $hasStatus = Database::tableExists('base_admin_statuses')
            && Database::columnExists('base_admin_statuses', 'CAN_BE_ASSIGNED');
        $hasAsany = $hasStatus && Database::columnExists('base_admin_statuses', 'CAN_BE_ASSIGNED_ASANY');

        if ($hasStatus) {
            $asanyCol = $hasAsany ? 'COALESCE(st.CAN_BE_ASSIGNED_ASANY, 0)' : '0';
            $sql = "SELECT u.id, u.login, u.name, u.ID_STATUS, u.ASSIST_TEAM, u.IS_HIDDEN,
                           u.color, u.color_text,
                           COALESCE(st.CAN_BE_ASSIGNED, 0) AS can_be_assigned,
                           $asanyCol AS can_be_assigned_asany
                    FROM base_users u
                    LEFT JOIN base_admin_statuses st ON st.ID = u.ID_STATUS
                    WHERE u.enabled = '1'
                      AND (COALESCE(st.CAN_BE_ASSIGNED, 0) > 0 OR $asanyCol > 0)
                    ORDER BY CASE
                               WHEN COALESCE(u.IS_HIDDEN, 0) > 0 THEN 4
                               WHEN COALESCE(u.ASSIST_TEAM, 0) > 0 THEN 3
                               WHEN $asanyCol > 0 OR u.ID_STATUS = 2 THEN 2
                               WHEN u.ID_STATUS = 18 THEN 1
                               ELSE 0
                             END ASC,
                             COALESCE(NULLIF(u.name, ''), u.login) ASC
                    LIMIT 500";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $rows = $pdo->query(
                "SELECT id, login, name, ID_STATUS, ASSIST_TEAM, IS_HIDDEN, color, color_text,
                        1 AS can_be_assigned, 0 AS can_be_assigned_asany
                 FROM base_users WHERE enabled = '1'
                 ORDER BY COALESCE(NULLIF(name, ''), login) ASC LIMIT 500"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if (!self::canAssignOperator($id)) {
                continue;
            }
            $card = self::staffCardFromRow($row);
            if ($card !== null) {
                $out[$id] = $card;
            }
        }
        // Managers often lack CAN_BE_ASSIGNED on their own status — still allow self.
        $selfId = Auth::userId();
        if ($selfId > 0 && self::canAssignOperator($selfId) && !isset($out[$selfId])) {
            $self = self::staffCard($selfId);
            if ($self !== null) {
                $out[$selfId] = $self;
            }
        }
        return $out;
    }

    /**
     * Single staff card for nazop columns (enabled users only).
     *
     * @return array{id:int,label:string,acronym:string,bg:string,fg:string,group:int}|null
     */
    public static function staffCard(int $userId): ?array
    {
        if ($userId < 1 || !Database::tableExists('base_users')) {
            return null;
        }
        $hasAsany = Database::tableExists('base_admin_statuses')
            && Database::columnExists('base_admin_statuses', 'CAN_BE_ASSIGNED_ASANY');
        $asanyCol = $hasAsany ? 'COALESCE(st.CAN_BE_ASSIGNED_ASANY, 0)' : '0';
        $joins = $hasAsany
            ? ' LEFT JOIN base_admin_statuses st ON st.ID = u.ID_STATUS'
            : '';
        $st = Database::pdo()->prepare(
            "SELECT u.id, u.login, u.name, u.ID_STATUS, u.ASSIST_TEAM, u.IS_HIDDEN,
                    u.color, u.color_text, $asanyCol AS can_be_assigned_asany
             FROM base_users u
             $joins
             WHERE u.id = ? AND u.enabled = '1'
             LIMIT 1"
        );
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::staffCardFromRow($row) : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,label:string,acronym:string,bg:string,fg:string,group:int}|null
     */
    private static function staffCardFromRow(array $row): ?array
    {
        $id = (int)($row['id'] ?? 0);
        if ($id < 1) {
            return null;
        }
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            $name = (string)($row['login'] ?? ('#' . $id));
        }
        $bg = trim((string)($row['color'] ?? ''));
        $fg = trim((string)($row['color_text'] ?? ''));
        return [
            'id' => $id,
            'label' => $name,
            'acronym' => class_exists('CalendarTimeline', false)
                ? CalendarTimeline::acronym($name)
                : mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8'),
            'bg' => $bg !== '' ? $bg : 'var(--bg-input)',
            'fg' => $fg !== '' ? $fg : 'var(--text)',
            'group' => self::shiftGroupRank($row),
        ];
    }

    /** @param array<string,mixed> $row */
    public static function shiftGroupRank(array $row): int
    {
        if ((int)($row['is_hidden'] ?? $row['IS_HIDDEN'] ?? 0) > 0) {
            return 4;
        }
        if ((int)($row['assist_team'] ?? $row['ASSIST_TEAM'] ?? 0) > 0) {
            return 3;
        }
        if ((int)($row['can_be_assigned_asany'] ?? 0) > 0
            || (int)($row['ID_STATUS'] ?? 0) === 2
        ) {
            return 2;
        }
        if ((int)($row['ID_STATUS'] ?? 0) === 18) {
            return 1;
        }
        return 0;
    }

    /** @param array<string,mixed> $row */
    private static function normalizeShiftRow(array $row): array
    {
        $name = (string)($row['operator_name'] ?? '');
        $group = self::shiftGroupRank([
            'is_hidden' => $row['is_hidden'] ?? 0,
            'assist_team' => $row['assist_team'] ?? 0,
            'can_be_assigned_asany' => $row['can_be_assigned_asany'] ?? 0,
            'ID_STATUS' => $row['ID_STATUS'] ?? 0,
        ]);
        return [
            'id' => (int)$row['id'],
            'id_operator' => (int)$row['id_operator'],
            'id_operator_create' => (int)$row['id_operator_create'],
            'dt_create' => (int)$row['dt_create'],
            'dt' => (int)$row['dt'],
            'price_col' => (int)$row['price_col'],
            'id_table' => (int)$row['id_table'],
            'mas_max' => (int)$row['mas_max'],
            'operator_name' => $name,
            'ID_STATUS' => (int)($row['ID_STATUS'] ?? 0),
            'assist_team' => (int)($row['assist_team'] ?? 0),
            'is_hidden' => (int)($row['is_hidden'] ?? 0),
            'can_be_assigned_asany' => (int)($row['can_be_assigned_asany'] ?? 0),
            'color' => (string)($row['color'] ?? ''),
            'color_text' => (string)($row['color_text'] ?? ''),
            'group' => $group,
        ];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_workweeks')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_OPERATOR AS id_operator, ID_OPERATOR_CREATE AS id_operator_create,
                    DT_CREATE AS dt_create, DT AS dt, PRICE_COL AS price_col,
                    ID_TABLE AS id_table, MAS_MAX AS mas_max
             FROM base_workweeks WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function findByDayOperator(int $dayStart, int $operatorId): ?array
    {
        if ($dayStart <= 0 || $operatorId < 1 || !Database::tableExists('base_workweeks')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_OPERATOR AS id_operator, PRICE_COL AS price_col,
                    ID_TABLE AS id_table, MAS_MAX AS mas_max
             FROM base_workweeks WHERE DT = ? AND ID_OPERATOR = ? LIMIT 1'
        );
        $st->execute([$dayStart, $operatorId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function assign(int $dayStart, int $operatorId, int $priceCol = 1, int $masMax = 1, int $idTable = 0): int
    {
        if ($dayStart <= 0 || $operatorId < 1) {
            throw new InvalidArgumentException(t('workweeks.err.invalid'));
        }
        $existing = self::findByDayOperator($dayStart, $operatorId);
        if ($existing) {
            self::update((int)$existing['id'], $priceCol, $masMax, $idTable);
            return (int)$existing['id'];
        }
        $priceCol = max(1, min(5, $priceCol));
        $masMax = max(1, $masMax);
        $idTable = self::normalizeTableId($idTable);
        $st = Database::pdo()->prepare(
            'INSERT INTO base_workweeks
             (ID_OPERATOR, ID_OPERATOR_CREATE, DT_CREATE, DT, PRICE_COL, ID_TABLE, MAS_MAX)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $operatorId,
            Auth::userId(),
            time(),
            $dayStart,
            $priceCol,
            $idTable,
            $masMax,
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    public static function update(int $id, int $priceCol, int $masMax, int $idTable = 0): void
    {
        if ($id < 1) {
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_workweeks SET PRICE_COL = ?, MAS_MAX = ?, ID_TABLE = ? WHERE ID = ?'
        );
        $st->execute([
            max(1, min(5, $priceCol)),
            max(1, $masMax),
            self::normalizeTableId($idTable),
            $id,
        ]);
    }

    public static function delete(int $id): void
    {
        if ($id < 1 || !Database::tableExists('base_workweeks')) {
            return;
        }
        Database::pdo()->prepare('DELETE FROM base_workweeks WHERE ID = ?')->execute([$id]);
    }

    public static function canAssign(): bool
    {
        if (!Auth::can('workweeks.edit')) {
            return false;
        }
        if (Auth::capability('EVT_ASSIGN')) {
            return true;
        }
        return !empty(Auth::statusFlags()['EVT_ASSIGN_MY']);
    }

    public static function canAssignOperator(int $operatorId): bool
    {
        if (!self::canAssign()) {
            return false;
        }
        if (Auth::capability('EVT_ASSIGN')) {
            return $operatorId > 0;
        }
        return $operatorId === Auth::userId();
    }

    /** Shared day URL helper for embeds and standalone UI. */
    public static function dayUrl(string $date, string $base = '/workweeks/day'): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        return rtrim($base, '/') . '/' . $date;
    }

    /**
     * Handle assign/update/delete POST for a day. Returns flash message or throws.
     *
     * @return string flash message key resolved text
     */
    public static function handleDayPost(int $dayStart, array $post): string
    {
        $action = (string)($post['shift_action'] ?? '');
        if ($action === 'add') {
            $op = (int)($post['id_operator'] ?? 0);
            if (!self::canAssignOperator($op)) {
                throw new RuntimeException(t('workweeks.err.no_assign'));
            }
            $pc = (int)($post['price_col'] ?? 1);
            $mm = (int)($post['mas_max'] ?? 1);
            $tid = (int)($post['id_table'] ?? 0);
            $before = self::findByDayOperator($dayStart, $op);
            self::assign($dayStart, $op, $pc, $mm, $tid);
            if (!$before) {
                self::logChangeHtml(self::buildLogLine('add', [
                    'id_operator' => $op,
                    'dt' => $dayStart,
                    'price_col' => max(1, min(5, $pc)),
                ]));
            } elseif ((int)$before['price_col'] !== max(1, min(5, $pc))) {
                self::logChangeHtml(self::buildLogLine('price', [
                    'id_operator' => $op,
                    'dt' => $dayStart,
                    'from' => (int)$before['price_col'],
                    'to' => max(1, min(5, $pc)),
                ]));
            }
            return t('workweeks.msg.saved');
        }
        if ($action === 'update') {
            $sid = (int)($post['shift_id'] ?? 0);
            $row = self::find($sid);
            if (!$row || (int)$row['dt'] !== $dayStart) {
                throw new RuntimeException(t('workweeks.err.not_found'));
            }
            if (!self::canAssignOperator((int)$row['id_operator'])) {
                throw new RuntimeException(t('workweeks.err.no_assign'));
            }
            $pc = max(1, min(5, (int)($post['price_col'] ?? 1)));
            $mm = max(1, (int)($post['mas_max'] ?? 1));
            $tid = self::normalizeTableId((int)($post['id_table'] ?? 0));
            $bits = [];
            if ((int)$row['price_col'] !== $pc) {
                $bits[] = self::buildLogLine('price', [
                    'id_operator' => (int)$row['id_operator'],
                    'dt' => $dayStart,
                    'from' => (int)$row['price_col'],
                    'to' => $pc,
                ]);
            } elseif ((int)$row['mas_max'] !== $mm || (int)$row['id_table'] !== $tid) {
                $bits[] = self::buildLogLine('update', [
                    'id_operator' => (int)$row['id_operator'],
                    'dt' => $dayStart,
                    'price_col' => $pc,
                    'mas_max' => $mm,
                    'id_table' => $tid,
                ]);
            }
            self::update($sid, $pc, $mm, $tid);
            if ($bits !== []) {
                self::logChangeHtml(implode('<br>', $bits));
            }
            return t('workweeks.msg.saved');
        }
        if ($action === 'delete') {
            $sid = (int)($post['shift_id'] ?? 0);
            $row = self::find($sid);
            if (!$row || (int)$row['dt'] !== $dayStart) {
                throw new RuntimeException(t('workweeks.err.not_found'));
            }
            if (!self::canAssignOperator((int)$row['id_operator'])) {
                throw new RuntimeException(t('workweeks.err.no_assign'));
            }
            self::delete($sid);
            self::logChangeHtml(self::buildLogLine('remove', [
                'id_operator' => (int)$row['id_operator'],
                'dt' => $dayStart,
            ]));
            return t('workweeks.msg.deleted');
        }
        throw new RuntimeException(t('workweeks.err.invalid'));
    }

    /**
     * Oper-style matrix: days × operators with current assignments.
     *
     * @param list<int> $operatorIds
     * @return array{
     *   days:list<array{ts:int,date:string,label:string,weekday:string,is_weekend:bool}>,
     *   operators:array<int,array{id:int,label:string,acronym:string,bg:string,fg:string}>,
     *   assigned:array<string,array<int,array{price_col:int,mas_max:int}>>,
     *   day_price:array<string,int>,
     *   day_mas_max:array<string,int>
     * }
     */
    public static function nazopMatrix(int $startTs, int $dayCount, array $operatorIds): array
    {
        $dayCount = max(1, min(120, $dayCount));
        $startTs = (int)strtotime(date('Y-m-d 00:00:00', $startTs));
        $ops = [];
        $assignable = self::listAssignableStaff();
        foreach ($operatorIds as $oid) {
            $oid = (int)$oid;
            if ($oid < 1 || !self::canAssignOperator($oid)) {
                continue;
            }
            if (isset($assignable[$oid])) {
                $ops[$oid] = $assignable[$oid];
                continue;
            }
            // Explicit column request (e.g. +self) even if not in CAN_BE_ASSIGNED pool.
            $card = self::staffCard($oid);
            if ($card !== null) {
                $ops[$oid] = $card;
            }
        }
        // Do not auto-expand to all staff — caller picks columns (oper userManage).

        $days = [];
        $endTs = $startTs + ($dayCount * 86400);
        $assigned = [];
        $dayPrice = [];
        $dayMasMax = [];

        // Load assignments for the day range (all ops), then filter display columns.
        if (Database::tableExists('base_workweeks')) {
            $st = Database::pdo()->prepare(
                'SELECT ID_OPERATOR AS id_operator, DT AS dt, PRICE_COL AS price_col, MAS_MAX AS mas_max
                 FROM base_workweeks
                 WHERE DT >= ? AND DT < ?'
            );
            $st->execute([$startTs, $endTs]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $d = date('Y-m-d', (int)$row['dt']);
                $oid = (int)$row['id_operator'];
                $pc = max(1, (int)$row['price_col']);
                $mm = max(1, (int)$row['mas_max']);
                $assigned[$d][$oid] = ['price_col' => $pc, 'mas_max' => $mm];
            }
        }

        // Day-level price/mas only across visible operator columns.
        foreach ($assigned as $d => $byOp) {
            $visible = [];
            foreach ($byOp as $oid => $meta) {
                if (isset($ops[$oid])) {
                    $visible[$oid] = $meta;
                }
            }
            if ($visible === []) {
                continue;
            }
            $pc0 = null;
            $mixed = false;
            $mm0 = 1;
            foreach ($visible as $meta) {
                $pc = (int)$meta['price_col'];
                $mm0 = max($mm0, (int)$meta['mas_max']);
                if ($pc0 === null) {
                    $pc0 = $pc;
                } elseif ($pc0 !== $pc) {
                    $mixed = true;
                }
            }
            $dayPrice[$d] = $mixed ? 0 : (int)$pc0;
            $dayMasMax[$d] = $mm0;
        }

        for ($i = 0; $i < $dayCount; $i++) {
            $ts = $startTs + ($i * 86400);
            $n = (int)date('N', $ts);
            $days[] = [
                'ts' => $ts,
                'date' => date('Y-m-d', $ts),
                'label' => date('d.m.Y', $ts),
                'weekday' => class_exists('CalendarWeek', false)
                    ? CalendarWeek::weekdayShort($n)
                    : date('D', $ts),
                'is_weekend' => $n >= 6,
            ];
        }

        return [
            'days' => $days,
            'operators' => $ops,
            'assigned' => $assigned,
            'day_price' => $dayPrice,
            'day_mas_max' => $dayMasMax,
        ];
    }

    /**
     * Save nazop matrix. $checked[date][opId]=1, $prices[date]=n, $masMax[date]=n.
     *
     * @param array<string,array<int,int|true>> $checked
     * @param array<string,int> $prices
     * @param array<string,int> $masMax
     * @param list<int> $managedOpIds operators columns in the form
     */
    public static function saveNazopMatrix(array $checked, array $prices, array $masMax, array $managedOpIds): string
    {
        if (!self::canAssign()) {
            throw new RuntimeException(t('workweeks.err.no_assign'));
        }
        $managed = [];
        foreach ($managedOpIds as $oid) {
            $oid = (int)$oid;
            if ($oid > 0 && self::canAssignOperator($oid)) {
                $managed[$oid] = true;
            }
        }
        if ($managed === []) {
            throw new RuntimeException(t('workweeks.err.no_assign'));
        }

        $dates = [];
        foreach (array_keys($checked) as $d) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d)) {
                $dates[(string)$d] = true;
            }
        }
        foreach (array_keys($prices) as $d) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d)) {
                $dates[(string)$d] = true;
            }
        }
        foreach (array_keys($masMax) as $d) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d)) {
                $dates[(string)$d] = true;
            }
        }

        $logBits = [];
        foreach (array_keys($dates) as $date) {
            $ts = strtotime($date . ' 00:00:00');
            if ($ts === false) {
                continue;
            }
            $dayStart = (int)$ts;
            $pc = max(1, min(5, (int)($prices[$date] ?? 1)));
            $mm = max(1, min(9, (int)($masMax[$date] ?? 1)));
            $want = [];
            foreach (($checked[$date] ?? []) as $oid => $on) {
                $oid = (int)$oid;
                if ($oid > 0 && !empty($on) && isset($managed[$oid])) {
                    $want[$oid] = true;
                }
            }
            foreach (array_keys($managed) as $oid) {
                $existing = self::findByDayOperator($dayStart, $oid);
                if (isset($want[$oid])) {
                    if ($existing) {
                        $oldPc = (int)$existing['price_col'];
                        $oldMm = (int)$existing['mas_max'];
                        if ($oldPc !== $pc) {
                            $logBits[] = self::buildLogLine('price', [
                                'id_operator' => $oid,
                                'dt' => $dayStart,
                                'from' => $oldPc,
                                'to' => $pc,
                            ]);
                        }
                        self::update((int)$existing['id'], $pc, $mm, (int)($existing['id_table'] ?? 1));
                        // Re-apply MAS_MAX even when price unchanged (oper updates all rows for the day).
                        if ($oldPc === $pc && $oldMm !== $mm) {
                            $logBits[] = self::buildLogLine('update', [
                                'id_operator' => $oid,
                                'dt' => $dayStart,
                                'price_col' => $pc,
                                'mas_max' => $mm,
                                'id_table' => (int)($existing['id_table'] ?? 1),
                            ]);
                        }
                    } else {
                        self::assign($dayStart, $oid, $pc, $mm, 1);
                        $logBits[] = self::buildLogLine('add', [
                            'id_operator' => $oid,
                            'dt' => $dayStart,
                            'price_col' => $pc,
                        ]);
                    }
                } elseif ($existing) {
                    self::delete((int)$existing['id']);
                    $logBits[] = self::buildLogLine('remove', [
                        'id_operator' => $oid,
                        'dt' => $dayStart,
                    ]);
                }
            }
        }
        if ($logBits !== []) {
            self::logChangeHtml(implode('<br>', $logBits));
        }
        return t('workweeks.msg.saved');
    }
}
