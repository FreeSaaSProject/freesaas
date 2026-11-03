<?php
declare(strict_types=1);

/** Ini-backed settings for vacations ([vacations] in cfg/modules/vacations.ini). */
final class VacationsConfig
{
    private const SECTION = 'vacations';

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            'remind_enabled' => '0',
            'remind_days_before' => '5',
            'remind_daily_at' => '09:00',
            'remind_chat_id' => '',
            'remind_platform' => 'max',
            'remind_message' => '',
            'remind_types' => '1,2',
            'remind_show_user_only' => '1',
        ];
    }

    public static function remindEnabled(): bool
    {
        return self::flag('remind_enabled', false);
    }

    /** @return list<int> */
    public static function remindDaysBefore(): array
    {
        $raw = self::string('remind_days_before', '5');
        $parts = preg_split('/\s*,\s*/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $n = (int)$p;
            if ($n >= 0 && $n <= 365 && !in_array($n, $out, true)) {
                $out[] = $n;
            }
        }
        if ($out === []) {
            $out[] = 5;
        }
        sort($out);
        return $out;
    }

    public static function remindDailyAt(): string
    {
        $v = trim(self::string('remind_daily_at', '09:00'));
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m)) {
            return '09:00';
        }
        $h = max(0, min(23, (int)$m[1]));
        $min = max(0, min(59, (int)$m[2]));
        return sprintf('%02d:%02d', $h, $min);
    }

    public static function remindChatId(): string
    {
        return trim(self::string('remind_chat_id', ''));
    }

    public static function remindPlatform(): string
    {
        $p = strtolower(trim(self::string('remind_platform', 'max')));
        return $p === 'telegram' ? 'telegram' : 'max';
    }

    public static function remindMessage(): string
    {
        return trim(self::string('remind_message', ''));
    }

    /** @return list<int> */
    public static function remindTypes(): array
    {
        $raw = self::string('remind_types', '1,2');
        $parts = preg_split('/\s*,\s*/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $n = (int)$p;
            if (in_array($n, [1, 2, 3], true) && !in_array($n, $out, true)) {
                $out[] = $n;
            }
        }
        return $out !== [] ? $out : [1, 2];
    }

    public static function remindShowUserOnly(): bool
    {
        return self::flag('remind_show_user_only', true);
    }

    /**
     * @return array<string, string>
     */
    public static function formValues(): array
    {
        return [
            'remind_enabled' => self::remindEnabled() ? '1' : '0',
            'remind_days_before' => implode(',', array_map('strval', self::remindDaysBefore())),
            'remind_daily_at' => self::remindDailyAt(),
            'remind_chat_id' => self::remindChatId(),
            'remind_platform' => self::remindPlatform(),
            'remind_message' => self::remindMessage(),
            'remind_types' => implode(',', array_map('strval', self::remindTypes())),
            'remind_show_user_only' => self::remindShowUserOnly() ? '1' : '0',
        ];
    }

    /**
     * Known group chats for settings datalist (assistant_groups + bots recipients).
     *
     * @return list<array{chat_id:string,title:string}>
     */
    public static function knownChatOptions(): array
    {
        $out = [];
        $seen = [];
        $add = static function (string $id, string $title) use (&$out, &$seen): void {
            $id = trim($id);
            if ($id === '' || isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            $title = trim($title);
            $out[] = ['chat_id' => $id, 'title' => $title !== '' ? $title : $id];
        };

        if (class_exists('AssistantGroupsConfig', false) && ModuleManager::isEnabled('assistant_groups')) {
            foreach (AssistantGroupsConfig::groupChatsDetailed() as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $add((string)($row['chat_id'] ?? ''), (string)($row['title'] ?? ''));
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $post
     */
    public static function saveFromPost(array $post): void
    {
        Config::set(self::SECTION, 'remind_enabled', !empty($post['remind_enabled']) ? '1' : '0');
        Config::set(
            self::SECTION,
            'remind_days_before',
            self::normalizeDaysCsv((string)($post['remind_days_before'] ?? '5'))
        );
        Config::set(self::SECTION, 'remind_daily_at', self::normalizeDailyAt((string)($post['remind_daily_at'] ?? '09:00')));
        Config::set(self::SECTION, 'remind_chat_id', trim((string)($post['remind_chat_id'] ?? '')));
        $platform = strtolower(trim((string)($post['remind_platform'] ?? 'max')));
        Config::set(self::SECTION, 'remind_platform', $platform === 'telegram' ? 'telegram' : 'max');
        Config::set(self::SECTION, 'remind_message', trim((string)($post['remind_message'] ?? '')));
        Config::set(
            self::SECTION,
            'remind_types',
            self::normalizeTypesCsv((string)($post['remind_types'] ?? '1,2'))
        );
        Config::set(
            self::SECTION,
            'remind_show_user_only',
            !empty($post['remind_show_user_only']) ? '1' : '0'
        );
    }

    private static function normalizeDaysCsv(string $raw): string
    {
        $parts = preg_split('/\s*,\s*/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $n = (int)$p;
            if ($n >= 0 && $n <= 365 && !in_array($n, $out, true)) {
                $out[] = $n;
            }
        }
        if ($out === []) {
            $out[] = 5;
        }
        sort($out);
        return implode(',', $out);
    }

    private static function normalizeTypesCsv(string $raw): string
    {
        $parts = preg_split('/\s*,\s*/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $n = (int)$p;
            if (in_array($n, [1, 2, 3], true) && !in_array($n, $out, true)) {
                $out[] = $n;
            }
        }
        if ($out === []) {
            $out = [1, 2];
        }
        sort($out);
        return implode(',', $out);
    }

    private static function normalizeDailyAt(string $v): string
    {
        $v = trim($v);
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m)) {
            return '09:00';
        }
        $h = max(0, min(23, (int)$m[1]));
        $min = max(0, min(59, (int)$m[2]));
        return sprintf('%02d:%02d', $h, $min);
    }

    private static function flag(string $key, bool $default): bool
    {
        $raw = Config::get(self::SECTION, $key, $default ? '1' : '0');
        return (string)$raw === '1' || $raw === 1 || $raw === true;
    }

    private static function string(string $key, string $default): string
    {
        return (string)Config::get(self::SECTION, $key, $default);
    }
}
