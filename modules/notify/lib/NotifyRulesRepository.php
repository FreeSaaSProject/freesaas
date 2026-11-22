<?php
declare(strict_types=1);

/** CRUD for base_notify_rules; JSON columns are normalized to arrays on read. */
final class NotifyRulesRepository
{
    public const KINDS = ['notify', 'confirm'];
    public const TARGET_KINDS = ['chat', 'staff', 'role', 'flag', 'on_shift', 'subject'];
    public const CHANNELS = ['bot', 'email', 'inapp'];
    public const STRATEGIES = ['all', 'first_available'];

    public static function tableReady(): bool
    {
        return Database::tableExists('base_notify_rules');
    }

    /** @return list<array<string,mixed>> */
    public static function listAll(): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $rows = Database::pdo()->query('SELECT * FROM base_notify_rules ORDER BY EVENT ASC, SORT ASC, ID ASC')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([self::class, 'hydrate'], $rows);
    }

    /** @return list<array<string,mixed>> enabled rules for an event, ordered by SORT then ID */
    public static function listEnabledByEvent(string $event): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_notify_rules WHERE EVENT = ? AND ENABLED = 1 ORDER BY SORT ASC, ID ASC'
        );
        $st->execute([$event]);
        return array_map([self::class, 'hydrate'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string,mixed>> */
    public static function listEnabledConfirm(): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $st = Database::pdo()->query(
            "SELECT * FROM base_notify_rules WHERE ENABLED = 1 AND KIND = 'confirm' ORDER BY SORT ASC, ID ASC"
        );
        return array_map([self::class, 'hydrate'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_notify_rules WHERE ID = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::hydrate($row) : null;
    }

    public static function countByEvent(string $event): int
    {
        if (!self::tableReady()) {
            return 0;
        }
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM base_notify_rules WHERE EVENT = ?');
        $st->execute([$event]);
        return (int)$st->fetchColumn();
    }

    /**
     * Insert (id=0) or update. Throws InvalidArgumentException with a translated message.
     *
     * @param array<string,mixed> $in
     */
    public static function save(int $id, array $in): int
    {
        if (!self::tableReady()) {
            throw new RuntimeException(t('notify.err.table'));
        }
        $d = self::normalizeInput($in);
        $now = time();
        if ($id > 0) {
            if (self::find($id) === null) {
                throw new InvalidArgumentException(t('notify.err.rule_not_found'));
            }
            Database::pdo()->prepare(
                'UPDATE base_notify_rules SET NAME=?, EVENT=?, ENABLED=?, SORT=?, STOP_ON_MATCH=?, KIND=?,
                 CONDITIONS_JSON=?, TARGETS_JSON=?, TEMPLATE=?, STAGES_JSON=?, QUIET_FROM=?, QUIET_TO=?, DTUPDATE=?
                 WHERE ID=?'
            )->execute([
                $d['name'], $d['event'], $d['enabled'], $d['sort'], $d['stop_on_match'], $d['kind'],
                self::enc($d['conditions']), self::enc($d['targets']), $d['template'], self::enc($d['stages']),
                $d['quiet_from'], $d['quiet_to'], $now, $id,
            ]);
            return $id;
        }
        Database::pdo()->prepare(
            'INSERT INTO base_notify_rules (NAME, EVENT, ENABLED, SORT, STOP_ON_MATCH, KIND, CONDITIONS_JSON,
             TARGETS_JSON, TEMPLATE, STAGES_JSON, QUIET_FROM, QUIET_TO, DTCREATE, DTUPDATE)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $d['name'], $d['event'], $d['enabled'], $d['sort'], $d['stop_on_match'], $d['kind'],
            self::enc($d['conditions']), self::enc($d['targets']), $d['template'], self::enc($d['stages']),
            $d['quiet_from'], $d['quiet_to'], $now, $now,
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    public static function setEnabled(int $id, bool $on): void
    {
        if ($id < 1 || !self::tableReady()) {
            return;
        }
        Database::pdo()->prepare('UPDATE base_notify_rules SET ENABLED = ?, DTUPDATE = ? WHERE ID = ?')
            ->execute([$on ? 1 : 0, time(), $id]);
    }

    public static function delete(int $id): bool
    {
        if ($id < 1 || !self::tableReady()) {
            return false;
        }
        $st = Database::pdo()->prepare('DELETE FROM base_notify_rules WHERE ID = ?');
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function normalizeInput(array $in): array
    {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('notify.err.name'));
        }
        $event = trim((string)($in['event'] ?? ''));
        $def = NotifyCatalog::get($event);
        if ($def === null) {
            throw new InvalidArgumentException(t('notify.err.event', ['event' => $event]));
        }
        $kind = (string)($in['kind'] ?? 'notify');
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException(t('notify.err.kind'));
        }
        $conditions = self::normalizeConditions(self::decodeList($in['conditions'] ?? []), $def);
        $targets = self::normalizeTargets(self::decodeList($in['targets'] ?? []));
        if ($targets === []) {
            throw new InvalidArgumentException(t('notify.err.targets'));
        }
        $stages = $kind === 'confirm' ? self::normalizeStages(self::decodeList($in['stages'] ?? [])) : [];
        if ($kind === 'confirm' && $stages === []) {
            throw new InvalidArgumentException(t('notify.err.stages'));
        }
        $qf = trim((string)($in['quiet_from'] ?? ''));
        $qt = trim((string)($in['quiet_to'] ?? ''));
        if (($qf !== '' && !NotifyConfig::validTime($qf)) || ($qt !== '' && !NotifyConfig::validTime($qt))) {
            throw new InvalidArgumentException(t('notify.err.time'));
        }
        if (($qf === '') !== ($qt === '')) {
            $qf = $qt = '';
        }
        return [
            'name' => mb_substr($name, 0, 255),
            'event' => $event,
            'enabled' => !array_key_exists('enabled', $in) || !empty($in['enabled']) ? 1 : 0,
            'sort' => (int)($in['sort'] ?? 100),
            'stop_on_match' => !empty($in['stop_on_match']) ? 1 : 0,
            'kind' => $kind,
            'conditions' => $conditions,
            'targets' => $targets,
            'template' => (string)($in['template'] ?? ''),
            'stages' => $stages,
            'quiet_from' => $qf !== '' ? $qf : null,
            'quiet_to' => $qt !== '' ? $qt : null,
        ];
    }

    /**
     * @param list<mixed> $rows
     * @param array<string,mixed> $def
     * @return list<array{field:string,op:string,value:mixed}>
     */
    public static function normalizeConditions(array $rows, array $def): array
    {
        $fields = [];
        foreach ($def['fields'] as $f) {
            $fields[(string)$f['id']] = $f;
        }
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $field = trim((string)($r['field'] ?? ''));
            if ($field === '') {
                continue;
            }
            if (!isset($fields[$field])) {
                throw new InvalidArgumentException(t('notify.err.field', ['field' => $field]));
            }
            $op = (string)($r['op'] ?? 'eq');
            if (!in_array($op, $fields[$field]['op'], true)) {
                throw new InvalidArgumentException(t('notify.err.op', ['field' => $field, 'op' => $op]));
            }
            $value = $r['value'] ?? '';
            if (in_array($op, ['in', 'nin'], true)) {
                if (!is_array($value)) {
                    $value = preg_split('/\s*,\s*/', trim((string)$value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                }
                $value = array_values(array_map('strval', $value));
            } elseif (is_array($value)) {
                $value = (string)reset($value);
            }
            $out[] = ['field' => $field, 'op' => $op, 'value' => $value];
        }
        return $out;
    }

    /**
     * @param list<mixed> $rows
     * @return list<array<string,mixed>>
     */
    public static function normalizeTargets(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $kind = (string)($r['kind'] ?? '');
            if (!in_array($kind, self::TARGET_KINDS, true)) {
                continue;
            }
            $channels = array_values(array_intersect(self::CHANNELS, array_map('strval', (array)($r['channels'] ?? ['bot']))));
            if ($channels === []) {
                $channels = ['bot'];
            }
            $t = [
                'kind' => $kind,
                'channels' => $channels,
                'strategy' => in_array((string)($r['strategy'] ?? ''), self::STRATEGIES, true) ? (string)$r['strategy'] : 'all',
            ];
            if ($kind === 'chat') {
                $t['chat_id'] = trim((string)($r['chat_id'] ?? ''));
                $t['bot_chat_id'] = (int)($r['bot_chat_id'] ?? 0);
                $t['platform'] = trim((string)($r['platform'] ?? ''));
                if ($t['chat_id'] === '' && $t['bot_chat_id'] < 1) {
                    continue;
                }
                $t['channels'] = ['bot'];
            } elseif ($kind === 'staff') {
                $ids = $r['user_ids'] ?? [];
                if (!is_array($ids)) {
                    $ids = preg_split('/[\s,;]+/', trim((string)$ids), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                }
                $t['user_ids'] = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
                if ($t['user_ids'] === []) {
                    continue;
                }
            } elseif ($kind === 'role') {
                $t['status_id'] = (int)($r['status_id'] ?? 0);
                if ($t['status_id'] < 1) {
                    continue;
                }
            } elseif ($kind === 'flag') {
                $t['flag'] = strtoupper(trim((string)($r['flag'] ?? '')));
                if (!preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', $t['flag'])) {
                    continue;
                }
            } elseif ($kind === 'on_shift') {
                $t['status_id'] = (int)($r['status_id'] ?? 0);
            } elseif ($kind === 'subject') {
                $t['subject'] = trim((string)($r['subject'] ?? ''));
                if ($t['subject'] === '') {
                    continue;
                }
            }
            $out[] = $t;
        }
        return $out;
    }

    /**
     * @param list<mixed> $rows
     * @return list<array<string,mixed>>
     */
    public static function normalizeStages(array $rows): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $i => $r) {
            if (!is_array($r)) {
                continue;
            }
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)($r['key'] ?? '')))) ?? '';
            if ($key === '' || isset($seen[$key])) {
                $key = 's' . ($i + 1);
            }
            $seen[$key] = true;
            $outcomes = $r['outcomes'] ?? [];
            if (!is_array($outcomes)) {
                $outcomes = preg_split('/[\s,;]+/', trim((string)$outcomes), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
            $out[] = [
                'key' => substr($key, 0, 64),
                'anchor' => (string)($r['anchor'] ?? 'event') === 'now' ? 'now' : 'event',
                'offset_sec' => (int)($r['offset_sec'] ?? 0),
                'retry_sec' => max(0, (int)($r['retry_sec'] ?? 0)),
                'max_attempts' => max(1, min(10, (int)($r['max_attempts'] ?? 1))),
                'expire_sec' => max(60, (int)($r['expire_sec'] ?? 7200)),
                'template' => (string)($r['template'] ?? ''),
                'outcomes' => array_values(array_map('strval', $outcomes)),
                'escalate' => self::normalizeTargets(is_array($r['escalate'] ?? null) ? $r['escalate'] : []),
            ];
        }
        return $out;
    }

    /**
     * @param mixed $raw array or JSON string
     * @return list<mixed>
     */
    private static function decodeList($raw): array
    {
        if (is_string($raw)) {
            $raw = trim($raw) === '' ? [] : json_decode($raw, true);
        }
        return is_array($raw) ? array_values($raw) : [];
    }

    /** @param mixed $v */
    private static function enc($v): string
    {
        return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        $dec = static function ($raw): array {
            $v = json_decode((string)$raw, true);
            return is_array($v) ? $v : [];
        };
        return [
            'id' => (int)$row['ID'],
            'name' => (string)$row['NAME'],
            'event' => (string)$row['EVENT'],
            'enabled' => (int)$row['ENABLED'],
            'sort' => (int)$row['SORT'],
            'stop_on_match' => (int)$row['STOP_ON_MATCH'],
            'kind' => (string)$row['KIND'],
            'conditions' => $dec($row['CONDITIONS_JSON'] ?? ''),
            'targets' => $dec($row['TARGETS_JSON'] ?? ''),
            'template' => (string)($row['TEMPLATE'] ?? ''),
            'stages' => $dec($row['STAGES_JSON'] ?? ''),
            'quiet_from' => (string)($row['QUIET_FROM'] ?? ''),
            'quiet_to' => (string)($row['QUIET_TO'] ?? ''),
            'dtcreate' => (int)$row['DTCREATE'],
            'dtupdate' => (int)$row['DTUPDATE'],
        ];
    }
}
