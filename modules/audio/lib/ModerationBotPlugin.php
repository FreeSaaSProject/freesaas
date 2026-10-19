<?php
declare(strict_types=1);

/**
 * Staff bot: LLM skim of pending-review day + confirm / walk transcripts with key hits.
 * Catalog id `moderation` (BotMenuCatalog).
 * ACL (temporary): SUPERADMIN_ACCESS only — widen later to audio.edit + position scope.
 *
 * Session (BotChatState) caches the last day summary so «back» does not re-run LLM.
 */
final class ModerationBotPlugin
{
    /** Must match BotPlugins::register id — clearForeignChatState keeps only matching owners. */
    private const MODULE = 'moderation';
    private const MODULE_LEGACY = 'audio_moderation';
    private const DAYS_BACK = 21;
    private const LIST_LIMIT = 20;
    private const TTL = 7200;
    private const MENTION_BTN_LIMIT = 8;

    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }

        BotPlugins::register('moderation', [
            'priority' => 24,
            'audiences' => ['staff'],
            'label' => 'bots.catalog.staff.moderation',
            'module' => 'audio',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return [];
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1) {
                    return [];
                }
                return Auth::runAs($uid, static function () {
                    if (!self::canModerate()) {
                        return [];
                    }
                    $n = self::scopedPendingTotal();
                    $label = t('bots.catalog.staff.moderation');
                    if ($n > 0) {
                        $label .= ' (' . $n . ')';
                    }
                    return [[[
                        'text' => $label,
                        'callback_data' => 'moderation',
                    ]]];
                });
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return null;
                }
                $data = (string)($ctx['callback_data'] ?? '');
                if ($data === '' || (strpos($data, 'moderation') !== 0 && strpos($data, 'mod_') !== 0)) {
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
        ]);
    }

    /** @param array<string,mixed> $ctx */
    private static function onCallback(array $ctx, string $data, int $uid): bool
    {
        if (!self::canModerate()) {
            BotClient::replyUi($ctx, t('audio.bot.mod.denied'), self::kbMenu());
            return true;
        }

        if ($data === 'moderation' || $data === 'mod_list') {
            self::showQueue($ctx);
            return true;
        }

        // mod_d_{admin}_{Ymd} — open day (use cache if present, else LLM)
        if (preg_match('/^mod_d_(\d+)_(\d{8})$/', $data, $m)) {
            $adminId = (int)$m[1];
            $ymd = (string)$m[2];
            if (!self::canAccessAdmin($adminId)) {
                BotClient::replyUi($ctx, t('audio.bot.mod.denied'), self::kbMenu());
                return true;
            }
            self::showDaySummary($ctx, $adminId, $ymd, false);
            return true;
        }

        // mod_sum_{admin}_{Ymd} — cached summary only (no LLM)
        if (preg_match('/^mod_sum_(\d+)_(\d{8})$/', $data, $m)) {
            $adminId = (int)$m[1];
            $ymd = (string)$m[2];
            if (!self::canAccessAdmin($adminId)) {
                BotClient::replyUi($ctx, t('audio.bot.mod.denied'), self::kbMenu());
                return true;
            }
            self::showCachedSummary($ctx, $adminId, $ymd);
            return true;
        }

        // mod_rerun_{admin}_{Ymd} — force fresh LLM
        if (preg_match('/^mod_rerun_(\d+)_(\d{8})$/', $data, $m)) {
            $adminId = (int)$m[1];
            $ymd = (string)$m[2];
            if (!self::canAccessAdmin($adminId)) {
                BotClient::replyUi($ctx, t('audio.bot.mod.denied'), self::kbMenu());
                return true;
            }
            self::showDaySummary($ctx, $adminId, $ymd, true);
            return true;
        }

        // mod_ok_{admin}_{Ymd} — mark all pending reviewed
        if (preg_match('/^mod_ok_(\d+)_(\d{8})$/', $data, $m)) {
            $adminId = (int)$m[1];
            $ymd = (string)$m[2];
            if (!self::canAccessAdmin($adminId)) {
                BotClient::replyUi($ctx, t('audio.bot.mod.denied'), self::kbMenu());
                return true;
            }
            self::confirmDay($ctx, $uid, $adminId, $ymd);
            return true;
        }

        // mod_r_{admin}_{Ymd}_{recordId} — open one highlighted record
        if (preg_match('/^mod_r_(\d+)_(\d{8})_(\d+)$/', $data, $m)) {
            $adminId = (int)$m[1];
            $ymd = (string)$m[2];
            $recordId = (int)$m[3];
            if (!self::canAccessAdmin($adminId)) {
                BotClient::replyUi($ctx, t('audio.bot.mod.denied'), self::kbMenu());
                return true;
            }
            self::showRecordById($ctx, $adminId, $ymd, $recordId);
            return true;
        }

        // mod_v_{admin}_{Ymd}_{idx} — walk all pending
        if (preg_match('/^mod_v_(\d+)_(\d{8})_(\d+)$/', $data, $m)) {
            $adminId = (int)$m[1];
            $ymd = (string)$m[2];
            $idx = (int)$m[3];
            if (!self::canAccessAdmin($adminId)) {
                BotClient::replyUi($ctx, t('audio.bot.mod.denied'), self::kbMenu());
                return true;
            }
            self::showPendingAt($ctx, $adminId, $ymd, $idx);
            return true;
        }

        return false;
    }

    private static function canModerate(): bool
    {
        return Auth::capability('SUPERADMIN_ACCESS') && RecordsReviewsRepository::tableExists();
    }

    /** @return list<int>|null null = no restriction (superadmin sees all) */
    private static function scopePositions(): ?array
    {
        return null;
    }

    private static function canAccessAdmin(int $adminId): bool
    {
        return $adminId > 0 && self::canModerate();
    }

    private static function scopedPendingTotal(): int
    {
        $from = time() - self::DAYS_BACK * 86400;
        $cells = RecordsReviewsRepository::pendingCells($from, time(), self::scopePositions());
        $n = 0;
        foreach ($cells as $c) {
            $n += (int)$c['pending'];
        }
        return $n;
    }

    /** @param array<string,mixed> $ctx */
    private static function showQueue(array $ctx): void
    {
        $from = time() - self::DAYS_BACK * 86400;
        $cells = RecordsReviewsRepository::pendingCells($from, time(), self::scopePositions());
        if ($cells === []) {
            BotClient::replyUi($ctx, t('audio.bot.mod.empty'), self::kbMenu());
            return;
        }
        $cells = array_slice($cells, 0, self::LIST_LIMIT);
        $lines = [t('audio.bot.mod.queue_title')];
        $rows = [];
        foreach ($cells as $c) {
            $aid = (int)$c['admin_id'];
            $name = class_exists('UsersRepository', false)
                ? UsersRepository::nameById($aid)
                : ('#' . $aid);
            if ($name === '') {
                $name = '#' . $aid;
            }
            $ymd = sprintf('%04d%02d%02d', (int)$c['year'], (int)$c['month'], (int)$c['day']);
            $label = sprintf(
                '%s · %s (%d)',
                date('d.m', mktime(0, 0, 0, (int)$c['month'], (int)$c['day'], (int)$c['year'])),
                self::nameAcronym($name),
                (int)$c['pending']
            );
            $cb = 'mod_d_' . $aid . '_' . $ymd;
            if (strlen($cb) > 64) {
                continue;
            }
            $rows[] = [['text' => $label, 'callback_data' => $cb]];
        }
        $rows[] = [['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet']];
        BotClient::replyUi($ctx, implode("\n", $lines), ['inline_keyboard' => $rows]);
    }

    /** @param array<string,mixed> $ctx */
    private static function showDaySummary(array $ctx, int $adminId, string $ymd, bool $force): void
    {
        if (!$force) {
            $cached = self::cachedSession($ctx, $adminId, $ymd);
            if ($cached !== null) {
                self::renderSummary($ctx, $cached);
                return;
            }
        }

        [$y, $m, $d] = self::parseYmd($ymd);
        if ($y < 1) {
            BotClient::replyUi($ctx, t('audio.bot.mod.bad_day'), self::kbList());
            return;
        }
        $ids = RecordsReviewsRepository::pendingIdsForAdminDay($adminId, $y, $m, $d);
        if ($ids === []) {
            BotClient::replyUi($ctx, t('audio.bot.mod.day_empty'), self::kbList());
            return;
        }

        $name = class_exists('UsersRepository', false)
            ? UsersRepository::nameById($adminId)
            : ('#' . $adminId);
        if ($name === '') {
            $name = '#' . $adminId;
        }
        $acro = self::nameAcronym($name);
        $dateLabel = sprintf('%02d.%02d.%04d', $d, $m, $y);
        $header = t('audio.bot.mod.day_header', [
            'name' => $acro,
            'date' => $dateLabel,
            'n' => count($ids),
        ]);

        BotClient::replyUi($ctx, t('audio.bot.mod.working', [
            'name' => $acro,
            'date' => $dateLabel,
            'n' => count($ids),
        ]));

        $body = '';
        if (class_exists('ReviewModerationLlm', false) && ReviewModerationLlm::isReady()) {
            $res = ReviewModerationLlm::run($adminId, $y, $m, $d);
            if (!empty($res['ok']) && trim((string)$res['text']) !== '') {
                $body = trim((string)$res['text']);
            } else {
                $body = t('audio.bot.mod.llm_fail', [
                    'err' => (string)($res['error'] ?? ''),
                ]);
            }
        } else {
            $body = t('audio.bot.mod.llm_unavailable');
        }

        $mentions = self::extractMentionIds($body, $ids);
        $session = [
            'admin_id' => $adminId,
            'ymd' => $ymd,
            'header' => $header,
            'body' => $body,
            'pending_ids' => $ids,
            'mention_ids' => $mentions,
        ];
        self::setSession($ctx, $session);
        self::renderSummary($ctx, $session);
    }

    /** @param array<string,mixed> $ctx */
    private static function showCachedSummary(array $ctx, int $adminId, string $ymd): void
    {
        $cached = self::cachedSession($ctx, $adminId, $ymd);
        if ($cached !== null) {
            self::renderSummary($ctx, $cached);
            return;
        }
        // TTL / legacy module_id clear — rebuild instead of a dead-end «cache miss».
        self::showDaySummary($ctx, $adminId, $ymd, false);
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array{
     *   admin_id:int,ymd:string,header:string,body:string,
     *   pending_ids:list<int>,mention_ids:list<int>
     * } $session
     */
    private static function renderSummary(array $ctx, array $session): void
    {
        $adminId = (int)$session['admin_id'];
        $ymd = (string)$session['ymd'];
        $text = trim((string)$session['header'] . "\n\n" . (string)$session['body']);
        $pending = is_array($session['pending_ids'] ?? null) ? $session['pending_ids'] : [];
        $mentions = is_array($session['mention_ids'] ?? null) ? $session['mention_ids'] : [];
        BotClient::replyUi(
            $ctx,
            $text,
            self::kbSummary($adminId, $ymd, $pending !== [], $mentions)
        );
    }

    /** @param array<string,mixed> $ctx */
    private static function confirmDay(array $ctx, int $uid, int $adminId, string $ymd): void
    {
        [$y, $m, $d] = self::parseYmd($ymd);
        if ($y < 1) {
            BotClient::replyUi($ctx, t('audio.bot.mod.bad_day'), self::kbList());
            return;
        }
        $ids = RecordsReviewsRepository::pendingIdsForAdminDay($adminId, $y, $m, $d);
        if ($ids === []) {
            self::clearSession($ctx);
            BotClient::replyUi($ctx, t('audio.bot.mod.day_empty'), self::kbList());
            return;
        }
        $done = RecordsReviewsRepository::markReviewed($ids, $uid);
        self::clearSession($ctx);
        BotClient::replyUi($ctx, t('audio.bot.mod.confirmed', ['n' => $done]), self::kbList());
    }

    /** @param array<string,mixed> $ctx */
    private static function showRecordById(array $ctx, int $adminId, string $ymd, int $recordId): void
    {
        $session = self::cachedSession($ctx, $adminId, $ymd);
        $mentions = $session !== null && is_array($session['mention_ids'] ?? null)
            ? array_values(array_map('intval', $session['mention_ids']))
            : [];
        $pending = $session !== null && is_array($session['pending_ids'] ?? null)
            ? array_values(array_map('intval', $session['pending_ids']))
            : [];

        if ($recordId < 1) {
            BotClient::replyUi($ctx, t('audio.bot.mod.no_transcript'), self::kbBackSummary($adminId, $ymd));
            return;
        }

        $snippet = self::telegramSnippet($recordId);
        $pos = array_search($recordId, $mentions, true);
        $meta = $pos !== false
            ? t('audio.bot.mod.record_meta', [
                'i' => $pos + 1,
                'total' => count($mentions),
                'id' => $recordId,
            ])
            : t('audio.bot.mod.record_one', ['id' => $recordId]);
        $text = $meta . "\n\n" . ($snippet !== '' ? $snippet : t('audio.bot.mod.no_transcript'));

        $rows = [];
        if ($pos !== false && $mentions !== []) {
            $nav = [];
            if ($pos > 0) {
                $prevId = (int)$mentions[$pos - 1];
                $nav[] = [
                    'text' => t('audio.bot.mod.prev'),
                    'callback_data' => 'mod_r_' . $adminId . '_' . $ymd . '_' . $prevId,
                ];
            }
            if ($pos + 1 < count($mentions)) {
                $nextId = (int)$mentions[$pos + 1];
                $nav[] = [
                    'text' => t('audio.bot.mod.next'),
                    'callback_data' => 'mod_r_' . $adminId . '_' . $ymd . '_' . $nextId,
                ];
            }
            if ($nav !== []) {
                $rows[] = $nav;
            }
        }

        $rows[] = [[
            'text' => t('audio.bot.mod.back_summary'),
            'callback_data' => 'mod_sum_' . $adminId . '_' . $ymd,
        ]];
        if ($pending !== []) {
            $rows[] = [[
                'text' => t('audio.bot.mod.confirm'),
                'callback_data' => 'mod_ok_' . $adminId . '_' . $ymd,
            ]];
        }
        $rows[] = [['text' => t('audio.bot.mod.back_list'), 'callback_data' => 'mod_list']];

        BotClient::replyUi($ctx, $text, ['inline_keyboard' => $rows], 'HTML');
    }

    /** @param array<string,mixed> $ctx */
    private static function showPendingAt(array $ctx, int $adminId, string $ymd, int $idx): void
    {
        $session = self::cachedSession($ctx, $adminId, $ymd);
        $ids = $session !== null && is_array($session['pending_ids'] ?? null)
            ? array_values(array_map('intval', $session['pending_ids']))
            : [];
        if ($ids === []) {
            [$y, $m, $d] = self::parseYmd($ymd);
            $ids = RecordsReviewsRepository::pendingIdsForAdminDay($adminId, $y, $m, $d);
        }
        if ($ids === []) {
            BotClient::replyUi($ctx, t('audio.bot.mod.day_empty'), self::kbList());
            return;
        }
        if ($idx < 0 || $idx >= count($ids)) {
            $idx = 0;
        }
        $rid = (int)$ids[$idx];
        $snippet = self::telegramSnippet($rid);
        $meta = t('audio.bot.mod.record_meta', [
            'i' => $idx + 1,
            'total' => count($ids),
            'id' => $rid,
        ]);
        $text = $meta . "\n\n" . ($snippet !== '' ? $snippet : t('audio.bot.mod.no_transcript'));

        $rows = [];
        $nav = [];
        if ($idx > 0) {
            $nav[] = [
                'text' => t('audio.bot.mod.prev'),
                'callback_data' => 'mod_v_' . $adminId . '_' . $ymd . '_' . ($idx - 1),
            ];
        }
        if ($idx + 1 < count($ids)) {
            $nav[] = [
                'text' => t('audio.bot.mod.next'),
                'callback_data' => 'mod_v_' . $adminId . '_' . $ymd . '_' . ($idx + 1),
            ];
        }
        if ($nav !== []) {
            $rows[] = $nav;
        }
        $rows[] = [[
            'text' => t('audio.bot.mod.back_summary'),
            'callback_data' => 'mod_sum_' . $adminId . '_' . $ymd,
        ]];
        $rows[] = [[
            'text' => t('audio.bot.mod.confirm'),
            'callback_data' => 'mod_ok_' . $adminId . '_' . $ymd,
        ]];
        $rows[] = [['text' => t('audio.bot.mod.back_list'), 'callback_data' => 'mod_list']];

        BotClient::replyUi($ctx, $text, ['inline_keyboard' => $rows], 'HTML');
    }

    /**
     * Prefer IDs that appear in the LLM text; intersect with pending when possible.
     *
     * @param list<int> $pendingIds
     * @return list<int>
     */
    private static function extractMentionIds(string $body, array $pendingIds): array
    {
        $found = [];
        if (preg_match_all('/\bID\s*[#:]?\s*(\d{3,10})\b/u', $body, $m)) {
            foreach ($m[1] as $raw) {
                $id = (int)$raw;
                if ($id > 0) {
                    $found[$id] = true;
                }
            }
        }
        if (preg_match_all('/#(\d{3,10})\b/u', $body, $m2)) {
            foreach ($m2[1] as $raw) {
                $id = (int)$raw;
                if ($id > 0) {
                    $found[$id] = true;
                }
            }
        }
        $pendingSet = [];
        foreach ($pendingIds as $pid) {
            $pendingSet[(int)$pid] = true;
        }
        $ordered = [];
        foreach (array_keys($found) as $id) {
            if (isset($pendingSet[$id])) {
                $ordered[] = $id;
            }
        }
        // Mentions outside pending (context) — still offer if any.
        foreach (array_keys($found) as $id) {
            if (!isset($pendingSet[$id])) {
                $ordered[] = $id;
            }
        }
        if ($ordered === []) {
            return array_slice(array_values(array_map('intval', $pendingIds)), 0, self::MENTION_BTN_LIMIT);
        }
        return array_slice($ordered, 0, self::MENTION_BTN_LIMIT);
    }

    private static function telegramSnippet(int $recordId): string
    {
        if ($recordId < 1 || !class_exists('RecordsRepository', false)) {
            return '';
        }
        $row = RecordsRepository::find($recordId);
        if ($row === null) {
            return '';
        }
        $src = (string)($row['transcription'] ?? '');
        if ($src === '' || strpos($src, '[') === false) {
            return '';
        }
        $instances = RecordsRepository::instancesForRecords([$recordId]);
        $byRec = $instances[$recordId] ?? [];
        $trash = [];
        if (Database::tableExists('base_records_trashes')) {
            $st = Database::pdo()->query("SELECT NAME FROM base_records_trashes WHERE ENABLED = '1' OR ENABLED = 1");
            if ($st) {
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                    $n = trim((string)($r['NAME'] ?? ''));
                    if ($n !== '') {
                        $trash[] = $n;
                    }
                }
            }
        }
        $rendered = TranscriptHighlighter::render($src, $byRec, $trash, '', ['compact' => true]);
        $html = (string)($rendered['html'] ?? '');
        if ($html === '') {
            return '';
        }
        $html = preg_replace('/<\/div>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<b\b[^>]*>/i', '<b>', $html) ?? $html;
        $html = strip_tags($html, '<b>');
        $html = preg_replace("/\n{3,}/", "\n\n", $html) ?? $html;
        $html = trim($html);
        $lines = preg_split("/\n/", $html) ?: [];
        $hitLines = [];
        foreach ($lines as $ln) {
            if (stripos($ln, '<b>') !== false) {
                $hitLines[] = $ln;
            }
        }
        if ($hitLines !== []) {
            $html = implode("\n", array_slice($hitLines, 0, 40));
        } else {
            $html = implode("\n", array_slice($lines, 0, 25));
        }
        if (mb_strlen($html, 'UTF-8') > 3500) {
            $html = mb_substr($html, 0, 3500, 'UTF-8') . '…';
        }
        $dt = (int)($row['dt_recorded'] ?? 0);
        $time = $dt > 0 ? date('H:i', $dt) : '';
        $head = $time !== '' ? ('<b>#' . $recordId . '</b> ' . e($time) . "\n") : ('<b>#' . $recordId . "</b>\n");
        return $head . $html;
    }

    /**
     * @param array<string,mixed> $ctx
     * @return array{
     *   admin_id:int,ymd:string,header:string,body:string,
     *   pending_ids:list<int>,mention_ids:list<int>
     * }|null
     */
    private static function cachedSession(array $ctx, int $adminId, string $ymd): ?array
    {
        $state = self::getState($ctx);
        if ($state === null || !BotChatState::isFresh($state, self::TTL)) {
            return null;
        }
        $mid = (string)($state['module_id'] ?? '');
        if (($mid !== self::MODULE && $mid !== self::MODULE_LEGACY)
            || (string)($state['mode'] ?? '') !== 'day') {
            return null;
        }
        $data = is_array($state['data'] ?? null) ? $state['data'] : [];
        if ((int)($data['admin_id'] ?? 0) !== $adminId || (string)($data['ymd'] ?? '') !== $ymd) {
            return null;
        }
        $session = [
            'admin_id' => $adminId,
            'ymd' => $ymd,
            'header' => (string)($data['header'] ?? ''),
            'body' => (string)($data['body'] ?? ''),
            'pending_ids' => is_array($data['pending_ids'] ?? null)
                ? array_values(array_map('intval', $data['pending_ids']))
                : [],
            'mention_ids' => is_array($data['mention_ids'] ?? null)
                ? array_values(array_map('intval', $data['mention_ids']))
                : [],
        ];
        // Rewrite legacy module_id before dispatch's clearForeignChatState runs.
        if ($mid === self::MODULE_LEGACY) {
            self::setSession($ctx, $session);
        }
        return $session;
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $session
     */
    private static function setSession(array $ctx, array $session): void
    {
        if (!class_exists('BotChatState', false) || !class_exists('BotLinksRepository', false)) {
            return;
        }
        BotChatState::set(
            BotLinksRepository::SOURCE_STAFF,
            (string)($ctx['platform'] ?? 'telegram'),
            (string)($ctx['chat_id'] ?? ''),
            self::MODULE,
            'day',
            $session
        );
    }

    /** @param array<string,mixed> $ctx */
    private static function clearSession(array $ctx): void
    {
        if (!class_exists('BotChatState', false) || !class_exists('BotLinksRepository', false)) {
            return;
        }
        BotChatState::clear(
            BotLinksRepository::SOURCE_STAFF,
            (string)($ctx['platform'] ?? 'telegram'),
            (string)($ctx['chat_id'] ?? '')
        );
    }

    /** @param array<string,mixed> $ctx @return array<string,mixed>|null */
    private static function getState(array $ctx): ?array
    {
        if (!class_exists('BotChatState', false) || !class_exists('BotLinksRepository', false)) {
            return null;
        }
        return BotChatState::get(
            BotLinksRepository::SOURCE_STAFF,
            (string)($ctx['platform'] ?? 'telegram'),
            (string)($ctx['chat_id'] ?? '')
        );
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private static function parseYmd(string $ymd): array
    {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $ymd, $m)) {
            return [0, 0, 0];
        }
        $y = (int)$m[1];
        $mo = (int)$m[2];
        $d = (int)$m[3];
        if ($y < 1970 || $mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
            return [0, 0, 0];
        }
        return [$y, $mo, $d];
    }

    private static function nameAcronym(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/u', $name) ?: [];
        if (count($parts) === 1) {
            $w = (string)$parts[0];
            return mb_strlen($w, 'UTF-8') > 16 ? (mb_substr($w, 0, 14, 'UTF-8') . '…') : $w;
        }
        $surname = (string)$parts[0];
        $initials = '';
        for ($i = 1, $n = count($parts); $i < $n; $i++) {
            $ch = mb_substr((string)$parts[$i], 0, 1, 'UTF-8');
            if ($ch === '') {
                continue;
            }
            $initials .= mb_strtoupper($ch, 'UTF-8') . '.';
        }
        $out = $initials !== '' ? ($surname . ' ' . $initials) : $surname;
        if (mb_strlen($out, 'UTF-8') > 22) {
            $out = mb_substr($out, 0, 20, 'UTF-8') . '…';
        }
        return $out;
    }

    /**
     * @param list<int> $mentionIds
     * @return array{inline_keyboard:list<list<array{text:string,callback_data:string}>>}
     */
    private static function kbSummary(int $adminId, string $ymd, bool $hasPending, array $mentionIds): array
    {
        $rows = [];
        if ($hasPending) {
            $rows[] = [[
                'text' => t('audio.bot.mod.confirm'),
                'callback_data' => 'mod_ok_' . $adminId . '_' . $ymd,
            ]];
        }

        $chunk = [];
        foreach ($mentionIds as $rid) {
            $rid = (int)$rid;
            if ($rid < 1) {
                continue;
            }
            $cb = 'mod_r_' . $adminId . '_' . $ymd . '_' . $rid;
            if (strlen($cb) > 64) {
                continue;
            }
            $chunk[] = [
                'text' => '#' . $rid,
                'callback_data' => $cb,
            ];
            if (count($chunk) >= 3) {
                $rows[] = $chunk;
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $rows[] = $chunk;
        }

        if ($hasPending) {
            $rows[] = [[
                'text' => t('audio.bot.mod.view_all'),
                'callback_data' => 'mod_v_' . $adminId . '_' . $ymd . '_0',
            ]];
        }
        $rows[] = [[
            'text' => t('audio.bot.mod.rerun'),
            'callback_data' => 'mod_rerun_' . $adminId . '_' . $ymd,
        ]];
        $rows[] = [['text' => t('audio.bot.mod.back_list'), 'callback_data' => 'mod_list']];
        return ['inline_keyboard' => $rows];
    }

    /** @return array{inline_keyboard:list<list<array{text:string,callback_data:string}>>} */
    private static function kbBackSummary(int $adminId, string $ymd): array
    {
        return ['inline_keyboard' => [[
            ['text' => t('audio.bot.mod.back_summary'), 'callback_data' => 'mod_sum_' . $adminId . '_' . $ymd],
        ]]];
    }

    /** @return array{inline_keyboard:list<list<array{text:string,callback_data:string}>>} */
    private static function kbList(): array
    {
        return ['inline_keyboard' => [[
            ['text' => t('audio.bot.mod.back_list'), 'callback_data' => 'mod_list'],
            ['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet'],
        ]]];
    }

    /** @return array{inline_keyboard:list<list<array{text:string,callback_data:string}>>} */
    private static function kbMenu(): array
    {
        return ['inline_keyboard' => [[
            ['text' => t('bots.cabinet.refresh'), 'callback_data' => 'cabinet'],
        ]]];
    }
}
