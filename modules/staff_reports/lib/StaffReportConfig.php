<?php
declare(strict_types=1);

/** Defaults and normalize for staff report position profiles. */
final class StaffReportConfig
{
    public const MODE_PROCEDURE_SLOT = 'procedure_slot';
    public const MODE_BOOKING_CREATE = 'booking_create';
    public const MODE_SCORE_TIME = 'score_time';

    public const FIELD_OPERATOR = 'ID_OPERATOR';
    public const FIELD_ADMIN = 'ID_ADMIN';

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'analysis' => [
                'mode' => self::MODE_PROCEDURE_SLOT,
                'staff_field' => self::FIELD_OPERATOR,
                'procedure_time_buffer' => 1600,
                'booking_buffer_sec' => 900,
                'equivalent_via_audio_position' => true,
            ],
            'excluded_procedure_type_ids' => [],
            'criteria_type_ids' => [],
            'color_thresholds' => [
                'red_max' => 40.0,
                'yellow_max' => 70.0,
            ],
            'coverage_min_percent' => 70.0,
            'require_full_parse' => true,
            'contract_reward_sum' => 500,
            'contract_working_days' => 5,
            'managing_contract_id' => 0,
            'ticket_status_id' => 4,
            'ticket_title_prefix' => '[Отчеты:',
            'ticket_default_body' => 'Здесь формируются отчёты по работе',
            'thank_you_news' => [
                'title' => '',
                'body' => '',
                'type' => 1,
            ],
            'llm' => [
                'enabled' => false,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function normalize(array $raw): array
    {
        $d = self::defaults();
        $analysis = is_array($raw['analysis'] ?? null) ? $raw['analysis'] : [];
        $mode = (string)($analysis['mode'] ?? $d['analysis']['mode']);
        if (!in_array($mode, [self::MODE_PROCEDURE_SLOT, self::MODE_BOOKING_CREATE, self::MODE_SCORE_TIME], true)) {
            $mode = self::MODE_PROCEDURE_SLOT;
        }
        $field = strtoupper((string)($analysis['staff_field'] ?? $d['analysis']['staff_field']));
        if ($field !== self::FIELD_ADMIN) {
            $field = self::FIELD_OPERATOR;
        }
        $thresholds = is_array($raw['color_thresholds'] ?? null) ? $raw['color_thresholds'] : [];
        $thank = is_array($raw['thank_you_news'] ?? null) ? $raw['thank_you_news'] : [];
        $llm = is_array($raw['llm'] ?? null) ? $raw['llm'] : [];

        return [
            'analysis' => [
                'mode' => $mode,
                'staff_field' => $field,
                'procedure_time_buffer' => max(0, (int)($analysis['procedure_time_buffer'] ?? $d['analysis']['procedure_time_buffer'])),
                'booking_buffer_sec' => max(0, (int)($analysis['booking_buffer_sec'] ?? $d['analysis']['booking_buffer_sec'])),
                'equivalent_via_audio_position' => !isset($analysis['equivalent_via_audio_position'])
                    ? true
                    : !empty($analysis['equivalent_via_audio_position']),
            ],
            'excluded_procedure_type_ids' => self::intList($raw['excluded_procedure_type_ids'] ?? []),
            'criteria_type_ids' => self::intList($raw['criteria_type_ids'] ?? []),
            'color_thresholds' => [
                'red_max' => (float)($thresholds['red_max'] ?? $d['color_thresholds']['red_max']),
                'yellow_max' => (float)($thresholds['yellow_max'] ?? $d['color_thresholds']['yellow_max']),
            ],
            'coverage_min_percent' => (float)($raw['coverage_min_percent'] ?? $d['coverage_min_percent']),
            'require_full_parse' => !isset($raw['require_full_parse']) ? true : !empty($raw['require_full_parse']),
            'contract_reward_sum' => max(0, (int)($raw['contract_reward_sum'] ?? $d['contract_reward_sum'])),
            'contract_working_days' => max(0, (int)($raw['contract_working_days'] ?? $d['contract_working_days'])),
            'managing_contract_id' => max(0, (int)($raw['managing_contract_id'] ?? 0)),
            'ticket_status_id' => max(0, (int)($raw['ticket_status_id'] ?? $d['ticket_status_id'])),
            'ticket_title_prefix' => trim((string)($raw['ticket_title_prefix'] ?? $d['ticket_title_prefix'])) ?: '[Отчеты:',
            'ticket_default_body' => (string)($raw['ticket_default_body'] ?? $d['ticket_default_body']),
            'thank_you_news' => [
                'title' => (string)($thank['title'] ?? ''),
                'body' => (string)($thank['body'] ?? ''),
                'type' => max(0, (int)($thank['type'] ?? 1)),
            ],
            'llm' => [
                'enabled' => !empty($llm['enabled']),
            ],
        ];
    }

    /**
     * @param mixed $v
     * @return list<int>
     */
    public static function intList($v): array
    {
        if (is_string($v)) {
            $parts = preg_split('/[\s,;]+/', $v) ?: [];
            $v = $parts;
        }
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $x) {
            $n = (int)$x;
            if ($n > 0) {
                $out[$n] = $n;
            }
        }
        return array_values($out);
    }

    /** @param array<string,mixed> $config */
    public static function colorForPercent(float $percent, array $config): array
    {
        $th = is_array($config['color_thresholds'] ?? null) ? $config['color_thresholds'] : [];
        $redMax = (float)($th['red_max'] ?? 40);
        $yellowMax = (float)($th['yellow_max'] ?? 70);
        if ($percent < $redMax) {
            return ['color' => 'red', 'emoji' => '🔴'];
        }
        if ($percent <= $yellowMax) {
            return ['color' => 'yellow', 'emoji' => '🟡'];
        }
        return ['color' => 'green', 'emoji' => '🟢'];
    }
}
