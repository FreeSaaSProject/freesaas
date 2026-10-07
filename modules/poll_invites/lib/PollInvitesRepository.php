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
        if ($op > 0) {
            $where[] = 'i.ID_OPERATOR_LINKED = ?';
            $params[] = $op;
        }
        $progress = (string)($opts['progress'] ?? '');
        $now = time();
        if ($progress === 'open') {
            $where[] = 'i.DT_COMPLETED = 0';
        } elseif ($progress === 'done') {
            $where[] = 'i.DT_COMPLETED > 0';
        } elseif ($progress === 'expired') {
            $where[] = 'i.DT_COMPLETED = 0 AND i.DT_EXPIRE > 0 AND i.DT_EXPIRE < ?';
            $params[] = $now;
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

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $pdo = Database::pdo();
        $code = self::generateCode();
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
                ?,?,?,?,0,
                ?,0,0,
                0,0,0';
        $params = [
            $now, $data['dt_avail'], $data['dt_expire'],
            $uid, $data['id_operator_linked'],
            $data['id_user'], $data['id_event'], $data['id_proc'], $data['id_poll'],
            $data['text_admin_to_user'],
            $data['bonus_auto'], $data['bonus_value'], $data['bonus_given'], $log,
            $data['dt_nextcall'], $data['status'],
            '{}', '{}', '', '',
            $code, '',
            $data['is_special'], $data['is_special_friend'],
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
            'source' => 'staff',
        ]);
        return $id;
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
     * @return list<array{short:string,href:string,title:string,tone:string}>
     */
    public static function noticesForUser(int $uid): array
    {
        if ($uid < 1 || !Auth::can('poll_invites.view') || !Database::tableExists('base_poll_invites')) {
            return [];
        }
        $out = [];
        $pdo = Database::pdo();
        $now = time();
        $endOfDay = mktime(23, 59, 59) ?: $now;

        $linked = '(ID_OPERATOR_LINKED = 0 OR ID_OPERATOR_LINKED = ?)';

        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM base_poll_invites
             WHERE DT_COMPLETED = 0 AND STATUS != 2 AND $linked"
        );
        $st->execute([$uid]);
        $open = (int)$st->fetchColumn();
        if ($open > 0) {
            $out[] = [
                'short' => t('poll_invites.notice.open_short', ['n' => $open]),
                'href' => '/poll-invites?progress=open',
                'title' => t('poll_invites.notice.open_title', ['n' => $open]),
                'tone' => 'warn',
            ];
        }

        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM base_poll_invites
             WHERE STATUS = 2 AND DT_NEXTCALL > 0 AND DT_NEXTCALL <= ? AND $linked"
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

        // Per-tray poll codes (oper tray)
        if (class_exists('PollsRepository', false)) {
            foreach (PollsRepository::trayPolls() as $poll) {
                $st = $pdo->prepare(
                    "SELECT COUNT(*) FROM base_poll_invites
                     WHERE ID_POLL = ? AND DT_COMPLETED = 0 AND STATUS != 2 AND $linked"
                );
                $st->execute([$poll['id'], $uid]);
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
