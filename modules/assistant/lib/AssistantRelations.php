<?php
declare(strict_types=1);

/**
 * Declarative cross-module consequences for assistant plans (module.json → agent.relations[]).
 *
 * Declared by the dependent module (e.g. workweeks knows shifts collide with vacations):
 *   {
 *     "id": "workweeks.vacation_shifts",
 *     "when": ["vacations.create", "vacations.update"],
 *     "if": {"enabled": "0"},             // optional: literal | "present" | "changed" (vs from row)
 *     "from": "vacations.get",            // optional read op for current row (merged under args, also {from.x})
 *     "from_key": "item",                 // optional, default "item"
 *     "check": "workweeks.list",          // read op run after the write is queued
 *     "args": {"user_id": "{user_id}", "date_from": "{dt_start|date}", "date_to": "{dt_end|date}"},
 *     "suggest": ["workweeks.delete"],    // write ops the model should add as plan steps
 *     "hint": "workweeks.relation.vacation_shifts",
 *     "notice": "workweeks.relation.vacation_shifts.notice",  // optional, {n} = affected rows; shown in plan
 *     "max_rows": 15,                     // optional: above this only warn, no extra plan steps
 *     "fields": ["date", "client_name"]   // optional: row keys shown to the model (id always kept)
 *   }
 *
 * Built-in placeholders: {today}. Filters: |date (→ YYYY-MM-DD).
 */
final class AssistantRelations
{
    private const MAX_ITEMS = 20;
    private const DEFAULT_MAX_ROWS = 15;

    /** @var list<array<string,mixed>>|null */
    private static $cache = null;

    /**
     * @return list<array{
     *   id:string,module:string,when:list<string>,if:array<string,string>,from:string,from_key:string,
     *   check:string,args:array<string,string>,suggest:list<string>,hint:string,notice:string,max_rows:int,
     *   fields:list<string>
     * }>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $out = [];
        foreach (ModuleManager::all() as $moduleId => $meta) {
            if (empty($meta['_enabled'])) {
                continue;
            }
            foreach (self::declared($meta) as $rel) {
                $out[] = $rel;
            }
        }
        self::$cache = $out;
        return $out;
    }

    /**
     * Normalized relations from one module manifest (enabled or not — for validators).
     *
     * @param array<string,mixed> $meta
     * @return list<array<string,mixed>>
     */
    public static function declared(array $meta): array
    {
        $raw = $meta['agent']['relations'] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        $moduleId = (string)($meta['id'] ?? '');
        $out = [];
        foreach ($raw as $row) {
            $rel = self::normalize($row, $moduleId);
            if ($rel !== null) {
                $out[] = $rel;
            }
        }
        return $out;
    }

    /**
     * Placeholder names used by a relation's args (without filters), e.g. ["user_id", "from.date"].
     *
     * @param array<string,mixed> $rel
     * @return list<string>
     */
    public static function placeholders(array $rel): array
    {
        $out = [];
        foreach ((array)($rel['args'] ?? []) as $raw) {
            if (preg_match('/^\{([a-zA-Z0-9_.]+)(?:\|[a-z]+)?\}$/', (string)$raw, $m)) {
                $out[] = $m[1];
            }
        }
        return $out;
    }

    /**
     * Prompt lines for relations whose trigger op is in the catalog.
     *
     * @param array<string,bool> $opIds
     * @return list<string>
     */
    public static function promptHints(array $opIds): array
    {
        $lines = [];
        foreach (self::all() as $rel) {
            $hit = false;
            foreach ($rel['when'] as $w) {
                if (isset($opIds[$w])) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
            $suggest = array_values(array_filter($rel['suggest'], static function (string $id) use ($opIds): bool {
                return isset($opIds[$id]);
            }));
            $lines[] = t('assistant.relations.prompt', [
                'when' => implode('|', $rel['when']),
                'check' => $rel['check'],
                'suggest' => $suggest !== [] ? implode(', ', $suggest) : '—',
                'hint' => self::text($rel['hint']),
            ]);
        }
        return $lines;
    }

    /**
     * Run related checks for a write about to be queued.
     *
     * @param array<string,mixed> $args write args as queued
     * @param callable(string,array<string,mixed>):array{ok:bool,data:?array,error:string} $invokeRead
     * @param array<string,bool> $catalogIds op ids the model may call in this turn
     * @param array<string,bool> $seen rows already reported this turn (check op + row id); updated in place
     * @return list<array<string,mixed>>
     */
    public static function inspect(
        string $opId,
        array $args,
        callable $invokeRead,
        array $catalogIds,
        array &$seen = []
    ): array {
        $out = [];
        $fromCache = [];
        foreach (self::all() as $rel) {
            if (!in_array($opId, $rel['when'], true)) {
                continue;
            }

            $fromItem = null;
            if ($rel['from'] !== '' && isset($catalogIds[$rel['from']])) {
                $cacheKey = $rel['from'] . '|' . $rel['from_key'];
                if (!array_key_exists($cacheKey, $fromCache)) {
                    $from = $invokeRead($rel['from'], $args);
                    $item = is_array($from['data'] ?? null) ? ($from['data'][$rel['from_key']] ?? null) : null;
                    $fromCache[$cacheKey] = !empty($from['ok']) && is_array($item) ? $item : null;
                }
                $fromItem = $fromCache[$cacheKey];
            }

            $cond = self::conditionMet($rel['if'], $args, $fromItem);
            if ($cond === false) {
                continue;
            }

            $entry = [
                'relation' => $rel['id'],
                'hint' => self::text($rel['hint']),
                'check' => $rel['check'],
            ];
            if ($cond === null) {
                $entry['check_skipped'] = true;
                $entry['note'] = t('assistant.relations.check_skipped', [
                    'check' => $rel['check'],
                    'vars' => implode(', ', array_keys($rel['if'])),
                ]);
                $out[] = $entry;
                continue;
            }
            if (!isset($catalogIds[$rel['check']])) {
                $entry['check_unavailable'] = true;
                $entry['note'] = t('assistant.relations.check_unavailable', ['op' => $rel['check']]);
                $out[] = $entry;
                continue;
            }

            $vars = self::vars($args, $fromItem);
            $rendered = self::renderArgs($rel['args'], $vars);
            if ($rendered['missing'] !== []) {
                $entry['check_skipped'] = true;
                $entry['note'] = t('assistant.relations.check_skipped', [
                    'check' => $rel['check'],
                    'vars' => implode(', ', $rendered['missing']),
                ]);
                $out[] = $entry;
                continue;
            }
            $checkArgs = $rendered['args'];
            $res = $invokeRead($rel['check'], $checkArgs);
            if (empty($res['ok'])) {
                $entry['error'] = (string)($res['error'] ?? 'check failed');
                $entry['note'] = t('assistant.relations.check_failed', [
                    'check' => $rel['check'],
                    'error' => $entry['error'],
                ]);
                $out[] = $entry;
                continue;
            }

            $data = is_array($res['data'] ?? null) ? $res['data'] : [];
            $items = self::extractItems($data);
            $fresh = [];
            foreach ($items as $it) {
                $rid = isset($it['id']) && is_scalar($it['id']) ? (string)$it['id'] : '';
                if ($rid !== '') {
                    $key = $rel['check'] . '#' . $rid;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                }
                $fresh[] = $it;
            }
            if ($items !== [] && $fresh === []) {
                // Every row already reported by another relation this turn.
                continue;
            }
            $count = $items !== []
                ? count($fresh)
                : (isset($data['total']) && is_numeric($data['total']) ? (int)$data['total'] : 0);

            $entry['check_args'] = $checkArgs;
            $entry['count'] = $count;
            $entry['items'] = self::slimItems($fresh, $rel['fields']);
            if ($count > 0) {
                if ($rel['notice'] !== '') {
                    $entry['notice'] = t($rel['notice'], ['n' => (string)$count]);
                }
                $suggest = [];
                $unavailable = [];
                foreach ($rel['suggest'] as $s) {
                    if (isset($catalogIds[$s])) {
                        $suggest[] = $s;
                    } else {
                        $unavailable[] = $s;
                    }
                }
                if ($count > $rel['max_rows']) {
                    $entry['too_many'] = true;
                    $entry['note'] = t('assistant.relations.too_many', [
                        'n' => (string)$count,
                        'max' => (string)$rel['max_rows'],
                    ]);
                } else {
                    $entry['suggest'] = $suggest;
                    if ($unavailable !== []) {
                        $entry['suggest_unavailable'] = $unavailable;
                        $entry['note'] = t('assistant.relations.suggest_unavailable', [
                            'ops' => implode(', ', $unavailable),
                        ]);
                    }
                }
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * True when the model should get one more round to add related plan steps.
     *
     * @param list<array<string,mixed>> $related
     */
    public static function needsFollowup(array $related): bool
    {
        foreach ($related as $r) {
            if ((int)($r['count'] ?? 0) > 0 && !empty($r['suggest'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Human lines for the plan preview (consequences + checks that could not run).
     *
     * @param list<array<string,mixed>> $related
     * @return list<string>
     */
    public static function previewLines(array $related): array
    {
        $lines = [];
        foreach ($related as $r) {
            foreach (['notice', 'note'] as $k) {
                $s = trim((string)($r[$k] ?? ''));
                if ($s !== '' && !in_array('⚠ ' . $s, $lines, true)) {
                    $lines[] = '⚠ ' . $s;
                }
            }
        }
        return $lines;
    }

    /** Test seam: drop cached relations (module enable/disable within one process). */
    public static function reset(): void
    {
        self::$cache = null;
    }

    /**
     * true = applies, false = not applicable, null = cannot decide (missing from row).
     *
     * @param array<string,string> $if
     * @param array<string,mixed> $args
     * @param array<string,mixed>|null $fromItem
     */
    public static function conditionMet(array $if, array $args, ?array $fromItem): ?bool
    {
        foreach ($if as $key => $want) {
            $has = array_key_exists($key, $args) && $args[$key] !== null && $args[$key] !== '';
            $raw = $has ? $args[$key] : null;
            $val = is_bool($raw) ? ($raw ? '1' : '0') : (is_scalar($raw) ? (string)$raw : '');
            if ($want === 'present') {
                if (!$has) {
                    return false;
                }
                continue;
            }
            if ($want === 'changed') {
                if (!$has) {
                    return false;
                }
                if ($fromItem === null || !array_key_exists($key, $fromItem)) {
                    return null;
                }
                if ((string)$fromItem[$key] === $val) {
                    return false;
                }
                continue;
            }
            if (!$has || $val !== $want) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,string> $tpl
     * @param array<string,mixed> $vars
     * @return array{args:array<string,string>,missing:list<string>}
     */
    public static function renderArgs(array $tpl, array $vars): array
    {
        $out = [];
        $missing = [];
        foreach ($tpl as $key => $raw) {
            if (!preg_match('/^\{([a-zA-Z0-9_.]+)(?:\|([a-z]+))?\}$/', $raw, $m)) {
                $out[$key] = $raw;
                continue;
            }
            $val = $vars[$m[1]] ?? null;
            if ($val === null || $val === '' || !is_scalar($val)) {
                $missing[] = $m[1];
                continue;
            }
            $val = (string)$val;
            if (($m[2] ?? '') === 'date') {
                $val = self::toDate($val);
                if ($val === '') {
                    $missing[] = $m[1];
                    continue;
                }
            }
            $out[$key] = $val;
        }
        return ['args' => $out, 'missing' => $missing];
    }

    /**
     * @param array<string,mixed> $args
     * @param array<string,mixed>|null $fromItem
     * @return array<string,mixed>
     */
    public static function vars(array $args, ?array $fromItem): array
    {
        $vars = $fromItem !== null ? array_merge($fromItem, self::nonEmpty($args)) : $args;
        if ($fromItem !== null) {
            foreach ($fromItem as $k => $v) {
                if (is_scalar($v)) {
                    $vars['from.' . $k] = $v;
                }
            }
        }
        $vars['today'] = date('Y-m-d');
        return $vars;
    }

    private static function text(string $key): string
    {
        if ($key === '') {
            return '';
        }
        $txt = t($key);
        return $txt !== $key ? $txt : '';
    }

    /**
     * @param mixed $row
     * @return array<string,mixed>|null
     */
    private static function normalize($row, string $moduleId): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $when = [];
        foreach ((array)($row['when'] ?? []) as $w) {
            $w = trim((string)$w);
            if ($w !== '') {
                $when[] = $w;
            }
        }
        $check = trim((string)($row['check'] ?? ''));
        if ($when === [] || $check === '') {
            return null;
        }
        $args = [];
        if (is_array($row['args'] ?? null)) {
            foreach ($row['args'] as $k => $v) {
                if (is_scalar($v)) {
                    $args[(string)$k] = (string)$v;
                }
            }
        }
        $if = [];
        if (is_array($row['if'] ?? null)) {
            foreach ($row['if'] as $k => $v) {
                if (is_scalar($v)) {
                    $if[(string)$k] = (string)$v;
                }
            }
        }
        $suggest = [];
        foreach ((array)($row['suggest'] ?? []) as $s) {
            $s = trim((string)$s);
            if ($s !== '') {
                $suggest[] = $s;
            }
        }
        $fields = [];
        foreach ((array)($row['fields'] ?? []) as $f) {
            $f = trim((string)$f);
            if ($f !== '') {
                $fields[] = $f;
            }
        }
        $id = trim((string)($row['id'] ?? ''));
        $maxRows = (int)($row['max_rows'] ?? 0);
        return [
            'id' => $id !== '' ? $id : ($moduleId . '.' . $check),
            'module' => $moduleId,
            'when' => $when,
            'if' => $if,
            'from' => trim((string)($row['from'] ?? '')),
            'from_key' => trim((string)($row['from_key'] ?? '')) ?: 'item',
            'check' => $check,
            'args' => $args,
            'suggest' => $suggest,
            'hint' => trim((string)($row['hint'] ?? '')),
            'notice' => trim((string)($row['notice'] ?? '')),
            'max_rows' => $maxRows > 0 ? $maxRows : self::DEFAULT_MAX_ROWS,
            'fields' => $fields,
        ];
    }

    private static function toDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (ctype_digit($raw)) {
            return date('Y-m-d', (int)$raw);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        $ts = strtotime($raw);
        return $ts !== false ? date('Y-m-d', $ts) : '';
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function nonEmpty(array $args): array
    {
        return array_filter($args, static function ($v): bool {
            return $v !== null && $v !== '';
        });
    }

    /**
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    private static function extractItems(array $data): array
    {
        foreach (['items', 'rows', 'shifts', 'events', 'orders'] as $k) {
            if (is_array($data[$k] ?? null)) {
                return array_values(array_filter($data[$k], 'is_array'));
            }
        }
        return [];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param list<string> $fields preferred keys (relation "fields"); empty = first scalars
     * @return list<array<string,scalar>>
     */
    private static function slimItems(array $items, array $fields = []): array
    {
        $out = [];
        foreach (array_slice($items, 0, self::MAX_ITEMS) as $row) {
            $slim = [];
            if ($fields !== []) {
                foreach (array_merge(['id'], $fields) as $k) {
                    if (array_key_exists($k, $row) && is_scalar($row[$k])) {
                        $slim[$k] = $row[$k];
                    }
                }
            } else {
                foreach ($row as $k => $v) {
                    if (is_scalar($v) && count($slim) < 12) {
                        $slim[(string)$k] = $v;
                    }
                }
            }
            $out[] = $slim;
        }
        return $out;
    }
}
