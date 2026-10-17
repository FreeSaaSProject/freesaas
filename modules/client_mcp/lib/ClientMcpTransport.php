<?php
declare(strict_types=1);

/**
 * MCP Streamable HTTP plumbing for the anonymous client server (JSON responses only, stateless).
 *
 * @see https://modelcontextprotocol.io/specification/2025-06-18/basic/transports
 */
final class ClientMcpTransport
{
    /** Newest first; initialize echoes the client's version when supported. */
    public const PROTOCOLS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;
    public const SERVER_DISABLED = -32000;

    public static function corsHeaders(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Accept, Mcp-Session-Id, MCP-Protocol-Version, Last-Event-ID, If-None-Match');
        header('Access-Control-Expose-Headers: Mcp-Session-Id, ETag');
        header('Access-Control-Max-Age: 86400');
    }

    public static function negotiateProtocol(string $requested): string
    {
        $requested = trim($requested);
        return in_array($requested, self::PROTOCOLS, true) ? $requested : self::PROTOCOLS[0];
    }

    /**
     * Read POST body. Returns null on parse failure.
     *
     * @return array<mixed>|null
     */
    public static function readBody(): ?array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '' || strlen($raw) > 256 * 1024) {
            return null;
        }
        $body = json_decode($raw, true);
        return is_array($body) ? $body : null;
    }

    /** @param array<mixed> $arr */
    public static function isList(array $arr): bool
    {
        return $arr === [] || array_keys($arr) === range(0, count($arr) - 1);
    }

    /**
     * @param mixed $id
     * @param mixed $result
     * @return array<string,mixed>
     */
    public static function result($id, $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @param mixed $id
     * @return array<string,mixed>
     */
    public static function error($id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /** @param mixed $payload */
    public static function emitJson($payload, int $http = 200, string $contentType = 'application/json'): void
    {
        http_response_code($http);
        header('Content-Type: ' . $contentType . '; charset=utf-8');
        header('Cache-Control: no-store');
        echo self::encode($payload);
        exit;
    }

    public static function emitAccepted(): void
    {
        http_response_code(202);
        exit;
    }

    /** @param mixed $payload */
    public static function encode($payload): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $out = json_encode($payload, $flags);
        return $out === false ? '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"encode failed"}}' : $out;
    }
}
