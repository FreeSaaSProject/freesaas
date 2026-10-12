<?php
declare(strict_types=1);

/**
 * Plain-text notes in BI recipes (constructor). Escaped on render; reject injection patterns at validate/save.
 */
final class BiText
{
    public const MAX_LEN = 8000;

    /**
     * Normalize + validate user/recipe plain text. Returns cleaned string or null on reject.
     *
     * @param list<string> $errors
     */
    public static function sanitize(string $raw, string $path, array &$errors): ?string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $raw);
        // Strip NULs / other C0 controls except TAB/LF.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        $s = trim($s);
        if ($s === '') {
            $errors[] = $path . ': empty';
            return null;
        }
        if (mb_strlen($s, 'UTF-8') > self::MAX_LEN) {
            $errors[] = $path . ': too_long';
            return null;
        }
        if (preg_match('/<\s*\/?\s*(script|iframe|object|embed|link|meta|style|svg|math)\b/i', $s)) {
            $errors[] = $path . ': html_tag_forbidden';
            return null;
        }
        if (preg_match('/<\?(?:php|=)?/i', $s) || strpos($s, '<%') !== false) {
            $errors[] = $path . ': code_tag_forbidden';
            return null;
        }
        if (preg_match('/\bon[a-z]+\s*=/i', $s)) {
            $errors[] = $path . ': event_handler_forbidden';
            return null;
        }
        if (preg_match('/(?:javascript|vbscript|data)\s*:/i', $s)) {
            $errors[] = $path . ': dangerous_uri_forbidden';
            return null;
        }
        if (preg_match('/\$\{|`\s*\$\(|\{\{/', $s)) {
            $errors[] = $path . ': template_injection_forbidden';
            return null;
        }
        // Angle brackets that look like tags (not math comparisons alone).
        if (preg_match('/<\s*[a-zA-Z!\/?]/', $s)) {
            $errors[] = $path . ': html_like_forbidden';
            return null;
        }
        return $s;
    }

    /**
     * @param mixed $body string|list<string>
     * @param list<string> $errors
     */
    public static function sanitizeBody($body, string $path, array &$errors): ?string
    {
        if (is_array($body)) {
            $parts = [];
            foreach ($body as $i => $line) {
                if (!is_string($line) && !is_int($line) && !is_float($line)) {
                    $errors[] = $path . '[' . $i . ']: need string';
                    return null;
                }
                $parts[] = (string)$line;
            }
            $body = implode("\n", $parts);
        }
        if (!is_string($body)) {
            $errors[] = $path . ': need string or string[]';
            return null;
        }
        return self::sanitize($body, $path, $errors);
    }
}
