<?php
declare(strict_types=1);

/** base_client_mcp_hosts: MCP settings per storefront host (SITE_HOST_ID 0 = single-site install). */
final class ClientMcpHostsRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_client_mcp_hosts');
    }

    /** @return array{site_host_id:int,enabled:bool,server_name:string,description:string,consent_url:string,disabled_tools:list<string>} */
    public static function get(int $siteHostId): array
    {
        $row = null;
        if (self::tableReady()) {
            $st = Database::pdo()->prepare('SELECT * FROM base_client_mcp_hosts WHERE SITE_HOST_ID = ? LIMIT 1');
            $st->execute([max(0, $siteHostId)]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        return [
            'site_host_id' => max(0, $siteHostId),
            'enabled' => is_array($row) && (int)$row['ENABLED'] === 1,
            'server_name' => is_array($row) ? (string)$row['SERVER_NAME'] : '',
            'description' => is_array($row) ? (string)$row['DESCRIPTION'] : '',
            'consent_url' => is_array($row) ? (string)$row['CONSENT_URL'] : '',
            'disabled_tools' => is_array($row) ? self::decodeTools((string)$row['DISABLED_TOOLS']) : [],
        ];
    }

    /** @param array{enabled:bool,server_name:string,description:string,consent_url:string,disabled_tools:list<string>} $data */
    public static function save(int $siteHostId, array $data): void
    {
        Database::pdo()->prepare(
            'INSERT INTO base_client_mcp_hosts
                (SITE_HOST_ID, ENABLED, SERVER_NAME, DESCRIPTION, CONSENT_URL, DISABLED_TOOLS, DT_UPDATE)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                ENABLED = VALUES(ENABLED), SERVER_NAME = VALUES(SERVER_NAME), DESCRIPTION = VALUES(DESCRIPTION),
                CONSENT_URL = VALUES(CONSENT_URL), DISABLED_TOOLS = VALUES(DISABLED_TOOLS), DT_UPDATE = VALUES(DT_UPDATE)'
        )->execute([
            max(0, $siteHostId),
            !empty($data['enabled']) ? 1 : 0,
            mb_substr((string)$data['server_name'], 0, 80),
            mb_substr((string)$data['description'], 0, 400),
            mb_substr((string)$data['consent_url'], 0, 500),
            (string)json_encode(array_values($data['disabled_tools'])),
            time(),
        ]);
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
