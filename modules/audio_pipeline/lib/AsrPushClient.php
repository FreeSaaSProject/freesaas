<?php
declare(strict_types=1);

/**
 * ASR view of the shared edge host client (app/EdgeHostClient.php).
 */
final class AsrPushClient
{
    public static function validBaseUrl(string $url): bool
    {
        return EdgeHostClient::validBaseUrl($url);
    }

    public static function validPin(string $pin): bool
    {
        return EdgeHostClient::validPin($pin);
    }

    /**
     * @param array<string,mixed> $worker
     * @param array<string,mixed>|null $payload
     * @return array{ok:bool,http:int,data:array<string,mixed>,error:string}
     */
    public static function call(array $worker, string $method, string $pathQs, ?array $payload = null, int $timeout = 25): array
    {
        return EdgeHostClient::call($worker, $method, $pathQs, $payload, $timeout);
    }
}
