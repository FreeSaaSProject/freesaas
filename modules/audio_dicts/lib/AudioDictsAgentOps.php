<?php
declare(strict_types=1);

/**
 * Surface A (/agent/audio_dicts/*) — scripts & dictionaries via agent_token.
 * For remote script-debug without a staff session. See docs/agents/audio-scripts-debug.md.
 */
final class AudioDictsAgentOps
{
    public static function register(): void
    {
        $ops = [
            ['GET', '/agent/audio_dicts/scripts', 'audio_dicts.agent.scripts_list', [self::class, 'scriptsList'], [
                'description' => 'List root scripts (q, include_disabled=0|1)',
                'query' => ['q', 'include_disabled'],
            ]],
            ['GET', '/agent/audio_dicts/script', 'audio_dicts.agent.script_get', [self::class, 'scriptGet'], [
                'description' => 'Root + all parts + fingerprint hash; query id=',
                'query' => ['id'],
            ]],
            ['POST', '/agent/audio_dicts/script', 'audio_dicts.agent.script_save', [self::class, 'scriptSave'], [
                'description' => 'Update root meta + full parts tree; body: id, confirm=1, meta{}, parts[]',
                'body' => ['id', 'confirm', 'meta', 'parts'],
            ]],
            ['POST', '/agent/audio_dicts/script/part', 'audio_dicts.agent.script_part_save', [self::class, 'scriptPartSave'], [
                'description' => 'Upsert one part; body: script_id, part{} (id optional for create)',
                'body' => ['script_id', 'part', 'confirm'],
            ]],
            ['POST', '/agent/audio_dicts/script/part/delete', 'audio_dicts.agent.script_part_delete', [self::class, 'scriptPartDelete'], [
                'description' => 'Delete one part; body: script_id, part_id, confirm=1',
                'body' => ['script_id', 'part_id', 'confirm'],
            ]],
            ['GET', '/agent/audio_dicts/dicts', 'audio_dicts.agent.dicts_list', [self::class, 'dictsList'], [
                'description' => 'List dictionaries (q, include_disabled)',
                'query' => ['q', 'include_disabled'],
            ]],
            ['GET', '/agent/audio_dicts/dict', 'audio_dicts.agent.dict_get', [self::class, 'dictGet'], [
                'description' => 'One dictionary + key count; query id=',
                'query' => ['id'],
            ]],
            ['GET', '/agent/audio_dicts/keys', 'audio_dicts.agent.keys_list', [self::class, 'keysList'], [
                'description' => 'Keys for a dictionary; query dict=, q=, limit=',
                'query' => ['dict', 'q', 'limit', 'offset'],
            ]],
            ['GET', '/agent/audio_dicts/key', 'audio_dicts.agent.key_get', [self::class, 'keyGet'], [
                'description' => 'One key by id= or exact name=',
                'query' => ['id', 'name'],
            ]],
            ['GET', '/agent/audio_dicts/keys/check-overlap', 'audio_dicts.agent.keys_check_overlap', [self::class, 'keysCheckOverlap'], [
                'description' => 'Overlap preview; query name=, optional exclude_id=',
                'query' => ['name', 'exclude_id'],
            ]],
            ['POST', '/agent/audio_dicts/key', 'audio_dicts.agent.key_save', [self::class, 'keySave'], [
                'description' => 'Create/update key; body confirm=1, name, dict (or id_dictonary); id= update; force=1 for hard overlaps',
                'body' => ['confirm', 'id', 'name', 'dict', 'id_dictonary', 'disabled', 'id_looklike', 'force'],
            ]],
            ['POST', '/agent/audio_dicts/key/delete', 'audio_dicts.agent.key_delete', [self::class, 'keyDelete'], [
                'description' => 'Delete key; body id, confirm=1',
                'body' => ['id', 'confirm'],
            ]],
        ];

        foreach ($ops as [$method, $path, $opId, $handler, $meta]) {
            AgentOps::registerModuleOp($method, $path, $opId, $handler, $meta);
        }

        $routes = [
            '/agent/audio_dicts/scripts' => ['GET'],
            '/agent/audio_dicts/script' => ['GET', 'POST'],
            '/agent/audio_dicts/script/part' => ['POST'],
            '/agent/audio_dicts/script/part/delete' => ['POST'],
            '/agent/audio_dicts/dicts' => ['GET'],
            '/agent/audio_dicts/dict' => ['GET'],
            '/agent/audio_dicts/keys' => ['GET'],
            '/agent/audio_dicts/key' => ['GET', 'POST'],
            '/agent/audio_dicts/keys/check-overlap' => ['GET'],
            '/agent/audio_dicts/key/delete' => ['POST'],
        ];
        foreach ($routes as $path => $methods) {
            ModuleManager::registerRoute($path, [
                'module' => 'audio_dicts',
                'public' => true,
                'csrf' => false,
                'methods' => $methods,
                'handler' => static function (): void {
                    require_once base_path('app/agent_http.php');
                },
            ]);
        }
    }

    /** @return array<string,mixed> */
    public static function scriptsList(): array
    {
        $in = AgentOps::requestInput();
        $includeDisabled = !empty($in['include_disabled'])
            && !in_array(strtolower((string)$in['include_disabled']), ['0', 'false', 'no'], true);
        $result = RecordsScriptsRepository::listRoots([
            'q' => trim((string)($in['q'] ?? '')),
            'limit' => 200,
            'offset' => 0,
        ]);
        $items = $result['items'] ?? [];
        if (!$includeDisabled) {
            $items = array_values(array_filter(
                $items,
                static fn (array $r): bool => (int)($r['disabled'] ?? 0) < 1
            ));
        }
        // Drop heavy nested parts summary from list (use script_get for full tree).
        foreach ($items as &$row) {
            unset($row['parts']);
        }
        unset($row);
        return [
            'ok' => true,
            'total' => count($items),
            'items' => $items,
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function scriptGet(): array
    {
        $in = AgentOps::requestInput();
        $id = (int)($in['id'] ?? 0);
        if ($id < 1) {
            return ['ok' => false, 'error' => 'id required', 'http' => 400];
        }
        $tree = RecordsScriptsRepository::findWithParts($id);
        if ($tree === null) {
            return ['ok' => false, 'error' => 'not_found', 'http' => 404];
        }
        $parts = $tree['parts'];
        return [
            'ok' => true,
            'root' => $tree['root'],
            'parts' => $parts,
            'parts_count' => count($parts),
            'fingerprint' => substr(sha1(json_encode($parts) ?: ''), 0, 12),
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function scriptSave(): array
    {
        $in = AgentOps::requestInput();
        if (!self::confirmed($in)) {
            return ['ok' => false, 'error' => 'confirm=1 required for script save', 'http' => 400];
        }
        $id = (int)($in['id'] ?? 0);
        if ($id < 1) {
            return ['ok' => false, 'error' => 'id required', 'http' => 400];
        }
        $tree = RecordsScriptsRepository::findWithParts($id);
        if ($tree === null) {
            return ['ok' => false, 'error' => 'not_found', 'http' => 404];
        }
        $metaIn = is_array($in['meta'] ?? null) ? $in['meta'] : [];
        $meta = array_merge([
            'name' => (string)$tree['root']['name'],
            'disabled' => (int)$tree['root']['disabled'],
            'num' => (int)$tree['root']['num'],
            'id_position' => (string)$tree['root']['id_position'],
            'rating_enabled' => (int)($tree['root']['rating_enabled'] ?? 0),
            'is_hidden' => (int)($tree['root']['is_hidden'] ?? 0),
            'legacy_type_id' => (int)($tree['root']['legacy_type_id'] ?? 0),
            'day_bonus' => (int)($tree['root']['day_bonus'] ?? 0),
            'day_bonus_sum' => (int)($tree['root']['day_bonus_sum'] ?? 0),
            'day_gates' => $tree['root']['day_gates'] ?? [],
            'proc_ratio_min' => (int)($tree['root']['proc_ratio_min'] ?? 0),
        ], $metaIn);

        $parts = is_array($in['parts'] ?? null) ? $in['parts'] : null;
        if ($parts === null) {
            $parts = $tree['parts'];
        }
        try {
            RecordsScriptsRepository::update($id, $meta, $parts);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
        $fresh = RecordsScriptsRepository::findWithParts($id);
        return [
            'ok' => true,
            'message' => 'script_saved',
            'root' => $fresh['root'] ?? null,
            'parts_count' => count($fresh['parts'] ?? []),
            'fingerprint' => substr(sha1(json_encode($fresh['parts'] ?? []) ?: ''), 0, 12),
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function scriptPartSave(): array
    {
        $in = AgentOps::requestInput();
        if (!self::confirmed($in)) {
            return ['ok' => false, 'error' => 'confirm=1 required', 'http' => 400];
        }
        $scriptId = (int)($in['script_id'] ?? 0);
        $part = is_array($in['part'] ?? null) ? $in['part'] : [];
        if ($scriptId < 1 || $part === []) {
            return ['ok' => false, 'error' => 'script_id and part{} required', 'http' => 400];
        }
        try {
            $saved = RecordsScriptsRepository::savePart($scriptId, $part);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
        $fresh = RecordsScriptsRepository::findWithParts($scriptId);
        return [
            'ok' => true,
            'message' => 'part_saved',
            'part' => $saved,
            'fingerprint' => substr(sha1(json_encode($fresh['parts'] ?? []) ?: ''), 0, 12),
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function scriptPartDelete(): array
    {
        $in = AgentOps::requestInput();
        if (!self::confirmed($in)) {
            return ['ok' => false, 'error' => 'confirm=1 required', 'http' => 400];
        }
        $scriptId = (int)($in['script_id'] ?? 0);
        $partId = (int)($in['part_id'] ?? 0);
        if ($scriptId < 1 || $partId < 1) {
            return ['ok' => false, 'error' => 'script_id and part_id required', 'http' => 400];
        }
        try {
            RecordsScriptsRepository::deletePart($scriptId, $partId);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
        $fresh = RecordsScriptsRepository::findWithParts($scriptId);
        return [
            'ok' => true,
            'message' => 'part_deleted',
            'parts_count' => count($fresh['parts'] ?? []),
            'fingerprint' => substr(sha1(json_encode($fresh['parts'] ?? []) ?: ''), 0, 12),
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function dictsList(): array
    {
        $in = AgentOps::requestInput();
        $includeDisabled = !empty($in['include_disabled']);
        $result = RecordsDictsRepository::list([
            'q' => trim((string)($in['q'] ?? '')),
            'limit' => 200,
            'offset' => 0,
        ]);
        $items = $result['items'] ?? [];
        if (!$includeDisabled) {
            $items = array_values(array_filter(
                $items,
                static fn (array $r): bool => (int)($r['disabled'] ?? 0) < 1
            ));
        }
        return [
            'ok' => true,
            'total' => count($items),
            'items' => $items,
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function dictGet(): array
    {
        $in = AgentOps::requestInput();
        $id = (int)($in['id'] ?? 0);
        if ($id < 1) {
            return ['ok' => false, 'error' => 'id required', 'http' => 400];
        }
        $row = RecordsDictsRepository::find($id);
        if ($row === null) {
            return ['ok' => false, 'error' => 'not_found', 'http' => 404];
        }
        $keys = RecordsKeysRepository::list(['dict' => $id, 'limit' => 0]);
        return [
            'ok' => true,
            'dict' => $row,
            'keys_total' => (int)($keys['total'] ?? 0),
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function keysList(): array
    {
        $in = AgentOps::requestInput();
        $dict = (int)($in['dict'] ?? 0);
        if ($dict < 1) {
            return ['ok' => false, 'error' => 'dict= required', 'http' => 400];
        }
        $limit = max(1, min(500, (int)($in['limit'] ?? 100)));
        $offset = max(0, (int)($in['offset'] ?? 0));
        $result = RecordsKeysRepository::list([
            'dict' => $dict,
            'q' => trim((string)($in['q'] ?? '')),
            'limit' => $limit,
            'offset' => $offset,
            'order' => 'k.NAME ASC',
        ]);
        return [
            'ok' => true,
            'dict' => $dict,
            'total' => (int)($result['total'] ?? 0),
            'items' => $result['items'] ?? [],
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function keyGet(): array
    {
        $in = AgentOps::requestInput();
        $id = (int)($in['id'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        $row = null;
        if ($id > 0) {
            $row = RecordsKeysRepository::find($id);
        } elseif ($name !== '') {
            $row = RecordsKeysRepository::findByName($name);
        } else {
            return ['ok' => false, 'error' => 'id= or name= required', 'http' => 400];
        }
        if ($row === null) {
            return ['ok' => false, 'error' => 'not_found', 'http' => 404];
        }
        return ['ok' => true, 'key' => $row, 'http' => 200];
    }

    /** @return array<string,mixed> */
    public static function keysCheckOverlap(): array
    {
        $in = AgentOps::requestInput();
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'error' => 'name= required', 'http' => 400];
        }
        $exclude = (int)($in['exclude_id'] ?? 0);
        $hits = RecordsKeysRepository::findOverlaps($name, $exclude, 40);
        $soft = RecordsKeysRepository::isSoftContainsOnly($hits);
        $hasExact = false;
        foreach ($hits as $ov) {
            if ((string)($ov['relation'] ?? '') === 'exact') {
                $hasExact = true;
                break;
            }
        }
        return [
            'ok' => true,
            'name' => $name,
            'normalized' => RecordsKeysRepository::normalizeName($name),
            'overlaps' => $hits,
            'has_exact' => $hasExact,
            'soft_contains_only' => $soft,
            'force_needed' => $hits !== [] && !$soft && !$hasExact,
            'http' => 200,
        ];
    }

    /**
     * Create or update a key with the same overlap rules as the UI form.
     *
     * @return array<string,mixed>
     */
    public static function keySave(): array
    {
        $in = AgentOps::requestInput();
        if (!self::confirmed($in)) {
            return ['ok' => false, 'error' => 'confirm=1 required', 'http' => 400];
        }
        $id = (int)($in['id'] ?? 0);
        $isNew = $id < 1;
        $existing = $isNew ? null : RecordsKeysRepository::find($id);
        if (!$isNew && $existing === null) {
            return ['ok' => false, 'error' => 'not_found', 'http' => 404];
        }
        try {
            $data = RecordsKeysRepository::dataFromPost([
                'name' => $in['name'] ?? ($existing['name'] ?? ''),
                'disabled' => $in['disabled'] ?? ($existing['disabled'] ?? 0),
                'id_dictonary' => $in['dict'] ?? $in['id_dictonary']
                    ?? ($existing['id_dictonary'] ?? 0),
                'id_looklike' => $in['id_looklike'] ?? ($existing['id_looklike'] ?? 0),
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 400];
        }
        if ((int)$data['id_dictonary'] < 1) {
            return ['ok' => false, 'error' => 'dict (id_dictonary) required', 'http' => 400];
        }
        if (RecordsDictsRepository::find((int)$data['id_dictonary']) === null) {
            return ['ok' => false, 'error' => 'dict_not_found', 'http' => 404];
        }

        $force = !empty($in['force'])
            && !in_array(strtolower((string)$in['force']), ['0', 'false', 'no'], true);
        $overlaps = RecordsKeysRepository::findOverlaps(
            (string)$data['name'],
            $isNew ? 0 : $id,
            40
        );
        $hasExact = false;
        foreach ($overlaps as $ov) {
            if ((string)($ov['relation'] ?? '') === 'exact') {
                $hasExact = true;
                break;
            }
        }
        if ($hasExact) {
            return [
                'ok' => false,
                'error' => 'exact_duplicate',
                'overlaps' => $overlaps,
                'http' => 409,
            ];
        }
        $softOnly = RecordsKeysRepository::isSoftContainsOnly($overlaps);
        if ($overlaps !== [] && !$softOnly && !$force) {
            if ((int)$data['id_looklike'] < 1) {
                $data['id_looklike'] = (int)($overlaps[0]['id'] ?? 0);
            }
            return [
                'ok' => false,
                'error' => 'overlap_force_required',
                'force_needed' => true,
                'overlaps' => $overlaps,
                'hint' => 'Retry with force=1 to create despite nesting; or pick a non-overlapping name',
                'http' => 409,
            ];
        }
        if ($overlaps !== [] && ($force || $softOnly) && (int)$data['id_looklike'] < 1) {
            $data['id_looklike'] = (int)($overlaps[0]['id'] ?? 0);
        }

        try {
            if ($isNew) {
                $id = RecordsKeysRepository::create($data);
            } else {
                RecordsKeysRepository::update($id, $data);
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'http' => 500];
        }
        $row = RecordsKeysRepository::find($id);
        return [
            'ok' => true,
            'message' => $isNew ? 'key_created' : 'key_updated',
            'id' => $id,
            'key' => $row,
            'overlaps' => $overlaps,
            'forced' => $force && $overlaps !== [] && !$softOnly,
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function keyDelete(): array
    {
        $in = AgentOps::requestInput();
        if (!self::confirmed($in)) {
            return ['ok' => false, 'error' => 'confirm=1 required', 'http' => 400];
        }
        $id = (int)($in['id'] ?? 0);
        if ($id < 1) {
            return ['ok' => false, 'error' => 'id required', 'http' => 400];
        }
        if (RecordsKeysRepository::find($id) === null) {
            return ['ok' => false, 'error' => 'not_found', 'http' => 404];
        }
        $n = RecordsKeysRepository::deleteByIds([$id]);
        return [
            'ok' => true,
            'message' => 'key_deleted',
            'deleted' => $n,
            'http' => 200,
        ];
    }

    /** @param array<string,mixed> $in */
    private static function confirmed(array $in): bool
    {
        $c = $in['confirm'] ?? null;
        if ($c === true || $c === 1 || $c === '1') {
            return true;
        }
        return is_string($c) && in_array(strtolower($c), ['1', 'true', 'yes'], true);
    }
}
