<?php
declare(strict_types=1);

/**
 * Immutable published script trees (as-of collect / day reports).
 *
 * Working copy stays in base_records_scripts. Collect hydrates from TREE_JSON
 * of the version resolved for a timestamp — not from the live draft.
 */
final class ScriptVersionsRepository
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUPERSEDED = 'superseded';

    public const MODE_FROM_DATE = 'from_date';
    public const MODE_REWRITE = 'rewrite';

    /** @var array<string,array<string,mixed>|null> */
    private static $asOfCache = [];

    public static function tableReady(): bool
    {
        return Database::tableExists('base_records_script_versions')
            && Database::tableExists('base_records_scripts')
            && Database::columnExists('base_records_scripts', 'ID_VERSION_CURRENT');
    }

    /**
     * Ensure every root has at least v1 published from its current working tree.
     *
     * @return int number of scripts backfilled
     */
    public static function ensureAllBackfilled(): int
    {
        if (!self::tableReady()) {
            return 0;
        }
        $pdo = Database::pdo();
        $st = $pdo->query(
            'SELECT s.ID FROM base_records_scripts s
             WHERE s.PID = 0
               AND (s.ID_VERSION_CURRENT = 0
                    OR NOT EXISTS (
                        SELECT 1 FROM base_records_script_versions v WHERE v.ID_SCRIPT = s.ID
                    ))'
        );
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $n = 0;
        foreach ($ids as $id) {
            if ($id < 1) {
                continue;
            }
            try {
                self::publishInitial($id);
                $n++;
            } catch (Throwable $e) {
                // Skip broken trees; do not block boot.
            }
        }
        return $n;
    }

    /**
     * Publish v1 (VALID_FROM=0) if the script has no versions yet.
     * No-op when the working-tree root is gone (orphan collection refs).
     */
    public static function ensureBackfilled(int $scriptId): void
    {
        if ($scriptId < 1 || !self::tableReady()) {
            return;
        }
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_records_script_versions WHERE ID_SCRIPT = ?'
        );
        $st->execute([$scriptId]);
        if ((int)$st->fetchColumn() > 0) {
            return;
        }
        // Deleted roots still appear via historical collections — never throw here.
        if (RecordsScriptsRepository::findWithParts($scriptId) === null) {
            return;
        }
        try {
            self::publishInitial($scriptId);
        } catch (Throwable $e) {
            // Leave without a version; asOf/current return null.
        }
    }

    /**
     * @return array{
     *   id:int,id_script:int,version:int,valid_from:int,status:string,
     *   tree:array{root:array<string,mixed>,parts:list<array<string,mixed>>},
     *   tree_hash:string,dt_create:int,id_user:int
     * }|null
     */
    public static function find(int $versionId): ?array
    {
        if ($versionId < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, ID_SCRIPT, VERSION, VALID_FROM, STATUS, TREE_JSON, TREE_HASH, DT_CREATE, ID_USER
             FROM base_records_script_versions WHERE ID = ? LIMIT 1'
        );
        $st->execute([$versionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalizeRow($row) : null;
    }

    /**
     * @return list<array{
     *   id:int,id_script:int,version:int,valid_from:int,status:string,
     *   tree_hash:string,dt_create:int,id_user:int,
     *   diff_lines:list<string>,diff_vs:int
     * }>
     */
    public static function listForScript(int $scriptId): array
    {
        if ($scriptId < 1 || !self::tableReady()) {
            return [];
        }
        self::ensureBackfilled($scriptId);
        $st = Database::pdo()->prepare(
            'SELECT ID, ID_SCRIPT, VERSION, VALID_FROM, STATUS, TREE_JSON, TREE_HASH, DT_CREATE, ID_USER
             FROM base_records_script_versions
             WHERE ID_SCRIPT = ?
             ORDER BY VERSION ASC, ID ASC'
        );
        $st->execute([$scriptId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $asc = [];
        $prevTree = null;
        $prevVersion = 0;
        $prevStatus = null;
        foreach ($rows as $row) {
            $tree = self::decodeTree((string)($row['TREE_JSON'] ?? ''));
            $validFrom = (int)$row['VALID_FROM'];
            $status = (string)$row['STATUS'];
            $diffLines = [];
            if ($prevTree === null) {
                $diffLines[] = t('audio_dicts.scripts.versions.diff.initial');
            } else {
                $diffLines = self::diffSummaryLines($prevTree, $tree);
                // VALID_FROM is shown in its own column — do not repeat in the diff.
                if ($prevStatus !== null && (string)$prevStatus !== $status) {
                    $diffLines[] = t('audio_dicts.scripts.versions.diff.status', [
                        'from' => t('audio_dicts.scripts.versions.status.' . (string)$prevStatus),
                        'to' => t('audio_dicts.scripts.versions.status.' . $status),
                    ]);
                }
                if ($diffLines === []) {
                    $diffLines[] = t('audio_dicts.scripts.versions.diff.none');
                }
            }
            $asc[] = [
                'id' => (int)$row['ID'],
                'id_script' => (int)$row['ID_SCRIPT'],
                'version' => (int)$row['VERSION'],
                'valid_from' => $validFrom,
                'status' => $status,
                'tree_hash' => (string)$row['TREE_HASH'],
                'dt_create' => (int)$row['DT_CREATE'],
                'id_user' => (int)$row['ID_USER'],
                'diff_lines' => $diffLines,
                'diff_vs' => $prevVersion,
            ];
            $prevTree = $tree;
            $prevVersion = (int)$row['VERSION'];
            $prevStatus = $status;
        }
        return array_reverse($asc);
    }

    private static function formatValidFromLabel(int $ts): string
    {
        if ($ts <= 0) {
            return t('audio_dicts.scripts.versions.from_epoch');
        }
        return date('Y-m-d H:i', $ts);
    }

    /**
     * Restore working tree from a published version, then publish a new version
     * (git-revert style — history kept). Default publish mode = rewrite.
     *
     * @param array{mode?:string,valid_from?:int,valid_from_date?:string,publish?:bool} $opts
     * @return array{restored_from:int,published?:array<string,mixed>,dirty:bool}
     */
    public static function restore(int $scriptId, int $versionId, array $opts = []): array
    {
        if ($scriptId < 1 || $versionId < 1 || !self::tableReady()) {
            throw new RuntimeException(t('audio_dicts.scripts.versions.err.not_ready'));
        }
        $ver = self::find($versionId);
        if ($ver === null || (int)$ver['id_script'] !== $scriptId) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.versions.err.version_not_found', [
                'id' => (string)$versionId,
            ]));
        }
        $tree = $ver['tree'];
        RecordsScriptsRepository::replaceFromVersionTree(
            $scriptId,
            $tree['root'],
            $tree['parts']
        );
        self::clearRequestCache();

        $doPublish = !isset($opts['publish']) || self::truthy($opts['publish']);
        $published = null;
        if ($doPublish) {
            $pubOpts = ['mode' => (string)($opts['mode'] ?? self::MODE_REWRITE)];
            if (array_key_exists('valid_from', $opts)) {
                $pubOpts['valid_from'] = $opts['valid_from'];
            }
            if (isset($opts['valid_from_date']) && (string)$opts['valid_from_date'] !== '') {
                $pubOpts['valid_from_date'] = (string)$opts['valid_from_date'];
            }
            $published = self::publish($scriptId, $pubOpts);
        }

        return [
            'restored_from' => (int)$ver['version'],
            'published' => $published,
            'dirty' => self::isDirty($scriptId),
        ];
    }

    /** @param mixed $v */
    private static function truthy($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (int)$v !== 0;
        }
        return in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Human-readable change lines: $from → $to (canonical trees).
     *
     * @param array{root:array<string,mixed>,parts:list<array<string,mixed>>} $from
     * @param array{root:array<string,mixed>,parts:list<array<string,mixed>>} $to
     * @return list<string>
     */
    public static function diffSummaryLines(array $from, array $to): array
    {
        $lines = [];
        $aRoot = $from['root'] ?? [];
        $bRoot = $to['root'] ?? [];
        $rootKeys = [
            'name' => 'audio_dicts.scripts.versions.diff.root_name',
            'disabled' => 'audio_dicts.scripts.versions.diff.root_disabled',
            'id_position' => 'audio_dicts.scripts.versions.diff.root_positions',
            'rating_enabled' => 'audio_dicts.scripts.versions.diff.root_rating',
            'is_hidden' => 'audio_dicts.scripts.versions.diff.root_hidden',
            'day_bonus' => 'audio_dicts.scripts.versions.diff.root_day_bonus',
            'day_bonus_sum' => 'audio_dicts.scripts.versions.diff.root_day_bonus_sum',
            'proc_ratio_min' => 'audio_dicts.scripts.versions.diff.root_proc_ratio',
        ];
        foreach ($rootKeys as $key => $i18n) {
            $av = $aRoot[$key] ?? null;
            $bv = $bRoot[$key] ?? null;
            if ((string)$av !== (string)$bv) {
                $lines[] = t($i18n);
            }
        }
        $aGates = json_encode($aRoot['day_gates'] ?? [], JSON_UNESCAPED_UNICODE);
        $bGates = json_encode($bRoot['day_gates'] ?? [], JSON_UNESCAPED_UNICODE);
        if ($aGates !== $bGates) {
            $lines[] = t('audio_dicts.scripts.versions.diff.root_day_gates');
        }

        $aParts = [];
        foreach ($from['parts'] ?? [] as $p) {
            $aParts[(int)($p['id'] ?? 0)] = $p;
        }
        $bParts = [];
        foreach ($to['parts'] ?? [] as $p) {
            $bParts[(int)($p['id'] ?? 0)] = $p;
        }
        foreach ($bParts as $pid => $bp) {
            if ($pid < 1) {
                continue;
            }
            $label = trim((string)($bp['name'] ?? '')) !== ''
                ? trim((string)$bp['name'])
                : ('#' . $pid);
            if (!isset($aParts[$pid])) {
                $lines[] = t('audio_dicts.scripts.versions.diff.part_added', ['name' => $label]);
                continue;
            }
            $ap = $aParts[$pid];
            $changes = self::partFieldChanges($ap, $bp);
            if ($changes !== []) {
                $lines[] = t('audio_dicts.scripts.versions.diff.part_changed', [
                    'name' => $label,
                    'fields' => implode(', ', $changes),
                ]);
            }
        }
        foreach ($aParts as $pid => $ap) {
            if ($pid < 1 || isset($bParts[$pid])) {
                continue;
            }
            $label = trim((string)($ap['name'] ?? '')) !== ''
                ? trim((string)$ap['name'])
                : ('#' . $pid);
            $lines[] = t('audio_dicts.scripts.versions.diff.part_removed', ['name' => $label]);
        }

        // Cap UI noise.
        if (count($lines) > 12) {
            $extra = count($lines) - 11;
            $lines = array_slice($lines, 0, 11);
            $lines[] = t('audio_dicts.scripts.versions.diff.more', ['n' => (string)$extra]);
        }
        return $lines;
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return list<string>
     */
    private static function partFieldChanges(array $a, array $b): array
    {
        $labels = [];
        $map = [
            'name' => 'audio_dicts.scripts.versions.diff.field.name',
            'disabled' => 'audio_dicts.scripts.versions.diff.field.disabled',
            'score' => 'audio_dicts.scripts.versions.diff.field.score',
            'score_rate' => 'audio_dicts.scripts.versions.diff.field.score_rate',
            'kpi' => 'audio_dicts.scripts.versions.diff.field.kpi',
            'cond_type' => 'audio_dicts.scripts.versions.diff.field.cond',
            'cond_value' => 'audio_dicts.scripts.versions.diff.field.cond_value',
            'timeout' => 'audio_dicts.scripts.versions.diff.field.timeout',
            'break_gap' => 'audio_dicts.scripts.versions.diff.field.break_gap',
            'diversity' => 'audio_dicts.scripts.versions.diff.field.diversity',
            'instances_min' => 'audio_dicts.scripts.versions.diff.field.instances_min',
            'start_gate' => 'audio_dicts.scripts.versions.diff.field.start_gate',
            'extend_timeout' => 'audio_dicts.scripts.versions.diff.field.extend_timeout',
            'gate_red_max' => 'audio_dicts.scripts.versions.diff.field.gate_red',
            'gate_yellow_max' => 'audio_dicts.scripts.versions.diff.field.gate_yellow',
            'hide_from_reports' => 'audio_dicts.scripts.versions.diff.field.hide',
            'is_req' => 'audio_dicts.scripts.versions.diff.field.trigger',
            'finish' => 'audio_dicts.scripts.versions.diff.field.finish',
            'closer' => 'audio_dicts.scripts.versions.diff.field.closer',
            'break_flag' => 'audio_dicts.scripts.versions.diff.field.break',
            'collection_killer' => 'audio_dicts.scripts.versions.diff.field.killer',
        ];
        foreach ($map as $key => $i18n) {
            if ((string)($a[$key] ?? '') !== (string)($b[$key] ?? '')) {
                $labels[] = t($i18n);
            }
        }
        $aDictIds = array_values(array_unique(array_map(
            'intval',
            is_array($a['dict_ids'] ?? null) ? $a['dict_ids'] : []
        )));
        $bDictIds = array_values(array_unique(array_map(
            'intval',
            is_array($b['dict_ids'] ?? null) ? $b['dict_ids'] : []
        )));
        sort($aDictIds);
        sort($bDictIds);
        if ($aDictIds !== $bDictIds) {
            $labels[] = self::dictIdsDiffLabel($aDictIds, $bDictIds);
        }
        return $labels;
    }

    /**
     * @param list<int> $fromIds
     * @param list<int> $toIds
     */
    private static function dictIdsDiffLabel(array $fromIds, array $toIds): string
    {
        $added = array_values(array_diff($toIds, $fromIds));
        $removed = array_values(array_diff($fromIds, $toIds));
        $names = [];
        if (class_exists('RecordsDictsRepository', false)) {
            $names = RecordsDictsRepository::options(true);
        }
        $fmt = static function (array $ids) use ($names): string {
            $out = [];
            foreach ($ids as $id) {
                $id = (int)$id;
                if ($id < 1) {
                    continue;
                }
                $out[] = isset($names[$id]) && (string)$names[$id] !== ''
                    ? (string)$names[$id]
                    : ('#' . $id);
            }
            return implode(', ', $out);
        };
        $bits = [];
        if ($added !== []) {
            $bits[] = t('audio_dicts.scripts.versions.diff.dicts_added', [
                'names' => $fmt($added),
            ]);
        }
        if ($removed !== []) {
            $bits[] = t('audio_dicts.scripts.versions.diff.dicts_removed', [
                'names' => $fmt($removed),
            ]);
        }
        if ($bits === []) {
            return t('audio_dicts.scripts.versions.diff.field.dicts');
        }
        return t('audio_dicts.scripts.versions.diff.field.dicts') . ': ' . implode('; ', $bits);
    }

    /**
     * Current published pointer (ID_VERSION_CURRENT), or null.
     *
     * @return array{
     *   id:int,id_script:int,version:int,valid_from:int,status:string,
     *   tree:array{root:array<string,mixed>,parts:list<array<string,mixed>>},
     *   tree_hash:string,dt_create:int,id_user:int
     * }|null
     */
    public static function current(int $scriptId): ?array
    {
        if ($scriptId < 1 || !self::tableReady()) {
            return null;
        }
        self::ensureBackfilled($scriptId);
        $st = Database::pdo()->prepare(
            'SELECT ID_VERSION_CURRENT FROM base_records_scripts WHERE ID = ? AND PID = 0 LIMIT 1'
        );
        $st->execute([$scriptId]);
        $vid = (int)$st->fetchColumn();
        if ($vid < 1) {
            return null;
        }
        return self::find($vid);
    }

    /**
     * Resolve published version active at timestamp T (VALID_FROM ≤ T).
     *
     * @return array{
     *   id:int,id_script:int,version:int,valid_from:int,status:string,
     *   tree:array{root:array<string,mixed>,parts:list<array<string,mixed>>},
     *   tree_hash:string,dt_create:int,id_user:int
     * }|null
     */
    public static function asOf(int $scriptId, int $asOfTs): ?array
    {
        if ($scriptId < 1 || !self::tableReady()) {
            return null;
        }
        self::ensureBackfilled($scriptId);
        $asOfTs = max(0, $asOfTs);
        $cacheKey = $scriptId . ':' . $asOfTs;
        if (array_key_exists($cacheKey, self::$asOfCache)) {
            return self::$asOfCache[$cacheKey];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, ID_SCRIPT, VERSION, VALID_FROM, STATUS, TREE_JSON, TREE_HASH, DT_CREATE, ID_USER
             FROM base_records_script_versions
             WHERE ID_SCRIPT = ? AND STATUS = ? AND VALID_FROM <= ?
             ORDER BY VALID_FROM DESC, ID DESC
             LIMIT 1'
        );
        $st->execute([$scriptId, self::STATUS_ACTIVE, $asOfTs]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $norm = $row ? self::normalizeRow($row) : null;
        self::$asOfCache[$cacheKey] = $norm;
        return $norm;
    }

    public static function clearRequestCache(): void
    {
        self::$asOfCache = [];
    }

    /**
     * Canonical content snapshot of the working tree (stable keys, no volatile fields).
     *
     * @return array{root:array<string,mixed>,parts:list<array<string,mixed>>}
     */
    public static function snapshotWorkingTree(int $scriptId): array
    {
        $tree = RecordsScriptsRepository::findWithParts($scriptId);
        if ($tree === null) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.err.not_found', ['id' => $scriptId]));
        }
        return self::canonicalizeTree($tree['root'], $tree['parts']);
    }

    /**
     * @param array{root:array<string,mixed>,parts:list<array<string,mixed>>} $tree
     */
    public static function treeHash(array $tree): string
    {
        return hash('sha256', self::canonicalJson($tree));
    }

    public static function isDirty(int $scriptId): bool
    {
        return self::isDirtyAgainst($scriptId, null);
    }

    /**
     * Working tree differs from a specific version (or from current pointer when $versionId is null).
     */
    public static function isDirtyAgainst(int $scriptId, ?int $versionId): bool
    {
        if ($scriptId < 1 || !self::tableReady()) {
            return false;
        }
        self::ensureBackfilled($scriptId);
        try {
            $snap = self::snapshotWorkingTree($scriptId);
        } catch (Throwable $e) {
            return false;
        }
        $hash = self::treeHash($snap);
        if ($versionId !== null && $versionId > 0) {
            $ver = self::find($versionId);
            if ($ver === null || (int)$ver['id_script'] !== $scriptId) {
                return true;
            }
            return $hash !== (string)$ver['tree_hash'];
        }
        $cur = self::current($scriptId);
        if ($cur === null) {
            return true;
        }
        return $hash !== (string)$cur['tree_hash'];
    }

    /**
     * Overwrite TREE_JSON of an existing version from the current working tree
     * (or from an explicit canonical tree).
     *
     * @param array{root:array<string,mixed>,parts:list<array<string,mixed>>}|null $tree
     * @return array{id:int,version:int,valid_from:int,status:string,unchanged?:bool}
     */
    public static function updateContent(int $scriptId, int $versionId, ?array $tree = null): array
    {
        if ($scriptId < 1 || $versionId < 1 || !self::tableReady()) {
            throw new RuntimeException(t('audio_dicts.scripts.versions.err.not_ready'));
        }
        $ver = self::find($versionId);
        if ($ver === null || (int)$ver['id_script'] !== $scriptId) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.versions.err.version_not_found', [
                'id' => (string)$versionId,
            ]));
        }
        if ($tree === null) {
            $live = RecordsScriptsRepository::findWithParts($scriptId);
            if ($live === null) {
                throw new InvalidArgumentException(t('audio_dicts.scripts.err.not_found', ['id' => $scriptId]));
            }
            $canonical = self::canonicalizeTree($live['root'], $live['parts']);
        } else {
            $canonical = self::canonicalizeTree($tree['root'] ?? [], $tree['parts'] ?? []);
        }
        $hash = self::treeHash($canonical);
        if ((string)$ver['tree_hash'] === $hash) {
            return [
                'id' => (int)$ver['id'],
                'version' => (int)$ver['version'],
                'valid_from' => (int)$ver['valid_from'],
                'status' => (string)$ver['status'],
                'unchanged' => true,
            ];
        }
        Database::pdo()->prepare(
            'UPDATE base_records_script_versions
             SET TREE_JSON = ?, TREE_HASH = ?
             WHERE ID = ? AND ID_SCRIPT = ?'
        )->execute([
            self::canonicalJson($canonical),
            $hash,
            $versionId,
            $scriptId,
        ]);
        // If this is the current pointer, working tree already matches after editor save.
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT ID_VERSION_CURRENT FROM base_records_scripts WHERE ID = ? AND PID = 0 LIMIT 1'
        );
        $st->execute([$scriptId]);
        if ((int)$st->fetchColumn() === $versionId) {
            // Keep pointer; content already on working tree when called after update().
        }
        self::clearRequestCache();
        EventBus::emit('audio_dicts.changed', [
            'entity' => 'script_versions',
            'id' => $scriptId,
            'version_id' => $versionId,
            'mode' => 'update_content',
        ]);
        return [
            'id' => $versionId,
            'version' => (int)$ver['version'],
            'valid_from' => (int)$ver['valid_from'],
            'status' => (string)$ver['status'],
        ];
    }

    /**
     * Change VALID_FROM for a published version (as-of schedule).
     *
     * @return array{id:int,version:int,valid_from:int}
     */
    public static function updateValidFrom(int $scriptId, int $versionId, int $validFrom): array
    {
        if ($scriptId < 1 || $versionId < 1 || !self::tableReady()) {
            throw new RuntimeException(t('audio_dicts.scripts.versions.err.not_ready'));
        }
        $ver = self::find($versionId);
        if ($ver === null || (int)$ver['id_script'] !== $scriptId) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.versions.err.version_not_found', [
                'id' => (string)$versionId,
            ]));
        }
        $validFrom = max(0, $validFrom);
        if ((int)$ver['valid_from'] === $validFrom) {
            return [
                'id' => $versionId,
                'version' => (int)$ver['version'],
                'valid_from' => $validFrom,
            ];
        }
        Database::pdo()->prepare(
            'UPDATE base_records_script_versions SET VALID_FROM = ? WHERE ID = ? AND ID_SCRIPT = ?'
        )->execute([$validFrom, $versionId, $scriptId]);
        self::clearRequestCache();
        EventBus::emit('audio_dicts.changed', [
            'entity' => 'script_versions',
            'id' => $scriptId,
            'version_id' => $versionId,
            'mode' => 'valid_from',
        ]);
        return [
            'id' => $versionId,
            'version' => (int)$ver['version'],
            'valid_from' => $validFrom,
        ];
    }

    /**
     * Load a published version into the working tree (enter / open for edit).
     *
     * @return array{version_id:int,version:int}
     */
    public static function openForEdit(int $scriptId, int $versionId): array
    {
        $ver = self::find($versionId);
        if ($ver === null || (int)$ver['id_script'] !== $scriptId) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.versions.err.version_not_found', [
                'id' => (string)$versionId,
            ]));
        }
        RecordsScriptsRepository::replaceFromVersionTree(
            $scriptId,
            $ver['tree']['root'],
            $ver['tree']['parts']
        );
        self::clearRequestCache();
        return [
            'version_id' => (int)$ver['id'],
            'version' => (int)$ver['version'],
        ];
    }

    /**
     * Save editor POST into a new version or an existing one.
     *
     * @param array{target?:string,version_id?:int,valid_from_date?:string,mode?:string} $opts
     * @return array{target:string,saved:array<string,mixed>}
     */
    public static function saveFromEditor(int $scriptId, array $meta, array $parts, array $opts = []): array
    {
        if ($parts === []) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.err.parts_required'));
        }
        $editingId = (int)($opts['editing_version_id'] ?? 0);
        $baselineBefore = null;
        if ($editingId > 0) {
            $ev = self::find($editingId);
            if ($ev !== null && (int)$ev['id_script'] === $scriptId) {
                $baselineBefore = (string)$ev['tree_hash'];
            }
        } else {
            $cur = self::current($scriptId);
            if ($cur !== null) {
                $baselineBefore = (string)$cur['tree_hash'];
            }
        }

        RecordsScriptsRepository::update($scriptId, $meta, $parts);
        $hash = self::treeHash(self::snapshotWorkingTree($scriptId));
        if ($baselineBefore !== null && $baselineBefore === $hash) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.versions.err.no_changes'));
        }

        $target = (string)($opts['target'] ?? 'new');
        if ($target === 'existing') {
            $versionId = (int)($opts['version_id'] ?? 0);
            if ($versionId < 1) {
                throw new InvalidArgumentException(t('audio_dicts.scripts.versions.err.version_required'));
            }
            $saved = self::updateContent($scriptId, $versionId);
            if (!empty($saved['unchanged'])) {
                throw new InvalidArgumentException(t('audio_dicts.scripts.versions.err.no_changes'));
            }
            // Point current at this version when saving into it (editor context).
            Database::pdo()->prepare(
                'UPDATE base_records_scripts SET ID_VERSION_CURRENT = ? WHERE ID = ? AND PID = 0'
            )->execute([$versionId, $scriptId]);
            self::clearRequestCache();
            return ['target' => 'existing', 'saved' => $saved];
        }
        $mode = (string)($opts['mode'] ?? self::MODE_FROM_DATE);
        $saved = self::publish($scriptId, [
            'mode' => $mode,
            'valid_from_date' => (string)($opts['valid_from_date'] ?? ''),
        ]);
        if (!empty($saved['unchanged'])) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.versions.err.no_changes'));
        }
        return ['target' => 'new', 'saved' => $saved];
    }

    /**
     * Publish working tree.
     *
     * @param array{mode?:string,valid_from?:int,valid_from_date?:string} $opts
     * @return array{id:int,version:int,valid_from:int,status:string,mode:string,unchanged?:bool}
     */
    public static function publish(int $scriptId, array $opts = []): array
    {
        if ($scriptId < 1 || !self::tableReady()) {
            throw new RuntimeException(t('audio_dicts.scripts.versions.err.not_ready'));
        }
        $tree = RecordsScriptsRepository::findWithParts($scriptId);
        if ($tree === null) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.err.not_found', ['id' => $scriptId]));
        }
        $mode = (string)($opts['mode'] ?? self::MODE_FROM_DATE);
        if ($mode !== self::MODE_REWRITE && $mode !== self::MODE_FROM_DATE) {
            $mode = self::MODE_FROM_DATE;
        }

        $canonical = self::canonicalizeTree($tree['root'], $tree['parts']);
        $hash = self::treeHash($canonical);
        $cur = self::current($scriptId);
        if ($cur !== null && (string)$cur['tree_hash'] === $hash && $mode !== self::MODE_REWRITE) {
            // Same content: allow from_date only when VALID_FROM changes meaningfully.
            $validFromSame = self::resolveValidFrom($mode, $opts);
            if ((int)$cur['valid_from'] === $validFromSame && (string)$cur['status'] === self::STATUS_ACTIVE) {
                return [
                    'id' => (int)$cur['id'],
                    'version' => (int)$cur['version'],
                    'valid_from' => (int)$cur['valid_from'],
                    'status' => (string)$cur['status'],
                    'mode' => $mode,
                    'unchanged' => true,
                ];
            }
        }

        $validFrom = self::resolveValidFrom($mode, $opts);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($mode === self::MODE_REWRITE) {
                $pdo->prepare(
                    'UPDATE base_records_script_versions SET STATUS = ? WHERE ID_SCRIPT = ? AND STATUS = ?'
                )->execute([self::STATUS_SUPERSEDED, $scriptId, self::STATUS_ACTIVE]);
                $validFrom = 0;
            }

            $st = $pdo->prepare(
                'SELECT COALESCE(MAX(VERSION), 0) FROM base_records_script_versions WHERE ID_SCRIPT = ?'
            );
            $st->execute([$scriptId]);
            $next = (int)$st->fetchColumn() + 1;

            $json = self::canonicalJson($canonical);
            $pdo->prepare(
                'INSERT INTO base_records_script_versions
                 (ID_SCRIPT, VERSION, VALID_FROM, STATUS, TREE_JSON, TREE_HASH, DT_CREATE, ID_USER)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $scriptId,
                $next,
                $validFrom,
                self::STATUS_ACTIVE,
                $json,
                $hash,
                time(),
                (int)(Auth::userId() ?? 0),
            ]);
            $vid = (int)$pdo->lastInsertId();
            $pdo->prepare(
                'UPDATE base_records_scripts SET ID_VERSION_CURRENT = ? WHERE ID = ? AND PID = 0'
            )->execute([$vid, $scriptId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        self::clearRequestCache();
        EventBus::emit('audio_dicts.changed', [
            'entity' => 'script_versions',
            'id' => $scriptId,
            'version_id' => $vid,
            'mode' => $mode,
        ]);

        return [
            'id' => $vid,
            'version' => $next,
            'valid_from' => $validFrom,
            'status' => self::STATUS_ACTIVE,
            'mode' => $mode,
        ];
    }

    /**
     * Flatten version trees into CollectRunner-shaped maps for a set of script roots.
     * Falls back to live working tree when versions table is missing (pre-migration).
     *
     * @param list<int>|null $onlyScriptIds null = all roots that have an as-of version (or live non-disabled)
     * @return array{
     *   by_id:array<int,array<string,mixed>>,
     *   roots:array<int,array<string,mixed>>,
     *   key_to_parts:array<int,list<array{script:int,part:int}>>,
     *   metric_parts:array<int,list<array<string,mixed>>>,
     *   version_by_script:array<int,int>
     * }
     */
    public static function hydrateForCollect(int $asOfTs, ?array $onlyScriptIds = null): array
    {
        $byId = [];
        $roots = [];
        $keyToParts = [];
        $metricParts = [];
        $versionByScript = [];

        $scriptIds = $onlyScriptIds;
        if ($scriptIds === null) {
            $scriptIds = self::listRootIds();
        } else {
            $scriptIds = array_values(array_filter(array_map('intval', $scriptIds), static fn ($i) => $i > 0));
        }

        $useVersions = self::tableReady();
        foreach ($scriptIds as $scriptId) {
            $tree = null;
            $versionId = 0;
            if ($useVersions) {
                $ver = self::asOf($scriptId, $asOfTs);
                if ($ver !== null) {
                    $tree = $ver['tree'];
                    $versionId = (int)$ver['id'];
                }
            }
            if ($tree === null) {
                $live = RecordsScriptsRepository::findWithParts($scriptId);
                if ($live === null) {
                    continue;
                }
                $tree = ['root' => $live['root'], 'parts' => $live['parts']];
            }

            $root = $tree['root'];
            if ((int)($root['disabled'] ?? 0) >= 1) {
                continue;
            }
            $sid = (int)$root['id'];
            if ($sid < 1) {
                $sid = $scriptId;
                $root['id'] = $sid;
            }
            $rootMeta = self::collectMetaFromRoot($root);
            $byId[$sid] = $rootMeta;
            $roots[$sid] = $rootMeta;
            $metricParts[$sid] = [];
            if ($versionId > 0) {
                $versionByScript[$sid] = $versionId;
            }

            foreach ($tree['parts'] as $part) {
                if ((int)($part['disabled'] ?? 0) >= 1) {
                    continue;
                }
                $pid = (int)($part['id'] ?? 0);
                if ($pid < 1) {
                    continue;
                }
                $meta = self::collectMetaFromPart($part, $sid);
                $byId[$pid] = $meta;
                $cond = (string)($meta['cond_type'] ?? '');
                $dicts = self::parseIds((string)($meta['ids_dictonarys'] ?? ''));
                if ($cond !== '' && self::isCollectMetricCond($cond) && $dicts === []) {
                    $metricParts[$sid][] = $meta;
                }
            }
        }

        // Live key → dict membership (dicts themselves are not versioned).
        if (Database::tableExists('base_records_keys') && $byId !== []) {
            $keys = Database::pdo()->query(
                'SELECT ID, ID_DICTONARY FROM base_records_keys WHERE DISABLED < 1'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $keysByDict = [];
            foreach ($keys as $k) {
                $did = (int)$k['ID_DICTONARY'];
                if ($did > 0) {
                    $keysByDict[$did][] = (int)$k['ID'];
                }
            }
            foreach ($byId as $partId => $meta) {
                if ((int)($meta['pid'] ?? 0) < 1) {
                    continue;
                }
                $cond = trim((string)($meta['cond_type'] ?? ''));
                if ($cond !== '') {
                    continue;
                }
                $dicts = self::parseIds((string)($meta['ids_dictonarys'] ?? ''));
                $scriptId = (int)$meta['pid'];
                foreach ($dicts as $did) {
                    foreach ($keysByDict[$did] ?? [] as $keyId) {
                        $keyToParts[$keyId][] = ['script' => $scriptId, 'part' => (int)$partId];
                    }
                }
            }
        }

        return [
            'by_id' => $byId,
            'roots' => $roots,
            'key_to_parts' => $keyToParts,
            'metric_parts' => $metricParts,
            'version_by_script' => $versionByScript,
        ];
    }

    /**
     * Tree shaped for day-quality / calendar (normalized root + parts, enabled only).
     *
     * @return list<array<string,mixed>> roots with embedded parts[]
     */
    public static function loadScriptsAsOf(int $asOfTs): array
    {
        $out = [];
        foreach (self::listRootIds() as $scriptId) {
            $tree = null;
            if (self::tableReady()) {
                $ver = self::asOf($scriptId, $asOfTs);
                if ($ver !== null) {
                    $tree = $ver['tree'];
                }
            }
            if ($tree === null) {
                $live = RecordsScriptsRepository::findWithParts($scriptId);
                if ($live === null) {
                    continue;
                }
                $tree = ['root' => $live['root'], 'parts' => $live['parts']];
            }
            $root = $tree['root'];
            if ((int)($root['disabled'] ?? 0) >= 1) {
                continue;
            }
            $parts = [];
            foreach ($tree['parts'] as $p) {
                if ((int)($p['disabled'] ?? 0) >= 1) {
                    continue;
                }
                $parts[] = $p;
            }
            $root['parts'] = $parts;
            $out[] = $root;
        }
        return $out;
    }

    /** @return list<int> */
    private static function listRootIds(): array
    {
        if (!Database::tableExists('base_records_scripts')) {
            return [];
        }
        // Include disabled roots so as-of can still resolve historical publishes;
        // hydrate/load filter disabled content from the chosen tree.
        $st = Database::pdo()->query('SELECT ID FROM base_records_scripts WHERE PID = 0 ORDER BY ID ASC');
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private static function publishInitial(int $scriptId): int
    {
        $tree = RecordsScriptsRepository::findWithParts($scriptId);
        if ($tree === null) {
            return 0;
        }
        $canonical = self::canonicalizeTree($tree['root'], $tree['parts']);
        $hash = self::treeHash($canonical);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM base_records_script_versions WHERE ID_SCRIPT = ?'
            );
            $st->execute([$scriptId]);
            if ((int)$st->fetchColumn() > 0) {
                $pdo->commit();
                $st = $pdo->prepare(
                    'SELECT ID_VERSION_CURRENT FROM base_records_scripts WHERE ID = ? AND PID = 0 LIMIT 1'
                );
                $st->execute([$scriptId]);
                return (int)$st->fetchColumn();
            }
            $pdo->prepare(
                'INSERT INTO base_records_script_versions
                 (ID_SCRIPT, VERSION, VALID_FROM, STATUS, TREE_JSON, TREE_HASH, DT_CREATE, ID_USER)
                 VALUES (?, 1, 0, ?, ?, ?, ?, ?)'
            )->execute([
                $scriptId,
                self::STATUS_ACTIVE,
                self::canonicalJson($canonical),
                $hash,
                time(),
                (int)(Auth::userId() ?? 0),
            ]);
            $vid = (int)$pdo->lastInsertId();
            $pdo->prepare(
                'UPDATE base_records_scripts SET ID_VERSION_CURRENT = ? WHERE ID = ? AND PID = 0'
            )->execute([$vid, $scriptId]);
            $pdo->commit();
            self::clearRequestCache();
            return $vid;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $opts
     */
    private static function resolveValidFrom(string $mode, array $opts): int
    {
        if ($mode === self::MODE_REWRITE) {
            return 0;
        }
        if (isset($opts['valid_from'])) {
            return max(0, (int)$opts['valid_from']);
        }
        $date = trim((string)($opts['valid_from_date'] ?? ''));
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $tz = date_default_timezone_get() ?: 'UTC';
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' 00:00:00', new DateTimeZone($tz));
            if ($dt instanceof DateTimeImmutable) {
                return $dt->getTimestamp();
            }
        }
        // Default: start of today in app timezone.
        $today = (new DateTimeImmutable('today'))->getTimestamp();
        return max(0, $today);
    }

    /**
     * @param array<string,mixed> $root
     * @param list<array<string,mixed>> $parts
     * @return array{root:array<string,mixed>,parts:list<array<string,mixed>>}
     */
    public static function canonicalizeTree(array $root, array $parts): array
    {
        $rootOut = [
            'id' => (int)($root['id'] ?? 0),
            'name' => (string)($root['name'] ?? ''),
            'disabled' => (int)($root['disabled'] ?? 0),
            'num' => (int)($root['num'] ?? 0),
            'id_position' => (string)($root['id_position'] ?? ''),
            'position_ids' => array_values(array_map('intval', is_array($root['position_ids'] ?? null)
                ? $root['position_ids']
                : RecordsScriptsRepository::parseIds((string)($root['id_position'] ?? '')))),
            'rating_enabled' => (int)($root['rating_enabled'] ?? 0),
            'is_hidden' => (int)($root['is_hidden'] ?? 0),
            'legacy_type_id' => (int)($root['legacy_type_id'] ?? 0),
            'day_bonus' => (int)($root['day_bonus'] ?? 0),
            'day_bonus_sum' => (int)($root['day_bonus_sum'] ?? 0),
            'day_gates' => is_array($root['day_gates'] ?? null) ? array_values($root['day_gates']) : [],
            'proc_ratio_min' => (int)($root['proc_ratio_min'] ?? 0),
        ];
        $partsOut = [];
        foreach ($parts as $p) {
            $dictIds = is_array($p['dict_ids'] ?? null)
                ? array_values(array_map('intval', $p['dict_ids']))
                : RecordsScriptsRepository::parseIds((string)($p['ids_dictonarys'] ?? ''));
            $roles = is_array($p['roles'] ?? null) ? array_values($p['roles']) : ['ordinary'];
            $partsOut[] = [
                'id' => (int)($p['id'] ?? 0),
                'pid' => (int)($p['pid'] ?? (int)($root['id'] ?? 0)),
                'num' => (int)($p['num'] ?? 0),
                'name' => (string)($p['name'] ?? ''),
                'disabled' => (int)($p['disabled'] ?? 0),
                'is_req' => (int)($p['is_req'] ?? 0),
                'finish' => (int)($p['finish'] ?? 0),
                'closer' => (int)($p['closer'] ?? 0),
                'break_flag' => (int)($p['break_flag'] ?? 0),
                'collection_killer' => (int)($p['collection_killer'] ?? 0),
                'ids_dictonarys' => (string)($p['ids_dictonarys'] ?? RecordsScriptsRepository::joinIds($dictIds)),
                'dict_ids' => $dictIds,
                'score' => (int)($p['score'] ?? 0),
                'score_rate' => (int)($p['score_rate'] ?? 0),
                'kpi' => (int)($p['kpi'] ?? 0),
                'cond_type' => (string)($p['cond_type'] ?? ''),
                'cond_value' => (int)($p['cond_value'] ?? 0),
                'start_gate' => max(0, min(2, (int)($p['start_gate'] ?? 0))),
                'extend_timeout' => array_key_exists('extend_timeout', $p)
                    ? (!empty($p['extend_timeout']) ? 1 : 0)
                    : 1,
                'timeout' => (int)($p['timeout'] ?? 0),
                'break_gap' => max(0, (int)($p['break_gap'] ?? 120)),
                'diversity' => (int)($p['diversity'] ?? 0),
                'instances_min' => (int)($p['instances_min'] ?? 0),
                'gate_red_max' => max(0.0, (float)($p['gate_red_max'] ?? 0)),
                'gate_yellow_max' => max(0.0, (float)($p['gate_yellow_max'] ?? 0)),
                'hide_from_reports' => !empty($p['hide_from_reports']) ? 1 : 0,
                'id_position' => (string)($p['id_position'] ?? ''),
                'roles' => $roles,
                'role' => (string)($roles[0] ?? 'ordinary'),
            ];
        }
        usort($partsOut, static function (array $a, array $b): int {
            $c = $a['num'] <=> $b['num'];
            return $c !== 0 ? $c : ($a['id'] <=> $b['id']);
        });
        return ['root' => $rootOut, 'parts' => $partsOut];
    }

    /** @param mixed $data */
    private static function canonicalJson($data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '{}';
    }

    /**
     * @param array<string,mixed> $row
     * @return array{
     *   id:int,id_script:int,version:int,valid_from:int,status:string,
     *   tree:array{root:array<string,mixed>,parts:list<array<string,mixed>>},
     *   tree_hash:string,dt_create:int,id_user:int
     * }
     */
    private static function normalizeRow(array $row): array
    {
        $tree = self::decodeTree((string)($row['TREE_JSON'] ?? ''));
        return [
            'id' => (int)$row['ID'],
            'id_script' => (int)$row['ID_SCRIPT'],
            'version' => (int)$row['VERSION'],
            'valid_from' => (int)$row['VALID_FROM'],
            'status' => (string)$row['STATUS'],
            'tree' => $tree,
            'tree_hash' => (string)($row['TREE_HASH'] ?? ''),
            'dt_create' => (int)($row['DT_CREATE'] ?? 0),
            'id_user' => (int)($row['ID_USER'] ?? 0),
        ];
    }

    /**
     * @return array{root:array<string,mixed>,parts:list<array<string,mixed>>}
     */
    private static function decodeTree(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['root']) || !is_array($decoded['root'])) {
            return ['root' => ['id' => 0, 'name' => '', 'disabled' => 1, 'parts' => []], 'parts' => []];
        }
        $parts = isset($decoded['parts']) && is_array($decoded['parts']) ? $decoded['parts'] : [];
        return self::canonicalizeTree($decoded['root'], $parts);
    }

    /**
     * @param array<string,mixed> $root
     * @return array<string,mixed>
     */
    private static function collectMetaFromRoot(array $root): array
    {
        return [
            'id' => (int)$root['id'],
            'pid' => 0,
            'name' => (string)($root['name'] ?? ''),
            'is_req' => 0,
            'finish' => 0,
            'closer' => 0,
            'score' => 0,
            'score_rate' => 0,
            'break' => 0,
            'timeout' => 0,
            'diversity' => 0,
            'instances_min' => 0,
            'killer' => 0,
            'kpi' => 0,
            'cond_type' => '',
            'cond_value' => 0,
            'start_gate' => 0,
            'extend_timeout' => 1,
            'break_gap' => 120,
            'ids_dictonarys' => '',
            'id_position' => (string)($root['id_position'] ?? ''),
            'rating_enabled' => (int)($root['rating_enabled'] ?? 0),
            'is_hidden' => (int)($root['is_hidden'] ?? 0),
            'day_bonus' => (int)($root['day_bonus'] ?? 0),
            'day_bonus_sum' => (int)($root['day_bonus_sum'] ?? 0),
            'day_gates' => is_array($root['day_gates'] ?? null) ? $root['day_gates'] : [],
            'proc_ratio_min' => (int)($root['proc_ratio_min'] ?? 0),
            'disabled' => (int)($root['disabled'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $part
     * @return array<string,mixed>
     */
    private static function collectMetaFromPart(array $part, int $scriptId): array
    {
        $dictIds = is_array($part['dict_ids'] ?? null)
            ? $part['dict_ids']
            : self::parseIds((string)($part['ids_dictonarys'] ?? ''));
        $idsRaw = (string)($part['ids_dictonarys'] ?? '');
        if ($idsRaw === '' && $dictIds !== []) {
            $idsRaw = RecordsScriptsRepository::joinIds(array_map('intval', $dictIds));
        }
        return [
            'id' => (int)$part['id'],
            'pid' => $scriptId,
            'name' => (string)($part['name'] ?? ''),
            'is_req' => (int)($part['is_req'] ?? 0),
            'finish' => (int)($part['finish'] ?? 0),
            'closer' => (int)($part['closer'] ?? 0),
            'score' => (int)($part['score'] ?? 0),
            'score_rate' => (int)($part['score_rate'] ?? 0),
            'break' => (int)($part['break_flag'] ?? 0),
            'timeout' => (int)($part['timeout'] ?? 0),
            'diversity' => (int)($part['diversity'] ?? 0),
            'instances_min' => (int)($part['instances_min'] ?? 0),
            'killer' => (int)($part['collection_killer'] ?? 0),
            'kpi' => (int)($part['kpi'] ?? 0),
            'cond_type' => (string)($part['cond_type'] ?? ''),
            'cond_value' => (int)($part['cond_value'] ?? 0),
            'start_gate' => max(0, min(2, (int)($part['start_gate'] ?? 0))),
            'extend_timeout' => array_key_exists('extend_timeout', $part)
                ? (!empty($part['extend_timeout']) ? 1 : 0)
                : 1,
            'break_gap' => max(0, (int)($part['break_gap'] ?? 120)),
            'ids_dictonarys' => $idsRaw,
            'id_position' => (string)($part['id_position'] ?? ''),
            'rating_enabled' => 0,
            'is_hidden' => 0,
            'gate_red_max' => max(0.0, (float)($part['gate_red_max'] ?? 0)),
            'gate_yellow_max' => max(0.0, (float)($part['gate_yellow_max'] ?? 0)),
            'hide_from_reports' => !empty($part['hide_from_reports']) ? 1 : 0,
            'disabled' => (int)($part['disabled'] ?? 0),
        ];
    }

    private static function isCollectMetricCond(string $cond): bool
    {
        static $types = [
            'intersect', 'no_intersect',
            'volume_gt', 'volume_lt',
            'strings_gt', 'strings_lt',
        ];
        return in_array($cond, $types, true);
    }

    /** @return list<int> */
    private static function parseIds(string $raw): array
    {
        return RecordsScriptsRepository::parseIds($raw);
    }
}
