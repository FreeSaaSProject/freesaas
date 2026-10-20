<?php
declare(strict_types=1);

/** Filled documents incl. medcards (base_doc_instances) + narrow field index. */
final class DocsInstancesRepository
{
    public const STATUSES = ['draft', 'ready', 'printed', 'void'];

    /** Meta columns for lists / calendar sections (never PAYLOAD / PRINT_SNAPSHOT). */
    private const META_COLS = 'i.ID, i.ID_DEF, i.ID_VERSION, i.KIND, i.ID_CLIENT, i.ID_EVENT, i.ID_PROCEDURE, i.ID_PARENT,
        i.STATUS, i.HASH, i.HASH_EXT, i.DT_CREATE, i.DT_UPDATE, i.DT_PRINT, i.DT_EXPIRE,
        i.ID_ADMIN_CREATE, i.ID_ADMIN_UPDATE, i.ID_ADMIN_PRINT, i.ID_ADMIN_SIGNED, i.DT_SIGNED';

    public static function tableReady(): bool
    {
        return Database::tableExists('base_doc_instances');
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ' . self::META_COLS . ', i.PAYLOAD, i.PRINT_SNAPSHOT, d.NAME AS DEF_NAME, d.CODE AS DEF_CODE
             FROM base_doc_instances i LEFT JOIN base_doc_defs d ON d.ID = i.ID_DEF
             WHERE i.ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalize($row, true) : null;
    }

    /** @return array<string,mixed>|null meta only */
    public static function findByHashExt(string $hash): ?array
    {
        $hash = strtolower(trim($hash));
        if (!preg_match('/^[a-f0-9]{32}$/', $hash) || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ' . self::META_COLS . ', d.NAME AS DEF_NAME, d.CODE AS DEF_CODE
             FROM base_doc_instances i LEFT JOIN base_doc_defs d ON d.ID = i.ID_DEF
             WHERE i.HASH_EXT = ? LIMIT 1'
        );
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalize($row, false) : null;
    }

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'i.ID',
            'kind' => 'i.KIND',
            'status' => 'i.STATUS',
            'dt_create' => 'i.DT_CREATE',
            'dt_print' => 'i.DT_PRINT',
        ];
    }

    /**
     * @param array<string,mixed> $opts kind, kinds(list), client, event, status, def, limit, offset, order
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!self::tableReady()) {
            return ['items' => [], 'total' => 0];
        }
        $where = ['1=1'];
        $params = [];
        $kind = (string)($opts['kind'] ?? '');
        if ($kind !== '') {
            $where[] = 'i.KIND = ?';
            $params[] = $kind;
        }
        $kinds = isset($opts['kinds']) && is_array($opts['kinds']) ? array_values($opts['kinds']) : null;
        if ($kinds !== null) {
            if ($kinds === []) {
                return ['items' => [], 'total' => 0];
            }
            $where[] = 'i.KIND IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')';
            foreach ($kinds as $k) {
                $params[] = (string)$k;
            }
        }
        foreach (['client' => 'i.ID_CLIENT', 'event' => 'i.ID_EVENT', 'procedure' => 'i.ID_PROCEDURE', 'def' => 'i.ID_DEF'] as $key => $col) {
            $v = (int)($opts[$key] ?? 0);
            if ($v > 0) {
                $where[] = $col . ' = ?';
                $params[] = $v;
            }
        }
        $status = (string)($opts['status'] ?? '');
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'i.STATUS = ?';
            $params[] = $status;
        } elseif (empty($opts['with_void'])) {
            $where[] = "i.STATUS <> 'void'";
        }
        $whereSql = implode(' AND ', $where);
        $pdo = Database::pdo();
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM base_doc_instances i WHERE ' . $whereSql);
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'i.ID DESC';
        }
        $limit = (int)($opts['limit'] ?? 50);
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $clientJoin = Database::tableExists('base_clients')
            ? 'LEFT JOIN base_clients c ON c.id = i.ID_CLIENT'
            : '';
        $clientCol = $clientJoin !== '' ? 'c.name AS CLIENT_NAME' : "'' AS CLIENT_NAME";
        $sql = 'SELECT ' . self::META_COLS . ', d.NAME AS DEF_NAME, d.CODE AS DEF_CODE, ' . $clientCol . '
                FROM base_doc_instances i
                LEFT JOIN base_doc_defs d ON d.ID = i.ID_DEF
                ' . $clientJoin . '
                WHERE ' . $whereSql . ' ORDER BY ' . $order;
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = self::normalize($row, false);
        }
        return ['items' => $items, 'total' => $total];
    }

    /** @return list<array<string,mixed>> meta rows, oldest first */
    public static function listForEvent(int $eventId): array
    {
        if ($eventId < 1) {
            return [];
        }
        return self::list(['event' => $eventId, 'limit' => 100, 'order' => 'i.ID ASC'])['items'];
    }

    /** Current (latest non-void) instance of a kind for a client, with payload. */
    public static function latestForClient(int $clientId, string $kind): ?array
    {
        if ($clientId < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            "SELECT ID FROM base_doc_instances WHERE ID_CLIENT = ? AND KIND = ? AND STATUS <> 'void'
             ORDER BY ID DESC LIMIT 1"
        );
        $st->execute([$clientId, $kind]);
        $id = (int)$st->fetchColumn();
        return $id > 0 ? self::find($id) : null;
    }

    /** Client-scope reuse: an instance of this definition that is not void and not expired. */
    public static function findValidForClient(int $defId, int $clientId, int $now): ?int
    {
        if ($defId < 1 || $clientId < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            "SELECT ID FROM base_doc_instances
             WHERE ID_DEF = ? AND ID_CLIENT = ? AND STATUS <> 'void' AND (DT_PRINT = 0 OR DT_EXPIRE = 0 OR DT_EXPIRE > ?)
             ORDER BY ID DESC LIMIT 1"
        );
        $st->execute([$defId, $clientId, $now]);
        $id = (int)$st->fetchColumn();
        return $id > 0 ? $id : null;
    }

    public static function findForEventDef(int $eventId, int $defId): ?int
    {
        if ($eventId < 1 || $defId < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            "SELECT ID FROM base_doc_instances WHERE ID_EVENT = ? AND ID_DEF = ? AND STATUS <> 'void' ORDER BY ID DESC LIMIT 1"
        );
        $st->execute([$eventId, $defId]);
        $id = (int)$st->fetchColumn();
        return $id > 0 ? $id : null;
    }

    /**
     * Ids of the client's visit sheets in visit order (event start, else creation).
     *
     * @return list<int>
     */
    public static function visitIdsForClient(int $clientId): array
    {
        if ($clientId < 1 || !self::tableReady()) {
            return [];
        }
        $eventJoin = Database::tableExists('base_events') ? 'LEFT JOIN base_events e ON e.ID = i.ID_EVENT' : '';
        $orderTs = $eventJoin !== '' ? 'COALESCE(NULLIF(e.DTSTART, 0), i.DT_CREATE)' : 'i.DT_CREATE';
        $st = Database::pdo()->prepare(
            "SELECT i.ID FROM base_doc_instances i {$eventJoin}
             WHERE i.ID_CLIENT = ? AND i.KIND = 'medcard_visit' AND i.STATUS <> 'void'
             ORDER BY {$orderTs} ASC, i.ID ASC LIMIT 500"
        );
        $st->execute([$clientId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** Medical visit sheets of a client in visit order (dossier). */
    public static function visitsForClient(int $clientId): array
    {
        if ($clientId < 1 || !self::tableReady()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            "SELECT ID FROM base_doc_instances WHERE ID_CLIENT = ? AND KIND = 'medcard_visit' AND STATUS <> 'void'
             ORDER BY DT_CREATE ASC, ID ASC LIMIT 500"
        );
        $st->execute([$clientId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $row = self::find((int)$id);
            if ($row !== null) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $def normalized definition
     * @param array{client?:int,event?:int,procedure?:int,parent?:int,payload?:array<string,mixed>,source?:string} $opts
     */
    public static function create(array $def, array $opts = []): int
    {
        $ver = DocsDefsRepository::currentVersion($def);
        if ($ver === null) {
            throw new RuntimeException(t('docs.err.no_version', ['code' => (string)$def['code']]));
        }
        $schema = $ver['schema'];
        $fieldMap = DocsSchema::fieldMap($schema);
        $payload = [];
        $source = (string)($opts['source'] ?? 'manual');
        $now = time();
        foreach ((array)($opts['payload'] ?? []) as $fid => $raw) {
            $fid = (string)$fid;
            if ($fid === '_prov' || !isset($fieldMap[$fid])) {
                continue;
            }
            $value = DocsSchema::normalizeValue($fieldMap[$fid], $raw);
            if (DocsSchema::isEmpty($fieldMap[$fid], $value)) {
                continue;
            }
            $payload[$fid] = $value;
            $payload['_prov'][$fid] = ['source' => $source, 'by' => (int)(Auth::userId() ?? 0), 'at' => $now];
        }
        $status = DocsSchema::missingRequired($schema, $payload) === [] ? 'ready' : 'draft';
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO base_doc_instances
             (ID_DEF, ID_VERSION, KIND, ID_CLIENT, ID_EVENT, ID_PROCEDURE, ID_PARENT, STATUS, PAYLOAD,
              HASH, HASH_EXT, DT_CREATE, DT_UPDATE, ID_ADMIN_CREATE, ID_ADMIN_UPDATE)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int)$def['id'],
            (int)$ver['id'],
            (string)$def['kind'],
            max(0, (int)($opts['client'] ?? 0)),
            max(0, (int)($opts['event'] ?? 0)),
            max(0, (int)($opts['procedure'] ?? 0)),
            max(0, (int)($opts['parent'] ?? 0)),
            $status,
            self::encodePayload($payload),
            bin2hex(random_bytes(16)),
            bin2hex(random_bytes(16)),
            $now,
            $now,
            (int)(Auth::userId() ?? 0),
            (int)(Auth::userId() ?? 0),
        ]);
        $id = (int)$pdo->lastInsertId();
        self::syncIndex($id, $schema, $payload, (string)$def['kind'], (int)($opts['client'] ?? 0));
        return $id;
    }

    /**
     * Merge field values into the payload (unknown ids ignored), recompute status + index.
     *
     * @param array<string,mixed> $values field id => raw value
     * @param array<string,mixed> $prov extra provenance merged per changed field
     * @return list<string> changed field ids
     */
    public static function updateFields(int $id, array $values, string $source = 'manual', array $prov = []): array
    {
        $row = self::find($id);
        if ($row === null) {
            throw new InvalidArgumentException(t('docs.err.not_found', ['id' => $id]));
        }
        if (in_array($row['status'], ['printed', 'void'], true)) {
            throw new InvalidArgumentException(t('docs.err.locked'));
        }
        $ver = DocsDefsRepository::version((int)$row['id_version']);
        if ($ver === null) {
            throw new RuntimeException(t('docs.err.no_version', ['code' => (string)$row['def_code']]));
        }
        $schema = $ver['schema'];
        $fieldMap = DocsSchema::fieldMap($schema);
        $payload = $row['payload'];
        $now = time();
        $userId = (int)(Auth::userId() ?? 0);
        $changed = [];
        foreach ($values as $fid => $raw) {
            $fid = (string)$fid;
            if (!isset($fieldMap[$fid])) {
                continue;
            }
            $new = DocsSchema::normalizeValue($fieldMap[$fid], $raw);
            $old = $payload[$fid] ?? null;
            if (DocsSchema::canon($new) === DocsSchema::canon($old)
                || (DocsSchema::isEmpty($fieldMap[$fid], $new) && DocsSchema::isEmpty($fieldMap[$fid], $old))
            ) {
                continue;
            }
            $payload[$fid] = $new;
            $payload['_prov'][$fid] = array_merge(['source' => $source, 'by' => $userId, 'at' => $now], $prov);
            $changed[] = $fid;
        }
        if ($changed === []) {
            return [];
        }
        $status = DocsSchema::missingRequired($schema, $payload) === [] ? 'ready' : 'draft';
        Database::pdo()->prepare(
            'UPDATE base_doc_instances SET PAYLOAD = ?, STATUS = ?, DT_UPDATE = ?, ID_ADMIN_UPDATE = ? WHERE ID = ?'
        )->execute([self::encodePayload($payload), $status, $now, $userId, $id]);
        self::syncIndex($id, $schema, $payload, (string)$row['kind'], (int)$row['id_client']);
        EventBus::emit('docs.instance.saved', [
            'id' => $id,
            'kind' => (string)$row['kind'],
            'changed' => $changed,
            'source' => $source,
        ]);
        return $changed;
    }

    public static function markPrinted(int $id, string $snapshot, int $expireDays): void
    {
        $now = time();
        Database::pdo()->prepare(
            "UPDATE base_doc_instances SET STATUS = 'printed', PRINT_SNAPSHOT = ?, DT_PRINT = ?, DT_EXPIRE = ?,
             ID_ADMIN_PRINT = ?, DT_UPDATE = ? WHERE ID = ? AND DT_PRINT = 0"
        )->execute([
            $snapshot,
            $now,
            $expireDays > 0 ? $now + $expireDays * 86400 : 0,
            (int)(Auth::userId() ?? 0),
            $now,
            $id,
        ]);
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            return;
        }
        Database::pdo()->prepare('UPDATE base_doc_instances SET STATUS = ?, DT_UPDATE = ?, ID_ADMIN_UPDATE = ? WHERE ID = ?')
            ->execute([$status, time(), (int)(Auth::userId() ?? 0), $id]);
    }

    /** Void a printed/ready instance and clone its payload into a new draft on the current version. */
    public static function revise(int $id): int
    {
        $row = self::find($id);
        if ($row === null) {
            throw new InvalidArgumentException(t('docs.err.not_found', ['id' => $id]));
        }
        $def = DocsDefsRepository::find((int)$row['id_def']);
        if ($def === null) {
            throw new InvalidArgumentException(t('docs.def.err.not_found', ['id' => (int)$row['id_def']]));
        }
        $payload = $row['payload'];
        unset($payload['_prov']);
        self::setStatus($id, 'void');
        return self::create($def, [
            'client' => (int)$row['id_client'],
            'event' => (int)$row['id_event'],
            'procedure' => (int)$row['id_procedure'],
            'parent' => $id,
            'payload' => $payload,
            'source' => 'revision',
        ]);
    }

    public static function sign(int $id): void
    {
        Database::pdo()->prepare(
            'UPDATE base_doc_instances SET ID_ADMIN_SIGNED = ?, DT_SIGNED = ? WHERE ID = ? AND DT_SIGNED = 0'
        )->execute([(int)(Auth::userId() ?? 0), time(), $id]);
    }

    public static function setProcedure(int $id, int $procedureId): void
    {
        Database::pdo()->prepare('UPDATE base_doc_instances SET ID_PROCEDURE = ? WHERE ID = ? AND ID_PROCEDURE = 0')
            ->execute([$procedureId, $id]);
    }

    /**
     * Delete never-printed instances; printed ones are only voided (archive).
     *
     * @param list<int> $ids
     * @return array{done:int,skipped:list<array{id:int,reason:string}>}
     */
    public static function deleteByIds(array $ids): array
    {
        $done = 0;
        $skipped = [];
        $pdo = Database::pdo();
        foreach ($ids as $id) {
            $id = (int)$id;
            $row = self::find($id);
            if ($row === null) {
                continue;
            }
            // Same gate as the edit UI: docs.edit alone must not delete medcards without docs.medical.
            if (!DocsConfig::canSeeKind((string)$row['kind'])) {
                $skipped[] = ['id' => $id, 'reason' => t('docs.err.no_medical')];
                continue;
            }
            if ((int)$row['dt_print'] > 0) {
                $skipped[] = ['id' => $id, 'reason' => t('docs.err.delete_printed')];
                continue;
            }
            $pdo->prepare('DELETE FROM base_doc_field_index WHERE ID_INSTANCE = ?')->execute([$id]);
            if (Database::tableExists('base_doc_proposals')) {
                $pdo->prepare('DELETE FROM base_doc_proposals WHERE ID_INSTANCE = ?')->execute([$id]);
            }
            $pdo->prepare('DELETE FROM base_doc_instances WHERE ID = ?')->execute([$id]);
            $done++;
        }
        return ['done' => $done, 'skipped' => $skipped];
    }

    /**
     * @param array{sections:list<array<string,mixed>>} $schema
     * @param array<string,mixed> $payload
     */
    private static function syncIndex(int $id, array $schema, array $payload, string $kind, int $clientId): void
    {
        if (!Database::tableExists('base_doc_field_index')) {
            return;
        }
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM base_doc_field_index WHERE ID_INSTANCE = ?')->execute([$id]);
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO base_doc_field_index (ID_INSTANCE, FIELD_ID, VALUE_STR, KIND, ID_CLIENT) VALUES (?, ?, ?, ?, ?)'
        );
        foreach (DocsSchema::fieldMap($schema) as $fid => $f) {
            if (empty($f['index'])) {
                continue;
            }
            $value = $payload[$fid] ?? null;
            $values = is_array($value) ? $value : [$value];
            foreach ($values as $v) {
                if (!is_scalar($v)) {
                    continue;
                }
                $s = mb_substr(trim((string)$v), 0, 191);
                if ($s === '') {
                    continue;
                }
                // Free-text codes like "L70.0, L71" index each token.
                $tokens = (string)$f['type'] === 'text' ? (preg_split('/\s*[,;]\s*/', $s) ?: [$s]) : [$s];
                foreach ($tokens as $tok) {
                    $tok = trim($tok);
                    if ($tok !== '') {
                        $ins->execute([$id, $fid, $tok, $kind, $clientId]);
                    }
                }
            }
        }
    }

    /** @param array<string,mixed> $payload */
    private static function encodePayload(array $payload): string
    {
        return (string)json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string,mixed> $row */
    private static function normalize(array $row, bool $withPayload): array
    {
        $out = [
            'id' => (int)$row['ID'],
            'id_def' => (int)$row['ID_DEF'],
            'id_version' => (int)$row['ID_VERSION'],
            'kind' => (string)$row['KIND'],
            'id_client' => (int)$row['ID_CLIENT'],
            'id_event' => (int)$row['ID_EVENT'],
            'id_procedure' => (int)$row['ID_PROCEDURE'],
            'id_parent' => (int)$row['ID_PARENT'],
            'status' => (string)$row['STATUS'],
            'hash' => (string)$row['HASH'],
            'hash_ext' => (string)$row['HASH_EXT'],
            'dt_create' => (int)$row['DT_CREATE'],
            'dt_update' => (int)$row['DT_UPDATE'],
            'dt_print' => (int)$row['DT_PRINT'],
            'dt_expire' => (int)$row['DT_EXPIRE'],
            'id_admin_create' => (int)$row['ID_ADMIN_CREATE'],
            'id_admin_print' => (int)$row['ID_ADMIN_PRINT'],
            'id_admin_signed' => (int)$row['ID_ADMIN_SIGNED'],
            'dt_signed' => (int)$row['DT_SIGNED'],
            'def_name' => DocsSeed::localizedName((string)($row['DEF_CODE'] ?? ''), (string)($row['DEF_NAME'] ?? '')),
            'def_code' => (string)($row['DEF_CODE'] ?? ''),
            'client_name' => (string)($row['CLIENT_NAME'] ?? ''),
        ];
        if ($withPayload) {
            $payload = json_decode((string)($row['PAYLOAD'] ?? ''), true);
            $out['payload'] = is_array($payload) ? $payload : [];
            $out['print_snapshot'] = (string)($row['PRINT_SNAPSHOT'] ?? '');
        }
        return $out;
    }
}
