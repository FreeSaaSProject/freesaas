<?php
declare(strict_types=1);

final class StatusesRepository
{
    /** @var list<array{title:string,when:string,fields:list<array{key:string,label:string,type:string}>}> */
    private static $registeredGroups = [];

    /**
     * Register a product status field group (call from owning module bootstrap).
     * Core keeps only when=always groups in fieldGroups().
     *
     * @param array{title:string,when:string,fields:list<array{key:string,label:string,type:string}>} $group
     */
    public static function registerFieldGroup(array $group): void
    {
        $title = trim((string)($group['title'] ?? ''));
        $when = trim((string)($group['when'] ?? ''));
        $fields = $group['fields'] ?? null;
        if ($title === '' || $when === '' || !is_array($fields) || !$fields) {
            return;
        }
        $clean = [];
        foreach ($fields as $f) {
            if (!is_array($f) || empty($f['key']) || empty($f['label'])) {
                continue;
            }
            $clean[] = [
                'key' => (string)$f['key'],
                'label' => (string)$f['label'],
                'type' => (string)($f['type'] ?? 'checkbox'),
            ];
        }
        if (!$clean) {
            return;
        }
        self::$registeredGroups[] = [
            'title' => $title,
            'when' => $when,
            'fields' => $clean,
        ];
    }

    /**
     * Product field groups registered via registerFieldGroup (when = module id).
     *
     * @return list<array{title:string,when:string,fields:list<array{key:string,label:string,type:string}>}>
     */
    public static function registeredFieldGroups(): array
    {
        return self::$registeredGroups;
    }

    /**
     * Core-owned editable field groups (when=always only).
     *
     * @return list<array{title:string,when:string,fields:list<array{key:string,label:string,type:string}>}>
     */
    public static function fieldGroups(): array
    {
        return [
            [
                'title' => 'core.statuses.group.main',
                'when' => 'always',
                'fields' => [
                    ['key' => 'NAME', 'label' => 'core.statuses.field.NAME', 'type' => 'text'],
                    ['key' => 'ENABLED', 'label' => 'core.statuses.field.ENABLED', 'type' => 'checkbox'],
                    ['key' => 'ASSIST_STA', 'label' => 'core.statuses.field.ASSIST_STA', 'type' => 'checkbox'],
                    ['key' => 'PARTNER_STA', 'label' => 'core.statuses.field.PARTNER_STA', 'type' => 'checkbox'],
                ],
            ],
            [
                'title' => 'core.statuses.group.platform',
                'when' => 'always',
                'fields' => [
                    ['key' => 'SUPERADMIN_ACCESS', 'label' => 'core.statuses.field.SUPERADMIN_ACCESS', 'type' => 'checkbox'],
                    ['key' => 'CONTROL_ACCESS', 'label' => 'core.statuses.field.CONTROL_ACCESS', 'type' => 'checkbox'],
                    ['key' => 'SHOW_ADMINISTRATOR_DATA', 'label' => 'core.statuses.field.SHOW_ADMINISTRATOR_DATA', 'type' => 'checkbox'],
                ],
            ],
            [
                'title' => 'core.statuses.group.accounting',
                'when' => 'always',
                'fields' => [
                    ['key' => 'CAN_SEE_SUM_OTHERS', 'label' => 'core.statuses.field.CAN_SEE_SUM_OTHERS', 'type' => 'checkbox'],
                ],
            ],
            [
                'title' => 'core.statuses.group.news',
                'when' => 'always',
                'fields' => [
                    ['key' => 'SHOW_NEWS', 'label' => 'core.statuses.field.SHOW_NEWS', 'type' => 'checkbox'],
                ],
            ],
            [
                'title' => 'core.statuses.group.security',
                'when' => 'always',
                'fields' => [
                    ['key' => 'FASTAUTH_WORKTIME_ONLY', 'label' => 'core.statuses.field.FASTAUTH_WORKTIME_ONLY', 'type' => 'checkbox'],
                ],
            ],
        ];
    }

    /** Whether a field group should render for current enabled modules. */
    public static function groupVisible(string $when): bool
    {
        if ($when === 'always') {
            return true;
        }
        if ($when === 'stocks') {
            return ModuleManager::isEnabled('stocks') || ModuleManager::isEnabled('likespa-stocks');
        }
        return ModuleManager::isEnabled($when);
    }

    /**
     * @return list<array{title:string,when:string,fields:list<array{key:string,label:string,type:string}>}>
     */
    public static function visibleFieldGroups(): array
    {
        $out = [];
        foreach (array_merge(self::fieldGroups(), self::$registeredGroups) as $g) {
            if (!self::groupVisible((string)$g['when'])) {
                continue;
            }
            $fields = [];
            foreach ($g['fields'] as $f) {
                if (Database::columnExists('base_admin_statuses', (string)$f['key'])) {
                    $fields[] = $f;
                }
            }
            if ($fields) {
                $g['fields'] = $fields;
                $out[] = $g;
            }
        }
        return $out;
    }

    /** @return array<string,string> request sort key => SQL expression */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'name' => 'NAME',
            'type' => 'ASSIST_STA',
            'enabled' => 'ENABLED',
        ];
    }

    /**
     * @param array{order?:string} $opts
     * @return list<array>
     */
    public static function listAll(array $opts = []): array
    {
        if (!Database::tableExists('base_admin_statuses')) {
            return [];
        }
        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'ASSIST_STA ASC, ID ASC';
        }
        return Database::pdo()->query(
            'SELECT ID, NAME, ENABLED, ASSIST_STA, PARTNER_STA, SUPERADMIN_ACCESS, CONTROL_ACCESS, SHOW_NEWS
             FROM base_admin_statuses ORDER BY ' . $order
        )->fetchAll() ?: [];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_admin_statuses')) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_admin_statuses WHERE ID = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** @return array<int,string> */
    public static function optionsForAudio(int $excludeId = 0): array
    {
        $out = [0 => t('audio.statuses.audio_none')];
        if (!Database::tableExists('base_admin_statuses')) {
            return $out;
        }
        $sql = 'SELECT ID, NAME FROM base_admin_statuses WHERE ENABLED > 0';
        if ($excludeId > 0) {
            $sql .= ' AND ID <> ' . (int)$excludeId;
        }
        $sql .= ' ORDER BY NAME';
        foreach (Database::pdo()->query($sql)->fetchAll() ?: [] as $r) {
            $out[(int)$r['ID']] = (string)$r['NAME'];
        }
        return $out;
    }

    /**
     * Build update/insert payload from POST for currently visible fields only.
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $data = [];
        foreach (self::visibleFieldGroups() as $g) {
            foreach ($g['fields'] as $f) {
                $key = (string)$f['key'];
                $type = (string)$f['type'];
                if ($type === 'checkbox') {
                    $data[$key] = !empty($post[$key]) ? 1 : 0;
                } elseif ($type === 'select_status' || $type === 'select_shift') {
                    $data[$key] = (int)($post[$key] ?? 0);
                } else {
                    $data[$key] = trim((string)($post[$key] ?? ''));
                }
            }
        }
        return $data;
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        if (!Database::tableExists('base_admin_statuses')) {
            throw new RuntimeException(t('core.statuses.err.no_table'));
        }
        $name = trim((string)($data['NAME'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('core.statuses.err.name_required'));
        }
        $cols = [];
        $vals = [];
        $params = [];
        foreach ($data as $k => $v) {
            if (!Database::columnExists('base_admin_statuses', (string)$k)) {
                continue;
            }
            $cols[] = '`' . $k . '`';
            $vals[] = '?';
            $params[] = $v;
        }
        if (!$cols) {
            throw new RuntimeException(t('core.statuses.err.no_fields'));
        }
        $sql = 'INSERT INTO base_admin_statuses (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')';
        Database::pdo()->prepare($sql)->execute($params);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            return;
        }
        if (isset($data['NAME']) && trim((string)$data['NAME']) === '') {
            throw new InvalidArgumentException(t('core.statuses.err.name_required'));
        }
        // Prevent audio position cycles (self-reference).
        if (isset($data['ID_AUDIO_POSITION']) && (int)$data['ID_AUDIO_POSITION'] === $id) {
            $data['ID_AUDIO_POSITION'] = 0;
        }
        $fields = [];
        $params = [];
        foreach ($data as $k => $v) {
            if (!Database::columnExists('base_admin_statuses', (string)$k)) {
                continue;
            }
            $fields[] = '`' . $k . '` = ?';
            $params[] = $v;
        }
        if (!$fields) {
            return;
        }
        $params[] = $id;
        $sql = 'UPDATE base_admin_statuses SET ' . implode(', ', $fields) . ' WHERE ID = ?';
        Database::pdo()->prepare($sql)->execute($params);
    }

    public static function userCount(int $statusId): int
    {
        if ($statusId < 1 || !Database::tableExists('base_users')) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_users WHERE ID_STATUS = ? OR ID_STATUS2 = ?'
        );
        $st->execute([$statusId, $statusId]);
        return (int)$st->fetchColumn();
    }

    /**
     * @param list<int> $ids
     * @return array{done:int,skipped:list<array{id:int,reason:string}>}
     */
    public static function deleteByIds(array $ids): array
    {
        $done = 0;
        $skipped = [];
        $pdo = Database::pdo();
        foreach ($ids as $raw) {
            $id = (int)$raw;
            if ($id < 1) {
                continue;
            }
            $n = self::userCount($id);
            if ($n > 0) {
                $skipped[] = ['id' => $id, 'reason' => t('core.statuses.err.in_use_users', ['n' => $n])];
                continue;
            }
            if (!Database::tableExists('base_admin_statuses')) {
                continue;
            }
            $pdo->prepare('DELETE FROM base_admin_statuses WHERE ID = ?')->execute([$id]);
            $done++;
        }
        return ['done' => $done, 'skipped' => $skipped];
    }
}
