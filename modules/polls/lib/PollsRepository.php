<?php
declare(strict_types=1);

final class PollsRepository
{
    public const EL_POLL = 0;
    public const EL_QUESTION = 1;
    public const EL_ANSWER = 2;

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'name' => 'NAME',
            'num' => 'NUM',
            'enabled' => 'ENABLED',
        ];
    }

    public static function elTypeLabel(int $elType): string
    {
        if ($elType === self::EL_QUESTION) {
            return t('polls.el_type.question');
        }
        if ($elType === self::EL_ANSWER) {
            return t('polls.el_type.answer');
        }
        return t('polls.el_type.poll');
    }

    public static function whoCanPassLabel(int $who): string
    {
        if ($who === 1) {
            return t('polls.who.1');
        }
        if ($who === 2) {
            return t('polls.who.2');
        }
        return t('polls.who.0');
    }

    /**
     * @param array{q?:string,enabled?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function listRoots(array $opts = []): array
    {
        if (!Database::tableExists('base_polls')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['PID = 0'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(NAME LIKE ? OR CAST(ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }
        $enabled = (string)($opts['enabled'] ?? '');
        if ($enabled === '1' || $enabled === '0') {
            $where[] = 'ENABLED = ?';
            $params[] = (int)$enabled;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_polls WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'NUM ASC, ID DESC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = 'SELECT ID AS id, PID AS pid, EL_TYPE AS el_type, NAME AS name, ENABLED AS enabled,
                       NUM AS num, WHO_CAN_PASS AS who_can_pass, AFTER_EVENT AS after_event,
                       SHOW_IN_TRAY AS show_in_tray, TRAY_CODE AS tray_code,
                       BONUS_DEFAULT AS bonus_default, BONUS_AUTO AS bonus_auto
                FROM base_polls WHERE ' . $sqlWhere . ' ORDER BY ' . $order;
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /** @return array<int,string> id => name for root polls */
    public static function rootNames(bool $enabledOnly = true): array
    {
        if (!Database::tableExists('base_polls')) {
            return [];
        }
        $sql = 'SELECT ID, NAME FROM base_polls WHERE PID = 0';
        if ($enabledOnly) {
            $sql .= ' AND ENABLED = 1';
        }
        $sql .= ' ORDER BY NUM ASC, NAME ASC';
        $out = [];
        foreach (Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['ID']] = (string)$r['NAME'];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_polls')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, PID AS pid, EL_TYPE AS el_type, NAME AS name, DT AS dt,
                    DESCR_ADMIN AS descr_admin, DESCR_SITE AS descr_site,
                    RESP_GROUPS AS resp_groups, BONUS_DEFAULT AS bonus_default,
                    BONUS_AUTO AS bonus_auto, ENABLED AS enabled, REQ_TEXT_LEN AS req_text_len,
                    NUM AS num, QTY AS qty, STORE_TO_USER AS store_to_user,
                    MULTY_OPTS AS multy_opts, TYPES AS types, PROCS AS procs,
                    STATS_USERS_AMOUNT AS stats_users_amount, NEXT_QID AS next_qid,
                    WHO_CAN_PASS AS who_can_pass, SHOW2USER AS show2user,
                    ADD2DTNEXT AS add2dtnext, STATUS_ON_SEL AS status_on_sel,
                    SHOW_IN_TRAY AS show_in_tray, TRAY_CODE AS tray_code,
                    STATUSES AS statuses, NEGATIVE AS negative, MULTY_ANSWER AS multy_answer,
                    ANS_END_POLL AS ans_end_poll, AFTER_EVENT AS after_event,
                    AFTER_EVENT_STOP_REW AS after_event_stop_rew,
                    AFTER_EVENT_TIME AS after_event_time,
                    AFTER_EVENT_EXPIRE_TIME AS after_event_expire_time,
                    SHOW_HISTORY AS show_history, NOT_REPASS AS not_repass,
                    IS_SPECIAL AS is_special, IS_SPECIAL_FRIEND AS is_special_friend,
                    IS_RESULT AS is_result, NOTICE_SMS AS notice_sms,
                    NOTICE_EMAIL AS notice_email, NOTICE_SMS_TPL_ID AS notice_sms_tpl_id,
                    NOTICE_EMAIL_TPL_ID AS notice_email_tpl_id
             FROM base_polls WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public static function children(int $pid): array
    {
        if ($pid < 0 || !Database::tableExists('base_polls')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, PID AS pid, EL_TYPE AS el_type, NAME AS name, ENABLED AS enabled, NUM AS num
             FROM base_polls WHERE PID = ? ORDER BY NUM ASC, ID ASC'
        );
        $st->execute([$pid]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function countChildren(int $pid): int
    {
        if ($pid < 1 || !Database::tableExists('base_polls')) {
            return 0;
        }
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM base_polls WHERE PID = ?');
        $st->execute([$pid]);
        return (int)$st->fetchColumn();
    }

    /**
     * Build question map for a root poll: questionId => row + _ANSWERS_.
     *
     * @return array{poll:array<string,mixed>|null,questions:array<int,array<string,mixed>>,first_qid:int}
     */
    public static function buildWalkTree(int $pollId): array
    {
        $poll = self::find($pollId);
        if (!$poll || (int)$poll['el_type'] !== self::EL_POLL || (int)$poll['pid'] !== 0) {
            return ['poll' => null, 'questions' => [], 'first_qid' => 0];
        }
        $questions = [];
        $firstQid = 0;
        foreach (self::children($pollId) as $q) {
            if ((int)$q['el_type'] !== self::EL_QUESTION) {
                continue;
            }
            $qid = (int)$q['id'];
            $full = self::find($qid);
            if (!$full) {
                continue;
            }
            $answers = [];
            foreach (self::children($qid) as $a) {
                if ((int)$a['el_type'] !== self::EL_ANSWER) {
                    continue;
                }
                $af = self::find((int)$a['id']);
                if ($af) {
                    $answers[(int)$af['id']] = $af;
                }
            }
            $full['_ANSWERS_'] = $answers;
            $full['NEXT_QID_DEFAULT'] = 0;
            $questions[$qid] = $full;
            if ($firstQid === 0) {
                $firstQid = $qid;
            }
        }
        // Default next question = next by NUM order among siblings
        $qids = array_keys($questions);
        $n = count($qids);
        for ($i = 0; $i < $n; $i++) {
            $questions[$qids[$i]]['NEXT_QID_DEFAULT'] = $i + 1 < $n ? $qids[$i + 1] : 0;
        }
        return ['poll' => $poll, 'questions' => $questions, 'first_qid' => $firstQid];
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post, ?array $existing = null): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('polls.err.name_required'));
        }
        $isNew = $existing === null;
        $elType = $isNew
            ? (int)($post['el_type'] ?? self::EL_POLL)
            : (int)$existing['el_type'];
        if (!$isNew && isset($post['el_type']) && (int)$post['el_type'] !== $elType) {
            throw new InvalidArgumentException(t('polls.err.cannot_change_type'));
        }
        if (!in_array($elType, [self::EL_POLL, self::EL_QUESTION, self::EL_ANSWER], true)) {
            $elType = self::EL_POLL;
        }
        $pid = $isNew ? (int)($post['pid'] ?? 0) : (int)$existing['pid'];
        if ($elType === self::EL_POLL) {
            $pid = 0;
        } else {
            if ($pid < 1) {
                throw new InvalidArgumentException(t('polls.err.invalid_parent'));
            }
            $parent = self::find($pid);
            if (!$parent) {
                throw new InvalidArgumentException(t('polls.err.invalid_parent'));
            }
            if ($elType === self::EL_QUESTION && (int)$parent['el_type'] !== self::EL_POLL) {
                throw new InvalidArgumentException(t('polls.err.invalid_parent'));
            }
            if ($elType === self::EL_ANSWER && (int)$parent['el_type'] !== self::EL_QUESTION) {
                throw new InvalidArgumentException(t('polls.err.invalid_parent'));
            }
        }

        $flag = static function (string $key) use ($post): int {
            return !empty($post[$key]) ? 1 : 0;
        };

        return [
            'pid' => $pid,
            'el_type' => $elType,
            'name' => mb_substr($name, 0, 255),
            'descr_admin' => (string)($post['descr_admin'] ?? ''),
            'descr_site' => (string)($post['descr_site'] ?? ''),
            'resp_groups' => (string)($post['resp_groups'] ?? ''),
            'bonus_default' => max(0, (int)($post['bonus_default'] ?? 0)),
            'bonus_auto' => $flag('bonus_auto'),
            'enabled' => $flag('enabled'),
            'req_text_len' => max(0, min(255, (int)($post['req_text_len'] ?? 0))),
            'num' => (int)($post['num'] ?? 0),
            'qty' => max(0, min(255, (int)($post['qty'] ?? 0))),
            'store_to_user' => $flag('store_to_user'),
            'multy_opts' => $flag('multy_opts'),
            'types' => (string)($post['types'] ?? ''),
            'procs' => (string)($post['procs'] ?? ''),
            'next_qid' => max(0, (int)($post['next_qid'] ?? 0)),
            'who_can_pass' => max(0, min(2, (int)($post['who_can_pass'] ?? 0))),
            'show2user' => $flag('show2user'),
            'add2dtnext' => max(0, (int)($post['add2dtnext'] ?? 0)),
            'status_on_sel' => max(0, (int)($post['status_on_sel'] ?? 0)),
            'show_in_tray' => $flag('show_in_tray'),
            'tray_code' => mb_substr(trim((string)($post['tray_code'] ?? '')), 0, 10),
            'statuses' => (string)($post['statuses'] ?? ''),
            'negative' => $flag('negative'),
            'multy_answer' => $flag('multy_answer'),
            'ans_end_poll' => $flag('ans_end_poll'),
            'after_event' => $flag('after_event'),
            'after_event_stop_rew' => $flag('after_event_stop_rew'),
            'after_event_time' => max(0, (int)($post['after_event_time'] ?? 0)),
            'after_event_expire_time' => max(0, (int)($post['after_event_expire_time'] ?? 0)),
            'show_history' => $flag('show_history'),
            'not_repass' => $flag('not_repass'),
            'is_special' => $flag('is_special'),
            'is_special_friend' => $flag('is_special_friend'),
            'is_result' => $flag('is_result'),
            'notice_sms' => $flag('notice_sms'),
            'notice_email' => $flag('notice_email'),
            'notice_sms_tpl_id' => max(0, (int)($post['notice_sms_tpl_id'] ?? 0)),
            'notice_email_tpl_id' => max(0, (int)($post['notice_email_tpl_id'] ?? 0)),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'INSERT INTO base_polls (
                PID, EL_TYPE, NAME, DT, DESCR_ADMIN, DESCR_SITE, RESP_GROUPS,
                BONUS_DEFAULT, BONUS_AUTO, ENABLED, REQ_TEXT_LEN, NUM, QTY,
                STORE_TO_USER, MULTY_OPTS, TYPES, PROCS, STATS_USERS_AMOUNT,
                NEXT_QID, WHO_CAN_PASS, SHOW2USER, ADD2DTNEXT, STATUS_ON_SEL,
                SHOW_IN_TRAY, TRAY_CODE, STATUSES, NEGATIVE, MULTY_ANSWER,
                ANS_END_POLL, AFTER_EVENT, AFTER_EVENT_STOP_REW, AFTER_EVENT_TIME,
                AFTER_EVENT_EXPIRE_TIME, SHOW_HISTORY, NOT_REPASS,
                IS_SPECIAL, IS_SPECIAL_FRIEND, IS_RESULT,
                NOTICE_SMS, NOTICE_EMAIL, NOTICE_SMS_TPL_ID, NOTICE_EMAIL_TPL_ID
             ) VALUES (
                ?,?,?,?,?,?,?,
                ?,?,?,?,?,?,
                ?,?,?,?,?,0,
                ?,?,?,?,?,
                ?,?,?,?,?,
                ?,?,?,?,
                ?,?,?,
                ?,?,?,
                ?,?,?,?
             )'
        );
        $st->execute([
            $data['pid'], $data['el_type'], $data['name'], time(),
            $data['descr_admin'], $data['descr_site'], $data['resp_groups'],
            $data['bonus_default'], $data['bonus_auto'], $data['enabled'],
            $data['req_text_len'], $data['num'], $data['qty'],
            $data['store_to_user'], $data['multy_opts'], $data['types'], $data['procs'],
            $data['next_qid'], $data['who_can_pass'], $data['show2user'],
            $data['add2dtnext'], $data['status_on_sel'],
            $data['show_in_tray'], $data['tray_code'], $data['statuses'],
            $data['negative'], $data['multy_answer'],
            $data['ans_end_poll'], $data['after_event'], $data['after_event_stop_rew'],
            $data['after_event_time'], $data['after_event_expire_time'],
            $data['show_history'], $data['not_repass'],
            $data['is_special'], $data['is_special_friend'], $data['is_result'],
            $data['notice_sms'], $data['notice_email'],
            $data['notice_sms_tpl_id'], $data['notice_email_tpl_id'],
        ]);
        $id = (int)$pdo->lastInsertId();
        EventBus::emit('polls.definition.changed', [
            'id' => $id,
            'action' => 'create',
            'el_type' => $data['el_type'],
        ]);
        return $id;
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid poll id');
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_polls SET
                NAME = ?, DESCR_ADMIN = ?, DESCR_SITE = ?, RESP_GROUPS = ?,
                BONUS_DEFAULT = ?, BONUS_AUTO = ?, ENABLED = ?, REQ_TEXT_LEN = ?,
                NUM = ?, QTY = ?, STORE_TO_USER = ?, MULTY_OPTS = ?, TYPES = ?, PROCS = ?,
                NEXT_QID = ?, WHO_CAN_PASS = ?, SHOW2USER = ?, ADD2DTNEXT = ?, STATUS_ON_SEL = ?,
                SHOW_IN_TRAY = ?, TRAY_CODE = ?, STATUSES = ?, NEGATIVE = ?, MULTY_ANSWER = ?,
                ANS_END_POLL = ?, AFTER_EVENT = ?, AFTER_EVENT_STOP_REW = ?, AFTER_EVENT_TIME = ?,
                AFTER_EVENT_EXPIRE_TIME = ?, SHOW_HISTORY = ?, NOT_REPASS = ?,
                IS_SPECIAL = ?, IS_SPECIAL_FRIEND = ?, IS_RESULT = ?,
                NOTICE_SMS = ?, NOTICE_EMAIL = ?, NOTICE_SMS_TPL_ID = ?, NOTICE_EMAIL_TPL_ID = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['name'], $data['descr_admin'], $data['descr_site'], $data['resp_groups'],
            $data['bonus_default'], $data['bonus_auto'], $data['enabled'], $data['req_text_len'],
            $data['num'], $data['qty'], $data['store_to_user'], $data['multy_opts'],
            $data['types'], $data['procs'],
            $data['next_qid'], $data['who_can_pass'], $data['show2user'],
            $data['add2dtnext'], $data['status_on_sel'],
            $data['show_in_tray'], $data['tray_code'], $data['statuses'],
            $data['negative'], $data['multy_answer'],
            $data['ans_end_poll'], $data['after_event'], $data['after_event_stop_rew'],
            $data['after_event_time'], $data['after_event_expire_time'],
            $data['show_history'], $data['not_repass'],
            $data['is_special'], $data['is_special_friend'], $data['is_result'],
            $data['notice_sms'], $data['notice_email'],
            $data['notice_sms_tpl_id'], $data['notice_email_tpl_id'],
            $id,
        ]);
        EventBus::emit('polls.definition.changed', [
            'id' => $id,
            'action' => 'update',
            'el_type' => $data['el_type'],
        ]);
    }

    /**
     * @param list<int> $ids
     * @return int deleted count
     */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === [] || !Database::tableExists('base_polls')) {
            return 0;
        }
        $done = 0;
        foreach ($ids as $id) {
            if (self::countChildren($id) > 0) {
                continue;
            }
            $st = Database::pdo()->prepare('DELETE FROM base_polls WHERE ID = ?');
            $st->execute([$id]);
            if ($st->rowCount() > 0) {
                $done++;
                EventBus::emit('polls.definition.changed', [
                    'id' => $id,
                    'action' => 'delete',
                ]);
            }
        }
        return $done;
    }

    /** @return list<array{id:int,name:string,tray_code:string}> */
    public static function trayPolls(): array
    {
        if (!Database::tableExists('base_polls')) {
            return [];
        }
        $st = Database::pdo()->query(
            "SELECT ID AS id, NAME AS name, TRAY_CODE AS tray_code
             FROM base_polls
             WHERE PID = 0 AND ENABLED = 1 AND SHOW_IN_TRAY = 1 AND TRAY_CODE != ''
             ORDER BY NUM ASC, ID ASC"
        );
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = [
                'id' => (int)$r['id'],
                'name' => (string)$r['name'],
                'tray_code' => (string)$r['tray_code'],
            ];
        }
        return $out;
    }
}
