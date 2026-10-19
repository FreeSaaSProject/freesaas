<?php
declare(strict_types=1);

/**
 * Template files (definitions without instances): bundled packs under modules/docs/library/<pack>/
 * and single-definition export/import. Templates live in files, not in the DB, until imported.
 */
final class DocsLibrary
{
    public const FORMAT = 'freesaas.doc-template';
    public const FORMAT_VERSION = 1;

    public static function dir(): string
    {
        return base_path('modules/docs/library');
    }

    /**
     * @return list<array{id:string,name:string,description:string,count:int,exported_at:string}>
     */
    public static function packs(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*/pack.json') ?: [] as $file) {
            $meta = json_decode((string)file_get_contents($file), true);
            $id = basename(dirname($file));
            if (!is_array($meta) || !preg_match('/^[a-z0-9_\-]+$/', $id)) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => (string)($meta['name'] ?? $id),
                'description' => (string)($meta['description'] ?? ''),
                'count' => count(glob(dirname($file) . '/*.json') ?: []) - 1,
                'exported_at' => (string)($meta['exported_at'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Templates of a pack (header fields only, no HTML).
     *
     * @return list<array<string,mixed>>
     */
    public static function packTemplates(string $packId): array
    {
        if (!preg_match('/^[a-z0-9_\-]+$/', $packId)) {
            return [];
        }
        $out = [];
        foreach (glob(self::dir() . '/' . $packId . '/*.json') ?: [] as $file) {
            if (basename($file) === 'pack.json') {
                continue;
            }
            $data = self::read((string)file_get_contents($file));
            if ($data === null) {
                continue;
            }
            $out[] = [
                'file' => basename($file, '.json'),
                'code' => (string)$data['code'],
                'name' => (string)$data['name'],
                'kind' => (string)$data['kind'],
                'trigger_mode' => (string)$data['trigger_mode'],
                'legacy_trigger' => (string)($data['source']['trigger'] ?? ''),
                'exists' => DocsDefsRepository::findByCode((string)$data['code']) !== null,
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name']));
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function readPackFile(string $packId, string $file): ?array
    {
        if (!preg_match('/^[a-z0-9_\-]+$/', $packId) || !preg_match('/^[a-z0-9_\-]+$/', $file)) {
            return null;
        }
        $path = self::dir() . '/' . $packId . '/' . $file . '.json';
        return is_file($path) ? self::read((string)file_get_contents($path)) : null;
    }

    /**
     * Parse + validate one template file.
     *
     * @return array<string,mixed>|null
     */
    public static function read(string $json): ?array
    {
        $d = json_decode($json, true);
        if (!is_array($d) || ($d['format'] ?? '') !== self::FORMAT) {
            return null;
        }
        $code = strtolower(trim((string)($d['code'] ?? '')));
        if (!preg_match('/^[a-z0-9_\-]{2,64}$/', $code) || !in_array((string)($d['kind'] ?? ''), DocsDefsRepository::KINDS, true)) {
            return null;
        }
        $d['code'] = $code;
        $d['name'] = mb_substr(trim((string)($d['name'] ?? $code)), 0, 255);
        $d['scope'] = in_array((string)($d['scope'] ?? ''), DocsDefsRepository::SCOPES, true) ? (string)$d['scope'] : 'event';
        $d['trigger_mode'] = in_array((string)($d['trigger_mode'] ?? ''), DocsDefsRepository::TRIGGERS, true) ? (string)$d['trigger_mode'] : 'manual';
        $d['schema'] = DocsSchema::parse($d['schema'] ?? []);
        $d['print_html'] = (string)($d['print_html'] ?? '');
        return $d;
    }

    /**
     * File payload for one definition (current version).
     *
     * @param array<string,mixed> $def
     * @return array<string,mixed>
     */
    public static function export(array $def): array
    {
        $ver = DocsDefsRepository::currentVersion($def);
        return [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'code' => (string)$def['code'],
            'kind' => (string)$def['kind'],
            'name' => (string)$def['name'],
            'scope' => (string)$def['scope'],
            'trigger_mode' => (string)$def['trigger_mode'],
            'trigger_types' => array_values($def['trigger_types']),
            'trigger_procs' => array_values($def['trigger_procs']),
            'expire_days' => (int)$def['expire_days'],
            'require_medcard' => (int)$def['require_medcard'],
            'orientation' => (string)($ver['orientation'] ?? 'portrait'),
            'paper' => (string)($ver['format'] ?? 'A4'),
            'copies' => (int)($ver['copies'] ?? 1),
            'schema' => $ver['schema'] ?? ['sections' => []],
            'print_html' => (string)($ver['print_html'] ?? ''),
            'source' => [
                'version' => (int)($ver['version'] ?? 0),
                'exported_at' => date('c'),
            ],
        ];
    }

    public static function encode(array $data): string
    {
        return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * Create (or, with update, re-version) a definition from a template file.
     *
     * @param array<string,mixed> $data read() result
     * @param array{keep_ids?:bool,update?:bool,enable?:bool} $opts
     * @return array{status:string,id:int,code:string} status created|updated|skipped
     */
    public static function import(array $data, array $opts = []): array
    {
        $trigger = (string)$data['trigger_mode'];
        $types = array_values(array_map('intval', (array)($data['trigger_types'] ?? [])));
        $procs = array_values(array_map('intval', (array)($data['trigger_procs'] ?? [])));
        // Service / type ids belong to the source database.
        if (empty($opts['keep_ids']) && in_array($trigger, ['types', 'procs'], true)) {
            $trigger = 'manual';
            $types = [];
            $procs = [];
        }
        $meta = [
            'code' => (string)$data['code'],
            'kind' => (string)$data['kind'],
            'name' => (string)$data['name'],
            'scope' => (string)$data['scope'],
            'trigger_mode' => $trigger,
            'trigger_types' => $types,
            'trigger_procs' => $procs,
            'expire_days' => max(0, min(3650, (int)($data['expire_days'] ?? 0))),
            'require_medcard' => !empty($data['require_medcard']) ? 1 : 0,
            'enabled' => !empty($opts['enable']) ? 1 : 0,
            'num' => 100,
        ];
        $ver = [
            'schema_json' => (string)json_encode($data['schema'], JSON_UNESCAPED_UNICODE),
            'print_html' => (string)$data['print_html'],
            'orientation' => (string)($data['orientation'] ?? 'portrait'),
            'format' => (string)($data['paper'] ?? 'A4'),
            'copies' => (int)($data['copies'] ?? 1),
        ];
        $existing = DocsDefsRepository::findByCode($meta['code']);
        if ($existing !== null) {
            if (empty($opts['update'])) {
                return ['status' => 'skipped', 'id' => (int)$existing['id'], 'code' => $meta['code']];
            }
            $meta['enabled'] = (int)$existing['enabled'];
            $meta['num'] = (int)$existing['num'];
            DocsDefsRepository::updateMeta((int)$existing['id'], $meta);
            DocsDefsRepository::saveVersionIfChanged((int)$existing['id'], $ver);
            return ['status' => 'updated', 'id' => (int)$existing['id'], 'code' => $meta['code']];
        }
        return ['status' => 'created', 'id' => DocsDefsRepository::create($meta, $ver), 'code' => $meta['code']];
    }
}
