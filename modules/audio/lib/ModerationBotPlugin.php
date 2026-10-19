<?php
declare(strict_types=1);

/**
 * Staff bot: LLM skim of pending-review day + confirm / walk transcripts with key hits.
 * Catalog id `moderation` (BotMenuCatalog).
 * ACL (temporary): SUPERADMIN_ACCESS only — widen later to audio.edit + position scope.
 */
final class ModerationBotPlugin
{
    private const DAYS_BACK = 21;
    private const LIST_LIMIT = 20;

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

        // mod_d_{admin}_{Ymd} — open day (LLM + buttons)
        if (preg_match('/^mod_d_(\d+)_(\d{8})$/', $data, $m)) {
            $adminId = (int)$m[1];
            $ymd = (string)$m[2];
            if (!self::canAccessAdmin($adminId)) {
                BotClient::replyUi($ctx, t('audio.bot.mod.denied'), self::kbMenu());
                return true;
            }
            self::showDaySummary($ctx, $adminId, $ymd);
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

        // mod_v_{admin}_{Ymd}_{idx} — walk records
        if (preg_match('/^mod_v_(\d+)_(\d{8})_(\d+)$/', $data, $m)) {
            $adminId = (int)$m[1];
            $ymd = (string)$m[2];
            $idx = (int)$m[3];
            if (!self::canAccessAdmin($adminId)) {
                BotClient::replyUi($ctx, t('audio.bot.mod.denied'), self::kbMenu());
                return true;
            }
            self::showRecord($ctx, $adminId, $ymd, $idx);
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
        // Temporary superadmin-only gate: no position filter.
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
                self::nameAcronym($name !== '' ? $name : ('#' . $aid)),
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
    private static function showDaySummary(array $ctx, int $adminId, string $ymd): void
    {
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

        // Immediate UI feedback — LLM can take up to ~3 minutes.
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

        $text = $header . "\n\n" . $body;
        $kb = self::kbDay($adminId, $ymd, count($ids) > 0);
        BotClient::replyUi($ctx, $text, $kb);
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
            BotClient::replyUi($ctx, t('audio.bot.mod.day_empty'), self::kbList());
            return;
        }
        $done = RecordsReviewsRepository::markReviewed($ids, $uid);
        BotClient::replyUi($ctx, t('audio.bot.mod.confirmed', ['n' => $done]), self::kbList());
    }

    /** @param array<string,mixed> $ctx */
    private static function showRecord(array $ctx, int $adminId, string $ymd, int $idx): void
    {
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
            'text' => t('audio.bot.mod.confirm'),
            'callback_data' => 'mod_ok_' . $adminId . '_' . $ymd,
        ]];
        $rows[] = [[
            'text' => t('audio.bot.mod.back_summary'),
            'callback_data' => 'mod_d_' . $adminId . '_' . $ymd,
        ]];
        $rows[] = [['text' => t('audio.bot.mod.back_list'), 'callback_data' => 'mod_list']];

        BotClient::replyUi($ctx, $text, ['inline_keyboard' => $rows], 'HTML');
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
        // Convert web transcript HTML to Telegram-safe HTML (<b> only).
        $html = preg_replace('/<\/div>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<b\b[^>]*>/i', '<b>', $html) ?? $html;
        $html = strip_tags($html, '<b>');
        $html = preg_replace("/\n{3,}/", "\n\n", $html) ?? $html;
        $html = trim($html);
        // Prefer lines that still contain hits; if none, keep first chunk.
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
     * @return array{0:int,1:int,2:int} year, month, day (0s if invalid)
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

    /** "Иванов Иван Иванович" → "Иванов И.И."; single word → as-is (trimmed). */
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

    /** @return array{inline_keyboard:list<list<array{text:string,callback_data:string}>>} */
    private static function kbDay(int $adminId, string $ymd, bool $hasPending): array
    {
        $rows = [];
        if ($hasPending) {
            $rows[] = [[
                'text' => t('audio.bot.mod.confirm'),
                'callback_data' => 'mod_ok_' . $adminId . '_' . $ymd,
            ]];
            $rows[] = [[
                'text' => t('audio.bot.mod.view_records'),
                'callback_data' => 'mod_v_' . $adminId . '_' . $ymd . '_0',
            ]];
        }
        $rows[] = [['text' => t('audio.bot.mod.back_list'), 'callback_data' => 'mod_list']];
        return ['inline_keyboard' => $rows];
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
