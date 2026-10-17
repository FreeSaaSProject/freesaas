<?php
declare(strict_types=1);

/**
 * Pre-connection discovery per storefront host: MCP Server Card + entry in the host AI Catalog (sites).
 *
 * @see https://github.com/modelcontextprotocol/experimental-ext-server-card/blob/main/docs/discovery.md
 */
final class ClientMcpDiscovery
{
    public const CARD_TYPE = 'application/mcp-server-card+json';

    /**
     * @param array<string,mixed> $host
     * @return array<string,mixed>
     */
    public static function serverCard(array $host): array
    {
        return [
            '$schema' => 'https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json',
            'name' => ClientMcpHost::serverId($host),
            'title' => ClientMcpHost::serverName($host),
            'description' => ClientMcpHost::description($host),
            'version' => ClientMcpConfig::moduleVersion(),
            'websiteUrl' => (string)$host['base'],
            'remotes' => [
                [
                    'type' => 'streamable-http',
                    'url' => ClientMcpHost::mcpUrl($host),
                    'supportedProtocolVersions' => ClientMcpTransport::PROTOCOLS,
                ],
            ],
            '_meta' => [
                'io.freesaas/client-mcp' => [
                    'auth' => 'none',
                    'audience' => 'clients',
                ],
            ],
        ];
    }

    /**
     * EventBus sites.ai_catalog subscriber.
     *
     * @param mixed $payload
     * @return mixed
     */
    public static function onAiCatalog($payload)
    {
        if (!is_array($payload)) {
            return $payload;
        }
        $host = ClientMcpHost::currentEnabled();
        if ($host === null) {
            return $payload;
        }
        $payload['entries'][] = [
            'identifier' => 'urn:air:' . $host['host'] . ':mcp:booking',
            'type' => self::CARD_TYPE,
            'url' => ClientMcpHost::serverCardUrl($host),
        ];
        return $payload;
    }

    public static function emitServerCard(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, If-None-Match');
        header('Access-Control-Expose-Headers: ETag');
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        $host = ClientMcpHost::currentEnabled();
        if ($host === null) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo '{"error":"not_found"}';
            exit;
        }
        $body = ClientMcpTransport::encode(self::serverCard($host));
        $etag = '"' . substr(hash('sha256', $body), 0, 32) . '"';
        header('Cache-Control: public, max-age=3600');
        header('ETag: ' . $etag);
        $inm = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($inm !== '' && ($inm === $etag || $inm === 'W/' . $etag || $inm === '*')) {
            http_response_code(304);
            exit;
        }
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        header('Content-Type: ' . (stripos($accept, self::CARD_TYPE) !== false ? self::CARD_TYPE : 'application/json') . '; charset=utf-8');
        header('Vary: Accept');
        echo $body;
        exit;
    }
}
