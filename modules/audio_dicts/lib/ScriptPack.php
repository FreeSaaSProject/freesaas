<?php
declare(strict_types=1);

/**
 * Portable JSON pack for a script tree + referenced dictionaries/keys.
 * format: freesaas.audio_dicts.script_pack / version 1
 */
final class ScriptPack
{
    public const FORMAT = 'freesaas.audio_dicts.script_pack';
    public const VERSION = 1;
    public const MAX_BYTES = 2097152; // 2 MiB

    /**
     * @param bool $includeKeys When true, embed full dictionaries + keys; default is names only in parts.dicts.
     * @return array<string,mixed>
     */
    public static function export(int $scriptId, bool $includeKeys = false): array
    {
        $tree = RecordsScriptsRepository::findWithParts($scriptId);
        if ($tree === null) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.err.not_found', ['id' => $scriptId]));
        }
        $root = $tree['root'];
        $posNames = self::positionNames((array)($root['position_ids'] ?? []));

        $dictIds = [];
        $partsOut = [];
        foreach ($tree['parts'] as $p) {
            $ids = is_array($p['dict_ids'] ?? null) ? $p['dict_ids'] : [];
            foreach ($ids as $did) {
                $did = (int)$did;
                if ($did > 0) {
                    $dictIds[$did] = true;
                }
            }
            $dictNames = [];
            foreach ($ids as $did) {
                $did = (int)$did;
                if ($did < 1) {
                    continue;
                }
                $d = RecordsDictsRepository::find($did);
                if ($d !== null) {
                    $dictNames[] = (string)$d['name'];
                }
            }
            $roles = is_array($p['roles'] ?? null) ? $p['roles'] : ['ordinary'];
            $partsOut[] = [
                'name' => (string)$p['name'],
                'disabled' => (int)($p['disabled'] ?? 0),
                'roles' => array_values($roles),
                'dicts' => $dictNames,
                'score' => (int)($p['score'] ?? 0),
                'score_rate' => (int)($p['score_rate'] ?? 0),
                'kpi' => (int)($p['kpi'] ?? 0),
                'cond_type' => (string)($p['cond_type'] ?? ''),
                'cond_value' => (int)($p['cond_value'] ?? 0),
                'start_gate' => (int)($p['start_gate'] ?? 0),
                'timeout' => (int)($p['timeout'] ?? 0),
                'extend_timeout' => array_key_exists('extend_timeout', $p)
                    ? (!empty($p['extend_timeout']) ? 1 : 0)
                    : 1,
                'diversity' => (int)($p['diversity'] ?? 0),
                'instances_min' => (int)($p['instances_min'] ?? 0),
                'gate_red_max' => (float)($p['gate_red_max'] ?? 0),
                'gate_yellow_max' => (float)($p['gate_yellow_max'] ?? 0),
                'hide_from_reports' => !empty($p['hide_from_reports']) ? 1 : 0,
            ];
        }

        $dictionaries = [];
        if ($includeKeys) {
            foreach (array_keys($dictIds) as $did) {
                $pack = self::exportDictionary((int)$did);
                if ($pack !== null) {
                    $dictionaries[] = $pack;
                }
            }
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => gmdate('c'),
            'include_keys' => $includeKeys ? 1 : 0,
            'script' => [
                'name' => (string)$root['name'],
                'disabled' => (int)($root['disabled'] ?? 0),
                'positions' => $posNames,
                'rating_enabled' => (int)($root['rating_enabled'] ?? 0),
                'is_hidden' => (int)($root['is_hidden'] ?? 0),
                'day_bonus' => (int)($root['day_bonus'] ?? 0),
                'day_bonus_sum' => (int)($root['day_bonus_sum'] ?? 0),
                'proc_ratio_min' => (int)($root['proc_ratio_min'] ?? 0),
                'parts' => $partsOut,
            ],
            'dictionaries' => $dictionaries,
        ];
    }

    public static function filename(int $scriptId, string $name): string
    {
        $slug = preg_replace('/[^\p{L}\p{N}_-]+/u', '-', mb_strtolower(trim($name), 'UTF-8')) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'script';
        }
        if (mb_strlen($slug) > 48) {
            $slug = mb_substr($slug, 0, 48);
        }
        return 'script-' . $scriptId . '-' . $slug . '.json';
    }

    /**
     * @param array<string,mixed> $pack
     * @return array{id:int,warnings:list<string>}
     */
    public static function import(array $pack, ?string $nameOverride = null): array
    {
        self::validateEnvelope($pack);
        $script = is_array($pack['script'] ?? null) ? $pack['script'] : [];
        $name = trim((string)($nameOverride ?? ''));
        if ($name === '') {
            $name = trim((string)($script['name'] ?? ''));
        }
        if ($name === '') {
            throw new InvalidArgumentException(t('audio_dicts.err.name_required'));
        }
        $warnings = [];

        /** @var array<string,int> name => local dict id */
        $dictByName = [];
        $dictsRaw = is_array($pack['dictionaries'] ?? null) ? $pack['dictionaries'] : [];
        foreach ($dictsRaw as $d) {
            if (!is_array($d)) {
                continue;
            }
            $resolved = self::upsertDictionary($d, $warnings);
            if ($resolved['id'] > 0 && $resolved['name'] !== '') {
                $dictByName[$resolved['name']] = $resolved['id'];
            }
        }

        $posIds = self::resolvePositionIds(
            is_array($script['positions'] ?? null) ? $script['positions'] : [],
            $warnings
        );

        $partsIn = is_array($script['parts'] ?? null) ? $script['parts'] : [];
        $partsPost = ['parts' => []];
        foreach ($partsIn as $row) {
            if (!is_array($row)) {
                continue;
            }
            $partName = trim((string)($row['name'] ?? ''));
            if ($partName === '') {
                continue;
            }
            $dictNames = $row['dicts'] ?? [];
            if (!is_array($dictNames)) {
                $dictNames = [];
            }
            $dictIds = [];
            foreach ($dictNames as $dn) {
                $dn = trim((string)$dn);
                if ($dn === '') {
                    continue;
                }
                if (!isset($dictByName[$dn])) {
                    $existing = self::findDictByName($dn);
                    if ($existing !== null) {
                        $dictByName[$dn] = (int)$existing['id'];
                    } else {
                        // Names-only pack (or missing dict): create empty dictionary.
                        $dictByName[$dn] = RecordsDictsRepository::create([
                            'name' => mb_substr($dn, 0, 50),
                            'descr' => '',
                            'color' => '',
                            'color_background' => '',
                            'disabled' => 0,
                            'is_review_req' => 0,
                            'id_position' => '',
                            'tracking_code' => '',
                        ]);
                    }
                }
                $dictIds[] = $dictByName[$dn];
            }
            $partsPost['parts'][] = [
                'name' => $partName,
                'disabled' => !empty($row['disabled']) ? 1 : 0,
                'roles' => is_array($row['roles'] ?? null) ? $row['roles'] : ['ordinary'],
                'dict_ids' => $dictIds,
                'score' => (int)($row['score'] ?? 0),
                'score_rate' => (int)($row['score_rate'] ?? 0),
                'kpi' => (int)($row['kpi'] ?? 0),
                'cond_type' => (string)($row['cond_type'] ?? ''),
                'cond_value' => (int)($row['cond_value'] ?? 0),
                'start_gate' => (int)($row['start_gate'] ?? 0),
                'timeout' => (int)($row['timeout'] ?? 0),
                'extend_timeout' => array_key_exists('extend_timeout', $row)
                    ? (!empty($row['extend_timeout']) ? 1 : 0)
                    : 1,
                'diversity' => (int)($row['diversity'] ?? 0),
                'instances_min' => (int)($row['instances_min'] ?? 0),
                'gate_red_max' => (float)($row['gate_red_max'] ?? 0),
                'gate_yellow_max' => (float)($row['gate_yellow_max'] ?? 0),
                'hide_from_reports' => !empty($row['hide_from_reports']) ? 1 : 0,
            ];
        }
        $parts = RecordsScriptsRepository::partsFromPost($partsPost);
        if ($parts === []) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.err.parts_required'));
        }

        $meta = [
            'name' => mb_substr($name, 0, 40),
            'disabled' => !empty($script['disabled']) ? 1 : 0,
            'num' => 0,
            'id_position' => RecordsScriptsRepository::joinIds($posIds),
            'rating_enabled' => !empty($script['rating_enabled']) ? 1 : 0,
            'is_hidden' => !empty($script['is_hidden']) ? 1 : 0,
            'legacy_type_id' => 0,
            'day_bonus' => !empty($script['day_bonus']) ? 1 : 0,
            'day_bonus_sum' => max(0, (int)($script['day_bonus_sum'] ?? 0)),
            'proc_ratio_min' => max(0, (int)($script['proc_ratio_min'] ?? 0)),
        ];

        $newId = RecordsScriptsRepository::create($meta, $parts);
        return ['id' => $newId, 'warnings' => $warnings];
    }

    /**
     * @return array<string,mixed>
     */
    public static function decodeJson(string $raw): array
    {
        if (strlen($raw) > self::MAX_BYTES) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.pack.err_too_large'));
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.pack.err_invalid_json'));
        }
        return $decoded;
    }

    /**
     * @param array<string,mixed> $pack
     */
    private static function validateEnvelope(array $pack): void
    {
        $format = (string)($pack['format'] ?? '');
        if ($format !== self::FORMAT) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.pack.err_format'));
        }
        $ver = (int)($pack['version'] ?? 0);
        if ($ver !== self::VERSION) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.pack.err_version', [
                'version' => (string)$ver,
            ]));
        }
        if (!is_array($pack['script'] ?? null)) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.pack.err_no_script'));
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function exportDictionary(int $dictId): ?array
    {
        $d = RecordsDictsRepository::find($dictId);
        if ($d === null) {
            return null;
        }
        $posNames = self::positionNames(
            RecordsScriptsRepository::parseIds((string)($d['id_position'] ?? ''))
        );
        $keys = self::loadKeysForDict($dictId);
        $keyIdToName = [];
        foreach ($keys as $k) {
            $keyIdToName[(int)$k['id']] = (string)$k['name'];
        }
        $keysOut = [];
        foreach ($keys as $k) {
            $lookId = (int)($k['id_looklike'] ?? 0);
            $keysOut[] = [
                'name' => (string)$k['name'],
                'disabled' => (int)($k['disabled'] ?? 0),
                'tags' => self::tagNamesForKey((int)$k['id']),
                'lookalike' => $lookId > 0 ? (string)($keyIdToName[$lookId] ?? '') : '',
            ];
        }
        return [
            'name' => (string)$d['name'],
            'tracking_code' => (string)($d['tracking_code'] ?? ''),
            'descr' => (string)($d['descr'] ?? ''),
            'color' => (string)($d['color'] ?? ''),
            'color_background' => (string)($d['color_background'] ?? ''),
            'is_review_req' => (int)($d['is_review_req'] ?? 0),
            'disabled' => (int)($d['disabled'] ?? 0),
            'positions' => $posNames,
            'keys' => $keysOut,
        ];
    }

    /**
     * @param array<string,mixed> $d
     * @param list<string> $warnings
     * @return array{id:int,name:string}
     */
    private static function upsertDictionary(array $d, array &$warnings): array
    {
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') {
            return ['id' => 0, 'name' => ''];
        }
        $code = trim((string)($d['tracking_code'] ?? ''));
        $existing = null;
        if ($code !== '') {
            $existing = self::findDictByTrackingCode($code);
        }
        if ($existing === null) {
            $existing = self::findDictByName($name);
        }

        $posIds = self::resolvePositionIds(
            is_array($d['positions'] ?? null) ? $d['positions'] : [],
            $warnings
        );
        $posJoined = RecordsScriptsRepository::joinIds($posIds);

        if ($existing !== null) {
            $dictId = (int)$existing['id'];
        } else {
            $dictId = RecordsDictsRepository::create([
                'name' => mb_substr($name, 0, 50),
                'descr' => mb_substr(trim((string)($d['descr'] ?? '')), 0, 100),
                'color' => mb_substr(trim((string)($d['color'] ?? '')), 0, 10),
                'color_background' => mb_substr(trim((string)($d['color_background'] ?? '')), 0, 10),
                'disabled' => !empty($d['disabled']) ? 1 : 0,
                'is_review_req' => !empty($d['is_review_req']) ? 1 : 0,
                'id_position' => $posJoined,
                'tracking_code' => mb_substr($code, 0, 64),
            ]);
        }

        $keysRaw = is_array($d['keys'] ?? null) ? $d['keys'] : [];
        $existingKeys = self::keysByNameForDict($dictId);
        /** @var array<string,int> */
        $nameToId = $existingKeys;
        /** @var list<array{id:int,lookalike:string}> */
        $lookPending = [];

        foreach ($keysRaw as $k) {
            if (!is_array($k)) {
                continue;
            }
            $kname = trim((string)($k['name'] ?? ''));
            if ($kname === '') {
                continue;
            }
            $kname = mb_substr($kname, 0, 255);
            if (isset($nameToId[$kname])) {
                $kid = $nameToId[$kname];
            } else {
                $kid = RecordsKeysRepository::create([
                    'name' => $kname,
                    'disabled' => !empty($k['disabled']) ? 1 : 0,
                    'id_type' => 0,
                    'id_dictonary' => $dictId,
                    'id_looklike' => 0,
                ]);
                $nameToId[$kname] = $kid;
            }
            $tags = is_array($k['tags'] ?? null) ? $k['tags'] : [];
            self::ensureKeyTags($kid, $tags);
            $look = trim((string)($k['lookalike'] ?? ''));
            if ($look !== '') {
                $lookPending[] = ['id' => $kid, 'lookalike' => $look];
            }
        }

        foreach ($lookPending as $lp) {
            $target = $nameToId[$lp['lookalike']] ?? 0;
            if ($target > 0 && $target !== $lp['id']) {
                $row = RecordsKeysRepository::find($lp['id']);
                if ($row !== null && (int)($row['id_looklike'] ?? 0) !== $target) {
                    RecordsKeysRepository::update($lp['id'], [
                        'name' => (string)$row['name'],
                        'disabled' => (int)$row['disabled'],
                        'id_dictonary' => (int)$row['id_dictonary'],
                        'id_looklike' => $target,
                    ]);
                }
            }
        }

        return ['id' => $dictId, 'name' => $name];
    }

    /**
     * @param list<int> $positionIds
     * @return list<string>
     */
    private static function positionNames(array $positionIds): array
    {
        $opts = RecordsScriptsRepository::positionOptions();
        $out = [];
        foreach ($positionIds as $pid) {
            $pid = (int)$pid;
            if ($pid > 0 && isset($opts[$pid])) {
                $out[] = (string)$opts[$pid];
            }
        }
        return $out;
    }

    /**
     * @param list<mixed> $names
     * @param list<string> $warnings
     * @return list<int>
     */
    private static function resolvePositionIds(array $names, array &$warnings): array
    {
        $opts = RecordsScriptsRepository::positionOptions();
        $byName = [];
        foreach ($opts as $id => $label) {
            $byName[(string)$label] = (int)$id;
        }
        $ids = [];
        foreach ($names as $n) {
            $n = trim((string)$n);
            if ($n === '') {
                continue;
            }
            if (!isset($byName[$n])) {
                $warnings[] = t('audio_dicts.scripts.pack.warn_position', ['name' => $n]);
                continue;
            }
            $ids[] = $byName[$n];
        }
        return array_values(array_unique($ids));
    }

    /** @return list<array<string,mixed>> */
    private static function loadKeysForDict(int $dictId): array
    {
        if ($dictId < 1 || !Database::tableExists('base_records_keys')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, NAME AS name, DISABLED AS disabled, ID_LOOKLIKE AS id_looklike
             FROM base_records_keys WHERE ID_DICTONARY = ? ORDER BY ID ASC'
        );
        $st->execute([$dictId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,int> name => id */
    private static function keysByNameForDict(int $dictId): array
    {
        $out = [];
        foreach (self::loadKeysForDict($dictId) as $k) {
            $out[(string)$k['name']] = (int)$k['id'];
        }
        return $out;
    }

    /** @return list<string> */
    private static function tagNamesForKey(int $keyId): array
    {
        if ($keyId < 1
            || !Database::tableExists('base_records_keys_tags_links')
            || !Database::tableExists('base_records_keys_tags')
        ) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT t.NAME AS name
             FROM base_records_keys_tags_links l
             INNER JOIN base_records_keys_tags t ON t.ID = l.ID_TAG
             WHERE l.ID_KEY = ?
             ORDER BY t.NAME ASC'
        );
        $st->execute([$keyId]);
        $names = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $n = trim((string)($row['name'] ?? ''));
            if ($n !== '') {
                $names[] = $n;
            }
        }
        return $names;
    }

    /** @param list<mixed> $tagNames */
    private static function ensureKeyTags(int $keyId, array $tagNames): void
    {
        if ($keyId < 1
            || !Database::tableExists('base_records_keys_tags')
            || !Database::tableExists('base_records_keys_tags_links')
        ) {
            return;
        }
        $pdo = Database::pdo();
        foreach ($tagNames as $tn) {
            $tn = trim((string)$tn);
            if ($tn === '') {
                continue;
            }
            $tn = mb_substr($tn, 0, 100);
            $tagId = self::findOrCreateTagId($tn);
            if ($tagId < 1) {
                continue;
            }
            $chk = $pdo->prepare(
                'SELECT ID FROM base_records_keys_tags_links WHERE ID_KEY = ? AND ID_TAG = ? LIMIT 1'
            );
            $chk->execute([$keyId, $tagId]);
            if ($chk->fetchColumn()) {
                continue;
            }
            $ins = $pdo->prepare(
                'INSERT INTO base_records_keys_tags_links (ID_KEY, ID_TAG, DT_CREATE) VALUES (?,?,?)'
            );
            $ins->execute([$keyId, $tagId, time()]);
        }
    }

    private static function findOrCreateTagId(string $name): int
    {
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_records_keys_tags WHERE NAME = ? LIMIT 1'
        );
        $st->execute([$name]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        return RecordsTagsRepository::create(['name' => $name, 'enabled' => 1]);
    }

    /** @return array<string,mixed>|null */
    private static function findDictByTrackingCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '' || !Database::tableExists('base_records_dictonarys')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, NAME AS name, TRACKING_CODE AS tracking_code
             FROM base_records_dictonarys WHERE TRACKING_CODE = ? LIMIT 1'
        );
        $st->execute([mb_substr($code, 0, 64)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    private static function findDictByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '' || !Database::tableExists('base_records_dictonarys')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, NAME AS name, TRACKING_CODE AS tracking_code
             FROM base_records_dictonarys WHERE NAME = ? LIMIT 1'
        );
        $st->execute([mb_substr($name, 0, 50)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
