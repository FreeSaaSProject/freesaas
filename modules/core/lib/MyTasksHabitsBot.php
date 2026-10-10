<?php
declare(strict_types=1);

/** Bot UI / FSM for my_tasks habits (wizard, slots, full card). */
final class MyTasksHabitsBot
{
    public static function isHabitCallback(string $data): bool
    {
        return (bool)preg_match(
            '/^(htb_|htoff_|htx_|hte_|htf_|htw_|hth_|htr_|hs_|hsd_|hz_|mtf_|mtfp_)/',
            $data
        );
    }

    /** @param array<string,mixed> $ctx */
    public static function onCallback(array $ctx, string $data, int $uid): bool
    {
        if (preg_match('/^htb_(\d+)$/', $data, $m)) {
            $tid = (int)$m[1];
            if (!self::canManage($tid, $uid)) {
                self::reply($ctx, t('core.bot.my_tasks.denied'), self::kbMenu());
                return true;
            }
            self::setWizard($ctx, $tid, ['id_task' => $tid]);
            self::renderEndStep($ctx, $tid);
            return true;
        }
        if (preg_match('/^htoff_(\d+)$/', $data, $m)) {
            $tid = (int)$m[1];
            if (!self::canManage($tid, $uid)) {
                self::reply($ctx, t('core.bot.my_tasks.denied'), self::kbMenu());
                return true;
            }
            if (!class_exists('CommentsRepository', false)) {
                self::reply($ctx, t('core.bot.my_tasks.no_comments'), self::kbBack($tid));
                return true;
            }
            $off = MyTasksHabits::MARKER . json_encode(['v' => 1, 'active' => false], JSON_UNESCAPED_UNICODE);
            CommentsRepository::add($tid, $uid, t('core.bot.my_tasks.habit.disabled_comment') . ' ' . $off, [
                'COLOR' => '2',
            ]);
            self::clearHabitPending($ctx);
            NewsBotPlugin::showCardPublic($ctx, $uid, $tid, 1, 0, true);
            return true;
        }
        if (preg_match('/^htx_(\d+)$/', $data, $m)) {
            self::clearHabitPending($ctx);
            NewsBotPlugin::showCardPublic($ctx, $uid, (int)$m[1], 1, 0, false);
            return true;
        }
        if (preg_match('/^hte_(\d+)_(\d+)$/', $data, $m)) {
            return self::onEndStep($ctx, $uid, (int)$m[1], (int)$m[2]);
        }
        if (preg_match('/^htf_(\d+)_(\d+)$/', $data, $m)) {
            return self::onFreq($ctx, $uid, (int)$m[1], (int)$m[2]);
        }
        if (preg_match('/^htw_(\d+)_(\d+)$/', $data, $m)) {
            return self::onWeekday($ctx, $uid, (int)$m[1], (int)$m[2]);
        }
        if (preg_match('/^hth_(\d+)_(\d+)_(\d+)$/', $data, $m)) {
            return self::onHour($ctx, $uid, (int)$m[1], (int)$m[2], (int)$m[3]);
        }
        if (preg_match('/^htr_(\d+)_(\d+)$/', $data, $m)) {
            return self::onHourBack($ctx, $uid, (int)$m[1], (int)$m[2]);
        }
        if (preg_match('/^hsd_(\d+)_(\d{8})_(\d+)_([01])$/', $data, $m)) {
            return self::onOverdueSlot($ctx, $uid, (int)$m[1], (string)$m[2], (int)$m[3], (int)$m[4] === 1);
        }
        if (preg_match('/^hs_(\d+)_(\d+)_([01])$/', $data, $m)) {
            return self::onTodaySlot($ctx, $uid, (int)$m[1], (int)$m[2], (int)$m[3] === 1);
        }
        if (preg_match('/^hz_(\d+)_(\d+)_(\d+)$/', $data, $m)) {
            return self::onSnooze($ctx, $uid, (int)$m[1], (int)$m[2], (int)$m[3]);
        }
        if (preg_match('/^mtf_(\d+)$/', $data, $m)) {
            NewsBotPlugin::showCardPublic($ctx, $uid, (int)$m[1], 1, 0, true);
            return true;
        }
        if (preg_match('/^mtfp_(\d+)_(\d+)_(\d+)$/', $data, $m)) {
            NewsBotPlugin::showCardPublic($ctx, $uid, (int)$m[1], (int)$m[2], (int)$m[3], true);
            return true;
        }
        return false;
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $state
     */
    public static function onText(array $ctx, array $state, int $uid, string $text): ?bool
    {
        $mode = (string)($state['mode'] ?? '');
        $data = is_array($state['data'] ?? null) ? $state['data'] : [];

        if ($mode === 'habit_end_date') {
            $tid = (int)($data['id_task'] ?? 0);
            if ($tid < 1) {
                return false;
            }
            if (!preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $text, $m)) {
                self::reply($ctx, t('core.bot.my_tasks.habit.bad_date'), [
                    'inline_keyboard' => [[[
                        'text' => t('core.bot.my_tasks.cancel'),
                        'callback_data' => 'htx_' . $tid,
                    ]]],
                ]);
                return true;
            }
            $ts = mktime(0, 0, 0, (int)$m[2], (int)$m[1], (int)$m[3]);
            if ($ts === false || $ts < 1) {
                self::reply($ctx, t('core.bot.my_tasks.habit.bad_date'), [
                    'inline_keyboard' => [[[
                        'text' => t('core.bot.my_tasks.cancel'),
                        'callback_data' => 'htx_' . $tid,
                    ]]],
                ]);
                return true;
            }
            $wiz = is_array($data['wizard'] ?? null) ? $data['wizard'] : ['id_task' => $tid];
            $wiz['id_task'] = $tid;
            $wiz['end_ymd'] = date('Y-m-d', (int)$ts);
            unset($wiz['unlimited']);
            self::setWizard($ctx, $tid, $wiz);
            self::renderFreqStep($ctx, $tid);
            return true;
        }

        if ($mode === 'habit_slot_comment') {
            $tid = (int)($data['id_task'] ?? 0);
            self::clearHabitPending($ctx);
            if ($tid > 0 && $text !== '' && class_exists('CommentsRepository', false)) {
                CommentsRepository::add($tid, $uid, $text);
            }
            self::reply($ctx, t('core.bot.my_tasks.habit.slot_comment_ok'), self::kbBack($tid > 0 ? $tid : 0));
            return true;
        }

        return null;
    }

    /** @param array<string,mixed> $ctx */
    private static function onEndStep(array $ctx, int $uid, int $tid, int $k): bool
    {
        if (!self::canManage($tid, $uid)) {
            return true;
        }
        $wiz = self::wizard($ctx) ?? ['id_task' => $tid];
        $wiz['id_task'] = $tid;
        $todayStart = (int)mktime(0, 0, 0);
        if ($k >= 1 && $k <= 5) {
            $wiz['end_ymd'] = date('Y-m-d', $todayStart + $k * 86400);
            unset($wiz['unlimited']);
        } elseif ($k === 8) {
            $wiz['unlimited'] = 1;
            unset($wiz['end_ymd']);
        } elseif ($k === 9) {
            self::setState($ctx, 'habit_end_date', [
                'id_task' => $tid,
                'wizard' => $wiz,
            ]);
            self::reply($ctx, t('core.bot.my_tasks.habit.end_date_prompt'), [
                'inline_keyboard' => [[[
                    'text' => t('core.bot.my_tasks.cancel'),
                    'callback_data' => 'htx_' . $tid,
                ]]],
            ]);
            return true;
        } else {
            return true;
        }
        self::setWizard($ctx, $tid, $wiz);
        self::renderFreqStep($ctx, $tid);
        return true;
    }

    /** @param array<string,mixed> $ctx */
    private static function onFreq(array $ctx, int $uid, int $tid, int $code): bool
    {
        if (!self::canManage($tid, $uid)) {
            return true;
        }
        $wiz = self::wizard($ctx);
        if ($wiz === null || (int)($wiz['id_task'] ?? 0) !== $tid) {
            return true;
        }
        $wiz['freq'] = $code;
        if ($code === 31) {
            self::setWizard($ctx, $tid, $wiz);
            self::renderWeekdayStep($ctx, $tid);
            return true;
        }
        $wiz['hours'] = [];
        self::setWizard($ctx, $tid, $wiz);
        self::beginHourFlow($ctx, $uid, $tid);
        return true;
    }

    /** @param array<string,mixed> $ctx */
    private static function onWeekday(array $ctx, int $uid, int $tid, int $dow): bool
    {
        if (!self::canManage($tid, $uid)) {
            return true;
        }
        $wiz = self::wizard($ctx);
        if ($wiz === null || (int)($wiz['id_task'] ?? 0) !== $tid) {
            return true;
        }
        $wiz['weekday'] = $dow;
        $wiz['hours'] = [];
        self::setWizard($ctx, $tid, $wiz);
        self::beginHourFlow($ctx, $uid, $tid);
        return true;
    }

    /** @param array<string,mixed> $ctx */
    private static function onHour(array $ctx, int $uid, int $tid, int $step, int $hour): bool
    {
        if (!self::canManage($tid, $uid)) {
            return true;
        }
        $wiz = self::wizard($ctx);
        if ($wiz === null || (int)($wiz['id_task'] ?? 0) !== $tid) {
            return true;
        }
        if (!isset($wiz['hours']) || !is_array($wiz['hours'])) {
            $wiz['hours'] = [];
        }
        while (count($wiz['hours']) <= $step) {
            $wiz['hours'][] = 0;
        }
        $wiz['hours'][$step] = $hour;
        $wiz['hours'] = array_slice($wiz['hours'], 0, $step + 1);
        self::setWizard($ctx, $tid, $wiz);
        self::beginHourFlow($ctx, $uid, $tid);
        return true;
    }

    /** @param array<string,mixed> $ctx */
    private static function onHourBack(array $ctx, int $uid, int $tid, int $backStep): bool
    {
        if (!self::canManage($tid, $uid)) {
            return true;
        }
        $wiz = self::wizard($ctx);
        if ($wiz === null || (int)($wiz['id_task'] ?? 0) !== $tid) {
            return true;
        }
        $wiz['hours'] = array_slice((array)($wiz['hours'] ?? []), 0, $backStep);
        self::setWizard($ctx, $tid, $wiz);
        self::beginHourFlow($ctx, $uid, $tid);
        return true;
    }

    /** @param array<string,mixed> $ctx */
    private static function beginHourFlow(array $ctx, int $uid, int $tid): void
    {
        $wiz = self::wizard($ctx);
        if ($wiz === null || (int)($wiz['id_task'] ?? 0) !== $tid) {
            return;
        }
        $need = 1;
        $f = isset($wiz['freq']) ? (int)$wiz['freq'] : 11;
        if ($f >= 11 && $f <= 13) {
            $need = $f - 10;
        }
        $wiz['hours'] = isset($wiz['hours']) ? array_values((array)$wiz['hours']) : [];
        $step = count($wiz['hours']);
        if ($step >= $need) {
            $habit = MyTasksHabits::wizardBuildConfig($wiz);
            if ($habit === null) {
                self::reply($ctx, t('core.bot.my_tasks.habit.cfg_error'), self::kbBack($tid));
                self::clearHabitPending($ctx);
                return;
            }
            MyTasksHabits::applyDeadline($tid, $habit, $uid);
            if (class_exists('CommentsRepository', false)) {
                $body = MyTasksHabits::formatCommentBody($habit);
                CommentsRepository::add(
                    $tid,
                    $uid,
                    t('core.bot.my_tasks.habit.enabled_comment') . ' ' . $body,
                    ['COLOR' => '2']
                );
            }
            self::clearHabitPending($ctx);
            self::reply($ctx, t('core.bot.my_tasks.habit.saved'), self::kbBack($tid));
            return;
        }
        self::renderHourStep($ctx, $tid, $step, (array)($wiz['hours'] ?? []));
    }

    /** @param array<string,mixed> $ctx */
    private static function onTodaySlot(array $ctx, int $uid, int $tid, int $idx, bool $ok): bool
    {
        if (!self::canManage($tid, $uid) || !class_exists('CommentsRepository', false)) {
            self::reply($ctx, t('core.bot.my_tasks.denied'), self::kbMenu());
            return true;
        }
        $habit = MyTasksHabits::fetchLatestConfig($tid);
        $todayStart = (int)mktime(0, 0, 0);
        if ($habit === null || !MyTasksHabits::isActiveCalendarDay($habit, $todayStart) || !isset($habit['hours'][$idx])) {
            NewsBotPlugin::showCardPublic($ctx, $uid, $tid, 1, 0, false);
            return true;
        }
        $h = (int)$habit['hours'][$idx];
        $ymd = MyTasksHabits::dayStartToYmd($todayStart);
        $mark = MyTasksHabits::slotMarker($ymd, $h, $idx, $ok ? 'ok' : 'skip');
        $line = ($ok ? t('core.bot.my_tasks.habit.done') : t('core.bot.my_tasks.habit.skipped')) . ' ' . $mark;
        CommentsRepository::add($tid, $uid, $line, ['COLOR' => $ok ? '1' : '2']);
        self::offerSlotComment($ctx, $tid, ($ok ? '✓' : '✕') . ' ' . sprintf('%02d:00', $h));
        return true;
    }

    /** @param array<string,mixed> $ctx */
    private static function onOverdueSlot(array $ctx, int $uid, int $tid, string $ymdRaw, int $idx, bool $ok): bool
    {
        if (!self::canManage($tid, $uid) || !class_exists('CommentsRepository', false)) {
            self::reply($ctx, t('core.bot.my_tasks.denied'), self::kbMenu());
            return true;
        }
        $habit = MyTasksHabits::fetchLatestConfig($tid);
        if ($habit === null) {
            NewsBotPlugin::showCardPublic($ctx, $uid, $tid, 1, 0, false);
            return true;
        }
        $allMissed = MyTasksHabits::collectUnmarkedDueSlots($habit, $tid, $uid, time(), 240);
        $missed = $allMissed !== [] ? $allMissed[count($allMissed) - 1] : null;
        $ymdFmt = substr($ymdRaw, 0, 4) . '-' . substr($ymdRaw, 4, 2) . '-' . substr($ymdRaw, 6, 2);
        if ($missed === null || $missed['ymd'] !== $ymdFmt || (int)$missed['idx'] !== $idx) {
            NewsBotPlugin::showCardPublic($ctx, $uid, $tid, 1, 0, false);
            return true;
        }
        if (count($allMissed) > 1) {
            for ($i = 0; $i < count($allMissed) - 1; $i++) {
                $old = $allMissed[$i];
                $oldMark = MyTasksHabits::slotMarker($old['ymd'], (int)$old['hour'], (int)$old['idx'], 'skip');
                CommentsRepository::add(
                    $tid,
                    $uid,
                    t('core.bot.my_tasks.habit.auto_skip') . ' ' . $oldMark,
                    ['COLOR' => '2']
                );
            }
        }
        $h = (int)$missed['hour'];
        $mark = MyTasksHabits::slotMarker($missed['ymd'], $h, $idx, $ok ? 'ok' : 'skip');
        $todayStart = (int)mktime(0, 0, 0);
        $missDay = MyTasksHabits::ymdToDayStart($missed['ymd']);
        $lateDays = ($missDay > 0 && $todayStart > $missDay)
            ? (int)floor(($todayStart - $missDay) / 86400)
            : 0;
        $lateTxt = $lateDays > 0
            ? ' ' . t('core.bot.my_tasks.habit.late_days', ['n' => $lateDays])
            : '';
        $line = ($ok ? t('core.bot.my_tasks.habit.done') : t('core.bot.my_tasks.habit.skipped'))
            . $lateTxt . ' ' . $mark;
        CommentsRepository::add($tid, $uid, $line, ['COLOR' => $ok ? '1' : '2']);
        $label = ($ok ? '✓' : '✕') . ' '
            . date('d.m', MyTasksHabits::ymdToDayStart($missed['ymd'])) . ' '
            . sprintf('%02d:00', $h);
        self::offerSlotComment($ctx, $tid, $label);
        return true;
    }

    /** @param array<string,mixed> $ctx */
    private static function onSnooze(array $ctx, int $uid, int $tid, int $sidx, int $code): bool
    {
        if (!self::canManage($tid, $uid) || !class_exists('CommentsRepository', false)) {
            self::reply($ctx, t('core.bot.my_tasks.denied'), self::kbMenu());
            return true;
        }
        $todayStart = (int)mktime(0, 0, 0);
        $ymd = MyTasksHabits::dayStartToYmd($todayStart);
        $now = time();
        if ($code === 999) {
            CommentsRepository::add(
                $tid,
                $uid,
                t('core.bot.my_tasks.habit.skip_day_comment') . ' [HABIT_SKIP_DAY|' . $ymd . ']',
                ['COLOR' => '2']
            );
            self::reply($ctx, t('core.bot.my_tasks.habit.skip_day_ok'), self::kbBack($tid));
            return true;
        }
        if (!in_array($code, [5, 10, 30, 60, 120], true)) {
            return true;
        }
        $until = $now + $code * 60;
        CommentsRepository::add(
            $tid,
            $uid,
            t('core.bot.my_tasks.habit.snooze_comment')
                . ' [HABIT_SNOOZE|' . $ymd . '|' . $sidx . '|' . $until . ']'
        );
        $row = NewsRepository::find($tid);
        $name = $row ? trim((string)($row['NAME'] ?? '')) : ('#' . $tid);
        if (class_exists('BotSend', false)) {
            try {
                BotSend::enqueue([
                    'audience' => 'staff',
                    'user_id' => $uid,
                    'text' => t('core.bot.my_tasks.habit.snooze_push', [
                        'id' => $tid,
                        'name' => $name,
                        'mins' => $code,
                    ]),
                    'name' => 'habit_snz_' . $tid . '_' . $uid . '_' . $ymd . '_' . $sidx . '_' . $until,
                    'type' => 12,
                    'dtsend' => $until,
                    'buttons' => MyTasksHabits::reminderKeyboard($tid, $sidx),
                ]);
            } catch (Throwable $e) {
                // soft
            }
        }
        self::reply($ctx, t('core.bot.my_tasks.habit.snooze_ok'), self::kbBack($tid));
        return true;
    }

    /** @param array<string,mixed> $ctx */
    private static function offerSlotComment(array $ctx, int $tid, string $slotLabel): void
    {
        self::setState($ctx, 'habit_slot_comment', ['id_task' => $tid]);
        self::reply($ctx, t('core.bot.my_tasks.habit.slot_comment_prompt', ['slot' => $slotLabel]), [
            'inline_keyboard' => [[
                ['text' => t('core.bot.my_tasks.habit.skip_comment'), 'callback_data' => 'my_task_i' . $tid . '_s1_p0'],
                ['text' => t('bots.catalog.staff.my_tasks'), 'callback_data' => 'my_tasks'],
            ]],
        ]);
    }

    /** @param array<string,mixed> $ctx */
    private static function renderEndStep(array $ctx, int $tid): void
    {
        $todayStart = (int)mktime(0, 0, 0);
        $btns = [];
        $row = [];
        for ($i = 1; $i <= 5; $i++) {
            $ds = $todayStart + $i * 86400;
            $row[] = [
                'text' => '+' . $i . t('core.bot.my_tasks.habit.day_short') . ' ' . date('d.m.y', $ds),
                'callback_data' => 'hte_' . $tid . '_' . $i,
            ];
            if (count($row) >= 2) {
                $btns[] = $row;
                $row = [];
            }
        }
        if ($row !== []) {
            $btns[] = $row;
        }
        $btns[] = [[
            'text' => t('core.bot.my_tasks.habit.end_custom'),
            'callback_data' => 'hte_' . $tid . '_9',
        ]];
        $btns[] = [[
            'text' => t('core.bot.my_tasks.habit.end_unlimited'),
            'callback_data' => 'hte_' . $tid . '_8',
        ]];
        $btns[] = [[
            'text' => t('core.bot.my_tasks.cancel'),
            'callback_data' => 'htx_' . $tid,
        ]];
        self::reply($ctx, t('core.bot.my_tasks.habit.wizard_intro', ['id' => $tid]), [
            'inline_keyboard' => $btns,
        ]);
    }

    /** @param array<string,mixed> $ctx */
    private static function renderFreqStep(array $ctx, int $tid): void
    {
        $btns = [
            [
                ['text' => t('core.bot.my_tasks.habit.freq_1'), 'callback_data' => 'htf_' . $tid . '_11'],
                ['text' => t('core.bot.my_tasks.habit.freq_2'), 'callback_data' => 'htf_' . $tid . '_12'],
            ],
            [
                ['text' => t('core.bot.my_tasks.habit.freq_3'), 'callback_data' => 'htf_' . $tid . '_13'],
            ],
            [
                ['text' => t('core.bot.my_tasks.habit.freq_2d'), 'callback_data' => 'htf_' . $tid . '_22'],
                ['text' => t('core.bot.my_tasks.habit.freq_3d'), 'callback_data' => 'htf_' . $tid . '_23'],
            ],
            [
                ['text' => t('core.bot.my_tasks.habit.freq_7d'), 'callback_data' => 'htf_' . $tid . '_27'],
                ['text' => t('core.bot.my_tasks.habit.freq_wd'), 'callback_data' => 'htf_' . $tid . '_31'],
            ],
            [[
                'text' => t('core.bot.my_tasks.cancel'),
                'callback_data' => 'htx_' . $tid,
            ]],
        ];
        self::reply($ctx, t('core.bot.my_tasks.habit.freq_prompt'), ['inline_keyboard' => $btns]);
    }

    /** @param array<string,mixed> $ctx */
    private static function renderWeekdayStep(array $ctx, int $tid): void
    {
        $rows = [];
        $r = [];
        for ($dn = 1; $dn <= 7; $dn++) {
            $r[] = [
                'text' => t('core.bot.my_tasks.habit.wd.' . $dn),
                'callback_data' => 'htw_' . $tid . '_' . $dn,
            ];
            if (count($r) >= 4) {
                $rows[] = $r;
                $r = [];
            }
        }
        if ($r !== []) {
            $rows[] = $r;
        }
        $rows[] = [[
            'text' => t('core.bot.my_tasks.cancel'),
            'callback_data' => 'htx_' . $tid,
        ]];
        self::reply($ctx, t('core.bot.my_tasks.habit.weekday_prompt'), ['inline_keyboard' => $rows]);
    }

    /**
     * @param array<string,mixed> $ctx
     * @param list<int|string> $pickedHours
     */
    private static function renderHourStep(array $ctx, int $tid, int $step, array $pickedHours): void
    {
        $btns = [];
        $row = [];
        for ($h = 6; $h <= 23; $h++) {
            $label = (string)$h;
            foreach ($pickedHours as $pi => $ph) {
                if ((int)$ph === $h && (int)$pi !== $step) {
                    $label = $h . '(№' . ((int)$pi + 1) . ')';
                    break;
                }
            }
            $row[] = [
                'text' => $label,
                'callback_data' => 'hth_' . $tid . '_' . $step . '_' . $h,
            ];
            if (count($row) >= 6) {
                $btns[] = $row;
                $row = [];
            }
        }
        if ($row !== []) {
            $btns[] = $row;
        }
        if ($step > 0) {
            $btns[] = [[
                'text' => t('core.bot.my_tasks.habit.hour_back', ['n' => $step]),
                'callback_data' => 'htr_' . $tid . '_' . ($step - 1),
            ]];
        }
        $btns[] = [[
            'text' => t('core.bot.my_tasks.cancel'),
            'callback_data' => 'htx_' . $tid,
        ]];
        self::reply($ctx, t('core.bot.my_tasks.habit.hour_prompt', ['n' => $step + 1]), [
            'inline_keyboard' => $btns,
        ]);
    }

    private static function canManage(int $tid, int $uid): bool
    {
        $row = NewsRepository::find($tid);
        if ($row === null || (int)($row['TYPE'] ?? 0) !== 2) {
            return false;
        }
        if (Auth::isSuperAdmin()) {
            return true;
        }
        return (int)($row['ID_USER'] ?? 0) === $uid
            || (int)($row['ID_CURATOR'] ?? 0) === $uid
            || in_array($uid, NewsRepository::parseUserList((string)($row['USERS_RESP'] ?? '')), true);
    }

    /** @param array<string,mixed> $ctx @return array<string,mixed>|null */
    private static function wizard(array $ctx): ?array
    {
        $st = self::getState($ctx);
        if ($st === null || ($st['module_id'] ?? '') !== 'my_tasks') {
            return null;
        }
        $data = is_array($st['data'] ?? null) ? $st['data'] : [];
        return is_array($data['wizard'] ?? null) ? $data['wizard'] : null;
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $wiz
     */
    private static function setWizard(array $ctx, int $tid, array $wiz): void
    {
        $wiz['id_task'] = $tid;
        self::setState($ctx, 'habit_wizard', ['id_task' => $tid, 'wizard' => $wiz]);
    }

    /** @param array<string,mixed> $ctx */
    private static function clearHabitPending(array $ctx): void
    {
        BotChatState::clear(
            BotLinksRepository::SOURCE_STAFF,
            (string)($ctx['platform'] ?? 'telegram'),
            (string)($ctx['chat_id'] ?? '')
        );
    }

    /** @param array<string,mixed> $ctx */
    private static function getState(array $ctx): ?array
    {
        return BotChatState::get(
            BotLinksRepository::SOURCE_STAFF,
            (string)($ctx['platform'] ?? 'telegram'),
            (string)($ctx['chat_id'] ?? '')
        );
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $data
     */
    private static function setState(array $ctx, string $mode, array $data): void
    {
        BotChatState::set(
            BotLinksRepository::SOURCE_STAFF,
            (string)($ctx['platform'] ?? 'telegram'),
            (string)($ctx['chat_id'] ?? ''),
            'my_tasks',
            $mode,
            $data
        );
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array{inline_keyboard?:list<list<array<string,string>>>}|null $kb
     */
    private static function reply(array $ctx, string $text, ?array $kb = null): void
    {
        BotClient::replyUi($ctx, $text, $kb);
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    private static function kbMenu(): array
    {
        return ['inline_keyboard' => [[
            ['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet'],
        ]]];
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    private static function kbBack(int $tid): array
    {
        $rows = [];
        if ($tid > 0) {
            $rows[] = [[
                'text' => t('core.bot.my_tasks.back_card'),
                'callback_data' => 'my_task_i' . $tid . '_s1_p0',
            ]];
        }
        $rows[] = [[
            'text' => t('bots.catalog.staff.my_tasks'),
            'callback_data' => 'my_tasks',
        ]];
        return ['inline_keyboard' => $rows];
    }
}
