<?php
declare(strict_types=1);

final class StageTiming
{
    /** @var list<array{stage:string,sec:float}> */
    private static $rows = [];
    private static $anchor = 0.0;
    private static $started = 0.0;

    public static function reset(?float $anchorTime = null): void
    {
        self::$rows = [];
        $now = $anchorTime ?? microtime(true);
        self::$anchor = $now;
        self::$started = $now;
    }

    public static function mark(string $stage, ?float $sec = null): void
    {
        $now = microtime(true);
        $elapsed = $sec !== null ? round($sec, 3) : round($now - self::$anchor, 3);
        self::$rows[] = ['stage' => $stage, 'sec' => $elapsed];
        self::$anchor = $now;
    }

    public static function add(string $stage, float $sec): void
    {
        self::$rows[] = ['stage' => $stage, 'sec' => round($sec, 3)];
    }

    public static function startedAt(): float
    {
        return self::$started > 0 ? self::$started : microtime(true);
    }

    public static function total(): float
    {
        return round(microtime(true) - self::startedAt(), 3);
    }

    /** @return list<array{stage:string,sec:float}> */
    public static function rows(): array
    {
        return self::$rows;
    }

    public static function reportHtml(): string
    {
        $total = self::total();
        $sum = 0.0;
        $html = '<div class="timing-stages"><b>' . e(t('app.timing.title')) . '</b><ul>';
        foreach (self::$rows as $row) {
            $sum += $row['sec'];
            $html .= '<li>' . e($row['stage']) . ': <b>' . sprintf('%.3f', $row['sec']) . '</b> ' . e(t('app.timing.sec')) . '</li>';
        }
        $html .= '</ul>';
        $html .= '<div class="timing-sum">' . e(t('app.timing.sum')) . ': <b>' . sprintf('%.3f', $sum) . '</b> ' . e(t('app.timing.sec'));
        $html .= ' · ' . e(t('app.timing.total')) . ': <b>' . sprintf('%.3f', $total) . '</b> ' . e(t('app.timing.sec'));
        $other = round($total - $sum, 3);
        if ($other > 0.001) {
            $html .= ' · ' . e(t('app.timing.other')) . ': <b>' . sprintf('%.3f', $other) . '</b> ' . e(t('app.timing.sec'));
        }
        $html .= '</div></div>';
        return $html;
    }
}
