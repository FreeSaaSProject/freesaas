<?php
declare(strict_types=1);

/**
 * Lightweight cost counters for /audio/calendar/reparse (time, memory, throughput).
 * Accumulators survive stem meta-refresh via query string.
 */
final class ReparseCost
{
    private float $t0;
    private float $markAt;
    private string $markLabel = '';

    /** @var array<string, float> */
    private array $stepsSec = [];

    /** @var array{sec:float,processed:int,skipped:int,batches:int,peak_mb:float,pending_start:int} */
    private array $acc;

    /**
     * @param array<string,mixed> $query
     */
    public function __construct(array $query = [])
    {
        $this->t0 = microtime(true);
        $this->markAt = $this->t0;
        $this->acc = [
            'sec' => max(0.0, (float)($query['acc_sec'] ?? 0)),
            'processed' => max(0, (int)($query['acc_proc'] ?? 0)),
            'skipped' => max(0, (int)($query['acc_skip'] ?? 0)),
            'batches' => max(0, (int)($query['acc_batches'] ?? 0)),
            'peak_mb' => max(0.0, (float)($query['acc_peak'] ?? 0)),
            'pending_start' => max(0, (int)($query['acc_pend0'] ?? 0)),
        ];
    }

    public function mark(string $label): void
    {
        $now = microtime(true);
        if ($this->markLabel !== '') {
            $this->stepsSec[$this->markLabel] = ($this->stepsSec[$this->markLabel] ?? 0.0)
                + ($now - $this->markAt);
        }
        $this->markLabel = $label;
        $this->markAt = $now;
    }

    public function endMark(): void
    {
        if ($this->markLabel === '') {
            return;
        }
        $now = microtime(true);
        $this->stepsSec[$this->markLabel] = ($this->stepsSec[$this->markLabel] ?? 0.0)
            + ($now - $this->markAt);
        $this->markLabel = '';
        $this->markAt = $now;
    }

    public function requestSec(): float
    {
        return microtime(true) - $this->t0;
    }

    public function memMb(): float
    {
        return round(memory_get_usage(true) / 1048576, 1);
    }

    public function peakMb(): float
    {
        return round(memory_get_peak_usage(true) / 1048576, 1);
    }

    public function touchPeak(): void
    {
        $peak = $this->peakMb();
        if ($peak > $this->acc['peak_mb']) {
            $this->acc['peak_mb'] = $peak;
        }
    }

    public function setPendingStart(int $n): void
    {
        if ($this->acc['pending_start'] < 1 && $n > 0) {
            $this->acc['pending_start'] = $n;
        }
    }

    public function addStemBatch(int $processed, int $skipped): void
    {
        $this->endMark();
        $this->acc['processed'] += max(0, $processed);
        $this->acc['skipped'] += max(0, $skipped);
        $this->acc['batches']++;
        $this->touchPeak();
    }

    public function addCollectDone(): void
    {
        $this->endMark();
        $this->touchPeak();
    }

    /**
     * Query params to carry accumulators into the next stem refresh.
     * Fold this request's wall time into acc_sec so the next hit continues the total.
     *
     * @return array<string,scalar>
     */
    public function queryParams(): array
    {
        $this->touchPeak();
        return [
            'acc_sec' => round($this->acc['sec'] + $this->requestSec(), 2),
            'acc_proc' => $this->acc['processed'],
            'acc_skip' => $this->acc['skipped'],
            'acc_batches' => $this->acc['batches'],
            'acc_peak' => round(max($this->acc['peak_mb'], $this->peakMb()), 1),
            'acc_pend0' => $this->acc['pending_start'],
        ];
    }

    /**
     * One-line snapshot for the live log.
     */
    public function line(string $prefix = 'cost'): string
    {
        $this->touchPeak();
        $req = $this->requestSec();
        $parts = [
            sprintf('%s: req=%.2fs', $prefix, $req),
            sprintf('mem=%.1fMb', $this->memMb()),
            sprintf('peak=%.1fMb', $this->peakMb()),
        ];
        if ($this->markLabel !== '') {
            $parts[] = sprintf('step[%s]=%.2fs', $this->markLabel, microtime(true) - $this->markAt);
        }
        foreach ($this->stepsSec as $name => $sec) {
            $parts[] = sprintf('%s=%.2fs', $name, $sec);
        }
        $accSec = $this->acc['sec'] + $req;
        if ($this->acc['batches'] > 0 || $this->acc['processed'] > 0) {
            $rate = $accSec > 0.01
                ? round($this->acc['processed'] / $accSec, 2)
                : 0.0;
            $parts[] = sprintf(
                'acc: batches=%d proc=%d skip=%d sec=%.1f rate=%.2f rec/s peakMax=%.1fMb',
                $this->acc['batches'],
                $this->acc['processed'],
                $this->acc['skipped'],
                $accSec,
                $rate,
                $this->acc['peak_mb']
            );
            if ($this->acc['pending_start'] > 0) {
                $parts[] = 'pend0=' . $this->acc['pending_start'];
            }
        }
        return implode(' · ', $parts);
    }

    /**
     * Final summary block (HTML-safe plain text lines).
     *
     * @return list<string>
     */
    public function summaryLines(int $pendingLeft = 0): array
    {
        $this->endMark();
        $this->touchPeak();
        $accSec = $this->acc['sec'] + $this->requestSec();
        $proc = $this->acc['processed'];
        $rate = $accSec > 0.01 ? round($proc / $accSec, 2) : 0.0;
        $lines = [
            sprintf('TOTAL wall≈%.1fs (this req %.2fs)', $accSec, $this->requestSec()),
            sprintf('mem now=%.1fMb peak=%.1fMb (session peakMax=%.1fMb)', $this->memMb(), $this->peakMb(), $this->acc['peak_mb']),
            sprintf(
                'stem batches=%d processed=%d skipped=%d → %.2f records/s',
                $this->acc['batches'],
                $proc,
                $this->acc['skipped'],
                $rate
            ),
        ];
        if ($this->acc['pending_start'] > 0) {
            $done = max(0, $this->acc['pending_start'] - max(0, $pendingLeft));
            $lines[] = sprintf(
                'queue: start=%d left=%d cleared=%d',
                $this->acc['pending_start'],
                $pendingLeft,
                $done
            );
        }
        foreach ($this->stepsSec as $name => $sec) {
            $lines[] = sprintf('step %s: %.2fs', $name, $sec);
        }
        return $lines;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $this->touchPeak();
        return [
            'request_sec' => round($this->requestSec(), 3),
            'mem_mb' => $this->memMb(),
            'peak_mb' => $this->peakMb(),
            'steps_sec' => array_map(static fn ($v) => round($v, 3), $this->stepsSec),
            'acc' => [
                'sec' => round($this->acc['sec'] + $this->requestSec(), 3),
                'processed' => $this->acc['processed'],
                'skipped' => $this->acc['skipped'],
                'batches' => $this->acc['batches'],
                'peak_mb' => $this->acc['peak_mb'],
                'pending_start' => $this->acc['pending_start'],
            ],
        ];
    }
}
