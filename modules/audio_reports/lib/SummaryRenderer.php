<?php
declare(strict_types=1);

/**
 * View-model builder for audio summary reports (oper rs_render_summary_html parity).
 */
final class SummaryRenderer
{
    /**
     * @param array<string,string> $labels
     */
    public static function tip(array $labels, string $key, string $fallback = ''): string
    {
        $v = isset($labels[$key]) ? trim((string)$labels[$key]) : '';
        if ($v !== '') {
            return $v;
        }
        $i18n = 'audio_reports.summaries.' . $key;
        if (function_exists('te') === false && function_exists('t')) {
            $tr = t($i18n);
            if ($tr !== $i18n) {
                return $tr;
            }
        } elseif (function_exists('t')) {
            $tr = t($i18n);
            if ($tr !== $i18n) {
                return $tr;
            }
        }
        return $fallback;
    }

    /**
     * @param array<string,string> $labels
     */
    public static function label(array $labels, string $key, string $fallbackKey = ''): string
    {
        $v = isset($labels[$key]) ? trim((string)$labels[$key]) : '';
        if ($v !== '') {
            return $v;
        }
        // short alias (card_volume → volume)
        if (strpos($key, 'card_') === 0) {
            $short = substr($key, 5);
            $v2 = isset($labels[$short]) ? trim((string)$labels[$short]) : '';
            if ($v2 !== '') {
                return $v2;
            }
        }
        $i18n = $fallbackKey !== '' ? $fallbackKey : ('audio_reports.summaries.label.' . $key);
        return t($i18n);
    }

    /**
     * @param mixed $value
     */
    public static function formatNumber($value, int $decimals = 0): string
    {
        if ($value === null) {
            return '—';
        }
        $decimals = max(0, min(4, $decimals));
        if ($decimals > 0) {
            return number_format((float)$value, $decimals, '.', ' ');
        }
        return number_format(round((float)$value, 0), 0, '.', ' ');
    }

    /**
     * @param mixed $pass
     */
    public static function computedPassColor($pass): string
    {
        if ($pass === 1 || $pass === '1') {
            return '#16a34a';
        }
        if ($pass === 0 || $pass === '0') {
            return '#dc2626';
        }
        return '#334155';
    }

    /**
     * Compact count targets for month-nav animation / agent clients.
     *
     * @param array<string,mixed> $report
     * @return array<string,float|int>
     */
    public static function countTargets(array $report): array
    {
        $starts = 0;
        $finishes = 0;
        if (!empty($report['scripts']) && is_array($report['scripts'])) {
            foreach ($report['scripts'] as $sm) {
                $starts += (int)($sm['starts'] ?? 0);
                $finishes += (int)($sm['finishes'] ?? 0);
            }
        }
        $conv = ($starts > 0) ? round($finishes * 100 / $starts, 1) : 0.0;
        $out = [
            'audio_hours' => (float)($report['total_audio_hours'] ?? 0),
            'key_instances' => (int)($report['total_key_instances'] ?? 0),
            'avg_score' => (float)($report['global_avg_score'] ?? 0),
            'score_pass_days' => (int)($report['global_score_pass_days'] ?? 0),
            'score_fail_days' => (int)($report['global_score_fail_days'] ?? 0),
            'criteria_count' => isset($report['criteria']) && is_array($report['criteria'])
                ? count($report['criteria']) : 0,
            'script_conv' => $conv,
            'script_starts' => $starts,
            'script_finishes' => $finishes,
            'script_score_total' => (float)($report['total_script_score'] ?? 0),
            'benchmark_score' => (float)($report['benchmark_score'] ?? 0),
            'growth_avg_score' => (float)($report['global_avg_score'] ?? 0),
            'growth_fail_days' => (int)($report['global_score_fail_days'] ?? 0),
        ];
        if (!empty($report['tracking']) && is_array($report['tracking'])) {
            foreach ($report['tracking'] as $did => $td) {
                $out['track_' . (int)$did] = (int)($td['count'] ?? 0);
            }
        }
        if (!empty($report['external']) && is_array($report['external'])) {
            foreach ($report['external'] as $ext) {
                if (empty($ext['key'])) {
                    continue;
                }
                $out['ext_' . $ext['key']] = (float)($ext['total'] ?? 0);
            }
        }
        return $out;
    }

    /**
     * Field refs for computed-metric constructor selects.
     *
     * @param array<string,mixed> $config
     * @return array<string,string> ref => label
     */
    public static function fieldOptions(array $config): array
    {
        $opts = [
            'audio_hours' => t('audio_reports.summaries.fieldref.audio_hours'),
            'avg_score' => t('audio_reports.summaries.fieldref.avg_score'),
            'script_score' => t('audio_reports.summaries.fieldref.script_score'),
            'instances' => t('audio_reports.summaries.fieldref.instances'),
            'script_conversion' => t('audio_reports.summaries.fieldref.script_conversion'),
            'days_count' => t('audio_reports.summaries.fieldref.days_count'),
            'days_pass_score' => t('audio_reports.summaries.fieldref.days_pass_score'),
            'days_fail_score' => t('audio_reports.summaries.fieldref.days_fail_score'),
            'script_starts' => t('audio_reports.summaries.fieldref.script_starts'),
            'script_finishes' => t('audio_reports.summaries.fieldref.script_finishes'),
        ];
        if (!empty($config['external_sources']) && is_array($config['external_sources'])) {
            foreach ($config['external_sources'] as $es) {
                $k = isset($es['key']) ? (string)$es['key'] : '';
                if ($k === '') {
                    continue;
                }
                $title = isset($es['title']) ? (string)$es['title'] : $k;
                $opts['ext.' . $k] = t('audio_reports.summaries.fieldref.ext', ['title' => $title]);
            }
        }
        if (!empty($config['detail_criteria_ids']) && is_array($config['detail_criteria_ids'])) {
            foreach ($config['detail_criteria_ids'] as $tid) {
                $tid = (int)$tid;
                if ($tid > 0) {
                    $opts['crit.' . $tid] = t('audio_reports.summaries.fieldref.crit', ['id' => (string)$tid]);
                }
            }
        }
        if (!empty($config['detail_tracking_dict_ids']) && is_array($config['detail_tracking_dict_ids'])) {
            foreach ($config['detail_tracking_dict_ids'] as $did) {
                $did = (int)$did;
                if ($did > 0) {
                    $opts['track.' . $did] = t('audio_reports.summaries.fieldref.track', ['id' => (string)$did]);
                }
            }
        }
        if (!empty($config['script_ids']) && is_array($config['script_ids'])) {
            foreach ($config['script_ids'] as $sid) {
                $sid = (int)$sid;
                if ($sid <= 0) {
                    continue;
                }
                $opts['script.' . $sid . '.conversion'] = t('audio_reports.summaries.fieldref.script_conv', ['id' => (string)$sid]);
                $opts['script.' . $sid . '.score'] = t('audio_reports.summaries.fieldref.script_scr', ['id' => (string)$sid]);
                $opts['script.' . $sid . '.starts'] = t('audio_reports.summaries.fieldref.script_st', ['id' => (string)$sid]);
                $opts['script.' . $sid . '.finishes'] = t('audio_reports.summaries.fieldref.script_fn', ['id' => (string)$sid]);
            }
        }
        if (!empty($config['computed_metrics']) && is_array($config['computed_metrics'])) {
            foreach ($config['computed_metrics'] as $cm) {
                $k = isset($cm['key']) ? (string)$cm['key'] : '';
                if ($k === '') {
                    continue;
                }
                $title = isset($cm['title']) ? (string)$cm['title'] : $k;
                $opts['calc.' . $k] = t('audio_reports.summaries.fieldref.calc', ['title' => $title]);
            }
        }
        return $opts;
    }

    /**
     * Build structured view-model for report screens / preview.
     *
     * @param array<string,mixed> $report from SummaryRunner::run
     * @param array{preview?:bool,profiles?:list<array>,can_edit?:bool} $opts
     * @return array<string,mixed>
     */
    public static function build(array $report, array $opts = []): array
    {
        $labels = isset($report['labels']) && is_array($report['labels']) ? $report['labels'] : [];
        $config = isset($report['config']) && is_array($report['config']) ? $report['config'] : [];
        $cards = isset($config['cards']) && is_array($config['cards']) ? $config['cards'] : [];
        $threshold = (float)($report['score_pass_threshold'] ?? 1.0);
        $avgScore = (float)($report['global_avg_score'] ?? 0);
        $scoreColor = SummaryRunner::scoreColor($avgScore, $threshold);
        $preview = !empty($opts['preview']);

        $periodMode = (string)($report['period_mode'] ?? 'month');
        $year = (int)($report['year'] ?? date('Y'));
        $month = (int)($report['month'] ?? date('n'));
        $dateFrom = (string)($report['date_from'] ?? '');
        $dateTo = (string)($report['date_to'] ?? '');
        $periodLabel = (string)($report['period_label'] ?? '');

        $prevMonth = $month - 1;
        $prevYear = $year;
        if ($prevMonth < 1) {
            $prevMonth = 12;
            $prevYear--;
        }
        $nextMonth = $month + 1;
        $nextYear = $year;
        if ($nextMonth > 12) {
            $nextMonth = 1;
            $nextYear++;
        }
        $nextStart = mktime(0, 0, 0, $nextMonth, 1, $nextYear);
        $canNext = ($nextStart !== false && $nextStart <= time() && $periodMode !== 'range');

        $bannerTitle = self::label($labels, 'banner_title', 'audio_reports.summaries.label.banner_title');
        $bannerText = self::label($labels, 'banner', 'audio_reports.summaries.label.banner');

        $cardItems = self::buildCards($report, $labels, $cards, $scoreColor, $threshold);
        $growth = self::buildGrowth($report, $labels, $scoreColor);
        $leaders = null;
        $dayColsMeta = null;
        if (in_array('leaders', $cards, true)) {
            $leaders = self::buildLeaders($report, $labels, $config, $threshold);
            $dayColsMeta = $leaders['day_cols'] ?? null;
        }

        return [
            'preview' => $preview,
            'profile_id' => (int)($report['profile_id'] ?? 0),
            'profile_name' => (string)($report['profile_name'] ?? ''),
            'position_name' => (string)($report['position_name'] ?? ''),
            'is_default' => !empty($report['is_default']),
            'period_mode' => $periodMode,
            'period_label' => $periodLabel,
            'year' => $year,
            'month' => $month,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'prev_year' => $prevYear,
            'prev_month' => $prevMonth,
            'next_year' => $nextYear,
            'next_month' => $nextMonth,
            'can_next' => $canNext,
            'score_color' => $scoreColor,
            'threshold' => $threshold,
            'banner' => [
                'title' => $bannerTitle,
                'text' => $bannerText,
                'tip' => self::tip($labels, 'tip_banner'),
            ],
            'cards' => $cardItems,
            'growth' => $growth,
            'leaders' => $leaders,
            'day_cols_json' => $dayColsMeta !== null
                ? (string)json_encode($dayColsMeta, JSON_UNESCAPED_UNICODE)
                : '',
            'counts' => self::countTargets($report),
            'profiles' => isset($opts['profiles']) && is_array($opts['profiles']) ? $opts['profiles'] : [],
            'can_edit' => !empty($opts['can_edit']),
            'tip_growth' => self::tip($labels, 'tip_growth'),
            'tip_detail' => self::tip($labels, 'tip_detail'),
        ];
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,string> $labels
     * @param list<string> $cards
     * @return list<array<string,mixed>>
     */
    private static function buildCards(
        array $report,
        array $labels,
        array $cards,
        string $scoreColor,
        float $threshold
    ): array {
        $out = [];
        if (in_array('volume', $cards, true)) {
            $chars = (int)($report['total_transcription_chars'] ?? 0);
            $charsFmt = ($chars > 1000000)
                ? round($chars / 1000000, 2) . ' ' . t('audio_reports.summaries.unit.m_chars')
                : number_format($chars, 0, '.', ' ') . ' ' . t('audio_reports.summaries.unit.chars');
            $out[] = [
                'id' => 'volume',
                'tip' => self::tip($labels, 'tip_card_volume'),
                'label' => self::label($labels, 'card_volume', 'audio_reports.summaries.card.volume'),
                'value' => (float)($report['total_audio_hours'] ?? 0),
                'decimals' => 1,
                'count_key' => 'audio_hours',
                'unit' => t('audio_reports.summaries.unit.hours_audio'),
                'color' => '#4338ca',
                'sub' => t('audio_reports.summaries.card.volume_text', ['chars' => $charsFmt]),
                'badge' => t('audio_reports.summaries.card.volume_keys', [
                    'n' => (string)(int)($report['total_key_instances'] ?? 0),
                ]),
                'badge_key' => 'key_instances',
                'kind' => 'volume',
            ];
        }
        if (in_array('score', $cards, true)) {
            $avg = (float)($report['global_avg_score'] ?? 0);
            $out[] = [
                'id' => 'score',
                'tip' => self::tip($labels, 'tip_card_score'),
                'label' => self::label($labels, 'card_score', 'audio_reports.summaries.card.score'),
                'value' => $avg,
                'decimals' => 2,
                'count_key' => 'avg_score',
                'unit' => '®',
                'color' => $scoreColor,
                'sub' => t('audio_reports.summaries.card.score_threshold', ['n' => (string)$threshold])
                    . ' ' . ($avg >= $threshold ? '✓' : '↓'),
                'badge' => t('audio_reports.summaries.card.score_days', [
                    'pass' => (string)(int)($report['global_score_pass_days'] ?? 0),
                    'fail' => (string)(int)($report['global_score_fail_days'] ?? 0),
                ]),
                'kind' => 'score',
                'pass_days' => (int)($report['global_score_pass_days'] ?? 0),
                'fail_days' => (int)($report['global_score_fail_days'] ?? 0),
            ];
        }
        if (in_array('criteria', $cards, true)) {
            $topFail = '—';
            if (!empty($report['criteria_fails']) && is_array($report['criteria_fails'])) {
                $parts = [];
                $n = 0;
                foreach ($report['criteria_fails'] as $cn => $miss) {
                    $parts[] = (string)$cn . ' (−' . round((float)$miss, 0) . '%)';
                    if (++$n >= 2) {
                        break;
                    }
                }
                $topFail = implode(', ', $parts);
            }
            $critCount = isset($report['criteria']) && is_array($report['criteria'])
                ? count($report['criteria']) : 0;
            $out[] = [
                'id' => 'criteria',
                'tip' => self::tip($labels, 'tip_card_criteria'),
                'label' => self::label($labels, 'card_criteria', 'audio_reports.summaries.card.criteria'),
                'value' => $critCount,
                'decimals' => 0,
                'count_key' => 'criteria_count',
                'unit' => '',
                'color' => '#1e293b',
                'value_prefix' => t('audio_reports.summaries.card.criteria_in_report') . ': ',
                'badge' => t('audio_reports.summaries.card.criteria_weak', ['list' => $topFail]),
                'badge_class' => 'is-warn',
                'kind' => 'criteria',
            ];
        }
        if (in_array('scripts', $cards, true)) {
            $starts = 0;
            $finishes = 0;
            foreach (($report['scripts'] ?? []) as $sm) {
                $starts += (int)($sm['starts'] ?? 0);
                $finishes += (int)($sm['finishes'] ?? 0);
            }
            $conv = ($starts > 0) ? round($finishes * 100 / $starts, 1) : 0.0;
            $out[] = [
                'id' => 'scripts',
                'tip' => self::tip($labels, 'tip_card_scripts'),
                'label' => self::label($labels, 'card_scripts', 'audio_reports.summaries.card.scripts'),
                'value' => $conv,
                'decimals' => 1,
                'count_key' => 'script_conv',
                'unit' => '%',
                'color' => '#16a34a',
                'sub' => t('audio_reports.summaries.card.scripts_flow', [
                    'starts' => (string)$starts,
                    'finishes' => (string)$finishes,
                ]),
                'kind' => 'scripts',
            ];
        }
        if (in_array('script_score', $cards, true)) {
            $lines = [];
            foreach (($report['scripts'] ?? []) as $sm) {
                $lines[] = [
                    'name' => (string)($sm['NAME'] ?? ''),
                    'score' => round((float)($sm['score'] ?? 0), 0),
                ];
            }
            $out[] = [
                'id' => 'script_score',
                'tip' => self::tip($labels, 'tip_card_script_score'),
                'label' => self::label($labels, 'card_script_score', 'audio_reports.summaries.card.script_score'),
                'value' => (float)($report['total_script_score'] ?? 0),
                'decimals' => 0,
                'count_key' => 'script_score_total',
                'unit' => t('audio_reports.summaries.unit.rub'),
                'color' => '#0f766e',
                'lines' => $lines,
                'kind' => 'script_score',
            ];
        }
        if (in_array('tracking', $cards, true) && !empty($report['tracking'])) {
            $items = [];
            foreach ($report['tracking'] as $did => $td) {
                $items[] = [
                    'id' => (int)$did,
                    'code' => (string)(($td['TRACKING_CODE'] ?? '') !== '' ? $td['TRACKING_CODE'] : '•'),
                    'name' => trim((string)($td['NAME'] ?? '')),
                    'count' => (int)($td['count'] ?? 0),
                    'count_key' => 'track_' . (int)$did,
                ];
            }
            $out[] = [
                'id' => 'tracking',
                'tip' => self::tip($labels, 'tip_card_tracking'),
                'label' => self::label($labels, 'card_tracking', 'audio_reports.summaries.card.tracking'),
                'kind' => 'tracking',
                'color' => '#dc2626',
                'items' => $items,
            ];
        }
        if (!empty($report['external']) && is_array($report['external'])) {
            foreach ($report['external'] as $ext) {
                $meta = isset($ext['meta']) && is_array($ext['meta']) ? $ext['meta'] : [];
                if (empty($meta['show_card'])) {
                    continue;
                }
                $dec = isset($meta['decimals']) ? (int)$meta['decimals'] : 0;
                $unit = isset($meta['unit']) ? trim((string)$meta['unit']) : '';
                $aggLabel = isset($meta['agg']) ? (string)$meta['agg'] : 'sum';
                $extTip = isset($meta['tip']) ? trim((string)$meta['tip']) : '';
                if ($extTip === '') {
                    $extTip = self::tip($labels, 'tip_col_external');
                }
                $out[] = [
                    'id' => 'ext_' . (string)($ext['key'] ?? ''),
                    'tip' => $extTip,
                    'label' => (string)($ext['title'] ?? $ext['key'] ?? ''),
                    'value' => (float)($ext['total'] ?? 0),
                    'decimals' => $dec,
                    'count_key' => 'ext_' . (string)($ext['key'] ?? ''),
                    'unit' => $unit,
                    'color' => '#0369a1',
                    'error' => (string)($ext['error'] ?? ''),
                    'sub' => strtoupper($aggLabel)
                        . (!empty($meta['table']) ? ' · ' . (string)$meta['table'] : '')
                        . ' · ' . t('audio_reports.summaries.card.ext_rows', [
                            'n' => (string)(int)($ext['rows_count'] ?? 0),
                        ]),
                    'kind' => 'external',
                ];
            }
        }
        if (!empty($report['computed']) && is_array($report['computed'])) {
            foreach ($report['computed'] as $cm) {
                $meta = isset($cm['meta']) && is_array($cm['meta']) ? $cm['meta'] : [];
                if (empty($meta['show_card'])) {
                    continue;
                }
                $kind = (string)($cm['kind'] ?? 'result');
                $tip = isset($meta['tip']) ? (string)$meta['tip'] : '';
                $item = [
                    'id' => 'calc_' . (string)($cm['key'] ?? ''),
                    'tip' => $tip,
                    'label' => (string)($cm['title'] ?? ''),
                    'kind' => 'computed',
                    'calc_kind' => $kind,
                ];
                if ($kind === 'check') {
                    $item['pass_count'] = (int)($cm['pass_count'] ?? 0);
                    $item['rows_count'] = (int)($cm['rows_count'] ?? 0);
                    $item['color'] = '#16a34a';
                } else {
                    $dec = isset($meta['decimals']) ? (int)$meta['decimals'] : 1;
                    $unit = isset($meta['unit']) ? trim((string)$meta['unit']) : (($kind === 'percent') ? '%' : '');
                    $item['value'] = $cm['total'] ?? null;
                    $item['decimals'] = $dec;
                    $item['unit'] = $unit;
                    $item['color'] = self::computedPassColor($cm['pass'] ?? null);
                    $item['formatted'] = self::formatNumber($cm['total'] ?? null, $dec);
                }
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,string> $labels
     * @return array<string,mixed>
     */
    private static function buildGrowth(array $report, array $labels, string $scoreColor): array
    {
        $failLines = [];
        if (!empty($report['criteria_fails']) && is_array($report['criteria_fails'])) {
            $i = 0;
            foreach ($report['criteria_fails'] as $cn => $_miss) {
                $failLines[] = (string)$cn;
                if (++$i >= 5) {
                    break;
                }
            }
        }
        $scriptLines = [];
        if (!empty($report['scripts']) && is_array($report['scripts'])) {
            foreach ($report['scripts'] as $sm) {
                $scriptLines[] = [
                    'name' => (string)($sm['NAME'] ?? ''),
                    'conversion' => (float)($sm['conversion'] ?? 0),
                    'finishes' => (int)($sm['finishes'] ?? 0),
                    'starts' => (int)($sm['starts'] ?? 0),
                ];
            }
        }
        $gcRaw = self::label($labels, 'growth_criteria', 'audio_reports.summaries.label.growth_criteria');
        $gcTitle = $gcRaw;
        $gcNames = [];
        if (preg_match('/^(.+?:)\s*(.+)$/u', $gcRaw, $gcm)) {
            $gcTitle = rtrim($gcm[1]);
            $gcNames = preg_split('/\s*,\s*/u', $gcm[2]) ?: [];
        }

        return [
            'score_title' => self::label($labels, 'growth_score', 'audio_reports.summaries.label.growth_score'),
            'criteria_title' => $gcTitle,
            'criteria_names' => array_values(array_filter(array_map('trim', $gcNames))),
            'scripts_title' => self::label($labels, 'growth_scripts', 'audio_reports.summaries.label.growth_scripts'),
            'avg_score' => (float)($report['global_avg_score'] ?? 0),
            'score_color' => $scoreColor,
            'benchmark_score' => (float)($report['benchmark_score'] ?? 0),
            'benchmark_name' => (string)($report['benchmark_employee_name'] ?? ''),
            'fail_days' => (int)($report['global_score_fail_days'] ?? 0),
            'fail_lines' => $failLines,
            'script_lines' => $scriptLines,
        ];
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,string> $labels
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function buildLeaders(
        array $report,
        array $labels,
        array $config,
        float $threshold
    ): array {
        $detailCriteria = !empty($report['detail_criteria']) && is_array($report['detail_criteria'])
            ? $report['detail_criteria'] : [];
        $detailTracking = !empty($report['detail_tracking']) && is_array($report['detail_tracking'])
            ? $report['detail_tracking'] : [];
        $detailScriptsMode = isset($config['detail_scripts_mode'])
            ? (string)$config['detail_scripts_mode'] : 'sum';
        $showScriptSum = ($detailScriptsMode === 'sum' || $detailScriptsMode === 'both');
        $showScriptSep = ($detailScriptsMode === 'separate' || $detailScriptsMode === 'both');
        $scriptCols = ($showScriptSep && !empty($report['scripts']) && is_array($report['scripts']))
            ? $report['scripts'] : [];
        $showScore = !empty($config['detail_show_score']);
        $showPass = !empty($config['detail_show_pass']);
        $showKeys = !empty($config['detail_show_keys']);
        $showScriptScore = !empty($config['detail_show_script_score']);

        $detailExternal = [];
        if (!empty($report['external']) && is_array($report['external'])) {
            foreach ($report['external'] as $ext) {
                $meta = isset($ext['meta']) && is_array($ext['meta']) ? $ext['meta'] : [];
                if (!empty($meta['show_detail'])) {
                    $detailExternal[] = $ext;
                }
            }
        }
        $detailComputed = [];
        if (!empty($report['computed']) && is_array($report['computed'])) {
            foreach ($report['computed'] as $cm) {
                $meta = isset($cm['meta']) && is_array($cm['meta']) ? $cm['meta'] : [];
                if (!empty($meta['show_detail'])) {
                    $detailComputed[] = $cm;
                }
            }
        }

        $columns = [];
        $columns[] = [
            'key' => 'employee',
            'label' => t('audio_reports.summaries.col_employee'),
            'tip' => self::tip($labels, 'tip_col_employee'),
            'abs' => false,
            'align' => 'left',
        ];
        $columns[] = [
            'key' => 'days',
            'label' => t('audio_reports.summaries.col_days'),
            'tip' => self::tip($labels, 'tip_col_days'),
            'abs' => false,
        ];
        $columns[] = [
            'key' => 'hours',
            'label' => t('audio_reports.summaries.col_hours'),
            'tip' => self::tip($labels, 'tip_col_hours'),
            'abs' => false,
        ];
        if ($showScore) {
            $columns[] = [
                'key' => 'score',
                'label' => t('audio_reports.summaries.col_score_mark'),
                'tip' => self::tip($labels, 'tip_col_score'),
                'abs' => false,
            ];
        }
        if ($showPass) {
            $columns[] = [
                'key' => 'pass',
                'label' => t('audio_reports.summaries.col_pass_short'),
                'tip' => self::tip($labels, 'tip_col_pass'),
                'abs' => false,
            ];
        }
        if ($showScriptSum) {
            $columns[] = [
                'key' => 'scripts_sum',
                'label' => t('audio_reports.summaries.col_scripts_sum'),
                'tip' => self::tip($labels, 'tip_col_scripts_sum'),
                'abs' => false,
            ];
        }
        foreach ($scriptCols as $sid => $sm) {
            $columns[] = [
                'key' => 'script_' . (int)$sid,
                'label' => (string)($sm['NAME'] ?? ('#' . $sid)),
                'tip' => self::tip($labels, 'tip_col_script') . ' «' . (string)($sm['NAME'] ?? '') . '»',
                'abs' => false,
                'script_id' => (int)$sid,
            ];
        }
        if ($showScriptScore) {
            $columns[] = [
                'key' => 'script_score',
                'label' => t('audio_reports.summaries.col_rub'),
                'tip' => self::tip($labels, 'tip_col_script_score'),
                'abs' => true,
            ];
        }
        foreach ($detailCriteria as $tid => $tm) {
            $columns[] = [
                'key' => 'crit_' . (int)$tid,
                'label' => (string)($tm['NAME'] ?? ('#' . $tid)),
                'tip' => self::tip($labels, 'tip_col_criteria') . ' «' . (string)($tm['NAME'] ?? '') . '»',
                'abs' => false,
                'criteria_id' => (int)$tid,
            ];
        }
        foreach ($detailTracking as $did => $td) {
            $code = (string)(($td['TRACKING_CODE'] ?? '') !== '' ? $td['TRACKING_CODE'] : ($td['NAME'] ?? ''));
            $columns[] = [
                'key' => 'track_' . (int)$did,
                'label_html' => $code,
                'label' => (string)($td['NAME'] ?? ''),
                'tip' => self::tip($labels, 'tip_col_tracking') . ' «' . (string)($td['NAME'] ?? '') . '»',
                'abs' => true,
                'tracking_id' => (int)$did,
                'class' => 'is-track',
            ];
        }
        if ($showKeys) {
            $columns[] = [
                'key' => 'keys',
                'label' => t('audio_reports.summaries.col_keys'),
                'tip' => self::tip($labels, 'tip_col_keys'),
                'abs' => true,
            ];
        }
        foreach ($detailExternal as $ext) {
            $meta = isset($ext['meta']) && is_array($ext['meta']) ? $ext['meta'] : [];
            $unit = isset($meta['unit']) ? trim((string)$meta['unit']) : '';
            $th = (string)($ext['title'] ?? '');
            if ($unit !== '') {
                $th .= ', ' . $unit;
            }
            $extTip = isset($meta['tip']) ? trim((string)$meta['tip']) : '';
            if ($extTip === '') {
                $extTip = self::tip($labels, 'tip_col_external') . ' «' . (string)($ext['title'] ?? '') . '»';
            }
            $columns[] = [
                'key' => 'ext_' . (string)($ext['key'] ?? ''),
                'label' => $th,
                'tip' => $extTip,
                'abs' => true,
                'ext_key' => (string)($ext['key'] ?? ''),
                'decimals' => isset($meta['decimals']) ? (int)$meta['decimals'] : 0,
                'class' => 'is-ext',
            ];
        }
        foreach ($detailComputed as $cm) {
            $meta = isset($cm['meta']) && is_array($cm['meta']) ? $cm['meta'] : [];
            $kind = (string)($cm['kind'] ?? 'result');
            $unit = isset($meta['unit']) ? trim((string)$meta['unit']) : (($kind === 'percent') ? '%' : '');
            $th = (string)($cm['title'] ?? '');
            if ($kind !== 'check' && $unit !== '') {
                $th .= ', ' . $unit;
            }
            $columns[] = [
                'key' => 'calc_' . (string)($cm['key'] ?? ''),
                'label' => $th,
                'tip' => isset($meta['tip']) ? (string)$meta['tip'] : $th,
                'abs' => false,
                'calc_key' => (string)($cm['key'] ?? ''),
                'calc_kind' => $kind,
                'decimals' => isset($meta['decimals']) ? (int)$meta['decimals'] : 1,
                'unit' => $unit,
                'class' => 'is-calc',
            ];
        }

        $rows = [];
        foreach (($report['rows'] ?? []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $hasExtVal = false;
            if (!empty($r['external_detail']) && is_array($r['external_detail'])) {
                foreach ($r['external_detail'] as $ev) {
                    if ((float)$ev != 0.0) {
                        $hasExtVal = true;
                        break;
                    }
                }
            }
            if ((int)($r['days_count'] ?? 0) <= 0 && (float)($r['audio_hours'] ?? 0) <= 0 && !$hasExtVal) {
                continue;
            }
            $hours = (float)($r['audio_hours'] ?? 0);
            $daysJson = json_encode(
                isset($r['days_detail']) && is_array($r['days_detail']) ? $r['days_detail'] : [],
                JSON_UNESCAPED_UNICODE
            );
            $nameSort = function_exists('mb_strtolower')
                ? mb_strtolower((string)($r['name_full'] ?? ''), 'UTF-8')
                : strtolower((string)($r['name_full'] ?? ''));
            $rowScore = (float)($r['avg_score'] ?? 0);
            $cells = [];
            $cells['employee'] = [
                'sort' => $nameSort,
                'name' => (string)($r['name'] ?? ''),
                'name_full' => (string)($r['name_full'] ?? ''),
                'color' => (string)($r['color'] ?? ''),
                'color_text' => (string)($r['color_text'] ?? ''),
            ];
            $cells['days'] = ['sort' => (int)($r['days_count'] ?? 0), 'text' => (string)(int)($r['days_count'] ?? 0)];
            $cells['hours'] = [
                'sort' => $hours,
                'text' => (string)$hours,
                'important' => (int)($r['important'] ?? 0),
            ];
            if ($showScore) {
                $cells['score'] = [
                    'sort' => $rowScore,
                    'text' => (string)$rowScore,
                    'color' => SummaryRunner::scoreColor($rowScore, $threshold),
                ];
            }
            if ($showPass) {
                $pass = (int)($r['days_pass_score'] ?? 0);
                $fail = (int)($r['days_fail_score'] ?? 0);
                $cells['pass'] = [
                    'sort' => $pass,
                    'pass' => $pass,
                    'fail' => $fail,
                ];
            }
            if ($showScriptSum) {
                $cells['scripts_sum'] = [
                    'sort' => (float)($r['script_conversion'] ?? 0),
                    'conversion' => (float)($r['script_conversion'] ?? 0),
                    'finishes' => (int)($r['script_finishes'] ?? 0),
                    'starts' => (int)($r['script_starts'] ?? 0),
                ];
            }
            foreach ($scriptCols as $sid => $sm) {
                $sd = isset($r['scripts_detail'][$sid]) && is_array($r['scripts_detail'][$sid])
                    ? $r['scripts_detail'][$sid]
                    : ['conversion' => 0, 'finishes' => 0, 'starts' => 0, 'score' => 0];
                $cells['script_' . (int)$sid] = [
                    'sort' => (float)($sd['conversion'] ?? 0),
                    'conversion' => (float)($sd['conversion'] ?? 0),
                    'finishes' => (int)($sd['finishes'] ?? 0),
                    'starts' => (int)($sd['starts'] ?? 0),
                    'score' => (float)($sd['score'] ?? 0),
                    'show_score' => $showScriptScore,
                ];
            }
            if ($showScriptScore) {
                $ss = (float)($r['script_score'] ?? 0);
                $cells['script_score'] = ['sort' => $ss, 'sum' => $ss, 'text' => (string)round($ss, 0), 'decimals' => 1];
            }
            foreach ($detailCriteria as $tid => $tm) {
                $pct = isset($r['criteria_pct'][$tid]) ? (float)$r['criteria_pct'][$tid] : 0.0;
                $pctColor = ($pct >= 70) ? '#16a34a' : (($pct >= 40) ? '#ca8a04' : '#dc2626');
                $cells['crit_' . (int)$tid] = [
                    'sort' => $pct,
                    'text' => $pct . '%',
                    'color' => $pctColor,
                ];
            }
            foreach ($detailTracking as $did => $td) {
                $cnt = isset($r['tracking_detail'][$did]) ? (int)$r['tracking_detail'][$did] : 0;
                $cells['track_' . (int)$did] = [
                    'sort' => $cnt,
                    'sum' => $cnt,
                    'text' => (string)$cnt,
                    'decimals' => 2,
                ];
            }
            if ($showKeys) {
                $inst = (int)($r['instances'] ?? 0);
                $cells['keys'] = ['sort' => $inst, 'sum' => $inst, 'text' => (string)$inst, 'decimals' => 2];
            }
            foreach ($detailExternal as $ext) {
                $ek = (string)($ext['key'] ?? '');
                $meta = isset($ext['meta']) && is_array($ext['meta']) ? $ext['meta'] : [];
                $dec = isset($meta['decimals']) ? (int)$meta['decimals'] : 0;
                $val = isset($r['external_detail'][$ek]) ? (float)$r['external_detail'][$ek] : 0.0;
                $cells['ext_' . $ek] = [
                    'sort' => $val,
                    'sum' => $val,
                    'text' => self::formatNumber($val, $dec),
                    'decimals' => $dec,
                ];
            }
            foreach ($detailComputed as $cm) {
                $ck = (string)($cm['key'] ?? '');
                $meta = isset($cm['meta']) && is_array($cm['meta']) ? $cm['meta'] : [];
                $kind = (string)($cm['kind'] ?? 'result');
                $dec = isset($meta['decimals']) ? (int)$meta['decimals'] : 1;
                $unit = isset($meta['unit']) ? trim((string)$meta['unit']) : (($kind === 'percent') ? '%' : '');
                $val = $r['computed_detail'][$ck] ?? null;
                $pass = $r['computed_pass'][$ck] ?? null;
                $color = self::computedPassColor($pass);
                if ($kind === 'check') {
                    $sym = ($pass === 1 || $pass === '1') ? '✓' : (($pass === 0 || $pass === '0') ? '✗' : '—');
                    $cells['calc_' . $ck] = [
                        'sort' => ($pass === null) ? -1 : (int)$pass,
                        'text' => $sym,
                        'color' => $color,
                        'check' => true,
                    ];
                } else {
                    $shown = self::formatNumber($val, $dec);
                    if ($unit !== '' && $shown !== '—') {
                        $shown .= ' ' . $unit;
                    }
                    $cells['calc_' . $ck] = [
                        'sort' => ($val === null) ? -999999999 : (float)$val,
                        'text' => $shown,
                        'color' => $color,
                    ];
                }
            }

            $rows[] = [
                'employee_id' => (int)($r['employee_id'] ?? 0),
                'hours' => $hours,
                'days_json' => $daysJson !== false ? $daysJson : '[]',
                'can_open_days' => !empty($r['days_detail']),
                'cells' => $cells,
            ];
        }

        $dayCols = [
            'show_score' => $showScore ? 1 : 0,
            'show_pass' => $showPass ? 1 : 0,
            'show_script_sum' => $showScriptSum ? 1 : 0,
            'show_script_score' => $showScriptScore ? 1 : 0,
            'show_keys' => $showKeys ? 1 : 0,
            'scripts' => [],
            'criteria' => [],
            'tracking' => [],
            'external' => [],
            'computed' => [],
            'threshold' => $threshold,
        ];
        foreach ($scriptCols as $sid => $sm) {
            $dayCols['scripts'][] = ['id' => (string)(int)$sid, 'name' => (string)($sm['NAME'] ?? '')];
        }
        foreach ($detailCriteria as $tid => $tm) {
            $dayCols['criteria'][] = ['id' => (string)(int)$tid, 'name' => (string)($tm['NAME'] ?? '')];
        }
        foreach ($detailTracking as $did => $td) {
            $dayCols['tracking'][] = [
                'id' => (string)(int)$did,
                'name' => (string)($td['NAME'] ?? ''),
                'code' => (string)($td['TRACKING_CODE'] ?? ''),
            ];
        }
        foreach ($detailExternal as $ext) {
            $meta = isset($ext['meta']) && is_array($ext['meta']) ? $ext['meta'] : [];
            $dayCols['external'][] = [
                'key' => (string)($ext['key'] ?? ''),
                'title' => (string)($ext['title'] ?? ''),
                'unit' => (string)($meta['unit'] ?? ''),
                'decimals' => isset($meta['decimals']) ? (int)$meta['decimals'] : 0,
            ];
        }
        foreach ($detailComputed as $cm) {
            $meta = isset($cm['meta']) && is_array($cm['meta']) ? $cm['meta'] : [];
            $kind = (string)($cm['kind'] ?? 'result');
            $dayCols['computed'][] = [
                'key' => (string)($cm['key'] ?? ''),
                'title' => (string)($cm['title'] ?? ''),
                'kind' => $kind,
                'unit' => (string)($meta['unit'] ?? (($kind === 'percent') ? '%' : '')),
                'decimals' => isset($meta['decimals']) ? (int)$meta['decimals'] : 1,
            ];
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'show_script_score' => $showScriptScore,
            'day_cols' => $dayCols,
        ];
    }
}
