<?php
declare(strict_types=1);

/**
 * Year-over-year (YoY / ГкГ) helpers ported from oper show_balance5.
 */
final class FinanceYoy
{
    /**
     * @param array<string,mixed> $opts
     * @return array{
     *   mode:string, as_of:int, nav_year:int,
     *   periods:array<int,array{start:int,end:int,Y:int,m:int}>,
     *   pairs:list<array{a:int|list<int>,b:int|list<int>,label:string}>,
     *   idx_a:list<int>, idx_b:list<int>,
     *   label_a:string, label_b:string,
     *   opening_n:int, parse_end:int
     * }
     */
    public static function buildPeriodGrid(
        string $mode,
        int $asOf,
        int $navYear,
        int $dtStart,
        int $dtEnd,
        int $yStart,
        int $yEnd,
        int $mEnd
    ): array {
        $mode = in_array($mode, ['month', 'ytd', 'rolling'], true) ? $mode : 'ytd';
        $asOfY = (int)date('Y', $asOf);
        $asOfM = (int)date('n', $asOf);
        $asOfD = (int)date('j', $asOf);

        $periods = [];
        $n = 1;
        $pairs = [];
        $chartPairs = [];
        $idxA = [];
        $idxB = [];
        $labelA = '';
        $labelB = '';
        $openingN = 0;

        if ($mode === 'month') {
            if ($navYear < 1) {
                $navYear = ($yEnd > 0) ? $yEnd : $asOfY;
            }
            if ($yStart > 0 && $navYear <= $yStart) {
                $navYear = $yStart + 1;
            }
            if ($yEnd > 0 && $navYear > $yEnd) {
                $navYear = $yEnd;
            }
            $yComp = $navYear;
            $yPrev = $yComp - 1;

            $openEndY = $yPrev - 1;
            $yPrevStart = self::mktime(0, 0, 0, 1, 1, $yPrev);
            if ($openEndY >= $yStart && $dtStart < $yPrevStart) {
                $openingN = self::addPeriod(
                    $periods,
                    $n,
                    (int)date('Y', $dtStart),
                    (int)date('n', $dtStart),
                    (int)date('j', $dtStart),
                    $openEndY,
                    12,
                    31
                );
            }

            $lastMPrev = 12;
            $lastMComp = 12;
            if ($yPrev === $yEnd) {
                $lastMPrev = $mEnd;
            }
            if ($yComp === $yEnd) {
                $lastMComp = $mEnd;
            }
            $maxPair = min(12, max($lastMPrev, $lastMComp));
            $idxAByM = [];
            $idxBByM = [];
            for ($m = 1; $m <= $maxPair; $m++) {
                if ($m <= $lastMPrev || ($yEnd > 0 && $yPrev < $yEnd) || $yEnd < 1) {
                    $dEnd = (int)date('d', self::mktime(23, 59, 59, $m + 1, 0, $yPrev));
                    $idxAByM[$m] = self::addPeriod($periods, $n, $yPrev, $m, 1, $yPrev, $m, $dEnd);
                }
            }
            for ($m = 1; $m <= $maxPair; $m++) {
                if ($m <= $lastMComp || ($yEnd > 0 && $yComp < $yEnd) || $yEnd < 1) {
                    $dEnd = (int)date('d', self::mktime(23, 59, 59, $m + 1, 0, $yComp));
                    $idxBByM[$m] = self::addPeriod($periods, $n, $yComp, $m, 1, $yComp, $m, $dEnd);
                }
            }
            for ($m = 1; $m <= $maxPair; $m++) {
                if (!empty($idxAByM[$m]) && !empty($idxBByM[$m])) {
                    $pairs[] = [
                        'a' => $idxAByM[$m],
                        'b' => $idxBByM[$m],
                        'label' => self::monthLabel($m) . ' ' . $yPrev . '/' . $yComp,
                    ];
                    $idxA[] = $idxAByM[$m];
                    $idxB[] = $idxBByM[$m];
                    $chartPairs[] = [
                        'a' => $idxAByM[$m],
                        'b' => $idxBByM[$m],
                        'label' => self::monthLabel($m),
                    ];
                }
            }
            $labelA = (string)$yPrev;
            $labelB = (string)$yComp;
        } elseif ($mode === 'ytd') {
            $yB = $asOfY;
            $yA = $asOfY - 1;
            $yAStart = self::mktime(0, 0, 0, 1, 1, $yA);
            if ($dtStart < $yAStart) {
                $openingN = self::addPeriod(
                    $periods,
                    $n,
                    (int)date('Y', $dtStart),
                    (int)date('n', $dtStart),
                    (int)date('j', $dtStart),
                    $yA - 1,
                    12,
                    31
                );
            }

            for ($m = 1; $m <= $asOfM; $m++) {
                $dEndA = ($m === $asOfM) ? $asOfD : (int)date('d', self::mktime(23, 59, 59, $m + 1, 0, $yA));
                $idx = self::addPeriod($periods, $n, $yA, $m, 1, $yA, $m, $dEndA);
                $idxA[] = $idx;
                $chartPairs[] = ['a' => $idx, 'b' => 0, 'label' => self::monthLabel($m)];
            }
            if ($asOfM < 12) {
                $gapStartD = $asOfD + 1;
                $gapStartM = $asOfM;
                $gapStartY = $yA;
                $maxD = (int)date('d', self::mktime(23, 59, 59, $asOfM + 1, 0, $yA));
                if ($gapStartD > $maxD) {
                    $gapStartD = 1;
                    $gapStartM = $asOfM + 1;
                }
                if ($gapStartM <= 12) {
                    self::addPeriod($periods, $n, $gapStartY, $gapStartM, $gapStartD, $yA, 12, 31);
                }
            } elseif ($asOfD < 31) {
                self::addPeriod($periods, $n, $yA, 12, $asOfD + 1, $yA, 12, 31);
            }

            $ci = 0;
            for ($m = 1; $m <= $asOfM; $m++) {
                $dEndB = ($m === $asOfM) ? $asOfD : (int)date('d', self::mktime(23, 59, 59, $m + 1, 0, $yB));
                $idx = self::addPeriod($periods, $n, $yB, $m, 1, $yB, $m, $dEndB);
                $idxB[] = $idx;
                if (isset($chartPairs[$ci])) {
                    $chartPairs[$ci]['b'] = $idx;
                }
                $ci++;
            }
            $labelA = 'YTD ' . $yA . ' (01.01–' . sprintf('%02d.%02d', $asOfD, $asOfM) . '.' . $yA . ')';
            $labelB = 'YTD ' . $yB . ' (01.01–' . sprintf('%02d.%02d', $asOfD, $asOfM) . '.' . $yB . ')';
            $pairs[] = ['a' => $idxA, 'b' => $idxB, 'label' => 'YTD'];
        } else {
            // rolling 12 months
            $startB = self::mktime(0, 0, 0, $asOfM, $asOfD, $asOfY - 1);
            $endB = $asOf;
            $endA = $startB - 1;
            $startA = self::mktime(0, 0, 0, $asOfM, $asOfD, $asOfY - 2);

            if ($dtStart < $startA) {
                $openingN = self::addPeriod(
                    $periods,
                    $n,
                    (int)date('Y', $dtStart),
                    (int)date('n', $dtStart),
                    (int)date('j', $dtStart),
                    (int)date('Y', $startA - 1),
                    (int)date('n', $startA - 1),
                    (int)date('j', $startA - 1)
                );
            }

            $cursor = $startA;
            while ($cursor <= $endB) {
                $cy = (int)date('Y', $cursor);
                $cm = (int)date('n', $cursor);
                $cd = (int)date('j', $cursor);
                $monthEnd = self::mktime(23, 59, 59, $cm + 1, 0, $cy);
                $sliceEnd = $monthEnd;
                if ($sliceEnd > $endB) {
                    $sliceEnd = $endB;
                }
                if ($cursor <= $endA && $sliceEnd > $endA) {
                    $idx = self::addPeriod(
                        $periods,
                        $n,
                        $cy,
                        $cm,
                        $cd,
                        (int)date('Y', $endA),
                        (int)date('n', $endA),
                        (int)date('j', $endA)
                    );
                    $idxA[] = $idx;
                    $cursor = $startB;
                    continue;
                }
                $idx = self::addPeriod(
                    $periods,
                    $n,
                    $cy,
                    $cm,
                    $cd,
                    (int)date('Y', $sliceEnd),
                    (int)date('n', $sliceEnd),
                    (int)date('j', $sliceEnd)
                );
                if ($sliceEnd <= $endA) {
                    $idxA[] = $idx;
                } else {
                    $idxB[] = $idx;
                }
                $cursor = $sliceEnd + 1;
            }
            $cnt = min(count($idxA), count($idxB));
            for ($i = 0; $i < $cnt; $i++) {
                $labA = self::monthLabel((int)($periods[$idxA[$i]]['m'] ?? 0));
                $labB = self::monthLabel((int)($periods[$idxB[$i]]['m'] ?? 0));
                $lab = ($labA === $labB) ? $labA : ($labA . '→' . $labB);
                $chartPairs[] = [
                    'a' => $idxA[$i],
                    'b' => $idxB[$i],
                    'label' => '#' . ($i + 1) . ' ' . $lab,
                ];
            }
            $labelA = date('d.m.Y', $startA) . '–' . date('d.m.Y', $endA);
            $labelB = date('d.m.Y', $startB) . '–' . date('d.m.Y', $endB);
            $pairs[] = ['a' => $idxA, 'b' => $idxB, 'label' => 'Rolling'];
        }

        $parseEnd = 0;
        foreach ($periods as $p) {
            if ($p['end'] > $parseEnd) {
                $parseEnd = $p['end'];
            }
        }

        return [
            'mode' => $mode,
            'as_of' => $asOf,
            'nav_year' => $navYear,
            'periods' => $periods,
            'pairs' => $pairs,
            'chart_pairs' => $chartPairs,
            'idx_a' => $idxA,
            'idx_b' => $idxB,
            'label_a' => $labelA,
            'label_b' => $labelB,
            'opening_n' => $openingN,
            'parse_end' => $parseEnd,
            'next_n' => $n,
        ];
    }

    /**
     * @param array<int,array{start:int,end:int,Y:int,m:int}> $periods
     */
    public static function addPeriod(
        array &$periods,
        int &$n,
        int $y,
        int $m,
        int $dStart,
        int $y2,
        int $m2,
        int $dEnd
    ): int {
        $start = self::mktime(0, 0, 0, $m, $dStart, $y);
        $end = self::mktime(23, 59, 59, $m2, $dEnd, $y2);
        $idx = $n;
        $periods[$idx] = [
            'start' => $start,
            'end' => $end,
            'Y' => $y,
            'm' => $m,
            'chart_only' => false,
        ];
        $n++;
        return $idx;
    }

    public static function monthLabel(int $m): string
    {
        $key = 'finance.yoy.month.' . $m;
        if (function_exists('t')) {
            $s = t($key);
            if ($s !== $key) {
                return $s;
            }
        }
        $fallback = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
            7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
        ];
        return $fallback[$m] ?? (string)$m;
    }

    public static function monthLabelFull(int $m): string
    {
        $key = 'finance.yoy.month_full.' . $m;
        if (function_exists('t')) {
            $s = t($key);
            if ($s !== $key) {
                return $s;
            }
        }
        return self::monthLabel($m);
    }

    /**
     * @param array<int,array{start:int,end:int,Y:int,m:int,chart_only?:bool}> $periods
     */
    public static function findPeriodIdx(array $periods, int $y, int $m): int
    {
        foreach ($periods as $pn => $p) {
            if ((int)$pn < 1 || !is_array($p)) {
                continue;
            }
            // Prefer periods that start on day 1 of the month (chart buckets).
            if ((int)($p['Y'] ?? 0) === $y && (int)($p['m'] ?? 0) === $m) {
                $start = (int)($p['start'] ?? 0);
                if ($start > 0 && (int)date('j', $start) === 1) {
                    return (int)$pn;
                }
            }
        }
        return 0;
    }

    /**
     * Extra monthly periods for multi-year line chart (excluded from tables).
     *
     * @param array<int,array{start:int,end:int,Y:int,m:int,chart_only?:bool}> $periods
     * @return array{year_month_idx:array<int,array<int,int>>, next_n:int, parse_end:int}
     */
    public static function appendMultiYearChartPeriods(
        array &$periods,
        int $n,
        int $yoyYears,
        int $asOfY,
        int $asOfM,
        int $asOfD,
        int $yStart,
        int $yEnd,
        int $mEnd
    ): array {
        $yearMonthIdx = [];
        $startYear = max($yStart, $asOfY - $yoyYears + 1);
        $parseEnd = 0;
        for ($y = $startYear; $y <= $asOfY; $y++) {
            $firstM = 1;
            $lastM = 12;
            if ($y === $yStart) {
                $firstM = 1; // data may start mid-year; still chart from available months
            }
            if ($y === $asOfY) {
                $lastM = $asOfM;
            } elseif ($y === $yEnd) {
                $lastM = $mEnd;
            }
            for ($m = $firstM; $m <= $lastM; $m++) {
                $idx = self::findPeriodIdx($periods, $y, $m);
                if ($idx < 1) {
                    $dEnd = (int)date('d', self::mktime(23, 59, 59, $m + 1, 0, $y));
                    if ($y === $asOfY && $m === $asOfM) {
                        $dEnd = $asOfD;
                    }
                    $idx = self::addPeriod($periods, $n, $y, $m, 1, $y, $m, $dEnd);
                    $periods[$idx]['chart_only'] = true;
                }
                $yearMonthIdx[$y][$m] = $idx;
                $end = (int)($periods[$idx]['end'] ?? 0);
                if ($end > $parseEnd) {
                    $parseEnd = $end;
                }
            }
        }
        return [
            'year_month_idx' => $yearMonthIdx,
            'next_n' => $n,
            'parse_end' => $parseEnd,
        ];
    }

    /** @return array<string,array{title:string,src:string,key:string}> */
    public static function chartSeriesDefs(): array
    {
        $t = static function (string $key, string $fallback): string {
            if (function_exists('t')) {
                $s = t($key);
                if ($s !== $key) {
                    return $s;
                }
            }
            return $fallback;
        };
        return [
            'revenue' => ['title' => $t('finance.yoy.metric.revenue', 'Revenue'), 'src' => 'OPIU', 'key' => 'revenue'],
            'ebit' => ['title' => $t('finance.yoy.metric.ebit', 'EBIT'), 'src' => 'OPIU', 'key' => 'ebit'],
            'ebi' => ['title' => $t('finance.yoy.metric.ebi', 'EBI'), 'src' => 'OPIU', 'key' => 'ebi'],
            'cf' => ['title' => $t('finance.yoy.metric.cf', 'Net CF'), 'src' => 'ODDS', 'key' => 'cf_total'],
            'ros' => ['title' => $t('finance.yoy.metric.ros', 'ROS %'), 'src' => 'KANAL', 'key' => 'ros'],
            'roce_op' => ['title' => $t('finance.yoy.metric.roce_op', 'ROCE op %'), 'src' => 'KANAL', 'key' => 'roce_ebit'],
        ];
    }

    /**
     * @param array<string,array<int,float|string>> $rawOpiu
     * @param array<string,array<int,float|string>> $rawOdds
     * @param array<string,array<int,float|string>> $rawKanal
     * @param list<array{a:int,b:int,label:string}> $chartPairs
     * @return array{labels:list<string>,series:array<string,array{title:string,a:list<float>,b:list<float>}>}
     */
    public static function buildCompareChartPayload(
        array $rawOpiu,
        array $rawOdds,
        array $rawKanal,
        array $chartPairs
    ): array {
        $seriesDefs = self::chartSeriesDefs();
        $payload = ['labels' => [], 'series' => []];
        foreach ($chartPairs as $cp) {
            $payload['labels'][] = (string)($cp['label'] ?? '');
        }
        foreach ($seriesDefs as $sid => $sd) {
            if ($sd['src'] === 'OPIU') {
                $raw = $rawOpiu;
            } elseif ($sd['src'] === 'ODDS') {
                $raw = $rawOdds;
            } else {
                $raw = $rawKanal;
            }
            $arrA = [];
            $arrB = [];
            $key = $sd['key'];
            foreach ($chartPairs as $cp) {
                $ia = (int)($cp['a'] ?? 0);
                $ib = (int)($cp['b'] ?? 0);
                $va = (isset($raw[$key][$ia]) && is_numeric($raw[$key][$ia])) ? round((float)$raw[$key][$ia], 2) : 0.0;
                $vb = (isset($raw[$key][$ib]) && is_numeric($raw[$key][$ib])) ? round((float)$raw[$key][$ib], 2) : 0.0;
                $arrA[] = $va;
                $arrB[] = $vb;
            }
            $payload['series'][$sid] = ['title' => $sd['title'], 'a' => $arrA, 'b' => $arrB];
        }
        return $payload;
    }

    /**
     * @param array<string,array<int,float|string>> $rawOpiu
     * @param array<string,array<int,float|string>> $rawOdds
     * @param array<string,array<int,float|string>> $rawKanal
     * @param array<int,array<int,int>> $yearMonthIdx
     * @return array{labels:list<string>,years:list<int>,series:array<string,array{title:string,years:array<string,list<?float>}>}}
     */
    public static function buildMultiYearChartPayload(
        array $rawOpiu,
        array $rawOdds,
        array $rawKanal,
        array $yearMonthIdx
    ): array {
        $seriesDefs = self::chartSeriesDefs();
        $years = array_keys($yearMonthIdx);
        sort($years, SORT_NUMERIC);
        $labels = [];
        for ($m = 1; $m <= 12; $m++) {
            $labels[] = self::monthLabelFull($m);
        }
        $payload = ['labels' => $labels, 'years' => $years, 'series' => []];
        foreach ($seriesDefs as $sid => $sd) {
            if ($sd['src'] === 'OPIU') {
                $raw = $rawOpiu;
            } elseif ($sd['src'] === 'ODDS') {
                $raw = $rawOdds;
            } else {
                $raw = $rawKanal;
            }
            $yearSeries = [];
            $key = $sd['key'];
            foreach ($years as $y) {
                $row = [];
                for ($m = 1; $m <= 12; $m++) {
                    if (!isset($yearMonthIdx[$y][$m])) {
                        $row[] = null;
                        continue;
                    }
                    $idx = (int)$yearMonthIdx[$y][$m];
                    if (isset($raw[$key][$idx]) && is_numeric($raw[$key][$idx])) {
                        $row[] = round((float)$raw[$key][$idx], 2);
                    } else {
                        $row[] = null;
                    }
                }
                $yearSeries[(string)$y] = $row;
            }
            $payload['series'][$sid] = ['title' => $sd['title'], 'years' => $yearSeries];
        }
        return $payload;
    }

    /**
     * @param array<string,array<int,float|string>> $src
     * @param list<array{a:int,b:int,label:string}> $pairs
     * @return array{matrix:array<string,array<int,float|string>>,period_labels:array<int,string>,diff_cols:list<int>}
     */
    public static function buildPairedMatrix(array $src, array $pairs): array
    {
        $out = [];
        $periodLabels = [];
        $diffCols = [];
        if ($src === []) {
            return ['matrix' => $out, 'period_labels' => $periodLabels, 'diff_cols' => $diffCols];
        }

        $deltaLabel = function_exists('t') ? t('finance.yoy.delta') : 'Δ%';

        foreach ($src as $rowKey => $row) {
            $out[$rowKey] = [];
            $out[$rowKey][0] = $row[0] ?? $rowKey;
            $col = 1;
            foreach ($pairs as $pair) {
                $idxA = (int)$pair['a'];
                $idxB = (int)$pair['b'];
                $lab = (string)($pair['label'] ?? '');
                $valA = $row[$idxA] ?? 0;
                $valB = $row[$idxB] ?? 0;
                if (is_numeric($valA)) {
                    $valA = $valA + 0;
                }
                if (is_numeric($valB)) {
                    $valB = $valB + 0;
                }

                if ($rowKey === 'period') {
                    $out[$rowKey][$col] = $lab . ' A';
                    if (!isset($periodLabels[$col])) {
                        $periodLabels[$col] = $lab . ' A';
                    }
                    $col++;
                    $out[$rowKey][$col] = $lab . ' B';
                    if (!isset($periodLabels[$col])) {
                        $periodLabels[$col] = $lab . ' B';
                    }
                    $col++;
                    $out[$rowKey][$col] = $deltaLabel;
                    if (!isset($periodLabels[$col])) {
                        $periodLabels[$col] = $deltaLabel;
                        $diffCols[] = $col;
                    }
                    $col++;
                } else {
                    $numA = is_numeric($valA);
                    $numB = is_numeric($valB);
                    $out[$rowKey][$col] = $numA ? round((float)$valA, 2) : (is_string($valA) ? $valA : 0);
                    $col++;
                    $out[$rowKey][$col] = $numB ? round((float)$valB, 2) : (is_string($valB) ? $valB : 0);
                    $col++;
                    $out[$rowKey][$col] = ($numA && $numB)
                        ? self::getDiffCell((float)$valA, (float)$valB)
                        : '';
                    $col++;
                }
            }
        }

        return ['matrix' => $out, 'period_labels' => $periodLabels, 'diff_cols' => $diffCols];
    }

    /**
     * @param array<string,array<int,float|string>> $src
     * @param list<int> $idxListA
     * @param list<int> $idxListB
     * @return array{matrix:array<string,array<int,float|string>>,period_labels:array<int,string>,diff_cols:list<int>}
     */
    public static function buildAggPairedMatrix(
        array $src,
        array $idxListA,
        array $idxListB,
        string $labelA,
        string $labelB,
        string $matrixName
    ): array {
        $out = [];
        $deltaLabel = function_exists('t') ? t('finance.yoy.delta') : 'Δ%';
        $periodLabels = [1 => $labelA, 2 => $labelB, 3 => $deltaLabel];
        $diffCols = [3];
        if ($src === []) {
            return ['matrix' => $out, 'period_labels' => $periodLabels, 'diff_cols' => $diffCols];
        }

        foreach ($src as $rowKey => $row) {
            $mode = self::rowAggMode((string)$rowKey, $matrixName);
            $valA = self::aggregateCells($row, $idxListA, $mode);
            $valB = self::aggregateCells($row, $idxListB, $mode);
            $out[$rowKey] = [];
            $out[$rowKey][0] = $row[0] ?? $rowKey;
            if ($rowKey === 'period') {
                $out[$rowKey][1] = $labelA;
                $out[$rowKey][2] = $labelB;
                $out[$rowKey][3] = $deltaLabel;
            } else {
                $numA = is_numeric($valA);
                $numB = is_numeric($valB);
                $out[$rowKey][1] = $numA ? round((float)$valA, 2) : (is_string($valA) ? $valA : 0);
                $out[$rowKey][2] = $numB ? round((float)$valB, 2) : (is_string($valB) ? $valB : 0);
                $out[$rowKey][3] = ($numA && $numB)
                    ? self::getDiffCell((float)$valA, (float)$valB)
                    : '';
            }
        }

        return ['matrix' => $out, 'period_labels' => $periodLabels, 'diff_cols' => $diffCols];
    }

    /**
     * @param array<int,float|string> $row
     * @param list<int> $indices
     * @return float|string
     */
    public static function aggregateCells(array $row, array $indices, string $mode = 'sum')
    {
        $sum = 0.0;
        $last = 0.0;
        $first = 0.0;
        $gotFirst = false;
        $any = false;
        $nonNumeric = null;
        foreach ($indices as $idx) {
            if (!array_key_exists($idx, $row)) {
                continue;
            }
            $v = $row[$idx];
            if (!is_numeric($v)) {
                if ($nonNumeric === null && is_string($v) && trim(strip_tags($v)) !== '') {
                    $nonNumeric = $v;
                }
                continue;
            }
            $any = true;
            $last = (float)$v;
            if (!$gotFirst) {
                $first = $last;
                $gotFirst = true;
            }
            $sum += $last;
        }
        if (!$any) {
            return $nonNumeric !== null ? $nonNumeric : 0;
        }
        if ($mode === 'last') {
            return $last;
        }
        if ($mode === 'first') {
            return $first;
        }
        return $sum;
    }

    /** @return list<string> */
    public static function stockRowKeys(string $matrixName): array
    {
        if (
            $matrixName === 'ACCOUNTS'
            || $matrixName === 'BALANCE'
            || $matrixName === 'FUND_MATRIX'
            || $matrixName === 'KANAL'
        ) {
            return ['*'];
        }
        if ($matrixName === 'ODDS') {
            return ['cash_start', 'cash_end'];
        }
        return ['cash_end'];
    }

    public static function rowAggMode(string $rowKey, string $matrixName): string
    {
        if ($rowKey === 'cash_start') {
            return 'first';
        }
        $stocks = self::stockRowKeys($matrixName);
        if (in_array('*', $stocks, true)) {
            return 'last';
        }
        if (in_array($rowKey, $stocks, true)) {
            return 'last';
        }
        return 'sum';
    }

    /**
     * Recalculate ratios from aggregated OPIU/BALANCE (ytd/rolling).
     *
     * @param array<string,array<int,float|string>> $opiu
     * @param array<string,array<int,float|string>> $balance
     * @param array<string,array<int,float|string>> $kanalTemplate
     * @param array<int,array{odds_type:int,is_div:int}> $typesOuts
     * @return array<string,array<int,float|string>>
     */
    public static function recalculateKanal(
        array $opiu,
        array $balance,
        array $kanalTemplate,
        array $typesOuts,
        string $labelA,
        string $labelB
    ): array {
        $deltaLabel = function_exists('t') ? t('finance.yoy.delta') : 'Δ%';
        $out = [];
        $out['period'] = [
            0 => $kanalTemplate['period'][0] ?? (function_exists('t') ? t('finance.odds.period') : ''),
            1 => $labelA,
            2 => $labelB,
            3 => $deltaLabel,
        ];

        $ebiA = self::num($opiu, 'ebi', 1);
        $ebiB = self::num($opiu, 'ebi', 2);
        $ebitA = self::num($opiu, 'ebit', 1);
        $ebitB = self::num($opiu, 'ebit', 2);
        $revA = self::num($opiu, 'revenue', 1);
        $revB = self::num($opiu, 'revenue', 2);
        $saA = self::num($balance, 'eq_tot', 1);
        $saB = self::num($balance, 'eq_tot', 2);
        $doA = self::num($balance, 'lt_tot', 1);
        $doB = self::num($balance, 'lt_tot', 2);

        $divA = 0.0;
        $divB = 0.0;
        foreach ($typesOuts as $tid => $td) {
            if ((int)$td['odds_type'] === 3 && !empty($td['is_div'])) {
                $divA += abs(self::num($opiu, 't_' . $tid, 1));
                $divB += abs(self::num($opiu, 't_' . $tid, 2));
            }
        }

        $rows = [
            'ros' => [
                $revA ? round($ebiA * 100 / $revA, 2) : 0.0,
                $revB ? round($ebiB * 100 / $revB, 2) : 0.0,
            ],
            'roce' => [
                ($saA + $doA) > 0 ? round($ebiA * 100 / ($saA + $doA), 2) : 0.0,
                ($saB + $doB) > 0 ? round($ebiB * 100 / ($saB + $doB), 2) : 0.0,
            ],
            'roe' => [
                $saA ? round($ebiA * 100 / $saA, 2) : 0.0,
                $saB ? round($ebiB * 100 / $saB, 2) : 0.0,
            ],
            'roe_ebit' => [
                $saA ? round($ebitA * 100 / $saA, 2) : 0.0,
                $saB ? round($ebitB * 100 / $saB, 2) : 0.0,
            ],
            'roce_ebit' => [
                ($saA + $doA) > 0 ? round($ebitA * 100 / ($saA + $doA), 2) : 0.0,
                ($saB + $doB) > 0 ? round($ebitB * 100 / ($saB + $doB), 2) : 0.0,
            ],
            'dpr' => [
                $ebiA ? round($divA * 100 / $ebiA, 2) : 0.0,
                $ebiB ? round($divB * 100 / $ebiB, 2) : 0.0,
            ],
        ];

        foreach ($rows as $kr => $vals) {
            if (!isset($kanalTemplate[$kr][0])) {
                continue;
            }
            $out[$kr] = [
                0 => $kanalTemplate[$kr][0],
                1 => $vals[0],
                2 => $vals[1],
                3 => self::getDiffCell($vals[0], $vals[1]),
            ];
        }

        return $out;
    }

    /** Δ% cell: plain "0%" / "+12.5%" / "-3.1%" / "∞%" (view styles it). */
    public static function getDiffCell($val1, $val2): string
    {
        if (!is_numeric($val1) || !is_numeric($val2)) {
            return '';
        }
        $a = (float)$val1;
        $b = (float)$val2;
        if ($a == $b) {
            return '0%';
        }
        if ($a == 0.0) {
            return '∞%';
        }
        $diff = $b - $a;
        $pct = round($diff * 100 / $a, 1);
        $sign = ($diff > 0) ? '+' : '';
        return $sign . $pct . '%';
    }

    public static function getDiff($val1 = false, $val2 = false): string
    {
        if (!is_numeric($val1) || !is_numeric($val2)) {
            return '';
        }
        $a = (float)$val1;
        $b = (float)$val2;
        if ($a == $b) {
            return '';
        }
        if ($a == 0.0) {
            return $b == 0.0 ? '' : ' ∞%';
        }
        $diff = $b - $a;
        $pct = round($diff * 100 / $a, 1);
        return ' ' . $pct . '%';
    }

    /** @param int|string $asOf */
    public static function parseAsOf($asOf): int
    {
        if (is_int($asOf) && $asOf > 0) {
            return $asOf;
        }
        if (is_string($asOf) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $asOf, $m)) {
            $ts = self::mktime(23, 59, 59, (int)$m[2], (int)$m[3], (int)$m[1]);
            return $ts > 0 ? $ts : time();
        }
        if (is_numeric($asOf) && (int)$asOf > 0) {
            return (int)$asOf;
        }
        return time();
    }

    /**
     * @param array<string,array<int,float|string>> $matrix
     */
    private static function num(array $matrix, string $key, int $col): float
    {
        $v = $matrix[$key][$col] ?? 0;
        return is_numeric($v) ? (float)$v : 0.0;
    }

    private static function mktime(int $h, int $i, int $s, int $m, int $d, int $y): int
    {
        $ts = @mktime($h, $i, $s, $m, $d, $y);
        return $ts !== false ? $ts : 0;
    }
}
