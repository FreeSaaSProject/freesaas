<?php
declare(strict_types=1);

/** Amount in words for contracts/acts ({event.total_text}); Russian grammar, plain number otherwise. */
final class DocsMoney
{
    public static function words(float $amount): string
    {
        $rub = (int)floor($amount + 0.0001);
        $kop = (int)round(($amount - $rub) * 100);
        if (I18n::locale() !== 'ru') {
            $words = $rub === 0 ? 'zero' : self::enNumber($rub);
            return ucfirst($words) . ' ' . ($rub === 1 ? 'rouble' : 'roubles')
                . ' ' . sprintf('%02d', $kop) . ' ' . ($kop === 1 ? 'kopeck' : 'kopecks');
        }
        $words = $rub === 0 ? 'ноль' : self::ruNumber($rub);
        return self::ucfirst($words) . ' ' . self::plural($rub, 'рубль', 'рубля', 'рублей')
            . ' ' . sprintf('%02d', $kop) . ' ' . self::plural($kop, 'копейка', 'копейки', 'копеек');
    }

    private static function ruNumber(int $n): string
    {
        $units = [
            ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'],
            ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'],
        ];
        $teens = ['десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать',
            'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];
        $tens = ['', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];
        $hundreds = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];
        // [one, few, many, feminine]
        $scales = [
            ['', '', '', false],
            ['тысяча', 'тысячи', 'тысяч', true],
            ['миллион', 'миллиона', 'миллионов', false],
            ['миллиард', 'миллиарда', 'миллиардов', false],
        ];
        $parts = [];
        $scale = 0;
        while ($n > 0 && $scale < count($scales)) {
            $chunk = $n % 1000;
            $n = intdiv($n, 1000);
            if ($chunk > 0) {
                $w = [];
                $w[] = $hundreds[intdiv($chunk, 100)];
                $rest = $chunk % 100;
                if ($rest >= 10 && $rest < 20) {
                    $w[] = $teens[$rest - 10];
                } else {
                    $w[] = $tens[intdiv($rest, 10)];
                    $w[] = $units[$scales[$scale][3] ? 1 : 0][$rest % 10];
                }
                if ($scale > 0) {
                    $w[] = self::plural($chunk, $scales[$scale][0], $scales[$scale][1], $scales[$scale][2]);
                }
                array_unshift($parts, implode(' ', array_filter($w, static fn(string $s): bool => $s !== '')));
            }
            $scale++;
        }
        return implode(' ', $parts);
    }

    private static function enNumber(int $n): string
    {
        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve',
            'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        $scales = ['', 'thousand', 'million', 'billion'];
        $parts = [];
        $scale = 0;
        while ($n > 0 && $scale < count($scales)) {
            $chunk = $n % 1000;
            $n = intdiv($n, 1000);
            if ($chunk > 0) {
                $w = [];
                if ($chunk >= 100) {
                    $w[] = $ones[intdiv($chunk, 100)] . ' hundred';
                }
                $rest = $chunk % 100;
                if ($rest >= 20) {
                    $w[] = $tens[intdiv($rest, 10)] . ($rest % 10 > 0 ? '-' . $ones[$rest % 10] : '');
                } elseif ($rest > 0) {
                    $w[] = $ones[$rest];
                }
                if ($scales[$scale] !== '') {
                    $w[] = $scales[$scale];
                }
                array_unshift($parts, implode(' ', $w));
            }
            $scale++;
        }
        return implode(' ', $parts);
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n) % 100;
        if ($n >= 11 && $n <= 19) {
            return $many;
        }
        $n %= 10;
        if ($n === 1) {
            return $one;
        }
        return ($n >= 2 && $n <= 4) ? $few : $many;
    }

    private static function ucfirst(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }
}
