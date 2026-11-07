<?php
declare(strict_types=1);

/** Type registration for LongJob handlers (module-owned budgets / tick). */
final class LongJobRegistry
{
    /** Platform safety ceiling only — domain clamps live in module defaults. */
    public const TICK_SECONDS_HARD_MIN = 1.0;
    public const TICK_SECONDS_HARD_MAX = 600.0;

    /** @var array<string,array<string,mixed>> */
    private static $types = [];

    /**
     * @param array{
     *   module?:string,
     *   permission?:string,
     *   capability?:string,
     *   defaults?:array<string,mixed>,
     *   contexts?:array<string,array<string,mixed>>,
     *   store_dir?:string,
     *   tick?:callable|array{0:class-string,1:string},
     *   on_start?:callable|array{0:class-string,1:string},
     *   on_cancel?:callable|array{0:class-string,1:string},
     *   agent?:array{mode?:string,assistant?:bool}
     * } $meta
     */
    public static function register(string $type, array $meta): void
    {
        $type = strtolower(trim($type));
        if ($type === '' || !preg_match('/^[a-z0-9_.-]+$/', $type)) {
            throw new InvalidArgumentException('Invalid LongJob type');
        }
        self::$types[$type] = $meta;
    }

    public static function has(string $type): bool
    {
        return isset(self::$types[strtolower(trim($type))]);
    }

    /** @return array<string,mixed> */
    public static function get(string $type): array
    {
        $type = strtolower(trim($type));
        if (!isset(self::$types[$type])) {
            throw new InvalidArgumentException('Unknown LongJob type: ' . $type);
        }
        return self::$types[$type];
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::$types);
    }

    /**
     * Merge defaults + named context + explicit opts; clamp tick_seconds to platform ceiling.
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function resolveStartOpts(string $type, array $opts = []): array
    {
        $meta = self::get($type);
        $out = is_array($meta['defaults'] ?? null) ? $meta['defaults'] : [];
        $ctx = trim((string)($opts['context'] ?? ''));
        if ($ctx !== '' && is_array($meta['contexts'][$ctx] ?? null)) {
            $out = array_merge($out, $meta['contexts'][$ctx]);
        }
        unset($opts['context']);
        $out = array_merge($out, $opts);
        if (isset($out['tick_seconds'])) {
            $out['tick_seconds'] = self::clampTickSeconds((float)$out['tick_seconds']);
        }
        return $out;
    }

    public static function clampTickSeconds(float $sec): float
    {
        if ($sec <= 0) {
            return 0.0;
        }
        return max(self::TICK_SECONDS_HARD_MIN, min(self::TICK_SECONDS_HARD_MAX, $sec));
    }

    public static function storeDirFor(string $type): ?string
    {
        $meta = self::get($type);
        $dir = $meta['store_dir'] ?? null;
        return is_string($dir) && $dir !== '' ? $dir : null;
    }

    /**
     * @param callable|array{0:class-string,1:string}|null $cb
     * @param array<int,mixed> $args
     * @return mixed
     */
    public static function invoke($cb, array $args = [])
    {
        if ($cb === null) {
            return null;
        }
        if (is_array($cb) && count($cb) === 2) {
            return call_user_func_array($cb, $args);
        }
        if (is_callable($cb)) {
            return call_user_func_array($cb, $args);
        }
        throw new RuntimeException('Invalid LongJob handler');
    }
}
