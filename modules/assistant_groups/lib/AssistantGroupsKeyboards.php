<?php
declare(strict_types=1);

/**
 * Max inline keyboards + HTML send helpers for group assistant.
 */
final class AssistantGroupsKeyboards
{
    public const CB_OK = 'ag:ok:';
    public const CB_NO = 'ag:no:';
    public const CB_PROB = 'ag:prob:';
    /** Choice pick: ag:ch:{token}:{n} (n = 1-based index). */
    public const CB_CH = 'ag:ch:';
    /** Soft numbered pick from LLM text (no pending draft): ag:n:{n}. */
    public const CB_NUM = 'ag:n:';
    /** Soft Cancel / Problem when options are shown without a pending token. */
    public const CB_SOFT_NO = 'ag:softno';
    public const CB_SOFT_PROB = 'ag:softprob';

    public static function draftKeyboard(string $token, bool $confirmable, array $choices = []): array
    {
        $rows = [];
        if (!$confirmable && $choices !== []) {
            $pickRow = [];
            foreach (array_values($choices) as $i => $c) {
                $n = $i + 1;
                if ($n > 5) {
                    break;
                }
                $pickRow[] = [
                    'text' => (string)$n,
                    'callback_data' => self::CB_CH . $token . ':' . $n,
                ];
            }
            if ($pickRow !== []) {
                $rows[] = $pickRow;
            }
        }
        // Separate rows: Max truncates/rejects crowded rows with long labels.
        if ($confirmable) {
            $rows[] = [[
                'text' => t('assistant.confirm.btn'),
                'callback_data' => self::CB_OK . $token,
            ]];
        }
        $rows[] = [
            ['text' => t('assistant.confirm.cancel'), 'callback_data' => self::CB_NO . $token],
            ['text' => t('assistant_groups.bot.problem_btn'), 'callback_data' => self::CB_PROB . $token],
        ];
        return ['inline_keyboard' => $rows];
    }

    /**
     * Soft 1/2/… taps when the model listed options without queueing a write.
     * Cancel / "leave as is" lines are omitted — use chat Cancel wording or ignore.
     *
     * @return list<int>
     */
    public static function parseNumberedOptionNs(string $text): array
    {
        return array_keys(self::parseNumberedOptions($text));
    }

    /**
     * @return array<int,string> n => label
     */
    public static function parseNumberedOptions(string $text): array
    {
        if ($text === '' || !preg_match_all('/^\s*(\d{1})[.)]\s+(\S.+)$/mu', $text, $mm, PREG_SET_ORDER)) {
            return [];
        }
        $out = [];
        foreach ($mm as $m) {
            $n = (int)$m[1];
            $label = trim((string)$m[2]);
            if ($n < 1 || $n > 9 || $label === '') {
                continue;
            }
            if (self::isCancelLikeOption($label)) {
                continue;
            }
            $out[$n] = $label;
            if (count($out) >= 5) {
                break;
            }
        }
        ksort($out, SORT_NUMERIC);
        return $out;
    }

    /** True when the model invented a Confirm draft without queueing a write tool. */
    public static function looksLikeFakeDraft(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        $markers = [
            t('assistant.confirm.press_buttons'),
            t('assistant_groups.bot.need_manager', ['perm' => '']),
            t('assistant_groups.bot.blocked_footer', ['perm' => '']),
        ];
        foreach ($markers as $m) {
            $m = trim((string)$m);
            // need_manager / blocked_footer include {perm} — match a stable prefix.
            if ($m === '') {
                continue;
            }
            $probe = function_exists('mb_substr') ? mb_substr($m, 0, 24) : substr($m, 0, 24);
            if ($probe !== '' && strpos($text, $probe) !== false) {
                return true;
            }
        }
        return (bool)preg_match(
            '/(press Confirm|нажмите\s*[«"]?Подтвердить|Draft ready|Черновик готов)/iu',
            $text
        );
    }

    public static function isCancelLikeOption(string $label): bool
    {
        return (bool)preg_match(
            '/\b(отмен|оставить|как есть|leave|cancel|abort|skip|без изменен)\b/iu',
            $label
        );
    }

    /**
     * @param list<int> $nums
     * @return list<list<array<string,string>>>
     */
    public static function numberPickRows(array $nums): array
    {
        $row = [];
        foreach ($nums as $n) {
            $n = (int)$n;
            if ($n < 1 || $n > 9) {
                continue;
            }
            $row[] = [
                'text' => (string)$n,
                'callback_data' => self::CB_NUM . $n,
            ];
        }
        return $row !== [] ? [$row] : [];
    }

    /**
     * Soft option taps + always system Cancel / Problem (not from LLM text).
     *
     * @param list<int> $nums
     * @return array{inline_keyboard:list<list<array<string,string>>>}
     */
    public static function numberPickKeyboard(array $nums): array
    {
        $rows = self::numberPickRows($nums);
        // Always appended by the system — independent of whether LLM listed Cancel.
        $rows[] = [
            ['text' => t('assistant.confirm.cancel'), 'callback_data' => self::CB_SOFT_NO],
            ['text' => t('assistant_groups.bot.problem_btn'), 'callback_data' => self::CB_SOFT_PROB],
        ];
        return ['inline_keyboard' => $rows];
    }

    /**
     * Cancel / Problem while options were shown without a live pending token.
     *
     * @param array<string,mixed> $user
     */
    public static function confirmKeyboard(string $token): array
    {
        return self::draftKeyboard($token, true, []);
    }

    /**
     * Remove inline keyboards from tracked bot messages (text stays).
     * $extraMid — e.g. the message the user just clicked (may predate keyboard_mids tracking).
     */
    public static function stripKeyboards(string $token, string $chatId, string $extraMid = ''): void
    {
        $token = trim($token);
        $chatId = trim($chatId);
        $mids = AssistantGroupsDialog::takeKeyboardMids($chatId);
        $extraMid = trim($extraMid);
        if ($extraMid !== '') {
            $mids[] = $extraMid;
        }
        $mids = array_values(array_unique(array_filter($mids)));
        if ($token === '' || $chatId === '' || !class_exists('BotClient', false)) {
            return;
        }
        foreach ($mids as $mid) {
            try {
                BotClient::clearMessageKeyboard('max', $token, $chatId, $mid);
            } catch (Throwable $e) {
                error_log('[assistant_groups] strip keyboard ' . $mid . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Escape for Max HTML and bold draft change lines (arrow deltas, Will: prefix).
     * Keeps pending summary plain for the web UI (textContent).
     */
    public static function formatMaxHtml(string $text): string
    {
        $parts = preg_split("/\r\n|\n|\r/", $text) ?: [$text];
        $out = [];
        foreach ($parts as $line) {
            $esc = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($line !== '' && (strpos($line, '→') !== false
                || preg_match('/^(Будет|Will)\s*:/u', $line) === 1)
            ) {
                $esc = '<b>' . $esc . '</b>';
            }
            $out[] = $esc;
        }
        return implode("\n", $out);
    }

    /**
     * @param array{inline_keyboard?:list}|null $replyMarkup
     * @return string|null sent Max message mid
     */
    public static function reply(string $token, string $chatId, string $text, ?array $replyMarkup = null): ?string
    {
        $text = trim($text);
        if ($text === '' || $token === '' || $chatId === '') {
            return null;
        }
        $html = self::formatMaxHtml($text);
        $hasKeyboard = $replyMarkup !== null && !empty($replyMarkup['inline_keyboard']);
        if (class_exists('BotClient', false)) {
            $res = BotClient::sendKeepMessage('max', $token, $chatId, $html, $replyMarkup, 'html');
            if (empty($res['ok']) && $hasKeyboard) {
                // Keyboard rejected (e.g. bad button shape) — still deliver text, log reason.
                error_log('[assistant_groups] send with keyboard failed: ' . (string)($res['error'] ?? ''));
                $res = BotClient::sendKeepMessage('max', $token, $chatId, $html, null, 'html');
                $hasKeyboard = false;
            }
            $mid = BotClient::extractMessageId('max', is_array($res['data'] ?? null) ? $res['data'] : null);
            if ($mid !== null && $mid !== '') {
                AssistantGroupsDialog::rememberBotMid($chatId, $mid);
                if ($hasKeyboard) {
                    AssistantGroupsDialog::rememberKeyboardMid($chatId, $mid);
                }
                return $mid;
            }
            if (empty($res['ok'])) {
                error_log('[assistant_groups] send failed: ' . (string)($res['error'] ?? ''));
            }
            return null;
        }
        $res = MaxGroupSpike::request($token, 'POST', '/messages', ['chat_id' => $chatId], [
            'text' => $html,
            'format' => 'html',
        ]);
        $mid = null;
        if ($res['ok'] && is_array($res['data'] ?? null)) {
            $mid = (string)($res['data']['message']['body']['mid'] ?? '');
            if ($mid !== '') {
                AssistantGroupsDialog::rememberBotMid($chatId, $mid);
                if ($hasKeyboard) {
                    AssistantGroupsDialog::rememberKeyboardMid($chatId, $mid);
                }
            }
        }
        return $mid !== '' ? $mid : null;
    }

    /** @return array{user_id:int,username:string,name:string} */

}
