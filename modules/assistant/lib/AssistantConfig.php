<?php
declare(strict_types=1);

/**
 * Ini-backed assistant settings ([assistant] in cfg/modules/assistant.ini).
 * Display name is the persona the harness and UI use; default Макс.
 */
final class AssistantConfig
{
    public const DEFAULT_NAME = 'Макс';

    public const NAME_MAX_LEN = 40;

    public static function displayName(): string
    {
        $name = self::normalizeName((string)Config::get('assistant', 'name', self::DEFAULT_NAME));
        return $name !== '' ? $name : self::DEFAULT_NAME;
    }

    /** First grapheme/letter for the compact FAB button. */
    public static function initialLetter(): string
    {
        $name = self::displayName();
        if ($name === '') {
            return 'A';
        }
        if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
            $ch = mb_substr($name, 0, 1, 'UTF-8');
            return $ch !== '' ? mb_strtoupper($ch, 'UTF-8') : 'A';
        }
        $ch = substr($name, 0, 1);
        return $ch !== '' ? strtoupper($ch) : 'A';
    }

    /**
     * t() with {name} always filled from displayName().
     *
     * @param array<string, scalar|null> $replace
     */
    public static function t(string $key, array $replace = []): string
    {
        $replace['name'] = self::displayName();
        return t($key, $replace);
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function saveFromPost(array $post): void
    {
        $raw = array_key_exists('assistant_name', $post)
            ? (string)$post['assistant_name']
            : (string)($post['name'] ?? '');
        $name = self::normalizeName($raw);
        Config::set('assistant', 'name', $name !== '' ? $name : self::DEFAULT_NAME);
    }

    public static function normalizeName(string $raw): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
        if ($name === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, self::NAME_MAX_LEN);
        } else {
            $name = substr($name, 0, self::NAME_MAX_LEN);
        }
        return trim($name);
    }
}
