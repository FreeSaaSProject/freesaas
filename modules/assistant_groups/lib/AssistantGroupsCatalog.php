<?php
declare(strict_types=1);

/**
 * Build a tool catalog for group drafts from group_ops allowlist.
 * Skips Auth::can so requesters without write rights can still queue capability-pending.
 */
final class AssistantGroupsCatalog
{
    /**
     * @return list<array<string,mixed>>
     */
    public static function opsForGroup(): array
    {
        $patterns = AssistantGroupsConfig::groupOpsPatterns();
        if ($patterns === []) {
            return [];
        }
        $out = [];
        foreach (AgentHttp::allOps() as $op) {
            if (empty($op['assistant'])) {
                continue;
            }
            $id = (string)($op['id'] ?? '');
            if ($id === '' || !self::matches($id, $patterns)) {
                continue;
            }
            if (!isset(ModuleManager::routes()[(string)$op['path']])) {
                continue;
            }
            $side = (string)($op['side_effect'] ?? 'read');
            if ($side !== 'read' && $side !== 'write') {
                $method = strtoupper((string)($op['method'] ?? 'GET'));
                $side = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? 'write' : 'read';
            }
            $params = class_exists('AssistantTools', false)
                ? self::parametersViaTools($op)
                : ['type' => 'object', 'properties' => new stdClass()];
            $desc = (string)($op['description'] ?? $id);
            if ($side === 'write') {
                $desc .= ' [CALL NOW to queue; manager must Confirm — do not claim it ran]';
            }
            $out[] = [
                'id' => $id,
                'method' => (string)$op['method'],
                'path' => (string)$op['path'],
                'permission' => (string)($op['permission'] ?? ''),
                'description' => $desc,
                'side_effect' => $side,
                'parameters' => $params,
                'function_name' => AssistantTools::opIdToFunctionName($id),
                'module' => (string)($op['module'] ?? ''),
                'group_draft' => true,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp($a['id'], $b['id']);
        });
        return $out;
    }

    public static function isOpAllowed(string $opId): bool
    {
        return self::matches($opId, AssistantGroupsConfig::groupOpsPatterns());
    }

    /**
     * Settings UI catalog: enabled modules' assistant ops, grouped, with checked state.
     *
     * @return list<array{
     *   module:string,
     *   title:string,
     *   star:bool,
     *   ops:list<array{id:string,description:string,side_effect:string,permission:string,checked:bool}>
     * }>
     */
    public static function settingsCatalog(): array
    {
        $patterns = AssistantGroupsConfig::groupOpsPatterns();
        $byMod = [];
        foreach (AgentHttp::allOps() as $op) {
            if (empty($op['assistant'])) {
                continue;
            }
            $id = (string)($op['id'] ?? '');
            $module = (string)($op['module'] ?? '');
            if ($id === '' || $module === '') {
                continue;
            }
            if (!isset(ModuleManager::routes()[(string)($op['path'] ?? '')])) {
                continue;
            }
            $side = (string)($op['side_effect'] ?? 'read');
            if ($side !== 'read' && $side !== 'write') {
                $method = strtoupper((string)($op['method'] ?? 'GET'));
                $side = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? 'write' : 'read';
            }
            if (!isset($byMod[$module])) {
                $byMod[$module] = [];
            }
            $byMod[$module][] = [
                'id' => $id,
                'description' => (string)($op['description'] ?? $id),
                'side_effect' => $side,
                'permission' => (string)($op['permission'] ?? ''),
                'checked' => self::matches($id, $patterns),
            ];
        }
        ksort($byMod, SORT_STRING);
        $out = [];
        foreach ($byMod as $module => $ops) {
            usort($ops, static function (array $a, array $b): int {
                return strcmp($a['id'], $b['id']);
            });
            $starPattern = $module . '.*';
            $star = in_array($starPattern, $patterns, true);
            if (!$star && $ops !== []) {
                $all = true;
                foreach ($ops as $o) {
                    if (empty($o['checked'])) {
                        $all = false;
                        break;
                    }
                }
                // Only treat as star if pattern was explicit; keep individuals checked as-is.
                unset($all);
            }
            $title = $module;
            $meta = ModuleManager::get($module);
            if (is_array($meta)) {
                $nameKey = (string)($meta['name'] ?? '');
                if ($nameKey !== '') {
                    $translated = t($nameKey);
                    if ($translated !== '' && $translated !== $nameKey) {
                        $title = $translated;
                    }
                }
            }
            $out[] = [
                'module' => $module,
                'title' => $title,
                'star' => $star,
                'ops' => $ops,
            ];
        }
        return $out;
    }

    /**
     * Compress checkbox POST into group_ops CSV (module.* when whole module selected).
     *
     * @param array<string,mixed> $post
     */
    public static function patternsFromPost(array $post): string
    {
        $modules = [];
        if (isset($post['group_ops_module']) && is_array($post['group_ops_module'])) {
            foreach ($post['group_ops_module'] as $m) {
                $m = trim((string)$m);
                if ($m !== '' && preg_match('/^[a-z0-9_]+$/i', $m)) {
                    $modules[$m] = true;
                }
            }
        }
        $ids = [];
        if (isset($post['group_ops_id']) && is_array($post['group_ops_id'])) {
            foreach ($post['group_ops_id'] as $id) {
                $id = trim((string)$id);
                if ($id === '' || !preg_match('/^[a-z0-9_.-]+$/i', $id)) {
                    continue;
                }
                $mod = strpos($id, '.') !== false ? explode('.', $id, 2)[0] : '';
                if ($mod !== '' && isset($modules[$mod])) {
                    continue; // covered by module.*
                }
                $ids[$id] = true;
            }
        }
        $parts = [];
        foreach (array_keys($modules) as $m) {
            $parts[] = $m . '.*';
        }
        foreach (array_keys($ids) as $id) {
            $parts[] = $id;
        }
        // Optional advanced line: keep only patterns not already covered.
        $extra = trim((string)($post['group_ops_extra'] ?? ''));
        if ($extra !== '') {
            $knownIds = [];
            foreach (AgentHttp::allOps() as $op) {
                if (!empty($op['assistant'])) {
                    $knownIds[(string)($op['id'] ?? '')] = true;
                }
            }
            foreach (preg_split('/[\s,;]+/', $extra) ?: [] as $p) {
                $p = trim((string)$p);
                if ($p === '') {
                    continue;
                }
                if (substr($p, -2) === '.*') {
                    $mod = substr($p, 0, -2);
                    if ($mod !== '' && isset($modules[$mod])) {
                        continue;
                    }
                    $parts[] = $p;
                    continue;
                }
                if (isset($ids[$p]) || isset($knownIds[$p])) {
                    // Known catalog id: only keep if it was checked (already in $ids).
                    continue;
                }
                $parts[] = $p;
            }
        }
        return implode(',', array_values(array_unique($parts)));
    }

    /**
     * Patterns that are not a selected module.* and not a known assistant op id
     * (shown in the advanced field).
     *
     * @return list<string>
     */
    public static function orphanPatterns(): array
    {
        $patterns = AssistantGroupsConfig::groupOpsPatterns();
        if ($patterns === []) {
            return [];
        }
        $known = [];
        foreach (AgentHttp::allOps() as $op) {
            if (!empty($op['assistant'])) {
                $known[(string)($op['id'] ?? '')] = true;
            }
        }
        $out = [];
        foreach ($patterns as $p) {
            if (substr($p, -2) === '.*') {
                continue; // module stars live in checkboxes
            }
            if (isset($known[$p])) {
                continue;
            }
            $out[] = $p;
        }
        return $out;
    }

    /**
     * @param list<string> $patterns
     */
    public static function matches(string $opId, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if ($p === $opId) {
                return true;
            }
            if (substr($p, -2) === '.*') {
                $prefix = substr($p, 0, -1); // keep trailing dot of "mod."
                if ($prefix !== '' && strpos($opId, $prefix) === 0) {
                    return true;
                }
            }
            if (substr($p, -1) === '*' && strlen($p) > 1) {
                $prefix = substr($p, 0, -1);
                if ($prefix !== '' && strpos($opId, $prefix) === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $op
     * @return array<string,mixed>
     */
    private static function parametersViaTools(array $op): array
    {
        // Mirror AssistantTools::availableOps parameter build via a tiny reflection-free path:
        // reuse toLlmTools on a one-op list if public builders are not available.
        $side = (string)($op['side_effect'] ?? 'read');
        $params = ['type' => 'object', 'properties' => new stdClass()];
        if (isset($op['parameters']) && is_array($op['parameters'])) {
            $params = $op['parameters'];
        }
        unset($side);
        return $params;
    }
}
