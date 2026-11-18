<?php
declare(strict_types=1);

/**
 * Float32 L2-normalized speaker embedding helpers (WeSpeaker; dim from model, e.g. 256/512).
 *
 * Wire format: little-endian float32 (`pack('g')`). Older rows may have been
 * written with `pack('e')`, which on some PHP builds is an 8-byte double —
 * unpack() detects that via EMBEDDING_DIM / blob length.
 */
final class SpeakerEmbedding
{
    public static function pack(array $values): string
    {
        $out = '';
        foreach ($values as $v) {
            $out .= pack('g', (float)$v); // little-endian float32
        }
        return $out;
    }

    /**
     * @return list<float>
     */
    public static function unpack(?string $blob, int $dim = 0): array
    {
        $blob = (string)$blob;
        $len = strlen($blob);
        if ($len < 4) {
            return [];
        }

        $arr = null;
        if ($dim > 0) {
            if ($len === $dim * 4) {
                $arr = @unpack('g' . $dim, $blob);
            } elseif ($len === $dim * 8) {
                // Legacy: pack('e') stored machine doubles on this PHP.
                $arr = @unpack('e' . $dim, $blob);
            }
        } elseif (($len % 4) === 0) {
            $n4 = intdiv($len, 4);
            $n8 = (($len % 8) === 0) ? intdiv($len, 8) : 0;
            // Prefer float32; if length looks like classic 256-d doubles, use e.
            if ($n8 === 256 || $n8 === 192) {
                $arr = @unpack('e' . $n8, $blob);
            } else {
                $arr = @unpack('g' . $n4, $blob);
            }
        }

        if (!is_array($arr) || $arr === []) {
            return [];
        }
        $out = [];
        foreach ($arr as $v) {
            $out[] = (float)$v;
        }
        return $out;
    }

    /** @param list<float> $a @param list<float> $b */
    public static function cosineSimilarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n < 1) {
            return 0.0;
        }
        $dot = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
        }
        return max(0.0, min(1.0, $dot));
    }

    /**
     * EMA blend of two L2-normalized vectors; returns normalized result.
     *
     * @param list<float> $current
     * @param list<float> $incoming
     * @return list<float>
     */
    public static function ema(array $current, array $incoming, float $alpha = 0.15): array
    {
        $n = min(count($current), count($incoming));
        if ($n < 1) {
            return $incoming !== [] ? self::l2norm($incoming) : [];
        }
        if ($current === []) {
            return self::l2norm($incoming);
        }
        $alpha = max(0.01, min(0.9, $alpha));
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = ((1.0 - $alpha) * $current[$i]) + ($alpha * $incoming[$i]);
        }
        return self::l2norm($out);
    }

    /** @param list<float> $v @return list<float> */
    public static function l2norm(array $v): array
    {
        $sum = 0.0;
        foreach ($v as $x) {
            $sum += $x * $x;
        }
        $norm = sqrt($sum);
        if ($norm < 1e-8) {
            return $v;
        }
        $out = [];
        foreach ($v as $x) {
            $out[] = $x / $norm;
        }
        return $out;
    }

    /** Mean of embeddings, then L2-normalize. @param list<list<float>> $vecs */
    public static function centroid(array $vecs): array
    {
        if ($vecs === []) {
            return [];
        }
        $dim = count($vecs[0]);
        if ($dim < 1) {
            return [];
        }
        $acc = array_fill(0, $dim, 0.0);
        $n = 0;
        foreach ($vecs as $v) {
            if (count($v) < $dim) {
                continue;
            }
            for ($i = 0; $i < $dim; $i++) {
                $acc[$i] += $v[$i];
            }
            $n++;
        }
        if ($n < 1) {
            return [];
        }
        for ($i = 0; $i < $dim; $i++) {
            $acc[$i] /= $n;
        }
        return self::l2norm($acc);
    }
}
