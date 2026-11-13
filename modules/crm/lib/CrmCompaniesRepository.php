<?php
declare(strict_types=1);

final class CrmCompaniesRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_crm_companies');
    }

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'c.id',
            'name' => 'c.name',
            'inn' => 'c.inn',
            'updated_at' => 'c.updated_at',
        ];
    }

    /**
     * @param array{q?:string,enabled?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!self::tableReady()) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(c.name LIKE ? OR c.inn LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR CAST(c.id AS CHAR) = ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $q);
        }
        if (array_key_exists('enabled', $opts) && $opts['enabled'] !== '' && $opts['enabled'] !== null) {
            $where[] = 'c.enabled = ?';
            $params[] = ((string)$opts['enabled'] === '1') ? 1 : 0;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_crm_companies c WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'c.name ASC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT c.id, c.name, c.inn, c.kpp, c.phone, c.email, c.website, c.address,
                       c.enabled, c.responsible_user_id, c.created_at, c.updated_at
                FROM base_crm_companies c WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT id, name, inn, kpp, phone, email, website, address, notes,
                    enabled, responsible_user_id, created_at, updated_at
             FROM base_crm_companies WHERE id = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function findByInn(string $inn): ?array
    {
        $inn = trim($inn);
        if ($inn === '' || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT id FROM base_crm_companies WHERE inn = ? ORDER BY id ASC LIMIT 1');
        $st->execute([$inn]);
        $id = (int)($st->fetchColumn() ?: 0);
        return $id > 0 ? self::find($id) : null;
    }

    public static function findByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '' || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT id FROM base_crm_companies WHERE name = ? ORDER BY id ASC LIMIT 1');
        $st->execute([$name]);
        $id = (int)($st->fetchColumn() ?: 0);
        return $id > 0 ? self::find($id) : null;
    }

    private static function hasRoleColumn(): bool
    {
        static $has = null;
        if ($has === null) {
            $has = Database::tableExists('base_crm_company_contacts')
                && Database::columnExists('base_crm_company_contacts', 'role');
        }
        return $has;
    }

    /** @return list<array{client_id:int,is_primary:int,role:string,name?:string,tel?:string}> */
    public static function contactLinks(int $companyId): array
    {
        if ($companyId < 1 || !Database::tableExists('base_crm_company_contacts')) {
            return [];
        }
        $role = self::hasRoleColumn() ? 'cc.role' : "'' AS role";
        if (Database::tableExists('base_clients')) {
            $sql = "SELECT cc.client_id, cc.is_primary, $role, cl.name, cl.tel, cl.mail
                    FROM base_crm_company_contacts cc
                    LEFT JOIN base_clients cl ON cl.id = cc.client_id
                    WHERE cc.company_id = ?
                    ORDER BY cc.is_primary DESC, cc.client_id ASC";
        } else {
            $sql = "SELECT cc.client_id, cc.is_primary, $role
                    FROM base_crm_company_contacts cc WHERE cc.company_id = ? ORDER BY cc.is_primary DESC";
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute([$companyId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Link (or update role of) one contact; first contact becomes primary. Keeps other links. */
    public static function addContact(int $companyId, int $clientId, string $role = '', bool $makePrimary = false): void
    {
        if ($companyId < 1 || $clientId < 1 || !Database::tableExists('base_crm_company_contacts')) {
            throw new InvalidArgumentException(t('crm.err.contact_required'));
        }
        if (self::find($companyId) === null) {
            throw new InvalidArgumentException('Invalid company id');
        }
        $role = mb_substr(trim($role), 0, 64);
        $pdo = Database::pdo();
        $hasPrimary = (int)$pdo->query(
            'SELECT COUNT(*) FROM base_crm_company_contacts WHERE company_id = ' . $companyId . ' AND is_primary = 1'
        )->fetchColumn() > 0;
        $primary = $makePrimary || !$hasPrimary ? 1 : 0;
        if (self::hasRoleColumn()) {
            $pdo->prepare(
                "INSERT INTO base_crm_company_contacts (company_id, client_id, is_primary, role) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE role = IF(VALUES(role) <> '', VALUES(role), role),
                    is_primary = IF(VALUES(is_primary) = 1, 1, is_primary)"
            )->execute([$companyId, $clientId, $primary, $role]);
        } else {
            $pdo->prepare(
                'INSERT INTO base_crm_company_contacts (company_id, client_id, is_primary) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE is_primary = IF(VALUES(is_primary) = 1, 1, is_primary)'
            )->execute([$companyId, $clientId, $primary]);
        }
        if ($makePrimary) {
            self::setPrimaryContact($companyId, $clientId, false);
        }
        self::emitUpdated($companyId);
    }

    public static function removeContact(int $companyId, int $clientId): void
    {
        if ($companyId < 1 || $clientId < 1 || !Database::tableExists('base_crm_company_contacts')) {
            return;
        }
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM base_crm_company_contacts WHERE company_id = ? AND client_id = ?')
            ->execute([$companyId, $clientId]);
        $left = (int)$pdo->query(
            'SELECT COUNT(*) FROM base_crm_company_contacts WHERE company_id = ' . $companyId . ' AND is_primary = 1'
        )->fetchColumn();
        if ($left === 0) {
            $pdo->prepare(
                'UPDATE base_crm_company_contacts SET is_primary = 1 WHERE company_id = ? ORDER BY client_id ASC LIMIT 1'
            )->execute([$companyId]);
        }
        self::emitUpdated($companyId);
    }

    public static function setContactRole(int $companyId, int $clientId, string $role): void
    {
        if (!self::hasRoleColumn()) {
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_crm_company_contacts SET role = ? WHERE company_id = ? AND client_id = ?'
        );
        $st->execute([mb_substr(trim($role), 0, 64), $companyId, $clientId]);
        if ($st->rowCount() < 1 && !self::isContact($companyId, $clientId)) {
            throw new InvalidArgumentException(t('crm.err.contact_not_linked'));
        }
        self::emitUpdated($companyId);
    }

    public static function setPrimaryContact(int $companyId, int $clientId, bool $emit = true): void
    {
        if (!self::isContact($companyId, $clientId)) {
            throw new InvalidArgumentException(t('crm.err.contact_not_linked'));
        }
        Database::pdo()->prepare(
            'UPDATE base_crm_company_contacts SET is_primary = IF(client_id = ?, 1, 0) WHERE company_id = ?'
        )->execute([$clientId, $companyId]);
        if ($emit) {
            self::emitUpdated($companyId);
        }
    }

    public static function isContact(int $companyId, int $clientId): bool
    {
        if ($companyId < 1 || $clientId < 1 || !Database::tableExists('base_crm_company_contacts')) {
            return false;
        }
        $st = Database::pdo()->prepare(
            'SELECT 1 FROM base_crm_company_contacts WHERE company_id = ? AND client_id = ? LIMIT 1'
        );
        $st->execute([$companyId, $clientId]);
        return (bool)$st->fetchColumn();
    }

    private static function emitUpdated(int $companyId): void
    {
        Database::pdo()->prepare('UPDATE base_crm_companies SET updated_at = ? WHERE id = ?')
            ->execute([time(), $companyId]);
        EventBus::emit('crm.company.updated', CrmSyncContext::withOrigin([
            'id' => $companyId,
            'row' => self::find($companyId),
        ]));
    }

    /**
     * @param array<string,mixed> $data
     * @param list<int> $clientIds
     * @param int $primaryClientId
     */
    public static function create(array $data, array $clientIds = [], int $primaryClientId = 0): int
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('crm.err.company_name'));
        }
        $now = time();
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO base_crm_companies
             (name, inn, kpp, phone, email, website, address, notes, enabled, responsible_user_id, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $name,
            trim((string)($data['inn'] ?? '')),
            trim((string)($data['kpp'] ?? '')),
            trim((string)($data['phone'] ?? '')),
            trim((string)($data['email'] ?? '')),
            trim((string)($data['website'] ?? '')),
            trim((string)($data['address'] ?? '')),
            (string)($data['notes'] ?? ''),
            !empty($data['enabled']) ? 1 : 0,
            (int)($data['responsible_user_id'] ?? 0),
            $now,
            $now,
        ]);
        $id = (int)$pdo->lastInsertId();
        self::setContacts($id, $clientIds, $primaryClientId);
        $row = self::find($id);
        EventBus::emit('crm.company.created', CrmSyncContext::withOrigin([
            'id' => $id,
            'row' => $row,
        ]));
        return $id;
    }

    /**
     * @param array<string,mixed> $data
     * @param list<int>|null $clientIds null = leave contacts unchanged
     */
    public static function update(int $id, array $data, ?array $clientIds = null, int $primaryClientId = 0): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid company id');
        }
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('crm.err.company_name'));
        }
        Database::pdo()->prepare(
            'UPDATE base_crm_companies SET
             name = ?, inn = ?, kpp = ?, phone = ?, email = ?, website = ?, address = ?, notes = ?,
             enabled = ?, responsible_user_id = ?, updated_at = ?
             WHERE id = ?'
        )->execute([
            $name,
            trim((string)($data['inn'] ?? '')),
            trim((string)($data['kpp'] ?? '')),
            trim((string)($data['phone'] ?? '')),
            trim((string)($data['email'] ?? '')),
            trim((string)($data['website'] ?? '')),
            trim((string)($data['address'] ?? '')),
            (string)($data['notes'] ?? ''),
            !empty($data['enabled']) ? 1 : 0,
            (int)($data['responsible_user_id'] ?? 0),
            time(),
            $id,
        ]);
        if ($clientIds !== null) {
            self::setContacts($id, $clientIds, $primaryClientId);
        }
        $row = self::find($id);
        EventBus::emit('crm.company.updated', CrmSyncContext::withOrigin([
            'id' => $id,
            'row' => $row,
        ]));
    }

    /** @param list<int> $clientIds */
    public static function setContacts(int $companyId, array $clientIds, int $primaryClientId = 0): void
    {
        if ($companyId < 1 || !Database::tableExists('base_crm_company_contacts')) {
            return;
        }
        $pdo = Database::pdo();
        $roles = [];
        foreach (self::contactLinks($companyId) as $link) {
            $roles[(int)$link['client_id']] = (string)($link['role'] ?? '');
        }
        $pdo->prepare('DELETE FROM base_crm_company_contacts WHERE company_id = ?')->execute([$companyId]);
        $seen = [];
        foreach ($clientIds as $cid) {
            $cid = (int)$cid;
            if ($cid < 1 || isset($seen[$cid])) {
                continue;
            }
            $seen[$cid] = true;
            $primary = ($primaryClientId === $cid || ($primaryClientId < 1 && count($seen) === 1)) ? 1 : 0;
            if (self::hasRoleColumn()) {
                $pdo->prepare(
                    'INSERT INTO base_crm_company_contacts (company_id, client_id, is_primary, role) VALUES (?,?,?,?)'
                )->execute([$companyId, $cid, $primary, $roles[$cid] ?? '']);
            } else {
                $pdo->prepare(
                    'INSERT INTO base_crm_company_contacts (company_id, client_id, is_primary) VALUES (?,?,?)'
                )->execute([$companyId, $cid, $primary]);
            }
        }
    }

    public static function delete(int $id): void
    {
        if ($id < 1 || !self::tableReady()) {
            return;
        }
        $row = self::find($id);
        $pdo = Database::pdo();
        if (Database::tableExists('base_crm_company_contacts')) {
            $pdo->prepare('DELETE FROM base_crm_company_contacts WHERE company_id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM base_crm_companies WHERE id = ?')->execute([$id]);
        if (class_exists('CrmLinksRepository')) {
            CrmLinksRepository::removeEntity('company', $id);
        }
        EventBus::emit('crm.company.deleted', CrmSyncContext::withOrigin([
            'id' => $id,
            'row' => $row,
        ]));
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids): int
    {
        $n = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id < 1) {
                continue;
            }
            self::delete($id);
            $n++;
        }
        return $n;
    }

    /** @return array<int,string> */
    public static function names(bool $enabledOnly = true): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $sql = 'SELECT id, name FROM base_crm_companies';
        if ($enabledOnly) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY name ASC';
        $out = [];
        foreach (Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['id']] = (string)$r['name'];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function forClient(int $clientId): array
    {
        if ($clientId < 1 || !Database::tableExists('base_crm_company_contacts')) {
            return [];
        }
        $role = self::hasRoleColumn() ? 'cc.role' : "'' AS role";
        $st = Database::pdo()->prepare(
            "SELECT c.id, c.name, c.inn, c.phone, cc.is_primary, $role
             FROM base_crm_company_contacts cc
             INNER JOIN base_crm_companies c ON c.id = cc.company_id
             WHERE cc.client_id = ?
             ORDER BY cc.is_primary DESC, c.name ASC"
        );
        $st->execute([$clientId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
