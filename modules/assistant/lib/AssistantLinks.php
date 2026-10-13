<?php
declare(strict_types=1);

/**
 * Absolute admin URLs + staff-bot cabinet hints for Max replies.
 */
final class AssistantLinks
{
    public static function base(): string
    {
        return rtrim(AppRuntime::adminBaseUrl(), '/');
    }

    public static function url(string $path): string
    {
        return AppRuntime::adminUrl($path);
    }

    /**
     * Rewrite relative FreeSaaS paths in assistant text to absolute https://host/… links.
     */
    public static function absolutize(string $text): string
    {
        $base = self::base();
        if ($base === '' || $text === '') {
            return $text;
        }
        $escaped = preg_quote($base, '#');

        // [label](/path) → [label](https://host/path)
        $text = preg_replace_callback(
            '#\[([^\]]+)\]\((/[^\s)]+)\)#',
            static function (array $m) use ($base): string {
                return '[' . $m[1] . '](' . $base . $m[2] . ')';
            },
            $text
        ) ?? $text;

        // `/path` → `https://host/path`
        $text = preg_replace_callback(
            '#`(/[a-zA-Z][a-zA-Z0-9_./?&=%~-]*)`#',
            static function (array $m) use ($base): string {
                return '`' . $base . $m[1] . '`';
            },
            $text
        ) ?? $text;

        // Bare /module/... — not after scheme (http://), host, or another slash
        $text = preg_replace_callback(
            '#(?<!' . $escaped . ')(?<![\\w.:/])/([a-z][a-z0-9_-]*(?:/[\\w./?&=%~-]*)?)#',
            static function (array $m) use ($base): string {
                return $base . '/' . $m[1];
            },
            $text
        ) ?? $text;

        return $text;
    }

    /**
     * Active staff bot cabinet buttons (Telegram/Max menu), for system prompt.
     *
     * @return list<string> lines like «Еда и спорт» → wellness
     */
    public static function botCabinetHints(): array
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotMenuCatalog', false)) {
            return [];
        }
        $out = [];
        foreach (BotMenuCatalog::resolve('staff') as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['status'] ?? '') !== 'active' || ($row['kind'] ?? '') !== 'cabinet') {
                continue;
            }
            $id = (string)($row['id'] ?? '');
            $plugin = (string)($row['plugin_id'] ?? '');
            if ($id === 'chat_with_max' || $plugin === 'assistant') {
                continue;
            }
            $label = trim((string)($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $mod = trim((string)($row['module'] ?? ''));
            $out[] = $mod !== ''
                ? '«' . $label . '» → module ' . $mod
                : '«' . $label . '»';
        }
        return $out;
    }

    /** Label of an active cabinet button for a module id, or ''. */
    public static function botCabinetLabelForModule(string $moduleId): string
    {
        $moduleId = trim($moduleId);
        if ($moduleId === '' || !ModuleManager::isEnabled('bots') || !class_exists('BotMenuCatalog', false)) {
            return '';
        }
        foreach (BotMenuCatalog::resolve('staff') as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['status'] ?? '') !== 'active' || ($row['kind'] ?? '') !== 'cabinet') {
                continue;
            }
            if ((string)($row['module'] ?? '') !== $moduleId) {
                continue;
            }
            $id = (string)($row['id'] ?? '');
            $plugin = (string)($row['plugin_id'] ?? '');
            if ($id === 'chat_with_max' || $plugin === 'assistant') {
                continue;
            }
            return trim((string)($row['label'] ?? ''));
        }
        return '';
    }
}
