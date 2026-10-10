<?php
declare(strict_types=1);

/**
 * Day budget vs active target (elos63: remainders + low/over advice).
 */
final class FitDayBalance
{
    /**
     * @param array<string,mixed>|null $target
     * @param array<string,float|int> $totals keys: ccals, proteins, fats, carbs
     * @return array{
     *   burned:int,
     *   target:array{ccals:int,proteins:float,fats:float,carbs:float},
     *   left:array{ccals:int,proteins:int,fats:int,carbs:int},
     *   advice:string
     * }|null
     */
    public static function compute(?array $target, array $totals, int $burnedCcals): ?array
    {
        if ($target === null) {
            return null;
        }
        $tCals = (int)$target['ccals'];
        $tP = (float)$target['proteins'];
        $tF = (float)$target['fats'];
        $tU = (float)$target['carbs'];
        $burned = max(0, $burnedCcals);
        $left = [
            'ccals' => (int)round($tCals - (float)($totals['ccals'] ?? 0) + $burned),
            'proteins' => (int)round($tP - (float)($totals['proteins'] ?? 0)),
            'fats' => (int)round($tF - (float)($totals['fats'] ?? 0)),
            'carbs' => (int)round($tU - (float)($totals['carbs'] ?? 0)),
        ];
        $targetNorm = [
            'ccals' => $tCals,
            'proteins' => $tP,
            'fats' => $tF,
            'carbs' => $tU,
        ];

        return [
            'burned' => $burned,
            'target' => $targetNorm,
            'left' => $left,
            'advice' => self::adviceSentence($left, $targetNorm),
        ];
    }

    public static function cssClass(int $value): string
    {
        return $value < 0 ? 'wellness-left-neg' : 'wellness-left-ok';
    }

    /**
     * @param array{ccals:int,proteins:int,fats:int,carbs:int} $left
     * @param array{ccals:int,proteins:float,fats:float,carbs:float} $target
     */
    public static function adviceSentence(array $left, array $target): string
    {
        $low = [];
        $over = [];
        $map = [
            'ccals' => 'wellness.advice.macro_ccals',
            'proteins' => 'wellness.advice.macro_proteins',
            'fats' => 'wellness.advice.macro_fats',
            'carbs' => 'wellness.advice.macro_carbs',
        ];
        foreach ($map as $key => $labelKey) {
            $kind = self::warnKind((float)$left[$key], (float)$target[$key]);
            if ($kind === 'low') {
                $low[] = t($labelKey);
            } elseif ($kind === 'over') {
                $over[] = t($labelKey);
            }
        }
        $chunks = [];
        if ($low !== []) {
            $chunks[] = t('wellness.advice.low', ['list' => self::joinAnd($low)]);
        }
        if ($over !== []) {
            $chunks[] = t('wellness.advice.over', ['list' => self::joinAnd($over)]);
        }
        if ($chunks === []) {
            return '';
        }

        return t('wellness.advice.summary', ['text' => implode(', ', $chunks)]);
    }

    /** @return 'low'|'over'|'' */
    private static function warnKind(float $remaining, float $target): string
    {
        if ($remaining < 0) {
            return 'over';
        }
        if ($target > 0 && $remaining <= ($target * 0.15)) {
            return 'low';
        }

        return '';
    }

    /** @param list<string> $parts */
    private static function joinAnd(array $parts): string
    {
        $parts = array_values(array_filter($parts, static function ($x): bool {
            return $x !== '' && $x !== null;
        }));
        $n = count($parts);
        if ($n === 0) {
            return '';
        }
        if ($n === 1) {
            return $parts[0];
        }
        if ($n === 2) {
            return t('wellness.list.join2', ['a' => $parts[0], 'b' => $parts[1]]);
        }
        $last = array_pop($parts);

        return t('wellness.list.join_many', [
            'list' => implode(', ', $parts),
            'last' => (string)$last,
        ]);
    }
}
