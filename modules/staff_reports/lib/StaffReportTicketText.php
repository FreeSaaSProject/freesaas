<?php
declare(strict_types=1);

/**
 * i18n builders + multi-locale/legacy parsers for quality-report ticket comments.
 */
final class StaffReportTicketText
{
    /** @var array<string, list<string>> */
    private static $templateCache = [];

    /** @var list<string>|null */
    private static $dayDateRegexCache = null;

    /** Day report comment marker (first line), current locale. */
    public static function dayMarker(string $dayText): string
    {
        return t('staff_reports.report.marker', ['day' => $dayText]);
    }

    /** Footer line: granted / not granted (plain text, no HTML). */
    public static function rewardFooter(string $dayText, int $sum, bool $granted): string
    {
        $key = $granted ? 'staff_reports.report.reward_yes' : 'staff_reports.report.reward_no';

        return t($key, [
            'day' => $dayText,
            'sum' => (string)max(0, $sum),
        ]);
    }

    /** PRICE_OFFER child comment marker (find/upsert key). */
    public static function contractMarker(string $dayText): string
    {
        return t('staff_reports.report.contract_marker', ['day' => $dayText]);
    }

    /** Full PRICE_OFFER comment body. */
    public static function contractBody(string $dayText): string
    {
        return t('staff_reports.report.contract_body', ['day' => $dayText]);
    }

    /**
     * @return list<string>
     */
    public static function dayMarkerVariants(string $dayText): array
    {
        return self::fillDayTemplates('staff_reports.report.marker', $dayText, [
            'Отчёт за {day}',
            'Report for {day}',
        ]);
    }

    /**
     * @return list<string>
     */
    public static function contractMarkerVariants(string $dayText): array
    {
        return self::fillDayTemplates('staff_reports.report.contract_marker', $dayText, [
            'Вознаграждение за отчёт {day}',
            'Reward for report {day}',
        ]);
    }

    public static function findDayReportCommentId(int $ticketId, string $dayText): int
    {
        foreach (self::dayMarkerVariants($dayText) as $marker) {
            $id = TicketWriter::findCommentId($ticketId, $marker);
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    public static function findContractCommentId(int $ticketId, string $dayText): int
    {
        foreach (self::contractMarkerVariants($dayText) as $marker) {
            $id = TicketWriter::findCommentId($ticketId, $marker);
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    /**
     * SQL LIKE needles that match day-report comments across locales.
     *
     * @return list<string>
     */
    public static function dayMarkerLikeNeedles(): array
    {
        $needles = [];
        foreach (self::catalogTemplates('staff_reports.report.marker', [
            'Отчёт за {day}',
            'Report for {day}',
        ]) as $tpl) {
            $prefix = self::templatePrefixBeforeDay($tpl);
            if ($prefix !== '') {
                $needles[] = '%' . $prefix . '%';
            }
        }

        return array_values(array_unique($needles));
    }

    /**
     * @return array{day:int,month:int,year:int,day_text:string}|null
     */
    public static function parseDayFromPlain(string $plain): ?array
    {
        foreach (self::dayDateRegexes() as $re) {
            if (!preg_match($re, $plain, $m)) {
                continue;
            }
            $d = (int)$m[1];
            $mo = (int)$m[2];
            $y = (int)$m[3];
            if ($d < 1 || $d > 31 || $mo < 1 || $mo > 12 || $y < 2000) {
                continue;
            }

            return [
                'day' => $d,
                'month' => $mo,
                'year' => $y,
                'day_text' => sprintf('%02d.%02d.%04d', $d, $mo, $y),
            ];
        }

        return null;
    }

    /**
     * @return array{sum:int,granted:bool}|null
     */
    public static function parseRewardFromPlain(string $plain): ?array
    {
        $patterns = [
            // RU legacy + current (hyphen / em dash, optional р).
            '/Вознаграждение за \d{2}\.\d{2}\.\d{4} в (?P<sum>\d+)\s*р?\s*[—\-]\s*(?P<token>НАЧИСЛЯЕТСЯ|НЕ\s+начисляется)/u',
            // EN
            '/Reward for \d{2}\.\d{2}\.\d{4} of (?P<sum>\d+)\s*[—\-]\s*(?P<token>GRANTED|NOT\s+granted)/iu',
        ];
        foreach ($patterns as $re) {
            if (!preg_match($re, $plain, $m)) {
                continue;
            }
            $token = (string)($m['token'] ?? '');
            $granted = (bool)preg_match('/^(НАЧИСЛЯЕТСЯ|GRANTED)$/iu', $token);

            return ['sum' => max(0, (int)($m['sum'] ?? 0)), 'granted' => $granted];
        }

        return null;
    }

    public static function plainGrantsReward(string $plain): bool
    {
        $parsed = self::parseRewardFromPlain($plain);
        if ($parsed !== null) {
            return !empty($parsed['granted']);
        }
        if (preg_match('/-\s*НАЧИСЛЯЕТСЯ/u', $plain) || preg_match('/—\s*НАЧИСЛЯЕТСЯ/u', $plain)) {
            return true;
        }
        if (preg_match('/\bGRANTED\b/i', $plain) && !preg_match('/NOT\s+granted/i', $plain)) {
            return true;
        }

        return false;
    }

    public static function plainNoRecords(string $plain): bool
    {
        foreach (self::catalogTemplates('staff_reports.report.audio_skip_empty', []) as $tpl) {
            $core = self::templateLiteralCore($tpl);
            if ($core !== '' && mb_stripos($plain, $core) !== false) {
                return true;
            }
        }
        if (mb_stripos($plain, 'нет аудиозаписей за день') !== false) {
            return true;
        }
        if (stripos($plain, 'no audio records') !== false) {
            return true;
        }

        return false;
    }

    /**
     * @param list<string> $legacyTemplates
     * @return list<string>
     */
    private static function fillDayTemplates(string $key, string $dayText, array $legacyTemplates): array
    {
        $out = [];
        foreach (self::catalogTemplates($key, $legacyTemplates) as $tpl) {
            $out[] = str_replace('{day}', $dayText, $tpl);
        }
        $out[] = t($key, ['day' => $dayText]);

        return array_values(array_unique(array_filter($out, static fn ($s) => trim($s) !== '')));
    }

    /**
     * @param list<string> $legacy
     * @return list<string>
     */
    private static function catalogTemplates(string $key, array $legacy): array
    {
        $cacheKey = $key . "\0" . implode("\0", $legacy);
        if (isset(self::$templateCache[$cacheKey])) {
            return self::$templateCache[$cacheKey];
        }
        $out = $legacy;
        if (class_exists('I18n', false)) {
            foreach (I18n::supported() as $loc) {
                $cat = I18n::collectCatalogKeys($loc);
                $v = trim((string)($cat[$key] ?? ''));
                if ($v !== '') {
                    $out[] = $v;
                }
            }
        }

        return self::$templateCache[$cacheKey] = array_values(array_unique($out));
    }

    private static function templatePrefixBeforeDay(string $tpl): string
    {
        $pos = mb_strpos($tpl, '{day}');
        if ($pos === false) {
            return trim($tpl);
        }

        return trim(mb_substr($tpl, 0, $pos));
    }

    private static function templateLiteralCore(string $tpl): string
    {
        $parts = preg_split('/\{[a-z_]+\}/u', $tpl) ?: [];
        $best = '';
        foreach ($parts as $p) {
            $p = trim((string)$p);
            $p = preg_replace('/^[\s\p{P}\p{S}]+|[\s\p{P}\p{S}]+$/u', '', $p) ?? $p;
            if (mb_strlen($p) > mb_strlen($best)) {
                $best = $p;
            }
        }

        return $best;
    }

    /**
     * @return list<string>
     */
    private static function dayDateRegexes(): array
    {
        if (self::$dayDateRegexCache !== null) {
            return self::$dayDateRegexCache;
        }
        $res = [];
        foreach (self::catalogTemplates('staff_reports.report.marker', [
            'Отчёт за {day}',
            'Report for {day}',
        ]) as $tpl) {
            if (strpos($tpl, '{day}') === false) {
                continue;
            }
            $parts = explode('{day}', $tpl, 2);
            $re = '/' . preg_quote($parts[0], '/')
                . '(\d{2})\.(\d{2})\.(\d{4})'
                . preg_quote($parts[1] ?? '', '/')
                . '/u';
            $res[] = $re;
        }

        return self::$dayDateRegexCache = array_values(array_unique($res));
    }
}
