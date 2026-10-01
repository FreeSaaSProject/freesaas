<?php
declare(strict_types=1);

final class StatusesRepository
{
    /**
     * Editable field schema grouped for the form.
     * Each field: key, label (i18n key), type=checkbox|text|select, when=always|modules…
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
                    ['key' => 'MARKET_ACCESS', 'label' => 'core.statuses.field.MARKET_ACCESS', 'type' => 'checkbox'],
                    ['key' => 'STA_ACCESS', 'label' => 'core.statuses.field.STA_ACCESS', 'type' => 'checkbox'],
                    ['key' => 'ZGV_ACCESS', 'label' => 'core.statuses.field.ZGV_ACCESS', 'type' => 'checkbox'],
                    ['key' => 'SHOW_ADMINISTRATOR_DATA', 'label' => 'core.statuses.field.SHOW_ADMINISTRATOR_DATA', 'type' => 'checkbox'],
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
            [
                'title' => 'core.statuses.group.workweeks',
                'when' => 'workweeks',
                'fields' => [
                    ['key' => 'SHOW_LOAD_LEVEL', 'label' => 'core.statuses.field.SHOW_LOAD_LEVEL', 'type' => 'checkbox'],
                    ['key' => 'SHOW_IN_LOADS', 'label' => 'core.statuses.field.SHOW_IN_LOADS', 'type' => 'checkbox'],
                    ['key' => 'CAN_BE_ASSIGNED', 'label' => 'core.statuses.field.CAN_BE_ASSIGNED', 'type' => 'checkbox'],
                    ['key' => 'CAN_BE_ASSIGNED_ASANY', 'label' => 'core.statuses.field.CAN_BE_ASSIGNED_ASANY', 'type' => 'checkbox'],
                    ['key' => 'EVT_ASSIGN', 'label' => 'core.statuses.field.EVT_ASSIGN', 'type' => 'checkbox'],
                    ['key' => 'EVT_ASSIGN_MY', 'label' => 'core.statuses.field.EVT_ASSIGN_MY', 'type' => 'checkbox'],
                ],
            ],
            [
                'title' => 'core.statuses.group.calendar',
                'when' => 'calendar',
                'fields' => [
                    ['key' => 'EVT_MY_ONLY', 'label' => 'core.statuses.field.EVT_MY_ONLY', 'type' => 'checkbox'],
                    ['key' => 'EVT_OLD', 'label' => 'core.statuses.field.EVT_OLD', 'type' => 'checkbox'],
                    ['key' => 'EVT_MONEY_DATA', 'label' => 'core.statuses.field.EVT_MONEY_DATA', 'type' => 'checkbox'],
                    ['key' => 'EVT_DELETE', 'label' => 'core.statuses.field.EVT_DELETE', 'type' => 'checkbox'],
                    ['key' => 'EVT_CREATE_NEW', 'label' => 'core.statuses.field.EVT_CREATE_NEW', 'type' => 'checkbox'],
                    ['key' => 'EVT_MODIFY', 'label' => 'core.statuses.field.EVT_MODIFY', 'type' => 'checkbox'],
                    ['key' => 'EVT_BONUS', 'label' => 'core.statuses.field.EVT_BONUS', 'type' => 'checkbox'],
                    ['key' => 'EVT_CONTACT_DATA', 'label' => 'core.statuses.field.EVT_CONTACT_DATA', 'type' => 'checkbox'],
                    ['key' => 'EVT_DOC_LINKS', 'label' => 'core.statuses.field.EVT_DOC_LINKS', 'type' => 'checkbox'],
                    ['key' => 'EVT_PHOTOS', 'label' => 'core.statuses.field.EVT_PHOTOS', 'type' => 'checkbox'],
                ],
            ],
            [
                'title' => 'core.statuses.group.clients',
                'when' => 'clients',
                'fields' => [
                    ['key' => 'CLIENT_BASE', 'label' => 'core.statuses.field.CLIENT_BASE', 'type' => 'checkbox'],
                    ['key' => 'SHOW_MEDINFO', 'label' => 'core.statuses.field.SHOW_MEDINFO', 'type' => 'checkbox'],
                    ['key' => 'IS_DOCTOR', 'label' => 'core.statuses.field.IS_DOCTOR', 'type' => 'checkbox'],
                ],
            ],
            [
                'title' => 'core.statuses.group.stocks',
                'when' => 'stocks',
                'fields' => [
                    ['key' => 'STOCKS_DATA', 'label' => 'core.statuses.field.STOCKS_DATA', 'type' => 'checkbox'],
                    ['key' => 'GOOD_USE', 'label' => 'core.statuses.field.GOOD_USE', 'type' => 'checkbox'],
                    ['key' => 'STICKER_ADV_ACC', 'label' => 'core.statuses.field.STICKER_ADV_ACC', 'type' => 'checkbox'],
                ],
            ],
            [
                'title' => 'core.statuses.group.audio',
                'when' => 'audio',
                'fields' => [
                    ['key' => 'ID_AUDIO_POSITION', 'label' => 'core.statuses.field.ID_AUDIO_POSITION', 'type' => 'select_status'],
                    ['key' => 'DISABLE_NON_PROCEDURE_TIME', 'label' => 'core.statuses.field.DISABLE_NON_PROCEDURE_TIME', 'type' => 'checkbox'],
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
            return ModuleManager::isEnabled('likespa-stocks') || ModuleManager::isEnabled('stocks');
        }
        return ModuleManager::isEnabled($when);
    }

    /**
     * @return list<array{title:string,when:string,fields:list<array{key:string,label:string,type:string}>}>
     */
    public static function visibleFieldGroups(): array
    {
        $out = [];
        foreach (self::fieldGroups() as $g) {
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

    /** @return list<array> */
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
        $out = [0 => t('core.statuses.audio_none')];
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
                } elseif ($type === 'select_status') {
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
}
