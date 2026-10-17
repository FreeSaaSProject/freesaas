<?php
declare(strict_types=1);

final class PollInvitesRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'i.ID',
            'created' => 'i.DT_CREATE',
            'expire' => 'i.DT_EXPIRE',
            'completed' => 'i.DT_COMPLETED',
            'poll' => 'i.ID_POLL',
            'client' => 'i.ID_USER',
        ];
    }

    /**
     * @param array{
     *   q?:string,id_poll?:int,progress?:string,id_operator?:int,
     *   order?:string,limit?:int,offset?:int
     * } $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_poll_invites')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];

        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(CAST(i.ID AS CHAR) = ? OR i.CODE LIKE ? OR CAST(i.ID_USER AS CHAR) = ?)';
            $params[] = $q;
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }
        $pollId = (int)($opts['id_poll'] ?? 0);
        if ($pollId > 0) {
            $where[] = 'i.ID_POLL = ?';
            $params[] = $pollId;
        }
        $op = (int)($opts['id_operator'] ?? 0);
        $uidScope = (int)($opts['user_id'] ?? Auth::userId());
        $progress = (string)($opts['progress'] ?? '');
        $now = time();
        if ($progress === 'open') {
            // Shared open predicate (STATUS!=2 + linked scope); optional id_operator narrows further for super.
            $open = self::openWhereSql('i', $uidScope);
            $where[] = '(' . $open['sql'] . ')';
            foreach ($open['params'] as $p) {
                $params[] = $p;
            }
            if ($op > 0 && Auth::capability('SUPERADMIN_ACCESS')) {
                $where[] = 'i.ID_OPERATOR_LINKED = ?';
                $params[] = $op;
            }
        } else {
            if ($op > 0) {
                $where[] = 'i.ID_OPERATOR_LINKED = ?';
                $params[] = $op;
            } elseif (!Auth::capability('SUPERADMIN_ACCESS')) {
                // Non-super: only invites linked to self (unassigned = director only).
                $where[] = 'i.ID_OPERATOR_LINKED = ?';
                $params[] = $uidScope;
            }
            if ($progress === 'done') {
                $where[] = 'i.DT_COMPLETED > 0';
            } elseif ($progress === 'expired') {
                $where[] = 'i.DT_COMPLETED = 0 AND i.STATUS != 2 AND i.DT_EXPIRE > 0 AND i.DT_EXPIRE < ?';
                $params[] = $now;
            }
        }

        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_poll_invites i WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'i.ID DESC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));

        $joinPoll = Database::tableExists('base_polls')
            ? 'LEFT JOIN base_polls p ON p.ID = i.ID_POLL'
            : '';
        $pollName = $joinPoll !== '' ? 'p.NAME AS poll_name' : "'' AS poll_name";

        $sql = "SELECT i.ID AS id, i.ID_POLL AS id_poll, i.ID_USER AS id_user,
                       i.CODE AS code, i.DT_CREATE AS dt_create, i.DT_EXPIRE AS dt_expire,
                       i.DT_COMPLETED AS dt_completed, i.STATUS AS status,
                       i.ID_OPERATOR_LINKED AS id_operator_linked,
                       $pollName
                FROM base_poll_invites i
                $joinPoll
                WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_poll_invites')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DT_CREATE AS dt_create, DT_AVAIL AS dt_avail, DT_EXPIRE AS dt_expire,
                    DT_STARTED AS dt_started, DT_COMPLETED AS dt_completed, DT_RECOMPLETED AS dt_recompleted,
                    ID_OPERATOR_CREATE AS id_operator_create, ID_OPERATOR_LINKED AS id_operator_linked,
                    ID_OPERATOR_UPDATE AS id_operator_update, DT_OPERATOR_UPDATE AS dt_operator_update,
                    ID_USER AS id_user, ID_EVENT AS id_event, ID_PROC AS id_proc, ID_POLL AS id_poll,
                    TEXT_ADMIN_TO_USER AS text_admin_to_user,
                    BONUS_AUTO AS bonus_auto, BONUS_VALUE AS bonus_value, BONUS_GIVEN AS bonus_given,
                    LOG AS log, DT_NEXTCALL AS dt_nextcall, STATUS AS status,
                    DATA_OPTS AS data_opts, DATA_TEXT AS data_text,
                    RESP_USERS AS resp_users, RESP_USERS_VIEWED AS resp_users_viewed,
                    CODE AS code, CODE_FRIEND AS code_friend,
                    IS_SPECIAL AS is_special, IS_SPECIAL_FRIEND AS is_special_friend, IS_RESULT AS is_result,
                    SIGN_REQ AS sign_req, ID_SUPERVISOR_SIGNED AS id_supervisor_signed,
                    DT_SUPERVISOR_SIGNED AS dt_supervisor_signed,
                    ID_ORDER_NEW AS id_order_new, ID_ORDER_FRIEND_NEW AS id_order_friend_new,
                    ID_GBOOK AS id_gbook
             FROM base_poll_invites WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function assertCanOpen(?array $invite): void
    {
        if (!$invite) {
            throw new InvalidArgumentException(t('poll_invites.err.not_found', ['id' => 0]));
        }
        $linked = (int)($invite['id_operator_linked'] ?? 0);
        if ($linked > 0 && $linked !== Auth::userId() && !Auth::capability('SUPERADMIN_ACCESS')) {
            throw new RuntimeException(t('poll_invites.err.linked_locked'));
        }
    }

    public static function generateCode(int $len = 6): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = '';
            for ($i = 0; $i < $len; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }
            $st = Database::pdo()->prepare('SELECT 1 FROM base_poll_invites WHERE CODE = ? LIMIT 1');
            $st->execute([$code]);
            if (!$st->fetchColumn()) {
                return $code;
            }
        }
        return strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post, ?array $existing = null): array
    {
        $pollId = (int)($post['id_poll'] ?? ($existing['id_poll'] ?? 0));
        if ($pollId < 1) {
            throw new InvalidArgumentException(t('poll_invites.err.poll_required'));
        }
        $poll = PollsRepository::find($pollId);
        if (!$poll || (int)$poll['el_type'] !== PollsRepository::EL_POLL || (int)$poll['pid'] !== 0) {
            throw new InvalidArgumentException(t('poll_invites.err.poll_missing', ['id' => $pollId]));
        }

        $parseDt = static function (string $key) use ($post): int {
            $raw = trim((string)($post[$key] ?? ''));
            if ($raw === '') {
                return 0;
            }
            if (ctype_digit($raw)) {
                return (int)$raw;
            }
            $ts = strtotime($raw);
            return $ts !== false ? $ts : 0;
        };

        $flag = static function (string $key) use ($post): int {
            return !empty($post[$key]) ? 1 : 0;
        };

        $now = time();
        $dtAvail = $parseDt('dt_avail');
        if ($dtAvail < 1 && $existing === null) {
            $dtAvail = $now;
        }
        $dtExpire = $parseDt('dt_expire');
        if ($dtExpire < 1 && $existing === null) {
            $dtExpire = $now + 72 * 3600;
        }

        $bonusValue = (int)($post['bonus_value'] ?? 0);
        if ($existing === null && $bonusValue < 1) {
            $bonusValue = (int)$poll['bonus_default'];
        }

        return [
            'id_poll' => $pollId,
            'id_user' => max(0, (int)($post['id_user'] ?? 0)),
            'id_event' => max(0, (int)($post['id_event'] ?? 0)),
            'id_proc' => max(0, (int)($post['id_proc'] ?? 0)),
            'id_operator_linked' => max(0, (int)($post['id_operator_linked'] ?? 0)),
            'text_admin_to_user' => (string)($post['text_admin_to_user'] ?? ''),
            'dt_avail' => $dtAvail,
            'dt_expire' => $dtExpire,
            'dt_nextcall' => $parseDt('dt_nextcall'),
            'status' => max(0, (int)($post['status'] ?? 0)),
            'bonus_auto' => $flag('bonus_auto'),
            'bonus_value' => max(0, $bonusValue),
            'bonus_given' => max(0, (int)($post['bonus_given'] ?? ($existing['bonus_given'] ?? 0))),
            'sign_req' => $flag('sign_req'),
            'is_special' => $flag('is_special'),
            'is_special_friend' => $flag('is_special_friend'),
            'log' => (string)($post['log'] ?? ($existing['log'] ?? '')),
            'poll' => $poll,
        ];
    }

    /**
     * @param array<string,mixed> $data
     *   Optional overrides: code (incl. empty for oral), resp_users, is_result, source
     */
    public static function create(array $data): int
    {
        $pdo = Database::pdo();
        $code = array_key_exists('code', $data) ? (string)$data['code'] : self::generateCode();
        $respUsers = (string)($data['resp_users'] ?? '');
        $isResult = !empty($data['is_result']) ? 1 : 0;
        $source = (string)($data['source'] ?? 'staff');
        if ($source === '') {
            $source = 'staff';
        }
        $uid = Auth::userId();
        $now = time();
        $log = $data['log'];
        if ($log === '') {
            $log = '[' . date('d.m.y H:i') . '][' . $uid . ']: created' . "\n";
        }
        $hasUsersViewed = Database::columnExists('base_poll_invites', 'USERS_VIEWED');
        $cols = 'DT_CREATE, DT_AVAIL, DT_EXPIRE, DT_STARTED, DT_COMPLETED, DT_RECOMPLETED,
                ID_OPERATOR_CREATE, ID_OPERATOR_LINKED, ID_OPERATOR_UPDATE, DT_OPERATOR_UPDATE,
                ID_USER, ID_EVENT, ID_PROC, ID_POLL, TEXT_ADMIN_TO_USER,
                BONUS_AUTO, BONUS_VALUE, BONUS_GIVEN, LOG, DT_NEXTCALL, STATUS,
                DATA_OPTS, DATA_TEXT, RESP_USERS, RESP_USERS_VIEWED,
                CODE, CODE_FRIEND, IS_SPECIAL, IS_SPECIAL_FRIEND, IS_RESULT,
                SIGN_REQ, ID_SUPERVISOR_SIGNED, DT_SUPERVISOR_SIGNED,
                ID_ORDER_NEW, ID_ORDER_FRIEND_NEW, ID_GBOOK';
        $vals = '?,?,?,0,0,0,
                ?,?,0,0,
                ?,?,?,?,?,
                ?,?,?,?,?,?,
                ?,?,?,?,
                ?,?,?,?,?,
                ?,0,0,
                0,0,0';
        $params = [
            $now, $data['dt_avail'], $data['dt_expire'],
            $uid, $data['id_operator_linked'],
            $data['id_user'], $data['id_event'], $data['id_proc'], $data['id_poll'],
            $data['text_admin_to_user'],
            $data['bonus_auto'], $data['bonus_value'], $data['bonus_given'], $log,
            $data['dt_nextcall'], $data['status'],
            '{}', '{}', $respUsers, '',
            $code, '',
            $data['is_special'], $data['is_special_friend'], $isResult,
            $data['sign_req'],
        ];
        if ($hasUsersViewed) {
            $cols .= ', USERS_VIEWED';
            $vals .= ', ?';
            $params[] = '';
        }
        $st = $pdo->prepare("INSERT INTO base_poll_invites ($cols) VALUES ($vals)");
        $st->execute($params);
        $id = (int)$pdo->lastInsertId();
        EventBus::emit('poll_invites.created', [
            'invite_id' => $id,
            'poll_id' => $data['id_poll'],
            'client_id' => $data['id_user'],
            'code' => $code,
            'source' => $source,
            'id_proc' => (int)$data['id_proc'],
        ]);
        return $id;
    }

    /**
     * Any invite linked to procedure (oper dedupe: ID_PROC, not only [ORAL_ASKED]).
     *
     * @return array{id:int,id_poll:int,code:string,log:string}|null
     */
    public static function findMarkByProc(int $procId): ?array
    {
        if ($procId < 1 || !Database::tableExists('base_poll_invites')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_POLL AS id_poll, CODE AS code, LOG AS log
             FROM base_poll_invites WHERE ID_PROC = ? ORDER BY ID DESC LIMIT 1'
        );
        $st->execute([$procId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param list<int> $procIds
     * @return array<int,int> procId => inviteId
     */
    public static function idsMarkedByProc(array $procIds): array
    {
        $ids = [];
        foreach ($procIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === [] || !Database::tableExists('base_poll_invites')) {
            return [];
        }
        $list = array_values($ids);
        $ph = implode(',', array_fill(0, count($list), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID_PROC AS id_proc, MAX(ID) AS id
             FROM base_poll_invites
             WHERE ID_PROC IN ($ph)
             GROUP BY ID_PROC"
        );
        $st->execute($list);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int)$row['id_proc']] = (int)$row['id'];
        }
        return $out;
    }

    /**
     * Oral marks for clients within a procedure day range (one invite per client per calendar day).
     * Key: "{userId}|{Y-m-d}" from procedure DTSTART.
     *
     * @param list<int> $userIds
     * @return array<string,int> clientDayKey => inviteId
     */
    public static function idsMarkedByClientDay(array $userIds, int $rangeStart, int $rangeEnd): array
    {
        $uids = [];
        foreach ($userIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $uids[$id] = $id;
            }
        }
        if ($uids === []
            || $rangeEnd <= $rangeStart
            || !Database::tableExists('base_poll_invites')
            || !Database::tableExists('base_procedures')
        ) {
            return [];
        }
        $list = array_values($uids);
        $ph = implode(',', array_fill(0, count($list), '?'));
        $params = $list;
        $params[] = $rangeStart;
        $params[] = $rangeEnd;
        $st = Database::pdo()->prepare(
            "SELECT i.ID AS id, i.ID_USER AS id_user, p.DTSTART AS dtstart
             FROM base_poll_invites i
             INNER JOIN base_procedures p ON p.ID = i.ID_PROC
             WHERE i.ID_PROC > 0
               AND i.ID_USER IN ($ph)
               AND p.DTSTART >= ? AND p.DTSTART < ?
             ORDER BY i.ID ASC"
        );
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $uid = (int)$row['id_user'];
            $day = date('Y-m-d', (int)$row['dtstart']);
            $key = $uid . '|' . $day;
            // Keep first (oldest) invite for the client-day.
            if (!isset($out[$key])) {
                $out[$key] = (int)$row['id'];
            }
        }
        return $out;
    }

    /**
     * Invite already marking this client on the calendar day of $dayStart (unix).
     *
     * @return array{id:int,id_poll:int,id_proc:int}|null
     */
    public static function findMarkByClientDay(int $userId, int $dayStart): ?array
    {
        if ($userId < 1 || $dayStart < 1
            || !Database::tableExists('base_poll_invites')
            || !Database::tableExists('base_procedures')
        ) {
            return null;
        }
        $day = (int)strtotime(date('Y-m-d', $dayStart) . ' 00:00:00');
        if ($day < 1) {
            return null;
        }
        $dayEnd = $day + 86400;
        $st = Database::pdo()->prepare(
            'SELECT i.ID AS id, i.ID_POLL AS id_poll, i.ID_PROC AS id_proc
             FROM base_poll_invites i
             INNER JOIN base_procedures p ON p.ID = i.ID_PROC
             WHERE i.ID_USER = ? AND i.ID_PROC > 0
               AND p.DTSTART >= ? AND p.DTSTART < ?
             ORDER BY i.ID ASC
             LIMIT 1'
        );
        $st->execute([$userId, $day, $dayEnd]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Config oral_poll_id → first enabled IS_RESULT root poll → 0. */
    public static function resolveOralPollId(?int $pollId = null): int
    {
        if ($pollId !== null && $pollId > 0) {
            return $pollId;
        }
        $cfg = class_exists('PollInvitesConfig', false)
            ? PollInvitesConfig::oralPollId()
            : max(0, (int)Config::get('poll_invites', 'oral_poll_id', 0));
        if ($cfg > 0) {
            return $cfg;
        }
        if (!Database::tableExists('base_polls')
            || !Database::columnExists('base_polls', 'IS_RESULT')
        ) {
            return 0;
        }
        $st = Database::pdo()->query(
            'SELECT ID FROM base_polls
             WHERE PID = 0 AND ENABLED = 1 AND IS_RESULT = 1
             ORDER BY NUM ASC, ID ASC LIMIT 1'
        );
        $id = $st ? (int)$st->fetchColumn() : 0;
        return $id > 0 ? $id : 0;
    }

    /**
     * Oper poll_oral_asked: create invite with empty CODE + [ORAL_ASKED] log.
     *
     * @return array{ok:bool,id_invite:int,id_proc:int,id_poll:int,message:string,error:string,already:bool}
     */
    public static function markOralAsked(int $procId, ?int $pollId = null): array
    {
        $empty = [
            'ok' => false,
            'id_invite' => 0,
            'id_proc' => $procId,
            'id_poll' => 0,
            'message' => '',
            'error' => '',
            'already' => false,
        ];
        if ($procId < 1) {
            $empty['error'] = t('poll_invites.oral.err.proc_required');
            return $empty;
        }
        if (!Database::tableExists('base_poll_invites')) {
            $empty['error'] = t('poll_invites.oral.err.table');
            return $empty;
        }
        if (!class_exists('ProceduresRepository', false) || !Database::tableExists('base_procedures')) {
            $empty['error'] = t('poll_invites.oral.err.proc_module');
            return $empty;
        }

        $existing = self::findMarkByProc($procId);
        if ($existing !== null) {
            return [
                'ok' => true,
                'id_invite' => (int)$existing['id'],
                'id_proc' => $procId,
                'id_poll' => (int)$existing['id_poll'],
                'message' => t('poll_invites.oral.already', ['id' => (int)$existing['id']]),
                'error' => '',
                'already' => true,
            ];
        }

        $proc = ProceduresRepository::find($procId);
        if ($proc === null) {
            $empty['error'] = t('poll_invites.oral.err.proc_not_found', ['id' => $procId]);
            return $empty;
        }
        if ((float)($proc['summ'] ?? 0) <= 0) {
            $empty['error'] = t('poll_invites.oral.err.not_paid');
            return $empty;
        }
        $userId = (int)($proc['id_user'] ?? 0);
        if ($userId < 1) {
            $empty['error'] = t('poll_invites.oral.err.no_client');
            return $empty;
        }
        // One oral mark per client per calendar day of the procedure.
        $dayExisting = self::findMarkByClientDay($userId, (int)($proc['dtstart'] ?? 0));
        if ($dayExisting !== null) {
            return [
                'ok' => true,
                'id_invite' => (int)$dayExisting['id'],
                'id_proc' => $procId,
                'id_poll' => (int)$dayExisting['id_poll'],
                'message' => t('poll_invites.oral.already_client_day', ['id' => (int)$dayExisting['id']]),
                'error' => '',
                'already' => true,
            ];
        }
        if (Database::tableExists('base_clients')
            && Database::columnExists('base_clients', 'ANONYMOUS')
        ) {
            $stAnon = Database::pdo()->prepare(
                'SELECT ANONYMOUS FROM base_clients WHERE id = ? LIMIT 1'
            );
            $stAnon->execute([$userId]);
            if ((int)$stAnon->fetchColumn() > 0) {
                $empty['error'] = t('poll_invites.oral.err.anonymous');
                return $empty;
            }
        }

        $resolvedPollId = self::resolveOralPollId($pollId);
        if ($resolvedPollId < 1) {
            $empty['error'] = t('poll_invites.oral.err.no_poll');
            return $empty;
        }
        $poll = class_exists('PollsRepository', false)
            ? PollsRepository::find($resolvedPollId)
            : null;
        if ($poll === null || (int)($poll['pid'] ?? 0) !== 0) {
            $empty['error'] = t('poll_invites.err.poll_missing', ['id' => $resolvedPollId]);
            return $empty;
        }

        $dtStart = (int)($proc['dtstart'] ?? 0);
        $dtEnd = (int)($proc['dtend'] ?? 0);
        if ($dtEnd < 1) {
            $dtEnd = time();
        }
        if ($dtStart < 1) {
            $dtStart = $dtEnd;
        }
        $idEventParent = (int)($proc['id_event_parent'] ?? 0);
        $idEvent = $idEventParent > 0 ? $idEventParent : $procId;

        $respParts = [];
        $opId = (int)($proc['id_operator'] ?? 0);
        $admId = (int)($proc['id_admin'] ?? 0);
        if ($opId > 0) {
            $respParts[] = '#' . $opId . '#';
        }
        if ($admId > 0 && $admId !== $opId) {
            $respParts[] = '#' . $admId . '#';
        }
        $respUsers = implode(',', $respParts);

        $uid = Auth::userId();
        $pollName = (string)($poll['name'] ?? '');
        $log = '[' . date('d.m.y H:i') . '][' . $uid . ']: [ORAL_ASKED] '
            . t('poll_invites.oral.log', ['proc' => $procId, 'poll' => $pollName]) . "\n";

        $availTs = (int)mktime(0, 0, 1, (int)date('n', $dtStart), (int)date('j', $dtStart), (int)date('Y', $dtStart));
        $expireTs = (int)mktime(23, 59, 59, (int)date('n', $dtEnd), (int)date('j', $dtEnd), (int)date('Y', $dtEnd));

        $inviteId = self::create([
            'dt_avail' => $availTs,
            'dt_expire' => $expireTs,
            'id_operator_linked' => $uid > 0 ? $uid : $opId,
            'id_user' => $userId,
            'id_event' => $idEvent,
            'id_proc' => $procId,
            'id_poll' => $resolvedPollId,
            'text_admin_to_user' => '',
            'bonus_auto' => !empty($poll['bonus_auto']) ? 1 : 0,
            'bonus_value' => max(0, (int)($poll['bonus_default'] ?? 0)),
            'bonus_given' => 0,
            'log' => $log,
            'dt_nextcall' => 0,
            'status' => 0,
            'is_special' => !empty($poll['is_special']) ? 1 : 0,
            'is_special_friend' => !empty($poll['is_special_friend']) ? 1 : 0,
            'sign_req' => 0,
            'code' => '',
            'resp_users' => $respUsers,
            'is_result' => !empty($poll['is_result']) ? 1 : 0,
            'source' => 'oral_asked',
        ]);

        return [
            'ok' => true,
            'id_invite' => $inviteId,
            'id_proc' => $procId,
            'id_poll' => $resolvedPollId,
            'message' => t('poll_invites.oral.created', [
                'id' => $inviteId,
                'proc' => $procId,
                'client' => $userId,
            ]),
            'error' => '',
            'already' => false,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid invite id');
        }
        $uid = Auth::userId();
        $st = Database::pdo()->prepare(
            'UPDATE base_poll_invites SET
                DT_AVAIL = ?, DT_EXPIRE = ?, ID_OPERATOR_LINKED = ?,
                ID_USER = ?, ID_EVENT = ?, ID_PROC = ?, ID_POLL = ?,
                TEXT_ADMIN_TO_USER = ?, BONUS_AUTO = ?, BONUS_VALUE = ?, BONUS_GIVEN = ?,
                LOG = ?, DT_NEXTCALL = ?, STATUS = ?,
                IS_SPECIAL = ?, IS_SPECIAL_FRIEND = ?, SIGN_REQ = ?,
                ID_OPERATOR_UPDATE = ?, DT_OPERATOR_UPDATE = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['dt_avail'], $data['dt_expire'], $data['id_operator_linked'],
            $data['id_user'], $data['id_event'], $data['id_proc'], $data['id_poll'],
            $data['text_admin_to_user'], $data['bonus_auto'], $data['bonus_value'], $data['bonus_given'],
            $data['log'], $data['dt_nextcall'], $data['status'],
            $data['is_special'], $data['is_special_friend'], $data['sign_req'],
            $uid, time(), $id,
        ]);
    }

    /**
     * @param array<int|string,mixed> $dataOpts
     * @param array<int|string,string> $dataText
     */
    public static function saveAnswers(
        int $id,
        array $dataOpts,
        array $dataText,
        bool $complete,
        bool $needsSign,
        string $source = 'staff'
    ): void {
        $invite = self::find($id);
        if (!$invite) {
            throw new InvalidArgumentException(t('poll_invites.err.not_found', ['id' => $id]));
        }
        self::assertCanOpen($invite);

        $uid = Auth::userId();
        $now = time();
        $dtStarted = (int)$invite['dt_started'];
        if ($dtStarted < 1) {
            $dtStarted = $now;
        }
        $dtCompleted = (int)$invite['dt_completed'];
        $dtRecompleted = (int)$invite['dt_recompleted'];
        $signReq = (int)$invite['sign_req'];
        if ($needsSign) {
            $signReq = 1;
        }

        if ($complete) {
            if ($dtCompleted > 0) {
                $dtRecompleted = $now;
            } else {
                $dtCompleted = $now;
            }
        }

        $optsEnc = PollAnswersCodec::encode($dataOpts);
        $textEnc = PollAnswersCodec::encode($dataText);
        $log = (string)$invite['log'] . '[' . date('d.m.y H:i') . '][' . $uid . ']: answers saved'
            . ($complete ? ' + complete' : '') . "\n";

        $st = Database::pdo()->prepare(
            'UPDATE base_poll_invites SET
                DATA_OPTS = ?, DATA_TEXT = ?, DT_STARTED = ?, DT_COMPLETED = ?, DT_RECOMPLETED = ?,
                SIGN_REQ = ?, LOG = ?, ID_OPERATOR_UPDATE = ?, DT_OPERATOR_UPDATE = ?
             WHERE ID = ?'
        );
        $st->execute([
            $optsEnc, $textEnc, $dtStarted, $dtCompleted, $dtRecompleted,
            $signReq, $log, $uid, $now, $id,
        ]);

        EventBus::emit('poll_invites.answer.saved', [
            'invite_id' => $id,
            'poll_id' => (int)$invite['id_poll'],
            'client_id' => (int)$invite['id_user'],
            'code' => (string)$invite['code'],
            'data_opts' => $dataOpts,
            'source' => $source,
            'complete' => $complete,
        ]);

        if ($complete && ((int)$invite['dt_completed'] < 1 || $dtRecompleted === $now)) {
            EventBus::emit('poll_invites.completed', [
                'invite_id' => $id,
                'poll_id' => (int)$invite['id_poll'],
                'client_id' => (int)$invite['id_user'],
                'code' => (string)$invite['code'],
                'data_opts' => $dataOpts,
                'bonus_auto' => (int)$invite['bonus_auto'],
                'bonus_value' => (int)$invite['bonus_value'],
                'source' => $source,
            ]);
        }
    }

    public static function signSupervisor(int $id): void
    {
        if (!Auth::capability('SUPERADMIN_ACCESS')) {
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_poll_invites SET ID_SUPERVISOR_SIGNED = ?, DT_SUPERVISOR_SIGNED = ? WHERE ID = ?'
        );
        $st->execute([Auth::userId(), time(), $id]);
    }

    /**
     * @param list<int> $ids
     */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === [] || !Database::tableExists('base_poll_invites')) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_poll_invites WHERE ID IN ($placeholders)");
        $st->execute($ids);
        return $st->rowCount();
    }

    /**
     * Open invites predicate shared by notices + list (progress=open).
     * Non-super: only ID_OPERATOR_LINKED = me (unassigned = SUPERADMIN).
     *
     * @return array{sql:string,params:list<mixed>}
     */
    public static function openWhereSql(string $alias = '', int $uid = 0): array
    {
        $p = $alias !== '' ? $alias . '.' : '';
        $sql = $p . 'DT_COMPLETED = 0 AND ' . $p . 'STATUS != 2';
        $params = [];
        if (!Auth::capability('SUPERADMIN_ACCESS')) {
            $sql .= ' AND ' . $p . 'ID_OPERATOR_LINKED = ?';
            $params[] = $uid > 0 ? $uid : Auth::userId();
        }
        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * Linked-to-me scope for non-super notices that are not "open" (e.g. nextcall).
     *
     * @return array{sql:string,params:list<mixed>}
     */
    public static function linkedWhereSql(string $alias = '', int $uid = 0): array
    {
        $p = $alias !== '' ? $alias . '.' : '';
        if (Auth::capability('SUPERADMIN_ACCESS')) {
            return ['sql' => '1=1', 'params' => []];
        }
        return [
            'sql' => $p . 'ID_OPERATOR_LINKED = ?',
            'params' => [$uid > 0 ? $uid : Auth::userId()],
        ];
    }

    /**
     * @return list<array{short:string,href:string,title:string,tone:string}>
     */
    public static function noticesForUser(int $uid): array
    {
        if ($uid < 1 || !Auth::can('poll_invites.view') || !Database::tableExists('base_poll_invites')) {
            return [];
        }
        $out = [];
        $pdo = Database::pdo();
        $endOfDay = mktime(23, 59, 59) ?: time();

        // Badges always = linked-to-me (oper parity). SUPERADMIN list can still browse all.
        $mineOpen = 'DT_COMPLETED = 0 AND STATUS != 2 AND ID_OPERATOR_LINKED = ?';
        $st = $pdo->prepare('SELECT COUNT(*) FROM base_poll_invites WHERE ' . $mineOpen);
        $st->execute([$uid]);
        $openN = (int)$st->fetchColumn();
        if ($openN > 0) {
            $out[] = [
                'short' => t('poll_invites.notice.open_short', ['n' => $openN]),
                'href' => '/poll-invites?progress=open',
                'title' => t('poll_invites.notice.open_title', ['n' => $openN]),
                'tone' => 'warn',
            ];
        }

        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM base_poll_invites
             WHERE STATUS = 2 AND DT_NEXTCALL > 0 AND DT_NEXTCALL <= ?
               AND ID_OPERATOR_LINKED = ?'
        );
        $st->execute([$endOfDay, $uid]);
        $next = (int)$st->fetchColumn();
        if ($next > 0) {
            $out[] = [
                'short' => t('poll_invites.notice.nextcall_short', ['n' => $next]),
                'href' => '/poll-invites?progress=open',
                'title' => t('poll_invites.notice.nextcall_title', ['n' => $next]),
                'tone' => 'info',
            ];
        }

        if (Auth::capability('SUPERADMIN_ACCESS')) {
            $st = $pdo->query(
                'SELECT COUNT(*) FROM base_poll_invites
                 WHERE DT_COMPLETED > 0 AND SIGN_REQ = 1 AND ID_SUPERVISOR_SIGNED = 0'
            );
            $sign = (int)$st->fetchColumn();
            if ($sign > 0) {
                $out[] = [
                    'short' => t('poll_invites.notice.sign_short', ['n' => $sign]),
                    'href' => '/poll-invites?progress=done',
                    'title' => t('poll_invites.notice.sign_title', ['n' => $sign]),
                    'tone' => 'warn',
                ];
            }
        }

        // Per-tray poll codes — linked-to-me only (same as open chip)
        if (class_exists('PollsRepository', false)) {
            foreach (PollsRepository::trayPolls() as $poll) {
                $st = $pdo->prepare(
                    'SELECT COUNT(*) FROM base_poll_invites
                     WHERE ID_POLL = ? AND ' . $mineOpen
                );
                $st->execute([(int)$poll['id'], $uid]);
                $n = (int)$st->fetchColumn();
                if ($n > 0) {
                    $out[] = [
                        'short' => $poll['tray_code'] . $n,
                        'href' => '/poll-invites?progress=open&id_poll=' . $poll['id'],
                        'title' => $poll['name'] . ': ' . $n,
                        'tone' => 'info',
                    ];
                }
            }
        }

        return $out;
    }

    /** Format unix ts for datetime-local input. */
    public static function dtLocal(int $ts): string
    {
        if ($ts < 1) {
            return '';
        }
        return date('Y-m-d\TH:i', $ts);
    }

    public static function dtDisplay(int $ts): string
    {
        if ($ts < 1) {
            return '—';
        }
        return date('d.m.Y H:i', $ts);
    }
}
