<?php
declare(strict_types=1);

/**
 * Deterministic RNG with named streams.
 * Same seed + same call order => same data on the same PHP build.
 */
final class ClinicDemoRng
{
    /** @var array<string,int> */
    private static array $streams = [];

    public function __construct(string $stream, int $seed)
    {
        $key = $stream . '|' . $seed;
        self::$streams[$stream] = crc32($key);
        mt_srand(self::$streams[$stream]);
    }

    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }
        return mt_rand($min, $max);
    }

    public function float(): float
    {
        return mt_rand(0, 1000000) / 1000000;
    }

    public function chance(float $p): bool
    {
        return $this->float() < $p;
    }

    /** @param list<mixed> $items */
    public function pick(array $items)
    {
        $n = count($items);
        if ($n === 0) {
            return null;
        }
        return $items[$this->int(0, $n - 1)];
    }

    /**
     * @param array<string,int|float> $weights
     */
    public function weighted(array $weights): ?string
    {
        $total = 0.0;
        foreach ($weights as $w) {
            $total += max(0.0, (float)$w);
        }
        if ($total <= 0.0) {
            return null;
        }
        $roll = $this->float() * $total;
        $acc = 0.0;
        foreach ($weights as $key => $w) {
            $acc += max(0.0, (float)$w);
            if ($roll <= $acc) {
                return (string)$key;
            }
        }
        foreach (array_keys($weights) as $key) {
            return (string)$key;
        }
        return null;
    }

    /**
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    public function shuffled(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        return $items;
    }

    /** Rough normal distribution bounded to [min,max]. */
    public function gauss(int $mean, int $sd, int $min, int $max): int
    {
        $sum = 0;
        for ($i = 0; $i < 4; $i++) {
            $sum += mt_rand($mean - $sd, $mean + $sd);
        }
        $v = (int)round($sum / 4);
        return max($min, min($max, $v));
    }
}
