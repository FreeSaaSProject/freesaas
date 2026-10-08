<?php
declare(strict_types=1);

/**
 * Deposit tags in account-move DESCR: [депо:open|income|rate%|openDate|closeDate].
 */
final class DepoTagParser
{
    /** @return array{str:string,ts:int} */
    public static function parseDate(string $s): array
    {
        $s = trim($s);
        if (!preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$/', $s, $dm)) {
            return ['str' => '', 'ts' => 0];
        }
        $y = (int)$dm[3];
        if ($y < 100) {
            $y += 2000;
        }
        $str = sprintf('%02d.%02d.%04d', (int)$dm[1], (int)$dm[2], $y);
        $ts = (int)mktime(23, 59, 59, (int)$dm[2], (int)$dm[1], $y);

        return ['str' => $str, 'ts' => $ts];
    }

    /**
     * @return list<array{
     *   open_summ:float,income:float,rate:float,open:string,open_ts:int,
     *   close:string,close_ts:int,summ:float,until:string,until_ts:int,owner:string,raw:string
     * }>
     */
    public static function parse(string $descr): array
    {
        $out = [];
        if ($descr === '' || !preg_match_all('/\[депо:([^\]]+)\]/u', $descr, $m)) {
            return $out;
        }
        foreach ($m[1] as $body) {
            $parts = explode('|', $body);
            $openSumm = 0.0;
            $income = 0.0;
            $rate = 0.0;
            $open = '';
            $openTs = 0;
            $close = '';
            $closeTs = 0;
            $isNew = (isset($parts[2]) && strpos($parts[2], '%') !== false)
                || (count($parts) >= 5 && isset($parts[1]) && strpos((string)$parts[1], '%') === false);
            if ($isNew) {
                $openSumm = isset($parts[0]) ? (float)str_replace([' ', ','], ['', '.'], $parts[0]) : 0.0;
                $income = isset($parts[1]) ? (float)str_replace([' ', ','], ['', '.'], $parts[1]) : 0.0;
                if (isset($parts[2]) && $parts[2] !== '') {
                    $rate = (float)str_replace(['%', ','], ['', '.'], $parts[2]);
                }
                if (isset($parts[3])) {
                    $d = self::parseDate($parts[3]);
                    $open = $d['str'];
                    $openTs = $d['ts']
                        ? (int)mktime(0, 0, 0, (int)date('n', $d['ts']), (int)date('j', $d['ts']), (int)date('Y', $d['ts']))
                        : 0;
                }
                if (isset($parts[4])) {
                    $d = self::parseDate($parts[4]);
                    $close = $d['str'];
                    $closeTs = $d['ts'];
                }
            } else {
                $openSumm = isset($parts[0]) ? (float)str_replace([' ', ','], ['', '.'], $parts[0]) : 0.0;
                if (isset($parts[1]) && $parts[1] !== '') {
                    $rate = (float)str_replace(['%', ','], ['', '.'], $parts[1]);
                }
                if (isset($parts[2])) {
                    $d = self::parseDate($parts[2]);
                    $close = $d['str'];
                    $closeTs = $d['ts'];
                }
            }
            $out[] = [
                'open_summ' => $openSumm,
                'income' => $income,
                'rate' => $rate,
                'open' => $open,
                'open_ts' => $openTs,
                'close' => $close,
                'close_ts' => $closeTs,
                'summ' => $openSumm,
                'until' => $close,
                'until_ts' => $closeTs,
                'owner' => '',
                'raw' => '[депо:' . $body . ']',
            ];
        }

        return $out;
    }

    public static function format(float $openSumm, float $income, float $rate, string $openDate, string $closeDate): string
    {
        $openSumm = round($openSumm, 2);
        $income = round($income, 2);
        $rate = round($rate, 4);
        $openDate = trim($openDate);
        $closeDate = trim($closeDate);
        $body = $openSumm . '|' . $income . '|' . $rate . '%'
            . ($openDate !== '' ? '|' . $openDate : '|')
            . ($closeDate !== '' ? '|' . $closeDate : '');

        return '[депо:' . $body . ']';
    }

    public static function strip(string $descr): string
    {
        return trim((string)preg_replace('/\s*\[депо:[^\]]+\]\s*/u', ' ', $descr));
    }
}
