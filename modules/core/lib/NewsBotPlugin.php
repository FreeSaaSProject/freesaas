<?php
declare(strict_types=1);

/**
 * Staff bot: my_tasks (full ticket FSM) + news list (requires bots module).
 * Pending state: BotChatState module_id=my_tasks.
 */
final class NewsBotPlugin
{
    private const MODULE = 'my_tasks';
    private const TTL = 3600;
    private const COMMENTS_PAGE = 10;
    private const WORK_MARKER = '[WORK_TODAY]';

    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }

        BotPlugins::register('my_tasks', [
            'priority' => 20,
            'audiences' => ['staff'],
            'label' => 'bots.catalog.staff.my_tasks',
            'module' => 'core',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return [];
                }
                return [[[
                    'text' => t('bots.catalog.staff.my_tasks'),
                    'callback_data' => 'my_tasks',
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return null;
                }
                $data = (string)($ctx['callback_data'] ?? '');
                if ($data === '') {
                    return null;
                }
                $isMine = strpos($data, 'my_tasks') === 0 || strpos($data, 'my_task_') === 0
                    || (class_exists('MyTasksHabitsBot', false) && MyTasksHabitsBot::isHabitCallback($data));
                if (!$isMine) {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1) {
                    return true;
                }
                return Auth::runAs($uid, static function () use ($ctx, $data, $uid) {
                    return self::onCallback($ctx, $data, $uid);
                });
            },
            'handle_text' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1) {
                    return null;
                }
                $state = self::state($ctx);
                if ($state === null || !BotChatState::isFresh($state, self::TTL)) {
                    return null;
                }
                if (($state['module_id'] ?? '') !== self::MODULE) {
                    return null;
                }
                $text = trim((string)($ctx['text'] ?? ''));
                if ($text === '') {
                    return null;
                }
                return Auth::runAs($uid, static function () use ($ctx, $state, $uid, $text) {
                    if (class_exists('MyTasksHabitsBot', false)) {
                        $habitText = MyTasksHabitsBot::onText($ctx, $state, $uid, $text);
                        if ($habitText !== null) {
                            return $habitText;
                        }
                    }
                    return self::onText($ctx, $state, $uid, $text);
                });
            },
            'chat_context' => static function (array $ctx): array {
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                $n = $uid > 0 ? count(self::listTickets($uid, 1)) : 0;
                return [
                    'title' => t('bots.catalog.staff.my_tasks'),
                    'summary' => 'Open tickets: ' . $n,
                    'facts' => ['open_tickets' => $n],
                ];
            },
        ]);

        BotPlugins::register('news', [
            'priority' => 30,
            'audiences' => ['staff'],
            'label' => 'bots.plugin.news',
            'module' => 'core',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return [];
                }
                return [[[
                    'text' => t('bots.plugin.news'),
                    'callback_data' => 'news',
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['callback_data'] ?? '') !== 'news') {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1) {
                    return true;
                }
                $result = NewsRepository::list([
                    'type' => 1,
                    'user_id' => $uid,
                    'limit' => 5,
                    'offset' => 0,
                ]);
                $lines = [];
                foreach ($result['items'] as $item) {
                    $lines[] = '#' . (int)$item['ID'] . ' ' . trim((string)($item['NAME'] ?? ''));
                }
                $text = $lines !== [] ? implode("\n", $lines) : t('core.common.empty');
                BotClient::replyUi($ctx, $text, [
                    'inline_keyboard' => [[
                        ['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet'],
                    ]],
                ]);
                return true;
            },
        ]);
    }

    /** @param array<string,mixed> $ctx */
    private static function onCallback(array $ctx, string $data, int $uid): bool
    {
        if (class_exists('MyTasksHabitsBot', false) && MyTasksHabitsBot::isHabitCallback($data)) {
            return MyTasksHabitsBot::onCallback($ctx, $data, $uid);
        }
        if ($data === 'my_tasks' || preg_match('/^my_tasks_s(\d+)$/', $data, $m)) {
            $status = isset($m[1]) ? (int)$m[1] : 1;
            self::clearState($ctx);
            self::showList($ctx, $uid, $status);
            return true;
        }
        if ($data === 'my_tasks_create') {
            self::setState($ctx, 'await_create', ['user_id' => $uid]);
            self::reply($ctx, t('core.bot.my_tasks.create_prompt'), self::kbCancelCreate());
            return true;
        }
        if ($data === 'my_tasks_create_cancel') {
            self::clearState($ctx);
            self::showList($ctx, $uid, 1);
            return true;
        }
        if (preg_match('/^my_task_i(\d+)_s(\d+)_p(\d+)$/', $data, $m)) {
            self::showCard($ctx, $uid, (int)$m[1], (int)$m[2], (int)$m[3], false);
            return true;
        }
        if (preg_match('/^my_task_work_(\d+)$/', $data, $m)) {
            if (self::habitBlocks((int)$m[1])) {
                self::reply($ctx, t('core.bot.my_tasks.habit.blocks_actions'), self::kbBackCard((int)$m[1]));
                return true;
            }
            self::workToday($ctx, $uid, (int)$m[1]);
            return true;
        }
        if (preg_match('/^my_task_c(\d+)_(\d+)$/', $data, $m)) {
            self::showComments($ctx, $uid, (int)$m[1], (int)$m[2]);
            return true;
        }
        if (preg_match('/^my_task_addc_(\d+)$/', $data, $m)) {
            $id = (int)$m[1];
            if (!self::canViewTicket($id, $uid)) {
                self::reply($ctx, t('core.bot.my_tasks.denied'), self::kbMenu());
                return true;
            }
            self::setState($ctx, 'await_comment', ['user_id' => $uid, 'id' => $id]);
            self::reply($ctx, t('core.bot.my_tasks.comment_prompt'), self::kbCancelField($id));
            return true;
        }
        if (preg_match('/^my_task_title_(\d+)$/', $data, $m)) {
            return self::beginFieldEdit($ctx, $uid, (int)$m[1], 'await_title', t('core.bot.my_tasks.title_prompt'));
        }
        if (preg_match('/^my_task_desc_(\d+)$/', $data, $m)) {
            return self::beginFieldEdit($ctx, $uid, (int)$m[1], 'await_desc', t('core.bot.my_tasks.desc_prompt'));
        }
        if (preg_match('/^my_task_field_cancel_(\d+)$/', $data, $m)) {
            self::clearState($ctx);
            self::showCard($ctx, $uid, (int)$m[1], 1, 0, false);
            return true;
        }
        if (preg_match('/^my_task_dl_(\d+)$/', $data, $m)
            || preg_match('/^my_task_dlset_(\d+)_/', $data, $m)
            || preg_match('/^my_task_dlinput_(\d+)$/', $data, $m)
            || preg_match('/^my_task_st_(\d+)$/', $data, $m)
            || preg_match('/^my_task_stset_(\d+)_/', $data, $m)
        ) {
            $id = (int)$m[1];
            if (self::habitBlocks($id)) {
                self::reply($ctx, t('core.bot.my_tasks.habit.blocks_actions'), self::kbBackCard($id));
                return true;
            }
        }
        if (preg_match('/^my_task_dl_(\d+)$/', $data, $m)) {
            self::showDeadlineMenu($ctx, $uid, (int)$m[1]);
            return true;
        }
        if (preg_match('/^my_task_dlset_(\d+)_(tomorrow|plus3|plus7|clear)$/', $data, $m)) {
            self::setDeadlinePreset($ctx, $uid, (int)$m[1], (string)$m[2]);
            return true;
        }
        if (preg_match('/^my_task_dlinput_(\d+)$/', $data, $m)) {
            return self::beginFieldEdit($ctx, $uid, (int)$m[1], 'await_deadline', t('core.bot.my_tasks.deadline_prompt'));
        }
        if (preg_match('/^my_task_st_(\d+)$/', $data, $m)) {
            self::showStatusMenu($ctx, $uid, (int)$m[1]);
            return true;
        }
        if (preg_match('/^my_task_stset_(\d+)_(\d+)$/', $data, $m)) {
            self::setStatus($ctx, $uid, (int)$m[1], (int)$m[2]);
            return true;
        }
        return false;
    }

    /** Public for MyTasksHabitsBot. */
    public static function showCardPublic(array $ctx, int $uid, int $id, int $status, int $page, bool $fullHabit): void
    {
        self::showCard($ctx, $uid, $id, $status, $page, $fullHabit);
    }

    private static function habitBlocks(int $taskId): bool
    {
        return class_exists('MyTasksHabits', false) && MyTasksHabits::isActive($taskId);
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $state
     */
    private static function onText(array $ctx, array $state, int $uid, string $text): bool
    {
        $mode = (string)($state['mode'] ?? '');
        $data = is_array($state['data'] ?? null) ? $state['data'] : [];
        $id = (int)($data['id'] ?? 0);

        if ($mode === 'await_create') {
            self::clearState($ctx);
            $title = mb_substr($text, 0, 200);
            $today = (int)mktime(0, 0, 0);
            $curator = self::defaultCurator($uid);
            $encoded = NewsRepository::encodeIdList([$uid]);
            $newId = NewsRepository::create([
                'TYPE' => 2,
                'STATUS' => 1,
                'NAME' => $title,
                'BIG' => t('core.bot.my_tasks.default_body'),
                'DT_DEADLINE' => $today,
                'USERS_RESP' => $encoded,
                'ACCESS_USER' => $encoded,
                'ID_CURATOR' => $curator,
                'COMMENTS_LOCKED' => 0,
            ], $uid);
            self::reply($ctx, t('core.bot.my_tasks.created', ['id' => $newId]), self::kbMenu());
            self::showCard($ctx, $uid, $newId, 1, 0, false);
            return true;
        }

        if ($id < 1) {
            return false;
        }
        $row = NewsRepository::find($id);
        if ($row === null) {
            self::clearState($ctx);
            self::reply($ctx, t('core.bot.my_tasks.denied'), self::kbMenu());
            return true;
        }

        if ($mode === 'await_comment') {
            if (!self::canViewRow($row, $uid)) {
                self::clearState($ctx);
                self::reply($ctx, t('core.bot.my_tasks.denied'), self::kbMenu());
                return true;
            }
            if (!class_exists('CommentsRepository', false)) {
                self::reply($ctx, t('core.bot.my_tasks.no_comments'), self::kbBackCard($id));
                return true;
            }
            if (!empty($row['COMMENTS_LOCKED'])) {
                self::clearState($ctx);
                self::reply($ctx, t('core.bot.my_tasks.comments_locked'), self::kbBackCard($id));
                return true;
            }
            CommentsRepository::add($id, $uid, $text);
            self::clearState($ctx);
            self::reply($ctx, t('core.bot.my_tasks.comment_ok'), self::kbBackCard($id));
            return true;
        }
        if (!self::canManage($row, $uid)) {
            self::clearState($ctx);
            self::reply($ctx, t('core.bot.my_tasks.denied'), self::kbMenu());
            return true;
        }
        if ($mode === 'await_title') {
            NewsRepository::update($id, ['NAME' => mb_substr($text, 0, 200)], $uid);
            if (class_exists('CommentsRepository', false)) {
                CommentsRepository::add($id, $uid, t('core.bot.my_tasks.title_changed', ['title' => mb_substr($text, 0, 200)]), [
                    'STATUS_UPDATE' => 1,
                ]);
            }
            self::clearState($ctx);
            self::showCard($ctx, $uid, $id, 1, 0, false);
            return true;
        }
        if ($mode === 'await_desc') {
            NewsRepository::update($id, ['BIG' => $text], $uid);
            if (class_exists('CommentsRepository', false)) {
                CommentsRepository::add($id, $uid, t('core.bot.my_tasks.desc_changed'), ['STATUS_UPDATE' => 1]);
            }
            self::clearState($ctx);
            self::showCard($ctx, $uid, $id, 1, 0, false);
            return true;
        }
        if ($mode === 'await_deadline') {
            if (self::habitBlocks($id)) {
                self::clearState($ctx);
                self::reply($ctx, t('core.bot.my_tasks.habit.blocks_actions'), self::kbBackCard($id));
                return true;
            }
            $ts = self::parseDate($text);
            if ($ts === null) {
                self::reply($ctx, t('core.bot.my_tasks.deadline_bad'), self::kbCancelField($id));
                return true;
            }
            NewsRepository::update($id, ['DT_DEADLINE' => $ts], $uid);
            if (class_exists('CommentsRepository', false)) {
                CommentsRepository::add($id, $uid, t('core.bot.my_tasks.deadline_changed', [
                    'date' => date('d.m.Y', $ts),
                ]), ['STATUS_UPDATE' => 1]);
            }
            self::clearState($ctx);
            self::showCard($ctx, $uid, $id, 1, 0, false);
            return true;
        }
        return false;
    }

    /** @param array<string,mixed> $ctx */
    private static function showList(array $ctx, int $uid, int $status): void
    {
        $items = self::listTickets($uid, $status);
        $statuses = NewsRepository::statuses();
        $statusName = (string)($statuses[$status]['NAME'] ?? ('#' . $status));
        $todayStart = (int)mktime(0, 0, 0);

        $ids = [];
        foreach ($items as $it) {
            $ids[] = (int)$it['ID'];
        }
        $habits = class_exists('MyTasksHabits', false)
            ? MyTasksHabits::fetchLatestConfigBatch($ids)
            : [];
        $progress = class_exists('MyTasksHabits', false)
            ? MyTasksHabits::listProgressBatch($ids, $uid, $todayStart)
            : [];

        $filtered = [];
        foreach ($items as $item) {
            $tid = (int)$item['ID'];
            $habit = $habits[$tid] ?? null;
            if (class_exists('MyTasksHabits', false)
                && !MyTasksHabits::taskVisibleInList($item, $habit, $todayStart, $uid)
            ) {
                continue;
            }
            $filtered[] = $item;
        }
        $items = $filtered;

        $kb = ['inline_keyboard' => []];
        $kb['inline_keyboard'][] = [[
            'text' => t('core.bot.my_tasks.create_btn'),
            'callback_data' => 'my_tasks_create',
        ]];

        if ($items === []) {
            $text = t('core.bot.my_tasks.empty_status', ['status' => $statusName]);
            $kb['inline_keyboard'][] = self::statusFilterRow($statuses, $status);
            $kb['inline_keyboard'][] = [['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet']];
            self::reply($ctx, $text, $kb);
            return;
        }

        $todayEnd = (int)mktime(23, 59, 59);
        $overdue = [];
        $rest = [];
        $workToday = self::workTodayIds($uid);
        foreach ($items as $item) {
            $tid = (int)$item['ID'];
            $habit = $habits[$tid] ?? null;
            $dl = (int)($item['DT_DEADLINE'] ?? 0);
            if ($habit === null && $status === 1 && $dl > 0 && $dl < $todayEnd - 86399) {
                $overdue[] = $item;
            } else {
                $rest[] = $item;
            }
        }

        $lines = [t('core.bot.my_tasks.list_title', ['status' => $statusName])];
        $page = 0;
        $emit = static function (array $item) use (&$lines, &$kb, $workToday, $progress, $status, $page): void {
            $tid = (int)$item['ID'];
            $suf = isset($progress[$tid]) ? (' ' . $progress[$tid]) : '';
            $lines[] = self::listLine($item, $workToday) . $suf;
            $kb['inline_keyboard'][] = [[
                'text' => self::btnLabel($item, $workToday) . $suf,
                'callback_data' => 'my_task_i' . $tid . '_s' . $status . '_p' . $page,
            ]];
        };
        if ($overdue !== []) {
            $lines[] = t('core.bot.my_tasks.overdue_hdr');
            foreach ($overdue as $item) {
                $emit($item);
            }
            if ($rest !== []) {
                $lines[] = t('core.bot.my_tasks.inwork_hdr');
            }
        }
        foreach ($rest as $item) {
            $emit($item);
        }
        $kb['inline_keyboard'][] = self::statusFilterRow($statuses, $status);
        $kb['inline_keyboard'][] = [['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet']];
        self::reply($ctx, implode("\n", $lines), $kb);
    }

    /** @param array<string,mixed> $ctx */
    private static function showCard(array $ctx, int $uid, int $id, int $status, int $page, bool $fullHabit = false): void
    {
        $row = NewsRepository::find($id);
        if ($row === null || !self::canViewRow($row, $uid)) {
            self::reply($ctx, t('core.bot.my_tasks.denied'), self::kbMenu());
            return;
        }
        $habit = class_exists('MyTasksHabits', false) ? MyTasksHabits::fetchLatestConfig($id) : null;
        $todayStart = (int)mktime(0, 0, 0);
        $canManage = self::canManage($row, $uid);

        // Compact habit card (default when habit active).
        if ($habit !== null && !$fullHabit && $canManage && class_exists('MyTasksHabits', false)) {
            $compact = MyTasksHabits::buildCompactKeyboard($row, $uid, $todayStart);
            if ($compact !== null) {
                $slots = MyTasksHabits::formatTodaySlotsLine($habit, $uid, $id, $todayStart);
                $text = t('core.bot.my_tasks.habit.compact_card', [
                    'id' => $id,
                    'name' => trim((string)($row['NAME'] ?? '')),
                    'settings' => MyTasksHabits::formatSettings($habit),
                    'slots' => $slots,
                ]);
                self::reply($ctx, $text, ['inline_keyboard' => $compact]);
                return;
            }
        }

        $statuses = NewsRepository::statuses();
        $stId = (int)($row['STATUS'] ?? 0);
        $stName = (string)($statuses[$stId]['NAME'] ?? ('#' . $stId));
        $dl = (int)($row['DT_DEADLINE'] ?? 0);
        $dlLabel = $dl > 0 ? date('d.m.Y', $dl) : t('core.bot.my_tasks.no_deadline');
        $body = trim(strip_tags((string)($row['BIG'] ?? '')));
        if (mb_strlen($body) > 800) {
            $body = mb_substr($body, 0, 800) . '…';
        }
        $text = t('core.bot.my_tasks.card', [
            'id' => $id,
            'name' => trim((string)($row['NAME'] ?? '')),
            'status' => $stName,
            'deadline' => $dlLabel,
            'body' => $body !== '' ? $body : '—',
        ]);
        if ($habit !== null) {
            $text .= "\n\n" . MyTasksHabits::formatSettings($habit);
            $slots = MyTasksHabits::formatTodaySlotsLine($habit, $uid, $id, $todayStart);
            if ($slots !== '') {
                $text .= "\n" . $slots;
            }
        }

        $rows = [];
        if ($habit === null && $canManage && $stId === 1) {
            $rows[] = [[
                'text' => t('core.bot.my_tasks.work_today'),
                'callback_data' => 'my_task_work_' . $id,
            ]];
        }
        $rows[] = [
            ['text' => t('core.bot.my_tasks.comments'), 'callback_data' => 'my_task_c' . $id . '_0'],
            ['text' => t('core.bot.my_tasks.add_comment'), 'callback_data' => 'my_task_addc_' . $id],
        ];
        if ($canManage) {
            $rows[] = [
                ['text' => t('core.bot.my_tasks.edit_title'), 'callback_data' => 'my_task_title_' . $id],
                ['text' => t('core.bot.my_tasks.edit_desc'), 'callback_data' => 'my_task_desc_' . $id],
            ];
            if ($habit === null) {
                $rows[] = [
                    ['text' => t('core.bot.my_tasks.deadline_btn'), 'callback_data' => 'my_task_dl_' . $id],
                    ['text' => t('core.bot.my_tasks.status_btn'), 'callback_data' => 'my_task_st_' . $id],
                ];
                $rows[] = [[
                    'text' => t('core.bot.my_tasks.habit.make_btn'),
                    'callback_data' => 'htb_' . $id,
                ]];
            } else {
                $rows[] = [
                    ['text' => t('core.bot.my_tasks.habit.reconfigure'), 'callback_data' => 'htb_' . $id],
                    ['text' => t('core.bot.my_tasks.habit.disable'), 'callback_data' => 'htoff_' . $id],
                ];
            }
        }
        $rows[] = [[
            'text' => t('core.bot.my_tasks.back_list'),
            'callback_data' => 'my_tasks_s' . max(1, $status),
        ]];
        $rows[] = [['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet']];
        self::reply($ctx, $text, ['inline_keyboard' => $rows]);
    }

    /** @param array<string,mixed> $ctx */
    private static function showComments(array $ctx, int $uid, int $id, int $offset): void
    {
        $row = NewsRepository::find($id);
        if ($row === null || !self::canViewRow($row, $uid)) {
            self::reply($ctx, t('core.bot.my_tasks.denied'), self::kbMenu());
            return;
        }
        if (!class_exists('CommentsRepository', false)) {
            self::reply($ctx, t('core.bot.my_tasks.no_comments'), self::kbBackCard($id));
            return;
        }
        $page = CommentsRepository::listForAgent($id, [
            'pid' => 0,
            'limit' => self::COMMENTS_PAGE,
            'offset' => max(0, $offset),
            'order' => 'desc',
            'excerpt' => 400,
        ]);
        $lines = [t('core.bot.my_tasks.comments_title', ['id' => $id])];
        if (($page['items'] ?? []) === []) {
            $lines[] = t('core.bot.my_tasks.comments_empty');
        } else {
            foreach ($page['items'] as $c) {
                $whoId = (int)($c['id_user'] ?? $c['ID_USER'] ?? 0);
                $who = $whoId > 0 ? ('#' . $whoId) : '';
                if ($whoId > 0) {
                    $u = UsersRepository::find($whoId);
                    if ($u !== null) {
                        $nm = trim((string)($u['name'] ?? ''));
                        if ($nm !== '') {
                            $who = $nm;
                        }
                    }
                }
                $whenTs = (int)($c['dt'] ?? $c['DT'] ?? 0);
                $when = $whenTs > 0 ? date('d.m H:i', $whenTs) : '';
                $body = trim((string)($c['text'] ?? $c['excerpt'] ?? $c['TEXT'] ?? ''));
                $lines[] = ($when !== '' ? $when . ' ' : '') . $who . ":\n" . $body;
            }
        }
        $rows = [];
        if (!empty($page['has_more'])) {
            $rows[] = [[
                'text' => t('core.bot.my_tasks.comments_more'),
                'callback_data' => 'my_task_c' . $id . '_' . (int)($page['next_offset'] ?? ($offset + self::COMMENTS_PAGE)),
            ]];
        }
        $rows[] = [
            ['text' => t('core.bot.my_tasks.add_comment'), 'callback_data' => 'my_task_addc_' . $id],
            ['text' => t('core.bot.my_tasks.back_card'), 'callback_data' => 'my_task_i' . $id . '_s1_p0'],
        ];
        self::reply($ctx, implode("\n\n", $lines), ['inline_keyboard' => $rows]);
    }

    /** @param array<string,mixed> $ctx */
    private static function workToday(array $ctx, int $uid, int $id): void
    {
        $row = NewsRepository::find($id);
        if ($row === null || !self::canManage($row, $uid)) {
            self::reply($ctx, t('core.bot.my_tasks.denied'), self::kbMenu());
            return;
        }
        if (!class_exists('CommentsRepository', false)) {
            self::reply($ctx, t('core.bot.my_tasks.no_comments'), self::kbBackCard($id));
            return;
        }
        $stamp = date('Y-m-d H:i');
        CommentsRepository::add(
            $id,
            $uid,
            t('core.bot.my_tasks.work_today_text') . ' [BOT]' . self::WORK_MARKER . ' ' . $stamp
        );
        self::reply($ctx, t('core.bot.my_tasks.work_today_ok'), self::kbBackCard($id));
    }

    /** @param array<string,mixed> $ctx */
    private static function showDeadlineMenu(array $ctx, int $uid, int $id): void
    {
        $row = NewsRepository::find($id);
        if ($row === null || !self::canManage($row, $uid)) {
            self::reply($ctx, t('core.bot.my_tasks.denied'), self::kbMenu());
            return;
        }
        $kb = ['inline_keyboard' => [
            [
                ['text' => t('core.bot.my_tasks.dl_tomorrow'), 'callback_data' => 'my_task_dlset_' . $id . '_tomorrow'],
                ['text' => t('core.bot.my_tasks.dl_plus3'), 'callback_data' => 'my_task_dlset_' . $id . '_plus3'],
            ],
            [
                ['text' => t('core.bot.my_tasks.dl_plus7'), 'callback_data' => 'my_task_dlset_' . $id . '_plus7'],
                ['text' => t('core.bot.my_tasks.dl_clear'), 'callback_data' => 'my_task_dlset_' . $id . '_clear'],
            ],
            [['text' => t('core.bot.my_tasks.dl_custom'), 'callback_data' => 'my_task_dlinput_' . $id]],
            [['text' => t('core.bot.my_tasks.back_card'), 'callback_data' => 'my_task_i' . $id . '_s1_p0']],
        ]];
        self::reply($ctx, t('core.bot.my_tasks.deadline_menu'), $kb);
    }

    /** @param array<string,mixed> $ctx */
    private static function setDeadlinePreset(array $ctx, int $uid, int $id, string $preset): void
    {
        $row = NewsRepository::find($id);
        if ($row === null || !self::canManage($row, $uid)) {
            self::reply($ctx, t('core.bot.my_tasks.denied'), self::kbMenu());
            return;
        }
        $today = (int)mktime(0, 0, 0);
        if ($preset === 'clear') {
            $ts = 0;
        } elseif ($preset === 'tomorrow') {
            $ts = $today + 86400;
        } elseif ($preset === 'plus3') {
            $ts = $today + 3 * 86400;
        } else {
            $ts = $today + 7 * 86400;
        }
        NewsRepository::update($id, ['DT_DEADLINE' => $ts], $uid);
        if (class_exists('CommentsRepository', false)) {
            $label = $ts > 0 ? date('d.m.Y', $ts) : t('core.bot.my_tasks.no_deadline');
            CommentsRepository::add($id, $uid, t('core.bot.my_tasks.deadline_changed', ['date' => $label]), [
                'STATUS_UPDATE' => 1,
            ]);
        }
        self::showCard($ctx, $uid, $id, 1, 0, false);
    }

    /** @param array<string,mixed> $ctx */
    private static function showStatusMenu(array $ctx, int $uid, int $id): void
    {
        $row = NewsRepository::find($id);
        if ($row === null || !self::canManage($row, $uid)) {
            self::reply($ctx, t('core.bot.my_tasks.denied'), self::kbMenu());
            return;
        }
        $rows = [];
        $rowBtns = [];
        $i = 0;
        foreach (NewsRepository::statuses() as $sid => $meta) {
            $name = trim((string)($meta['NAME'] ?? ('#' . $sid)));
            $rowBtns[] = [
                'text' => $name,
                'callback_data' => 'my_task_stset_' . $id . '_' . (int)$sid,
            ];
            $i++;
            if ($i % 2 === 0) {
                $rows[] = $rowBtns;
                $rowBtns = [];
            }
        }
        if ($rowBtns !== []) {
            $rows[] = $rowBtns;
        }
        $rows[] = [['text' => t('core.bot.my_tasks.back_card'), 'callback_data' => 'my_task_i' . $id . '_s1_p0']];
        self::reply($ctx, t('core.bot.my_tasks.status_menu'), ['inline_keyboard' => $rows]);
    }

    /** @param array<string,mixed> $ctx */
    private static function setStatus(array $ctx, int $uid, int $id, int $statusId): void
    {
        $row = NewsRepository::find($id);
        if ($row === null || !self::canManage($row, $uid)) {
            self::reply($ctx, t('core.bot.my_tasks.denied'), self::kbMenu());
            return;
        }
        $statuses = NewsRepository::statuses();
        if (!isset($statuses[$statusId])) {
            self::reply($ctx, t('core.bot.my_tasks.status_bad'), self::kbBackCard($id));
            return;
        }
        $data = ['STATUS' => $statusId];
        // Terminal-ish: clear deadline when leaving "in work" (status 1) to closed-looking ids ≥ 3.
        if ($statusId !== 1 && (int)($row['DT_DEADLINE'] ?? 0) > 0 && $statusId >= 3) {
            $data['DT_DEADLINE'] = 0;
        }
        NewsRepository::update($id, $data, $uid);
        if (class_exists('CommentsRepository', false)) {
            CommentsRepository::add($id, $uid, t('core.bot.my_tasks.status_changed', [
                'status' => (string)$statuses[$statusId]['NAME'],
            ]), ['STATUS_UPDATE' => 1]);
        }
        self::showCard($ctx, $uid, $id, $statusId, 0, false);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private static function beginFieldEdit(array $ctx, int $uid, int $id, string $mode, string $prompt): bool
    {
        $row = NewsRepository::find($id);
        if ($row === null || !self::canManage($row, $uid)) {
            self::reply($ctx, t('core.bot.my_tasks.denied'), self::kbMenu());
            return true;
        }
        self::setState($ctx, $mode, ['user_id' => $uid, 'id' => $id]);
        self::reply($ctx, $prompt, self::kbCancelField($id));
        return true;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function listTickets(int $uid, int $status): array
    {
        if (!Database::tableExists('base_news') || $uid < 1) {
            return [];
        }
        $needle = '%#' . $uid . '#%';
        $st = Database::pdo()->prepare(
            'SELECT ID, NAME, STATUS, DT_DEADLINE, USERS_RESP, ID_CURATOR, ID_USER, COMMENTS_LOCKED, BIG, DT
             FROM base_news
             WHERE TYPE = 2 AND DISABLED = 0 AND HAS_CHILDREN = 0
               AND (PID = 0 OR PID IS NULL) AND STATUS = ?
               AND (USERS_RESP LIKE ? OR USERS_RESP = ? OR ID_CURATOR = ? OR ID_USER = ?)
             ORDER BY DT DESC
             LIMIT 80'
        );
        $st->execute([$status, $needle, (string)$uid, $uid, $uid]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,true> */
    private static function workTodayIds(int $uid): array
    {
        if (!class_exists('CommentsRepository', false) || !Database::tableExists('base_comments')) {
            return [];
        }
        $today = (int)mktime(0, 0, 0);
        $st = Database::pdo()->prepare(
            'SELECT DISTINCT ID_OBJECT AS id FROM base_comments
             WHERE ID_USER = ? AND DT >= ? AND TEXT LIKE ?'
        );
        $st->execute([$uid, $today, '%' . self::WORK_MARKER . '%']);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int)$row['id']] = true;
        }
        return $out;
    }

    /** @param array<string,mixed> $item @param array<int,true> $workToday */
    private static function listLine(array $item, array $workToday): string
    {
        $id = (int)$item['ID'];
        $mark = isset($workToday[$id]) ? '💼 ' : '';
        $dl = (int)($item['DT_DEADLINE'] ?? 0);
        $dlBit = $dl > 0 ? (' · ' . date('d.m', $dl)) : '';
        return $mark . '#' . $id . ' ' . trim((string)($item['NAME'] ?? '')) . $dlBit;
    }

    /** @param array<string,mixed> $item @param array<int,true> $workToday */
    private static function btnLabel(array $item, array $workToday): string
    {
        $id = (int)$item['ID'];
        $mark = isset($workToday[$id]) ? '💼 ' : '';
        $name = trim((string)($item['NAME'] ?? ''));
        if (mb_strlen($name) > 40) {
            $name = mb_substr($name, 0, 40) . '…';
        }
        return $mark . '#' . $id . ' ' . $name;
    }

    /**
     * @param array<int,array{NAME?:string,COLOR?:string}> $statuses
     * @return list<array{text:string,callback_data:string}>
     */
    private static function statusFilterRow(array $statuses, int $current): array
    {
        $row = [];
        $n = 0;
        foreach ($statuses as $sid => $meta) {
            if ($n >= 4) {
                break;
            }
            $name = trim((string)($meta['NAME'] ?? ('#' . $sid)));
            if (mb_strlen($name) > 12) {
                $name = mb_substr($name, 0, 12);
            }
            $prefix = ((int)$sid === $current) ? '· ' : '';
            $row[] = [
                'text' => $prefix . $name,
                'callback_data' => 'my_tasks_s' . (int)$sid,
            ];
            $n++;
        }
        return $row;
    }

    private static function defaultCurator(int $uid): int
    {
        if (!Database::tableExists('base_users') || !Database::columnExists('base_users', 'IS_ACCEPTOR')) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            "SELECT id FROM base_users
             WHERE enabled = '1' AND IS_ACCEPTOR = 1 AND id <> ?
             ORDER BY name ASC, id ASC LIMIT 1"
        );
        $st->execute([$uid]);
        return (int)$st->fetchColumn();
    }

    private static function canViewTicket(int $id, int $uid): bool
    {
        $row = NewsRepository::find($id);
        return $row !== null && self::canViewRow($row, $uid);
    }

    /** @param array<string,mixed> $row */
    private static function canViewRow(array $row, int $uid): bool
    {
        if ((int)($row['TYPE'] ?? 0) !== 2) {
            return false;
        }
        if (Auth::isSuperAdmin()) {
            return true;
        }
        return (int)($row['ID_USER'] ?? 0) === $uid
            || (int)($row['ID_CURATOR'] ?? 0) === $uid
            || in_array($uid, NewsRepository::parseUserList((string)($row['USERS_RESP'] ?? '')), true);
    }

    /** @param array<string,mixed> $row */
    private static function canManage(array $row, int $uid): bool
    {
        return self::canViewRow($row, $uid);
    }

    private static function parseDate(string $text): ?int
    {
        $text = trim($text);
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $text, $m)) {
            $ts = mktime(0, 0, 0, (int)$m[2], (int)$m[1], (int)$m[3]);
            return $ts !== false ? (int)$ts : null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m)) {
            $ts = mktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
            return $ts !== false ? (int)$ts : null;
        }
        return null;
    }

    /** @param array<string,mixed> $ctx */
    private static function state(array $ctx): ?array
    {
        if (!class_exists('BotChatState', false)) {
            return null;
        }
        return BotChatState::get(
            BotLinksRepository::SOURCE_STAFF,
            (string)($ctx['platform'] ?? 'telegram'),
            (string)($ctx['chat_id'] ?? '')
        );
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $data */
    private static function setState(array $ctx, string $mode, array $data = []): void
    {
        BotChatState::set(
            BotLinksRepository::SOURCE_STAFF,
            (string)($ctx['platform'] ?? 'telegram'),
            (string)($ctx['chat_id'] ?? ''),
            self::MODULE,
            $mode,
            $data
        );
    }

    /** @param array<string,mixed> $ctx */
    private static function clearState(array $ctx): void
    {
        BotChatState::clear(
            BotLinksRepository::SOURCE_STAFF,
            (string)($ctx['platform'] ?? 'telegram'),
            (string)($ctx['chat_id'] ?? '')
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
        return ['inline_keyboard' => [
            [['text' => t('core.bot.my_tasks.back_list'), 'callback_data' => 'my_tasks']],
            [['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet']],
        ]];
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    private static function kbCancelCreate(): array
    {
        return ['inline_keyboard' => [[
            ['text' => t('core.bot.my_tasks.cancel'), 'callback_data' => 'my_tasks_create_cancel'],
        ]]];
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    private static function kbCancelField(int $id): array
    {
        return ['inline_keyboard' => [[
            ['text' => t('core.bot.my_tasks.cancel'), 'callback_data' => 'my_task_field_cancel_' . $id],
        ]]];
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    private static function kbBackCard(int $id): array
    {
        return ['inline_keyboard' => [
            [['text' => t('core.bot.my_tasks.back_card'), 'callback_data' => 'my_task_i' . $id . '_s1_p0']],
            [['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet']],
        ]];
    }
}
