<?php
declare(strict_types=1);

/**
 * Staff bot «Food & sport» + own recipes (oper fit_food_entry / recipes).
 * Pending state: BotChatState module_id=fit_food_entry.
 */
final class FitFoodBotPlugin
{
    private const MODULE = 'fit_food_entry';
    private const TTL = 3600;

    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }

        BotPlugins::register('fit_food_entry', [
            'priority' => 35,
            'audiences' => ['staff'],
            'label' => 'bots.catalog.staff.fit_food_entry',
            'module' => 'wellness',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return [];
                }
                return [[[
                    'text' => t('bots.catalog.staff.fit_food_entry'),
                    'callback_data' => 'fit_food_entry',
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return null;
                }
                $data = (string)($ctx['callback_data'] ?? '');
                if ($data === '' || (strpos($data, 'fit_food_entry') !== 0 && $data !== 'fit_food_entry')) {
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
                    return self::onText($ctx, $state, $uid, $text);
                });
            },
            'handle_message' => static function (array $ctx) {
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
                if (($state['module_id'] ?? '') !== self::MODULE || ($state['mode'] ?? '') !== 'await_input') {
                    return null;
                }
                if (!class_exists('VoiceTranscribeBotPlugin', false)) {
                    return null;
                }
                $raw = is_array($ctx['raw'] ?? null) ? $ctx['raw'] : [];
                $audio = VoiceTranscribeBotPlugin::extractAudio($ctx, $raw);
                if ($audio === null) {
                    return null;
                }
                $res = VoiceTranscribeBotPlugin::enqueueFromPath(
                    (string)($ctx['platform'] ?? 'telegram'),
                    (string)($ctx['chat_id'] ?? ''),
                    $uid,
                    $audio['path'],
                    $audio['ext'],
                    ['consumer_module' => self::MODULE, 'consumer_mode' => 'add_food']
                );
                if (!$res['ok']) {
                    self::reply($ctx, t('wellness.bot.voice_fail'));
                    return true;
                }
                self::setState($ctx, 'await_transcription', ['job_id' => $res['job_id'], 'user_id' => $uid]);
                self::reply($ctx, t('wellness.bot.voice_queued', ['id' => $res['job_id']]));
                return true;
            },
        ]);

        EventBus::on('bots.voice.transcription_result', static function ($payload) {
            if (!is_array($payload)) {
                return $payload;
            }
            $job = is_array($payload['job'] ?? null) ? $payload['job'] : [];
            if ((string)($job['consumer_module'] ?? '') !== self::MODULE) {
                return $payload;
            }
            $payload['handled'] = true;
            $platform = (string)($job['platform'] ?? 'telegram');
            $chatId = (string)($job['chat_id'] ?? '');
            $uid = (int)($job['user_id'] ?? 0);
            $error = trim((string)($payload['error'] ?? ''));
            $text = trim((string)($payload['text'] ?? ''));
            $cfg = BotsConfig::channel('staff', $platform === 'max' ? 'max' : 'telegram');
            $token = is_array($cfg) ? (string)($cfg['token'] ?? '') : '';
            $ctx = [
                'audience' => 'staff',
                'platform' => $platform,
                'chat_id' => $chatId,
                'token' => $token,
                'user' => ['id' => $uid],
            ];
            BotChatState::clear(BotLinksRepository::SOURCE_STAFF, $platform, $chatId);
            if ($error !== '') {
                self::reply($ctx, t('wellness.bot.voice_asr_err', ['error' => $error]));
                return $payload;
            }
            if ($uid > 0 && $text !== '') {
                Auth::runAs($uid, static function () use ($ctx, $uid, $text) {
                    self::setState($ctx, 'await_input', ['user_id' => $uid]);
                    self::processFoodInput($ctx, $uid, $text);
                });
            }
            return $payload;
        });
    }

    /** @param array<string,mixed> $ctx */
    private static function onCallback(array $ctx, string $data, int $uid): bool
    {
        if ($data === 'fit_food_entry' || $data === 'fit_food_entry_cancel' || $data === 'fit_food_entry_rcan') {
            self::showHome($ctx, $uid);
            return true;
        }
        if ($data === 'fit_food_entry_rmenu') {
            self::showRecipeMenu($ctx, $uid);
            return true;
        }
        if ($data === 'fit_food_entry_rloglist') {
            self::showRecipeLogList($ctx, $uid);
            return true;
        }
        if ($data === 'fit_food_entry_rnew') {
            self::setState($ctx, 'recipe_new_name', ['user_id' => $uid, 'ingredients' => []]);
            self::reply($ctx, t('wellness.bot.recipe_ask_name'), self::kbCancel());
            return true;
        }
        if ($data === 'fit_food_entry_redlist') {
            self::showRecipeEditList($ctx, $uid);
            return true;
        }
        if (preg_match('/^fit_food_entry_rx_(\d+)$/', $data, $m)) {
            self::startRecipeLog($ctx, $uid, (int)$m[1]);
            return true;
        }
        if (preg_match('/^fit_food_entry_red_(\d+)$/', $data, $m)) {
            self::startRecipeEdit($ctx, $uid, (int)$m[1]);
            return true;
        }
        if (preg_match('/^fit_food_entry_pick_(\d+)$/', $data, $m)) {
            self::pickCandidate($ctx, $uid, (int)$m[1]);
            return true;
        }
        if ($data === 'fit_food_entry_add_sport') {
            self::setState($ctx, 'await_sport_input', ['user_id' => $uid]);
            self::reply($ctx, t('wellness.bot.sport_hint'), self::kbCancel());
            return true;
        }
        if (preg_match('/^fit_food_entry_rfp_(\d+)$/', $data, $m)) {
            self::pickRecipeFood($ctx, $uid, (int)$m[1]);
            return true;
        }
        if ($data === 'fit_food_entry_rdone') {
            self::finishRecipeIngredients($ctx, $uid);
            return true;
        }
        return true;
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array{module_id:string,mode:string,data:array<string,mixed>,at:int} $state
     */
    private static function onText(array $ctx, array $state, int $uid, string $text): bool
    {
        $mode = (string)($state['mode'] ?? '');
        $data = is_array($state['data'] ?? null) ? $state['data'] : [];

        if ($mode === 'await_input') {
            self::processFoodInput($ctx, $uid, $text);
            return true;
        }
        if ($mode === 'await_sport_input') {
            self::processSportInput($ctx, $uid, $text);
            return true;
        }
        if ($mode === 'recipe_log_weight') {
            $rid = (int)($data['recipe_id'] ?? 0);
            $g = (float)str_replace(',', '.', $text);
            if ($rid < 1 || $g <= 0) {
                self::reply($ctx, t('wellness.bot.recipe_bad_grams'), self::kbCancel());
                return true;
            }
            self::logRecipeGrams($ctx, $uid, $rid, $g);
            return true;
        }
        if ($mode === 'recipe_new_name') {
            $name = trim($text);
            if ($name === '') {
                self::reply($ctx, t('wellness.bot.recipe_ask_name'), self::kbCancel());
                return true;
            }
            $data['name'] = $name;
            $data['portions'] = 1;
            self::setState($ctx, 'recipe_new_cooked', $data);
            self::reply($ctx, t('wellness.bot.recipe_ask_cooked'), self::kbCancel());
            return true;
        }
        if ($mode === 'recipe_new_cooked') {
            $cooked = (float)str_replace(',', '.', $text);
            if ($cooked <= 0) {
                self::reply($ctx, t('wellness.bot.recipe_ask_cooked'), self::kbCancel());
                return true;
            }
            $data['cooked_g'] = $cooked;
            $rid = (int)($data['recipe_edit_id'] ?? 0);
            if ($rid > 0) {
                FitRecipesRepository::updateMeta($rid, (string)$data['name'], 1, $cooked);
            } else {
                $rid = FitRecipesRepository::create($uid, (string)$data['name'], 1, $cooked);
                $data['recipe_edit_id'] = $rid;
            }
            if ($rid < 1) {
                self::reply($ctx, t('wellness.bot.recipe_create_fail'));
                self::showHome($ctx, $uid);
                return true;
            }
            $data['ingredients'] = is_array($data['ingredients'] ?? null) ? $data['ingredients'] : [];
            self::setState($ctx, 'recipe_await_food_query', $data);
            self::reply($ctx, t('wellness.bot.recipe_ask_ingredient'), self::kbRecipeIng());
            return true;
        }
        if ($mode === 'recipe_await_food_query') {
            $cands = FitFoodsRepository::searchByName($uid, $text, 8);
            if ($cands === []) {
                self::reply($ctx, t('wellness.bot.no_foods'), self::kbRecipeIng());
                return true;
            }
            $data['candidates'] = $cands;
            self::setState($ctx, 'recipe_await_food_pick', $data);
            $rows = [];
            foreach ($cands as $i => $f) {
                $rows[] = [[
                    'text' => mb_substr((string)$f['name'], 0, 40) . ' (' . (int)$f['ccals'] . ')',
                    'callback_data' => 'fit_food_entry_rfp_' . $i,
                ]];
            }
            $rows = array_merge($rows, self::kbRecipeIng()['inline_keyboard']);
            self::reply($ctx, t('wellness.bot.pick_food'), ['inline_keyboard' => $rows]);
            return true;
        }
        if ($mode === 'recipe_await_food_grams') {
            $g = (float)str_replace(',', '.', $text);
            $fid = (int)($data['pending_food_id'] ?? 0);
            if ($g <= 0 || $fid < 1) {
                self::reply($ctx, t('wellness.bot.recipe_bad_grams'), self::kbCancel());
                return true;
            }
            $ings = is_array($data['ingredients'] ?? null) ? $data['ingredients'] : [];
            $ings[] = ['food_id' => $fid, 'grams' => $g];
            $data['ingredients'] = $ings;
            unset($data['pending_food_id'], $data['candidates']);
            self::setState($ctx, 'recipe_await_food_query', $data);
            self::reply($ctx, t('wellness.bot.recipe_ing_added', ['n' => count($ings)]), self::kbRecipeIng());
            return true;
        }
        return false;
    }

    /** @param array<string,mixed> $ctx */
    private static function processFoodInput(array $ctx, int $uid, string $text): void
    {
        $items = self::parseMultiFoodItems($text);
        if ($items === []) {
            self::reply($ctx, t('wellness.bot.parse_hint'), self::kbHome());
            return;
        }
        $first = array_shift($items);
        self::startFoodItem(
            $ctx,
            $uid,
            (string)$first['food_query'],
            (float)$first['weight_grams'],
            $items,
            [],
            count($items) + 1
        );
    }

    /**
     * Resolve one food line; remaining $queue items are asked after this one.
     *
     * @param array<string,mixed> $ctx
     * @param list<array{food_query:string,weight_grams:float}> $queue
     * @param list<string> $results
     */
    private static function startFoodItem(
        array $ctx,
        int $uid,
        string $foodQuery,
        float $weight,
        array $queue,
        array $results,
        int $batchTotal
    ): void {
        $foodQuery = trim($foodQuery);
        if ($foodQuery === '' || $weight <= 0) {
            self::advanceFoodQueue($ctx, $uid, $queue, $results, $batchTotal);
            return;
        }
        $cands = FitFoodsRepository::searchByName($uid, $foodQuery, 8);
        if ($cands === []) {
            $results[] = t('wellness.bot.batch_skip', ['q' => $foodQuery]);
            self::advanceFoodQueue($ctx, $uid, $queue, $results, $batchTotal);
            return;
        }
        if (count($cands) === 1) {
            self::logFoodAndContinue(
                $ctx,
                $uid,
                (int)$cands[0]['id'],
                $weight,
                $queue,
                $results,
                $batchTotal
            );
            return;
        }
        self::setState($ctx, 'await_choice', [
            'user_id' => $uid,
            'weight' => $weight,
            'food_query' => $foodQuery,
            'candidates' => $cands,
            'queue' => array_values($queue),
            'results' => array_values($results),
            'batch_total' => max(1, $batchTotal),
        ]);
        $rows = [];
        foreach ($cands as $i => $f) {
            $rows[] = [[
                'text' => mb_substr((string)$f['name'], 0, 40) . ' · ' . (int)$f['ccals'] . ' kcal',
                'callback_data' => 'fit_food_entry_pick_' . $i,
            ]];
        }
        $rows[] = [['text' => t('wellness.bot.cancel'), 'callback_data' => 'fit_food_entry_cancel']];
        $prefix = $batchTotal > 1
            ? t('wellness.bot.batch_progress', [
                'n' => (string)(count($results) + 1),
                'total' => (string)$batchTotal,
            ]) . "\n"
            : '';
        self::reply($ctx, $prefix . t('wellness.bot.pick_food_weight', [
            'q' => $foodQuery,
            'g' => (string)$weight,
        ]), ['inline_keyboard' => $rows]);
    }

    /**
     * @param array<string,mixed> $ctx
     * @param list<array{food_query:string,weight_grams:float}> $queue
     * @param list<string> $results
     */
    private static function advanceFoodQueue(
        array $ctx,
        int $uid,
        array $queue,
        array $results,
        int $batchTotal
    ): void {
        if ($queue !== []) {
            $next = array_shift($queue);
            self::startFoodItem(
                $ctx,
                $uid,
                (string)($next['food_query'] ?? ''),
                (float)($next['weight_grams'] ?? 0),
                $queue,
                $results,
                $batchTotal
            );
            return;
        }
        self::finishFoodBatch($ctx, $uid, $results);
    }

    /**
     * @param array<string,mixed> $ctx
     * @param list<string> $results
     */
    private static function finishFoodBatch(array $ctx, int $uid, array $results): void
    {
        self::setState($ctx, 'await_input', ['user_id' => $uid]);
        $body = $results !== []
            ? implode("\n", $results)
            : t('wellness.bot.batch_empty');
        self::reply($ctx, $body . "\n\n" . self::statusText($uid), self::kbHome());
    }

    /** @param array<string,mixed> $ctx */
    private static function pickCandidate(array $ctx, int $uid, int $idx): void
    {
        $state = self::state($ctx);
        $data = is_array($state['data'] ?? null) ? $state['data'] : [];
        $cands = is_array($data['candidates'] ?? null) ? $data['candidates'] : [];
        $weight = (float)($data['weight'] ?? 0);
        $queue = is_array($data['queue'] ?? null) ? $data['queue'] : [];
        $results = is_array($data['results'] ?? null) ? $data['results'] : [];
        $batchTotal = max(1, (int)($data['batch_total'] ?? 1));
        if (!isset($cands[$idx]) || $weight <= 0) {
            self::showHome($ctx, $uid);
            return;
        }
        self::logFoodAndContinue(
            $ctx,
            $uid,
            (int)$cands[$idx]['id'],
            $weight,
            $queue,
            $results,
            $batchTotal
        );
    }

    /**
     * @param array<string,mixed> $ctx
     * @param list<array{food_query:string,weight_grams:float}> $queue
     * @param list<string> $results
     */
    private static function logFoodAndContinue(
        array $ctx,
        int $uid,
        int $foodId,
        float $weight,
        array $queue,
        array $results,
        int $batchTotal
    ): void {
        $food = FitFoodsRepository::findOwned($foodId);
        if ($food === null) {
            $results[] = t('wellness.bot.no_foods');
            self::advanceFoodQueue($ctx, $uid, $queue, $results, $batchTotal);
            return;
        }
        try {
            $data = FitFoodlogsRepository::dataFromPost([
                'food_id' => $foodId,
                'weight' => $weight,
                'dt' => time(),
            ]);
            FitFoodlogsRepository::create($data);
        } catch (Throwable $e) {
            error_log('[wellness.bot.logFood] ' . $e->getMessage());
            self::reply($ctx, self::saveFailText($e), self::kbHome());
            return;
        }
        $results[] = t('wellness.bot.logged', [
            'name' => (string)$food['name'],
            'g' => (string)$weight,
            'kcal' => (string)$data['ccals'],
        ]);
        self::advanceFoodQueue($ctx, $uid, $queue, $results, $batchTotal);
    }

    /** @param array<string,mixed> $ctx */
    private static function logFood(array $ctx, int $uid, int $foodId, float $weight): void
    {
        self::logFoodAndContinue($ctx, $uid, $foodId, $weight, [], [], 1);
    }

    /** @param array<string,mixed> $ctx */
    private static function processSportInput(array $ctx, int $uid, string $text): void
    {
        // "бег 30 200" or "йога 45"
        if (!preg_match('/^(.+?)\s+(\d+(?:[.,]\d+)?)(?:\s+(\d+(?:[.,]\d+)?))?$/u', trim($text), $m)) {
            self::reply($ctx, t('wellness.bot.sport_hint'), self::kbCancel());
            return;
        }
        $name = trim($m[1]);
        $mins = (float)str_replace(',', '.', $m[2]);
        $kcal = isset($m[3]) ? (int)round((float)str_replace(',', '.', $m[3])) : 0;
        $start = time();
        $end = $start + (int)round($mins * 60);
        try {
            FitWorkoutsRepository::create([
                'name' => mb_substr($name, 0, 255),
                'dt_start' => $start,
                'dt_end' => $end,
                'ccals' => max(0, $kcal),
            ]);
        } catch (Throwable $e) {
            error_log('[wellness.bot.logSport] ' . $e->getMessage());
            self::reply($ctx, self::saveFailText($e), self::kbHome());
            return;
        }
        self::showHome($ctx, $uid);
    }

    /** @param array<string,mixed> $ctx */
    private static function showHome(array $ctx, int $uid): void
    {
        self::setState($ctx, 'await_input', ['user_id' => $uid]);
        self::replyHtml($ctx, self::statusText($uid), self::kbHome());
    }

    /** @param array<string,mixed> $ctx */
    private static function showRecipeMenu(array $ctx, int $uid): void
    {
        self::setState($ctx, 'recipe_menu', ['user_id' => $uid]);
        self::reply($ctx, t('wellness.bot.recipe_menu'), [
            'inline_keyboard' => [
                [['text' => t('wellness.bot.recipe_log'), 'callback_data' => 'fit_food_entry_rloglist']],
                [['text' => t('wellness.bot.recipe_new'), 'callback_data' => 'fit_food_entry_rnew']],
                [['text' => t('wellness.bot.recipe_edit'), 'callback_data' => 'fit_food_entry_redlist']],
                [['text' => t('wellness.bot.back_food'), 'callback_data' => 'fit_food_entry']],
            ],
        ]);
    }

    /** @param array<string,mixed> $ctx */
    private static function showRecipeLogList(array $ctx, int $uid): void
    {
        $super = Auth::capability('SUPERADMIN_ACCESS');
        $rows = FitRecipesRepository::listForUser($uid, 20, $super);
        if ($rows === []) {
            self::reply($ctx, t('wellness.bot.recipe_empty'), [
                'inline_keyboard' => [
                    [['text' => t('wellness.bot.recipe_new'), 'callback_data' => 'fit_food_entry_rnew']],
                    [['text' => t('wellness.bot.back'), 'callback_data' => 'fit_food_entry_rmenu']],
                ],
            ]);
            return;
        }
        $btns = [];
        foreach ($rows as $r) {
            $btns[] = [[
                'text' => mb_substr((string)$r['name'], 0, 40),
                'callback_data' => 'fit_food_entry_rx_' . (int)$r['id'],
            ]];
        }
        $btns[] = [['text' => t('wellness.bot.back'), 'callback_data' => 'fit_food_entry_rmenu']];
        self::reply($ctx, t('wellness.bot.recipe_pick_log'), ['inline_keyboard' => $btns]);
    }

    /** @param array<string,mixed> $ctx */
    private static function showRecipeEditList(array $ctx, int $uid): void
    {
        $super = Auth::capability('SUPERADMIN_ACCESS');
        $rows = FitRecipesRepository::listForUser($uid, 20, $super);
        if ($rows === []) {
            self::reply($ctx, t('wellness.bot.recipe_empty'), [
                'inline_keyboard' => [[['text' => t('wellness.bot.back'), 'callback_data' => 'fit_food_entry_rmenu']]],
            ]);
            return;
        }
        $btns = [];
        foreach ($rows as $r) {
            $btns[] = [[
                'text' => '✏️ ' . mb_substr((string)$r['name'], 0, 38),
                'callback_data' => 'fit_food_entry_red_' . (int)$r['id'],
            ]];
        }
        $btns[] = [['text' => t('wellness.bot.back'), 'callback_data' => 'fit_food_entry_rmenu']];
        self::reply($ctx, t('wellness.bot.recipe_pick_edit'), ['inline_keyboard' => $btns]);
    }

    /** @param array<string,mixed> $ctx */
    private static function startRecipeLog(array $ctx, int $uid, int $rid): void
    {
        $row = FitRecipesRepository::find($rid);
        $super = Auth::capability('SUPERADMIN_ACCESS');
        if (!FitRecipesRepository::canAccess($row, $uid, $super)) {
            self::reply($ctx, t('wellness.bot.recipe_denied'), self::kbCancel());
            return;
        }
        $ens = FitRecipeSync::ensureFoodForDiary($rid);
        if (empty($ens['ok'])) {
            self::reply($ctx, t('wellness.bot.recipe_not_ready'), [
                'inline_keyboard' => [[['text' => t('wellness.bot.back'), 'callback_data' => 'fit_food_entry_rloglist']]],
            ]);
            return;
        }
        self::setState($ctx, 'recipe_log_weight', [
            'user_id' => $uid,
            'recipe_id' => $rid,
            'recipe_name' => (string)($row['name'] ?? ''),
        ]);
        self::reply($ctx, t('wellness.bot.recipe_ask_grams', ['name' => (string)($row['name'] ?? '')]), self::kbCancel());
    }

    /** @param array<string,mixed> $ctx */
    private static function startRecipeEdit(array $ctx, int $uid, int $rid): void
    {
        $row = FitRecipesRepository::find($rid);
        $super = Auth::capability('SUPERADMIN_ACCESS');
        if (!FitRecipesRepository::canAccess($row, $uid, $super) || $row === null) {
            self::reply($ctx, t('wellness.bot.recipe_denied'), self::kbCancel());
            return;
        }
        $items = FitRecipesRepository::loadItems($rid);
        $ings = [];
        foreach ($items as $it) {
            $ings[] = ['food_id' => (int)$it['food_id'], 'grams' => (float)$it['grams']];
        }
        self::setState($ctx, 'recipe_await_food_query', [
            'user_id' => $uid,
            'name' => (string)$row['name'],
            'cooked_g' => (float)($row['weight_cooked_g'] ?? 0),
            'recipe_edit_id' => $rid,
            'ingredients' => $ings,
        ]);
        self::reply($ctx, t('wellness.bot.recipe_edit_ings', ['n' => count($ings)]), self::kbRecipeIng());
    }

    /** @param array<string,mixed> $ctx */
    private static function logRecipeGrams(array $ctx, int $uid, int $rid, float $grams): void
    {
        $ens = FitRecipeSync::ensureFoodForDiary($rid);
        if (empty($ens['ok']) || empty($ens['food_row'])) {
            self::reply($ctx, t('wellness.bot.recipe_not_ready'));
            self::showHome($ctx, $uid);
            return;
        }
        $foodId = (int)$ens['food_id'];
        try {
            $data = FitFoodlogsRepository::dataFromPost([
                'food_id' => $foodId,
                'weight' => $grams,
                'dt' => time(),
            ]);
            FitFoodlogsRepository::create($data);
        } catch (Throwable $e) {
            error_log('[wellness.bot.logRecipe] ' . $e->getMessage());
            self::reply($ctx, self::saveFailText($e), self::kbHome());
            return;
        }
        $name = (string)($ens['food_row']['name'] ?? '');
        self::setState($ctx, 'await_input', ['user_id' => $uid]);
        self::reply($ctx, t('wellness.bot.logged', [
            'name' => $name,
            'g' => (string)$grams,
            'kcal' => (string)$data['ccals'],
        ]) . "\n\n" . self::statusText($uid), self::kbHome());
    }

    /** @param array<string,mixed> $ctx */
    private static function pickRecipeFood(array $ctx, int $uid, int $idx): void
    {
        $state = self::state($ctx);
        $data = is_array($state['data'] ?? null) ? $state['data'] : [];
        $cands = is_array($data['candidates'] ?? null) ? $data['candidates'] : [];
        if (!isset($cands[$idx])) {
            self::reply($ctx, t('wellness.bot.no_foods'), self::kbRecipeIng());
            return;
        }
        $data['pending_food_id'] = (int)$cands[$idx]['id'];
        self::setState($ctx, 'recipe_await_food_grams', $data);
        self::reply($ctx, t('wellness.bot.recipe_ask_ing_grams', [
            'name' => (string)$cands[$idx]['name'],
        ]), self::kbCancel());
    }

    /** @param array<string,mixed> $ctx */
    private static function finishRecipeIngredients(array $ctx, int $uid): void
    {
        $state = self::state($ctx);
        $data = is_array($state['data'] ?? null) ? $state['data'] : [];
        $rid = (int)($data['recipe_edit_id'] ?? 0);
        $ings = is_array($data['ingredients'] ?? null) ? $data['ingredients'] : [];
        if ($rid < 1) {
            $rid = FitRecipesRepository::create(
                $uid,
                (string)($data['name'] ?? 'Recipe'),
                1,
                isset($data['cooked_g']) ? (float)$data['cooked_g'] : null
            );
        }
        if ($rid < 1) {
            self::reply($ctx, t('wellness.bot.recipe_create_fail'));
            self::showHome($ctx, $uid);
            return;
        }
        FitRecipesRepository::replaceItems($rid, $ings);
        $sync = FitRecipeSync::syncFromItems($rid);
        self::showRecipeMenu($ctx, $uid);
        if (!empty($sync['ok'])) {
            self::reply($ctx, t('wellness.bot.recipe_saved', ['id' => $rid]));
        } else {
            self::reply($ctx, t('wellness.bot.recipe_saved_partial', ['id' => $rid]));
        }
    }

    private static function statusText(int $uid): string
    {
        $ymd = date('Y-m-d');
        [$from, $to] = WellnessOwner::dayRange($ymd);
        $list = FitFoodlogsRepository::list(['from' => $from, 'to' => $to, 'limit' => 40, 'order' => 'l.DT ASC, l.ID ASC']);
        $lines = ['<b>' . t('wellness.bot.title') . '</b>', ''];
        foreach ($list['items'] as $row) {
            $lines[] = '• ' . date('H:i', (int)$row['dt']) . ' — ' . e((string)$row['food_name'])
                . ' ' . (float)$row['weight'] . 'g (' . (int)$row['ccals'] . ')';
        }
        $t = $list['totals'];
        $lines[] = '';
        $lines[] = '<b>' . t('wellness.bot.totals', [
            'c' => (string)$t['ccals'],
            'p' => (string)$t['proteins'],
            'f' => (string)$t['fats'],
            'u' => (string)$t['carbs'],
        ]) . '</b>';
        $sports = FitWorkoutsRepository::list(['from' => $from, 'to' => $to, 'limit' => 10]);
        $lines[] = '';
        $lines[] = t('wellness.bot.sport_today');
        if (($sports['items'] ?? []) === []) {
            $lines[] = t('wellness.bot.sport_empty');
        } else {
            foreach ($sports['items'] as $s) {
                $lines[] = '✅ ' . date('H:i', (int)$s['dt_start']) . ' — ' . e((string)$s['name'])
                    . ' (' . (int)$s['ccals'] . ')';
            }
        }
        $lines[] = '';
        $lines[] = t('wellness.bot.input_hint');
        return implode("\n", $lines);
    }

    /**
     * Split "гречка 130г, молоко 3.2 116" into separate food/weight pairs (oper parse_multi_items).
     *
     * @return list<array{food_query:string,weight_grams:float}>
     */
    private static function parseMultiFoodItems(string $text): array
    {
        $src = trim($text);
        if ($src === '') {
            return [];
        }
        $parts = preg_split('/\s*(?:,|;|\s+и\s+)\s*/iu', $src, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || $parts === []) {
            $parts = [$src];
        }
        $items = [];
        foreach ($parts as $partRaw) {
            $one = self::parseFoodText((string)$partRaw);
            if ($one !== null) {
                $items[] = $one;
            }
        }
        return $items;
    }

    /** @return array{food_query:string,weight_grams:float}|null */
    private static function parseFoodText(string $text): ?array
    {
        $part = trim($text);
        if ($part === '') {
            return null;
        }
        $part = preg_replace('/^(добав(ь|ить|ьте)\s+|впиши(те)?\s+|занеси(те)?\s+)/iu', '', $part);
        $part = preg_replace('/^и\s+/iu', '', (string)$part);
        $part = trim((string)$part, " \t\n\r\0\x0B,.;:-");
        if ($part === '') {
            return null;
        }

        $name = '';
        $weight = 0.0;
        if (preg_match('/^(.+?)\s+(\d+(?:[.,]\d+)?)\s*(?:грамм(?:а|ов)?|гр|г)\b/iu', $part, $m1)) {
            $name = trim((string)$m1[1]);
            $weight = (float)str_replace(',', '.', (string)$m1[2]);
        } elseif (preg_match('/^(\d+(?:[.,]\d+)?)\s*(?:грамм(?:а|ов)?|гр|г)\s+(.+)$/iu', $part, $m2)) {
            $weight = (float)str_replace(',', '.', (string)$m2[1]);
            $name = trim((string)$m2[2]);
        } elseif (preg_match('/^(\d+(?:[.,]\d+)?)\s+(.+)$/iu', $part, $m4)
            && !preg_match('/\d/u', (string)$m4[2])) {
            // "150 картошка" — вес первым, в названии нет цифр
            $weight = (float)str_replace(',', '.', (string)$m4[1]);
            $name = trim((string)$m4[2]);
        } elseif (preg_match('/^(.+?)\s+(\d+(?:[.,]\d+)?)$/iu', $part, $m3)) {
            // "сырники 5% 161" / "молоко 3.2 116" — вес в конце
            $name = trim((string)$m3[1]);
            $weight = (float)str_replace(',', '.', (string)$m3[2]);
        }
        $name = trim($name, " \t\n\r\0\x0B,.;:-");
        if ($name === '' || $weight <= 0) {
            return null;
        }
        if (preg_match('/^(добав(ь|ить|ьте)?|впиши(те)?|занеси(те)?)$/iu', $name)) {
            return null;
        }
        return ['food_query' => $name, 'weight_grams' => $weight];
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

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $data
     */
    private static function setState(array $ctx, string $mode, array $data): void
    {
        if (!class_exists('BotChatState', false)) {
            return;
        }
        BotChatState::set(
            BotLinksRepository::SOURCE_STAFF,
            (string)($ctx['platform'] ?? 'telegram'),
            (string)($ctx['chat_id'] ?? ''),
            self::MODULE,
            $mode,
            $data
        );
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    private static function kbHome(): array
    {
        return ['inline_keyboard' => [
            [['text' => t('wellness.bot.recipe_menu_btn'), 'callback_data' => 'fit_food_entry_rmenu']],
            [['text' => t('wellness.bot.add_sport'), 'callback_data' => 'fit_food_entry_add_sport']],
            [['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet']],
        ]];
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    private static function kbCancel(): array
    {
        return ['inline_keyboard' => [
            [['text' => t('wellness.bot.cancel'), 'callback_data' => 'fit_food_entry_cancel']],
        ]];
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    private static function kbRecipeIng(): array
    {
        return ['inline_keyboard' => [
            [['text' => t('wellness.bot.recipe_done_ings'), 'callback_data' => 'fit_food_entry_rdone']],
            [['text' => t('wellness.bot.cancel'), 'callback_data' => 'fit_food_entry_cancel']],
        ]];
    }

    private static function saveFailText(Throwable $e): string
    {
        $msg = trim($e->getMessage());
        if ($msg === '') {
            $msg = get_class($e);
        }
        if (function_exists('mb_strlen') && mb_strlen($msg) > 1500) {
            $msg = mb_substr($msg, 0, 1500) . '…';
        } elseif (strlen($msg) > 1500) {
            $msg = substr($msg, 0, 1500) . '…';
        }
        return t('wellness.bot.save_fail', ['error' => $msg]);
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed>|null $markup
     */
    private static function reply(array $ctx, string $text, ?array $markup = null): void
    {
        if ((string)($ctx['token'] ?? '') === '' || (string)($ctx['chat_id'] ?? '') === '') {
            return;
        }
        BotClient::replyUi($ctx, $text, $markup);
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed>|null $markup
     */
    private static function replyHtml(array $ctx, string $text, ?array $markup = null): void
    {
        if ((string)($ctx['token'] ?? '') === '' || (string)($ctx['chat_id'] ?? '') === '') {
            return;
        }
        BotClient::replyUi($ctx, $text, $markup, 'HTML');
    }
}
