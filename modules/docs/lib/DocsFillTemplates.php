<?php
declare(strict_types=1);

/**
 * Default phrases for visit medcard sheets: clinic (ID_ADMIN=0) and per-doctor.
 * Applied only to empty fields after LLM/manual input (never overwrites).
 */
final class DocsFillTemplates
{
    /** Field types that may carry default text/values. */
    public const FILLABLE_TYPES = ['text', 'textarea', 'number', 'date', 'checkbox', 'select', 'multiselect'];

    public static function tableReady(): bool
    {
        return Database::tableExists('base_doc_fill_templates');
    }

    /** Visit-sheet definitions that support fill templates. */
    public static function isVisitKind(string $kind): bool
    {
        return $kind === 'medcard_visit';
    }

    /**
     * @return array{id:int,id_def:int,id_admin:int,fields:array<string,mixed>,enabled:bool,dt_update:int}|null
     */
    public static function find(int $defId, int $adminId): ?array
    {
        if ($defId < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, ID_DEF, ID_ADMIN, FIELDS_JSON, ENABLED, DT_UPDATE
             FROM base_doc_fill_templates WHERE ID_DEF = ? AND ID_ADMIN = ? LIMIT 1'
        );
        $st->execute([$defId, max(0, $adminId)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::normalize($row) : null;
    }

    /**
     * Fields map for a template (empty array when missing or disabled).
     *
     * @return array<string,mixed>
     */
    public static function fieldsFor(int $defId, int $adminId): array
    {
        $row = self::find($defId, $adminId);
        if ($row === null || !$row['enabled']) {
            return [];
        }
        return $row['fields'];
    }

    /**
     * Upsert template. $fields is filtered to schema field ids of the def's current version.
     *
     * @param array<string,mixed> $fields
     * @return array{id:int,id_def:int,id_admin:int,fields:array<string,mixed>,enabled:bool,dt_update:int}
     */
    public static function save(int $defId, int $adminId, array $fields, bool $enabled = true): array
    {
        if (!self::tableReady()) {
            throw new RuntimeException(t('docs.fill.err.not_ready'));
        }
        $def = DocsDefsRepository::find($defId);
        if ($def === null) {
            throw new InvalidArgumentException(t('docs.def.err.not_found', ['id' => (string)$defId]));
        }
        if (!self::isVisitKind((string)$def['kind'])) {
            throw new InvalidArgumentException(t('docs.fill.err.not_visit'));
        }
        $ver = DocsDefsRepository::currentVersion($def);
        $fieldMap = $ver !== null ? DocsSchema::fieldMap($ver['schema']) : [];
        $clean = self::sanitizeFields($fields, $fieldMap);
        $adminId = max(0, $adminId);
        $now = time();
        $pdo = Database::pdo();
        $existing = self::find($defId, $adminId);
        if ($existing !== null) {
            $pdo->prepare(
                'UPDATE base_doc_fill_templates SET FIELDS_JSON = ?, ENABLED = ?, DT_UPDATE = ? WHERE ID = ?'
            )->execute([
                (string)json_encode($clean, JSON_UNESCAPED_UNICODE),
                $enabled ? 1 : 0,
                $now,
                (int)$existing['id'],
            ]);
            return self::find($defId, $adminId) ?? $existing;
        }
        $pdo->prepare(
            'INSERT INTO base_doc_fill_templates (ID_DEF, ID_ADMIN, FIELDS_JSON, ENABLED, DT_UPDATE)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $defId,
            $adminId,
            (string)json_encode($clean, JSON_UNESCAPED_UNICODE),
            $enabled ? 1 : 0,
            $now,
        ]);
        $row = self::find($defId, $adminId);
        if ($row === null) {
            throw new RuntimeException(t('docs.fill.err.not_ready'));
        }
        return $row;
    }

    /**
     * Resolve gap values: doctor template then clinic. Does not write.
     *
     * @param array<string,array<string,mixed>> $fieldMap
     * @param array<string,mixed> $payload current instance payload
     * @param array<string,mixed> $incoming already decided (LLM/manual) — treated as filled
     * @return array{fields:array<string,mixed>,scopes:array<string,string>} scopes: field_id => doctor|clinic
     */
    public static function resolveGaps(
        int $defId,
        array $fieldMap,
        array $payload,
        array $incoming,
        int $doctorId
    ): array {
        $out = ['fields' => [], 'scopes' => []];
        if ($defId < 1 || !self::tableReady()) {
            return $out;
        }
        $doctor = $doctorId > 0 ? self::fieldsFor($defId, $doctorId) : [];
        $clinic = self::fieldsFor($defId, 0);
        if ($doctor === [] && $clinic === []) {
            return $out;
        }
        foreach ($fieldMap as $fid => $f) {
            $fid = (string)$fid;
            if ($fid === 'doctor_id' || $fid === 'talk_segments') {
                continue;
            }
            if (!in_array((string)($f['type'] ?? ''), self::FILLABLE_TYPES, true)) {
                continue;
            }
            $have = array_key_exists($fid, $incoming) ? $incoming[$fid] : ($payload[$fid] ?? null);
            if (!DocsSchema::isEmpty($f, $have)) {
                continue;
            }
            if ($doctor !== [] && array_key_exists($fid, $doctor) && !DocsSchema::isEmpty($f, $doctor[$fid])) {
                $out['fields'][$fid] = $doctor[$fid];
                $out['scopes'][$fid] = 'doctor';
                continue;
            }
            if ($clinic !== [] && array_key_exists($fid, $clinic) && !DocsSchema::isEmpty($f, $clinic[$fid])) {
                $out['fields'][$fid] = $clinic[$fid];
                $out['scopes'][$fid] = 'clinic';
            }
        }
        return $out;
    }

    /**
     * Write gap fields into the instance (direct) or propose when already confirmed.
     *
     * @return array{filled:int,via:string,fields:array<string,mixed>}
     */
    public static function applyGaps(int $instanceId, int $doctorId): array
    {
        $empty = ['filled' => 0, 'via' => '', 'fields' => []];
        $row = DocsInstancesRepository::find($instanceId);
        if ($row === null) {
            throw new InvalidArgumentException(t('docs.err.not_found', ['id' => (string)$instanceId]));
        }
        if (!DocsConfig::canSeeKind((string)$row['kind'])) {
            throw new RuntimeException(t('docs.err.no_medical'));
        }
        if (!self::isVisitKind((string)$row['kind'])) {
            return $empty;
        }
        if (in_array((string)$row['status'], ['printed', 'void'], true)) {
            throw new InvalidArgumentException(t('docs.err.locked'));
        }
        $ver = DocsDefsRepository::version((int)$row['id_version']);
        if ($ver === null) {
            return $empty;
        }
        $fieldMap = DocsSchema::fieldMap($ver['schema']);
        $gaps = self::resolveGaps((int)$row['id_def'], $fieldMap, $row['payload'], [], $doctorId);
        if ($gaps['fields'] === []) {
            return $empty;
        }

        $confirmed = (int)($row['dt_confirmed'] ?? 0) > 0;
        if ($confirmed && class_exists('DocsProposals', false)) {
            $res = DocsProposals::propose([
                'instance_id' => $instanceId,
                'fields' => $gaps['fields'],
                'source' => 'fill_template',
                'confidence' => 1,
            ]);
            return [
                'filled' => (int)$res['proposed'],
                'via' => 'propose',
                'fields' => $gaps['fields'],
            ];
        }

        $changed = [];
        foreach ($gaps['fields'] as $fid => $value) {
            $scope = (string)($gaps['scopes'][$fid] ?? 'clinic');
            $one = DocsInstancesRepository::updateFields(
                $instanceId,
                [$fid => $value],
                'fill_template',
                ['scope' => $scope]
            );
            foreach ($one as $c) {
                $changed[] = $c;
            }
        }
        return [
            'filled' => count($changed),
            'via' => 'direct',
            'fields' => $gaps['fields'],
        ];
    }

    /**
     * Merge LLM/incoming fields with template gaps (LLM wins). Returns full field map to write.
     *
     * @param array<string,array<string,mixed>> $fieldMap
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $incoming
     * @return array{fields:array<string,mixed>,scopes:array<string,string>}
     */
    public static function withGaps(
        int $defId,
        array $fieldMap,
        array $payload,
        array $incoming,
        int $doctorId
    ): array {
        $gaps = self::resolveGaps($defId, $fieldMap, $payload, $incoming, $doctorId);
        return [
            'fields' => array_merge($gaps['fields'], $incoming),
            'scopes' => $gaps['scopes'],
        ];
    }

    /**
     * @param array<string,mixed> $fields
     * @param array<string,array<string,mixed>> $fieldMap
     * @return array<string,mixed>
     */
    public static function sanitizeFields(array $fields, array $fieldMap): array
    {
        $out = [];
        foreach ($fields as $fid => $raw) {
            $fid = (string)$fid;
            if (!isset($fieldMap[$fid])) {
                continue;
            }
            if ($fid === 'doctor_id' || $fid === 'talk_segments') {
                continue;
            }
            $f = $fieldMap[$fid];
            if (!in_array((string)($f['type'] ?? ''), self::FILLABLE_TYPES, true)) {
                continue;
            }
            $value = DocsSchema::normalizeValue($f, $raw);
            if (DocsSchema::isEmpty($f, $value)) {
                continue;
            }
            $out[$fid] = $value;
        }
        return $out;
    }

    /**
     * Who may edit a template for this admin id.
     */
    public static function canEdit(int $adminId): bool
    {
        if ($adminId === 0) {
            return Auth::can('docs.manage');
        }
        if (!Auth::can('docs.edit') || !Auth::can('docs.medical')) {
            return false;
        }
        return (int)(Auth::userId() ?? 0) === $adminId || Auth::capability('SUPERADMIN_ACCESS');
    }

    /**
     * Visit defs for picker UI (enabled, kind medcard_visit).
     *
     * @return list<array{id:int,code:string,name:string}>
     */
    public static function visitDefs(): array
    {
        $out = [];
        foreach (DocsDefsRepository::listAll(true) as $def) {
            if (!self::isVisitKind((string)$def['kind'])) {
                continue;
            }
            $out[] = [
                'id' => (int)$def['id'],
                'code' => (string)$def['code'],
                'name' => (string)$def['name'],
            ];
        }
        return $out;
    }

    /**
     * Field catalog for agents/LLM: ids that may appear in fill[] (labels, types, select options).
     *
     * @param array{sections:list<array<string,mixed>>} $schema
     * @return list<array{id:string,type:string,label:string,options?:list<array{value:string,label:string}>}>
     */
    public static function fillableCatalog(array $schema): array
    {
        $out = [];
        foreach (DocsSchema::fieldMap($schema) as $fid => $f) {
            $fid = (string)$fid;
            if ($fid === 'doctor_id' || $fid === 'talk_segments') {
                continue;
            }
            $type = (string)($f['type'] ?? 'text');
            if (!in_array($type, self::FILLABLE_TYPES, true)) {
                continue;
            }
            $entry = [
                'id' => $fid,
                'type' => $type,
                'label' => (string)($f['label'] ?? $fid),
            ];
            if (in_array($type, ['select', 'multiselect'], true)) {
                $opts = [];
                foreach ((array)($f['options'] ?? []) as $o) {
                    if (!is_array($o)) {
                        continue;
                    }
                    $v = trim((string)($o['value'] ?? ''));
                    if ($v === '') {
                        continue;
                    }
                    $opts[] = ['value' => $v, 'label' => (string)($o['label'] ?? $v)];
                }
                $entry['options'] = $opts;
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,id_def:int,id_admin:int,fields:array<string,mixed>,enabled:bool,dt_update:int}
     */
    private static function normalize(array $row): array
    {
        $fields = json_decode((string)($row['FIELDS_JSON'] ?? ''), true);
        return [
            'id' => (int)$row['ID'],
            'id_def' => (int)$row['ID_DEF'],
            'id_admin' => (int)$row['ID_ADMIN'],
            'fields' => is_array($fields) ? $fields : [],
            'enabled' => !empty($row['ENABLED']),
            'dt_update' => (int)$row['DT_UPDATE'],
        ];
    }
}
