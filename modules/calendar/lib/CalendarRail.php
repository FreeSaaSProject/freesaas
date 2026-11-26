<?php
declare(strict_types=1);

/**
 * Compact calendar in the main rail, above the Assistant group.
 * Personal prefs: calendar.rail = off|month|week, calendar.rail_week_off_today = 0|1.
 */
final class CalendarRail
{
    public const PREF_MODE = 'calendar.rail';
    public const PREF_WEEK = 'calendar.rail_week_off_today';
    public const PREF_SOURCE = 'calendar.rail_source';

    public static function mode(): string
    {
        if (!Auth::check() || !Auth::can('calendar.view') || !Database::tableExists('base_events')) {
            return 'off';
        }
        $v = UserPrefsRepository::get(Auth::userId(), self::PREF_MODE);
        return ($v === 'month' || $v === 'week') ? $v : 'off';
    }

    public static function weekOnOther(): bool
    {
        if (!Auth::check()) {
            return false;
        }
        return UserPrefsRepository::get(Auth::userId(), self::PREF_WEEK) === '1';
    }

    /** @param mixed $payload */
    public static function onGate($payload)
    {
        if (!is_array($payload)) {
            return [];
        }
        if (self::mode() !== 'off') {
            Assets::need('calendar');
        }
        return $payload;
    }

    /** @param mixed $payload */
    public static function onRail($payload)
    {
        if (!is_array($payload)) {
            return [];
        }
        $html = self::widgetHtml((string)($payload['path'] ?? ''));
        if ($html === '') {
            return $payload;
        }
        $before = is_array($payload['before'] ?? null) ? $payload['before'] : [];
        $before['llm.menu.group'] = (string)($before['llm.menu.group'] ?? '') . $html;
        $payload['before'] = $before;
        return $payload;
    }

    /** @param mixed $payload */
    public static function onOptions($payload)
    {
        if (!is_array($payload) || !Auth::check() || !Auth::can('calendar.view')) {
            return is_array($payload) ? $payload : [];
        }
        $mode = UserPrefsRepository::get(Auth::userId(), self::PREF_MODE);
        if ($mode !== 'month' && $mode !== 'week') {
            $mode = 'off';
        }
        $week = self::weekOnOther();
        $source = self::sourcePref();
        $resolved = self::markSource();
        if ($resolved === 'procedures') {
            $resolvedLabel = t('calendar.rail.source_procedures');
        } elseif ($resolved === 'events') {
            $resolvedLabel = t('calendar.rail.source_events');
        } else {
            $resolvedLabel = t('calendar.rail.source_off');
        }
        $html = '<label class="dashboard-rail-peek">'
            . e(t('calendar.rail.mode'))
            . ' <select name="calendar_rail">'
            . self::option('off', t('calendar.rail.mode_off'), $mode)
            . self::option('month', t('calendar.rail.mode_month'), $mode)
            . self::option('week', t('calendar.rail.mode_week'), $mode)
            . '</select></label>'
            . '<label class="dashboard-rail-peek">'
            . '<input type="checkbox" name="calendar_rail_week" value="1"' . ($week ? ' checked' : '') . '>'
            . '<span>' . e(t('calendar.rail.week_other')) . '</span></label>'
            . '<p class="muted dashboard-hint">' . e(t('calendar.rail.week_other_hint')) . '</p>'
            . '<label class="dashboard-rail-peek">'
            . e(t('calendar.rail.source'))
            . ' <select name="calendar_rail_source">'
            . self::option('off', t('calendar.rail.source_off'), $source)
            . self::option('auto', t('calendar.rail.source_auto'), $source)
            . self::option('procedures', t('calendar.rail.source_procedures'), $source)
            . self::option('events', t('calendar.rail.source_events'), $source)
            . '</select></label>'
            . '<p class="muted dashboard-hint">' . e(t('calendar.rail.source_hint', ['source' => $resolvedLabel])) . '</p>';
        $payload['html'] = (string)($payload['html'] ?? '') . $html;
        return $payload;
    }

    /** @param mixed $payload */
    public static function onOptionsSave($payload)
    {
        if (!is_array($payload)) {
            return [];
        }
        $uid = (int)($payload['user_id'] ?? 0);
        $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
        if ($uid < 1 || !array_key_exists('calendar_rail', $post)) {
            return $payload;
        }
        $mode = (string)$post['calendar_rail'];
        if ($mode !== 'month' && $mode !== 'week') {
            $mode = 'off';
        }
        UserPrefsRepository::set($uid, self::PREF_MODE, $mode);
        UserPrefsRepository::set($uid, self::PREF_WEEK, !empty($post['calendar_rail_week']) ? '1' : '0');
        if (array_key_exists('calendar_rail_source', $post)) {
            $source = (string)$post['calendar_rail_source'];
            if ($source !== 'events' && $source !== 'procedures' && $source !== 'auto') {
                $source = 'off';
            }
            UserPrefsRepository::set($uid, self::PREF_SOURCE, $source);
        }
        return $payload;
    }

    public static function widgetHtml(string $path, ?string $month = null): string
    {
        $mode = self::mode();
        if ($mode === 'off') {
            return '';
        }
        $anchor = self::anchorFromPath($path);
        $weekOnOther = self::weekOnOther();
        $staffId = self::staffId();
        if ($mode === 'week') {
            return self::weekHtml($anchor, $weekOnOther, $staffId);
        }
        $month = self::normalizeMonth($month, $anchor);
        return self::monthHtml($month, $anchor, $weekOnOther, $staffId);
    }

    public static function anchorFromPath(string $path): string
    {
        if (preg_match('#/calendar/(?:day|week)/(\d{4}-\d{2}-\d{2})#', $path, $m)) {
            return $m[1];
        }
        return date('Y-m-d', DemoMode::now());
    }

    /**
     * What the menu calendar counts. off skips the query. auto follows the floor:
     * procedures when the salon does not keep visit-events (LikeSpa), visits otherwise.
     *
     * @return 'off'|'events'|'procedures'
     */
    public static function markSource(): string
    {
        $pref = self::sourcePref();
        if ($pref === 'off') {
            return 'off';
        }
        if ($pref === 'events' || $pref === 'procedures') {
            return $pref;
        }
        if (!class_exists('ProceduresRepository', false)
            || !ModuleManager::isEnabled('procedures')
            || !Database::tableExists('base_procedures')
        ) {
            return 'events';
        }
        if (CalendarConfig::isBlocksMode()) {
            return 'procedures';
        }
        return self::hasRecentVisits() ? 'events' : 'procedures';
    }

    /** @return 'off'|'auto'|'events'|'procedures' */
    public static function sourcePref(): string
    {
        if (!Auth::check()) {
            return 'off';
        }
        $v = UserPrefsRepository::get(Auth::userId(), self::PREF_SOURCE);
        if ($v === 'auto' || $v === 'events' || $v === 'procedures' || $v === 'off') {
            return $v;
        }
        return 'off';
    }

    /**
     * @return array<string,int>
     */
    public static function counts(int $fromTs, int $toTs, int $staffId): array
    {
        $source = self::markSource();
        if ($source === 'off' || CalendarViewScope::active()) {
            return [];
        }
        if ($source === 'procedures' && class_exists('ProceduresRepository', false)) {
            return ProceduresRepository::countsByDay($fromTs, $toTs, $staffId);
        }
        return EventsRepository::countsByDay($fromTs, $toTs, $staffId);
    }

    private static function hasRecentVisits(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $cached = false;
        if (!Database::tableExists('base_events')) {
            return false;
        }
        $from = DemoMode::now() - (30 * 86400);
        $sql = 'SELECT 1 FROM base_events WHERE DTSTART >= ?';
        if (Database::columnExists('base_events', 'IS_BLOCK')) {
            $sql .= ' AND IFNULL(IS_BLOCK, 0) = 0';
        }
        $sql .= ' LIMIT 1';
        $st = Database::pdo()->prepare($sql);
        $st->execute([$from]);
        $cached = (bool)$st->fetchColumn();
        return $cached;
    }

    private static function staffId(): int
    {
        if (!empty(Auth::statusFlags()['EVT_MY_ONLY'])) {
            return Auth::userId();
        }
        $staff = (int)($_GET['staff'] ?? 0);
        return $staff > 0 ? $staff : 0;
    }

    private static function href(string $date, bool $weekOnOther, int $staffId): string
    {
        $today = date('Y-m-d', DemoMode::now());
        $openDay = ($date === $today) || !$weekOnOther;
        $url = ($openDay ? '/calendar/day/' : '/calendar/week/') . rawurlencode($date);
        if ($staffId > 0 && empty(Auth::statusFlags()['EVT_MY_ONLY'])) {
            $url .= '?staff=' . $staffId;
        }
        return $url;
    }

    private static function weekHtml(string $anchor, bool $weekOnOther, int $staffId): string
    {
        $ts = strtotime($anchor . ' 00:00:00');
        $monday = CalendarWeek::mondayOf($ts !== false ? $ts : DemoMode::now());
        $days = CalendarWeek::days($monday);
        $from = $monday;
        $counts = self::counts($from, $from + 7 * 86400, $staffId);
        $html = '<div class="rail-cal rail-cal-week" data-rail-cal="week" data-rail-source="' . e(self::markSource()) . '" aria-label="' . e(t('calendar.rail.aria')) . '">';
        $html .= '<div class="rail-cal-days">';
        foreach ($days as $day) {
            $dom = date('j', (int)$day['ts']);
            $html .= self::dayLink($day['date'], $dom, (string)$day['weekday'], $anchor, $weekOnOther, $staffId, $counts, (string)$day['weekday']);
        }
        $html .= '</div></div>';
        return $html;
    }

    private static function monthHtml(string $month, string $anchor, bool $weekOnOther, int $staffId): string
    {
        $first = strtotime($month . '-01 00:00:00');
        if ($first === false) {
            $first = strtotime(date('Y-m-01', DemoMode::now()));
        }
        $daysInMonth = (int)date('t', $first);
        $lead = (int)date('N', $first) - 1;
        $prev = date('Y-m', strtotime('-1 month', $first));
        $next = date('Y-m', strtotime('+1 month', $first));
        $end = strtotime('+1 month', $first);
        $counts = self::counts($first, $end !== false ? $end : $first + 32 * 86400, $staffId);
        $label = CalendarWeek::monthShort((int)date('n', $first)) . ' ' . date('Y', $first);

        $html = '<div class="rail-cal rail-cal-month" data-rail-cal="month" data-rail-source="' . e(self::markSource()) . '" aria-label="' . e(t('calendar.rail.aria')) . '">';
        $html .= '<div class="rail-cal-bar">';
        $html .= '<button type="button" class="rail-cal-nav" data-rail-month="' . e($prev) . '" aria-label="' . e(t('calendar.rail.prev_month')) . '">«</button>';
        $html .= '<span class="rail-cal-title">' . e($label) . '</span>';
        $html .= '<button type="button" class="rail-cal-nav" data-rail-month="' . e($next) . '" aria-label="' . e(t('calendar.rail.next_month')) . '">»</button>';
        $html .= '</div>';
        $html .= '<div class="rail-cal-dow">';
        foreach (['calendar.week.mon', 'calendar.week.tue', 'calendar.week.wed', 'calendar.week.thu', 'calendar.week.fri', 'calendar.week.sat', 'calendar.week.sun'] as $key) {
            $html .= '<span>' . e(t($key)) . '</span>';
        }
        $html .= '</div><div class="rail-cal-grid">';
        for ($i = 0; $i < $lead; $i++) {
            $html .= '<span class="rail-cal-pad"></span>';
        }
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date = date('Y-m-', $first) . sprintf('%02d', $d);
            $html .= self::dayLink($date, (string)$d, (string)$d, $anchor, $weekOnOther, $staffId, $counts);
        }
        $html .= '</div></div>';
        return $html;
    }

    /**
     * @param array<string,int> $counts
     */
    private static function dayLink(
        string $date,
        string $label,
        string $title,
        string $anchor,
        bool $weekOnOther,
        int $staffId,
        array $counts,
        string $sub = ''
    ): string {
        $today = date('Y-m-d', DemoMode::now());
        $class = 'rail-cal-day';
        if ($date === $today) {
            $class .= ' is-today';
        }
        if ($date === $anchor) {
            $class .= ' is-open';
        }
        $n = (int)($counts[$date] ?? 0);
        $tip = $title;
        if ($n > 0) {
            $tip .= ' · ' . $n;
        }
        $html = '<a class="' . $class . '" href="' . e(self::href($date, $weekOnOther, $staffId)) . '" title="' . e($tip) . '">';
        if ($sub !== '') {
            $html .= '<small>' . e($sub) . '</small>';
        }
        $html .= '<span>' . e($label) . '</span>';
        if ($n > 0) {
            $html .= '<i class="rail-cal-count">' . $n . '</i>';
        }
        $html .= '</a>';
        return $html;
    }

    private static function normalizeMonth(?string $month, string $anchor): string
    {
        $month = trim((string)$month);
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            return $month;
        }
        return substr($anchor, 0, 7);
    }

    private static function option(string $value, string $label, string $current): string
    {
        return '<option value="' . e($value) . '"' . ($value === $current ? ' selected' : '') . '>' . e($label) . '</option>';
    }
}
