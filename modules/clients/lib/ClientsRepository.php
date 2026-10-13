<?php
declare(strict_types=1);

final class ClientsRepository
{
    /** Tel/mail in list/view: director-only (not SHOW_ADMINISTRATOR_DATA). */
    public static function canViewPii(): bool
    {
        return Auth::capability('SUPERADMIN_ACCESS');
    }

    /**
     * Non-super: only clients with an event or order touching today (local day).
     * Superadmin: unrestricted.
     */
    public static function canAccessClient(int $id): bool
    {
        if ($id < 1) {
            return false;
        }
        if (Auth::capability('SUPERADMIN_ACCESS')) {
            return true;
        }
        return self::clientIdsActiveOnDay(self::todayBounds(), [$id]) !== [];
    }

    /**
     * @return array{0:int,1:int} [dayStart, dayEnd] unix
     */
    public static function todayBounds(): array
    {
        $start = mktime(0, 0, 0) ?: time();
        $end = mktime(23, 59, 59) ?: ($start + 86399);
        return [$start, $end];
    }

    /**
     * Client ids with event (DTSTART) or order (DT_CREATE) in [from,to].
     *
     * @param array{0:int,1:int} $bounds
     * @param list<int>|null $limitIds optional IN filter
     * @return list<int>
     */
    public static function clientIdsActiveOnDay(array $bounds, ?array $limitIds = null): array
    {
        $from = (int)($bounds[0] ?? 0);
        $to = (int)($bounds[1] ?? 0);
        if ($from < 1 || $to < $from) {
            return [];
        }
        $ids = [];
        $limitSql = '';
        $limitParams = [];
        if ($limitIds !== null) {
            $clean = [];
            foreach ($limitIds as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $clean[$id] = true;
                }
            }
            $clean = array_keys($clean);
            if ($clean === []) {
                return [];
            }
            $limitSql = ' AND ID_USER IN (' . implode(',', array_fill(0, count($clean), '?')) . ')';
            $limitParams = $clean;
        }
        $pdo = Database::pdo();
        if (Database::tableExists('base_events') && Database::columnExists('base_events', 'ID_USER')) {
            $st = $pdo->prepare(
                'SELECT DISTINCT ID_USER FROM base_events
                 WHERE ID_USER > 0 AND DTSTART >= ? AND DTSTART <= ?' . $limitSql
            );
            $st->execute(array_merge([$from, $to], $limitParams));
            while ($id = $st->fetchColumn()) {
                $ids[(int)$id] = true;
            }
        }
        if (Database::tableExists('base_orders') && Database::columnExists('base_orders', 'ID_USER')) {
            $dtCol = Database::columnExists('base_orders', 'DT_CREATE') ? 'DT_CREATE' : 'DT_SAVE';
            $st = $pdo->prepare(
                "SELECT DISTINCT ID_USER FROM base_orders
                 WHERE ID_USER > 0 AND {$dtCol} >= ? AND {$dtCol} <= ?" . $limitSql
            );
            $st->execute(array_merge([$from, $to], $limitParams));
            while ($id = $st->fetchColumn()) {
                $ids[(int)$id] = true;
            }
        }
        return array_keys($ids);
    }

    /** @return array<string,string> request sort key => SQL expression */
    public static function listSortMap(): array
    {
        $map = [
            'id' => 'c.id',
            'name' => 'c.name',
            'tel' => 'c.tel',
            'mail' => 'c.mail',
            'birthday' => 'c.birthday',
            'registered' => 'c.registered_at',
            'enabled' => 'c.enabled',
        ];
        if (Database::tableExists('base_client_statuses')) {
            $map['status'] = 's.name';
        } else {
            $map['status'] = 'c.status_id';
        }
        return $map;
    }

    /**
     * @param array{q?:string,enabled?:string|null,status_id?:int|null,tag_id?:int|null,limit?:int,offset?:int,order?:string} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_clients')) {
            return ['items' => [], 'total' => 0];
        }
        $q = trim((string)($opts['q'] ?? ''));
        $enabled = $opts['enabled'] ?? null;
        $statusId = array_key_exists('status_id', $opts) ? $opts['status_id'] : null;
        $tagId = array_key_exists('tag_id', $opts) ? $opts['tag_id'] : null;
        $limit = (int)($opts['limit'] ?? 100);
        if ($limit < 0) {
            $limit = 0;
        } elseif ($limit > 0) {
            $limit = min(3000, max(1, $limit));
        }
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            // Legacy show_users default: ORDER BY id DESC (newest first).
            $order = 'c.id DESC';
        }

        $where = ['1=1'];
        $params = [];
        if ($enabled === '1' || $enabled === '0') {
            $where[] = 'c.enabled = ?';
            $params[] = (int)$enabled;
        }
        if ($statusId !== null && $statusId !== '' && (int)$statusId > 0) {
            $where[] = 'c.status_id = ?';
            $params[] = (int)$statusId;
        }
        if (
            $tagId !== null && $tagId !== '' && (int)$tagId > 0
            && Database::tableExists('base_client_tag_links')
        ) {
            $where[] = 'EXISTS (
                SELECT 1 FROM base_client_tag_links tl
                WHERE tl.client_id = c.id AND tl.tag_id = ?
            )';
            $params[] = (int)$tagId;
        }
        if ($q !== '') {
            if (self::canViewPii()) {
                $where[] = '(c.name LIKE ? OR c.tel LIKE ? OR c.mail LIKE ? OR c.id = ?)';
                $like = '%' . $q . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = ctype_digit($q) ? (int)$q : 0;
            } else {
                $where[] = '(c.name LIKE ? OR c.id = ?)';
                $like = '%' . $q . '%';
                $params[] = $like;
                $params[] = ctype_digit($q) ? (int)$q : 0;
            }
        }
        if (!Auth::capability('SUPERADMIN_ACCESS')) {
            $activeIds = self::clientIdsActiveOnDay(self::todayBounds());
            if ($activeIds === []) {
                return ['items' => [], 'total' => 0];
            }
            $where[] = 'c.id IN (' . implode(',', array_map('intval', $activeIds)) . ')';
        }
        $sqlWhere = implode(' AND ', $where);
        $pdo = Database::pdo();

        $stCount = $pdo->prepare("SELECT COUNT(*) FROM base_clients c WHERE $sqlWhere");
        $stCount->execute($params);
        $total = (int)$stCount->fetchColumn();

        $joinStatus = Database::tableExists('base_client_statuses');
        $statusSelect = $joinStatus ? ', s.name AS status_name' : ', NULL AS status_name';
        $statusJoin = $joinStatus
            ? ' LEFT JOIN base_client_statuses s ON s.id = c.status_id'
            : '';

        $sql = "SELECT c.id, c.name, c.tel, c.mail, c.birthday, c.gender, c.enabled, c.status_id,
                    c.staff_user_id, c.subscribe, c.registered_at
                    $statusSelect
             FROM base_clients c
             $statusJoin
             WHERE $sqlWhere
             ORDER BY $order";
        if ($limit > 0) {
            $sql .= " LIMIT $limit OFFSET $offset";
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $tagMap = self::tagNamesByClientIds(array_map(static function (array $r): int {
            return (int)$r['id'];
        }, $items));
        foreach ($items as &$item) {
            $item['tag_names'] = $tagMap[(int)$item['id']] ?? [];
        }
        unset($item);

        return ['items' => $items, 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_clients')) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_clients WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Match by exact tel (caller should normalize phone first). */
    public static function findByTel(string $tel): ?array
    {
        $tel = trim($tel);
        if ($tel === '' || !Database::tableExists('base_clients')) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_clients WHERE tel = ? LIMIT 1');
        $st->execute([$tel]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Lookup by normalized digits (11-digit 7… or last 10).
     */
    public static function findByPhoneDigits(string $digits): ?array
    {
        $digits = preg_replace('/\D+/', '', $digits) ?? '';
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        }
        $row = self::findByTel($digits);
        if ($row !== null) {
            return $row;
        }
        if (strlen($digits) >= 10) {
            $tail = substr($digits, -10);
            $found = self::list([
                'q' => $tail,
                'enabled' => null,
                'limit' => 10,
                'offset' => 0,
            ]);
            foreach ($found['items'] as $item) {
                $d = preg_replace('/\D+/', '', (string)($item['tel'] ?? '')) ?? '';
                if ($d === $digits || substr($d, -10) === $tail) {
                    return self::find((int)$item['id']) ?? $item;
                }
            }
        }
        return null;
    }

    /**
     * Storefront gate: unknown client or status without block_online → allowed.
     *
     * @param array<string,mixed>|null $client
     */
    public static function canBookOnline(?array $client): bool
    {
        if ($client === null) {
            return true;
        }
        $statusId = (int)($client['status_id'] ?? 0);
        if ($statusId < 1) {
            return true;
        }
        if (!class_exists(ClientStatusesRepository::class, false)) {
            return true;
        }
        return !ClientStatusesRepository::blocksOnline($statusId);
    }

    public static function setStatusId(int $clientId, int $statusId): void
    {
        if ($clientId < 1 || !Database::tableExists('base_clients')) {
            throw new InvalidArgumentException('Invalid client id');
        }
        $statusId = max(0, $statusId);
        $st = Database::pdo()->prepare('UPDATE base_clients SET status_id = ? WHERE id = ?');
        $st->execute([$statusId, $clientId]);
    }

    /**
     * Strip secrets for AgentHttp / JSON twins.
     *
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>|null
     */
    public static function forAgent(?array $row, bool $withTags = false): ?array
    {
        if ($row === null) {
            return null;
        }
        unset($row['password']);
        if ($withTags) {
            $id = (int)($row['id'] ?? 0);
            $row['tag_ids'] = $id > 0 ? self::tagIdsForClient($id) : [];
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
            unset($row['password']);
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $name = trim((string)($post['name'] ?? ''));
        $tel = trim((string)($post['tel'] ?? ''));
        if ($tel === '') {
            throw new InvalidArgumentException(t('clients.err.phone_required'));
        }
        if ($name === '') {
            $name = $tel;
        }

        $genderRaw = (string)($post['gender'] ?? '');
        $gender = null;
        if ($genderRaw === '0' || $genderRaw === '1') {
            $gender = (int)$genderRaw;
        }

        $birthday = trim((string)($post['birthday'] ?? ''));
        if ($birthday === '') {
            $birthdayDate = null;
        } else {
            $ts = strtotime($birthday);
            if ($ts === false) {
                throw new InvalidArgumentException(t('clients.err.bad_birthday'));
            }
            $birthdayDate = date('Y-m-d', $ts);
        }

        $staffRaw = trim((string)($post['staff_user_id'] ?? ''));
        $staffUserId = ($staffRaw === '' || (int)$staffRaw < 1) ? null : (int)$staffRaw;

        $nullStr = static function (string $key) use ($post): ?string {
            $v = trim((string)($post[$key] ?? ''));
            return $v === '' ? null : $v;
        };

        $data = [
            'name' => $name,
            'tel' => $tel,
            'mail' => trim((string)($post['mail'] ?? '')),
            'birthday' => $birthdayDate,
            'gender' => $gender,
            'enabled' => !empty($post['enabled']) ? 1 : 0,
            'status_id' => max(0, (int)($post['status_id'] ?? 0)),
            'staff_user_id' => $staffUserId,
            'surname' => $nullStr('surname'),
            'pname' => $nullStr('pname'),
            'patronymic' => $nullStr('patronymic'),
            'passport_serie' => $nullStr('passport_serie'),
            'passport_number' => $nullStr('passport_number'),
            'passport_issued_by' => $nullStr('passport_issued_by'),
            'passport_issued_at' => $nullStr('passport_issued_at'),
            'passport_birthday' => $nullStr('passport_birthday'),
            'address_passport' => $nullStr('address_passport'),
            'address_fact' => $nullStr('address_fact'),
            'subscribe' => !empty($post['subscribe']) ? 1 : 0,
            'notes' => $nullStr('notes'),
        ];

        $pass = (string)($post['password'] ?? '');
        if ($pass !== '') {
            $data['password'] = $pass;
        }

        return $data;
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'INSERT INTO base_clients (
                name, tel, mail, password, birthday, gender, enabled, registered_at, status_id,
                staff_user_id, surname, pname, patronymic,
                passport_serie, passport_number, passport_issued_by, passport_issued_at, passport_birthday,
                address_passport, address_fact, subscribe, notes
            ) VALUES (
                ?,?,?,?,?,?,?,?,?,
                ?,?,?,?,
                ?,?,?,?,?,
                ?,?,?,?
            )'
        );
        $st->execute([
            $data['name'],
            $data['tel'],
            $data['mail'],
            (string)($data['password'] ?? ''),
            $data['birthday'],
            $data['gender'],
            $data['enabled'],
            time(),
            $data['status_id'],
            $data['staff_user_id'],
            $data['surname'],
            $data['pname'],
            $data['patronymic'],
            $data['passport_serie'],
            $data['passport_number'],
            $data['passport_issued_by'],
            $data['passport_issued_at'],
            $data['passport_birthday'],
            $data['address_passport'],
            $data['address_fact'],
            $data['subscribe'],
            $data['notes'],
        ]);
        return (int)$pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid client id');
        }
        $sets = [
            'name = ?', 'tel = ?', 'mail = ?', 'birthday = ?', 'gender = ?', 'enabled = ?',
            'status_id = ?', 'staff_user_id = ?',
            'surname = ?', 'pname = ?', 'patronymic = ?',
            'passport_serie = ?', 'passport_number = ?', 'passport_issued_by = ?',
            'passport_issued_at = ?', 'passport_birthday = ?',
            'address_passport = ?', 'address_fact = ?',
            'subscribe = ?', 'notes = ?',
        ];
        $params = [
            $data['name'],
            $data['tel'],
            $data['mail'],
            $data['birthday'],
            $data['gender'],
            $data['enabled'],
            $data['status_id'],
            $data['staff_user_id'],
            $data['surname'],
            $data['pname'],
            $data['patronymic'],
            $data['passport_serie'],
            $data['passport_number'],
            $data['passport_issued_by'],
            $data['passport_issued_at'],
            $data['passport_birthday'],
            $data['address_passport'],
            $data['address_fact'],
            $data['subscribe'],
            $data['notes'],
        ];
        if (array_key_exists('password', $data)) {
            $sets[] = 'password = ?';
            $params[] = $data['password'];
        }
        $params[] = $id;
        $sql = 'UPDATE base_clients SET ' . implode(', ', $sets) . ' WHERE id = ?';
        Database::pdo()->prepare($sql)->execute($params);
    }

    /** @return list<array{id:int,name:string,login:string}> */
    public static function staffOptions(): array
    {
        if (!Database::tableExists('base_users')) {
            return [];
        }
        $st = Database::pdo()->query(
            "SELECT id, name, login FROM base_users WHERE enabled='1' ORDER BY name ASC LIMIT 500"
        );
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = [
                'id' => (int)$r['id'],
                'name' => (string)$r['name'],
                'login' => (string)$r['login'],
            ];
        }
        return $out;
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
        if ($ids === [] || !Database::tableExists('base_clients')) {
            return 0;
        }
        $pdo = Database::pdo();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if (Database::tableExists('base_client_tag_links')) {
            $pdo->prepare("DELETE FROM base_client_tag_links WHERE client_id IN ($placeholders)")->execute($ids);
        }
        $st = $pdo->prepare("DELETE FROM base_clients WHERE id IN ($placeholders)");
        $st->execute($ids);
        return $st->rowCount();
    }

    /**
     * @param list<int> $ids
     * @return int updated count
     */
    public static function setEnabledByIds(array $ids, bool $enabled): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === [] || !Database::tableExists('base_clients')) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([(int)$enabled], $ids);
        $st = Database::pdo()->prepare(
            "UPDATE base_clients SET enabled = ? WHERE id IN ($placeholders)"
        );
        $st->execute($params);
        return $st->rowCount();
    }

    /** @return list<int> */
    public static function tagIdsForClient(int $clientId): array
    {
        if ($clientId < 1 || !Database::tableExists('base_client_tag_links')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT tag_id FROM base_client_tag_links WHERE client_id = ? ORDER BY tag_id ASC'
        );
        $st->execute([$clientId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $tid) {
            $out[] = (int)$tid;
        }
        return $out;
    }

    /**
     * @param list<int> $tagIds
     */
    public static function setTagIds(int $clientId, array $tagIds): void
    {
        if ($clientId < 1 || !Database::tableExists('base_client_tag_links')) {
            return;
        }
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds), static function (int $id): bool {
            return $id > 0;
        })));
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM base_client_tag_links WHERE client_id = ?')->execute([$clientId]);
        if ($tagIds === []) {
            return;
        }
        $st = $pdo->prepare(
            'INSERT INTO base_client_tag_links (client_id, tag_id) VALUES (?, ?)'
        );
        foreach ($tagIds as $tid) {
            $st->execute([$clientId, $tid]);
        }
    }

    /**
     * @param list<int> $clientIds
     * @return int number of newly inserted links
     */
    public static function addTagToClients(array $clientIds, int $tagId): int
    {
        $clientIds = array_values(array_unique(array_filter(array_map('intval', $clientIds), static function (int $id): bool {
            return $id > 0;
        })));
        if ($clientIds === [] || $tagId < 1 || !Database::tableExists('base_client_tag_links')) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'INSERT IGNORE INTO base_client_tag_links (client_id, tag_id) VALUES (?, ?)'
        );
        $done = 0;
        foreach ($clientIds as $cid) {
            $st->execute([$cid, $tagId]);
            $done += $st->rowCount();
        }
        return $done;
    }

    /**
     * @param list<int> $clientIds
     * @return int deleted link count
     */
    public static function removeTagFromClients(array $clientIds, int $tagId): int
    {
        $clientIds = array_values(array_unique(array_filter(array_map('intval', $clientIds), static function (int $id): bool {
            return $id > 0;
        })));
        if ($clientIds === [] || $tagId < 1 || !Database::tableExists('base_client_tag_links')) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
        $params = array_merge([$tagId], $clientIds);
        $st = Database::pdo()->prepare(
            "DELETE FROM base_client_tag_links WHERE tag_id = ? AND client_id IN ($placeholders)"
        );
        $st->execute($params);
        return $st->rowCount();
    }

    /**
     * @param list<int> $clientIds
     * @return array<int,list<string>> client_id => tag names
     */
    public static function tagNamesByClientIds(array $clientIds): array
    {
        $clientIds = array_values(array_unique(array_filter(array_map('intval', $clientIds), static function (int $id): bool {
            return $id > 0;
        })));
        if (
            $clientIds === []
            || !Database::tableExists('base_client_tag_links')
            || !Database::tableExists('base_shopusers_tags')
        ) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
        $st = Database::pdo()->prepare(
            "SELECT l.client_id, t.NAME AS name
             FROM base_client_tag_links l
             INNER JOIN base_shopusers_tags t ON t.ID = l.tag_id
             WHERE l.client_id IN ($placeholders)
             ORDER BY t.NAME ASC"
        );
        $st->execute($clientIds);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $cid = (int)$r['client_id'];
            if (!isset($out[$cid])) {
                $out[$cid] = [];
            }
            $out[$cid][] = (string)$r['name'];
        }
        return $out;
    }
}
