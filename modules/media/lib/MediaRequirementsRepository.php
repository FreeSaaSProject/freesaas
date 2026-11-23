<?php
declare(strict_types=1);

final class MediaRequirementsRepository
{
    /**
     * @return list<array<string,mixed>>
     */
    public static function listForScope(string $scopeTable, int $scopeId, bool $enabledOnly = true): array
    {
        if ($scopeId < 1 || !Database::tableExists('base_media_requirements')) {
            return [];
        }
        $sql = 'SELECT ID AS id, SCOPE_TABLE AS scope_table, SCOPE_ID AS scope_id,
                       KIND AS kind, MIN_COUNT AS min_count, LABEL AS label, ENABLED AS enabled
                FROM base_media_requirements
                WHERE SCOPE_TABLE = ? AND SCOPE_ID = ?';
        if ($enabledOnly) {
            $sql .= ' AND ENABLED = 1';
        }
        $sql .= ' ORDER BY KIND ASC, ID ASC';
        $st = Database::pdo()->prepare($sql);
        $st->execute([$scopeTable, $scopeId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Union of product + procedure_type requirements for a procedure row.
     *
     * @param array<string,mixed> $procedure
     * @return list<array{kind:string,min_count:int,label:string,scope_table:string,scope_id:int}>
     */
    public static function resolveForProcedure(array $procedure): array
    {
        $byKind = [];
        $typeId = (int)($procedure['type'] ?? 0);
        $goodId = (int)($procedure['id_good'] ?? 0);
        foreach (self::listForScope('base_procedure_types', $typeId, true) as $r) {
            self::mergeReq($byKind, $r);
        }
        foreach (self::listForScope('base_products', $goodId, true) as $r) {
            self::mergeReq($byKind, $r);
        }
        return array_values($byKind);
    }

    /**
     * @param array<string,array{kind:string,min_count:int,label:string,scope_table:string,scope_id:int}> $byKind
     * @param array<string,mixed> $r
     */
    private static function mergeReq(array &$byKind, array $r): void
    {
        $kind = (string)($r['kind'] ?? '');
        if ($kind === '' || !in_array($kind, MediaConfig::kinds(), true)) {
            return;
        }
        $min = max(1, (int)($r['min_count'] ?? 1));
        if (!isset($byKind[$kind])) {
            $byKind[$kind] = [
                'kind' => $kind,
                'min_count' => $min,
                'label' => (string)($r['label'] ?? ''),
                'scope_table' => (string)($r['scope_table'] ?? ''),
                'scope_id' => (int)($r['scope_id'] ?? 0),
            ];
            return;
        }
        $byKind[$kind]['min_count'] = max($byKind[$kind]['min_count'], $min);
        $label = trim((string)($r['label'] ?? ''));
        if ($label !== '') {
            $prev = (string)($byKind[$kind]['label'] ?? '');
            if ($prev === '') {
                $byKind[$kind]['label'] = $label;
            } elseif ($prev !== $label) {
                $byKind[$kind]['label'] = $prev . ' · ' . $label;
            }
        }
    }

    /**
     * Director journal of all requirements.
     *
     * @param array{scope_table?:string,scope_id?:int,kind?:string,enabled?:string,q?:string} $filters
     * @return list<array<string,mixed>>
     */
    public static function listAll(array $filters = []): array
    {
        if (!Database::tableExists('base_media_requirements')) {
            return [];
        }
        $sql = 'SELECT ID AS id, SCOPE_TABLE AS scope_table, SCOPE_ID AS scope_id,
                       KIND AS kind, MIN_COUNT AS min_count, LABEL AS label, ENABLED AS enabled
                FROM base_media_requirements WHERE 1=1';
        $params = [];
        $scopeTable = trim((string)($filters['scope_table'] ?? ''));
        if ($scopeTable !== '' && in_array($scopeTable, MediaConfig::scopeTables(), true)) {
            $sql .= ' AND SCOPE_TABLE = ?';
            $params[] = $scopeTable;
        }
        $scopeId = (int)($filters['scope_id'] ?? 0);
        if ($scopeId > 0) {
            $sql .= ' AND SCOPE_ID = ?';
            $params[] = $scopeId;
        }
        $kind = trim((string)($filters['kind'] ?? ''));
        if ($kind !== '' && in_array($kind, MediaConfig::kinds(), true)) {
            $sql .= ' AND KIND = ?';
            $params[] = $kind;
        }
        $enabled = (string)($filters['enabled'] ?? '');
        if ($enabled === '1' || $enabled === '0') {
            $sql .= ' AND ENABLED = ?';
            $params[] = (int)$enabled;
        }
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $sql .= ' AND LABEL LIKE ?';
            $params[] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY SCOPE_TABLE ASC, SCOPE_ID ASC, KIND ASC, ID ASC LIMIT 500';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function upsert(array $data): int
    {
        $scopeTable = (string)($data['scope_table'] ?? '');
        $scopeId = (int)($data['scope_id'] ?? 0);
        $kind = (string)($data['kind'] ?? '');
        if (!in_array($scopeTable, MediaConfig::scopeTables(), true)
            || $scopeId < 1
            || !in_array($kind, MediaConfig::kinds(), true)
        ) {
            throw new InvalidArgumentException(t('media.err.bad_requirement'));
        }
        $min = max(1, min(20, (int)($data['min_count'] ?? 1)));
        $label = mb_substr(trim((string)($data['label'] ?? '')), 0, 512);
        $enabled = !empty($data['enabled']) ? 1 : 0;
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT ID FROM base_media_requirements
             WHERE SCOPE_TABLE = ? AND SCOPE_ID = ? AND KIND = ? LIMIT 1'
        );
        $st->execute([$scopeTable, $scopeId, $kind]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            $u = $pdo->prepare(
                'UPDATE base_media_requirements SET MIN_COUNT = ?, LABEL = ?, ENABLED = ? WHERE ID = ?'
            );
            $u->execute([$min, $label, $enabled, $id]);
            return $id;
        }
        $ins = $pdo->prepare(
            'INSERT INTO base_media_requirements
             (SCOPE_TABLE, SCOPE_ID, KIND, MIN_COUNT, LABEL, ENABLED)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$scopeTable, $scopeId, $kind, $min, $label, $enabled]);
        return (int)$pdo->lastInsertId();
    }

    public static function delete(int $id): void
    {
        if ($id < 1 || !Database::tableExists('base_media_requirements')) {
            return;
        }
        $st = Database::pdo()->prepare('DELETE FROM base_media_requirements WHERE ID = ?');
        $st->execute([$id]);
    }

    public static function deleteByScopeKind(string $scopeTable, int $scopeId, string $kind): void
    {
        if ($scopeId < 1 || !Database::tableExists('base_media_requirements')) {
            return;
        }
        $st = Database::pdo()->prepare(
            'DELETE FROM base_media_requirements WHERE SCOPE_TABLE = ? AND SCOPE_ID = ? AND KIND = ?'
        );
        $st->execute([$scopeTable, $scopeId, $kind]);
    }
}
