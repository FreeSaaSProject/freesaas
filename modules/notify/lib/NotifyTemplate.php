<?php
declare(strict_types=1);

/**
 * Message templates: {var} placeholders. Values are HTML-escaped (bot text is sent as HTML);
 * the template itself is staff-authored and may contain <b>/<i>. Empty template → t(default key).
 */
final class NotifyTemplate
{
    /**
     * @param array<string,mixed> $vars
     */
    public static function render(string $tpl, array $vars, string $defaultKey = ''): string
    {
        $safe = self::escapeVars($vars);
        if (trim($tpl) === '') {
            if ($defaultKey === '') {
                return '';
            }
            return t($defaultKey, $safe);
        }
        $repl = [];
        foreach ($safe as $k => $v) {
            $repl['{' . $k . '}'] = $v;
        }
        return strtr($tpl, $repl);
    }

    /** First line of the (HTML) text without tags — email subject. */
    public static function subject(string $html, string $fallback = ''): string
    {
        $plain = self::toPlain($html);
        $line = trim((string)strtok($plain, "\n"));
        if ($line === '') {
            $line = $fallback;
        }
        return mb_substr($line, 0, 200);
    }

    public static function toPlain(string $html): string
    {
        $t = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        return trim(html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8'));
    }

    /**
     * @param array<string,mixed> $vars
     * @return array<string,string>
     */
    public static function escapeVars(array $vars): array
    {
        $out = [];
        foreach ($vars as $k => $v) {
            if (is_array($v)) {
                $v = implode(', ', array_map('strval', array_filter($v, 'is_scalar')));
            } elseif (is_bool($v)) {
                $v = $v ? '1' : '0';
            } elseif (!is_scalar($v)) {
                $v = '';
            }
            $out[(string)$k] = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        }
        return $out;
    }
}
