<?php
declare(strict_types=1);

/**
 * ASR view of the shared edge host registry (app/EdgeHosts.php, base_edge_hosts).
 * Rows keep the legacy shape: ENABLED = host on AND asr service on, MAX_INFLIGHT and
 * LAST_STATUS of the asr service. IDs are edge host IDs (base_asr_leases.WORKER_ID).
 */
final class AsrPushWorkers
{
    public const SERVICE = 'asr';

    public static function available(): bool
    {
        return EdgeHosts::available();
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return array_map([self::class, 'view'], EdgeHosts::all());
    }

    /** @return list<array<string,mixed>> */
    public static function enabled(): array
    {
        return array_map([self::class, 'view'], EdgeHosts::forService(self::SERVICE));
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $h = EdgeHosts::find($id);
        return $h === null ? null : self::view($h);
    }

    /** @return array{id:int,secret:string} */
    public static function create(string $name, string $baseUrl, string $pin, int $maxInflight): array
    {
        return EdgeHosts::create($name, $baseUrl, $pin, [self::SERVICE => ['enabled' => true, 'max_inflight' => $maxInflight]]);
    }

    public static function update(int $id, string $name, string $baseUrl, string $pin, int $maxInflight, bool $enabled): void
    {
        $h = EdgeHosts::find($id);
        if ($h === null) {
            return;
        }
        $hostOn = $enabled || (int)$h['ENABLED'] === 1;
        EdgeHosts::update($id, $name, $baseUrl, $pin, $hostOn, (string)$h['KEY_ID']);
        EdgeHosts::setPlugin($id, self::SERVICE, $enabled, $maxInflight);
    }

    public static function rotateNext(int $id): string
    {
        return EdgeHosts::rotateNext($id);
    }

    public static function promoteNext(int $id): bool
    {
        return EdgeHosts::promoteNext($id);
    }

    public static function delete(int $id): void
    {
        EdgeHosts::delete($id);
    }

    /** @param array<string,mixed> $status */
    public static function noteSuccess(int $id, array $status): void
    {
        EdgeHosts::noteSuccess($id, self::SERVICE, $status);
    }

    public static function noteFailure(int $id, string $error): int
    {
        return EdgeHosts::noteFailure($id, $error);
    }

    public static function noteProbeError(int $id, string $error): void
    {
        EdgeHosts::noteProbeError($id, $error);
    }

    public static function mask(string $secret): string
    {
        return EdgeHosts::mask($secret);
    }

    /**
     * @param array<string,mixed> $h base_edge_hosts row
     * @return array<string,mixed>
     */
    private static function view(array $h): array
    {
        $h['ENABLED'] = ((int)$h['ENABLED'] === 1 && EdgeHosts::serviceEnabled($h, self::SERVICE)) ? 1 : 0;
        $h['MAX_INFLIGHT'] = EdgeHosts::maxInflight($h, self::SERVICE);
        $last = EdgeHosts::lastStatus($h, self::SERVICE);
        $h['LAST_STATUS'] = $last === [] ? '' : (string)json_encode($last, JSON_UNESCAPED_UNICODE);
        // Host LAST_SEEN also moves on video syncs; ASR health needs its own last sync.
        if (isset($last['at'])) {
            $h['LAST_SEEN'] = (int)$last['at'];
        }
        return $h;
    }
}
