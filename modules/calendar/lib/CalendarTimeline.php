<?php
declare(strict_types=1);

/**
 * Oper-style calendar timeline: vertical hour axis, absolute-positioned chips,
 * staff as horizontal stacks inside each day cell.
 */
final class CalendarTimeline
{
    public const PX_PER_HOUR = 56;
    /** Floor only for zero-length quirks — must stay ≤ shortest bookable slot in px. */
    public const MIN_CHIP_PX = 2;
    /** @var array{start:?int,end:?int}|null */
    private static $hoursCache = null;

    /**
     * Persist salon working hours (UI: /calendar/settings) into cfg/modules/calendar.ini [calendar].
     * Booking and badges read the same keys.
     */
    public static function updateWorkHours(int $start, int $end): void
    {
        $start = max(0, min(23, $start));
        $end = max(0, min(23, $end));
        if ($end < $start) {
            $end = $start;
        }
        Config::set('calendar', 'hour_start', (string)$start);
        Config::set('calendar', 'hour_end', (string)$end);
        self::$hoursCache = null;
    }

    /**
     * @return array{start:int,end:int}
     */
    private static function resolveHours(): array
    {
        if (self::$hoursCache !== null) {
            return [
                'start' => (int)self::$hoursCache['start'],
                'end' => (int)self::$hoursCache['end'],
            ];
        }
        $start = (int)Config::get('calendar', 'hour_start', 9);
        $end = (int)Config::get('calendar', 'hour_end', 22);
        $start = max(0, min(23, $start));
        $end = max(0, min(23, $end));
        if ($end < $start) {
            $end = $start;
        }
        self::$hoursCache = ['start' => $start, 'end' => $end];
        return ['start' => $start, 'end' => $end];
    }

    public static function hourStart(): int
    {
        return self::resolveHours()['start'];
    }

    public static function hourEnd(): int
    {
        return self::resolveHours()['end'];
    }

    /** Hours shown on the axis (inclusive start..end). */
    public static function hours(): array
    {
        $out = [];
        for ($i = self::hourStart(); $i <= self::hourEnd(); $i++) {
            $out[] = $i;
        }
        return $out;
    }

    public static function gridHeight(): int
    {
        return count(self::hours()) * self::PX_PER_HOUR;
    }

    public static function pxPerMinute(): float
    {
        return self::PX_PER_HOUR / 60.0;
    }

    /**
     * @return array{top:int,height:int}
     */
    public static function position(int $dayStart, int $dtStart, int $dtEnd): array
    {
        $offsetSec = self::hourStart() * 3600;
        $gridStart = $dayStart + $offsetSec;
        $koef = self::pxPerMinute();

        $start = $dtStart > 0 ? $dtStart : $gridStart;
        $end = $dtEnd > $start ? $dtEnd : ($start + 1800);

        $top = (int)round(($start - $gridStart) * $koef / 60);
        $durSec = max(60, $end - $start);
        $height = (int)round($durSec * $koef / 60);
        if ($height < self::MIN_CHIP_PX) {
            $height = self::MIN_CHIP_PX;
        }

        $maxH = self::gridHeight();
        if ($top < 0) {
            $height += $top;
            $top = 0;
        }
        if ($top + $height > $maxH) {
            $height = max(self::MIN_CHIP_PX, $maxH - $top);
        }
        if ($height < 1) {
            $height = self::MIN_CHIP_PX;
        }

        return ['top' => $top, 'height' => $height];
    }

    /**
     * Attach duration_sec and class is-compact for short bookable chips.
     * Threshold: CalendarConfig::compactChipMinutes() (0 = off). Compact chips swap bg/fg.
     *
     * @param array<string,mixed> $chip
     * @return array<string,mixed>
     */
    public static function withCompactMeta(array $chip, int $dtStart, int $dtEnd): array
    {
        $durationSec = ($dtStart > 0 && $dtEnd > $dtStart) ? ($dtEnd - $dtStart) : 0;
        $chip['duration_sec'] = $durationSec;
        $thresholdMin = CalendarConfig::compactChipMinutes();
        if ($thresholdMin > 0 && $durationSec > 0 && $durationSec <= ($thresholdMin * 60)) {
            $extra = trim((string)($chip['class'] ?? ''));
            $chip['class'] = $extra === '' ? 'is-compact' : ($extra . ' is-compact');
            $bg = (string)($chip['bg'] ?? '');
            $fg = (string)($chip['fg'] ?? '');
            if ($bg !== '' && $fg !== '') {
                $chip['bg'] = $fg;
                $chip['fg'] = $bg;
            }
        }

        return $chip;
    }

    /**
     * Staff column layout inside a day cell (oper steks_left).
     * Preserves caller order (shift panel: masseurs → FL → admin → assist → hidden).
     *
     * @param list<int> $operatorIds unique preferred order
     * @return array{width:float,lefts:array<int,float>}
     */
    public static function staffStacks(array $operatorIds): array
    {
        $list = [];
        $seen = [];
        foreach ($operatorIds as $id) {
            $id = (int)$id;
            if ($id < 1 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $list[] = $id;
        }
        $n = count($list);
        if ($n < 1) {
            return ['width' => 95.0, 'lefts' => [0 => 0.0]];
        }
        $width = round((100 - 5) / $n, 2);
        if ($n > 5) {
            $width -= 0.57;
        }
        $lefts = [];
        $i = 0;
        foreach ($list as $oid) {
            $lefts[$oid] = round(($width + 1) * $i, 2);
            $i++;
        }
        return ['width' => $width, 'lefts' => $lefts];
    }

    /**
     * Chip-lane operator ids: only staff who have events/procedures/orders (used width).
     * Order follows top shift badges when on shift; off-shift chip ops append last.
     * Empty shift badges do not get a lane — columns fill available width.
     *
     * @param list<array<string,mixed>> $shifts WorkweeksRepository::listForDay rows (already group-sorted)
     * @param list<array<string,mixed>> ...$itemLists events, procedures, orders
     * @return list<int>
     */
    public static function stackOperatorIds(array $shifts, array ...$itemLists): array
    {
        $withChips = [];
        foreach (self::collectOperatorIds(...$itemLists) as $id) {
            $withChips[$id] = true;
        }
        $out = [];
        foreach ($shifts as $sh) {
            $oid = (int)($sh['id_operator'] ?? 0);
            if ($oid < 1 || !isset($withChips[$oid])) {
                continue;
            }
            $out[$oid] = true;
            unset($withChips[$oid]);
        }
        foreach ($withChips as $oid => $_) {
            $out[$oid] = true;
        }
        return array_keys($out);
    }

    /**
     * Past-time overlay for a day cell (oper events-timeline-passed).
     *
     * @return array{height:int,is_now:bool}|null
     */
    public static function passedOverlay(int $dayStart, ?int $now = null): ?array
    {
        $now = $now ?? DemoMode::now();
        $hStart = self::hourStart();
        $hEnd = self::hourEnd();
        $gridStart = $dayStart + $hStart * 3600;
        $gridEnd = $dayStart + ($hEnd + 1) * 3600;
        $koef = self::pxPerMinute();

        if ($gridEnd <= $now) {
            $h = (int)round(($gridEnd - $gridStart) * $koef / 60);
            return ['height' => max(0, $h), 'is_now' => false];
        }
        if ($gridStart < $now) {
            $h = (int)round(($now - $gridStart) * $koef / 60);
            return ['height' => max(0, $h), 'is_now' => true];
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $events
     * @param list<array<string,mixed>> $procedures
     * @param array<int,array{bg:string,fg:string}> $colors
     * @param list<array<string,mixed>> $orders advance website orders (base_orders)
     * @param list<array<string,mixed>> $shifts workweeks rows for the day (PRICE_COL bounds)
     * @return list<array<string,mixed>>
     */
    public static function chipsForDay(
        int $dayStart,
        array $events,
        array $procedures,
        array $stacks,
        array $colors,
        bool $canEditEvents,
        array $orders = [],
        array $shifts = []
    ): array {
        $width = (float)($stacks['width'] ?? 95);
        $lefts = $stacks['lefts'] ?? [0 => 0.0];
        $chips = [];
        /** @var array<int,true> ops that already have event/proc/order chips */
        $opsWithItems = [];
        $canContact = Auth::capability('SUPERADMIN_ACCESS')
            || Auth::capability('EVT_CONTACT_DATA');
        $isToday = date('Y-m-d', $dayStart) === date('Y-m-d');
        $showEventPhone = $canContact && $isToday;
        $showOrderPhone = $canContact;

        $cosmIds = [];
        foreach ($orders as $ord) {
            $cid = (int)($ord['id_cosmetolog'] ?? $ord['id_operator'] ?? 0);
            if ($cid > 0) {
                $cosmIds[$cid] = true;
            }
        }
        $cosmNames = [];
        if ($cosmIds !== [] && class_exists(UsersRepository::class, false)) {
            $cosmNames = UsersRepository::staffNamesIncluding(array_keys($cosmIds));
        }

        foreach ($procedures as $pr) {
            $op = (int)($pr['id_operator'] ?? 0);
            if ($op > 0) {
                $opsWithItems[$op] = true;
            }
            $pos = self::position($dayStart, (int)($pr['dtstart'] ?? 0), (int)($pr['dtend'] ?? 0));
            $title = trim((string)($pr['product_name'] ?? $pr['type_name'] ?? ''));
            if ($title === '') {
                $title = '#' . (int)$pr['id'];
            }
            $client = trim((string)($pr['client_name'] ?? ''));
            $phone = trim((string)($pr['client_tel'] ?? ''));
            $label = date('H:i', (int)($pr['dtstart'] ?? 0)) . '–' . date('H:i', (int)($pr['dtend'] ?? 0));
            if ($client !== '') {
                $label .= ' ' . $client;
            }
            if ($showEventPhone && $phone !== '') {
                $label .= ' ' . $phone;
            }
            $label .= ' ' . $title;
            $color = $colors[$op] ?? ['bg' => 'var(--accent-soft)', 'fg' => 'var(--text)'];
            $info = '<div class="calendar-info-head">' . $title . '</div>'
                . '<div>' . date('H:i', (int)($pr['dtstart'] ?? 0)) . ' – ' . date('H:i', (int)($pr['dtend'] ?? 0)) . '</div>';
            if ($client !== '') {
                $info .= '<div>' . $client . '</div>';
            }
            if ($showEventPhone && $phone !== '') {
                $info .= '<div class="muted">' . $phone . '</div>';
            }
            if (!empty($pr['operator_name'])) {
                $info .= '<div class="muted">' . (string)$pr['operator_name'] . '</div>';
            }
            if (isset($pr['summ'])) {
                $info .= '<div><b>' . number_format((float)$pr['summ'], 0, '.', ' ') . '</b></div>';
            }
            $summ = (float)($pr['summ'] ?? 0);
            $summDefault = (float)($pr['summ_default'] ?? 0);
            $restored = (int)($pr['restored'] ?? 0) > 0;
            // Oper parity: restored dashed (wins); promo (SUMM≠SUMM_DEFAULT) dotted.
            $procClass = '';
            if ($restored && $summ > 0) {
                $procClass = 'is-restored';
            } elseif ($summDefault > 0 && abs($summ - $summDefault) > 0.01) {
                $procClass = 'is-promo';
            }
            $dtStart = (int)($pr['dtstart'] ?? 0);
            $dtEnd = (int)($pr['dtend'] ?? 0);
            $chips[] = self::withCompactMeta([
                'kind' => 'proc',
                'id' => (int)$pr['id'],
                // Metadata for product modules (e.g. terminals chill paint / poll_invites oral mark).
                'id_good' => (int)($pr['id_good'] ?? 0),
                'id_user' => (int)($pr['id_user'] ?? 0),
                'summ' => $summ,
                'summ_default' => $summDefault,
                'restored' => $restored ? 1 : 0,
                'notice' => (string)($pr['notice'] ?? ''),
                'class' => $procClass,
                'href' => '/procedures/' . (int)$pr['id'] . '/edit',
                'top' => $pos['top'],
                'height' => $pos['height'],
                'left' => $lefts[$op] ?? 0.0,
                'width' => $width,
                'bg' => $color['bg'],
                'fg' => $color['fg'],
                'is_block' => false,
                'dtstart' => $dtStart,
                'dtend' => $dtEnd,
                'label' => $label,
                'title' => $label,
                'info' => $info,
                // Optional trusted HTML from calendar.timeline.enrich (merged into info panel).
                'actions_html' => '',
            ], $dtStart, $dtEnd);
        }

        foreach ($events as $ev) {
            $op = (int)($ev['id_operator'] ?? 0);
            if ($op > 0) {
                $opsWithItems[$op] = true;
            }
            $pos = self::position($dayStart, (int)($ev['dtstart'] ?? 0), (int)($ev['dtend'] ?? 0));
            $isBlock = !empty($ev['is_block']);
            $client = trim((string)($ev['client_name'] ?? ''));
            if ($client === '') {
                $client = trim((string)($ev['name'] ?? ''));
            }
            if ($client === '') {
                $client = $isBlock
                    ? t('calendar.day.block')
                    : ((int)($ev['id_user'] ?? 0) > 0 ? ('#' . (int)$ev['id_user']) : ('#' . (int)$ev['id']));
            }
            $phone = trim((string)($ev['phone'] ?? ''));
            $label = date('H:i', (int)$ev['dtstart']) . '–' . date('H:i', (int)$ev['dtend']) . ' ' . $client;
            if (!$isBlock && $showEventPhone && $phone !== '') {
                $label .= ' ' . $phone;
            }
            $color = $colors[$op] ?? ['bg' => 'var(--accent-soft)', 'fg' => 'var(--text)'];
            if ($isBlock) {
                $safeBg = self::safeCssColor($color['bg'], '#444');
                $color = [
                    'bg' => 'repeating-linear-gradient(45deg,#222 0 6px,' . $safeBg . ' 6px 12px)',
                    'fg' => '#fff',
                ];
            }
            $href = $canEditEvents
                ? '/calendar/events/' . (int)$ev['id'] . '/edit'
                : '/calendar/day/' . date('Y-m-d', $dayStart);
            $info = '<div class="calendar-info-head">#' . (int)$ev['id']
                . ($isBlock ? ' · ' . t('calendar.day.block') : '') . '</div>'
                . '<div>' . date('H:i', (int)$ev['dtstart']) . ' – ' . date('H:i', (int)$ev['dtend']) . '</div>'
                . '<div>' . htmlspecialchars($client, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
            if (!$isBlock && $showEventPhone && $phone !== '') {
                $info .= '<div class="muted">' . htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
            }
            if (!empty($ev['operator_name'])) {
                $info .= '<div class="muted">' . htmlspecialchars((string)$ev['operator_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
            }
            if (!$isBlock) {
                $info .= self::eventBasketInfoHtml($ev);
            }
            if (!$isBlock && isset($ev['real_summ']) && (float)$ev['real_summ'] > 0) {
                $sym = class_exists('I18n', false) ? I18n::currencySymbol() : '₽';
                $info .= '<div><b>' . number_format((float)$ev['real_summ'], 0, '.', ' ')
                    . ' ' . htmlspecialchars($sym, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b></div>';
            }
            if (!empty($ev['descr'])) {
                $info .= '<div class="muted">' . htmlspecialchars(mb_substr((string)$ev['descr'], 0, 160), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
            }
            $dtStart = (int)($ev['dtstart'] ?? 0);
            $dtEnd = (int)($ev['dtend'] ?? 0);
            $chip = [
                'kind' => $isBlock ? 'block' : 'event',
                'id' => (int)$ev['id'],
                'href' => $href,
                'top' => $pos['top'],
                'height' => $pos['height'],
                'left' => $lefts[$op] ?? 0.0,
                'width' => $width,
                'bg' => $color['bg'],
                'fg' => $color['fg'],
                'is_block' => $isBlock,
                'dtstart' => $dtStart,
                'dtend' => $dtEnd,
                'label' => $label,
                'title' => $label,
                'info' => $info,
            ];
            // Blocks stay muted — only bookable short slots get is-compact.
            $chips[] = $isBlock ? $chip : self::withCompactMeta($chip, $dtStart, $dtEnd);
        }

        foreach ($orders as $ord) {
            $ts = (int)($ord['dt_event'] ?? 0);
            if ($ts <= 0) {
                continue;
            }
            $op = (int)($ord['id_cosmetolog'] ?? $ord['id_operator'] ?? 0);
            if ($op > 0) {
                $opsWithItems[$op] = true;
            }
            $end = $ts + self::orderDurationSec($ord);
            $pos = self::position($dayStart, $ts, $end);
            $phone = trim((string)($ord['phone'] ?? ''));
            $status = trim((string)($ord['status'] ?? ''));
            $cosmName = $op > 0 ? trim((string)($cosmNames[$op] ?? '')) : '';
            $cartSummary = self::orderCartSummary($ord);

            $label = date('H:i', $ts) . ' ' . t('calendar.widget.board.order');
            if ($cartSummary['short'] !== '') {
                $label .= ' ' . $cartSummary['short'];
            }
            if ($cosmName !== '') {
                $label .= ' · ' . $cosmName;
            }
            if ($showOrderPhone && $phone !== '') {
                $label .= ' ' . $phone;
            }

            $laneOk = $op > 0 && array_key_exists($op, $lefts);
            $chipLeft = $laneOk ? (float)$lefts[$op] : 2.0;
            $chipWidth = $laneOk ? $width : 96.0;
            // Use specialist colors when known (same as procs/events); amber only if unassigned.
            if ($op > 0 && isset($colors[$op])) {
                $chipBg = (string)$colors[$op]['bg'];
                $chipFg = (string)$colors[$op]['fg'];
            } else {
                $chipBg = 'rgba(251, 191, 36, 0.28)';
                $chipFg = 'var(--text)';
            }

            $info = '<div class="calendar-info-head">' . t('calendar.widget.board.order')
                . ' #' . (int)($ord['id'] ?? 0) . '</div>'
                . '<div>' . date('H:i', $ts) . ' – ' . date('H:i', $end) . '</div>';
            if ($cosmName !== '') {
                $info .= '<div>' . $cosmName . '</div>';
            } elseif (!$laneOk) {
                $info .= '<div class="muted">' . t('calendar.day.order_unassigned') . '</div>';
            }
            foreach ($cartSummary['names'] as $svc) {
                $info .= '<div class="muted">' . $svc . '</div>';
            }
            if ($showOrderPhone && $phone !== '') {
                $info .= '<div>' . $phone . '</div>';
            }
            if ($status !== '') {
                $info .= '<div class="muted">' . $status . '</div>';
            }
            if (isset($ord['summ_real'])) {
                $info .= '<div><b>' . number_format((float)$ord['summ_real'], 0, '.', ' ') . '</b></div>';
            }
            $chips[] = [
                'kind' => 'order',
                'id' => (int)($ord['id'] ?? 0),
                'href' => '/orders/' . (int)($ord['id'] ?? 0) . '/edit',
                'top' => $pos['top'],
                'height' => $pos['height'],
                'left' => $chipLeft,
                'width' => $chipWidth,
                'bg' => $chipBg,
                'fg' => $chipFg,
                'is_block' => false,
                'label' => $label,
                'title' => $label,
                'info' => $info,
            ];
        }

        foreach (self::shiftBoundChips($dayStart, $shifts, $lefts, $width, $colors, $opsWithItems) as $bound) {
            $chips[] = $bound;
        }

        return $chips;
    }

    /**
     * Faint out-of-shift overlays on lanes that already have calendar items.
     * Pre-shift (grid start → shift start) and post-shift (shift end → grid end).
     *
     * @param list<array<string,mixed>> $shifts
     * @param array<int|string,float> $lefts
     * @param array<int,array{bg:string,fg:string}> $colors
     * @param array<int,true> $opsWithItems
     * @return list<array<string,mixed>>
     */
    private static function shiftBoundChips(
        int $dayStart,
        array $shifts,
        array $lefts,
        float $width,
        array $colors,
        array $opsWithItems
    ): array {
        if ($shifts === [] || $opsWithItems === [] || !class_exists(WorkweeksRepository::class, false)) {
            return [];
        }
        $hStart = self::hourStart();
        $hEnd = self::hourEnd();
        $gridStart = $dayStart + $hStart * 3600;
        $gridEnd = $dayStart + ($hEnd + 1) * 3600;
        $byOp = [];
        foreach ($shifts as $sh) {
            $oid = (int)($sh['id_operator'] ?? 0);
            if ($oid < 1 || !isset($opsWithItems[$oid]) || !array_key_exists($oid, $lefts)) {
                continue;
            }
            if (isset($byOp[$oid])) {
                continue;
            }
            $pc = WorkweeksRepository::normalizePriceCol((int)($sh['price_col'] ?? 1));
            $range = WorkweeksRepository::shiftRangeForDay($dayStart, $pc);
            if ($range === null) {
                continue;
            }
            $byOp[$oid] = $range;
        }
        $out = [];
        foreach ($byOp as $oid => $range) {
            $shiftStart = (int)$range['start'];
            $shiftEnd = (int)$range['end'];
            $label = (string)($range['label'] ?? '');
            $title = t('calendar.timeline.shift_bound', [
                'label' => $label !== '' ? $label : (string)$oid,
                'start' => date('H:i', $shiftStart),
                'end' => date('H:i', $shiftEnd),
            ]);
            $color = $colors[$oid] ?? ['bg' => 'var(--accent-soft)', 'fg' => 'var(--text)'];
            $bg = self::safeCssColor((string)$color['bg'], 'var(--accent-soft)');
            $left = (float)$lefts[$oid];
            // Before shift start (outside working window).
            if ($shiftStart > $gridStart + 60) {
                $pos = self::position($dayStart, $gridStart, min($shiftStart, $gridEnd));
                if ($pos['height'] >= self::MIN_CHIP_PX) {
                    $out[] = self::makeShiftBoundChip($pos, $left, $width, $bg, $title, 'pre');
                }
            }
            // After shift end.
            if ($shiftEnd < $gridEnd - 60) {
                $pos = self::position($dayStart, max($shiftEnd, $gridStart), $gridEnd);
                if ($pos['height'] >= self::MIN_CHIP_PX) {
                    $out[] = self::makeShiftBoundChip($pos, $left, $width, $bg, $title, 'post');
                }
            }
        }
        return $out;
    }

    /**
     * @param array{top:int,height:int} $pos
     * @return array<string,mixed>
     */
    private static function makeShiftBoundChip(
        array $pos,
        float $left,
        float $width,
        string $bg,
        string $title,
        string $side
    ): array {
        return [
            'kind' => 'shift_bound',
            'id' => 0,
            'href' => '#',
            'top' => $pos['top'],
            'height' => $pos['height'],
            'left' => $left,
            'width' => $width,
            'bg' => $bg,
            'fg' => 'transparent',
            'is_block' => false,
            'label' => '',
            'title' => $title,
            'info' => '',
            'class' => 'is-shift-' . $side,
        ];
    }

    /**
     * Basket lines for event info popup (names × qty · price).
     *
     * @param array<string,mixed> $ev
     */
    private static function eventBasketInfoHtml(array $ev): string
    {
        $items = is_array($ev['items'] ?? null) ? $ev['items'] : [];
        if ($items === []) {
            return '';
        }
        $names = class_exists(ProductsRepository::class, false)
            ? ProductsRepository::names(true)
            : [];
        $html = '<ul class="calendar-info-items">';
        $n = 0;
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $gid = (int)($it['id_good'] ?? 0);
            if ($gid < 1) {
                continue;
            }
            $qty = max(1, (int)($it['qty'] ?? 1));
            $price = (float)($it['price'] ?? 0);
            $label = (string)($names[$gid] ?? ('#' . $gid));
            $line = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($qty > 1) {
                $line .= ' ×' . $qty;
            }
            if ($price > 0) {
                $sym = class_exists('I18n', false) ? I18n::currencySymbol() : '₽';
                $line .= ' · ' . number_format($price * $qty, 0, '.', ' ')
                    . ' ' . htmlspecialchars($sym, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $html .= '<li>' . $line . '</li>';
            $n++;
            if ($n >= 12) {
                break;
            }
        }
        $html .= '</ul>';
        return $n > 0 ? $html : '';
    }

    /**
     * Short cart labels for advance-order chips (no fake procedure chips).
     *
     * @param array<string,mixed> $ord
     * @return array{short:string,names:list<string>}
     */
    private static function orderCartSummary(array $ord): array
    {
        $empty = ['short' => '', 'names' => []];
        if (!class_exists(OrdersRepository::class, false)) {
            return $empty;
        }
        $lines = OrdersRepository::enrichCartNames(
            OrdersRepository::decodeCart((string)($ord['cart'] ?? ''))
        );
        $names = [];
        foreach ($lines as $line) {
            $n = trim((string)($line['name'] ?? ''));
            if ($n === '' && (int)($line['id'] ?? 0) > 0) {
                $n = '#' . (int)$line['id'];
            }
            if ($n !== '') {
                $names[] = $n;
            }
        }
        if ($names === []) {
            return $empty;
        }
        $short = $names[0];
        if (count($names) > 1) {
            $short .= ' +' . (count($names) - 1);
        }
        return ['short' => $short, 'names' => $names];
    }

    /**
     * Visual length of an advance order on the timeline.
     * Must match BookingOccupancy (real cart time_sec) — do not force a 15‑min floor,
     * or short online bookings overlap on the board while slots stay dense.
     *
     * @param array<string,mixed> $ord
     */
    public static function orderDurationSec(array $ord): int
    {
        $lines = [];
        if (class_exists(OrdersRepository::class, false)) {
            $lines = OrdersRepository::decodeCart((string)($ord['cart'] ?? ''));
        }
        $total = 0;
        foreach ($lines as $line) {
            $id = (int)($line['id'] ?? 0);
            $sec = 0;
            if ($id > 0 && class_exists(ProductsRepository::class, false)) {
                $p = ProductsRepository::find($id);
                $sec = $p ? max(0, (int)($p['time_sec'] ?? 0)) : 0;
            }
            $total += $sec > 0 ? $sec : 1800;
        }
        return $total > 0 ? $total : 1800;
    }

    /**
     * Compact home-widget board: procedures + events (no blocks) + orders,
     * full-width columns (no staff lanes). Chip click opens popup (href=#).
     *
     * @param list<array<string,mixed>> $events
     * @param list<array<string,mixed>> $procedures
     * @param list<array<string,mixed>> $orders
     * @return list<array<string,mixed>>
     */
    public static function chipsForBoard(int $dayStart, array $events, array $procedures, array $orders): array
    {
        $events = array_values(array_filter(
            $events,
            static fn(array $ev): bool => empty($ev['is_block'])
        ));
        $opIds = self::collectOperatorIds($events, $procedures);
        $colors = self::operatorColors($opIds);
        $stacks = ['width' => 96.0, 'lefts' => [0 => 2.0]];
        foreach ($opIds as $oid) {
            $stacks['lefts'][$oid] = 2.0;
        }

        $chips = self::chipsForDay(
            $dayStart,
            $events,
            $procedures,
            $stacks,
            $colors,
            Auth::can('calendar.edit'),
            $orders
        );
        foreach ($chips as &$chip) {
            $chip['left'] = 2.0;
            $chip['width'] = 96.0;
            $chip['href'] = '#';
        }
        unset($chip);

        usort($chips, static function (array $a, array $b): int {
            return ((int)$a['top'] <=> (int)$b['top']) ?: ((int)$a['id'] <=> (int)$b['id']);
        });
        return $chips;
    }

    /**
     * Collect operator ids present in items.
     *
     * @param list<array<string,mixed>> ...$itemLists
     * @return list<int>
     */
    public static function collectOperatorIds(array ...$itemLists): array
    {
        $ids = [];
        foreach ($itemLists as $list) {
            foreach ($list as $row) {
                $op = (int)($row['id_operator'] ?? 0);
                if ($op < 1) {
                    $op = (int)($row['id_cosmetolog'] ?? 0);
                }
                if ($op > 0) {
                    $ids[$op] = true;
                }
            }
        }
        return array_keys($ids);
    }

    /**
     * Advance orders for calendar timeline (cashComplete / pay-ready).
     *
     * @return list<array<string,mixed>>
     */
    public static function advanceOrdersForRange(int $dayStart, int $dayEnd, ?int $staffId = null): array
    {
        if (!class_exists(OrdersRepository::class, false) || !Database::tableExists('base_orders')) {
            return [];
        }
        $rows = OrdersRepository::listForDayRange($dayStart, $dayEnd, $staffId);
        $allow = array_fill_keys(OrdersRepository::NEW_STATUSES, true);
        $out = [];
        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '');
            if (!isset($allow[$status])) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Compact procedure rows for assistant / agent JSON (keep ids + time + names).
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function proceduresForAgent(array $items): array
    {
        $out = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $dtstart = (int)($row['dtstart'] ?? 0);
            $dtend = (int)($row['dtend'] ?? 0);
            $out[] = [
                'id' => (int)($row['id'] ?? 0),
                'id_operator' => (int)($row['id_operator'] ?? 0),
                'operator_name' => trim((string)($row['operator_name'] ?? '')),
                'id_event_parent' => (int)($row['id_event_parent'] ?? 0),
                'product_name' => trim((string)($row['product_name'] ?? '')),
                'client_name' => trim((string)($row['client_name'] ?? '')),
                'type_name' => trim((string)($row['type_name'] ?? '')),
                'summ' => isset($row['summ']) ? (float)$row['summ'] : null,
                'dtstart' => $dtstart,
                'dtend' => $dtend,
                'dtstart_iso' => $dtstart > 0 ? date('Y-m-d\TH:i', $dtstart) : '',
                'dtend_iso' => $dtend > 0 ? date('Y-m-d\TH:i', $dtend) : '',
            ];
        }
        return $out;
    }

    /**
     * Compact advance-order rows for assistant / agent JSON.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function ordersForAgent(array $items): array
    {
        $out = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $dt = (int)($row['dt'] ?? $row['dtstart'] ?? 0);
            $out[] = [
                'id' => (int)($row['id'] ?? 0),
                'id_operator' => (int)($row['id_operator'] ?? 0),
                'status' => (string)($row['status'] ?? ''),
                'name' => trim((string)($row['name'] ?? $row['client_name'] ?? '')),
                'phone' => trim((string)($row['phone'] ?? $row['tel'] ?? '')),
                'sum' => isset($row['sum']) ? (float)$row['sum'] : (isset($row['summ']) ? (float)$row['summ'] : null),
                'dt' => $dt,
                'dt_iso' => $dt > 0 ? date('Y-m-d\TH:i', $dt) : '',
            ];
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int,array{bg:string,fg:string}>
     */
    public static function operatorColors(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !Database::tableExists('base_users')) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            "SELECT id, color, color_text FROM base_users WHERE id IN ($placeholders)"
        );
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $bg = trim((string)($row['color'] ?? ''));
            $fg = trim((string)($row['color_text'] ?? ''));
            if ($bg === '') {
                $bg = 'var(--accent-soft)';
            } else {
                $bg = self::safeCssColor($bg, 'var(--accent-soft)');
            }
            if ($fg === '') {
                $fg = 'var(--text)';
            } else {
                $fg = self::safeCssColor($fg, 'var(--text)');
            }
            $out[(int)$row['id']] = ['bg' => $bg, 'fg' => $fg];
        }
        return $out;
    }

    public static function safeCssColor(string $raw, string $fallback): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $fallback;
        }
        if (preg_match(
            '/^(?:#[0-9a-fA-F]{3,8}|rgba?\([^;<>{}]+\)|hsla?\([^;<>{}]+\)|[a-zA-Z]{1,30}|var\(--[a-zA-Z0-9\-]+\))$/',
            $raw
        )) {
            return $raw;
        }
        return $fallback;
    }

    public static function shortName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $parts = preg_split('/\s+/u', $name) ?: [];
        if (count($parts) <= 1) {
            return $parts[0] ?? $name;
        }
        $out = $parts[0];
        if (isset($parts[1]) && $parts[1] !== '') {
            $out .= ' ' . mb_substr($parts[1], 0, 1) . '.';
        }
        return $out;
    }

    /** Oper ShortName($name, 1): initials of each word, e.g. "Иванов Иван" → "ИИ". */
    public static function acronym(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/u', $name) ?: [];
        $out = '';
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $ch = mb_substr($part, 0, 1, 'UTF-8');
            if ($ch !== '') {
                $out .= mb_strtoupper($ch, 'UTF-8');
            }
        }
        return $out !== '' ? $out : '?';
    }
}