<?php
declare(strict_types=1);

/** base_client_mcp_hosts: MCP settings per storefront host (SITE_HOST_ID 0 = single-site install). */
final class ClientMcpHostsRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_client_mcp_hosts');
    }

    /**
     * @return array{site_host_id:int,enabled:bool,server_name:string,description:string,consent_url:string,
     *   show_note:bool,note_text:string,disabled_tools:list<string>}
     */
    public static function get(int $siteHostId): array
    {
        $row = null;
        if (self::tableReady()) {
            $st = Database::pdo()->prepare('SELECT * FROM base_client_mcp_hosts WHERE SITE_HOST_ID = ? LIMIT 1');
            $st->execute([max(0, $siteHostId)]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $r = is_array($row) ? $row : [];
        return [
            'site_host_id' => max(0, $siteHostId),
            'enabled' => (int)($r['ENABLED'] ?? 0) === 1,
            'server_name' => (string)($r['SERVER_NAME'] ?? ''),
            'description' => (string)($r['DESCRIPTION'] ?? ''),
            'consent_url' => (string)($r['CONSENT_URL'] ?? ''),
            'show_note' => (int)($r['SHOW_NOTE'] ?? 1) === 1,
            'note_text' => (string)($r['NOTE_TEXT'] ?? ''),
            'disabled_tools' => self::decodeTools((string)($r['DISABLED_TOOLS'] ?? '[]')),
        ];
    }

    /**
     * @param array{enabled:bool,server_name:string,description:string,consent_url:string,
     *   show_note?:bool,note_text?:string,disabled_tools:list<string>} $data
     */
    public static function save(int $siteHostId, array $data): void
    {
        $hasNote = Database::columnExists('base_client_mcp_hosts', 'SHOW_NOTE');
        $cols = 'SITE_HOST_ID, ENABLED, SERVER_NAME, DESCRIPTION, CONSENT_URL, DISABLED_TOOLS, DT_UPDATE';
        $marks = '?, ?, ?, ?, ?, ?, ?';
        $upd = 'ENABLED = VALUES(ENABLED), SERVER_NAME = VALUES(SERVER_NAME), DESCRIPTION = VALUES(DESCRIPTION),
                CONSENT_URL = VALUES(CONSENT_URL), DISABLED_TOOLS = VALUES(DISABLED_TOOLS), DT_UPDATE = VALUES(DT_UPDATE)';
        $vals = [
            max(0, $siteHostId),
            !empty($data['enabled']) ? 1 : 0,
            mb_substr((string)$data['server_name'], 0, 80),
            mb_substr((string)$data['description'], 0, 400),
            mb_substr((string)$data['consent_url'], 0, 500),
            (string)json_encode(array_values($data['disabled_tools'])),
            time(),
        ];
        if ($hasNote) {
            $cols .= ', SHOW_NOTE, NOTE_TEXT';
            $marks .= ', ?, ?';
            $upd .= ', SHOW_NOTE = VALUES(SHOW_NOTE), NOTE_TEXT = VALUES(NOTE_TEXT)';
            $vals[] = array_key_exists('show_note', $data) ? (!empty($data['show_note']) ? 1 : 0) : 1;
            $vals[] = mb_substr((string)($data['note_text'] ?? ''), 0, 400);
        }
        Database::pdo()->prepare(
            "INSERT INTO base_client_mcp_hosts ($cols) VALUES ($marks) ON DUPLICATE KEY UPDATE $upd"
        )->execute($vals);
    }

    public static function countEnabled(): int
    {
        if (!self::tableReady()) {
            return 0;
        }
        return (int)Database::pdo()->query('SELECT COUNT(*) FROM base_client_mcp_hosts WHERE ENABLED = 1')->fetchColumn();
    }

    /** @return list<string> */
    private static function decodeTools(string $raw): array
    {
        $arr = json_decode($raw, true);
        $out = [];
        foreach (is_array($arr) ? $arr : [] as $name) {
            $name = (string)$name;
            if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $name)) {
                $out[] = $name;
            }
        }
        return $out;
    }
}
