<?php
declare(strict_types=1);

/** Document definitions (base_doc_defs) + immutable versions (base_doc_def_versions). */
final class DocsDefsRepository
{
    public const KINDS = ['consent', 'contract', 'check', 'medcard_profile', 'medcard_visit', 'medcard_dossier'];
    public const MEDICAL_KINDS = ['medcard_profile', 'medcard_visit', 'medcard_dossier'];
    public const SCOPES = ['client', 'event'];
    public const TRIGGERS = ['manual', 'every', 'medical', 'non_medical', 'types', 'procs'];
    public const ORIENTATIONS = ['portrait', 'landscape'];
    public const FORMATS = ['A4', 'A5'];

    /** @var bool */
    private static $seedChecked = false;

    public static function tableReady(): bool
    {
        return Database::tableExists('base_doc_defs') && Database::tableExists('base_doc_def_versions');
    }

    public static function isMedicalKind(string $kind): bool
    {
        return in_array($kind, self::MEDICAL_KINDS, true);
    }

    public static function kindLabel(string $kind): string
    {
        return in_array($kind, self::KINDS, true) ? t('docs.kind.' . $kind) : $kind;
    }

    /** @return list<array<string,mixed>> */
    public static function listAll(bool $onlyEnabled = false): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $sql = 'SELECT * FROM base_doc_defs' . ($onlyEnabled ? ' WHERE ENABLED = 1' : '') . ' ORDER BY NUM ASC, ID ASC';
        $out = [];
        foreach (Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = self::normalize($row);
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_doc_defs WHERE ID = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalize($row) : null;
    }

    /** @return array<string,mixed>|null */
    public static function findByCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '' || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_doc_defs WHERE CODE = ? LIMIT 1');
        $st->execute([$code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalize($row) : null;
    }

    /** First enabled definition of a kind (profile / visit / dossier are singletons by convention). */
    public static function firstOfKind(string $kind): ?array
    {
        foreach (self::listAll(true) as $def) {
            if ($def['kind'] === $kind) {
                return $def;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public static function version(int $versionId): ?array
    {
        if ($versionId < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_doc_def_versions WHERE ID = ? LIMIT 1');
        $st->execute([$versionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalizeVersion($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public static function versions(int $defId): array
    {
        if ($defId < 1 || !self::tableReady()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, ID_DEF, VERSION, ORIENTATION, FORMAT, COPIES, DT_CREATE, ID_ADMIN
             FROM base_doc_def_versions WHERE ID_DEF = ? ORDER BY VERSION DESC'
        );
        $st->execute([$defId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'id' => (int)$row['ID'],
                'version' => (int)$row['VERSION'],
                'orientation' => (string)$row['ORIENTATION'],
                'format' => (string)$row['FORMAT'],
                'copies' => (int)$row['COPIES'],
                'dt_create' => (int)$row['DT_CREATE'],
                'id_admin' => (int)$row['ID_ADMIN'],
            ];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function currentVersion(array $def): ?array
    {
        return self::version((int)($def['id_version_current'] ?? 0));
    }

    /**
     * @param array<string,mixed> $meta normalized def fields
     * @param array{schema_json:string,print_html:string,orientation?:string,format?:string,copies?:int} $ver
     */
    public static function create(array $meta, array $ver): int
    {
        $now = time();
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO base_doc_defs
             (CODE, KIND, NAME, SCOPE, TRIGGER_MODE, TRIGGER_TYPES, TRIGGER_PROCS, EXPIRE_DAYS,
              REQUIRE_MEDCARD, ENABLED, NUM, ID_VERSION_CURRENT, DT_CREATE, DT_UPDATE, ID_ADMIN)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
        )->execute([
            $meta['code'],
            $meta['kind'],
            $meta['name'],
            $meta['scope'],
            $meta['trigger_mode'],
            json_encode(array_values($meta['trigger_types']), JSON_UNESCAPED_UNICODE),
            json_encode(array_values($meta['trigger_procs']), JSON_UNESCAPED_UNICODE),
            $meta['expire_days'],
            $meta['require_medcard'],
            $meta['enabled'],
            $meta['num'],
            $now,
            $now,
            (int)(Auth::userId() ?? 0),
        ]);
        $id = (int)$pdo->lastInsertId();
        self::addVersion($id, $ver);
        return $id;
    }

    /** @param array<string,mixed> $meta */
    public static function updateMeta(int $id, array $meta): void
    {
        Database::pdo()->prepare(
            'UPDATE base_doc_defs SET CODE = ?, KIND = ?, NAME = ?, SCOPE = ?, TRIGGER_MODE = ?,
             TRIGGER_TYPES = ?, TRIGGER_PROCS = ?, EXPIRE_DAYS = ?, REQUIRE_MEDCARD = ?, ENABLED = ?,
             NUM = ?, DT_UPDATE = ?
             WHERE ID = ?'
        )->execute([
            $meta['code'],
            $meta['kind'],
            $meta['name'],
            $meta['scope'],
            $meta['trigger_mode'],
            json_encode(array_values($meta['trigger_types']), JSON_UNESCAPED_UNICODE),
            json_encode(array_values($meta['trigger_procs']), JSON_UNESCAPED_UNICODE),
            $meta['expire_days'],
            $meta['require_medcard'],
            $meta['enabled'],
            $meta['num'],
            time(),
            $id,
        ]);
    }

    /**
     * New immutable version only when schema / template / layout changed.
     *
     * @param array{schema_json:string,print_html:string,orientation?:string,format?:string,copies?:int} $ver
     * @return int version id now current
     */
    public static function saveVersionIfChanged(int $defId, array $ver): int
    {
        $def = self::find($defId);
        if ($def === null) {
            throw new InvalidArgumentException(t('docs.def.err.not_found', ['id' => $defId]));
        }
        $cur = self::currentVersion($def);
        if ($cur !== null) {
            $same = self::canonicalJson($cur['schema_json']) === self::canonicalJson($ver['schema_json'])
                && trim((string)$cur['print_html']) === trim($ver['print_html'])
                && $cur['orientation'] === ($ver['orientation'] ?? 'portrait')
                && $cur['format'] === ($ver['format'] ?? 'A4')
                && (int)$cur['copies'] === (int)($ver['copies'] ?? 1);
            if ($same) {
                return (int)$cur['id'];
            }
        }
        return self::addVersion($defId, $ver);
    }

    /** @param array{schema_json:string,print_html:string,orientation?:string,format?:string,copies?:int} $ver */
    private static function addVersion(int $defId, array $ver): int
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT COALESCE(MAX(VERSION), 0) FROM base_doc_def_versions WHERE ID_DEF = ?');
        $st->execute([$defId]);
        $next = (int)$st->fetchColumn() + 1;
        $schema = DocsSchema::parse($ver['schema_json']);
        $pdo->prepare(
            'INSERT INTO base_doc_def_versions
             (ID_DEF, VERSION, SCHEMA_JSON, PRINT_HTML, ORIENTATION, FORMAT, COPIES, DT_CREATE, ID_ADMIN)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $defId,
            $next,
            json_encode($schema, JSON_UNESCAPED_UNICODE),
            $ver['print_html'],
            in_array($ver['orientation'] ?? '', self::ORIENTATIONS, true) ? $ver['orientation'] : 'portrait',
            in_array($ver['format'] ?? '', self::FORMATS, true) ? $ver['format'] : 'A4',
            max(1, min(9, (int)($ver['copies'] ?? 1))),
            time(),
            (int)(Auth::userId() ?? 0),
        ]);
        $vid = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE base_doc_defs SET ID_VERSION_CURRENT = ?, DT_UPDATE = ? WHERE ID = ?')
            ->execute([$vid, time(), $defId]);
        return $vid;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function metaFromPost(array $post, int $selfId = 0): array
    {
        $code = strtolower(trim((string)($post['code'] ?? '')));
        if (!preg_match('/^[a-z0-9_\-]{2,64}$/', $code)) {
            throw new InvalidArgumentException(t('docs.def.err.code'));
        }
        $other = self::findByCode($code);
        if ($other !== null && (int)$other['id'] !== $selfId) {
            throw new InvalidArgumentException(t('docs.def.err.code_taken', ['code' => $code]));
        }
        $kind = (string)($post['kind'] ?? 'consent');
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException(t('docs.def.err.kind'));
        }
        $name = mb_substr(trim((string)($post['name'] ?? '')), 0, 255);
        if ($name === '') {
            throw new InvalidArgumentException(t('docs.def.err.name'));
        }
        $scope = (string)($post['scope'] ?? 'event');
        if ($kind === 'medcard_profile' || $kind === 'medcard_dossier') {
            $scope = 'client';
        } elseif ($kind === 'medcard_visit') {
            $scope = 'event';
        }
        if (!in_array($scope, self::SCOPES, true)) {
            $scope = 'event';
        }
        $trigger = (string)($post['trigger_mode'] ?? 'manual');
        // Profile / dossier are client-level and opened by hand, never spawned from a visit.
        if (!in_array($trigger, self::TRIGGERS, true) || $kind === 'medcard_profile' || $kind === 'medcard_dossier') {
            $trigger = 'manual';
        }
        return [
            'code' => $code,
            'kind' => $kind,
            'name' => $name,
            'scope' => $scope,
            'trigger_mode' => $trigger,
            'trigger_types' => self::parseIdList((string)($post['trigger_types'] ?? '')),
            'trigger_procs' => self::parseIdList((string)($post['trigger_procs'] ?? '')),
            'expire_days' => max(0, min(3650, (int)($post['expire_days'] ?? 0))),
            'require_medcard' => !empty($post['require_medcard']) ? 1 : 0,
            'enabled' => !empty($post['enabled']) ? 1 : 0,
            'num' => (int)($post['num'] ?? 0),
        ];
    }

    /** @return list<int> */
    public static function parseIdList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $p) {
            $n = (int)$p;
            if ($n > 0 && !in_array($n, $out, true)) {
                $out[] = $n;
            }
        }
        return $out;
    }

    /** Runtime seed of default definitions (labels in the current locale). */
    public static function ensureSeeded(): void
    {
        if (self::$seedChecked || !self::tableReady()) {
            return;
        }
        self::$seedChecked = true;
        $created = false;
        foreach (DocsSeed::definitions() as $seed) {
            if (self::findByCode((string)$seed['meta']['code']) !== null) {
                continue;
            }
            self::create($seed['meta'], $seed['version']);
            $created = true;
        }
        if ($created) {
            Config::set('docs', 'seed_revision', (string)DocsSeed::REVISION);
        }
        DocsSeed::upgradeUntouched();
    }

    /** @param mixed $raw */
    private static function canonicalJson($raw): string
    {
        // Localized like the editor shows it, so re-saving an untouched seed in another UI locale is not a change.
        return (string)json_encode(DocsSeed::localizeSchema(DocsSchema::parse($raw)), JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string,mixed> $row */
    private static function normalize(array $row): array
    {
        $types = json_decode((string)($row['TRIGGER_TYPES'] ?? '[]'), true);
        $procs = json_decode((string)($row['TRIGGER_PROCS'] ?? '[]'), true);
        return [
            'id' => (int)$row['ID'],
            'code' => (string)$row['CODE'],
            'kind' => (string)$row['KIND'],
            'name' => DocsSeed::localizedName((string)$row['CODE'], (string)$row['NAME']),
            'scope' => (string)$row['SCOPE'],
            'trigger_mode' => (string)$row['TRIGGER_MODE'],
            'trigger_types' => is_array($types) ? array_map('intval', $types) : [],
            'trigger_procs' => is_array($procs) ? array_map('intval', $procs) : [],
            'expire_days' => (int)$row['EXPIRE_DAYS'],
            'require_medcard' => (int)$row['REQUIRE_MEDCARD'],
            'enabled' => (int)$row['ENABLED'],
            'num' => (int)$row['NUM'],
            'id_version_current' => (int)$row['ID_VERSION_CURRENT'],
            'dt_create' => (int)$row['DT_CREATE'],
            'dt_update' => (int)$row['DT_UPDATE'],
        ];
    }

    /** @param array<string,mixed> $row */
    private static function normalizeVersion(array $row): array
    {
        $schemaRaw = (string)($row['SCHEMA_JSON'] ?? '');
        return [
            'id' => (int)$row['ID'],
            'id_def' => (int)$row['ID_DEF'],
            'version' => (int)$row['VERSION'],
            'schema_json' => $schemaRaw,
            'schema' => DocsSeed::localizeSchema(DocsSchema::parse($schemaRaw)),
            'print_html' => DocsSeed::localizedHtml((string)($row['PRINT_HTML'] ?? '')),
            'orientation' => (string)$row['ORIENTATION'],
            'format' => (string)$row['FORMAT'],
            'copies' => (int)$row['COPIES'],
            'dt_create' => (int)$row['DT_CREATE'],
        ];
    }
}
