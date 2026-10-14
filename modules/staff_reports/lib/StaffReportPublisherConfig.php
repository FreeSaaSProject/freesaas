<?php
declare(strict_types=1);

/**
 * Global publisher settings for quality day reports (tickets / gratitude board).
 * Stored in cfg/modules via Config section staff_reports.
 */
final class StaffReportPublisherConfig
{
    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'require_full_parse' => true,
            'managing_contract_id' => 0,
            'ticket_status_id' => 4,
            'ticket_title_prefix' => '[Отчеты:',
            'ticket_default_body' => 'Здесь формируются отчёты по работе',
            'gratitude_news_id' => 0,
            'contract_working_days' => 5,
            'llm' => ['enabled' => false],
        ];
    }

    /** @return array<string,mixed> */
    public static function read(): array
    {
        $d = self::defaults();
        $d['require_full_parse'] = self::truthy(Config::get(
            'staff_reports',
            'require_full_parse',
            $d['require_full_parse'] ? '1' : '0'
        ));
        $d['managing_contract_id'] = max(0, (int)Config::get('staff_reports', 'managing_contract_id', $d['managing_contract_id']));
        $d['ticket_status_id'] = max(0, (int)Config::get('staff_reports', 'ticket_status_id', $d['ticket_status_id']));
        $prefix = trim((string)Config::get('staff_reports', 'ticket_title_prefix', $d['ticket_title_prefix']));
        $d['ticket_title_prefix'] = $prefix !== '' ? $prefix : $d['ticket_title_prefix'];
        $body = (string)Config::get('staff_reports', 'ticket_default_body', $d['ticket_default_body']);
        if ($body !== '') {
            $d['ticket_default_body'] = $body;
        }
        $d['gratitude_news_id'] = max(0, (int)Config::get('staff_reports', 'gratitude_news_id', 0));
        $d['contract_working_days'] = max(0, (int)Config::get(
            'staff_reports',
            'contract_working_days',
            $d['contract_working_days']
        ));
        $d['llm'] = [
            'enabled' => self::truthy(Config::get('staff_reports', 'llm_enabled', '0')),
        ];

        return $d;
    }

    /** @param array<string,mixed> $data */
    public static function save(array $data): void
    {
        $d = self::defaults();
        Config::set('staff_reports', 'require_full_parse', !empty($data['require_full_parse']) ? '1' : '0');
        Config::set('staff_reports', 'managing_contract_id', (string)max(0, (int)($data['managing_contract_id'] ?? 0)));
        Config::set('staff_reports', 'ticket_status_id', (string)max(0, (int)($data['ticket_status_id'] ?? $d['ticket_status_id'])));
        $prefix = trim((string)($data['ticket_title_prefix'] ?? $d['ticket_title_prefix']));
        Config::set('staff_reports', 'ticket_title_prefix', $prefix !== '' ? $prefix : $d['ticket_title_prefix']);
        Config::set(
            'staff_reports',
            'ticket_default_body',
            (string)($data['ticket_default_body'] ?? $d['ticket_default_body'])
        );
        Config::set('staff_reports', 'gratitude_news_id', (string)max(0, (int)($data['gratitude_news_id'] ?? 0)));
        Config::set(
            'staff_reports',
            'contract_working_days',
            (string)max(0, (int)($data['contract_working_days'] ?? $d['contract_working_days']))
        );
        $llm = is_array($data['llm'] ?? null) ? $data['llm'] : [];
        Config::set('staff_reports', 'llm_enabled', !empty($llm['enabled']) ? '1' : '0');
    }

    /** Config shape expected by TicketWriter (legacy keys). */
    public static function asTicketConfig(?array $cfg = null): array
    {
        $c = $cfg ?? self::read();

        return [
            'managing_contract_id' => (int)($c['managing_contract_id'] ?? 0),
            'ticket_status_id' => (int)($c['ticket_status_id'] ?? 4),
            'ticket_title_prefix' => (string)($c['ticket_title_prefix'] ?? '[Отчеты:'),
            'ticket_default_body' => (string)($c['ticket_default_body'] ?? 'Здесь формируются отчёты по работе'),
            'contract_reward_sum' => 0,
            'contract_working_days' => max(0, (int)($c['contract_working_days'] ?? 5)),
            'llm' => is_array($c['llm'] ?? null) ? $c['llm'] : ['enabled' => false],
            'require_full_parse' => !empty($c['require_full_parse']),
            'gratitude_news_id' => (int)($c['gratitude_news_id'] ?? 0),
        ];
    }

    /** @param mixed $v */
    private static function truthy($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string)$v));

        return $s === '1' || $s === 'true' || $s === 'yes' || $s === 'on';
    }
}
